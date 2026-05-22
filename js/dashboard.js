/* dashboard.js */
let __VEH_CACHE__ = null;
let __VEH_CACHE_PROMISE__ = null;

let WE_LG_FILTER = 'ALL';
let WA_LG_FILTER = 'ALL';

let DASHBOARD_BASE_CACHE = {
  weRows: null,
  waRows: null,
  loadedAt: 0
};

let DASHBOARD_BASE_CACHE_PROMISE = null;
let DASHBOARD_REFRESH_PROMISE = null;
let DASHBOARD_REFRESH_SEQ = 0;
let DASHBOARD_AUTO_RELOAD_TIMER = null;

const DASHBOARD_BASE_CACHE_TTL_MS = 60 * 1000; // 1 Minute

const CONFIG = {
  API_BASE: '/api',
  ENDPOINTS: {
    WE: 'wareneingang_api.php',
    WA: 'warenausgang_api.php',
    SD: 'stammdaten_api.php',
    DRV: {
      GET_DAY: 'get_day.php'
    }
  }
};

/* =========================================================
   BASIS-HELPER
========================================================= */
function pad2(n) {
  return String(n).padStart(2, '0');
}

function isoDate(d) {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

function parseYMD(s) {
  if (!s) return null;
  const [y, m, d] = String(s).split('-').map(Number);
  if (!y || !m || !d) return null;
  const dt = new Date(y, m - 1, d);
  return Number.isNaN(dt.getTime()) ? null : dt;
}

function toDateOnly(d) {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  return x;
}

function startOfDay(d = new Date()) {
  const copy = new Date(d);
  copy.setHours(0, 0, 0, 0);
  return copy;
}

function within(d, from, to) {
  if (!d || !from || !to) return false;
  const x = toDateOnly(d);
  return x >= toDateOnly(from) && x <= toDateOnly(to);
}

function addDaysLocal(d, days) {
  const x = new Date(d);
  x.setDate(x.getDate() + days);
  return x;
}

function addYearsLocal(d, years) {
  const x = new Date(d);
  x.setFullYear(x.getFullYear() + years);
  return x;
}

function hhmmDiff(start, end) {
  if (!start || !end) return '';
  const [h1, m1] = String(start).split(':').map(Number);
  const [h2, m2] = String(end).split(':').map(Number);

  if (!Number.isFinite(h1) || !Number.isFinite(m1) || !Number.isFinite(h2) || !Number.isFinite(m2)) {
    return '';
  }

  const diff = (h2 * 60 + m2) - (h1 * 60 + m1);
  if (diff < 0) return '';

  const h = Math.floor(diff / 60);
  const m = diff % 60;
  return `${h}h ${pad2(m)}m`;
}

function formatSmartNumber(value) {
  return Number(value || 0).toLocaleString('de-DE');
}

function formatSmartWeight(value) {
  const n = Number(value || 0);
  if (Math.abs(n) >= 1000) {
    return `${(n / 1000).toLocaleString('de-DE', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1
    })} t`;
  }
  return `${n.toLocaleString('de-DE')} kg`;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[ch]));
}

function uniqueSorted(arr) {
  return [...new Set(arr)]
    .filter(Boolean)
    .sort((a, b) => String(a).localeCompare(String(b), 'de', {
      numeric: true,
      sensitivity: 'base'
    }));
}

function lgFromRow(row) {
  const lg = String(row?.lagergruppe || row?.lg || '').trim();
  return lg || 'UNBEKANNT';
}

function getLastMatching(arr, predicate) {
  if (!Array.isArray(arr)) return null;
  for (let i = arr.length - 1; i >= 0; i--) {
    if (predicate(arr[i], i)) return arr[i];
  }
  return null;
}

