// === Server-Mode: echte API benutzen ===
const SERVER_MODE = true;
window.API_BASE = '/api';
window.ENDPOINTS = {
  GET_DAY:  'get_day.php',
  STAMP:    'stamp.php',
  CLEAR:    'clear_day.php',
};


// ---- Globals ----
let vehSelect, dateSelect, previewBody, nowLbl, statusLbl, blockStart, blockRest;
let hallModalEl, hallSelectEl, hallSaveBtnEl;
let btnStart, btnFeier;
let dayRows = [];

// ---- Helpers ----
function pad2(n){ return String(n).padStart(2,'0'); }
function hhmmNow(){ const d=new Date(); return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`; }
function isoDate(d){ return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`; }
function setStatus(msg, ok=true){
  if (!statusLbl) return;
  statusLbl.textContent = msg;
  statusLbl.classList.toggle('text-success', !!ok);
  statusLbl.classList.toggle('text-danger', !ok);
}
// Row-Helper auf Basis von dayRows
function firstRow(){ return dayRows.length ? { i:0, r:dayRows[0] } : null; }
function lastRow(){  return dayRows.length ? { i:dayRows.length-1, r:dayRows[dayRows.length-1] } : null; }
function findRowForField(field){
  for (let i=0;i<dayRows.length;i++){
    const r = dayRows[i];
    if (field==='arriveWU' && !r.arriveWU)               return {i,r};
    if (field==='departWU' && r.arriveWU && !r.departWU) return {i,r};
    if (field==='arriveH'  && r.departWU && !r.arriveH ) return {i,r};
    if (field==='departH'  && r.arriveH  && !r.departH ) return {i,r};
  }
  return null;
}

// ---- Robust JSON-Fetch + API-Wrapper (nur EINMAL definieren) ----
async function fetchJSON(url, opts = {}) {
  const res  = await fetch(url, { credentials:'include', cache:'no-store', ...opts });
  const text = await res.text();
  let data; try { data = JSON.parse(text); }
  catch {
    console.error('❌ Non-JSON from', url, 'HTTP', res.status, '\n--- BODY START ---\n', text, '\n--- BODY END ---');
    throw new Error(`Server lieferte kein JSON (HTTP ${res.status})`);
  }
  if (!res.ok || data?.ok === false) throw new Error(data?.error || `HTTP ${res.status}`);
  return data;
}

window.apiGetDay = async (vehId, dateISO) => {
  const url = `${API_BASE}/${ENDPOINTS.GET_DAY}?veh_id=${encodeURIComponent(vehId)}&date=${encodeURIComponent(dateISO)}`;
  const j = await fetchJSON(url);
  return Array.isArray(j.rows) ? j.rows : [];
};
window.apiStamp = async (vehId, dateISO, tour, fields) => {
  const url = `${API_BASE}/${ENDPOINTS.STAMP}`;
  return await fetchJSON(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body: JSON.stringify({ veh_id:vehId, date:dateISO, tour, fields })
  });
};
window.apiClearDay = async (vehId, dateISO) => {
  const url = `${API_BASE}/${ENDPOINTS.CLEAR}`;
  return await fetchJSON(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body: JSON.stringify({ veh_id:vehId, date:dateISO })
  });
};

// ---- UI Init ----
document.addEventListener('DOMContentLoaded', async () => {
  // DOM-Refs
  vehSelect   = document.getElementById('vehSelect');
  dateSelect  = document.getElementById('dateSelect');
  previewBody = document.getElementById('previewBody');
  nowLbl      = document.getElementById('nowLbl');
  statusLbl   = document.getElementById('statusLbl');
  blockStart  = document.getElementById('blockStart');
  blockRest   = document.getElementById('blockRest');

  hallModalEl   = document.getElementById('hallModal');
  hallSelectEl  = document.getElementById('hallSelect');
  hallSaveBtnEl = document.getElementById('hallSaveBtn');

  btnStart = document.getElementById('btnStart');
  btnFeier = document.getElementById('btnFeierabend');

  // Defaults (lokale Zeit!)
  if (dateSelect && !dateSelect.value) {
    const todayISO = isoDate(new Date());
    const opt = Array.from(dateSelect.options).find(o => o.value === todayISO);
    dateSelect.value = opt ? todayISO : (dateSelect.options[0]?.value || '');
  }
  if (vehSelect && !vehSelect.value) vehSelect.value = vehSelect.options[0]?.value || 'veh1';

  // Listener
  vehSelect?.addEventListener('change', reloadDay);
  dateSelect?.addEventListener('change', reloadDay);
  btnStart?.addEventListener('click', (e)=>{ e.preventDefault(); stampStart(); });
  btnFeier?.addEventListener('click', (e)=>{ e.preventDefault(); stampFeierabend(); });
  document.querySelectorAll('[data-field]').forEach(b=>{
    b.addEventListener('click', (e)=>{ e.preventDefault(); stampField(b.getAttribute('data-field')); });
  });

  // Uhr
 function tick(){ if (nowLbl) nowLbl.textContent = hhmmNow(); }
  tick(); setInterval(tick, 10000);

  if (typeof window.buildDriverTabs === 'function') {
    window.buildDriverTabs();
  }
  if (typeof window.syncWeekFromServer === 'function') {
    await window.syncWeekFromServer();
  }
  await reloadDay();
  if (typeof window.renderAll === 'function') {
    window.renderAll();
  }
});

// --------- Render ----------
function renderPreview(){
  if (!previewBody) return;           // Guard
  if (!dayRows.length){
    previewBody.innerHTML = '<tr><td colspan="7" class="text-muted">Kein Eintrag gefunden.</td></tr>';
    return;
  }
  previewBody.innerHTML = dayRows.map(r => `
    <tr>
      <td>${r.tour}</td>
      <td>${r.date}</td>
      <td><code>${r.workStart||''}</code></td>
      <td><code>${r.arriveWU||''}</code></td>
      <td><code>${r.departWU||''}</code></td>
      <td><code>${r.arriveH||''}${r.hannoverHall ? ' ('+r.hannoverHall+')' : ''}</code></td>
      <td><code>${r.departH||''}</code></td>
    </tr>
  `).join('');
}

function refreshVisibility(){
  // Guards gegen nicht vorhandene Blöcke
  if (!blockStart || !blockRest) {    // z.B. Seite ohne diese Elemente
    renderPreview();
    return;
  }

  const fr = firstRow();
  if (!fr){
    blockStart.style.display = '';
    blockRest.style.display  = 'none';
    setStatus('Kein Tagesraster gefunden.', false);
    renderPreview();
    return;
  }
  const started = !!fr.r.workStart;
  const feier   = !!fr.r.workEnd;

  blockStart.style.display = started ? 'none' : '';
  blockRest.style.display  = (started && !feier) ? '' : 'none';

  if (!started) setStatus('Bitte zuerst „Startzeit“ stempeln.', false);
  else if (feier) setStatus(`Feierabend ${fr.r.workEnd} erfasst.`, true);
  else setStatus(`Arbeitsstart ${fr.r.workStart} erfasst.`, true);

  renderPreview();
}
async function reloadDay(){
  if (!vehSelect || !dateSelect) return;     // DOM noch nicht bereit
  const v = vehSelect.value;
  const d = dateSelect.value;

  try{
    setStatus('Lade…', true);
    if (!v || !d){                            // nichts auszuwählen -> UI „leer“
      dayRows = [];
      refreshVisibility();
      return;
    }
    dayRows = await apiGetDay(v, d);
    refreshVisibility();
  }catch(err){
    dayRows = [];
    refreshVisibility();
    setStatus(err.message || 'Fehler beim Laden.', false);
  }
}


