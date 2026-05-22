// /LKW/js/fahrer_ubersicht.js
export async function initFahrerUebersicht(root) {
  console.log('✅ Fahrer-Übersicht initialisiert');

  // ==== Basis ====
  updateWeekInfo();
  await renderWeek();
  startAutoRefresh();

  // Tab-Logik
  document.querySelectorAll('#mainTabs .nav-link').forEach(tab => {
    tab.addEventListener('shown.bs.tab', e => {
      const id = e.target.getAttribute('href');
      if (id === '#tabOverview') renderWeek();
      if (id === '#tabF1') renderDriver(1);
      if (id === '#tabF2') renderDriver(2);
      if (id === '#tabF3') renderDriver(3);
    });
  });
}

/* ============================================
   🔧 KONFIGURATION & BASISFUNKTIONEN
   ============================================ */
const API_BASE = '/LKW/api';
const ENDPOINTS = { GET_DAY: 'get_day.php' };

function pad2(n){return String(n).padStart(2,'0');}
function isoDate(d){return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;}
function wochentag(d){return d.toLocaleDateString('de-DE',{weekday:'long'});}
function hhmmDiff(start,end){
  if(!start||!end) return '';
  const [h1,m1]=start.split(':').map(Number);
  const [h2,m2]=end.split(':').map(Number);
  const diff=(h2*60+m2)-(h1*60+m1);
  if(diff<0) return '';
  const h=Math.floor(diff/60),m=diff%60;
  return `${h}h ${pad2(m)}m`;
}

async function apiGetDay(vehId,dateISO){
  const url=`${API_BASE}/${ENDPOINTS.GET_DAY}?veh_id=${encodeURIComponent(vehId)}&date=${encodeURIComponent(dateISO)}`;
  const res=await fetch(url,{credentials:'include',cache:'no-store'});
  const j=await res.json().catch(()=>({}));
  if(j.ok&&Array.isArray(j.rows)) return j.rows;
  if(Array.isArray(j)) return j;
  throw new Error(j.error||'Fehler beim Laden');
}
function diffMinutes(start, end) {
  if (!start || !end) return 0;
  const [h1, m1] = start.split(':').map(Number);
  const [h2, m2] = end.split(':').map(Number);
  return Math.max(0, (h2 * 60 + m2) - (h1 * 60 + m1));
}


function statusOf(r){
  if(r.workEnd) return `<span class="badge badge-status status-feier">Feierabend</span>`;
  if(r.pauseStart&&!r.pauseEnd) return `<span class="badge badge-status status-pause">Pause</span>`;
  if(!r.arriveWU) return `<span class="badge badge-status status-fahrt">Unterwegs</span>`;
  if(r.arriveWU&&!r.departWU) return `<span class="badge badge-status status-wunstorf">Wunstorf</span>`;
  if(r.departWU&&!r.arriveH) return `<span class="badge badge-status status-fahrt">→ Hannover</span>`;
  if(r.arriveH&&!r.departH) return `<span class="badge badge-status status-hannover">Hannover ${r.hannoverHall||''}</span>`;
  if(r.hannoverHall2&&!r.arriveH2) return `<span class="badge badge-status status-fahrt">→ ${r.hannoverHall2}</span>`;
  if(r.arriveH2&&!r.departH2) return `<span class="badge badge-status status-hannover">Hannover 2 (${r.hannoverHall2})</span>`;
  return `<span class="badge bg-secondary">Offen</span>`;
}

/* ============================================
   📅 DATUMSHILFEN
   ============================================ */
function mondayOf(d){d=new Date(d);const g=d.getDay(),diff=(g===0?-6:1)-g;d.setDate(d.getDate()+diff);d.setHours(0,0,0,0);return d;}
function weekDays(){
  const mon=mondayOf(new Date());
  return Array.from({length:5},(_,i)=>{
    const x=new Date(mon);x.setDate(mon.getDate()+i);
    return {date:isoDate(x),label:wochentag(x)};
  });
}

/* ============================================
   🧱 TABELLEN RENDERING (editierbar in Fahrer-Tabs)
   ============================================ */