function rebuildLgSelect(selectEl, values, selectedValue = 'ALL') {
  if (!selectEl) return;

  const options = ['<option value="ALL">Alle</option>']
    .concat(values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`));

  selectEl.innerHTML = options.join('');

  const exists = [...selectEl.options].some(o => o.value === selectedValue);
  selectEl.value = exists ? selectedValue : 'ALL';
}

function makeDeltaBadge(current, previous, formatter = formatSmartNumber) {
  const delta = Number(current || 0) - Number(previous || 0);

  let cls = 'bg-secondary-subtle text-secondary-emphasis';
  let txt = `→ ${formatter(0)}`;

  if (delta > 0) {
    cls = 'bg-success-subtle text-success-emphasis';
    txt = `↗ ${formatter(delta)}`;
  } else if (delta < 0) {
    cls = 'bg-danger-subtle text-danger-emphasis';
    txt = `↘ ${formatter(Math.abs(delta))}`;
  }

  return `<span class="badge rounded-pill ${cls} dash-delta-badge">${txt}</span>`;
}

function makeCurrentCell(current, previous, formatter = formatSmartNumber) {
  return `
    <td class="text-end dash-current-cell">
      <div class="fw-semibold">${formatter(current)}</div>
      <div class="mt-1">${makeDeltaBadge(current, previous, formatter)}</div>
    </td>
  `;
}

function makePreviousCell(previous, formatter = formatSmartNumber) {
  return `
    <td class="text-end dash-prev-cell">
      <div>${formatter(previous)}</div>
    </td>
  `;
}

function updateLastRefreshStamp() {
  const el = document.getElementById('dashboardLastUpdate');
  if (!el) return;

  const now = new Date();
  el.textContent = `Zuletzt aktualisiert: ${now.toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit'
  })} Uhr`;
}

function setReloadButtonBusy(isBusy) {
  const btn = document.getElementById('btnReload');
  if (!btn) return;

  btn.disabled = !!isBusy;
  btn.classList.toggle('disabled', !!isBusy);
}

/* =========================================================
   FETCH-HELPER
========================================================= */
async function fetchJSON(url, opts = {}) {
  const res = await fetch(url, {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { 'Accept': 'application/json' },
    ...opts
  });

  const text = await res.text();

  if (!res.ok) {
    let msg = text;
    try {
      const j = JSON.parse(text);
      msg = j.error || text;
    } catch (_) {}
    throw new Error(`${res.status} ${res.statusText} – ${msg}`);
  }

  try {
    return JSON.parse(text);
  } catch (_) {
    throw new Error(`Invalid JSON: ${text.slice(0, 300)}`);
  }
}

async function fetchList(resource) {
  const url = `${CONFIG.API_BASE}/${resource}?action=list`;
  const data = await fetchJSON(url);
  if (!data.ok || !Array.isArray(data.items)) return [];
  return data.items;
}

/* =========================================================
   VEHICLES / FAHRER
========================================================= */
async function getVehiclesFromCfg() {
  if (__VEH_CACHE__) return __VEH_CACHE__;
  if (__VEH_CACHE_PROMISE__) return __VEH_CACHE_PROMISE__;

  __VEH_CACHE_PROMISE__ = (async () => {
    try {
      const j = await fetchJSON('/api/veh_cfg_get.php');

      if (j.ok && j.cfg && Array.isArray(j.cfg.vehicles) && j.cfg.vehicles.length) {
        __VEH_CACHE__ = j.cfg.vehicles.map(v => ({
          id: v.id,
          title: v.title || v.plate || v.id,
          plate: v.plate || ''
        }));
        return __VEH_CACHE__;
      }
    } catch (e) {
      console.warn('veh_cfg_get.php failed:', e.message);
    }

    __VEH_CACHE__ = [
      { id: 'veh1', title: 'BOH - DT 324', plate: '' },
      { id: 'veh2', title: 'BOH - DT 988', plate: '' },
      { id: 'veh3', title: 'BOH - DT 964', plate: '' }
    ];

    return __VEH_CACHE__;
  })();

  try {
    return await __VEH_CACHE_PROMISE__;
  } finally {
    __VEH_CACHE_PROMISE__ = null;
  }
}

async function apiGetDay(vehId, dateISO) {
  const url = `${CONFIG.API_BASE}/${CONFIG.ENDPOINTS.DRV.GET_DAY}?veh_id=${encodeURIComponent(vehId)}&date=${encodeURIComponent(dateISO)}`;

  try {
    const j = await fetchJSON(url);
    if (j.ok && Array.isArray(j.rows)) return j.rows;
    if (Array.isArray(j)) return j;
    return [];
  } catch (err) {
    console.error('apiGetDay failed:', vehId, dateISO, err);
    return [];
  }
}

function getGlobalStatusBadgeFromRows(rows) {
  if (!Array.isArray(rows) || !rows.length) {
    return `<span class="badge bg-secondary">Keine Daten</span>`;
  }

  const hasStart = rows.some(r => r.workStart);
  const hasEnd = rows.some(r => r.workEnd);

  const r = getLastMatching(rows, row =>
    row.arriveWU || row.departWU || row.arriveH || row.departH ||
    row.arriveH2 || row.departH2 || row.pauseStart || row.workStart
  ) || rows[0];

  let status = 'Offen';
  let cls = 'bg-secondary';

  if (hasEnd) {
    const endRow = getLastMatching(rows, row => row.workEnd);
    const endTime = endRow?.workEnd || '';
    status = endTime ? `Feierabend (${endTime})` : 'Feierabend';
    cls = 'status-feier';
  } else if (r.pauseStart && !r.pauseEnd) {
    status = 'Pause';
    cls = 'status-pause';
  } else if (r.departH2) {
    status = 'Auf dem Weg nach Wunstorf';
    cls = 'status-fahrt';
  } else if (r.arriveH2 && !r.departH2) {
    status = `In Halle ${r.hannoverHall2 || 'Hannover 2'}`;
    cls = 'status-hannover';
  } else if (r.departH) {
    if (r.hannoverHall2 && !r.arriveH2) {
      status = `Auf dem Weg nach Halle ${r.hannoverHall2}`;
      cls = 'status-fahrt';
    } else {
      status = 'Auf dem Weg nach Wunstorf';
      cls = 'status-fahrt';
    }
  } else if (r.arriveH && !r.departH) {
    status = `In Halle ${r.hannoverHall || 'Hannover'}`;
    cls = 'status-hannover';
  } else if (r.departWU && !r.arriveH) {
    status = 'Auf dem Weg nach Hannover';
    cls = 'status-fahrt';
  } else if (r.arriveWU && !r.departWU) {
    status = 'In Halle Wunstorf';
    cls = 'status-wunstorf';
  } else if (hasStart && !hasEnd) {
    status = 'Arbeit begonnen';
    cls = 'status-fahrt';
  } else {
    status = 'Noch nicht gestartet';
    cls = 'status-feier';
  }

  return `<span class="badge ${cls}">${status}</span>`;
}

function dotClassFromBadgeHtml(batchHTML) {
  if (batchHTML.includes('status-feier')) return 'dot-red';
  if (batchHTML.includes('status-fahrt')) return 'dot-blue';
  if (batchHTML.includes('status-hannover') || batchHTML.includes('status-wunstorf')) return 'dot-green';
  if (batchHTML.includes('status-pause')) return 'dot-yellow';
  return 'dot-gray';
}

async function fillDriverSummary() {
  const el = document.getElementById('driverSummary');
  if (!el) return;

  el.innerHTML = '<div class="text-muted">Lade Fahrer...</div>';

  const vehicles = await getVehiclesFromCfg();
  const today = isoDate(new Date());

  const results = await Promise.all(vehicles.map(async (v) => {
    try {
      const rows = await apiGetDay(v.id, today);

      if (!rows.length) {
        return {
          name: v.title,
          cls: 'dot-gray',
          tours: 0,
          duration: '–',
          batchHTML: '<span class="badge bg-secondary">Keine Daten</span>'
        };
      }

      const tours = rows.filter(r =>
        r.arriveWU || r.departWU || r.arriveH || r.departH
      ).length;

      const startTime = rows.find(r => r.workStart)?.workStart;
      const endTime = getLastMatching(rows, r => r.workEnd)?.workEnd;

      let duration = '–';
      if (startTime) {
        const nowTime = endTime || `${pad2(new Date().getHours())}:${pad2(new Date().getMinutes())}`;
        duration = hhmmDiff(startTime, nowTime) || '–';
      }

      const batchHTML = getGlobalStatusBadgeFromRows(rows);
      const cls = dotClassFromBadgeHtml(batchHTML);

      return {
        name: v.title,
        cls,
        tours,
        duration,
        batchHTML
      };
    } catch (e) {
      console.warn('Fehler bei', v.id, e);
      return {
        name: v.title,
        cls: 'dot-red',
        tours: 0,
        duration: '–',
        batchHTML: `<span class="badge bg-danger">${escapeHtml(e.message || 'Fehler')}</span>`
      };
    }
  }));

  el.innerHTML = results.map(d => `
    <div class="d-flex align-items-center mb-2">
      <span class="badge-dot ${d.cls} me-2"></span>
      <div class="flex-grow-1">
        <strong>${escapeHtml(d.name)}</strong><br>
        ${d.batchHTML}
      </div>
      <div class="text-end">
        <span class="fw-semibold">${d.tours} Tour${d.tours !== 1 ? 'en' : ''}</span><br>
        <small>${escapeHtml(d.duration)}</small>
      </div>
    </div>
  `).join('');
}

/* =========================================================
   CACHE FÜR WE / WA
========================================================= */
function invalidateDashboardBaseCache() {
  DASHBOARD_BASE_CACHE = {
    weRows: null,
    waRows: null,
    loadedAt: 0
  };
  DASHBOARD_BASE_CACHE_PROMISE = null;
}

async function loadDashboardBaseData(force = false) {
  const now = Date.now();

  const cacheValid =
    !force &&
    Array.isArray(DASHBOARD_BASE_CACHE.weRows) &&
    Array.isArray(DASHBOARD_BASE_CACHE.waRows) &&
    (now - DASHBOARD_BASE_CACHE.loadedAt) < DASHBOARD_BASE_CACHE_TTL_MS;

  if (cacheValid) {
    return DASHBOARD_BASE_CACHE;
  }

  if (DASHBOARD_BASE_CACHE_PROMISE) {
    return DASHBOARD_BASE_CACHE_PROMISE;
  }

  DASHBOARD_BASE_CACHE_PROMISE = (async () => {
    const [weRows, waRows] = await Promise.all([
      fetchList(CONFIG.ENDPOINTS.WE),
      fetchList(CONFIG.ENDPOINTS.WA)
    ]);

    DASHBOARD_BASE_CACHE = {
      weRows,
      waRows,
      loadedAt: Date.now()
    };

    return DASHBOARD_BASE_CACHE;
  })();

  try {
    return await DASHBOARD_BASE_CACHE_PROMISE;
  } finally {
    DASHBOARD_BASE_CACHE_PROMISE = null;
  }
}

/* =========================================================
   STAMMDATEN
========================================================= */
async function getStammdatenTrend() {
  try {
    const data = await fetchJSON(`${CONFIG.API_BASE}/stammdaten_stats.php`);
    if (!data.ok || !data.stats) throw new Error('invalid JSON or missing data');
    return data.stats;
  } catch (err) {
    console.error('❌ getStammdatenTrend failed:', err);
    return {};
  }
}

async function fillStammdatenTrend() {
  const t = await getStammdatenTrend();

  const tbody = document.getElementById('sdTrendBody');
  const wrap = document.querySelector('#sdTrendWrap');
  const mobileBox = document.getElementById('sdMobileSummary');

  const safeNum = (v) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  };

  const safeText = (v, fallback = '±0') => {
    const s = String(v ?? '').trim();
    return s !== '' ? s : fallback;
  };

  // =========================
  // Mobile Summary
  // =========================
  if (mobileBox) {
    if (t?.totals_all) {
      mobileBox.innerHTML = `
        <div class="sd-mobile-box">
          <div class="sd-mobile-title">Aktuelle Gesamtstände</div>

          <div class="sd-mobile-item">
            <span class="sd-mobile-label">Speditionen</span>
            <span class="sd-mobile-value">${formatSmartNumber(safeNum(t.totals_all.speditionen))}</span>
          </div>

          <div class="sd-mobile-item">
            <span class="sd-mobile-label">Behälter</span>
            <span class="sd-mobile-value">${formatSmartNumber(safeNum(t.totals_all.behaelter))}</span>
          </div>

          <div class="sd-mobile-item">
            <span class="sd-mobile-label">Sachnummern</span>
            <span class="sd-mobile-value">${formatSmartNumber(safeNum(t.totals_all.sachnummern))}</span>
          </div>
        </div>
      `;
    } else {
      mobileBox.innerHTML = `<div class="text-muted small">Keine Daten vorhanden</div>`;
    }
  }

  // =========================
  // Desktop / Tablet Tabelle
  // =========================
  if (!tbody || !wrap) return;

  wrap.querySelectorAll('.sd-extra-info, .sd-fade-wrap').forEach(el => el.remove());

  const table = tbody.closest('table');
  table?.classList.add('fade-target');
  table?.classList.remove('visible');

  await new Promise(resolve => setTimeout(resolve, 150));

  if (!t || !t.speditionen) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-muted">Keine Daten vorhanden</td></tr>`;
  } else {
    const makeRow = (label, obj = {}) => {
      return `
        <tr>
          <td>${escapeHtml(label)}</td>
          <td class="text-end fw-semibold">${formatSmartNumber(safeNum(obj.today))}</td>
          <td class="text-end text-muted d-none d-sm-table-cell">${escapeHtml(safeText(obj?.diffs?.day))}</td>
          <td class="text-end text-muted d-none d-md-table-cell">${escapeHtml(safeText(obj?.diffs?.week))}</td>
          <td class="text-end text-muted d-none d-lg-table-cell">${escapeHtml(safeText(obj?.diffs?.year))}</td>
        </tr>
      `;
    };

    tbody.innerHTML = `
      ${makeRow('Speditionen', t.speditionen)}
      ${makeRow('Behälter', t.behaelter)}
      ${makeRow('Sachnummern', t.sachnummern)}
      <tr class="border-top">
        <td><strong>Gesamt</strong></td>
        <td class="text-end fw-bold">${formatSmartNumber(safeNum(t.total?.today))}</td>
        <td class="text-end text-muted d-none d-sm-table-cell">${escapeHtml(safeText(t.total?.diffs?.day))}</td>
        <td class="text-end text-muted d-none d-md-table-cell">${escapeHtml(safeText(t.total?.diffs?.week))}</td>
        <td class="text-end text-muted d-none d-lg-table-cell">${escapeHtml(safeText(t.total?.diffs?.year))}</td>
      </tr>
    `;
  }

  const fadeWrap = document.createElement('div');
  fadeWrap.className = 'sd-fade-wrap fade-target';
  fadeWrap.innerHTML = `
    <div class="text-muted small mt-2 sd-extra-info">
      (Vergleich: gestern / Vorwoche / Vorjahr)
    </div>
    ${
      t?.totals_all
        ? `<div class="mt-2 small sd-extra-info">
             <strong>Aktueller Gesamtbestand:</strong><br>
             Speditionen: ${formatSmartNumber(safeNum(t.totals_all.speditionen))} •
             Behälter: ${formatSmartNumber(safeNum(t.totals_all.behaelter))} •
             Sachnummern: ${formatSmartNumber(safeNum(t.totals_all.sachnummern))}
           </div>`
        : ''
    }
  `;
  wrap.appendChild(fadeWrap);

  requestAnimationFrame(() => {
    table?.classList.add('visible');
    fadeWrap.classList.add('visible');
  });
}

