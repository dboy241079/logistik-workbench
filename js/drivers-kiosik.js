
/* ===================== KIOSK (SERVER ONLY) ===================== */
const API_BASE = '/api';   // <— hier anpassen
const ENDPOINTS = {
  GET_DAY: 'get_day.php',
  STAMP:   'stamp.php',
  CLEAR:   'clear_day.php'
};

async function clearDayConfirmed(){
  if (!vehSelect || !dateSelect) return;
  const v = vehSelect.value, d = dateSelect.value;
  try{
    await apiClearDay(v, d);
    setStatus('Tag geleert.', true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Zurücksetzen fehlgeschlagen', false);
  }
}

function openClearDayConfirm(){
  const modalEl = document.getElementById('confirmClearModal');
  const okBtn   = document.getElementById('confirmClearYes');
  const noBtn   = document.getElementById('confirmClearNo');
  const titleEl = document.getElementById('confirmClearTitle');
  const msgEl   = document.getElementById('confirmClearMsg');

  if (!modalEl || !okBtn || !msgEl){ clearDayConfirmed(); return; } // Fallback

  const veh = vehSelect?.value || '';
  const iso = dateSelect?.value || '';
  const todayISO = isoDateLocal(new Date());
  const isToday = (iso === todayISO);
  const dayLabel = isToday ? 'heutigen Tag' : 'ausgewählten Tag';

  // Titel & Nachricht (de)
  if (titleEl) titleEl.textContent = 'Alles löschen?';
  msgEl.innerHTML = `
    <p class="mb-2"><strong>Alle Zeiten für den ${dayLabel} ${iso} sollen gelöscht werden?</strong></p>
    <p class="mb-2 blink-danger">Wenn du das machst, sind alle Zeiten des ${dayLabel}s gelöscht und können <u>nicht</u> wiederhergestellt werden!</p>
    <p class="mb-0">Fortfahren?</p>
  `;

  // Buttons stylen
  okBtn.textContent = 'Ja, alles löschen';
  okBtn.classList.add('btn-danger', 'blink-danger');
  noBtn.textContent = 'Nein';
  noBtn.classList.remove('btn-danger', 'blink-danger');

  const modal = new bootstrap.Modal(modalEl);

  const onYes = async () => {
    okBtn.removeEventListener('click', onYes);
    modal.hide();
    await clearDayConfirmed();
  };
  okBtn.addEventListener('click', onYes, { once:true });

  // Beim Schließen Blinken zurücksetzen (optional)
  modalEl.addEventListener('hidden.bs.modal', () => {
    okBtn.classList.remove('blink-danger');
  }, { once:true });

  modal.show();
}



// === Datums-Utils (lokal, nicht UTC) ===
function pad2(n){ return String(n).padStart(2,'0'); }
function isoDateLocal(d){ // Date -> "YYYY-MM-DD" (lokal)
  return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
}

// Montag aus ISO eines beliebigen Tages bestimmen (lokal)
function mondayISOFromDateISO(dateISO){
  const base = dateISO ? new Date(dateISO) : new Date();
  const wd = base.getDay();            // 0=So, 1=Mo, ... 6=Sa
  const diff = (wd === 0 ? -6 : 1) - wd;
  base.setDate(base.getDate() + diff);
  return isoDateLocal(base);
}


// Woche (Mo–Fr) als ISO-Strings (lokal) erzeugen
function weekDatesFromMondayISO(mondayISO){
  const [y,m,d] = mondayISO.split('-').map(Number);
  const base = new Date(y, m-1, d);                 // lokal
  const out = [];
  for (let i=0; i<5; i++){
    const x = new Date(base);
    x.setDate(base.getDate()+i);
    out.push(isoDateLocal(x));
  }
  return out;
}

const hhmmNow = () => { const d=new Date(); return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`; };

// Server state (nur vom Server)
let dayRows = [];

// DOM-Refs
let vehSelect, dateSelect, previewBody, nowLbl, statusLbl, blockStart, blockRest, btnStart, btnFeier;
let hallModalEl, hallSelectEl, hallSaveBtnEl;

// --- API helpers ---
async function apiGetDay(vehId, dateISO){
  const url = `${API_BASE}/${ENDPOINTS.GET_DAY}?veh_id=${encodeURIComponent(vehId)}&date=${encodeURIComponent(dateISO)}`;
  const res = await fetch(url, { credentials: 'include', cache: 'no-store' });
  if (!res.ok) throw new Error(`HTTP ${res.status} – ${url}`);
  const data = await res.json().catch(()=> ({}));
  if (data && data.ok && Array.isArray(data.rows)) return data.rows;
  if (Array.isArray(data)) return data;
  throw new Error(data?.error || 'Fehler beim Laden des Tages');
}
async function apiStamp(vehId, dateISO, tour, fields){
  const res = await fetch(`${API_BASE}/${ENDPOINTS.STAMP}`, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    credentials:'same-origin',
    cache:'no-store',
    body: JSON.stringify({ veh_id: vehId, date: dateISO, tour, fields })
  });
  const data = await res.json().catch(()=> ({}));
  if (!res.ok || data?.ok !== true) throw new Error(data?.error || `HTTP ${res.status}`);
  return data;
}
async function apiClearDay(vehId, dateISO){
  const res = await fetch(`${API_BASE}/${ENDPOINTS.CLEAR}`, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    credentials:'same-origin',
    cache:'no-store',
    body: JSON.stringify({ veh_id: vehId, date: dateISO })
  });
  const data = await res.json().catch(()=> ({}));
  if (!res.ok || data?.ok !== true) throw new Error(data?.error || `HTTP ${res.status}`);
  return data;
}
// Statusanzeige
function setStatus(msg, ok=true){
  if (!statusLbl) return;
  statusLbl.textContent = msg;
  statusLbl.classList.toggle('text-success', !!ok);
  statusLbl.classList.toggle('text-danger', !ok);
}

// Row-Helfer auf Basis von dayRows
function firstRow(){ return dayRows.length ? { i:0, r:dayRows[0] } : null; }
function lastRow(){  return dayRows.length ? { i:dayRows.length-1, r:dayRows[dayRows.length-1] } : null; }
function findRowForField(field) {
  for (let i = 0; i < dayRows.length; i++) {
    const r = dayRows[i];

    // === Start & erste Wunstorf-Phase ===
    if (field === 'arriveWU' && !r.arriveWU && !r.departWU && i === 0) return { i, r };
    if (field === 'departWU' && r.arriveWU && !r.departWU) return { i, r };

    // === Hannover 1 ===
    if (field === 'arriveH' && r.departWU && !r.arriveH) return { i, r };
    if (field === 'departH' && r.arriveH && !r.departH) return { i, r };

    // === Hannover 2 ===
    if (field === 'arriveH2' && r.hannoverHall2 && !r.arriveH2) return { i, r };
    if (field === 'departH2' && r.arriveH2 && !r.departH2) return { i, r };

    // === Rückfahrt: erlaubt nur, wenn aktuelle Tour komplett fertig ist ===
    const hannoverFertig =
      (r.arriveH && r.departH) &&
      (!r.hannoverHall2 || (r.arriveH2 && r.departH2));

    // wenn diese Tour fertig und die nächste leer ist → dort ankommen
    if (hannoverFertig && i + 1 < dayRows.length) {
      const next = dayRows[i + 1];
      if (field === 'arriveWU' && !next.arriveWU) return { i: i + 1, r: next };
      if (field === 'departWU' && next.arriveWU && !next.departWU) return { i: i + 1, r: next };
    }
  }
  return null;
}

function labelFor(field){
  const map = { arriveWU:'arriveWU', departWU:'departWU', arriveH:'arriveH', departH:'departH', arriveH2:'arriveH2', departH2:'departH2' };
  return t(map[field] || field);
}


let lastStamp = null; // ganz oben im Script definieren (falls noch nicht vorhanden)

function flashCls(r, field){
  return (lastStamp && lastStamp.tour === r.tour && lastStamp.field === field) ? 'flash' : '';
}


function renderPreview(){
  if (!previewBody) return;

  if (!dayRows.length){
    previewBody.innerHTML = `<tr><td colspan="11" class="text-muted">${t('noEntryFound')}</td></tr>`;

    return;
  }

  previewBody.innerHTML = dayRows.map(r => {
  let pauseInfo = '';
if (r.pauseStart && r.pauseEnd) {
  const diff = diffMinutes(r.pauseStart, r.pauseEnd);
  pauseInfo = `${r.pauseStart}–${r.pauseEnd} (${diff} min)`;
} else if (r.pauseStart) {
  pauseInfo = `${r.pauseStart}–…`;
} else if (r.pauseEnd) {
  pauseInfo = `…–${r.pauseEnd}`;
}


  const h1In  = (r.arriveH || '') + (r.hannoverHall ? ` (${r.hannoverHall})` : '');
  const h1Out = r.departH || '';
  const h2In  = (r.arriveH2 || '') + (r.hannoverHall2 ? ` (${r.hannoverHall2})` : '');
  const h2Out = r.departH2 || '';

  const detailsMobile = `
    ${(r.arriveH||'')}${r.hannoverHall ? ' ('+r.hannoverHall+')' : ''}
    ${ (r.arriveH2||r.departH2) ? ` / ${(r.arriveH2||'')}${r.hannoverHall2 ? ' ('+r.hannoverHall2+')' : ''}` : '' }
  `;

  return `
    <tr>
      <td>${r.tour}</td>
      <td>${r.date}</td>
      <td><code>${r.workStart||''}</code></td>

      <td class="d-none d-md-table-cell"><code>${r.arriveWU||''}</code></td>
      <td class="d-none d-md-table-cell"><code>${r.departWU||''}</code></td>

      <td class="d-none d-md-table-cell"><code>${h1In}</code></td>
      <td class="d-none d-md-table-cell"><code>${h1Out}</code></td>
      <td class="d-none d-md-table-cell"><code>${h2In}</code></td>   <!-- 🆕 -->
      <td class="d-none d-md-table-cell"><code>${h2Out}</code></td>  <!-- 🆕 -->

      <td class="d-none d-md-table-cell"><code>${pauseInfo}</code></td>

      <td class="d-table-cell d-md-none"><code>${detailsMobile}</code></td>
    </tr>
  `;
}).join('');

}

function toggleH2Buttons(){
  const btnA2 = document.getElementById('btnArriveH2');
  const btnD2 = document.getElementById('btnDepartH2');
  if (!btnA2 || !btnD2) return;

  const anyH2Open = dayRows.some(r => r.hannoverHall2 && (!r.arriveH2 || !r.departH2));
  btnA2.classList.toggle('d-none', !anyH2Open);
  btnD2.classList.toggle('d-none', !anyH2Open);
}
function refreshVisibility(){
  if (!blockStart || !blockRest){ renderPreview(); return; }
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
  toggleH2Buttons();      // ✅ nur Sichtbarkeit prüfen
  highlightButtons();     // ✅ und Markierung aktualisieren
  updateCurrentStep();

}

window.updateCurrentStep = function() {
  const lbl = document.getElementById('currentStepLbl');
  if (!lbl) return;

  const next = window.getNextField?.();
  if (!next) {
    lbl.textContent = t('allDone');
    lbl.classList.replace('text-primary', 'text-success');
    return;
  }

  let currentTour = 1;
  for (let i = 0; i < dayRows.length; i++) {
    const r = dayRows[i];
    if (!r[next]) { currentTour = r.tour; break; }
  }

  const map = {
    workStart: t('startTime'),
    arriveWU: t('arriveWU'),
    departWU: t('departWU'),
    arriveH:  t('arriveH'),
    departH:  t('departH'),
    arriveH2: t('arriveH') + ' 2',
    departH2: t('departH') + ' 2',
    workEnd:  t('feierabend')
  };

  const label = map[next] || next;
  lbl.textContent = t('currentStep', { label, tour: String(currentTour) });
  lbl.classList.replace('text-success', 'text-primary');
};



// Modal: Halle wählen
function pickHall(preset=''){
  return new Promise(resolve => {
    if (!hallModalEl || !hallSelectEl || !hallSaveBtnEl) { resolve(null); return; }
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

// Laden vom Server
async function reloadDay(){
  if (!vehSelect || !dateSelect) return;
  const v = vehSelect.value, d = dateSelect.value;

  try {
    setStatus('Lade…', true);

    if (!v || !d){
      dayRows = [];
      refreshVisibility();
      return;
    }

    // Daten vom Server laden
    dayRows = await apiGetDay(v, d);

    // Darstellung aktualisieren
    refreshVisibility();

  } catch (err) {
    console.error('Fehler beim Laden des Tages:', err);
    dayRows = [];
    refreshVisibility();
    setStatus(err.message || 'Fehler beim Laden.', false);
  }
}




async function stampField(field){
  const btn = document.querySelector(`[data-field="${field}"]`);
if (btn?.disabled) return;
btn.disabled = true;
setTimeout(() => btn.disabled = false, 1000);

  const t = findRowForField(field);
if (!t) {
  setStatus(`„${labelFor(field)}“ ist bereits gestempelt oder aktuell nicht erlaubt.`, false);
  flashError(document.querySelector(`[data-field="${field}"]`));
  return;
}

  if (!t){ setStatus(`Kein offener Eintrag für „${labelFor(field)}“ gefunden.`, false); return; }

  const now = hhmmNow();
  const payload = { [field]: now };

  // Halle bei Ankunft H1 abfragen (bestehend)
  if (field === 'arriveH'){
    const hall = await pickHall(t.r.hannoverHall || '');
    if (hall === null){ setStatus('Abgebrochen.', false); return; }
    payload.hannoverHall = hall;
  }

  // 🔸 NEU: beim Abfahrt H1 nach zweiter Halle fragen
  if (field === 'departH'){
    // zuerst Abfahrt H1 stempeln
    await apiStamp(vehSelect.value, dateSelect.value, t.r.tour, payload);

    // dann Yes/No-Modal öffnen
    const needSecond = await askMoreHall(); // s.u.
    if (!needSecond){
      setStatus(`Gespeichert: ${labelFor(field)} = ${now}`, true);
      await reloadDay();
      return;
    }

    // Wenn JA → Halle für H2 wählen und „Plan H2“ setzen
    const hall2 = await pickHall(t.r.hannoverHall2 || '');
    if (hall2 === null){
      setStatus('Abgebrochen.', false);
      await reloadDay();
      return;
    }
   


    await apiStamp(vehSelect.value, dateSelect.value, t.r.tour, { hannoverHall2: hall2 });
    // Buttons für H2 sichtbar schalten
    document.getElementById('btnArriveH2')?.classList.remove('d-none');
    document.getElementById('btnDepartH2')?.classList.remove('d-none');

    setStatus(`Abfahrt Hannover = ${now}; zweite Halle: ${hall2} geplant.`, true);
    await reloadDay();
    return;
  }

  // 🔸 NEU: bei Ankunft H2 Hallenwechsel optional ändern (falls gewünscht)
  if (field === 'arriveH2'){
    // Wenn noch keine Halle2 gewählt wurde, jetzt wählen
    const hall2 = t.r.hannoverHall2 || await pickHall('');
    if (hall2 === null){ setStatus('Abgebrochen.', false); return; }
    payload.hannoverHall2 = hall2;
  }

  try{
    await apiStamp(vehSelect.value, dateSelect.value, t.r.tour, payload);
    setStatus(`Gespeichert: ${labelFor(field)} = ${now}`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Stempeln fehlgeschlagen', false);
  }
}

function askMoreHall(){
  return new Promise(resolve=>{
    const el = document.getElementById('moreHallModal');
    if (!el) return resolve(false);
    const yes = document.getElementById('moreHallYes');
    const no  = document.getElementById('moreHallNo');
    const m = new bootstrap.Modal(el);

    const onYes = ()=>{ cleanup(); m.hide(); resolve(true); };
    const onNo  = ()=>{ cleanup(); m.hide(); resolve(false); };
    const cleanup = ()=>{
      yes?.removeEventListener('click', onYes);
      no?.removeEventListener('click', onNo);
      el.removeEventListener('hidden.bs.modal', onNo);
    };

    yes?.addEventListener('click', onYes, { once:true });
    no?.addEventListener('click', onNo,   { once:true });
    // Schließen mit X = Nein
    el.addEventListener('hidden.bs.modal', onNo, { once:true });

    m.show();
  });
}



// Letzten gefüllten Eintrag des Tages ermitteln
function findLastFilled(){
  const fr = firstRow();
  const lr = lastRow();

  // 1) Feierabend zuerst rückgängig machen
  if (fr && fr.r.workEnd) {
    return { tour: fr.r.tour, field: 'workEnd', alsoClearReported: true, lastTour: lr?.r?.tour };
  }

  // 2) Dann die Tour-Felder rückwärts prüfen
  for (let i = dayRows.length - 1; i >= 0; i--){
    const r = dayRows[i];
    if (r.departH)  return { tour: r.tour, field: 'departH' };
    if (r.arriveH)  return { tour: r.tour, field: 'arriveH', extra: ['hannoverHall'] };
    if (r.departWU) return { tour: r.tour, field: 'departWU' };
    if (r.arriveWU) return { tour: r.tour, field: 'arriveWU' };
  }

  // 3) Zuletzt Startzeit
  if (fr && fr.r.workStart) {
    return { tour: fr.r.tour, field: 'workStart' };
  }

  return null;
}

// Payload fürs Leeren bauen (Feld(e) auf "")
function makeClearPayload(info){
  const p = {};
  p[info.field] = ""; // leeren
  if (info.extra && info.extra.length){
    info.extra.forEach(k => { p[k] = ""; });
  }
  return p;
}

// Undo-Action
async function undoLastStamp(){
  if (!vehSelect || !dateSelect) return;
  // Sicherheit: aktuelle Daten verwenden
  await reloadDay();

  const info = findLastFilled();
  if (!info){ setStatus('Nichts zum Löschen gefunden.', false); return; }

  try{
    // Hauptfeld (und evtl. extra wie hannoverHall) leeren
    await apiStamp(vehSelect.value, dateSelect.value, info.tour, makeClearPayload(info));
    lastStamp = { tour: info.tour, field: info.field };

    // Sonderfall: Feierabend → auch "reported" auf letzter Tour zurücknehmen
    if (info.alsoClearReported && info.lastTour){
      await apiStamp(vehSelect.value, dateSelect.value, info.lastTour, { reported:"", reportedWhy:"" });
    }

    setStatus(`Gelöscht: ${labelFor(info.field)} (Tour ${info.tour})`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Löschen fehlgeschlagen', false);
  }
}

async function stampFeierabend(){
  const fr = firstRow(), lr = lastRow();
  if (!fr){ setStatus('Kein Tagesraster.', false); return; }
  try{
    const val = hhmmNow();
const out = await apiStamp(vehSelect.value, dateSelect.value, fr.r.tour, { workEnd: val });
if (lr) await apiStamp(vehSelect.value, dateSelect.value, lr.r.tour, { reported:'Feierabend', reportedWhy:'' });
const serverVal = out?.saved?.value || val;
lastStamp = { tour: fr.r.tour, field: 'workEnd' };
    setStatus(`Feierabend ${serverVal} erfasst.`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || 'Stempeln fehlgeschlagen', false);
  }
}

async function clearDay(){
  if (!vehSelect || !dateSelect) return;
  const v = vehSelect.value, d = dateSelect.value;
  if (!confirm(`Alle Zeiten für ${v} am ${d} wirklich löschen?`)) return;
  try{
    await apiClearDay(v, d);
    setStatus('Tag geleert.', true);
    await reloadDay();
  }catch(e){ setStatus(e.message || 'Zurücksetzen fehlgeschlagen', false); }
}
// Hilfen für Woche

// Woche (Mo–Fr) bauen
function mondayOf(d){ d=new Date(d); const g=d.getDay(), diff=(g===0?-6:1)-g; d.setDate(d.getDate()+diff); d.setHours(0,0,0,0); return d; }

// === Gemeinsame Config lesen (wie auf der Index-Seite) ===
const LS_LAST_DATE_KEY = 'drv_last_date';

const DRV_CFG_KEY = 'drv_cfg_v1';

function loadVehiclesFromConfig(){
  let cfg = null;
  try { cfg = JSON.parse(localStorage.getItem(DRV_CFG_KEY) || 'null'); } catch {}
  if (window.DRIVERS_CONFIG) cfg = Object.assign({}, cfg||{}, window.DRIVERS_CONFIG);

  if (!cfg) cfg = {};
  let vehicles = [];

  if (Array.isArray(cfg.vehicles) && cfg.vehicles.length){
    vehicles = cfg.vehicles.map((v, i) => ({
      id:    v.id    || ('veh'+(i+1)),
      title: v.title || ('Fahrzeug '+(i+1)),
      plate: v.plate || '',
      driver:v.driver|| ''
    }));
  } else {
    const count = Number(cfg.vehicleCount) || 3;
    vehicles = Array.from({length:count}, (_,i)=>({
      id:'veh'+(i+1), title:'Fahrzeug '+(i+1), plate:'', driver:''
    }));
  }
  return vehicles;
}

(async function ensureVehCfgOnKiosk(){
  try{
    const res = await fetch('/api/veh_cfg.php', { credentials:'include', cache:'no-store' });
    const j   = await res.json();
    if (j?.ok && j.cfg) localStorage.setItem('drv_cfg_v1', JSON.stringify(j.cfg));
  }catch(_){}
})();



async function initControls(){
  // Datum: aktuelle Woche, wenn leer
  if (dateSelect && dateSelect.options.length === 0){
    const mon = mondayOf(new Date());
    const days = Array.from({length:5}, (_,i)=>{ const x=new Date(mon); x.setDate(mon.getDate()+i); return x; });
    dateSelect.innerHTML = days.map(d=>{
      const iso = isoDateLocal(d); // <- LOKAL statt toISOString
      const label = d.toLocaleDateString('de-DE',{weekday:'long', day:'2-digit', month:'2-digit'});
      return `<option value="${iso}">${label}</option>`;
    }).join('');
  }

  // Vorauswahl: zuerst „zuletzt benutztes“ Datum aus LocalStorage, sonst heute/erste Option
  let presetISO = '';
  try { presetISO = localStorage.getItem(LS_LAST_DATE_KEY) || ''; } catch {}
  if (presetISO && Array.from(dateSelect.options).some(o => o.value === presetISO)) {
    dateSelect.value = presetISO;
  } else {
    const todayISO = isoDateLocal(new Date()); // <- LOKAL statt toISOString
    dateSelect.value = Array.from(dateSelect.options).some(o=>o.value===todayISO)
      ? todayISO
      : (dateSelect.options[0]?.value || '');
  }
  // Gewählte Vorauswahl gleich persistieren
  try { localStorage.setItem(LS_LAST_DATE_KEY, dateSelect.value); } catch {}

  // ---- Statt des bisherigen "Fallback: nur Fahrzeug 1" ----
const VEHICLES = loadVehiclesFromConfig();

const cfg = JSON.parse(localStorage.getItem('drv_cfg_v1') || '{}');
if (cfg.vehicles?.length){
  vehSelect.innerHTML = cfg.vehicles.map(v => `<option value="${v.id}">${v.title}</option>`).join('');



  // zuletzt gewähltes Fahrzeug wiederherstellen (optional)
  const LS_LAST_VEH_KEY = 'drv_last_veh';
  let lastVeh = '';
  try { lastVeh = localStorage.getItem(LS_LAST_VEH_KEY) || ''; } catch {}
  if (lastVeh && VEHICLES.some(v=>v.id===lastVeh)) {
    vehSelect.value = lastVeh;
  } else {
    vehSelect.value = VEHICLES[0]?.id || 'veh1';
  }

  // Änderung speichern & neu laden
  if (!vehSelect._bound){
    vehSelect.addEventListener('change', () => {
      try { localStorage.setItem(LS_LAST_VEH_KEY, vehSelect.value); } catch {}
      reloadDay();
    });
    vehSelect._bound = true;
  }
}


  // Events nur einmal binden
  if (vehSelect && !vehSelect._bound){
    vehSelect.addEventListener('change', reloadDay);
    vehSelect._bound = true;
  }
  if (dateSelect && !dateSelect._bound){
    dateSelect.addEventListener('change', () => {
      try { localStorage.setItem(LS_LAST_DATE_KEY, dateSelect.value); } catch {}
      reloadDay();
    });
    dateSelect._bound = true;
  }

  // Erst jetzt laden
  await reloadDay();
}


document.addEventListener('DOMContentLoaded', async () => {
  // DOM-Refs
  vehSelect      = document.getElementById('vehSelect');
  dateSelect     = document.getElementById('dateSelect');
  previewBody    = document.getElementById('previewBody');
  nowLbl         = document.getElementById('nowLbl');
  statusLbl      = document.getElementById('statusLbl');
  blockStart     = document.getElementById('blockStart');
  blockRest      = document.getElementById('blockRest');
  btnStart       = document.getElementById('btnStart');
  btnFeier       = document.getElementById('btnFeierabend');
  hallModalEl    = document.getElementById('hallModal');
  hallSelectEl   = document.getElementById('hallSelect');
  hallSaveBtnEl  = document.getElementById('hallSaveBtn');

  // Uhr
  const tick = () => { if (nowLbl) nowLbl.textContent = hhmmNow(); };
  tick(); setInterval(tick, 10000);

  // Buttons/Actions
  const clearBtn = document.getElementById('clearDayBtn');
  if (clearBtn) clearBtn.addEventListener('click', (e)=>{ e.preventDefault(); openClearDayConfirm(); });

  const undoBtn = document.getElementById('undoBtn');
if (undoBtn) undoBtn.addEventListener('click', (e)=>{ e.preventDefault(); undoLastStamp(); });
  if (btnStart) btnStart.addEventListener('click', (e)=>{
  e.preventDefault();
  flashClicked(e.currentTarget);
  stampStart();
});

document.querySelectorAll('[data-field]').forEach(b=>{
  b.addEventListener('click', (e)=>{
    e.preventDefault();
    flashClicked(e.currentTarget);
    stampField(b.getAttribute('data-field'));
  });
});

if (btnFeier) btnFeier.addEventListener('click', (e)=>{
  e.preventDefault();
  flashClicked(e.currentTarget);
  stampFeierabend();
});


  // 🎬 jetzt wirklich initialisieren (füllt Selects & bindet EINMAL die change-Events)
  await initControls();
});

/* ===== KIOSK i18n (de, en, ru, sr, pl, uk, lt) ===== */
(function(){
  const LS_KEY   = 'kiosk_lang';
  const LOCALES  = {de:'de-DE', en:'en-GB', ru:'ru-RU', sr:'sr-RS', pl:'pl-PL', uk:'uk-UA', lt:'lt-LT'};

  // <-- HIER ALLE SPRACHEN REIN -->
  const D = {
    /* ---- German (default) ---- */
    de:{
      driverPanelTitle:'Fahrer Panel',
      helperNote_html:'Mit diesen Tasten schreibt ihr "Jetzt"-Zeiten direkt in die bestehende Woche. (Gleiche Domain wie Dispo-Seite, damit <code>localStorage</code> geteilt wird.)',
      vehicleLabel:'Fahrzeug',
      dateLabel:'Datum (Woche)',
      resetButton:'Zurücksetzen',
      startTime:'Startzeit',
      selectHallTitle:'Halle auswählen',
      hallPlaceholder:'Halle …',
      cancel:'Abbrechen',
      save:'Speichern',
      arriveWU:'Ankunft Wunstorf',
      departWU:'Abfahrt Wunstorf',
      arriveH:'Ankunft Hannover',
      departH:'Abfahrt Hannover',
      feierabend:'Feierabend',
      undo:'Letzten Eintrag löschen',
      thTour:'Tour', thDate:'Datum', thStart:'Start',
      thWUin:'WU an', thWUout:'WU ab', thHin:'H an', thHout:'H ab',
      loading:'Lade…',
      noDayRows:'Kein Tagesraster gefunden.',
      pleaseStartFirst:'Bitte zuerst „Startzeit“ stempeln.',
      workEndCaptured:'Feierabend {time} erfasst.',
      workStartCaptured:'Arbeitsstart {time} erfasst.',
      noEntryFound:'Kein Eintrag gefunden.',
      savedStart:'Gespeichert: Startzeit = {time}',
      savedField:'Gespeichert: {label} = {time}',
      deletedField:'Gelöscht: {label} (Tour {tour})',
      stampFailed:'Stempeln fehlgeschlagen',
      loadFailed:'Fehler beim Laden.',
      clearConfirm:'Alle Zeiten für {veh} am {date} wirklich löschen?',
      dayCleared:'Tag geleert.',
      clearFailed:'Zurücksetzen fehlgeschlagen',
      abortedNoHall:'Abgebrochen (keine Halle gewählt).',
      noOpenEntry:'Kein offener Eintrag für „{label}“ gefunden.',
      nothingToDelete:'Nichts zum Löschen gefunden.',
        pause:'Pause',
  pauseEnd:'Pause Ende',
  thH1in:'H1 an',
  thH1out:'H1 ab',
  thH2in:'H2 an',
  thH2out:'H2 ab',
  thPause:'Pause',
  thDetails:'Details',
  // ==== NEU: Steps / Status ====
  allDone:'Alle Schritte abgeschlossen – Feierabend!',
  currentStep:'Aktueller Schritt: {label} (Tour {tour})',
  // ==== NEU: More-Hall Modal ====
  moreHallTitle:'Weitere Halle in Hannover?',
  moreHallQuestion:'Musst du noch in eine andere Halle (28C, 28, 34) abladen?',
  yes:'Ja',
  no:'Nein',
  // ==== NEU: Pause-Status ====
  pauseStarted:'Pause gestartet: {time}',
  pauseEnded:'Pause beendet: {time}',
  pauseEndedWithMinutes:'Pause beendet: {time} ({mins} Minuten)',
    },
    /* ---- English ---- */
    en:{
      driverPanelTitle:'Driver Kiosk',
      helperNote_html:'Use these buttons to stamp “now” times into the current week. (Same domain as the dispatch page so <code>localStorage</code> is shared.)',
      vehicleLabel:'Vehicle',
      dateLabel:'Date (week)',
      resetButton:'Reset day',
      startTime:'Start time',
      selectHallTitle:'Select hall',
      hallPlaceholder:'Hall …',
      cancel:'Cancel',
      save:'Save',
      arriveWU:'Arrival Wunstorf',
      departWU:'Departure Wunstorf',
      arriveH:'Arrival Hanover',
      departH:'Departure Hanover',
      feierabend:'Clock out',
      undo:'Undo last entry',
      thTour:'Tour', thDate:'Date', thStart:'Start',
      thWUin:'WU in', thWUout:'WU out', thHin:'H in', thHout:'H out',
      loading:'Loading…',
      noDayRows:'No day grid found.',
      pleaseStartFirst:'Please stamp “Start time” first.',
      workEndCaptured:'Clock-out {time} recorded.',
      workStartCaptured:'Work start {time} recorded.',
      noEntryFound:'No entries found.',
      savedStart:'Saved: Start time = {time}',
      savedField:'Saved: {label} = {time}',
      deletedField:'Deleted: {label} (tour {tour})',
      stampFailed:'Stamp failed',
      loadFailed:'Load failed',
      clearConfirm:'Delete all times for {veh} on {date}?',
      dayCleared:'Day cleared.',
      clearFailed:'Reset failed',
      abortedNoHall:'Cancelled (no hall selected).',
      noOpenEntry:'No open entry for “{label}” found.',
      nothingToDelete:'Nothing to delete.',
  pauseEnd:'End break',
  thH1in:'H1 in',
  thH1out:'H1 out',
  thH2in:'H2 in',
  thH2out:'H2 out',
  thPause:'Break',
  thDetails:'Details',
  allDone:'All steps complete – clock out!',
  currentStep:'Current step: {label} (tour {tour})',
  moreHallTitle:'Another hall in Hanover?',
  moreHallQuestion:'Do you need to unload at another hall (28C, 28, 34)?',
  yes:'Yes',
  no:'No',
  pauseStarted:'Break started: {time}',
  pauseEnded:'Break ended: {time}',
  pauseEndedWithMinutes:'Break ended: {time} ({mins} minutes)',
    },
    /* ---- Russian ---- */
    ru:{
      driverPanelTitle:'Панель водителя',
      helperNote_html:'Этими кнопками вы записываете «текущее» время в текущую неделю. (Тот же домен, что и страница диспетчера, поэтому <code>localStorage</code> общий.)',
      vehicleLabel:'Транспорт',
      dateLabel:'Дата (неделя)',
      resetButton:'Сбросить день',
      startTime:'Время начала',
      selectHallTitle:'Выбрать зал',
      hallPlaceholder:'Зал …',
      cancel:'Отмена',
      save:'Сохранить',
      arriveWU:'Прибытие Вунсторф',
      departWU:'Отправление Вунсторф',
      arriveH:'Прибытие Ганновер',
      departH:'Отправление Ганновер',
      feierabend:'Конец смены',
      undo:'Отменить последнюю запись',
      thTour:'Рейс', thDate:'Дата', thStart:'Старт',
      thWUin:'WU приб', thWUout:'WU выб', thHin:'H приб', thHout:'H выб',
      loading:'Загрузка…',
      noDayRows:'Сменное расписание не найдено.',
      pleaseStartFirst:'Сначала отметьте «Время начала».',
      workEndCaptured:'Конец смены {time} записан.',
      workStartCaptured:'Начало работы {time} записано.',
      noEntryFound:'Записей нет.',
      savedStart:'Сохранено: Время начала = {time}',
      savedField:'Сохранено: {label} = {time}',
      deletedField:'Удалено: {label} (рейс {tour})',
      stampFailed:'Ошибка отметки',
      loadFailed:'Ошибка загрузки',
      clearConfirm:'Удалить все отметки для {veh} {date}?',
      dayCleared:'День очищен.',
      clearFailed:'Сброс не выполнен',
      abortedNoHall:'Отменено (зал не выбран).',
      noOpenEntry:'Нет открытой записи для «{label}».',
      nothingToDelete:'Нечего удалять.',
      pause:'Пауза',
  pauseEnd:'Конец паузы',
  thH1in:'H1 приб',
  thH1out:'H1 выб',
  thH2in:'H2 приб',
  thH2out:'H2 выб',
  thPause:'Пауза',
  thDetails:'Детали',
  allDone:'Все шаги выполнены — конец смены!',
  currentStep:'Текущий шаг: {label} (рейс {tour})',
  moreHallTitle:'Другая зона в Ганновере?',
  moreHallQuestion:'Нужно разгрузиться ещё в другой зоне (28C, 28, 34)?',
  yes:'Да',
  no:'Нет',
  pauseStarted:'Пауза началась: {time}',
  pauseEnded:'Пауза закончена: {time}',
  pauseEndedWithMinutes:'Пауза закончена: {time} ({mins} минут)',
    },
    /* ---- Serbian (Latin) ---- */
    sr:{
      driverPanelTitle:'Panel vozača',
      helperNote_html:'Ovim tasterima upisujete vreme „sada“ u tekuću nedelju. (Isti domen kao dispečerska strana, pa je <code>localStorage</code> zajednički.)',
      vehicleLabel:'Vozilo',
      dateLabel:'Datum (nedelja)',
      resetButton:'Resetuj dan',
      startTime:'Početak rada',
      selectHallTitle:'Izaberite halu',
      hallPlaceholder:'Hala …',
      cancel:'Otkaži',
      save:'Sačuvaj',
      arriveWU:'Dolazak Vunstorf',
      departWU:'Polazak Vunstorf',
      arriveH:'Dolazak Hanover',
      departH:'Polazak Hanover',
      feierabend:'Kraj smene',
      undo:'Poništi poslednji unos',
      thTour:'Tura', thDate:'Datum', thStart:'Start',
      thWUin:'WU dol.', thWUout:'WU pol.', thHin:'H dol.', thHout:'H pol.',
      loading:'Učitavam…',
      noDayRows:'Nema dnevne tabele.',
      pleaseStartFirst:'Prvo zabeležite „Početak rada“.',
      workEndCaptured:'Kraj smene {time} zabeležen.',
      workStartCaptured:'Početak rada {time} zabeležen.',
      noEntryFound:'Nema unosa.',
      savedStart:'Sačuvano: Početak = {time}',
      savedField:'Sačuvano: {label} = {time}',
      deletedField:'Obrisano: {label} (tura {tour})',
      stampFailed:'Greška pri obeležavanju',
      loadFailed:'Greška pri učitavanju',
      clearConfirm:'Obrisati sve unose za {veh} dana {date}?',
      dayCleared:'Dan je obrisan.',
      clearFailed:'Reset nije uspeo',
      abortedNoHall:'Otkaženo (hala nije izabrana).',
      noOpenEntry:'Nema otvorenog unosa za „{label}“.',
      nothingToDelete:'Nema šta da se obriše.',
      pause:'Pauza',
  pauseEnd:'Kraj pauze',
  thH1in:'H1 dol.',
  thH1out:'H1 pol.',
  thH2in:'H2 dol.',
  thH2out:'H2 pol.',
  thPause:'Pauza',
  thDetails:'Detalji',
  allDone:'Svi koraci završeni — kraj smene!',
  currentStep:'Trenutni korak: {label} (tura {tour})',
  moreHallTitle:'Još jedna hala u Hanoveru?',
  moreHallQuestion:'Treba li da istovariš u još jednoj hali (28C, 28, 34)?',
  yes:'Da',
  no:'Ne',
  pauseStarted:'Pauza započeta: {time}',
  pauseEnded:'Pauza završena: {time}',
  pauseEndedWithMinutes:'Pauza završena: {time} ({mins} min)',
    },
    /* ---- Polish ---- */
    pl:{
      driverPanelTitle:'Panel kierowcy',
      helperNote_html:'Tymi przyciskami zapisujesz czas „teraz” w bieżącym tygodniu. (Ta sama domena co strona dyspozytora, więc <code>localStorage</code> jest wspólny.)',
      vehicleLabel:'Pojazd',
      dateLabel:'Data (tydzień)',
      resetButton:'Resetuj dzień',
      startTime:'Czas rozpoczęcia',
      selectHallTitle:'Wybierz halę',
      hallPlaceholder:'Hala …',
      cancel:'Anuluj',
      save:'Zapisz',
      arriveWU:'Przyjazd Wunstorf',
      departWU:'Odjazd Wunstorf',
      arriveH:'Przyjazd Hanower',
      departH:'Odjazd Hanower',
      feierabend:'Koniec pracy',
      undo:'Cofnij ostatni wpis',
      thTour:'Tura', thDate:'Data', thStart:'Start',
      thWUin:'WU przyj', thWUout:'WU odj', thHin:'H przyj', thHout:'H odj',
      loading:'Ładowanie…',
      noDayRows:'Brak siatki dnia.',
      pleaseStartFirst:'Najpierw zarejestruj „Czas rozpoczęcia”.',
      workEndCaptured:'Koniec pracy {time} zapisany.',
      workStartCaptured:'Start pracy {time} zapisany.',
      noEntryFound:'Brak wpisów.',
      savedStart:'Zapisano: Start = {time}',
      savedField:'Zapisano: {label} = {time}',
      deletedField:'Usunięto: {label} (tura {tour})',
      stampFailed:'Błąd rejestracji',
      loadFailed:'Błąd ładowania',
      clearConfirm:'Usunąć wszystkie wpisy dla {veh} w dniu {date}?',
      dayCleared:'Dzień wyczyszczony.',
      clearFailed:'Reset nieudany',
      abortedNoHall:'Anulowano (nie wybrano hali).',
      noOpenEntry:'Brak otwartego wpisu dla „{label}”.',
      nothingToDelete:'Brak danych do usunięcia.',
        pause:'Przerwa',
  pauseEnd:'Koniec przerwy',
  thH1in:'H1 przyj',
  thH1out:'H1 odj',
  thH2in:'H2 przyj',
  thH2out:'H2 odj',
  thPause:'Przerwa',
  thDetails:'Szczegóły',
  allDone:'Wszystkie kroki zakończone — koniec pracy!',
  currentStep:'Aktualny krok: {label} (tura {tour})',
  moreHallTitle:'Dodatkowa hala w Hanowerze?',
  moreHallQuestion:'Musisz rozładować jeszcze w innej hali (28C, 28, 34)?',
  yes:'Tak',
  no:'Nie',
  pauseStarted:'Przerwa rozpoczęta: {time}',
  pauseEnded:'Przerwa zakończona: {time}',
  pauseEndedWithMinutes:'Przerwa zakończona: {time} ({mins} min)',
    },
    /* ---- Ukrainian ---- */
    uk:{
      driverPanelTitle:'Панель водія',
      helperNote_html:'Цими кнопками ви записуєте час «зараз» у поточний тиждень. (Той самий домен, що й сторінка диспетчера, тому <code>localStorage</code> спільний.)',
      vehicleLabel:'Транспорт',
      dateLabel:'Дата (тиждень)',
      resetButton:'Скинути день',
      startTime:'Час початку',
      selectHallTitle:'Обрати зал',
      hallPlaceholder:'Зал …',
      cancel:'Скасувати',
      save:'Зберегти',
      arriveWU:'Прибуття Вунсторф',
      departWU:'Відправлення Вунсторф',
      arriveH:'Прибуття Ганновер',
      departH:'Відправлення Ганновер',
      feierabend:'Кінець зміни',
      undo:'Скасувати останній запис',
      thTour:'Рейс', thDate:'Дата', thStart:'Старт',
      thWUin:'WU приб', thWUout:'WU відпр', thHin:'H приб', thHout:'H відпр',
      loading:'Завантаження…',
      noDayRows:'Денну сітку не знайдено.',
      pleaseStartFirst:'Спершу відмітьте «Час початку».',
      workEndCaptured:'Кінець зміни {time} зафіксовано.',
      workStartCaptured:'Початок роботи {time} зафіксовано.',
      noEntryFound:'Записів немає.',
      savedStart:'Збережено: Початок = {time}',
      savedField:'Збережено: {label} = {time}',
      deletedField:'Видалено: {label} (рейс {tour})',
      stampFailed:'Помилка відмітки',
      loadFailed:'Помилка завантаження',
      clearConfirm:'Видалити всі відмітки для {veh} {date}?',
      dayCleared:'День очищено.',
      clearFailed:'Скидання не вдалося',
      abortedNoHall:'Скасовано (зал не обрано).',
      noOpenEntry:'Немає відкритого запису для «{label}».',
      nothingToDelete:'Нічого видаляти.',
        pause:'Перерва',
  pauseEnd:'Кінець перерви',
  thH1in:'H1 приб',
  thH1out:'H1 відпр',
  thH2in:'H2 приб',
  thH2out:'H2 відпр',
  thPause:'Перерва',
  thDetails:'Деталі',
  allDone:'Усі кроки завершено — кінець зміни!',
  currentStep:'Поточний крок: {label} (рейс {tour})',
  moreHallTitle:'Ще один зал у Ганновері?',
  moreHallQuestion:'Потрібно розвантажитись ще в іншому залі (28C, 28, 34)?',
  yes:'Так',
  no:'Ні',
  pauseStarted:'Перерву розпочато: {time}',
  pauseEnded:'Перерву завершено: {time}',
  pauseEndedWithMinutes:'Перерву завершено: {time} ({mins} хв)',
    },
    /* ---- Lithuanian ---- */
    lt:{
      driverPanelTitle:'Vairuotojo skydelis',
      helperNote_html:'Šiais mygtukais įrašote „dabar“ laiką į einamą savaitę. (Tas pats domenas kaip dispečerio puslapis, todėl <code>localStorage</code> bendras.)',
      vehicleLabel:'Transporto priemonė',
      dateLabel:'Data (savaitė)',
      resetButton:'Išvalyti dieną',
      startTime:'Pradžios laikas',
      selectHallTitle:'Pasirinkite salę',
      hallPlaceholder:'Salė …',
      cancel:'Atšaukti',
      save:'Išsaugoti',
      arriveWU:'Atvykimas Vunstorfas',
      departWU:'Išvykimas Vunstorfas',
      arriveH:'Atvykimas Hanoveris',
      departH:'Išvykimas Hanoveris',
      feierabend:'Pamainos pabaiga',
      undo:'Atšaukti paskutinį įrašą',
      thTour:'Reisas', thDate:'Data', thStart:'Startas',
      thWUin:'WU atv', thWUout:'WU išv', thHin:'H atv', thHout:'H išv',
      loading:'Įkeliama…',
      noDayRows:'Dienos tinklelis nerastas.',
      pleaseStartFirst:'Pirmiausia pažymėkite „Pradžios laiką“.',
      workEndCaptured:'Pamainos pabaiga {time} užfiksuota.',
      workStartCaptured:'Darbo pradžia {time} užfiksuota.',
      noEntryFound:'Įrašų nėra.',
      savedStart:'Išsaugota: Pradžia = {time}',
      savedField:'Išsaugota: {label} = {time}',
      deletedField:'Ištrinta: {label} (reisas {tour})',
      stampFailed:'Žymėjimo klaida',
      loadFailed:'Įkėlimo klaida',
      clearConfirm:'Ištrinti visus įrašus {veh} {date}?',
      dayCleared:'Diena išvalyta.',
      clearFailed:'Atstatyti nepavyko',
      abortedNoHall:'Atšaukta (nepasirinkta salė).',
      noOpenEntry:'Nėra atviro įrašo „{label}“.',
      nothingToDelete:'Nėra ką ištrinti.',
        pause:'Pertrauka',
  pauseEnd:'Pertraukos pabaiga',
  thH1in:'H1 atv',
  thH1out:'H1 išv',
  thH2in:'H2 atv',
  thH2out:'H2 išv',
  thPause:'Pertrauka',
  thDetails:'Išsamiau',
  allDone:'Visi žingsniai baigti – pamainos pabaiga!',
  currentStep:'Dabartinis žingsnis: {label} (reisas {tour})',
  moreHallTitle:'Kita salė Hanoverije?',
  moreHallQuestion:'Ar reikia iškrauti dar kitoje salėje (28C, 28, 34)?',
  yes:'Taip',
  no:'Ne',
  pauseStarted:'Pertrauka pradėta: {time}',
  pauseEnded:'Pertrauka baigta: {time}',
  pauseEndedWithMinutes:'Pertrauka baigta: {time} ({mins} min)',
    }
  };

  function t(key, params={}){
    const lang = window.i18n.lang;
    let s = (D[lang] && D[lang][key]) || D.de[key] || key;
    for (const [k,v] of Object.entries(params)) s = s.replaceAll(`{${k}}`, v);
    return s;
  }
  // t() global verfügbar machen (AUSSERHALB der Funktion t)
window.i18n = window.i18n || {};
window.i18n.t = t;
window.t = t; // Short-Alias


 function applyStatic(){
  const $ = (sel)=>document.querySelector(sel);

  const title = document.querySelector('.card-title');
  if (title) title.textContent = t('driverPanelTitle');

  const note = document.querySelector('.card-body p.text-muted');
  if (note) note.innerHTML = t('helperNote_html');

  const vehLbl = $('#vehSelect')?.closest('.col-12, .col-md-6')?.querySelector('label');
  if (vehLbl) vehLbl.textContent = t('vehicleLabel');

  const dateLbl = $('#dateSelect')?.closest('.col-6, .col-md-4')?.querySelector('label');
  if (dateLbl) dateLbl.textContent = t('dateLabel');

  const clearBtn = $('#clearDayBtn'); if (clearBtn) clearBtn.textContent = t('resetButton');
  const startBtn = $('#btnStart');    if (startBtn) startBtn.textContent = t('startTime');

  // Hall modal
  const hallTitle = document.querySelector('#hallModal .modal-title');
  if (hallTitle) hallTitle.textContent = t('selectHallTitle');
  const hallSel = $('#hallSelect');
  if (hallSel && hallSel.options.length) hallSel.options[0].textContent = t('hallPlaceholder');
  const hallCancel = document.querySelector('#hallModal .btn-outline-secondary');
  if (hallCancel) hallCancel.textContent = t('cancel');
  const hallSave = $('#hallSaveBtn'); if (hallSave) hallSave.textContent = t('save');

  // More-hall modal (Yes/No)
  const mhTitle = document.querySelector('#moreHallModal .modal-title');
  if (mhTitle) mhTitle.textContent = t('moreHallTitle');
  const mhBody = document.querySelector('#moreHallModal .modal-body');
  if (mhBody) mhBody.textContent = t('moreHallQuestion');
  const mhNo = document.getElementById('moreHallNo');
  if (mhNo) mhNo.textContent = t('no');
  const mhYes = document.getElementById('moreHallYes');
  if (mhYes) mhYes.textContent = t('yes');

  // BlockRest Buttons
  document.querySelectorAll('#blockRest [data-field]').forEach(b=>{
    const key = b.getAttribute('data-field');
    if (key) b.textContent = t(key);
  });
  const fb = $('#btnFeierabend'); if (fb) fb.textContent = t('feierabend');
  const undo = $('#undoBtn');     if (undo) undo.textContent = t('undo');

  // Pause Buttons (dynamisch erzeugt; existieren erst später)
  const p1 = document.getElementById('btnPause');
  if (p1) p1.textContent = t('pause');
  const p2 = document.getElementById('btnPauseEnd');
  if (p2) p2.textContent = t('pauseEnd');

  // Table headers (alle Spalten)
  const ths = document.querySelectorAll('table thead th');
  if (ths?.length >= 11){
    ths[0].textContent = t('thTour');
    ths[1].textContent = t('thDate');
    ths[2].textContent = t('thStart');
    ths[3].textContent = t('thWUin');
    ths[4].textContent = t('thWUout');
    ths[5].textContent = t('thH1in');
    ths[6].textContent = t('thH1out');
    ths[7].textContent = t('thH2in');
    ths[8].textContent = t('thH2out');
    ths[9].textContent = t('thPause');
    ths[10].textContent = t('thDetails');
  }

  document.documentElement.lang = window.i18n.lang;
}

  function localizeDateOptions(){
    const sel = document.getElementById('dateSelect');
    if (!sel) return;
    const locale = LOCALES[window.i18n.lang] || LOCALES.de;
    [...sel.options].forEach(opt=>{
      const [y,m,d] = (opt.value||'').split('-').map(Number);
      if (!y || !m || !d) return;
      const dt = new Date(y, m-1, d);
      opt.textContent = dt.toLocaleDateString(locale, {weekday:'long', day:'2-digit', month:'2-digit'});
    });
  }

  function setLang(lang){
    window.i18n.lang = (lang in D) ? lang : 'de';
    try{ localStorage.setItem(LS_KEY, window.i18n.lang); }catch(_){}
    applyStatic();
    localizeDateOptions();
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    window.i18n = window.i18n || {};
    const saved = (localStorage.getItem(LS_KEY) || document.documentElement.lang || 'de').split('-')[0];
    window.i18n.lang = (saved in D) ? saved : 'de';
    applyStatic();
    localizeDateOptions();
    // Optional: expose setter, z.B. Dropdown-Hook
    window.setLang = setLang;
  });

window.getNextField = function(){
  const fr = firstRow();
  if (!fr) return null;
  if (!fr.r.workStart) return 'workStart';

  for (let i = 0; i < dayRows.length; i++) {
    const r = dayRows[i];

    // Hinfahrt
    if (!r.arriveWU)               return 'arriveWU';
    if (r.arriveWU && !r.departWU) return 'departWU';
    if (r.departWU && !r.arriveH)  return 'arriveH';
    if (r.arriveH && !r.departH)   return 'departH';

    // Zweite Halle
    if (r.hannoverHall2) {
      if (!r.arriveH2)               return 'arriveH2';
      if (r.arriveH2 && !r.departH2) return 'departH2';
    }

    const hannoverFertig =
      (r.arriveH && r.departH) &&
      (!r.hannoverHall2 || (r.arriveH2 && r.departH2));

    // Nach Hannover fertig? → nächste Tour = Rückfahrt
    if (hannoverFertig) {
      const next = dayRows[i + 1];
      if (next && !next.arriveWU) return 'arriveWU';
      if (next && next.arriveWU && !next.departWU) return 'departWU';
    }
  }

  if (fr.r.workStart && !fr.r.workEnd) return 'workEnd';
  return null;
};



// === Button-Markierung stabil ===
window.highlightButtons = function(){
  // kleine Verzögerung, damit DOM nach reloadDay fertig ist
  setTimeout(() => {
    const next = window.getNextField?.();
    // alles resetten
    document.querySelectorAll('#blockRest .btn').forEach(b => b.classList.remove('btn-next'));
    document.getElementById('btnStart')?.classList.remove('btn-next');
    document.getElementById('btnFeierabend')?.classList.remove('btn-next');
    if (next) {
  document.querySelector(`#blockRest [data-field="${next}"]`)?.classList.add('btn-next');
}
updateCurrentStep(); // <--- NEU


    if (next === 'workStart'){
      document.getElementById('btnStart')?.classList.add('btn-next');
    } else if (next === 'workEnd'){
      document.getElementById('btnFeierabend')?.classList.add('btn-next');
    } else if (next){
      const el = document.querySelector(`#blockRest [data-field="${next}"]`);
      if (el) el.classList.add('btn-next');
    }
  }, 150); // ⏱ kleine Pause verhindert Timing-Fehler
};