function renderTable(rows) {
  if (!rows.length) return '<div class="text-muted small">Keine Einträge.</div>';

  // prüfen, ob wir uns in einem Fahrertab befinden (nicht in der Übersicht)
  const inDriverTab = document.querySelector('.nav-link.active')?.getAttribute('href')?.startsWith('#tabF');

  return `
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Tour</th>
          <th>Datum</th>
          <th>Start</th>
          <th>WU an</th>
          <th>WU ab</th>
          <th>Dauer WU</th>
          <th>H an</th>
          <th>Halle</th>
          <th>H ab</th>
          <th>Dauer H</th>
          <th>H2 an</th>
          <th>Halle 2</th>
          <th>H2 ab</th>
          <th>Dauer H2</th>
          <th>Pause Start</th>
          <th>Pause Ende</th>
        </tr>
      </thead>
      <tbody>
  ${rows.map(r => `
    <tr>
      <td>${r.tour}</td>
      <td>${r.date}</td>

      <!-- ✏️ Editierbare Felder -->
      <td contenteditable data-field="workStart" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.workStart || ''}</td>
      <td contenteditable data-field="arriveWU" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.arriveWU || ''}</td>
      <td contenteditable data-field="departWU" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.departWU || ''}</td>
      <td>${hhmmDiff(r.arriveWU, r.departWU)}</td>
      <td contenteditable data-field="arriveH" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.arriveH || ''}</td>
      <td contenteditable data-field="hannoverHall" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.hannoverHall || ''}</td>
      <td contenteditable data-field="departH" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.departH || ''}</td>
      <td>${hhmmDiff(r.arriveH, r.departH)}</td>
      <td contenteditable data-field="arriveH2" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.arriveH2 || ''}</td>
      <td contenteditable data-field="hannoverHall2" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.hannoverHall2 || ''}</td>
      <td contenteditable data-field="departH2" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.departH2 || ''}</td>
      <td>${hhmmDiff(r.arriveH2, r.departH2)}</td>
      <td contenteditable data-field="pauseStart" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.pauseStart || ''}</td>
      <td contenteditable data-field="pauseEnd" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}">${r.pauseEnd || ''}</td>
    </tr>
  `).join('')}
</tbody>

    </table>
  </div>`;
}

/* ============================================
   🗓️ KALENDERWOCHE BERECHNEN
   ============================================ */
function getWeekNumber(d) {
  d = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7)); // Donnerstag = Referenz
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
  return weekNo;
}

function updateWeekInfo() {
  const mon = mondayOf(new Date());
  const fri = new Date(mon);
  fri.setDate(mon.getDate() + 4);
  const kw = getWeekNumber(mon);
  const el = document.getElementById("weekInfo");
  if (el)
    el.textContent = `KW ${kw} (${isoDate(mon)} – ${isoDate(fri)})`;
}



/* ============================================
   🧭 WÖCHENTLICHE ÜBERSICHT mit ACCORDION
   ============================================ */
async function renderWeek() {
  const acc = document.getElementById('weekAccordion');
  acc.innerHTML = '<div class="text-muted p-2">Lade Daten…</div>';

  const cfg = JSON.parse(localStorage.getItem('drv_cfg_v1') || '{}');
  const vehicles = cfg.vehicles || [
    { id: 'veh1', title: 'BOH - DT 324' },
    { id: 'veh2', title: 'BOH - DT 988' },
    { id: 'veh3', title: 'BOH - DT 964' }
  ];

  const days = weekDays();
  const todayISO = isoDate(new Date());
  let html = '';

  for (const [i, d] of days.entries()) {
  const collapseId = `collapse${i}`;
  const isToday = d.date === todayISO;

  html += `
    <div class="accordion-item border rounded mb-2 ${isToday ? 'today' : ''}">
      <h2 class="accordion-header" id="heading${i}">
        <button class="accordion-button ${!isToday ? 'collapsed' : ''}"
                type="button" data-bs-toggle="collapse"
                data-bs-target="#${collapseId}" aria-expanded="${isToday}">
          <strong>${d.label}</strong> <span class="text-muted ms-2">(${d.date})</span>
          ${isToday ? `
  <span class="ms-auto d-flex align-items-center gap-2">
    <span class="badge bg-primary">Heute</span>
    
  </span>` : ''}

        </button>
      </h2>
      <div id="${collapseId}" 
           class="accordion-collapse collapse ${isToday ? 'show' : ''}"
           data-bs-parent="#weekAccordion">
        <div class="accordion-body">
          ${await renderDayForAllDrivers(d.date, vehicles)}
        </div>
      </div>
    </div>`;
}

  // Accordion HTML einfügen
  acc.innerHTML = html;

  // ✅ Jetzt alle Badges nachträglich laden
  document.querySelectorAll('.driver-status').forEach(el => {
    const id = el.dataset.id;
    const date = el.dataset.date;
    getGlobalStatusForDriver(id, date).then(html => {
      if (el) el.innerHTML = html || '';
    });
  });
}