/* =========================================================
   WE-TREND
========================================================= */
function aggregateWERange(list, from, to) {
  const eingaengeSet = new Set();
  let pallets = 0;
  let klts = 0;
  let units = 0;

  (list || []).forEach(r => {
    const d = parseYMD(r?.datum);
    if (!within(d, from, to)) return;

    const nr = String(r?.eingang_nr || '').trim();
    if (nr) eingaengeSet.add(nr);

    pallets += Number(r?.behaelter || 0);
    klts += Number(r?.zus_behaelter || 0);
    units += Number(r?.menge || 0);
  });

  return {
    eingaenge: eingaengeSet.size,
    pallets,
    klts,
    units
  };
}

async function fillWareneingangTrend() {
  try {
    const el = document.getElementById('weTrend');
    if (!el) return;

    el.innerHTML = '';

    const { weRows } = await loadDashboardBaseData(false);
    const rows = Array.isArray(weRows) ? weRows : [];

    const weFilterEl = document.getElementById('weLgFilter');
    const currentSelect = WE_LG_FILTER !== 'ALL' ? WE_LG_FILTER : (weFilterEl?.value || 'ALL');

    const weLgs = uniqueSorted(rows.map(lgFromRow));
    rebuildLgSelect(weFilterEl, weLgs, currentSelect);

    WE_LG_FILTER = weFilterEl?.value || 'ALL';

    const rowsFiltered = WE_LG_FILTER === 'ALL'
      ? rows
      : rows.filter(r => lgFromRow(r) === WE_LG_FILTER);

    const today = new Date();
    const todayStart = startOfDay(today);
    const sevenDaysStart = addDaysLocal(todayStart, -6);

    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    monthStart.setHours(0, 0, 0, 0);

    const twelveMonthsStart = new Date(today.getFullYear(), today.getMonth() - 11, 1);
    twelveMonthsStart.setHours(0, 0, 0, 0);

    const currentToday  = aggregateWERange(rowsFiltered, todayStart, todayStart);
    const previousToday = aggregateWERange(rowsFiltered, addYearsLocal(todayStart, -1), addYearsLocal(todayStart, -1));

    const current7      = aggregateWERange(rowsFiltered, sevenDaysStart, todayStart);
    const previous7     = aggregateWERange(rowsFiltered, addYearsLocal(sevenDaysStart, -1), addYearsLocal(todayStart, -1));

    const currentMonth  = aggregateWERange(rowsFiltered, monthStart, todayStart);
    const previousMonth = aggregateWERange(rowsFiltered, addYearsLocal(monthStart, -1), addYearsLocal(todayStart, -1));

    const current12     = aggregateWERange(rowsFiltered, twelveMonthsStart, todayStart);
    const previous12    = aggregateWERange(rowsFiltered, addYearsLocal(twelveMonthsStart, -1), addYearsLocal(todayStart, -1));

    const totalAll = aggregateWERange(rowsFiltered, new Date(2000, 0, 1), new Date(2099, 11, 31));

    el.innerHTML = `
      <div class="we-trend-table table-responsive mt-3 small fade-target">
        <table class="table table-sm table-borderless align-middle mb-0 text-nowrap dash-trend-wide">
          <thead class="text-muted small">
            <tr>
              <th rowspan="2"></th>
              <th colspan="2" class="dash-group-head">Heute</th>
              <th colspan="2" class="dash-group-head">Letzte 7 Tage</th>
              <th colspan="2" class="dash-group-head">Aktueller Monat</th>
              <th colspan="2" class="dash-group-head">Letzte 12 Monate</th>
            </tr>
            <tr>
              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Eingänge</td>
              ${makeCurrentCell(currentToday.eingaenge, previousToday.eingaenge)}
              ${makePreviousCell(previousToday.eingaenge)}
              ${makeCurrentCell(current7.eingaenge, previous7.eingaenge)}
              ${makePreviousCell(previous7.eingaenge)}
              ${makeCurrentCell(currentMonth.eingaenge, previousMonth.eingaenge)}
              ${makePreviousCell(previousMonth.eingaenge)}
              ${makeCurrentCell(current12.eingaenge, previous12.eingaenge)}
              ${makePreviousCell(previous12.eingaenge)}
            </tr>
            <tr>
              <td>Paletten</td>
              ${makeCurrentCell(currentToday.pallets, previousToday.pallets)}
              ${makePreviousCell(previousToday.pallets)}
              ${makeCurrentCell(current7.pallets, previous7.pallets)}
              ${makePreviousCell(previous7.pallets)}
              ${makeCurrentCell(currentMonth.pallets, previousMonth.pallets)}
              ${makePreviousCell(previousMonth.pallets)}
              ${makeCurrentCell(current12.pallets, previous12.pallets)}
              ${makePreviousCell(previous12.pallets)}
            </tr>
            <tr>
              <td>KLTs</td>
              ${makeCurrentCell(currentToday.klts, previousToday.klts)}
              ${makePreviousCell(previousToday.klts)}
              ${makeCurrentCell(current7.klts, previous7.klts)}
              ${makePreviousCell(previous7.klts)}
              ${makeCurrentCell(currentMonth.klts, previousMonth.klts)}
              ${makePreviousCell(previousMonth.klts)}
              ${makeCurrentCell(current12.klts, previous12.klts)}
              ${makePreviousCell(previous12.klts)}
            </tr>
            <tr>
              <td>Stückzahl</td>
              ${makeCurrentCell(currentToday.units, previousToday.units)}
              ${makePreviousCell(previousToday.units)}
              ${makeCurrentCell(current7.units, previous7.units)}
              ${makePreviousCell(previous7.units)}
              ${makeCurrentCell(currentMonth.units, previousMonth.units)}
              ${makePreviousCell(previousMonth.units)}
              ${makeCurrentCell(current12.units, previous12.units)}
              ${makePreviousCell(previous12.units)}
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-3 small text-muted we-total-info fade-target">
        <strong>Wareneingang gesamt:</strong><br>
        Eingänge: ${formatSmartNumber(totalAll.eingaenge)} •
        Paletten: ${formatSmartNumber(totalAll.pallets)} •
        KLTs: ${formatSmartNumber(totalAll.klts)} •
        Stückzahl: ${formatSmartNumber(totalAll.units)}
      </div>
    `;

    requestAnimationFrame(() => {
      el.querySelector('.we-trend-table')?.classList.add('visible');
      el.querySelector('.we-total-info')?.classList.add('visible');
    });

  } catch (err) {
    console.error('fillWareneingangTrend failed:', err);
  }
}