// kurzes Klick-Feedback
// kurzes Klick-Feedback (GLOBAL machen)
window.flashClicked = function(el){
  if (!el) return;
  el.classList.add('btn-clicked');
  setTimeout(()=> el.classList.remove('btn-clicked'), 900);
};

window.flashError = function(el){
  if (!el) return;
  el.classList.add('btn-danger');
  setTimeout(() => el.classList.remove('btn-danger'), 600);
};


})();


// === Pause-Funktion ===
let isPaused = false;
let pauseStartTime = null;

// Button erzeugen (am Ende von blockRest hinzufügen)
const pauseBtn = document.createElement('button');
pauseBtn.className = 'btn btn-outline-warning';
pauseBtn.id = 'btnPause';
pauseBtn.textContent = 'Pause';
document.getElementById('blockRest').appendChild(pauseBtn);

const pauseEndBtn = document.createElement('button');
pauseEndBtn.className = 'btn btn-warning';
pauseEndBtn.id = 'btnPauseEnd';
pauseEndBtn.textContent = 'Pause Ende';
pauseEndBtn.style.display = 'none';
document.getElementById('blockRest').appendChild(pauseEndBtn);


// Nach diffMinutes() etc.
function diffMinutes(start, end) {
  if (!start || !end) return 0;
  const [h1, m1] = start.split(':').map(Number);
  const [h2, m2] = end.split(':').map(Number);
  return (h2 * 60 + m2) - (h1 * 60 + m1);
}


