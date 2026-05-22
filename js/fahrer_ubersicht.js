let __VEHICLES__ = null;

async function getVehicles() {
  if (__VEHICLES__) return __VEHICLES__;

  // 1) Immer zuerst API
  try {
    const res = await fetch('/api/veh_cfg_get.php', {
      cache: 'no-store',
      credentials: 'include'
    });

    const j = await res.json().catch(() => ({}));

    if (j.ok && j.cfg?.vehicles?.length) {
      __VEHICLES__ = j.cfg.vehicles;

      // Cache aktualisieren
      try {
        localStorage.setItem('drv_cfg_v1', JSON.stringify(j.cfg));
      } catch {}

      return __VEHICLES__;
    }
  } catch (err) {
    console.warn('veh_cfg_get.php konnte nicht geladen werden, nutze Cache/Fallback.', err);
  }

  // 2) localStorage als Fallback
  try {
    const ls = localStorage.getItem('drv_cfg_v1');
    if (ls) {
      const cfg = JSON.parse(ls);
      if (cfg?.vehicles?.length) {
        __VEHICLES__ = cfg.vehicles;
        return __VEHICLES__;
      }
    }
  } catch {}

  // 3) harter Fallback
  __VEHICLES__ = [
    { id: "veh1", title: "BOH - DT 328", plate: "BOH - DT 328" },
    { id: "veh2", title: "BOH - DT 988", plate: "BOH - DT 988" },
    { id: "veh3", title: "BOH - DT 964", plate: "BOH - DT 964" }
  ];

  return __VEHICLES__;
}


 
 // ✅ Modul-scope: für init + reloadActiveTab nutzbar
function getTarget(el) {
  const raw =
    el.getAttribute('data-bs-target') ||
    el.getAttribute('href') ||
    '';

  // wenn raw eine URL ist -> nur hash verwenden
  try {
    const u = new URL(raw, location.href);
    return u.hash || raw; // "#tabF1"
  } catch {
    const i = raw.indexOf('#');
    return i >= 0 ? raw.slice(i) : raw; // "#tabF1"
  }
}

 // /LKW/js/fahrer_ubersicht.js
export async function initFahrerUebersicht(root) {
  // ==== Fahrer-Tabs automatisch mit echten Namen beschriften ====
  try {
    const cfg = JSON.parse(localStorage.getItem("drv_cfg_v1") || "{}");
   const vehicles = await getVehicles();

    vehicles.forEach((v, i) => {
  const tab = document.querySelector(
    `#drvTabs .nav-link[href="#tabF${i + 1}"], #drvTabs .nav-link[data-bs-target="#tabF${i + 1}"]`
  );

  if (tab) {
    tab.innerHTML = `<i class="bi bi-person-circle me-1"></i>${labelOf(v)}`;
  }
});
  } catch (err) {
    console.warn("⚠️ Fahrer-Tabs konnten nicht aktualisiert werden:", err);
  }

  // Week-Navigation initialisieren
  attachWeekNav();
  updateWeekInfo();
  await renderWeek();
  startAutoRefresh();
  initPasteImportUI();



  console.log(
  'driver1', !!document.getElementById('driver1'),
  'driver2', !!document.getElementById('driver2'),
  'driver3', !!document.getElementById('driver3')
);
// ==== Tabs per Klick steuern (robust) ====
// ==== Tabs per Klick steuern (100% robust, mit Fallback ohne Bootstrap) ====
const Tab = window.bootstrap?.Tab;

// Fallback: Tabs ohne Bootstrap aktivieren
function activateTabFallback(targetSel){
  // nav-links
 document.querySelectorAll('#drvTabs .nav-link').forEach(l=>{
  console.log(
    l.textContent.trim(),
    'data-bs-target=', l.getAttribute('data-bs-target'),
    'hrefAttr=', l.getAttribute('href'),
    'hash=', l.hash
  );
});


  // tab panes
  document.querySelectorAll('.tab-pane').forEach(p => {
    const isOn = ('#' + p.id) === targetSel;
    p.classList.toggle('active', isOn);
    p.classList.toggle('show', isOn);
  });
}

document.querySelectorAll('#drvTabs .nav-link').forEach(link => {
  link.addEventListener('click', async (e) => {
    const target = getTarget(link);
    if (!target) return;

    e.preventDefault();

    if (Tab) {
      Tab.getOrCreateInstance(link).show();
    } else {
      activateTabFallback(target);
    }

    // ✅ Auto-Refresh nur in Overview
    if (target === '#tabOverview') {
      startAutoRefresh();
      await renderWeek();
    } else {
      stopAutoRefresh();
      if (target === '#tabF1')      await renderDriver(1);
      else if (target === '#tabF2') await renderDriver(2);
      else if (target === '#tabF3') await renderDriver(3);
    }
  });
});


  console.log("🚛 Fahrerübersicht vollständig initialisiert");
}