/* =========================================================
   WA-TREND
========================================================= */
function aggregateWARange(list, from, to) {
  const ausgaengeSet = new Set();
  let pallets = 0;
  let klts = 0;
  let kilo = 0;

  (list || []).forEach(r => {
    const d = parseYMD(r?.datum);
    if (!within(d, from, to)) return;

    const nr = String(r?.ausgang_nr || '').trim();
    if (nr) ausgaengeSet.add(nr);

    pallets += Number(r?.behaelter || 0);
    klts += Number(r?.zus_behaelter || 0);
    kilo += Number(r?.brt_gew || 0);
  });

  return { ausgaenge: ausgaengeSet.size, pallets, klts, kilo };
}

async function fillWarenausgangTrend() {
  try {
    const el = document.getElementById('waTrend');
    if (!el) return;

    el.innerHTML = '';

    const { waRows } = await loadDashboardBaseData(false);
    const rows = Array.isArray(waRows) ? waRows : [];

    const waFilterEl = document.getElementById('waLgFilter');
    const currentSelect = WA_LG_FILTER !== 'ALL' ? WA_LG_FILTER : (waFilterEl?.value || 'ALL');

    const waLgs = uniqueSorted(rows.map(lgFromRow));
    rebuildLgSelect(waFilterEl, waLgs, currentSelect);

    WA_LG_FILTER = waFilterEl?.value || 'ALL';

    const rowsFiltered = WA_LG_FILTER === 'ALL'
      ? rows
      : rows.filter(r => lgFromRow(r) === WA_LG_FILTER);

    const today = new Date();
    const todayStart = startOfDay(today);
    const sevenDaysStart = addDaysLocal(todayStart, -6);

    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    monthStart.setHours(0, 0, 0, 0);

    const twelveMonthsStart = new Date(today.getFullYear(), today.getMonth() - 11, 1);
    twelveMonthsStart.setHours(0, 0, 0, 0);

    const currentToday  = aggregateWARange(rowsFiltered, todayStart, todayStart);
    const previousToday = aggregateWARange(rowsFiltered, addYearsLocal(todayStart, -1), addYearsLocal(todayStart, -1));

    const current7      = aggregateWARange(rowsFiltered, sevenDaysStart, todayStart);
    const previous7     = aggregateWARange(rowsFiltered, addYearsLocal(sevenDaysStart, -1), addYearsLocal(todayStart, -1));

    const currentMonth  = aggregateWARange(rowsFiltered, monthStart, todayStart);
    const previousMonth = aggregateWARange(rowsFiltered, addYearsLocal(monthStart, -1), addYearsLocal(todayStart, -1));

    const current12     = aggregateWARange(rowsFiltered, twelveMonthsStart, todayStart);
    const previous12    = aggregateWARange(rowsFiltered, addYearsLocal(twelveMonthsStart, -1), addYearsLocal(todayStart, -1));

    const totalAll = aggregateWARange(rowsFiltered, new Date(2000, 0, 1), new Date(2099, 11, 31));

    el.innerHTML = `
      <div class="wa-trend-table table-responsive mt-3 small fade-target">
        <table class="table table-sm table-borderless align-middle mb-0 text-nowrap dash-trend-wide">
          <thead class="text-muted small">
            <tr>
              <th rowspan="2"></th>
              <th colspan="2" class="dash-group-head">Heute</th>
              <th colspan="2" class="dash-group-head">Letzte 7 Tage</th>
              <th colspan="2" class="dash-group-head">Aktueller Monat</th>
              <th colspan="2" class="dash-group-head">Letzte 12 Monate</th>
            </tr>
            <tr>
              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>

              <th class="text-end dash-sub-head">Aktuell</th>
              <th class="text-end dash-sub-head dash-prev-head">Vorjahr</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Ausgänge</td>
              ${makeCurrentCell(currentToday.ausgaenge, previousToday.ausgaenge)}
              ${makePreviousCell(previousToday.ausgaenge)}
              ${makeCurrentCell(current7.ausgaenge, previous7.ausgaenge)}
              ${makePreviousCell(previous7.ausgaenge)}
              ${makeCurrentCell(currentMonth.ausgaenge, previousMonth.ausgaenge)}
              ${makePreviousCell(previousMonth.ausgaenge)}
              ${makeCurrentCell(current12.ausgaenge, previous12.ausgaenge)}
              ${makePreviousCell(previous12.ausgaenge)}
            </tr>
            <tr>
              <td>Paletten</td>
              ${makeCurrentCell(currentToday.pallets, previousToday.pallets)}
              ${makePreviousCell(previousToday.pallets)}
              ${makeCurrentCell(current7.pallets, previous7.pallets)}
              ${makePreviousCell(previous7.pallets)}
              ${makeCurrentCell(currentMonth.pallets, previousMonth.pallets)}
              ${makePreviousCell(previousMonth.pallets)}
              ${makeCurrentCell(current12.pallets, previous12.pallets)}
              ${makePreviousCell(previous12.pallets)}
            </tr>
            <tr>
              <td>KLTs</td>
              ${makeCurrentCell(currentToday.klts, previousToday.klts)}
              ${makePreviousCell(previousToday.klts)}
              ${makeCurrentCell(current7.klts, previous7.klts)}
              ${makePreviousCell(previous7.klts)}
              ${makeCurrentCell(currentMonth.klts, previousMonth.klts)}
              ${makePreviousCell(previousMonth.klts)}
              ${makeCurrentCell(current12.klts, previous12.klts)}
              ${makePreviousCell(previous12.klts)}
            </tr>
            <tr>
              <td>Kilo</td>
              ${makeCurrentCell(currentToday.kilo, previousToday.kilo, formatSmartWeight)}
              ${makePreviousCell(previousToday.kilo, formatSmartWeight)}
              ${makeCurrentCell(current7.kilo, previous7.kilo, formatSmartWeight)}
              ${makePreviousCell(previous7.kilo, formatSmartWeight)}
              ${makeCurrentCell(currentMonth.kilo, previousMonth.kilo, formatSmartWeight)}
              ${makePreviousCell(previousMonth.kilo, formatSmartWeight)}
              ${makeCurrentCell(current12.kilo, previous12.kilo, formatSmartWeight)}
              ${makePreviousCell(previous12.kilo, formatSmartWeight)}
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mt-3 small text-muted wa-total-info fade-target">
        <strong>Warenausgang gesamt:</strong><br>
        Ausgänge: ${formatSmartNumber(totalAll.ausgaenge)} •
        Paletten: ${formatSmartNumber(totalAll.pallets)} •
        KLTs: ${formatSmartNumber(totalAll.klts)} •
        Kilo: ${formatSmartWeight(totalAll.kilo)}
      </div>
    `;

    requestAnimationFrame(() => {
      el.querySelector('.wa-trend-table')?.classList.add('visible');
      el.querySelector('.wa-total-info')?.classList.add('visible');
    });

  } catch (err) {
    console.error('fillWarenausgangTrend failed:', err);
  }
}

