export async function initDashboard(rootEl) {
  console.log("✅ Dashboard initialisiert:", rootEl);

  // lokale Helper
  const $ = s => rootEl.querySelector(s);

  // Standardwerte
  $('#datePicker').value = new Date().toISOString().slice(0, 10);

  // Events (innerhalb des Tabs!)
  $('#btnReload')?.addEventListener('click', loadAll);
  $('#rangeSelect')?.addEventListener('change', () => {
    const isCustom = $('#rangeSelect').value === 'custom';
    $('#datePicker').disabled = !isCustom;
    loadAll();
  });

  // Initial laden
  await loadAll();
  await fillStammdatenTrend();
  await fillWareneingangTrend();
  await fillWarenausgangTrend();

  // Auto-Reload (optional)
  setInterval(() => {
    console.log('[AutoReload] Dashboard aktualisiert');
    loadAll();
    fillStammdatenTrend();
    fillWareneingangTrend();
    fillWarenausgangTrend();
  }, 30000);
}


// ======= CONFIG (nur falls noch nicht gesetzt) =======
const CONFIG = {
  API_BASE: '/LKW/api',
  ENDPOINTS: {
    WE: 'wareneingang_api.php',
    WA: 'warenausgang_api.php',
    SD: 'stammdaten_api.php',
    DRV: { GET_DAY: 'get_day.php' }
  }
};
// Robust: zeigt Antworttext bei Fehler
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, { headers: { 'Accept': 'application/json' }, ...opts });
  const text = await res.text();
  if (!res.ok) {
    // versuche Fehlermeldung aus dem Body anzuzeigen
    let msg = text;
    try { const j = JSON.parse(text); msg = j.error || text; } catch {}
    throw new Error(`${res.status} ${res.statusText} – ${msg}`);
  }
  try { return JSON.parse(text); } catch {
    throw new Error(`Invalid JSON: ${text.slice(0, 200)}`);
  }
}


// ======= Helpers =======
const $ = s => document.querySelector(s);
function toDateOnly(d){ const x=new Date(d); x.setHours(0,0,0,0); return x; }
function within(d, from, to){
  if(!d) return false;
  const x = toDateOnly(d);
  return x >= toDateOnly(from) && x <= toDateOnly(to);
}
// === URL Helper ===
function buildURL(base, params) {
  const u = new URL(base, location.origin);
  for (const [k, v] of Object.entries(params)) {
    if (v != null && v !== '') u.searchParams.append(k, v);
  }
  return u.toString();
}

function parseYMD(s){ // "YYYY-MM-DD" -> Date
  if(!s) return null;
  const [y,m,d] = s.split('-').map(Number);
  if(!y||!m||!d) return null;
  const dt = new Date(y, m-1, d);
  return isNaN(dt) ? null : dt;
}
function pickTs(row){
  // bevorzugt datum + beginn, sonst ankunft, sonst ende
  const base = parseYMD(row.datum);
  if(!base) return null;
  function setHM(dt, hhmm){
    if(!hhmm) return dt;
    const [h,m]=String(hhmm).split(':').map(Number);
    if(Number.isFinite(h) && Number.isFinite(m)) dt.setHours(h,m,0,0);
    return dt;
  }
  const dt = new Date(base);
  return setHM(dt, row.beginn || row.ankunft || row.ende);
}