async function stampPauseStart() {
  const fr = firstRow();
  if (!fr) { setStatus(t('noDayRows'), false); return; }
  const tourId = fr.r.tour || 1;
  try {
    const val = hhmmNow();
    await apiStamp(vehSelect.value, dateSelect.value, tourId, { pauseStart: val });
    lastStamp = { tour: tourId, field: 'pauseStart' };
    setStatus(t('pauseStarted', { time: val }), true);
    document.getElementById('btnPause').style.display = 'none';
    document.getElementById('btnPauseEnd').style.display = '';
    await reloadDay();
  } catch(e) {
    setStatus(e.message || t('stampFailed'), false);
  }
}

async function stampPauseEnd() {
  const fr = firstRow();
  if (!fr) { setStatus(t('noDayRows'), false); return; }
  const tourId = fr.r.tour || 1;
  try {
    const startVal = fr.r.pauseStart || '';
    const endVal = hhmmNow();
    const diff = diffMinutes(startVal, endVal);
    await apiStamp(vehSelect.value, dateSelect.value, tourId, { pauseEnd: endVal, pauseMinutes: diff });
    lastStamp = { tour: tourId, field: 'pauseEnd' };
    setStatus(t('pauseEndedWithMinutes', { time:endVal, mins:String(diff) }), true);
    document.getElementById('btnPause').style.display = '';
    document.getElementById('btnPauseEnd').style.display = 'none';
    await reloadDay();
  } catch(e) {
    setStatus(e.message || t('stampFailed'), false);
  }
}