/* ============================================
   🧩 GLOBALER STATUS PRO FAHRER – KORREKTUR
   ============================================ */
async function getGlobalStatusForDriver(vehId, dateISO) {
  let rows = [];
  try {
    rows = await apiGetDay(vehId, dateISO);
  } catch {
    return `<span class="badge bg-secondary">Fehler beim Laden</span>`;
  }

  if (!rows.length)
    return `<span class="badge bg-secondary">Keine Daten</span>`;

  // Prüfen, ob Arbeit begonnen und/oder beendet wurde
  const hasStart = rows.some(r => r.workStart);
  const hasEnd   = rows.some(r => r.workEnd);

  // Neueste relevante Zeile (letzte mit irgendeinem Eintrag)
  const r = [...rows].reverse().find(r =>
    r.arriveWU || r.departWU || r.arriveH || r.departH ||
    r.arriveH2 || r.departH2 || r.pauseStart || r.workStart
  ) || rows[0];

  let status = "Offen";
  let cls = "bg-secondary text-light";

  // === Tageslogik ===
  if (hasEnd) {
  const endRow = [...rows].reverse().find(r => r.workEnd);
  const endTime = endRow?.workEnd || "";
  status = endTime ? `Feierabend (${endTime})` : "Feierabend";
  cls = "status-feier";
}

  else if (r.pauseStart && !r.pauseEnd) {
    status = "Pause";
    cls = "status-pause";
  }
  else if (r.departH2) {
    status = "Auf dem Weg nach Wunstorf";
    cls = "status-fahrt";
  }
  else if (r.arriveH2 && !r.departH2) {
    status = `In Halle ${r.hannoverHall2 || 'Hannover 2'}`;
    cls = "status-hannover";
  }
  else if (r.departH) {
    if (r.hannoverHall2 && !r.arriveH2) {
      status = `Auf dem Weg nach Halle ${r.hannoverHall2}`;
      cls = "status-fahrt";
    } else {
      status = "Auf dem Weg nach Wunstorf";
      cls = "status-fahrt";
    }
  }
  else if (r.arriveH && !r.departH) {
    status = `In Halle ${r.hannoverHall || 'Hannover'}`;
    cls = "status-hannover";
  }
  else if (r.departWU && !r.arriveH) {
    status = "Auf dem Weg nach Hannover";
    cls = "status-fahrt";
  }
  else if (r.arriveWU && !r.departWU) {
    status = "In Halle Wunstorf";
    cls = "status-wunstorf";
  }
  else if (hasStart && !hasEnd) {
    status = "Arbeit begonnen";
    cls = "status-fahrt";
  }
  else {
    status = "Noch nicht gestartet";
    cls = "status-feier";
  }

  return `<span class="badge ${cls}">${status}</span>`;
}