// ======= Fetch-Wrapper (deine API liefert {ok, items}) =======
async function fetchList(resource){
  const url = `${CONFIG.API_BASE}/${resource}?action=list`;
  const res = await fetch(url, {headers:{'Accept':'application/json'}});
  if(!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  const data = await res.json();
  if(!data.ok || !Array.isArray(data.items)) return [];
  return data.items;
}

// ======= Aggregation WE =======
function aggregateWE(rows, from, to){
  const out = { count:0, pallets:0, units:0, byGroup:{}, last:[] };
  rows.forEach(r=>{
    // Zeitraumfilter über datum
    const d = parseYMD(r.datum);
    if(!within(d, from, to)) return;

    const pal = (Number(r.behaelter)||0) + (Number(r.zus_behaelter)||0);
    const uni = Number(r.menge)||0;
    const lg  = r.lagergruppe || '—';

    out.count++;
    out.pallets += pal;
    out.units   += uni;

    (out.byGroup[lg] ??= { we:0, wa:0, pallets:0 }).we += 1;
    out.byGroup[lg].pallets += pal;

    const ts = pickTs(r);
    out.last.push({
      ts: ts ? ts.toISOString() : r.datum,
      kind: 'WE',
      nr: r.eingang_nr ?? '—',
      lieferschein: r.lieferschein ?? '—',
      lg, pallets: pal, units: uni
    });
  });
  return out;
}

// ======= Aggregation WA =======
function aggregateWA(rows, from, to){
  const out = { count:0, pallets:0, units:0, byGroup:{}, last:[] };
  rows.forEach(r=>{
    const d = parseYMD(r.datum);
    if(!within(d, from, to)) return;

    const pal = (Number(r.behaelter)||0) + (Number(r.zus_behaelter)||0);
    const uni = Number(r.brt_gew)||0; // ggf. auf Stückzahl ändern
    const lg  = r.lagergruppe || '—';

    out.count++;
    out.pallets += pal;
    out.units   += uni;

    (out.byGroup[lg] ??= { we:0, wa:0, pallets:0 }).wa += 1;
    out.byGroup[lg].pallets += pal;

    const ts = pickTs(r);
    out.last.push({
      ts: ts ? ts.toISOString() : r.datum,
      kind: 'WA',
      nr: r.ausgang_nr ?? '—',
      lieferschein: r.lieferschein ?? '—',
      lg, pallets: pal, units: uni
    });
  });
  return out;
}

// ======= Public API für das Dashboard =======
// ersetze frühere getStats(..) Aufrufe durch diese Funktion:
async function getStats(resource, from, to){
  if(resource === CONFIG.ENDPOINTS.WE){
    const rows = await fetchList(resource);
    return aggregateWE(rows, from, to);
  } else if(resource === CONFIG.ENDPOINTS.WA){
    const rows = await fetchList(resource);
    return aggregateWA(rows, from, to);
  } else {
    return { count:0, pallets:0, units:0, byGroup:{}, last:[] };
  }
}

  // Summiere generisch
  function aggregateRows(rows){
    const out = { count:0, pallets:0, units:0, byGroup:{}, last:[] };
    if(!Array.isArray(rows)) return out;
    const palKeys = CONFIG.FIELDS.pallets;
    const unitKeys = CONFIG.FIELDS.units;
    out.count = rows.length;

    rows.forEach(r=>{
      const pal = Number(palKeys.map(k=> r[k]).find(v=> v!=null)) || 0;
      const uni = Number(unitKeys.map(k=> r[k]).find(v=> v!=null)) || 0;
      const lg  = r.lagergruppe || r.lg || '—';
      out.pallets += pal;
      out.units   += uni;
      out.byGroup[lg] ??= { we:0, wa:0, pallets:0 };
      out.byGroup[lg].pallets += pal;
      // Typ-Erkennung für kombinierten Block
      const type = (r.typ || r.type || r.direction || '').toUpperCase();
      out.last.push({
        ts: r.zeitpunkt || r.created_at || r.timestamp || r.zeit || r.datum || null,
        kind: type || '—',
        nr: r.eingang_nr || r.ausgang_nr || r.nr || '—',
        lieferschein: r.lieferschein || r.ls || '—',
        lg, pallets: pal, units: uni
      });
    });
    return out;
  }

async function getDriverStats(day) {
  const ymd = day.toISOString().slice(0,10);

  // 1) Bevorzugt: eigener Stats-Endpoint, wenn vorhanden
  try {
    const d = await fetchJSON(`${CONFIG.API_BASE}/drivers_stats.php?date=${encodeURIComponent(ymd)}`);
    if (d && d.active != null) return d;
  } catch (e) {
    console.warn('drivers_stats.php not available:', e.message);
  }

  // 2) get_day.php — dein Endpoint erwartet veh_id
  const base = `${CONFIG.API_BASE}/${CONFIG.ENDPOINTS.DRV.GET_DAY}`;
  
  // === Hier gibst du die Standard-Fahrzeug-ID ein ===
  // Trag hier ein gültiges Fahrzeug aus deinem System ein:
  // z. B.  veh_id = 1  oder  veh_id = 1001  oder was dein erster LKW ist
  const DEFAULT_VEH_ID = 1;

  const params = new URLSearchParams({
    date: ymd,
    veh_id: DEFAULT_VEH_ID
  });

  try {
    const data = await fetchJSON(`${base}?${params.toString()}`);
    // deine API liefert wahrscheinlich {ok:true, items:[...]}
    if (Array.isArray(data)) return aggregateDriverDay(data);
    if (data && Array.isArray(data.items)) return aggregateDriverDay(data.items);
    if (data && data.active != null) return data;
  } catch (e) {
    console.warn('get_day failed:', e.message);
  }

  // 3) Fallback, wenn alles scheitert
  return { active: 0, avgShiftMin: null, firstStamp: null, activeList: [] };
}



  function aggregateDriverDay(data){
    // Erwartete Struktur (flexibel):
    // [{fahrer:"Max", stamps:[{type:"start", time:"HH:MM"}, {type:"pause_start",...}, ...], status:"working"|"pause"|"off"}]
    if(!Array.isArray(data)) return {active:0, avgShiftMin:null, firstStamp:null, activeList:[]};
    let active=0, totalMin=0, countForAvg=0, firstStamp=null;
    const activeList=[];
    const now = new Date();

    data.forEach(d=>{
      const stamps = Array.isArray(d.stamps) ? d.stamps : [];
      const start = stamps.find(s=> /start/i.test(s.type));
      const end   = stamps.findLast ? stamps.findLast(s=> /end/i.test(s.type)) : [...stamps].reverse().find(s=> /end/i.test(s.type));
      const status = (d.status||'').toLowerCase();

      if(status==='working' || (!end && start)){
        active++;
        // Dauer seit Start
        if(start){
          const [hh,mm]=(start.time||'00:00').split(':').map(Number);
          const started = new Date(now.getFullYear(),now.getMonth(),now.getDate(),hh||0,mm||0,0,0);
          const diffMin = Math.max(0, Math.round((now - started)/60000));
          activeList.push({ name:d.fahrer||'—', start:start.time||'—', sinceMin:diffMin, status:'working' });
        }else{
          activeList.push({ name:d.fahrer||'—', start:'—', sinceMin:null, status:'unknown' });
        }
      }
      // Schichtdauer für Ø falls start & end vorhanden
      if(start && end){
        const [sh,sm]=(start.time||'00:00').split(':').map(Number);
        const [eh,em]=(end.time||'00:00').split(':').map(Number);
        const st=new Date(0,0,0,sh||0,sm||0), en=new Date(0,0,0,eh||0,em||0);
        const dur = Math.round((en-st)/60000);
        if(dur>0){ totalMin += dur; countForAvg++; }
      }
      // First stamp (minimale Zeit)
      (stamps||[]).forEach(s=>{
        if(/start/i.test(s.type) && s.time){
          if(!firstStamp || s.time < firstStamp) firstStamp = s.time;
        }
      });
    });

    return {
      active,
      avgShiftMin: countForAvg? Math.round(totalMin/countForAvg) : null,
      firstStamp: firstStamp || null,
      activeList
    };
  }

async function getStammdatenTrend() {
  try {
    const res = await fetch(`${CONFIG.API_BASE}/stammdaten_stats.php`);
    const text = await res.text(); // → zuerst reiner Text
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text}`);
    const data = JSON.parse(text);
    if (!data.ok || !data.stats) throw new Error("invalid JSON or missing data");
    return data.stats;
  } catch (err) {
    console.error("❌ getStammdatenTrend failed:", err);
    return {}; // Fallback
  }
}



  function fillTopLagergruppen(weStats, waStats, from, to){
    const map = {};
    const add = (src, key) => {
      Object.entries(src.byGroup||{}).forEach(([lg, obj])=>{
        map[lg] ??= { we:0, wa:0, pallets:0 };
        map[lg][key] += (obj.count || obj[key] || 0) || 0; // tolerant
        map[lg].pallets += obj.pallets || 0;
      });
    };
    add(weStats,'we'); add(waStats,'wa');

    const rows = Object.entries(map)
      .map(([lg,v])=> ({ lg, we:v.we, wa:v.wa, pallets:v.pallets }))
      .sort((a,b)=> (b.we+b.wa) - (a.we+a.wa))
      .slice(0,10);

    const tbody = $('#lgBody'); tbody.innerHTML='';
    if(!rows.length){ tbody.innerHTML = `<tr><td colspan="4" class="text-muted">Keine Daten</td></tr>`; return; }
    rows.forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.lg}</td>
        <td class="text-end">${r.we}</td>
        <td class="text-end">${r.wa}</td>
        <td class="text-end">${r.pallets}</td>`;
      tbody.appendChild(tr);
    });
    $('#lgCaption').textContent = `${from.toISOString().slice(0,10)} → ${to.toISOString().slice(0,10)}`;
  }