// --------- Halle auswählen (Modal) ----------
function pickHall(preset=''){
  return new Promise(resolve => {
    hallSelectEl.value = preset || '';
    let saved = false;

    const modal = new bootstrap.Modal(hallModalEl);
    const onSave = () => { saved = true; const v = hallSelectEl.value || ''; modal.hide(); cleanup(); resolve(v || null); };
    const onHidden = () => { if (!saved) resolve(null); cleanup(); };
    function cleanup(){
      hallSaveBtnEl.removeEventListener('click', onSave);
      hallModalEl.removeEventListener('hidden.bs.modal', onHidden);
    }

    hallSaveBtnEl.addEventListener('click', onSave);
    hallModalEl.addEventListener('hidden.bs.modal', onHidden, { once:true });
    modal.show();
  });
}


async function stampStart(){
  const fr = firstRow();
  if (!fr){ setStatus('Kein Tagesraster.', false); return; }
  const val = hhmmNow();
  try{
    await apiStamp(vehSelect.value, dateSelect.value, fr.r.tour, { workStart: val });
    setStatus(`Gespeichert: Startzeit = ${val}`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Stempeln fehlgeschlagen', false);
  }
}

function labelFor(field){
  return ({arriveWU:'Ankunft Wunstorf', departWU:'Abfahrt Wunstorf',
           arriveH:'Ankunft Hannover',  departH:'Abfahrt Hannover'})[field]||field;
}

async function stampField(field){
  const t = findRowForField(field);
  if (!t){ setStatus(`Kein offener Eintrag für „${labelFor(field)}“ gefunden.`, false); return; }

  const val = hhmmNow();
  const payload = { [field]: val };

  // Halle bei Ankunft H abfragen
  if (field === 'arriveH'){
    const hall = await pickHall(t.r.hannoverHall || '');
    if (hall === null){ setStatus('Abgebrochen.', false); return; }
    payload.hannoverHall = hall;
  }

  try{
    await apiStamp(vehSelect.value, dateSelect.value, t.r.tour, payload);
    setStatus(`Gespeichert: ${labelFor(field)} = ${val}`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Stempeln fehlgeschlagen', false);
  }
}

async function stampFeierabend(){
  const fr = firstRow(), lr = lastRow();
  if (!fr){ setStatus('Kein Tagesraster.', false); return; }
  const val = hhmmNow();
  try{
    await apiStamp(vehSelect.value, dateSelect.value, fr.r.tour, { workEnd: val });
    if (lr) {
      await apiStamp(vehSelect.value, dateSelect.value, lr.r.tour, { reported: 'Feierabend', reportedWhy: '' });
    }
    setStatus(`Feierabend ${val} erfasst.`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Stempeln fehlgeschlagen', false);
  }
}

async function clearDay(){
  const v = vehSelect.value, d = dateSelect.value;
  if (!confirm(`Alle Zeiten für ${v} am ${d} wirklich löschen?`)) return;
  try{
    await apiClearDay(v, d);
    setStatus('Tag geleert.', true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Zurücksetzen fehlgeschlagen', false);
  }
}

(function(){
  // ---- Config persistence ----------------------------------------------------
  var DRV_CFG_KEY = 'drv_cfg_v1';
  var DRV_STATE_KEY = 'drv_week_v2'; // state key (depends on toursPerDay etc.)

  // --- Server-Config immer holen & Cache/Runtime angleichen ---
(async function syncCfgFromServerOnce(){
  try {
    const res = await fetch((window.API_BASE||'/api') + '/veh_cfg.php', { credentials:'include', cache:'no-store' });
    const j   = await res.json();
    if (j?.ok && j.cfg) {
      const fresh = JSON.stringify(j.cfg);
      const old   = localStorage.getItem(DRV_CFG_KEY) || '';
      if (old !== fresh) {
        localStorage.setItem(DRV_CFG_KEY, fresh);
        // Seite neu laden, damit VEHICLES/CFG überall korrekt sind
        location.reload();
      }
    }
  } catch(_) { /* offline/kein Endpoint -> ignoriere, Cache bleibt */ }
})();


  function deepClone(o){ return JSON.parse(JSON.stringify(o)); }

  function loadConfig(){
    var cfg = null;
    try { cfg = JSON.parse(localStorage.getItem(DRV_CFG_KEY) || 'null'); } catch(e){}
    // merge with window.DRIVERS_CONFIG if provided
    if (window.DRIVERS_CONFIG) {
      cfg = Object.assign({}, cfg||{}, window.DRIVERS_CONFIG);
    }
    // defaults
    if (!cfg) cfg = {};
    if (typeof cfg.toursPerDay !== 'number' || cfg.toursPerDay < 1) cfg.toursPerDay = 4;

    if (Array.isArray(cfg.vehicles) && cfg.vehicles.length) {
      cfg.vehicles = cfg.vehicles.map(function(v, i){
        return {
          id: v.id || ('veh'+(i+1)),
          title: v.title || ('Fahrzeug '+(i+1)),
          plate: v.plate || '',
          driver: v.driver || ''
        };
      });
    } else {
      var count = Number(cfg.vehicleCount) || 3;
      cfg.vehicles = Array.from({length: count}, function(_, i){
        return { id:'veh'+(i+1), title:'Fahrzeug '+(i+1), plate:'', driver:'' };
      });
    }
    return cfg;
  }
  function saveConfig(cfg){
    localStorage.setItem(DRV_CFG_KEY, JSON.stringify(cfg));
  }

  var CFG = loadConfig();
  var VEHICLES = deepClone(CFG.vehicles);
  var TOURS_PER_DAY = CFG.toursPerDay;

  window.DRV_getDriverCount = function(){ return VEHICLES.length; };

  // ---- State & helpers -------------------------------------------------------
  function mondayOf(d){ d=d||new Date(); var x=new Date(d), day=x.getDay(), diff=(day===0?-6:1)-day; x.setDate(x.getDate()+diff); x.setHours(0,0,0,0); return x; }
  function isoDate(d){ var y=d.getFullYear(), m=('0'+(d.getMonth()+1)).slice(-2), dd=('0'+d.getDate()).slice(-2); return y+'-'+m+'-'+dd; }
  function parseHM(s){ if(!s) return null; var p=s.split(':'), h=parseInt(p[0],10), m=parseInt(p[1],10); if(isNaN(h)||isNaN(m)) return null; return h*60+m; }
  function fmtHM(min){ if(min==null||!isFinite(min)||min<0) return ''; var h=Math.floor(min/60), m=min%60; return h+':' + ('0'+m).slice(-2); }
  function wday(dateISO){ return new Date(dateISO+'T00:00:00').toLocaleDateString('de-DE',{weekday:'long'}); }

  function defaultWeek(){
    var mon = mondayOf(new Date());
    var days = []; for (var i=0;i<5;i++){ var d=new Date(mon); d.setDate(mon.getDate()+i); days.push(isoDate(d)); }

    var tours = [], n=1;
    for (var di=0; di<days.length; di++){
      for (var t=0; t<TOURS_PER_DAY; t++){
        tours.push({
          tour: n++,
          date: days[di],
          workStart: "",          // Startzeit (Arbeitsstart)
          departWU: "",           // Abfahrt Wunstorf
          arriveWU: "",           // Ankunft Wunstorf
          arriveH: "",            // Ankunft Hannover
          departH: "",            // Abfahrt Hannover
          hannoverHall: "",       // "28" | "28C" | "34"
          note: "",               // Hinweis
          reported: "",           // Meldung: "", "Ja", "Nein", "Feierabend"
          reportedWhy: ""         // Wenn Nein -> Warum
        });
      }
    }

    var data = {};
    for (var v=0; v<VEHICLES.length; v++){
      data[VEHICLES[v].id] = JSON.parse(JSON.stringify(tours));
    }
    return { monday: isoDate(mon), data: data };
  }

  function loadState(){
  try{
    var raw = localStorage.getItem(DRV_STATE_KEY);
    if(raw){
      var obj = JSON.parse(raw);

      // --- WICHTIG: Falls alte Struktur ohne monday -> jetzt setzen
      if (!obj.monday) {
        obj.monday = isoDate(mondayOf(new Date()));
      }

      // adjust for toursPerDay changes
      var firstVehId = VEHICLES[0] && VEHICLES[0].id;
      var sample = firstVehId && obj.data && obj.data[firstVehId];
      if(sample && sample.length){
        var perDay = sample.filter(function(r){ return r.date===sample[0].date; }).length;
        if (perDay !== TOURS_PER_DAY) throw new Error('toursPerDay changed');
      }

      // ensure vehicles present
      VEHICLES.forEach(function(v){
        if(!obj.data[v.id]) obj.data[v.id] = JSON.parse(JSON.stringify(defaultWeek().data[Object.keys(defaultWeek().data)[0]]));
      });
      // remove vehicles that no longer exist
      Object.keys(obj.data).forEach(function(vid){
        if (!VEHICLES.find(function(v){ return v.id===vid; })) delete obj.data[vid];
      });
      return obj;
    }
  }catch(e){}
  return defaultWeek();
}

  function saveState(){ localStorage.setItem(DRV_STATE_KEY, JSON.stringify(DRV.state)); }

  var DRV = { state: loadState() };

  function dur(dep,arr){ var d=parseHM(dep), a=parseHM(arr); if(d==null||a==null||a<d) return null; return a-d; }
  function net(dep,arr,pause){ var tt=dur(dep,arr); if(tt==null) return null; return Math.max(0, tt-(+pause||0)); }
  function totalsByDay(vehId){
    var map = {}, rows = DRV.state.data[vehId];
    for (var i=0;i<rows.length;i++){
      var r = rows[i];
      var sum = (dwellWu(r) || 0) + (dwellHan(r) || 0);
      map[r.date] = (map[r.date]||0) + sum;
    }
    return map;
  }

  // ---- UI builder for sub-tabs/panes ----------------------------------------
  function buildDriverTabs(){
    var pills = document.getElementById('drvSubTabs');
    var content = pills ? pills.parentElement.querySelector('.tab-content') : null;
    if(!pills || !content) return;

    // Rebuild pills
    var pillHtml = '';
    pillHtml += '<li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#drvOverview" type="button">Übersicht</button></li>';
    VEHICLES.forEach(function(v){
      var label = v.title + (v.plate ? ' · '+v.plate : '') + (v.driver ? ' · '+v.driver : '');
      var targetId = 'drv_'+v.id;
      pillHtml += '<li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#'+targetId+'" type="button">'+label+'</button></li>';
    });
    pillHtml += '<li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#drvWeek" type="button">Wochensicht</button></li>';
    pills.innerHTML = pillHtml;

    // Rebuild panes
    var paneHtml = '';
    paneHtml += '<div class="tab-pane fade show active" id="drvOverview"><div id="drvOverviewWrap" class="table-responsive"></div><div class="mt-2 small-note">Gesamtsumme Netto (Woche): <span id="drvTotalNetAll">0:00</span></div></div>';
    VEHICLES.forEach(function(v){
      var targetId = 'drv_'+v.id;
      paneHtml += '<div class="tab-pane fade" id="'+targetId+'">';
      paneHtml += '<h6>'+v.title+(v.plate?' — '+v.plate:'')+(v.driver?' — Fahrer: '+v.driver:'')+'</h6>';
      paneHtml += '<div id="'+targetId+'_wrap" class="table-responsive"></div>';
      paneHtml += '</div>';
    });
    paneHtml += '<div class="tab-pane fade" id="drvWeek"><h6>Wochensicht – Summe pro Tag & Fahrzeug</h6><div id="drvWeekWrap" class="table-responsive"></div></div>';
    content.innerHTML = paneHtml;
  }
function renderVeh(veh, mountId){
  // kleiner Helper: zeigt '0:00' auch bei 0 Minuten an, nur null/invalid bleibt leer
  function showHM(v){ return (v != null ? fmtHM(v) : ''); }

  const full = DRV.state.data[veh.id] || [];
  const weekDates = weekDatesFromMondayISO(DRV.state.monday);
  const weekSet = new Set(weekDates);

  // WICHTIG: wir behalten den Original-Index i bei!
  const rows = full.map((r,i)=>({ r, i }))
                   .filter(x => weekSet.has(x.r.date));

  const todayISO = isoDate(new Date());

  const html = [];
  html.push('<table class="table table-hover align-middle">');
  html.push('<thead>');
  html.push('<tr>');
  html.push('<th rowspan="2">Tour</th>');
  html.push('<th rowspan="2">Datum</th>');
  html.push('<th rowspan="2">Wochentag</th>');
  html.push('<th rowspan="2">Startzeit</th>');
  html.push('<th colspan="3" class="table-primary text-center">Wunstorf</th>');
  html.push('<th colspan="3" class="table-success text-center">Hannover</th>');
  html.push('<th rowspan="2">Status</th>');
  html.push('<th rowspan="2">Hinweis</th>');
  html.push('<th rowspan="2">Meldung</th>');
  html.push('<th>Pause Start</th><th>Pause Ende</th>');
  html.push('</tr>');
  html.push('<tr>');
  html.push('<th>Ankunft</th><th>Abfahrt</th><th>Dauer</th>');
  html.push('<th>Ankunft</th><th>Abfahrt</th><th>Dauer</th>');
  html.push('</tr>');
  html.push('</thead><tbody>');

  // laufende Tagessummen
  let currentDate = null;
  let daySumWU = 0;   // Summe "Dauer" Wunstorf (Ankunft WU -> Abfahrt WU)
  let daySumH  = 0;   // Summe "Dauer" Hannover (Ankunft H  -> Abfahrt H)

  function flushDaySubtotal(){
    if (!currentDate) return;
    const total = daySumWU + daySumH;
    html.push(
      '<tr class="table-light fw-semibold">' +
        '<td colspan="6" class="text-end">Tagessumme&nbsp;(' + currentDate + '):</td>' +
        '<td>' + (daySumWU ? fmtHM(daySumWU) : '0:00') + '</td>' +
        '<td></td><td></td>' +
        '<td>' + (daySumH ? fmtHM(daySumH) : '0:00') +
          (total ? ' <small class="text-muted">(Gesamt: ' + fmtHM(total) + ')</small>' : '') +
        '</td>' +
        '<td></td><td></td><td></td>' +
      '</tr>'
    );
    daySumWU = 0; daySumH = 0; currentDate = null;
  }

  // Reihenfolge wie im State belassen; nur auf Wochen-Daten gefiltert
  for (let k=0; k<rows.length; k++){
    const r  = rows[k].r;
    const ix = rows[k].i;          // <- Original-Index im State
    const hallWU = dwellWu(r);
    const hallH  = dwellHan(r);
    const st     = statusLabel(r);
    const isToday = (r.date === todayISO);

    if (currentDate === null) currentDate = r.date;
    if (r.date !== currentDate){
      flushDaySubtotal();
      currentDate = r.date;
    }

    daySumWU += (hallWU || 0);
    daySumH  += (hallH  || 0);

    html.push('<tr class="'+(isToday?'table-info':'')+'">');
    html.push('<td>'+r.tour+'</td>');
    html.push('<td><input type="date" class="form-control form-control-sm" value="'+r.date+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="date"></td>');
    html.push('<td class="text-muted small">'+wday(r.date)+'</td>');

    // Startzeit
    html.push('<td><input type="text" class="form-control form-control-sm time-input" value="'+(r.workStart||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="workStart"></td>');

    // Wunstorf
    html.push('<td><input type="text" class="form-control form-control-sm time-input" value="'+(r.arriveWU||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="arriveWU"></td>');
    html.push('<td><input type="text" class="form-control form-control-sm time-input" value="'+(r.departWU||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="departWU"></td>');
    html.push('<td>'+ showHM(hallWU) +'</td>');
    // 🟢 Pause-Spalten
    html.push('<td><input type="text" class="form-control form-control-sm time-input" value="'+(r.pauseStart||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="pauseStart" placeholder="Pause Start"></td>');
    html.push('<td><input type="text" class="form-control form-control-sm time-input" value="'+(r.pauseEnd||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="pauseEnd" placeholder="Pause Ende"></td>');


    // Hannover (input-group: Ankunft + Halle)
    html.push('<td>');
    html.push('  <div class="input-group input-group-sm">');
    html.push('    <input type="text" inputmode="numeric" pattern="\\d{1,2}:?\\d{0,2}" class="form-control time-input" value="'+(r.arriveH||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="arriveH">');
    html.push('    <label class="input-group-text">Halle</label>');
    html.push('    <select class="form-select" style="max-width:7rem" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="hannoverHall">');
    html.push('      <option value="" '+(r.hannoverHall===""?"selected":"")+'>Halle …</option>');
    html.push('      <option value="28" '+(r.hannoverHall==="28"?"selected":"")+'>28</option>');
    html.push('      <option value="28C" '+(r.hannoverHall==="28C"?"selected":"")+'>28C</option>');
    html.push('      <option value="34" '+(r.hannoverHall==="34"?"selected":"")+'>34</option>');
    html.push('    </select>');
    html.push('  </div>');
    html.push('</td>');

    // Abfahrt Hannover + Dauer
    html.push('<td><input type="text" inputmode="numeric" pattern="\\d{1,2}:?\\d{0,2}" class="form-control form-control-sm time-input" value="'+(r.departH||'')+'" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="departH"></td>');
    html.push('<td>'+ showHM(hallH) +'</td>');

    // Status-Badge
    const badge =
      (st === "Parkplatz") ? "secondary" :
      (st === "Nach Hannover" || st === "Auf dem Weg nach Wunstorf" ? "primary" :
      (st === "In Halle Wunstorf" ? "secondary" :
      (st === "In Halle Hannover" || /^In Halle Hannover/.test(st) ? "warning" :
      (st === "Nach Wunstorf" ? "success" : "success"))));
    html.push('<td><span class="badge bg-'+badge+'">'+st+'</span></td>');

    // Hinweis
    html.push(
      '<td style="min-width:260px;">' +
        '<textarea class="form-control form-control-sm" rows="2" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="note">'+(r.note||'')+'</textarea>' +
      '</td>'
    );

    // Meldung (Select + Warum)
    const showWhy = r.reported==="Nein";
    html.push('<td class="d-flex flex-column gap-1" style="min-width:260px;">');
    html.push('<select class="form-select form-select-sm" data-veh="'+veh.id+'" data-row="'+ix+'" data-field="reported">'
      + '<option value="" '+(r.reported===''?'selected':'')+'>–</option>'
      + '<option value="Ja" '+(r.reported==='Ja'?'selected':'')+'>Ja</option>'
      + '<option value="Nein" '+(r.reported==='Nein'?'selected':'')+'>Nein</option>'
      + '<option value="Feierabend" '+(r.reported==='Feierabend'?'selected':'')+'>Feierabend</option>'
      + '</select>');
    html.push('<textarea class="form-control form-control-sm" rows="2" placeholder="Warum?" '
      + 'data-veh="'+veh.id+'" data-row="'+ix+'" data-field="reportedWhy" '
      + 'style="'+(showWhy?'':'display:none')+'">'+(r.reportedWhy||'')+'</textarea>');
    html.push('</td>');

    html.push('</tr>');
  }

  // Letzte Tagesgruppe abschließen
  flushDaySubtotal();

  html.push('</tbody></table>');

  const mount = document.getElementById(mountId);
  if (mount){
    mount.innerHTML = html.join('');

    // Field change handler (inkl. DB Sync) – unverändert, arbeitet über data-row (Originalindex)
    const inputs = mount.querySelectorAll('input,select,textarea');
    for (let q=0; q<inputs.length; q++){
      inputs[q].addEventListener('change', function(ev){
        const v   = ev.target.getAttribute('data-veh');
        const idx = parseInt(ev.target.getAttribute('data-row'),10);
        const f   = ev.target.getAttribute('data-field');
        if (!v || isNaN(idx) || !f) return;

        let val = ev.target.value;
        DRV.state.data[v][idx][f] = val;

        if (f==='reported'){
          const cell = ev.target.closest('td');
          const whyInput = cell && cell.querySelector('textarea[data-field="reportedWhy"]');
          if (whyInput) whyInput.style.display = (val==='Nein' ? '' : 'none');
        }

        saveState();
        renderAll();

        try {
          const row = DRV.state.data[v][idx];
          const dateISO = row.date;
          const tourNo  = serverTourNoForRow(v, idx);
          const payload = {}; payload[f] = val;
          apiStamp(v, dateISO, tourNo, payload).catch(()=>{});
        } catch(e){ /* noop */ }
      });
    }
  }
}



  function onChangeCell(ev){
    var v=ev.target.getAttribute('data-veh'); var i=parseInt(ev.target.getAttribute('data-row'),10); var f=ev.target.getAttribute('data-field');
    if(!v || isNaN(i) || !f) return;
    var val = ev.target.value; if(f==='pauseMin') val = Number(val)||0;
    DRV.state.data[v][i][f] = val; saveState(); renderAll();
    // DB-Sync (Fallback falls dieser Handler genutzt wird)
    try {
  var row = DRV.state.data[v][i];
  var dateISO = row.date;
  var tourNo  = serverTourNoForRow(v, i);   // <- hier auch korrigieren
  var payload = {}; payload[f] = val;
  apiStamp(v, dateISO, tourNo, payload).catch(()=>{});
} catch(e){ /* noop */ }

  }

function renderOverview(){
  function showHM(v){ return (v != null ? fmtHM(v) : ''); }
  const todayISO = isoDate(new Date());

  const head =
    '<tr>'+
      '<th rowspan="2">Fahrzeug</th>'+
      '<th rowspan="2">Datum</th>'+
      '<th rowspan="2">Wochentag</th>'+
      '<th rowspan="2">Tour</th>'+
      '<th rowspan="2">Startzeit</th>'+
      '<th colspan="3" class="table-primary text-center">Wunstorf</th>'+
      '<th colspan="3" class="table-success text-center">Hannover</th>'+
      '<th colspan="3" class="table-warning text-center">Pause</th>'+
      '<th rowspan="2">Status</th>'+
      '<th rowspan="2">Hinweis</th>'+
      '<th rowspan="2">Meldung</th>'+
    '</tr>'+
    '<tr>'+
      '<th>Ankunft</th><th>Abfahrt</th><th>Dauer</th>'+
      '<th>Ankunft</th><th>Abfahrt</th><th>Dauer</th>'+
      '<th>Start</th><th>Ende</th><th>Dauer</th>'+
    '</tr>';

  const rows = [];
  const mon = mondayOf(new Date(DRV.state.monday));
  const days = [];
  for (let i=0;i<5;i++){ const d=new Date(mon); d.setDate(mon.getDate()+i); days.push(isoDate(d)); }

  let weekSumWU=0, weekSumH=0, weekSumPause=0;

  days.forEach(dateISO=>{
    const dayLabel = wday(dateISO)+' '+dateISO;
    let daySumWU=0, daySumH=0, daySumPause=0;

    rows.push('<tr class="table-secondary fw-semibold ov-day-header"><td colspan="17">'+dayLabel+'</td></tr>');

    VEHICLES.forEach(veh=>{
      const arr = (DRV.state.data[veh.id]||[]).filter(r=>r.date===dateISO);
      if(!arr.length) return;

      const vehLabel = veh.title+(veh.plate?' — '+veh.plate:'')+(veh.driver?' — Fahrer: '+veh.driver:'');
      rows.push('<tr class="table-light ov-veh-header"><td colspan="17" class="text-muted fw-semibold">'+vehLabel+'</td></tr>');

      let vSumWU=0,vSumH=0,vSumPause=0;

      arr.forEach(r=>{
        const hallWU = dwellWu(r);
        const hallH  = dwellHan(r);
        const pauseDur = duration(r.pauseStart, r.pauseEnd);
        const st = statusLabel(r);

        vSumWU += (hallWU||0);
        vSumH  += (hallH||0);
        vSumPause += (pauseDur||0);
        daySumWU += (hallWU||0);
        daySumH  += (hallH||0);
        daySumPause += (pauseDur||0);

        rows.push(
          '<tr class="'+(r.date===todayISO?'table-info':'')+'">'+
            '<td>'+veh.title+'</td>'+
            '<td>'+r.date+'</td>'+
            '<td class="text-muted small">'+wday(r.date)+'</td>'+
            '<td>'+r.tour+'</td>'+
            '<td>'+(r.workStart||'')+'</td>'+
            '<td>'+(r.arriveWU||'')+'</td>'+
            '<td>'+(r.departWU||'')+'</td>'+
            '<td>'+showHM(hallWU)+'</td>'+
            '<td>'+(r.arriveH||'')+(r.hannoverHall?' ('+r.hannoverHall+')':'')+'</td>'+
            '<td>'+(r.departH||'')+'</td>'+
            '<td>'+showHM(hallH)+'</td>'+
            '<td>'+(r.pauseStart||'')+'</td>'+
            '<td>'+(r.pauseEnd||'')+'</td>'+
            '<td>'+showHM(pauseDur)+'</td>'+
            '<td><span class="badge bg-'+(
              st==="Parkplatz"?"secondary":
              st==="Nach Hannover"?"primary":
              st==="Nach Wunstorf"?"success":
              st==="In Halle Wunstorf"?"warning":"info"
            )+'">'+st+'</span></td>'+
            '<td style="max-width:320px; white-space:pre-wrap;">'+(r.note||'')+'</td>'+
            '<td style="max-width:320px; white-space:pre-wrap;">'+
              (r.reported||'')+
              ((r.reported==='Nein'&&r.reportedWhy)?' — '+r.reportedWhy:'')+
            '</td>'+
          '</tr>'
        );
      });

      const vehTotal = vSumWU + vSumH;
      rows.push(
        '<tr class="table-info fw-semibold ov-veh-sum">'+
          '<td colspan="7" class="text-end">Summe '+vehLabel+':</td>'+
          '<td>'+fmtHM(vSumWU)+'</td>'+
          '<td></td><td></td>'+
          '<td>'+fmtHM(vSumH)+' <small class="text-muted">(Gesamt: '+fmtHM(vehTotal)+')</small></td>'+
          '<td>'+fmtHM(vSumPause)+'</td><td colspan="5"></td>'+
        '</tr>'
      );
    });

    const dayTotal = daySumWU + daySumH;
    rows.push(
      '<tr class="table-light fw-semibold ov-day-sum">'+
        '<td colspan="7" class="text-end">Tagessumme '+dayLabel+':</td>'+
        '<td>'+fmtHM(daySumWU)+'</td>'+
        '<td></td><td></td>'+
        '<td>'+fmtHM(daySumH)+' <small class="text-muted">(Gesamt: '+fmtHM(dayTotal)+')</small></td>'+
        '<td>'+fmtHM(daySumPause)+'</td>'+
        '<td colspan="5" class="text-end">'+
          '<button type="button" class="btn btn-sm btn-outline-primary drv-mail-btn" data-date="'+dateISO+'">'+
            '<i class="bi bi-envelope"></i> per E-Mail senden'+
          '</button>'+
        '</td>'+
      '</tr>'
    );

    weekSumWU += daySumWU;
    weekSumH  += daySumH;
    weekSumPause += daySumPause;
  });

  const weekTotal = weekSumWU + weekSumH;
  const kpis =
    '<div class="d-flex justify-content-end align-items-center gap-2 mb-2 ov-kpis">'+
      '<span class="badge rounded-pill text-bg-primary-subtle border border-primary-subtle">WU: <strong>'+fmtHM(weekSumWU)+'</strong></span>'+
      '<span class="badge rounded-pill text-bg-success-subtle border border-success-subtle">H: <strong>'+fmtHM(weekSumH)+'</strong></span>'+
      '<span class="badge rounded-pill text-bg-warning-subtle border border-warning-subtle">Pause: <strong>'+fmtHM(weekSumPause)+'</strong></span>'+
      '<span class="badge rounded-pill text-bg-dark-subtle border border-dark-subtle">Netto: <strong>'+fmtHM(weekTotal-weekSumPause)+'</strong></span>'+
    '</div>';

  const wrap = document.getElementById('drvOverviewWrap');
  if (wrap){
    rows.push(
      '<tr class="table-warning fw-semibold ov-week-sum">'+
        '<td colspan="7" class="text-end">Wochensumme:</td>'+
        '<td>'+fmtHM(weekSumWU)+'</td>'+
        '<td></td><td></td>'+
        '<td>'+fmtHM(weekSumH)+' <small class="text-muted">(Gesamt: '+fmtHM(weekTotal)+')</small></td>'+
        '<td>'+fmtHM(weekSumPause)+'</td>'+
        '<td colspan="5"></td>'+
      '</tr>'
    );

    wrap.innerHTML =
      kpis +
      '<table class="table table-hover align-middle">'+
        '<thead>'+head+'</thead>'+
        '<tbody>'+rows.join('')+'</tbody>'+
      '</table>';

    wrap.querySelectorAll('.drv-mail-btn').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const dateISO = btn.getAttribute('data-date');
        sendDayEmail(dateISO);
      });
    });
  }
}



  function sendDayEmail(dateISO){
    var to = "";
    if (window.DRIVERS_CONFIG && window.DRIVERS_CONFIG.emailTo){
      if (Array.isArray(window.DRIVERS_CONFIG.emailTo)) {
        to = window.DRIVERS_CONFIG.emailTo.join(';');
      } else {
        to = String(window.DRIVERS_CONFIG.emailTo).replace(/,/g, ';').replace(/\s*;\s*/g,';');
      }
    }

    var dateObj = new Date(dateISO + "T00:00:00");
    var dateStr = dateObj.toLocaleDateString("de-DE", { day:"2-digit", month:"2-digit", year:"numeric" });

    var now = new Date();
    var timeStr = now.toLocaleTimeString("de-DE", {hour:"2-digit", minute:"2-digit"});

    var subject = "Liste der Fahrer Stand " + dateStr + " um " + timeStr;

    var body = composeDaySummary(dateISO);

    var href = "mailto:" + encodeURIComponent(to)
      + "?subject=" + encodeURIComponent(subject)
      + "&body=" + encodeURIComponent(body);

    window.location.href = href;
  }

  function composeDaySummary(dateISO){
  const lines = [];
  let daySumWU = 0, daySumH = 0;

  VEHICLES.forEach(function(veh){
    const arr = (DRV.state.data[veh.id] || []).filter(function(r){ return r.date === dateISO; });
    if (!arr.length) return;

    let vSumWU = 0, vSumH = 0;
    arr.forEach(function(r){
      vSumWU += (dwellWu(r) || 0);
      vSumH  += (dwellHan(r) || 0);
    });

    let vehLabel = veh.title;
    if (veh.plate)  vehLabel += " — " + veh.plate;
    if (veh.driver) vehLabel += " — Fahrer: " + veh.driver;

    const vehTotal = vSumWU + vSumH;

    // 👉 gewünschte Ausgabe mit „Standzeit …“
    lines.push(
      "Summe " + vehLabel + ":\t" +
      "(Standzeit Wunstorf) " + fmtHM(vSumWU) + "\t\t\t" +
      "(Standzeit Hannover) " + fmtHM(vSumH) + " " +
      "(Standzeit Gesamt: " + fmtHM(vehTotal) + ")"
    );

    daySumWU += vSumWU; 
    daySumH  += vSumH;
  });

  lines.push("");
  lines.push(wday(dateISO) + " " + dateISO);
  return lines.join("\n");
}


  function renderWeek(){
    var mon=mondayOf(new Date(DRV.state.monday)); var days=[], j; for(j=0;j<5;j++){ var d=new Date(mon); d.setDate(mon.getDate()+j); days.push(d); }
    var rows=[]; for(j=0;j<days.length;j++){ var d2=days[j], iso=isoDate(d2);
      var totalsPerVeh = VEHICLES.map(function(v){ var m=totalsByDay(v.id); return fmtHM(m[iso]||0); });
      var all = VEHICLES.reduce(function(acc,v){ var m=totalsByDay(v.id); return acc + (m[iso]||0); }, 0);
      var tds = totalsPerVeh.map(function(t){ return '<td>'+t+'</td>'; }).join('');
      rows.push('<tr class="'+(isoDate(new Date())===iso?'table-info':'')+'"><td>'+d2.toLocaleDateString('de-DE')+'</td><td class="text-muted small">'+wday(iso)+'</td>'+tds+'<td>'+fmtHM(all)+'</td></tr>');
    }
    var headCols = VEHICLES.map(function(v){ return '<th>'+v.title+'</th>'; }).join('');
    var wrap = document.getElementById('drvWeekWrap');
    if(wrap){
      wrap.innerHTML = '<table class="table table-sm"><thead><tr><th>Datum</th><th>Wochentag</th>'+headCols+'<th>Gesamt</th></tr></thead><tbody>'+rows.join('')+'</tbody></table>';
    }
  }

  function renderAll(){
    renderOverview();
    VEHICLES.forEach(function(v){
      var mountId = 'drv_'+v.id+'_wrap';
      renderVeh(v, mountId);
    });
    renderWeek();
    // ganz unten im IIFE, NACH den Definitionen:
    
  }  

  function resetWeek(){
    if(!confirm('Woche wirklich leeren?')) return;
    DRV.state = defaultWeek();
    saveState();
    renderAll();
    // Optional: komplette Woche per API löschen (wenn du einen Endpunkt dafür baust)
  }

  // ---- Time input helpers ----------------------------------------------------
  function duration(a, b){
    var da = parseHM(a), db = parseHM(b);
    if (da==null || db==null || db<da) return null;
    return db - da;
  }
  function dwellWu(row){ return duration(row.arriveWU, row.departWU); }
  function dwellHan(row){ return duration(row.arriveH, row.departH); }
  function prettyHM(min){ return (min==null ? '' : fmtHM(min)); }

  // --- Custom Time Input (HH:MM) Quick-Entry ---
  (function customTimeInput(){
    document.addEventListener('keydown', function(e){
      const el = e.target;
      if (!el.classList.contains('time-input')) return;
      const k = e.key;

      if (/^\d$/.test(k)) {
        e.preventDefault();
        if (!el.dataset.tphase) el.dataset.tphase = 'h';
        let buf = (el.dataset.tbuf || '') + k;
        if (buf.length > 4) buf = k;
        el.dataset.tbuf = buf;

        const val = (el.value || '').trim();
        const currentHH = /^\d{2}:\d{2}$/.test(val) ? val.slice(0,2) : (el.dataset.hh || '00');

        if (el.dataset.tphase === 'h') {
          if (buf.length >= 3) {
            let h, m;
            if (buf.length === 3) { h = buf.slice(0,1); m = buf.slice(1); }
            else { h = buf.slice(0,2); m = buf.slice(2); }
            const hh = String(h).padStart(2,'0');
            const mm = String(m).padStart(2,'0');
            if (+hh < 24 && +mm < 60) {
              el.value = `${hh}:${mm}`;
              el.dispatchEvent(new Event('change', { bubbles:true }));
              el.dataset.tbuf   = '';
              el.dataset.tphase = 'h';
              el.dataset.hh     = hh;
              return;
            }
          }
          if (buf.length === 2) {
            const hh = buf;
            if (+hh < 24) {
              el.value = `${hh}:00`;
              el.dataset.hh     = hh;
              el.dataset.tphase = 'm';
              el.dataset.tbuf   = '';
              return;
            }
          }
          return;
        } else {
          if (buf.length === 2) {
            const mm = buf;
            if (+mm < 60) {
              const hh = (el.dataset.hh || currentHH || '00').padStart(2,'0');
              el.value = `${hh}:${mm}`;
              el.dispatchEvent(new Event('change', { bubbles:true }));
              el.dataset.tbuf   = '';
              el.dataset.tphase = 'h';
              return;
            }
          }
          return;
        }
      }

      if (k === 'Backspace') {
        e.preventDefault();
        if (el.dataset.tbuf && el.dataset.tbuf.length) {
          el.dataset.tbuf = el.dataset.tbuf.slice(0,-1);
        } else if (el.dataset.tphase === 'm') {
          el.dataset.tphase = 'h';
        }
        return;
      }

      if (k === 'Escape') {
        e.preventDefault();
        el.dataset.tbuf = '';
        el.dataset.tphase = 'h';
        return;
      }

      if (k.length === 1) e.preventDefault();
    });

    document.addEventListener('focusin',(e)=>{
      if (e.target.classList.contains('time-input')) {
        e.target.dataset.tbuf = '';
        e.target.dataset.tphase = 'h';
      }
    });
    document.addEventListener('focusout',(e)=>{
      if (e.target.classList.contains('time-input')) {
        e.target.dataset.tbuf = '';
        e.target.dataset.tphase = '';
      }
    });
  })();

function applyServerDay(vehId, dateISO, serverRows){
  const clientRows = (DRV.state.data[vehId] || []).filter(r => r.date === dateISO);

  for (let t=1; t<=TOURS_PER_DAY; t++){
    const s = serverRows.find(x => Number(x.tour) === t);
    const c = clientRows[t-1];
    if (!c) continue;
    if (!s) {
      c.workStart=''; c.arriveWU=''; c.departWU=''; c.arriveH=''; c.departH='';
      c.hannoverHall=''; c.note=''; c.reported=''; c.reportedWhy=''; c.workEnd='';
      c.pauseStart=''; c.pauseEnd='';  // 🟢 hier neu
      continue;
    }
    c.workStart    = s.workStart    || '';
    c.arriveWU     = s.arriveWU     || '';
    c.departWU     = s.departWU     || '';
    c.arriveH      = s.arriveH      || '';
    c.departH      = s.departH      || '';
    c.hannoverHall = s.hannoverHall || '';
    c.note         = s.note         || '';
    c.reported     = s.reported     || '';
    c.reportedWhy  = s.reportedWhy  || '';
    c.workEnd      = s.workEnd      || '';
    c.pauseStart   = s.pauseStart   || '';   // 🟢 neu
    c.pauseEnd     = s.pauseEnd     || '';   // 🟢 neu
  }
}


function serverTourNoForRow(vehId, rowIndex){
  const arr = DRV.state.data[vehId] || [];
  const row = arr[rowIndex];
  if (!row) return 1;
  const dateISO = row.date;
  const indicesSameDate = arr.map((r,i)=>({r,i}))
                             .filter(x => x.r.date === dateISO)
                             .map(x => x.i);
  const pos = indicesSameDate.indexOf(rowIndex);
  return (pos === -1 ? 0 : pos) % TOURS_PER_DAY + 1;
}

function mondayISOFromDateISO(dateISO){
  if (!dateISO) return DRV.state.monday;
  const d = new Date(dateISO + 'T00:00:00');  // lokale Mitternacht
  const wd = d.getDay();                      // 0=So, 1=Mo, ... 6=Sa
  const diff = (wd === 0 ? -6 : 1) - wd;
  d.setDate(d.getDate() + diff);
  return isoDate(d);                           // ← lokal zurückgeben!
}


// Mo–Fr ab gegebenem Montag (LOCAL, kein UTC!)
function weekDatesFromMondayISO(mondayISO){
  const [y,m,dd] = mondayISO.split('-').map(Number);
  const base = new Date(y, m-1, dd);          // lokal
  const out = [];
  for (let i=0;i<5;i++){
    const x = new Date(base);
    x.setDate(base.getDate()+i);
    out.push(isoDate(x));                      // lokal
  }
  return out;
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    let last = '';
    try { last = localStorage.getItem('drv_last_date') || ''; } catch {}
    if (last) {
      DRV.state.monday = mondayISOFromDateISO(last); // ← Woche auf Kiosk-Datum ausrichten
      if (typeof saveState === 'function') saveState();
    }
    await syncWeekFromServer();  // DB → Frontend (für genau diese Woche)
  } finally {
    renderAll();
  }
});

const overviewBtn = document.querySelector('button[data-bs-target="#drvOverview"]');
if (overviewBtn){
  overviewBtn.addEventListener('shown.bs.tab', async () => {
    try {
      const last = localStorage.getItem('drv_last_date') || '';
      if (last) { DRV.state.monday = mondayISOFromDateISO(last); saveState(); }
    } catch {}
    await syncWeekFromServer();
    renderAll();
  });
}

async function syncWeekFromServer(){
  ensureWeekSkeleton(DRV.state.monday);   // Skeleton bereitstellen
  const mondayISO = DRV.state.monday;
  const dates = weekDatesFromMondayISO(mondayISO);

  for (const veh of VEHICLES){
    const vehId = veh.id;
    for (const dateISO of dates){
      try{
        const rows = await (window.apiGetDay ? window.apiGetDay(vehId, dateISO) : Promise.resolve([]));
        applyServerDay(vehId, dateISO, rows || []);
      }catch(e){
        console.warn('get_day failed', vehId, dateISO, e);
      }
    }
  }
  if (typeof saveState === 'function') saveState();
}

  // --- Native time segmented (keine optische Markierung) ---
  (function timeTypingSegmented(){
    function isDigit(k){ return k.length===1 && k>='0' && k<='9'; }
    function trySelectMinutes(el){
      try { if (typeof el.setSelectionRange === 'function') el.setSelectionRange(3, 5); } catch(_) {}
    }
    document.addEventListener('keydown', function(e){
      const el = e.target;
      if (!el.matches('input[type="time"]')) return;

      const k = e.key;
      if (k === 'Tab' || k === 'ArrowLeft' || k === 'ArrowRight' || k === 'Home' || k === 'End' || k === 'Delete') return;

      if (!el.dataset.tphase) { el.dataset.tphase='h'; el.dataset.tbuf=''; el.dataset.hh=''; }

      if (k === ':') {
        e.preventDefault();
        const hh = (el.value && el.value.slice(0,2)) || el.dataset.hh || '00';
        el.value = `${hh}:00`;
        el.dataset.hh     = hh;
        el.dataset.tphase = 'm';
        el.dataset.tbuf   = '';
        trySelectMinutes(el);
        return;
      }

      if (isDigit(k)) {
        e.preventDefault();
        let phase = el.dataset.tphase || 'h';
        let buf   = el.dataset.tbuf   || '';
        buf += k;
        if (buf.length > 2) buf = k;
        el.dataset.tbuf = buf;

        if (phase === 'h') {
          if (buf.length === 2) {
            const hh = String(buf).padStart(2,'0');
            const hNum = +hh;
            if (hNum < 24) {
              el.value       = `${hh}:00`;
              el.dataset.hh  = hh;
              el.dataset.tphase = 'm';
              el.dataset.tbuf   = '';
              trySelectMinutes(el);
            } else {
              el.dataset.tbuf = '';
            }
          }
        } else {
          if (buf.length === 2) {
            const mm = String(buf).padStart(2,'0');
            const mNum = +mm;
            if (mNum < 60) {
              const hh = el.dataset.hh || (el.value.slice(0,2) || '00');
              el.value = `${hh}:${mm}`;
              el.dispatchEvent(new Event('change', { bubbles:true }));
              el.dataset.tphase = 'h';
              el.dataset.tbuf   = '';
            } else {
              el.dataset.tbuf = '';
            }
          }
        }
        return;
      }

      if (k === 'Backspace') {
        e.preventDefault();
        let phase = el.dataset.tphase || 'h';
        let buf   = el.dataset.tbuf   || '';
        if (buf.length) el.dataset.tbuf = buf.slice(0,-1);
        else if (phase === 'm') { el.dataset.tphase='h'; el.dataset.tbuf=''; }
        return;
      }

      if (k === 'Escape') {
        e.preventDefault();
        el.dataset.tphase = 'h';
        el.dataset.tbuf   = '';
        return;
      }

      e.preventDefault();
    });

    document.addEventListener('focusin', (e)=>{
      if (e.target.matches('input[type="time"]')) {
        e.target.dataset.tphase = 'h';
        e.target.dataset.tbuf   = '';
      }
    });
    document.addEventListener('focusout', (e)=>{
      if (e.target.matches('input[type="time"]')) {
        e.target.dataset.tphase = '';
        e.target.dataset.tbuf   = '';
      }
    });
  })();


const driversTabBtn = document.querySelector('button[data-bs-target="#drivers"]');
if (driversTabBtn){
  driversTabBtn.addEventListener('shown.bs.tab', async () => {
    await syncWeekFromServer();
    renderAll();
  });
}


  function statusLabel(r){
    if (r.departH && !r.arriveWU) return "Auf dem Weg nach Wunstorf";
    if (r.arriveH && !r.departH)  return "In Halle Hannover" + (r.hannoverHall ? " ("+r.hannoverHall+")" : "");
    if (r.departWU && !r.arriveH) return "Nach Hannover";
    if (r.arriveWU && !r.departWU) return "In Halle Wunstorf";
    if (r.workStart && !r.arriveWU && !r.departWU && !r.arriveH && !r.departH) return "Parkplatz";
    if (r.arriveH && r.departH && !r.arriveWU) return "Auf dem Weg nach Wunstorf";
    if (r.arriveWU || r.departWU) return "Nach Wunstorf";
    return "Parkplatz";
  }

  // --- Safety shim: falls die Seite keinen Dispo-Header hat, ist ensureConfigButton optional
if (typeof ensureConfigButton !== 'function') {
  function ensureConfigButton(){ /* no-op on driver-only pages */ }
}
  document.addEventListener('DOMContentLoaded', function(){
  // Tabs nur bauen, wenn die Ziel-Elemente existieren
  if (document.getElementById('drvSubTabs')) buildDriverTabs();

  if (typeof ensureConfigButton === 'function') ensureConfigButton();

  var tabBtn = document.querySelector('button[data-bs-target="#drivers"]');
  if (tabBtn){
    tabBtn.addEventListener('shown.bs.tab', function(){
      renderAll();
      if (typeof ensureConfigButton === 'function') ensureConfigButton();
    });
  }

  var resetBtn = document.getElementById('drvBtnReset');
  if (resetBtn){ resetBtn.addEventListener('click', resetWeek); }

  setTimeout(function(){
    var pane = document.getElementById('drivers');
    if (pane && pane.classList.contains('active')) {
      renderAll();
      if (typeof ensureConfigButton === 'function') ensureConfigButton();
    }
  }, 0);
});

// schon vorhandene Exporte ggf. lassen
window.buildDriverTabs    = buildDriverTabs;
window.syncWeekFromServer = syncWeekFromServer;
window.renderAll          = renderAll;

// NEU: nach außen geben, damit die Seite die Woche setzen kann
window.mondayISOFromDateISO = mondayISOFromDateISO;
window.weekDatesFromMondayISO = weekDatesFromMondayISO;
window.DRV = DRV;

function ensureWeekSkeleton(mondayISO){
  const dates = weekDatesFromMondayISO(mondayISO); // Mo–Fr
  VEHICLES.forEach(veh => {
    // Für die Zielwoche immer frische 5*TOURS_PER_DAY Zeilen mit korrekt gesetztem Datum
    const rebuilt = [];
    let tourNo = 1;
    for (let di = 0; di < 5; di++){
      for (let t = 0; t < TOURS_PER_DAY; t++){
        rebuilt.push({
          tour: tourNo++,
          date: dates[di],
          workStart: "",
          arriveWU: "",
          departWU: "",
          arriveH: "",
          departH: "",
          hannoverHall: "",
          note: "",
          reported: "",
          reportedWhy: "",
          workEnd: ""
        });
      }
    }
    DRV.state.data[veh.id] = rebuilt;
  });
}

window.setWeekByDate = function(dateISO){
  if (!dateISO) return;
  const mon = mondayISOFromDateISO(dateISO);
  DRV.state.monday = mon;
  ensureWeekSkeleton(mon);   // <<< wichtig: Skeleton auf neue Woche ausrichten
  if (typeof saveState === 'function') saveState();
};




})();
document.addEventListener('DOMContentLoaded', () => {
  const modalBody = document.getElementById('vehCfgBody');
  const modalSave = document.getElementById('vehCfgSaveBtn');

  modalBody.innerHTML = VEHICLES.map((v, i) => `
    <div class="row g-2 mb-2">
      <div class="col-md-3"><input class="form-control form-control-sm" value="${v.title}" data-index="${i}" data-field="title"></div>
      <div class="col-md-3"><input class="form-control form-control-sm" value="${v.plate}" data-index="${i}" data-field="plate" placeholder="Kennzeichen"></div>
      <div class="col-md-3"><input class="form-control form-control-sm" value="${v.driver}" data-index="${i}" data-field="driver" placeholder="Fahrername"></div>
    </div>
  `).join('');

  modalSave.addEventListener('click', () => {
    modalBody.querySelectorAll('input').forEach(inp => {
      const i = inp.dataset.index;
      const field = inp.dataset.field;
      VEHICLES[i][field] = inp.value.trim();
    });
    saveConfig({ vehicles: VEHICLES, toursPerDay: TOURS_PER_DAY });
    renderAll();
    bootstrap.Modal.getInstance(document.getElementById('vehCfgModal')).hide();
  });
});