async function renderDayForAllDrivers(date, vehicles) {
  let out = '';
  for (const v of vehicles) {
    try {
      const rows = await apiGetDay(v.id, date);

   // === 🧮 BERICHT BERECHNEN ===
let totalWU = 0, totalFahrtWU_H = 0, totalH = 0, totalFahrtH_WU = 0, totalPause = 0;
let tourCount = 0;
let workStartTime = null;
let workEndTime = null;

// 1️⃣ Grundwerte summieren
rows.forEach(r => {
  // Arbeitstag Anfang/Ende (einmalig)
  if (r.workStart && !workStartTime) workStartTime = r.workStart;
  if (r.workEnd) workEndTime = r.workEnd;

  // Wenn in der Tour irgendwas eingetragen ist → Tour zählt
  if (
    r.workStart || r.arriveWU || r.departWU ||
    r.arriveH || r.departH || r.arriveH2 || r.departH2
  ) {
    tourCount++;
  }

  if (r.arriveWU && r.departWU) totalWU += diffMinutes(r.arriveWU, r.departWU);
  if (r.departWU && r.arriveH) totalFahrtWU_H += diffMinutes(r.departWU, r.arriveH);
  if (r.arriveH && r.departH) totalH += diffMinutes(r.arriveH, r.departH);
  if (r.pauseStart && r.pauseEnd) totalPause += diffMinutes(r.pauseStart, r.pauseEnd);
});

// 2️⃣ Fahrten H → WU über Tour-Grenzen
for (let i = 0; i < rows.length; i++) {
  const depH = rows[i].departH;
  if (!depH) continue;
  // Suche nächste Tour mit arriveWU
  const next = rows.slice(i + 1).find(r => r.arriveWU);
  if (next && next.arriveWU) {
    totalFahrtH_WU += diffMinutes(depH, next.arriveWU);
  }
}

// 3️⃣ Gesamtarbeitszeit berechnen
let totalWork = 0;
if (workStartTime && workEndTime) {
  totalWork = diffMinutes(workStartTime, workEndTime);
}

// 4️⃣ Ausgabe formatieren
const fmt = m => m ? `${Math.floor(m / 60)}h ${pad2(m % 60)}m` : '–';

// Bericht
const report = `
  
  <div class="card mt-3 border-0 shadow-sm">
    <div class="card-body py-3">
      <h6 class="card-title mb-3">
        <i class="bi bi-graph-up-arrow me-2 text-primary"></i>
        <strong>Tagesbericht</strong>
      </h6>
      <div class="row small text-muted">
        <div class="col-md-6 mb-1">
          <i class="bi bi-truck-front text-primary me-1"></i>
          <strong>Gefahrene Touren:</strong> ${tourCount}
        </div>
        <div class="col-md-6 mb-1">
          <i class="bi bi-buildings text-success me-1"></i>
          <strong>In Halle Wunstorf:</strong> ${fmt(totalWU)}
        </div>
        <div class="col-md-6 mb-1">
          <i class="bi bi-arrow-right-circle text-info me-1"></i>
          <strong>Fahrt WU → H:</strong> ${fmt(totalFahrtWU_H)}
        </div>
        <div class="col-md-6 mb-1">
          <i class="bi bi-building text-success me-1"></i>
          <strong>In Halle Hannover:</strong> ${fmt(totalH)}
        </div>
        <div class="col-md-6 mb-1">
          <i class="bi bi-arrow-left-circle text-info me-1"></i>
          <strong>Fahrt H → WU:</strong> ${fmt(totalFahrtH_WU)}
        </div>
        <div class="col-md-6 mb-1">
          <i class="bi bi-cup-hot text-warning me-1"></i>
          <strong>Pausenzeit:</strong> ${fmt(totalPause)}
        </div>
        <div class="col-md-12 mt-2">
          <i class="bi bi-clock-history text-secondary me-1"></i>
          <strong>Gesamtarbeitszeit:</strong> ${fmt(totalWork)}
        </div>
      </div>
    </div>
  </div>`;




      // === AUSGABE ===
      out += `
        <h6 class="mt-2 mb-1 text-primary">
          <span class="d-flex align-items-center gap-2">
            <i class="bi bi-truck me-1"></i>${v.title}
            <span class="driver-status small" data-id="${v.id}" data-date="${date}"></span>
          </span>
        </h6>
        ${renderTable(rows)}
        ${report}
      `;

    } catch (e) {
      out += `<div class="text-danger small">Fehler bei ${v.title}: ${e.message}</div>`;
    }
  }
  return out;
}


/* ============================================
   🚛 EINZELNE FAHRER-ANSICHT (editierbar)
   ============================================ */