async function fillStammdatenTrend() {
  const t = await getStammdatenTrend();
  const tbody = document.getElementById('sdTrendBody');
  const wrap = document.querySelector('#sdTrendWrap');
  if (!tbody || !wrap) return;

  // 🧹 ALLES aufräumen – keine alten Infos mehr
  wrap.querySelectorAll('.sd-extra-info, .sd-fade-wrap').forEach(el => el.remove());

  // 🕊 Fade-out
  tbody.closest('table')?.classList.add('fade-target');
  tbody.closest('table')?.classList.remove('visible');

  await new Promise(r => setTimeout(r, 150));

  tbody.innerHTML = '';

  if (!t || !t.speditionen) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Keine Daten vorhanden</td></tr>`;
  } else {
    const makeRow = (label, obj) => `
      <tr>
        <td>${label}</td>
        <td class="text-end fw-semibold">${obj.today}</td>
        <td class="text-end text-muted d-none d-sm-table-cell">${obj.diffs.day}</td>
        <td class="text-end text-muted d-none d-md-table-cell">${obj.diffs.week}</td>
        <td class="text-end text-muted d-none d-lg-table-cell">${obj.diffs.year}</td>
      </tr>
    `;

    tbody.innerHTML = `
      ${makeRow('Speditionen', t.speditionen)}
      ${makeRow('Behälter', t.behaelter)}
      ${makeRow('Sachnummern', t.sachnummern)}
      <tr class="border-top">
        <td><strong>Gesamt</strong></td>
        <td class="text-end fw-bold">${t.total.today}</td>
        <td class="text-end text-muted d-none d-sm-table-cell">${t.total.diffs.day}</td>
        <td class="text-end text-muted d-none d-md-table-cell">${t.total.diffs.week}</td>
        <td class="text-end text-muted d-none d-lg-table-cell">${t.total.diffs.year}</td>
      </tr>
    `;
  }

  // 🪄 Zusatzinfos (einmalig)
  const fadeWrap = document.createElement('div');
  fadeWrap.className = 'sd-fade-wrap fade-target';
  fadeWrap.innerHTML = `
    <div class="text-muted small mt-2 sd-extra-info" style="font-size:.85em;">
      (Vergleich: gestern / Vorwoche / Vorjahr)
    </div>
    ${
      t?.totals_all
        ? `<div class="mt-2 small sd-extra-info">
            <strong>Aktueller Gesamtbestand:</strong><br>
            Speditionen: ${t.totals_all.speditionen.toLocaleString('de-DE')} • 
            Behälter: ${t.totals_all.behaelter.toLocaleString('de-DE')} • 
            Sachnummern: ${t.totals_all.sachnummern.toLocaleString('de-DE')}
          </div>`
        : ''
    }
  `;
  wrap.appendChild(fadeWrap);

  // ✨ Fade-In
  requestAnimationFrame(() => {
    tbody.closest('table')?.classList.add('visible');
    fadeWrap.classList.add('visible');
  });
}
async function fillWareneingangTrend() {
  try {
    const res = await fetch(`${CONFIG.API_BASE}/wareneingang_stats.php`);
    const data = await res.json();
    if (!data.ok || !data.trend) throw new Error("invalid response");

    const t = data.trend;
    const totals = data.totals;
    const el = Array.from(document.querySelectorAll('.card-body'))
      .find(b => b.textContent.includes('Wareneingang'));
    if (!el) return;

    // 🧹 Alte Tabellen & Infos löschen
    el.querySelectorAll(".we-trend-table, .we-total-info").forEach(e => e.remove());

    const fmt = n => Number(n).toLocaleString('de-DE');

    const html = `
      <div class="we-trend-table table-responsive mt-3 small fade-target">
        <table class="table table-sm table-borderless align-middle mb-0 text-nowrap">
          <thead class="text-muted small">
            <tr>
              <th></th>
              <th class="text-end">Heute</th>
              <th class="text-end">Gestern</th>
              <th class="text-end d-none d-md-table-cell">Vorwoche</th>
              <th class="text-end d-none d-lg-table-cell">Vorjahr</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Eingänge</td>
              <td class="text-end fw-semibold">${fmt(t.today.cases)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.cases)}<br><small>${t.diffs.cases.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.cases)}<br><small>${t.diffs.cases.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.cases)}<br><small>${t.diffs.cases.year}</small></td>
            </tr>
            <tr>
              <td>Paletten</td>
              <td class="text-end fw-semibold">${fmt(t.today.pallets)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.pallets)}<br><small>${t.diffs.pallets.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.pallets)}<br><small>${t.diffs.pallets.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.pallets)}<br><small>${t.diffs.pallets.year}</small></td>
            </tr>
            <tr>
              <td>KLTs</td>
              <td class="text-end fw-semibold">${fmt(t.today.klts)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.klts)}<br><small>${t.diffs.klts.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.klts)}<br><small>${t.diffs.klts.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.klts)}<br><small>${t.diffs.klts.year}</small></td>
            </tr>
            <tr>
              <td>Stückzahl</td>
              <td class="text-end fw-semibold">${fmt(t.today.units)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.units)}<br><small>${t.diffs.units.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.units)}<br><small>${t.diffs.units.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.units)}<br><small>${t.diffs.units.year}</small></td>
            </tr>
          </tbody>
        </table>
        <div class="text-muted small mt-2">(Vergleich: heute / gestern / Vorwoche / Vorjahr)</div>
      </div>

      <div class="mt-3 small text-muted we-total-info fade-target">
        <strong>Aktueller Gesamtbestand ${totals.year}:</strong><br>
        Eingänge: ${fmt(totals.cases)} • 
        Paletten: ${fmt(totals.pallets)} • 
        KLTs: ${fmt(totals.klts)} • 
        Stückzahl: ${fmt(totals.units)}
      </div>
    `;

    el.insertAdjacentHTML("beforeend", html);

    // ✨ Fade-In
    requestAnimationFrame(() => {
      el.querySelector(".we-trend-table")?.classList.add("visible");
      el.querySelector(".we-total-info")?.classList.add("visible");
    });
  } catch (err) {
    console.error("fillWareneingangTrend failed:", err);
  }
}
async function fillWarenausgangTrend() {
  try {
    const res = await fetch(`${CONFIG.API_BASE}/warenausgang_stats.php`);
    const data = await res.json();
    if (!data.ok || !data.trend) throw new Error("invalid response");

    const t = data.trend;
    const totals = data.totals;
    const el = Array.from(document.querySelectorAll('.card-body'))
      .find(b => b.textContent.includes('Warenausgang'));
    if (!el) return;

    // 🧹 Alte Tabellen & Infos löschen
    el.querySelectorAll(".wa-trend-table, .wa-total-info").forEach(e => e.remove());

    const fmt = n => Number(n).toLocaleString('de-DE');

    const html = `
      <div class="wa-trend-table table-responsive mt-3 small fade-target">
        <table class="table table-sm table-borderless align-middle mb-0 text-nowrap">
          <thead class="text-muted small">
            <tr>
              <th></th>
              <th class="text-end">Heute</th>
              <th class="text-end">Gestern</th>
              <th class="text-end d-none d-md-table-cell">Vorwoche</th>
              <th class="text-end d-none d-lg-table-cell">Vorjahr</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Ausgänge</td>
              <td class="text-end fw-semibold">${fmt(t.today.cases)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.cases)}<br><small>${t.diffs.cases.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.cases)}<br><small>${t.diffs.cases.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.cases)}<br><small>${t.diffs.cases.year}</small></td>
            </tr>
            <tr>
              <td>Paletten</td>
              <td class="text-end fw-semibold">${fmt(t.today.pallets)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.pallets)}<br><small>${t.diffs.pallets.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.pallets)}<br><small>${t.diffs.pallets.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.pallets)}<br><small>${t.diffs.pallets.year}</small></td>
            </tr>
            <tr>
              <td>KLTs</td>
              <td class="text-end fw-semibold">${fmt(t.today.klts)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.klts)}<br><small>${t.diffs.klts.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.klts)}<br><small>${t.diffs.klts.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.klts)}<br><small>${t.diffs.klts.year}</small></td>
            </tr>
            <tr>
              <td>Stückzahl</td>
              <td class="text-end fw-semibold">${fmt(t.today.units)}</td>
              <td class="text-end text-muted">${fmt(t.yesterday.units)}<br><small>${t.diffs.units.day}</small></td>
              <td class="text-end text-muted d-none d-md-table-cell">${fmt(t.week.units)}<br><small>${t.diffs.units.week}</small></td>
              <td class="text-end text-muted d-none d-lg-table-cell">${fmt(t.year.units)}<br><small>${t.diffs.units.year}</small></td>
            </tr>
          </tbody>
        </table>
        <div class="text-muted small mt-2">(Vergleich: heute / gestern / Vorwoche / Vorjahr)</div>
      </div>

      <!-- 🆕 Gesamtübersicht -->
      <div class="mt-3 small text-muted wa-total-info fade-target">
        <strong>Aktueller Gesamtbestand ${totals.year}:</strong><br>
        Ausgänge: ${fmt(totals.cases)} • 
        Paletten: ${fmt(totals.pallets)} • 
        KLTs: ${fmt(totals.klts)} • 
        Stückzahl: ${fmt(totals.units)}
      </div>
    `;

    el.insertAdjacentHTML("beforeend", html);

    // ✨ Fade-In
    requestAnimationFrame(() => {
      el.querySelector(".wa-trend-table")?.classList.add("visible");
      el.querySelector(".wa-total-info")?.classList.add("visible");
    });
  } catch (err) {
    console.error("fillWarenausgangTrend failed:", err);
  }
}


 function fillDriverTable(drv) {
  const tbody = $('#drvBody');
  tbody.innerHTML = '';
  if (!drv.activeList?.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Keine aktiven Fahrer</td></tr>`;
    return;
  }

  drv.activeList.forEach(d => {
    const tr = document.createElement('tr');
    const status = (d.status || '').toLowerCase();
    const dotClass = status === 'working' ? 'dot-green' :
                     status === 'pause' ? 'dot-gray' : 'dot-red';

    tr.innerHTML = `
      <td>${d.name ?? '—'}</td>
      <td>${d.start ?? '—'}</td>
      <td>${d.sinceMin != null ? fmtDurationMin(d.sinceMin) : '—'}</td>
      <td>${d.position ?? '—'}</td>
      <td><span class="badge-dot ${dotClass}"></span>${status || '—'}</td>
    `;
    tbody.appendChild(tr);
  });
}


  function fillTopKPIs(drv){
  
  // Fahrer (wie zuvor)
  document.getElementById('drvActive').textContent = drv.active ?? 0;
  document.getElementById('drvAvg').textContent    = drv.avgShiftMin!=null ? fmtDurationMin(drv.avgShiftMin) : '–';
  document.getElementById('drvFirst').textContent  = drv.firstStamp ?? '–';
}


  function getRange(){
    const mode = $('#rangeSelect').value;
    const today = new Date(); today.setHours(0,0,0,0);
    if(mode==='today'){
      return { from: today, to: endOfDay(today) };
    }else if(mode==='week'){
      const from = startOfWeek(today);
      return { from, to: endOfDay(new Date()) };
    }else if(mode==='month'){
      const from = startOfMonth(today);
      return { from, to: endOfDay(new Date()) };
    }else{ // custom uses datePicker as single day for now
      const sel = parseDate($('#datePicker').value);
      sel.setHours(0,0,0,0);
      return { from: sel, to: endOfDay(sel) };
    }
  }