async function stampStart(){
  const fr = firstRow();
  if (!fr){ setStatus(t('noDayRows') || 'Kein Tagesraster.', false); return; }
  try{
    const val = hhmmNow();
    const out = await apiStamp(vehSelect.value, dateSelect.value, fr.r.tour, { workStart: val });
    const serverVal = out?.saved?.value || val;
    lastStamp = { tour: fr.r.tour, field: 'workStart' };
    setStatus(t('savedStart', { time: serverVal }) || `Gespeichert: Startzeit = ${serverVal}`, true);
    await reloadDay();
  }catch(e){
    setStatus(e.message || t('stampFailed') || 'Stempeln fehlgeschlagen', false);
  }
}

// Button-Events
pauseBtn.addEventListener('click', e => {
  e.preventDefault();
  flashClicked(e.currentTarget);
  stampPauseStart();
});

pauseEndBtn.addEventListener('click', e => {
  e.preventDefault();
  flashClicked(e.currentTarget);
  stampPauseEnd();
});


document.addEventListener('DOMContentLoaded', () => {
  if (!window.setLang || !window.i18n) return;

  const codes = ['de','en','ru','sr','pl','uk','lt'];
  const NAMES = {de:'Deutsch', en:'English', ru:'Русский', sr:'Srpski', pl:'Polski', uk:'Українська', lt:'Lietuvių'};

  const cardBody = document.querySelector('.card-body');
  if (!cardBody || document.getElementById('langSwitch')) return;

  const wrapper = document.createElement('div');
  wrapper.className = 'd-flex justify-content-end mb-2';
  wrapper.innerHTML = `
    <div class="dropdown" id="langSwitch">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        ${NAMES[window.i18n.lang] || window.i18n.lang}
      </button>
      <ul class="dropdown-menu dropdown-menu-end"></ul>
    </div>`;
  cardBody.prepend(wrapper);

  const ul = wrapper.querySelector('.dropdown-menu');
  codes.forEach(code => {
    const li = document.createElement('li');
    li.innerHTML = `<a class="dropdown-item" href="#" data-code="${code}">${NAMES[code] || code}</a>`;
    ul.appendChild(li);
  });

  ul.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-code]');
    if (!a) return;
    e.preventDefault();
    const code = a.getAttribute('data-code');
    window.setLang(code);
    wrapper.querySelector('button').textContent = NAMES[code] || code;
  });
});