async function renderDriver(index) {
  const el = document.getElementById(`driver${index}`);
  el.innerHTML = '<div class="text-muted">Lade Daten…</div>';

  // Fahrzeug-Konfiguration laden
  const cfg = JSON.parse(localStorage.getItem('drv_cfg_v1') || '{}');
  const vehicles = cfg.vehicles || [
    { id: 'veh1', title: 'BOH - DT 324' },
    { id: 'veh2', title: 'BOH - DT 988' },
    { id: 'veh3', title: 'BOH - DT 964' }
  ];

  const v = vehicles[index - 1];
  if (!v) {
    el.innerHTML = '<div class="text-danger">Fahrer nicht gefunden.</div>';
    return;
  }

  // 🔹 aktuell aktiven Fahrer global merken
  window.currentVehId = v.id;

  const days = weekDays();
  let html = `<h5 class="mb-3"><i class="bi bi-person-circle me-1"></i>${v.title}</h5>`;

  for (const d of days) {
    try {
      const rows = await apiGetDay(v.id, d.date);

      // 🔹 Fallback veh_id falls leer
      const enrichedRows = rows.map(r => ({
        ...r,
        veh_id: r.veh_id || v.id
      }));

      html += `
      <div class="day-card">
        <div class="day-header d-flex justify-content-between align-items-center">
          <span>${d.label} (${d.date})</span>
          <span id="driver-status-${index}-${d.date}" class="small"></span>
        </div>
        <div class="day-body p-3">${renderTable(enrichedRows)}</div>
      </div>`;

      // Status nachladen
      getGlobalStatusForDriver(v.id, d.date).then(html => {
        const elStatus = document.getElementById(`driver-status-${index}-${d.date}`);
        if (elStatus) elStatus.innerHTML = html || '';
      });

    } catch (e) {
      html += `<div class="text-danger small">${d.label}: ${e.message}</div>`;
    }
  }

  el.innerHTML = html;

  // 🖊️ Eventlistener für editierbare Zellen hinzufügen
  el.querySelectorAll('[contenteditable][data-field]').forEach(td => {
    td.addEventListener('blur', async e => {
      const value = e.target.innerText.trim();
      const veh = e.target.dataset.veh;
      const date = e.target.dataset.date;
      const tour = e.target.dataset.tour;
      const field = e.target.dataset.field;

      if (!veh || !date || !tour || !field) return;

      console.log("🔄 Speichere Änderung:", { veh, date, tour, field, value });

      try {
        const res = await fetch('/LKW/api/update_time.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ veh_id: veh, date, tour, field, value })
        });
        const json = await res.json();
        if (json.ok) {
          e.target.style.background = '#d1e7dd'; // grün kurz aufblitzen
          setTimeout(() => (e.target.style.background = ''), 800);
        } else {
          throw new Error(json.error || 'Fehler beim Speichern');
        }
      } catch (err) {
        console.error(err);
        e.target.style.background = '#f8d7da'; // rot bei Fehler
      }
    });
  });
}

/* ============================================
   🔁 AUTOMATISCHES NEULADEN
   ============================================ */
function startAutoRefresh(){
  setInterval(()=>{
    const active=document.querySelector('.nav-link.active');
    if(!active) return;
    if(active.getAttribute('href')==='#tabOverview') renderWeek();
    else if(active.getAttribute('href')==='#tabF1') renderDriver(1);
    else if(active.getAttribute('href')==='#tabF2') renderDriver(2);
    else if(active.getAttribute('href')==='#tabF3') renderDriver(3);
    const lbl=document.getElementById('statusLbl');
    lbl.textContent='Letzte Aktualisierung: '+new Date().toLocaleTimeString();
  },60000); // alle 60 Sekunden
}

// ============================================
// ✉️ NEUE VERSION: TAGESBERICHT (nur aktueller Tag)
// ============================================
let mailPreviewModal, mailPreviewBody, btnSaveTxt, btnSendOutlook;

document.addEventListener("DOMContentLoaded", () => {
  mailPreviewModal = new bootstrap.Modal(document.getElementById("mailPreviewModal"));
  mailPreviewBody = document.getElementById("mailPreviewBody");
  btnSaveTxt = document.getElementById("btnSaveTxt");
  btnSendOutlook = document.getElementById("btnSendOutlook");
});



/* ============================================
   🔄 Tagesbericht neu berechnen (lokal)
   ============================================ */