/* =========================================================
   LAGERSTATS
========================================================= */
function bindLagerBestandToggle(bestandEl) {
  bestandEl.querySelectorAll('.lager-main-row').forEach(row => {
    if (row.dataset.bound === '1') return;
    row.dataset.bound = '1';

    row.addEventListener('click', () => {
      const detailRow = row.nextElementSibling;
      if (!detailRow || !detailRow.classList.contains('lager-detail-row')) return;

      const isOpen = !detailRow.classList.contains('d-none');

      bestandEl.querySelectorAll('.lager-detail-row').forEach(r => {
        r.classList.add('d-none');
      });

      bestandEl.querySelectorAll('.lager-main-row').forEach(r => {
        r.classList.remove('is-open');
        const icon = r.querySelector('.lager-acc-icon');
        if (icon) {
          icon.classList.remove('bi-chevron-down');
          icon.classList.add('bi-chevron-right');
        }
      });

      if (!isOpen) {
        detailRow.classList.remove('d-none');
        row.classList.add('is-open');

        const icon = row.querySelector('.lager-acc-icon');
        if (icon) {
          icon.classList.remove('bi-chevron-right');
          icon.classList.add('bi-chevron-down');
        }
      }
    });
  });
}

function ensureDashboardLagerStatsStyles() {
  if (document.getElementById('dashboard-lager-styles')) return;

  const style = document.createElement('style');
  style.id = 'dashboard-lager-styles';
  style.textContent = `
    #dashboardLagerBestand .table tbody tr,
    #dashboardLagerTransport .table tbody tr {
      transition: background-color .18s ease;
    }
      .dashboard-col-tip {
  color: #6c757d;
  line-height: 1;
  font-size: .82rem;
  text-decoration: none;
  cursor: pointer;
}

.dashboard-col-tip:hover,
.dashboard-col-tip:focus {
  color: #0d6efd;
}

.dashboard-tooltip .tooltip-inner {
  max-width: 280px;
  text-align: left;
  font-size: .78rem;
  line-height: 1.35;
}

    #dashboardLagerBestand .table tbody tr:hover,
    #dashboardLagerTransport .table tbody tr:hover {
      background: rgba(13, 110, 253, 0.06);
    }

    #dashboardLagerBestand .lager-total-box,
    #dashboardLagerTransport .lager-total-box {
      background: rgba(13, 110, 253, 0.04);
      border: 1px solid rgba(13, 110, 253, 0.10);
      border-radius: .75rem;
      padding: .65rem .8rem;
    }

    #dashboardLagerBestand .lager-filter-box,
    #dashboardLagerTransport .lager-filter-box {
      background: rgba(108, 117, 125, 0.05);
      border: 1px solid rgba(108, 117, 125, 0.10);
      border-radius: .75rem;
      padding: .65rem .8rem;
    }

    .lager-top-badge {
      font-size: .68rem;
      vertical-align: middle;
      margin-left: .35rem;
    }

    .lager-lkw-badge {
      font-size: .78rem;
      font-weight: 600;
    }

    .lager-share-cell {
      min-width: 76px;
    }

    .lager-share-bar {
      height: 6px;
      background: rgba(13, 110, 253, 0.12);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 4px;
    }

    .lager-share-bar > span {
      display: block;
      height: 100%;
      background: linear-gradient(90deg, #0d6efd 0%, #6ea8fe 100%);
      border-radius: 999px;
    }

    .lager-section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      flex-wrap: wrap;
      margin-bottom: .75rem;
    }

    .lager-main-row {
      cursor: pointer;
    }

    .lager-main-row.is-open {
      background: rgba(13, 110, 253, 0.08);
    }

    .lager-detail-row td {
      background: #fff;
    }

    .lager-detail-box {
      background: #f8f9fa;
      border: 1px solid rgba(0,0,0,.06);
      border-radius: .75rem;
      padding: .75rem;
    }

    .lager-acc-icon {
      transition: transform .18s ease;
    }

    .lager-main-row.is-open .lager-acc-icon {
      transform: rotate(90deg);
    }
  `;
  document.head.appendChild(style);
}