/* ============================================
   🔧 KONFIGURATION & BASISFUNKTIONEN
   ============================================ */
const API_BASE = '/api';
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

// 0 = aktuelle Woche, -1 = letzte Woche, +1 = nächste Woche
let weekOffset = 0;

function mondayOf(d) {
  d = new Date(d);
  const g = d.getDay(), diff = (g === 0 ? -6 : 1) - g;
  d.setDate(d.getDate() + diff);
  d.setHours(0, 0, 0, 0);
  return d;
}

// Basis-Montag der aktuell ausgewählten Woche
function getWeekBaseDate() {
  const mon = mondayOf(new Date());
  mon.setDate(mon.getDate() + weekOffset * 7);
  return mon;
}

// erzeugt die 5 Tage (Mo–Fr) der aktuell gewählten Woche
function weekDays() {
  const mon = getWeekBaseDate();
  return Array.from({ length: 5 }, (_, i) => {
    const x = new Date(mon);
    x.setDate(mon.getDate() + i);
    return {
      date: isoDate(x),
      label: wochentag(x)
    };
  });
}

/* ============================================
   🧱 TABELLEN RENDERING (editierbar in Fahrer-Tabs)
   ============================================ */
function renderTable(rows, { editable = false } = {}) {
  if (!rows.length) return '<div class="text-muted small">Keine Einträge.</div>';

  const CE = (field, r) => {
    const attrs = `data-field="${field}" data-veh="${r.veh_id}" data-date="${r.date}" data-tour="${r.tour}"`;
    const val = r[field] || '';
    return editable
      ? `<td contenteditable ${attrs}>${val}</td>`
      : `<td ${attrs}>${val}</td>`;
  };

  const diff = (a,b) => hhmmDiff(a,b);

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
          <th>Feierabend</th> <!-- NEU -->
        </tr>
      </thead>
      <tbody>
        ${rows.map(r => `
          <tr>
            <td>${r.tour}</td>
            <td>${r.date}</td>

            ${CE('workStart', r)}
            ${CE('arriveWU', r)}
            ${CE('departWU', r)}
            <td>${diff(r.arriveWU, r.departWU)}</td>

            ${CE('arriveH', r)}
            ${CE('hannoverHall', r)}
            ${CE('departH', r)}
            <td>${diff(r.arriveH, r.departH)}</td>

            ${CE('arriveH2', r)}
            ${CE('hannoverHall2', r)}
            ${CE('departH2', r)}
            <td>${diff(r.arriveH2, r.departH2)}</td>

            ${CE('pauseStart', r)}
            ${CE('pauseEnd', r)}
            ${CE('workEnd', r)} <!-- NEU -->
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
  const mon = getWeekBaseDate();     // <-- wichtig: gleiche Basis wie weekDays()
  const fri = new Date(mon);
  fri.setDate(mon.getDate() + 4);
  const kw = getWeekNumber(mon);
  const el = document.getElementById("weekInfo");
  if (el) {
    el.textContent = `KW ${kw} (${isoDate(mon)} – ${isoDate(fri)})`;
  }
}


/* ============================================
   🧭 WÖCHENTLICHE ÜBERSICHT mit ACCORDION
   ============================================ */
async function renderWeek() {
  const acc = document.getElementById('weekAccordion');
  acc.innerHTML = '<div class="text-muted p-2">Lade Daten…</div>';

  const cfg = JSON.parse(localStorage.getItem('drv_cfg_v1') || '{}');
 const vehicles = await getVehicles();


    const days = weekDays();
  const todayISO = isoDate(new Date());

  const isCurrentWeek =
    mondayOf(new Date()).getTime() === getWeekBaseDate().getTime();

  let html = '';

  for (const [i, d] of days.entries()) {
    const collapseId = `collapse${i}`;
    const isToday = isCurrentWeek && d.date === todayISO;


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
    <i class="bi bi-truck me-1"></i>${labelOf(v)}
    <span class="driver-status small" data-id="${v.id}" data-date="${date}"></span>
  </span>
</h6>
        ${renderTable(rows, { editable: false })}
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
  const vehicles = await getVehicles();


  const v = vehicles[index - 1];
  if (!v) {
    el.innerHTML = '<div class="text-danger">Fahrer nicht gefunden.</div>';
    return;
  }

  // 🔹 aktuell aktiven Fahrer global merken
  window.currentVehId = v.id;

  const days = weekDays();
  let html = `<h5 class="mb-3"><i class="bi bi-person-circle me-1"></i>${labelOf(v)}</h5>`;

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
        <div class="day-body p-3">${renderTable(enrichedRows, { editable: true })}</div>

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
}

async function reloadActiveTab() {
  updateWeekInfo();

  const active = document.querySelector('#drvTabs .nav-link.active');
  const target = active ? getTarget(active) : '#tabOverview';

  if (target === '#tabOverview')      await renderWeek();
  else if (target === '#tabF1')       await renderDriver(1);
  else if (target === '#tabF2')       await renderDriver(2);
  else if (target === '#tabF3')       await renderDriver(3);
}



function attachWeekNav() {
  const prev = document.getElementById('btnPrevWeek');
  const next = document.getElementById('btnNextWeek');

  if (prev) {
    prev.onclick = async () => {
      weekOffset--;
      await reloadActiveTab();
    };
  }

  if (next) {
    next.onclick = async () => {
      weekOffset++;
      await reloadActiveTab();
    };
  }
}

let __AUTO_REFRESH_TIMER__ = null;

function stopAutoRefresh() {
  if (__AUTO_REFRESH_TIMER__) {
    clearInterval(__AUTO_REFRESH_TIMER__);
    __AUTO_REFRESH_TIMER__ = null;
  }
}



// helper fürs Dropdown
const plateOf = (v) => (v?.plate && v.plate.trim()) ? v.plate.trim() : (extractPlate(v?.title) || '');
const labelOf = (v) => plateOf(v) || v?.title || v?.id || 'unbekannt';
/* ============================================
   🔁 AUTOMATISCHES NEULADEN
   ============================================ */
function startAutoRefresh() {
  stopAutoRefresh();
  __AUTO_REFRESH_TIMER__ = setInterval(async () => {
    const active = document.querySelector('#drvTabs .nav-link.active');
    const target = active ? getTarget(active) : '#tabOverview';
    if (target !== '#tabOverview') return;

    await renderWeek();
  }, 60000);
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

  const raw = td.textContent.trim();
  const norm = normalizeTime(raw);
  if (norm !== raw) td.textContent = norm;

  const value = norm;
  const { field, veh, date, tour } = td.dataset;
  if (!field || !veh || !date || !tour) return;
  try {
    // === Update speichern ===
    const res = await fetch("/api/update_time.php", {
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


function normalizeTime(v) {
  if (!v) return v;
  v = v.replace(/\s+/g,'').replace(/\./g,':');
  if (/^\d{1,2}:\d{2}$/.test(v)) return v;
  if (/^\d{1,2}$/.test(v)) return `${v.padStart(2,'0')}:00`;   // NEU: "7" -> "07:00"
  if (/^\d{3,4}$/.test(v)) {
    const h = v.length === 3 ? v.slice(0,1) : v.slice(0,2);
    const m = v.slice(-2);
    return `${h.padStart(2,'0')}:${m}`;
  }
  return v;
}



document.addEventListener('DOMContentLoaded', () => {
  const infoBtn = document.getElementById('tabInfoBtn');
  const infoBox = document.getElementById('tabInfoBox');
  const closeBtn = document.getElementById('closeTabInfo');

  if (infoBtn && infoBox && closeBtn) {
    infoBtn.addEventListener('click', () => infoBox.classList.add('show'));
    closeBtn.addEventListener('click', () => infoBox.classList.remove('show'));
    infoBox.addEventListener('click', (e) => {
      if (e.target === infoBox) infoBox.classList.remove('show'); // Klick außerhalb schließt
    });
  }
});
function normPlate(s){
  return (s||'').toUpperCase().replace(/\s+/g,'').replace(/[^A-Z0-9ÄÖÜ]/g,'');
}
function extractPlate(s){
  const m = (s||'').toUpperCase().match(/[A-ZÄÖÜ]{1,3}\s*-\s*[A-Z]{1,2}\s*\d{1,4}/);
  return m ? m[0] : (s||'');
}
function normalizeTimeImport(v){
  if(!v) return '';
  v = String(v).trim().replace(/\s+/g,'').replace(/\./g,':');
  if (/^\d{1,2}:\d{2}:\d{2}$/.test(v)) v = v.slice(0,5);
  if (/^\d{1,2}:\d{2}$/.test(v)) {
    const [h,m] = v.split(':');
    return `${String(h).padStart(2,'0')}:${m}`;
  }
  if (/^\d{1,2}$/.test(v)) return `${String(v).padStart(2,'0')}:00`;
  if (/^\d{3,4}$/.test(v)) {
    const h = v.length===3 ? v.slice(0,1) : v.slice(0,2);
    const m = v.slice(-2);
    return `${String(h).padStart(2,'0')}:${m}`;
  }
  return v;
}

function normalizeDateDE(v){
  // erwartet dd.mm.yyyy oder yyyy-mm-dd
  if(!v) return '';
  v = String(v).trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
  const m = v.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if (!m) return '';
  return `${m[3]}-${String(m[2]).padStart(2,'0')}-${String(m[1]).padStart(2,'0')}`;
}

function parsePastedTable(text){
  const lines = text.trim().split(/\r?\n/).filter(Boolean);
  if(lines.length < 2) return {rows:[], error:'Zu wenig Zeilen.'};

  // delimiter: tab bevorzugt, sonst ; oder ,
  const delim = lines[0].includes('\t') ? '\t' : (lines[0].includes(';') ? ';' : ',');
  const header = lines[0].split(delim).map(s=>s.trim());

  const col = (name) => {
    const i = header.findIndex(h => h.toLowerCase() === name.toLowerCase());
    return i;
  };

  const iDatum = col('Datum');
  const iKenn  = col('Kennzeichen');
  const iAnk   = col('Ankunft');
  const iEnde  = col('Ende Beladung');

  if([iDatum,iKenn,iAnk,iEnde].some(i=>i<0)){
    return {rows:[], error:`Header fehlt. Gefunden: ${header.join(' | ')}`};
  }

  const data = [];
  for(let r=1;r<lines.length;r++){
    const cells = lines[r].split(delim);
    const date = normalizeDateDE(cells[iDatum]);
    const plateRaw = (cells[iKenn]||'').trim();
    const arriveWU = normalizeTimeImport(cells[iAnk]);
const departWU = normalizeTimeImport(cells[iEnde]);

    if(!date || !plateRaw) continue;
    data.push({ date, plateRaw, arriveWU, departWU });
  }
  return {rows:data, error:null};
}

function buildVehicleMapFromCfg(){
  const cfg = JSON.parse(localStorage.getItem('drv_cfg_v1') || '{}');
  const vehicles = cfg.vehicles || [];
  const map = new Map();

  vehicles.forEach(v => {
    const plate = (v.plate && v.plate.trim()) ? v.plate.trim() : extractPlate(v.title);
    if (plate) map.set(normPlate(plate), { id: v.id, title: v.title, plate });
  });

  return { vehicles, map };
}


function assignTours(items){
  // tour pro (veh_id,date) hochzählen: 1,2,3...
  const counter = new Map();
  return items.map(it=>{
    const key = `${it.veh_id}__${it.date}`;
    const n = (counter.get(key) || 0) + 1;
    counter.set(key, n);
    return {...it, tour: n};

  });
}

function initPasteImportUI(){
  const box = document.getElementById('pasteBox');
  const btnPrev = document.getElementById('btnPreviewPaste');
  const btnCommit = document.getElementById('btnCommitPaste');
  const preview = document.getElementById('pastePreview');
  if(!box || !btnPrev || !btnCommit || !preview) return;

  let staged = [];        // finale items mit veh_id
  let unknownRows = [];   // rows ohne match

  btnPrev.addEventListener('click', async ()=>{
    preview.innerHTML = '';
    btnCommit.disabled = true;
    staged = [];
    unknownRows = [];

    const {rows, error} = parsePastedTable(box.value);
    if(error) {
      preview.innerHTML = `<div class="text-danger">${error}</div>`;
      return;
    }
    if(!rows.length){
      preview.innerHTML = `<div class="text-muted">Keine Daten gefunden.</div>`;
      return;
    }

    const vehicles = await getVehicles();

// Map für Kennzeichen -> Fahrzeug
const map = new Map();
vehicles.forEach(v => {
  const p = plateOf(v); // nutzt plate oder title fallback
  if (p) map.set(normPlate(p), { id: v.id, title: v.title, plate: p });
});

// ✅ Options einmal bauen
const optionsHtml = vehicles
  .map(v => `<option value="${v.id}">${labelOf(v)}</option>`)
  .join('');


    const mapped = rows.map((r, idx)=>{
      const key = normPlate(r.plateRaw);
      const hit = map.get(key);
      return {
        idx,
        date: r.date,
        plateRaw: r.plateRaw,
        arriveWU: r.arriveWU,
        departWU: r.departWU,
        veh_id: hit?.id || '',
        veh_title: hit?.title || ''
      };
    });

    unknownRows = mapped.filter(x=>!x.veh_id);

    // Vorschau + Dropdown für unbekannte Kennzeichen
    let html = `
      <div class="mb-2"><b>Gefunden:</b> ${mapped.length} Zeilen</div>
      ${unknownRows.length ? `<div class="alert alert-warning py-2">
        Unbekannte Kennzeichen: <b>${unknownRows.length}</b> — bitte zuordnen.
      </div>` : `<div class="alert alert-success py-2">Alle Kennzeichen erkannt ✅</div>`}
      <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th>Datum</th><th>Kennzeichen (Excel)</th><th>Zuordnung</th><th>WU an</th><th>WU ab</th>
          </tr>
        </thead>
        <tbody>
    `;

    html += mapped.map(m=>{
     const select = !m.veh_id ? `
  <select class="form-select form-select-sm plate-map" data-idx="${m.idx}">
    <option value="">— auswählen —</option>
    ${optionsHtml}
  </select>
` : `<span class="badge bg-success">${m.veh_title}</span>`;

      return `
        <tr>
          <td>${m.date}</td>
          <td>${m.plateRaw}</td>
          <td>${select}</td>
          <td>${m.arriveWU || ''}</td>
          <td>${m.departWU || ''}</td>
        </tr>
      `;
    }).join('');

    html += `</tbody></table></div>`;
    preview.innerHTML = html;

    // staged initial
    staged = mapped;

    // wenn keine unknowns -> freigeben
    if(!unknownRows.length) btnCommit.disabled = false;

    // dropdown handler
    preview.querySelectorAll('.plate-map').forEach(sel=>{
      sel.addEventListener('change', ()=>{
        const idx = Number(sel.dataset.idx);
        const val = sel.value;
        const row = staged.find(x=>x.idx===idx);
        if(row){
          row.veh_id = val;
          row.veh_title = vehicles.find(v=>v.id===val)?.title || '';
        }
        // check ob alle gefüllt
        const allOk = staged.every(x=>x.veh_id);
        btnCommit.disabled = !allOk;
      });
    });
  });

  btnCommit.addEventListener('click', async ()=>{
    // finale payload: nur Felder fürs DB
    const items = staged.map(x=>({
      veh_id: x.veh_id,
      date: x.date,
      arriveWU: x.arriveWU,
      departWU: x.departWU
    }));

    const withTours = assignTours(items);

    const res = await fetch('/api/import_wu_paste.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({items: withTours}),
      credentials:'include'
    });
    const j = await res.json().catch(()=>({}));
    if(!j.ok){
      alert('Import fehlgeschlagen: ' + (j.msg || j.error || 'unknown'));
      return;
    }
    alert(`Import OK: ${j.imported} Zeilen`);
    await reloadActiveTab();
    btnCommit.disabled = true;
  });
}

(function () {
  const infoBtn  = document.getElementById('tabInfoBtn');
  const infoBox  = document.getElementById('tabInfoBox');
  const closeBtn = document.getElementById('closeTabInfo');

  console.log('TabInfo wiring:', !!infoBtn, !!infoBox, !!closeBtn);

  if (!infoBtn || !infoBox || !closeBtn) return;

  infoBtn.addEventListener('click', (e) => {
    e.preventDefault();
    infoBox.classList.add('show');
  });

  closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    infoBox.classList.remove('show');
  });

  infoBox.addEventListener('click', (e) => {
    if (e.target === infoBox) infoBox.classList.remove('show');
  });
})();