function makeDriverReport(rows) {
  let totalWU = 0, totalFahrtWU_H = 0, totalH = 0, totalFahrtH_WU = 0, totalPause = 0;
  let tourCount = 0;
  let workStartTime = null, workEndTime = null;

  function diffMinutes(start, end) {
    if (!start || !end) return 0;
    const [h1, m1] = start.split(":").map(Number);
    const [h2, m2] = end.split(":").map(Number);
    return Math.max(0, (h2 * 60 + m2) - (h1 * 60 + m1));
  }

  rows.forEach(r => {
    if (r.workStart && !workStartTime) workStartTime = r.workStart;
    if (r.workEnd) workEndTime = r.workEnd;
    if (r.workStart || r.arriveWU || r.departWU || r.arriveH || r.departH || r.arriveH2 || r.departH2)
      tourCount++;

    if (r.arriveWU && r.departWU) totalWU += diffMinutes(r.arriveWU, r.departWU);
    if (r.departWU && r.arriveH) totalFahrtWU_H += diffMinutes(r.departWU, r.arriveH);
    if (r.arriveH && r.departH) totalH += diffMinutes(r.arriveH, r.departH);
    if (r.pauseStart && r.pauseEnd) totalPause += diffMinutes(r.pauseStart, r.pauseEnd);
  });

  for (let i = 0; i < rows.length; i++) {
    const depH = rows[i].departH;
    if (!depH) continue;
    const next = rows.slice(i + 1).find(r => r.arriveWU);
    if (next && next.arriveWU)
      totalFahrtH_WU += diffMinutes(depH, next.arriveWU);
  }

  let totalWork = 0;
  if (workStartTime && workEndTime)
    totalWork = diffMinutes(workStartTime, workEndTime);

  const fmt = m => m ? `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m` : '–';

  return `
    <h6 class="card-title mb-3">
      <i class="bi bi-graph-up-arrow me-2 text-primary"></i>
      <strong>Tagesbericht</strong>
    </h6>
    <div class="row small text-muted">
      <div class="col-md-6 mb-1"><i class="bi bi-truck-front text-primary me-1"></i>
        <strong>Gefahrene Touren:</strong> ${tourCount}</div>
      <div class="col-md-6 mb-1"><i class="bi bi-buildings text-success me-1"></i>
        <strong>In Halle Wunstorf:</strong> ${fmt(totalWU)}</div>
      <div class="col-md-6 mb-1"><i class="bi bi-arrow-right-circle text-info me-1"></i>
        <strong>Fahrt WU → H:</strong> ${fmt(totalFahrtWU_H)}</div>
      <div class="col-md-6 mb-1"><i class="bi bi-building text-success me-1"></i>
        <strong>In Halle Hannover:</strong> ${fmt(totalH)}</div>
      <div class="col-md-6 mb-1"><i class="bi bi-arrow-left-circle text-info me-1"></i>
        <strong>Fahrt H → WU:</strong> ${fmt(totalFahrtH_WU)}</div>
      <div class="col-md-6 mb-1"><i class="bi bi-cup-hot text-warning me-1"></i>
        <strong>Pausenzeit:</strong> ${fmt(totalPause)}</div>
      <div class="col-md-12 mt-2"><i class="bi bi-clock-history text-secondary me-1"></i>
        <strong>Gesamtarbeitszeit:</strong> ${fmt(totalWork)}</div>
    </div>`;
}


/* ============================================
   ✏️  EDITIERBARE ZEITFELDER in Fahrer-Tabs + Live-Status + Bericht-Recalc
   ============================================ */
document.addEventListener("blur", async (e) => {
  const td = e.target.closest("td[contenteditable]");
  if (!td) return;

  const value = td.textContent.trim();
  const { field, veh, date, tour } = td.dataset;
  if (!field || !veh || !date || !tour) return;

  try {
    // === Update speichern ===
    const res = await fetch("/LKW/api/update_time.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        veh_id: veh,
        date,
        tour,
        field,
        value,
      }),
    });
    const j = await res.json();
    if (!j.ok) throw new Error(j.error || "Fehler beim Speichern");

    // ✅ Visuelles Feedback
    td.style.background = "#d1e7dd"; // grün blink
    setTimeout(() => (td.style.background = ""), 800);

    // ✅ Fahrer-Status (Badge) live aktualisieren
    const statusEls = document.querySelectorAll(
      `.driver-status[data-id="${veh}"][data-date="${date}"]`
    );
    if (statusEls.length) {
      const html = await getGlobalStatusForDriver(veh, date);
      statusEls.forEach((el) => (el.innerHTML = html));
    }

    // ✅ Tagesbericht unter der Tabelle neu berechnen
    const driverSection = td.closest(".day-card, .accordion-body");
    if (driverSection) {
      const recalcEl = driverSection.querySelector(".card-body");
      if (recalcEl) {
        const newRows = await apiGetDay(veh, date);
        const newHTML = makeDriverReport(newRows); // <-- eigene Funktion unten
        recalcEl.innerHTML = newHTML;
      }
    }

  } catch (err) {
    console.error(err);
    td.style.background = "#f8d7da"; // rot blink
  }
}, true);