function escapeAttr(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function tooltipLabel(label, text) {
  return `
    <span class="d-inline-flex align-items-center justify-content-end gap-1">
      <span>${escapeHtml(label)}</span>
      <button
        type="button"
        class="btn btn-link p-0 border-0 shadow-none dashboard-col-tip"
        data-bs-toggle="tooltip"
        data-bs-trigger="hover focus click"
        data-bs-placement="top"
        data-bs-custom-class="dashboard-tooltip"
        data-bs-html="true"
        title="${escapeAttr(text)}"
        aria-label="Info zu ${escapeAttr(label)}"
      >
        <i class="bi bi-info-circle"></i>
      </button>
    </span>
  `;
}

function initDashboardTooltips(root = document) {
  if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;

  root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    bootstrap.Tooltip.getOrCreateInstance(el, {
      container: 'body',
      boundary: document.body,
      html: true,
      sanitize: false
    });
  });
}
async function fillDashboardLagerStats() {
  const bestandEl = document.getElementById('dashboardLagerBestand');
  const transportEl = document.getElementById('dashboardLagerTransport');

  if (!bestandEl || !transportEl) return;

  try {
    ensureDashboardLagerStatsStyles();

    bestandEl.innerHTML = `<div class="text-muted small">– lädt –</div>`;
    transportEl.innerHTML = `<div class="text-muted small">– lädt –</div>`;

    const j = await fetchJSON('/api/dashboard_lager_stats.php');

    if (!j.ok) {
      throw new Error(j.error || 'API-Fehler');
    }

    const rows = Array.isArray(j.rows) ? j.rows : [];
    const totals = j.totals || { paletten: 0, stueck: 0, sachnr: 0 };
    const filtered = j.filtered || { paletten: 0, stueck: 0, sachnr: 0 };
    const transportRows = Array.isArray(j.transport_rows) ? j.transport_rows : [];
    const transport = j.transport || {
  gt_count: 0,
  gt_lkw: 0,
  gt_rest: 0,
  vw_count: 0,
  vw_lkw: 0,
  vw_rest: 0,
  vw0001_count: 0,
  vw0001_lkw: 0,
  vw0001_rest: 0,
  karton_count: 0,
  karton_lkw: 0,
  karton_rest: 0,
  lkw_relevant: 0,
  offen: 0,
  erledigt_gesamt: 0,
  volle_lkw: 0
};
    const details = (j.details && typeof j.details === 'object') ? j.details : {};

    const fmt = n => Number(n || 0).toLocaleString('de-DE');

    // =========================
    // Lagerbestand
    // =========================
    const sortedRows = [...rows].sort((a, b) => Number(b.paletten || 0) - Number(a.paletten || 0));
    const top3 = sortedRows.slice(0, 3).map(r => String(r.lg || r.lagergruppe || ''));

    const badgeForLg = (lg) => {
      const idx = top3.indexOf(String(lg));
      if (idx === -1) return '';
      if (idx === 0) return `<span class="badge text-bg-warning lager-top-badge">Top 1</span>`;
      if (idx === 1) return `<span class="badge text-bg-secondary lager-top-badge">Top 2</span>`;
      return `<span class="badge text-bg-light border lager-top-badge">Top 3</span>`;
    };

    bestandEl.innerHTML = `
      <div class="lager-section-title">
        <div class="small text-muted">Aktueller Lagerbestand nach Lagergruppe</div>
        <span class="badge text-bg-primary">${fmt(totals.paletten)} Paletten gesamt</span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-3 text-nowrap">
          <thead class="text-muted small">
            <tr>
              <th>LG</th>
              <th class="text-end">Paletten</th>
              <th class="text-end">Stück</th>
              <th class="text-end">Sachnr.</th>
              <th class="text-end">Anteil %</th>
            </tr>
          </thead>
          <tbody>
            ${
              sortedRows.length
                ? sortedRows.map(r => {
                    const lg = r.lg || r.lagergruppe || 'UNBEKANNT';
                    const pal = Number(r.paletten || 0);
                    const stueck = Number(r.stueck || 0);
                    const sachnr = Number(r.sachnr || 0);
                    const share = totals.paletten > 0 ? (pal / totals.paletten) * 100 : 0;
                    const detailItems = Array.isArray(details[lg]?.items) ? details[lg].items : [];

                    return `
                      <tr class="lager-main-row" data-lg="${escapeHtml(lg)}">
                        <td>
                          <span class="lager-row-toggle me-1">
                            <i class="bi bi-chevron-right lager-acc-icon"></i>
                          </span>
                          <span class="fw-semibold">${escapeHtml(lg)}</span>
                          ${badgeForLg(lg)}
                        </td>
                        <td class="text-end fw-semibold">${fmt(pal)}</td>
                        <td class="text-end">${fmt(stueck)}</td>
                        <td class="text-end">${fmt(sachnr)}</td>
                        <td class="text-end lager-share-cell">
                          <div>${share.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %</div>
                          <div class="lager-share-bar">
                            <span style="width:${Math.max(0, Math.min(100, share))}%"></span>
                          </div>
                        </td>
                      </tr>

                      <tr class="lager-detail-row d-none">
                        <td colspan="5">
                          <div class="lager-detail-box">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <div class="fw-semibold">Sachnummern in ${escapeHtml(lg)}</div>
                              <div class="small text-muted">
                                ${fmt(detailItems.length)} Sachnummer${detailItems.length === 1 ? '' : 'n'}
                              </div>
                            </div>

                            <div class="table-responsive">
                              <table class="table table-sm align-middle mb-0 text-nowrap">
                                <thead class="text-muted small">
  <tr>
    <th>${tooltipLabel('LG', 'Lagergruppe des Bestandsbereichs.')}</th>
    <th class="text-end">${tooltipLabel('Paletten', 'Anzahl aktiver Lagerplätze in dieser Lagergruppe.<br><br>Berechnung: 1 aktiver Slot = 1 Palette')}</th>
    <th class="text-end">${tooltipLabel('Stück', 'Summe der Mengen aus allen aktiven Lagerplätzen dieser Lagergruppe.<br><br>Berechnung: SUM(menge)')}</th>
    <th class="text-end">${tooltipLabel('Sachnr.', 'Anzahl unterschiedlicher Sachnummern in dieser Lagergruppe.<br><br>Berechnung: COUNT(DISTINCT sachnummer)')}</th>
    <th class="text-end">${tooltipLabel('Anteil %', 'Anteil der Paletten dieser Lagergruppe am gesamten Lagerbestand.<br><br>Berechnung: Paletten LG / Gesamtpaletten × 100')}</th>
  </tr>
</thead>
                                <tbody>
                                  ${
                                    detailItems.length
                                      ? detailItems.map(d => {
                                          const dPal = Number(d.paletten || 0);
                                          const dStueck = Number(d.stueck || 0);
                                          const dShare = pal > 0 ? (dPal / pal) * 100 : 0;

                                          return `
                                            <tr>
                                              <td class="fw-semibold">${escapeHtml(d.sachnummer || '—')}</td>
                                              <td class="text-end">${fmt(dPal)}</td>
                                              <td class="text-end">${fmt(dStueck)}</td>
                                              <td class="text-end lager-share-cell">
                                                <div>${dShare.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %</div>
                                                <div class="lager-share-bar">
                                                  <span style="width:${Math.max(0, Math.min(100, dShare))}%"></span>
                                                </div>
                                              </td>
                                            </tr>
                                          `;
                                        }).join('')
                                      : `<tr><td colspan="4" class="text-muted">Keine Sachnummern vorhanden.</td></tr>`
                                  }
                                </tbody>
                              </table>
                            </div>
                          </div>
                        </td>
                      </tr>
                    `;
                  }).join('')
                : `<tr><td colspan="5" class="text-muted">Keine Daten vorhanden.</td></tr>`
            }
          </tbody>
        </table>
      </div>

      <div class="lager-total-box small mb-2">
        <div><b>Gesamt:</b> Paletten ${fmt(totals.paletten)} · Stück ${fmt(totals.stueck)} · Sachnr ${fmt(totals.sachnr)}</div>
      </div>

      <div class="lager-filter-box small text-muted">
        <div><b>Gefiltert:</b> Paletten ${fmt(filtered.paletten)} · Stück ${fmt(filtered.stueck)} · Sachnr ${fmt(filtered.sachnr)}</div>
      </div>
    `;

    bindLagerBestandToggle(bestandEl);
initDashboardTooltips(bestandEl);
initDashboardTooltips(transportEl);

    // =========================
    // Transport / LKW
    // =========================
    const sortedTransportRows = [...transportRows].sort((a, b) => {
      const diffRelevant = Number(b.lkw_relevant || 0) - Number(a.lkw_relevant || 0);
      if (diffRelevant !== 0) return diffRelevant;

      const diffFull = Number(b.volle_lkw || 0) - Number(a.volle_lkw || 0);
      if (diffFull !== 0) return diffFull;

      return String(a.lg || '').localeCompare(String(b.lg || ''), 'de');
    });

    const top3Transport = sortedTransportRows.slice(0, 3).map(r => String(r.lg || ''));

    const transportBadgeForLg = (lg) => {
      const idx = top3Transport.indexOf(String(lg));
      if (idx === -1) return '';
      if (idx === 0) return `<span class="badge text-bg-warning lager-top-badge">Top 1</span>`;
      if (idx === 1) return `<span class="badge text-bg-secondary lager-top-badge">Top 2</span>`;
      return `<span class="badge text-bg-light border lager-top-badge">Top 3</span>`;
    };

    transportEl.innerHTML = `
      <div class="lager-section-title">
        <div class="small text-muted">Transportrelevante Verpackungen nach Lagergruppe</div>
        <span class="badge text-bg-success lager-lkw-badge">${fmt(transport.volle_lkw)} volle LKW</span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-3 text-nowrap">
          <thead class="text-muted small">
  <tr>
    <th>${tooltipLabel('LG', 'Lagergruppe, für die die transportrelevanten Verpackungen ausgewertet werden.')}</th>
    <th class="text-end">${tooltipLabel('GT14488 / GT14491', 'Anzahl Paletten mit Verpackung GT14488 oder GT14491.')}</th>
    <th class="text-end">${tooltipLabel('VW0012 / 114003', 'Anzahl Paletten mit Verpackung VW0012 oder 114003.')}</th>
    <th class="text-end">${tooltipLabel('VW0001', 'Anzahl Paletten mit Verpackung VW0001.')}</th>
    <th class="text-end">${tooltipLabel('Karton', 'Anzahl Paletten mit Verpackung Karton/Kartons.<br><br>Bei Sarajevo ebenfalls LKW-relevant.')}</th>
    <th class="text-end">${tooltipLabel('LKW-relevant', 'Summe aller transportrelevanten Verpackungen.<br><br>Berechnung: GT + VW + VW0001 + Karton')}</th>
    <th class="text-end">${tooltipLabel('Anteil %', 'Anteil der LKW-relevanten Paletten dieser Lagergruppe an allen LKW-relevanten Paletten.<br><br>Berechnung: LKW-relevant LG / LKW-relevant gesamt × 100')}</th>
    <th class="text-end">${tooltipLabel('volle LKW', 'Anzahl vollständig gefüllter LKW nach Verpackungslogik.<br><br>Berechnung: GT-LKW + VW-LKW + VW0001-LKW + Karton-LKW')}</th>
    <th class="text-end">${tooltipLabel('offen', 'Paletten, die zwar im Bestand sind, aber keiner transportrelevanten Verpackung zugeordnet sind.<br><br>Berechnung: Paletten gesamt - LKW-relevant')}</th>
  </tr>
</thead>
          <tbody>
            ${
              sortedTransportRows.length
                ? sortedTransportRows.map(r => {
                    const relevant = Number(r.lkw_relevant || 0);
                    const share = Number(transport.lkw_relevant || 0) > 0
                      ? (relevant / Number(transport.lkw_relevant || 0)) * 100
                      : 0;

                    return `
                      <tr>
                        <td>
                          <span class="fw-semibold">${escapeHtml(r.lg || 'UNBEKANNT')}</span>
                          ${transportBadgeForLg(r.lg || 'UNBEKANNT')}
                        </td>
                        <td class="text-end">${fmt(r.gt_count)}</td>
                        <td class="text-end">${fmt(r.vw_count)}</td>
                        <td class="text-end">${fmt(r.vw0001_count || 0)}</td>
                        <td class="text-end">${fmt(r.karton_count || 0)}</td>
                        <td class="text-end fw-semibold">${fmt(relevant)}</td>
                        <td class="text-end lager-share-cell">
                          <div>${share.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %</div>
                          <div class="lager-share-bar">
                            <span style="width:${Math.max(0, Math.min(100, share))}%"></span>
                          </div>
                        </td>
                        <td class="text-end">${fmt(r.volle_lkw)}</td>
                        <td class="text-end text-muted">${fmt(r.offen)}</td>
                      </tr>
                    `;
                  }).join('')
                : `<tr><td colspan="9" class="text-muted">Keine Transportdaten vorhanden.</td></tr>`
            }
          </tbody>
          <tfoot>
            <tr class="table-light">
              <th>Gesamt</th>
              <th class="text-end">${fmt(transport.gt_count)}</th>
              <th class="text-end">${fmt(transport.vw_count)}</th>
              <th class="text-end">${fmt(transport.vw0001_count || 0)}</th>
              <th class="text-end">${fmt(transport.karton_count || 0)}</th>
              <th class="text-end">${fmt(transport.lkw_relevant)}</th>
              <th class="text-end">${Number(transport.lkw_relevant || 0) > 0 ? '100,0 %' : '0,0 %'}</th>
              <th class="text-end">${fmt(transport.volle_lkw)}</th>
              <th class="text-end">${fmt(transport.offen)}</th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="lager-total-box small mb-2">
        <div><b>Erledigt gesamt:</b> ${fmt(transport.erledigt_gesamt)} Paletten → ${fmt(transport.volle_lkw)} volle LKW</div>
      </div>

      <div class="lager-filter-box small text-muted">
  <div>
    <b>GT14488 / GT14491:</b> ${fmt(transport.gt_count)} → ${fmt(transport.gt_lkw)} LKW + ${fmt(transport.gt_rest)} einzelne
    ·
    <b>VW0012 / 114003:</b> ${fmt(transport.vw_count)} → ${fmt(transport.vw_lkw)} LKW + ${fmt(transport.vw_rest)} einzelne
    ·
    <b>VW0001:</b> ${fmt(transport.vw0001_count || 0)} → ${fmt(transport.vw0001_lkw || 0)} LKW + ${fmt(transport.vw0001_rest || 0)} einzelne
    ·
    <b>Karton:</b> ${fmt(transport.karton_count || 0)} → ${fmt(transport.karton_lkw || 0)} LKW + ${fmt(transport.karton_rest || 0)} einzelne
  </div>
</div>
    `;

  } catch (err) {
    console.error('fillDashboardLagerStats failed:', err);

    bestandEl.innerHTML = `<div class="text-danger small">Lagerbestand konnte nicht geladen werden.</div>`;
    transportEl.innerHTML = `<div class="text-danger small">Transportdaten konnten nicht geladen werden.</div>`;
  }
}
/* =========================================================
   DASHBOARD REFRESH
========================================================= */
async function refreshDashboard(force = false) {
  if (DASHBOARD_REFRESH_PROMISE) {
    return DASHBOARD_REFRESH_PROMISE;
  }

  const seq = ++DASHBOARD_REFRESH_SEQ;

  DASHBOARD_REFRESH_PROMISE = (async () => {
    setReloadButtonBusy(true);

    try {
      if (force) {
        invalidateDashboardBaseCache();
      }

      await loadDashboardBaseData(force);

      const results = await Promise.allSettled([
        fillStammdatenTrend(),
        fillWareneingangTrend(),
        fillWarenausgangTrend(),
        fillDriverSummary(),
        fillDashboardLagerStats()
      ]);

      if (seq !== DASHBOARD_REFRESH_SEQ) {
        return;
      }

      results.forEach((result, idx) => {
        if (result.status === 'rejected') {
          console.error(`Dashboard-Block ${idx + 1} fehlgeschlagen:`, result.reason);
        }
      });

      updateLastRefreshStamp();
    } finally {
      setReloadButtonBusy(false);
      DASHBOARD_REFRESH_PROMISE = null;
    }
  })();

  return DASHBOARD_REFRESH_PROMISE;
}

/* =========================================================
   INIT
========================================================= */
export async function initDashboard(rootEl) {
  console.log('✅ Dashboard initialisiert:', rootEl);

  const scoped$ = selector => rootEl.querySelector(selector);

  const dateEl = scoped$('#datePicker');
  if (dateEl) {
    dateEl.value = new Date().toISOString().slice(0, 10);
  }

  const weLgFilterEl = scoped$('#weLgFilter');
  if (weLgFilterEl && !weLgFilterEl.dataset.bound) {
    weLgFilterEl.dataset.bound = '1';
    weLgFilterEl.addEventListener('change', async (e) => {
      WE_LG_FILTER = e.target.value || 'ALL';
      await fillWareneingangTrend();
      updateLastRefreshStamp();
    });
  }

  const waLgFilterEl = scoped$('#waLgFilter');
  if (waLgFilterEl && !waLgFilterEl.dataset.bound) {
    waLgFilterEl.dataset.bound = '1';
    waLgFilterEl.addEventListener('change', async (e) => {
      WA_LG_FILTER = e.target.value || 'ALL';
      await fillWarenausgangTrend();
      updateLastRefreshStamp();
    });
  }

  const btnReload = scoped$('#btnReload');
  if (btnReload && !btnReload.dataset.bound) {
    btnReload.dataset.bound = '1';
    btnReload.addEventListener('click', async () => {
      await refreshDashboard(true);
    });
  }

  const rangeEl = scoped$('#rangeSelect');
  if (rangeEl && !rangeEl.dataset.bound) {
    rangeEl.dataset.bound = '1';
    rangeEl.addEventListener('change', async () => {
      const isCustom = rangeEl.value === 'custom';
      if (dateEl) dateEl.disabled = !isCustom;
      await refreshDashboard(true);
    });
  }

  await refreshDashboard(true);

  if (DASHBOARD_AUTO_RELOAD_TIMER) {
    clearInterval(DASHBOARD_AUTO_RELOAD_TIMER);
  }

  DASHBOARD_AUTO_RELOAD_TIMER = setInterval(async () => {
    console.log('[AutoReload] Dashboard aktualisiert');
    await refreshDashboard(true);
  }, 300000);
}

/* Optional für Debug im Browser */
window.refreshDashboard = refreshDashboard;
window.invalidateDashboardBaseCache = invalidateDashboardBaseCache;