async function getStammdatenStats() {
  const trend = await getStammdatenTrend();
  return trend.today; // liefert Spedition, Behälter, Sachnummer, total
}

  async function loadAll(){
    const {from,to} = getRange();
    const [we, wa, sd, drv] = await Promise.all([
      getStats(CONFIG.ENDPOINTS.WE, from, to),
      getStats(CONFIG.ENDPOINTS.WA, from, to),
      getStammdatenStats(),
      getDriverStats(from)
    ]);

    // byGroup might miss counts – ensure have counts in objects:
    // (Optional, falls dein Server schon we/wa pro Gruppe liefert)
    if(we.byGroup){ Object.values(we.byGroup).forEach(o=> o.we ??= 0); }
    if(wa.byGroup){ Object.values(wa.byGroup).forEach(o=> o.wa ??= 0); }

    fillTopKPIs(we, wa, sd, drv);
    fillTopLagergruppen(we, wa, from, to);
    fillDriverTable(drv);
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    // default: aktuelle Woche, datePicker = heute
    $('#datePicker').value = new Date().toISOString().slice(0,10);
    $('#btnReload').addEventListener('click', loadAll);
    $('#rangeSelect').addEventListener('change', ()=>{
      const isCustom = $('#rangeSelect').value==='custom';
      $('#datePicker').disabled = !isCustom;
      loadAll();
    });
    loadAll();
    fillStammdatenTrend();
    fillWareneingangTrend(); 
    fillWarenausgangTrend()   // ✅ lädt Wareneingang-Trend sofort
  });
  async function fetchSDList(type){
  const url = `${CONFIG.API_BASE}/${CONFIG.ENDPOINTS.SD}?action=list&type=${encodeURIComponent(type)}`;
  const res = await fetch(url, { headers:{ 'Accept':'application/json' }});
  if(!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  const data = await res.json();
  // Deine API antwortet: { ok:true, v:..., items:[...] }
  if(!data.ok || !Array.isArray(data.items)) return [];
  return data.items;
}


// === Zeit-Helfer ===
function startOfWeek(d = new Date()) {
  const day = (d.getDay() + 6) % 7; // Montag = 0
  const start = new Date(d);
  start.setDate(d.getDate() - day);
  start.setHours(0, 0, 0, 0);
  return start;
}

function startOfMonth(d = new Date()) {
  const start = new Date(d.getFullYear(), d.getMonth(), 1);
  start.setHours(0, 0, 0, 0);
  return start;
}

function endOfDay(d = new Date()) {
  const end = new Date(d);
  end.setHours(23, 59, 59, 999);
  return end;
}
document.addEventListener('DOMContentLoaded', () => {
  $('#datePicker').value = new Date().toISOString().slice(0,10);
  $('#btnReload').addEventListener('click', loadAll);
  $('#rangeSelect').addEventListener('change', () => {
    const isCustom = $('#rangeSelect').value === 'custom';
    $('#datePicker').disabled = !isCustom;
    loadAll();
  });
  loadAll();


  // 🔁 Auto-Reload alle 30 Sekunden
  setInterval(() => {
  console.log('[AutoReload] Dashboard aktualisiert');
  loadAll();
  fillStammdatenTrend();
  fillWareneingangTrend();
  fillWarenausgangTrend();
}, 60000); // = 60 Sekunden

});

