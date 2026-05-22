(() => {
  const API = '/kommi/api/status_by_ausgang.php';
  const CMR_URL = '/cmr/cmr.php';
  const LADELISTE_URL = '/kommi/ladeliste.php';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  let refreshTimer = null;
  let pollTimer = null;

  function getAusgangNr(tr) {
    const td = tr?.children?.[0];
    return (td?.dataset?.raw || td?.textContent || '').trim();
  }

  function getCellTextByIndex(tr, idx) {
    if (!tr || idx < 0) return '';
    const td = tr.children?.[idx];
    return (td?.dataset?.raw || td?.textContent || '').trim();
  }

  function normalizeText(s) {
    return String(s || '')
      .replace(/\s+/g, ' ')
      .replace(/↕/g, '')
      .trim()
      .toLowerCase();
  }

  function getHeaderIndexByContains(tableSelector, needle) {
    const table = document.querySelector(tableSelector);
    if (!table) return -1;

    const ths = Array.from(table.querySelectorAll('thead th'));
    const n = normalizeText(needle);

    return ths.findIndex(th => normalizeText(th.textContent).includes(n));
  }

  function getHeaderIndexByExactOrStartsWith(tableSelector, candidates = []) {
    const table = document.querySelector(tableSelector);
    if (!table) return -1;

    const ths = Array.from(table.querySelectorAll('thead th'));
    const labels = ths.map(th => normalizeText(th.textContent));

    for (const raw of candidates) {
      const c = normalizeText(raw);

      let idx = labels.findIndex(txt => txt === c);
      if (idx >= 0) return idx;

      idx = labels.findIndex(txt => txt.startsWith(c));
      if (idx >= 0) return idx;
    }

    return -1;
  }

  function isGroupHeader(tr) {
    if (tr.classList.contains('grp-start')) return true;
    const anyGroupBtnVisible = tr.querySelector('button.action-btn[data-role="group"]:not(.d-none)');
    return !!anyGroupBtnVisible;
  }

  function getGroupHeaderRows() {
    const tbody = $('#ausgangTable tbody');
    if (!tbody) return [];
    return $$('tr', tbody).filter(isGroupHeader);
  }

  function getCellTextFromRowOrGroupDetails(tr, idx) {
    if (!tr || idx < 0) return '';

    let val = getCellTextByIndex(tr, idx);
    if (val) return val;

    if (isGroupHeader(tr)) {
      let cur = tr.nextElementSibling;
      while (cur && !isGroupHeader(cur)) {
        val = getCellTextByIndex(cur, idx);
        if (val) return val;
        cur = cur.nextElementSibling;
      }
    }

    return '';
  }

  function getShipperFromRow(tr) {
    const idx = getHeaderIndexByContains('#ausgangTable', 'spedit');
    return getCellTextFromRowOrGroupDetails(tr, idx);
  }

  function getLicenceFromRow(tr) {
    const idx = getHeaderIndexByContains('#ausgangTable', 'kennz');
    return getCellTextFromRowOrGroupDetails(tr, idx);
  }

  function getLieferscheinFromRow(tr) {
    let idx = getHeaderIndexByExactOrStartsWith('#ausgangTable', [
      'lieferschein',
      'liefers.',
      'liefers',
      'lieferschein-nr',
      'lieferscheinnr',
      'ls-nr',
      'lief.-nr'
    ]);

    if (idx < 0) {
      idx = getHeaderIndexByExactOrStartsWith('#ausgangTable', ['ls']);
    }

    return getCellTextFromRowOrGroupDetails(tr, idx);
  }

  function findPrinterBtn(tr) {
    const icon = tr.querySelector('button.action-btn[data-role="group"] i.bi-printer');
    return icon ? icon.closest('button') : null;
  }

  function ensureStatusIcon(tr, status) {
    const td = tr?.children?.[0];
    if (!td) return;

    const s = String(status || '').trim().toUpperCase();
    const current = tr.dataset.kommiStatus || '';

    // nichts ändern, wenn Status identisch
    if (current === s && td.querySelector('.kommi-status-ico')) {
      return;
    }

    td.querySelector('.kommi-status-ico')?.remove();
    tr.dataset.kommiStatus = s;

    if (!s) return;

    const wrap = document.createElement('span');
    wrap.className = 'kommi-status-ico ms-2 d-inline-flex align-items-center gap-1';
    wrap.style.fontSize = '1rem';
    wrap.style.verticalAlign = 'middle';

    if (s === 'BEREITGESTELLT') {
      wrap.innerHTML = `<i class="bi bi-check2 text-secondary"></i>`;
      wrap.title = 'Bereitgestellt';
    } else if (s === 'VERLADUNG') {
      wrap.innerHTML = `
        <i class="bi bi-check2 text-secondary"></i>
        <i class="bi bi-check2 text-secondary"></i>
      `;
      wrap.title = 'Verladung läuft';
    } else if (s === 'VERLADEN_OK') {
      wrap.innerHTML = `
        <i class="bi bi-check2 text-secondary"></i>
        <i class="bi bi-check2 text-success"></i>
      `;
      wrap.title = 'Verladen OK';
    } else {
      return;
    }

    td.appendChild(wrap);
  }

  function ensureLadelisteBtn(tr, on, meta) {
    const printer = findPrinterBtn(tr);
    if (!printer) return;

    let btn = tr.querySelector('button.btn-ladeliste');

    if (!on) {
      btn?.remove();
      return;
    }

    const ausgangNr = (meta?.ausgang_nr || tr.dataset.cmrAusgang || getAusgangNr(tr) || '').trim();
    const shipper = (tr.dataset.shipper || getShipperFromRow(tr) || '').trim();
    const licence = (tr.dataset.licence || getLicenceFromRow(tr) || '').trim();
    const lieferschein = (tr.dataset.lieferschein || getLieferscheinFromRow(tr) || '').trim();

    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-outline-success btn-sm action-btn me-1 btn-ladeliste';
      btn.title = 'Ladeliste drucken';
      btn.innerHTML = '<i class="bi bi-card-checklist"></i>';

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const nr = (tr.dataset.cmrAusgang || getAusgangNr(tr) || '').trim();
        const sp = (tr.dataset.shipper || getShipperFromRow(tr) || '').trim();
        const li = (tr.dataset.licence || getLicenceFromRow(tr) || '').trim();
        const ls = (tr.dataset.lieferschein || getLieferscheinFromRow(tr) || '').trim();

        const url =
          `${LADELISTE_URL}?ausgang_nr=${encodeURIComponent(nr)}` +
          `&shipper=${encodeURIComponent(sp)}` +
          `&licence=${encodeURIComponent(li)}` +
          `&lieferschein=${encodeURIComponent(ls)}` +
          `&embed=1`;

        window.open(url, '_blank');
      });

      printer.insertAdjacentElement('afterend', btn);
    }

    btn.dataset.ausgangNr = ausgangNr;
    btn.dataset.shipper = shipper;
    btn.dataset.licence = licence;
    btn.dataset.lieferschein = lieferschein;
  }

  function applyPrinterState(tr, ready, meta) {
    const btn = findPrinterBtn(tr);
    if (!btn) return;

    if (!btn.dataset.origTitle) btn.dataset.origTitle = btn.title || '';
    if (!btn.dataset.origClass) btn.dataset.origClass = btn.className || '';

    const nextReady = ready ? '1' : '0';
    const prevReady = tr.dataset.kommiReadyState || '0';

    // wenn Zustand gleich geblieben ist -> nur Ladeliste sicherstellen, sonst nichts anfassen
    if (prevReady === nextReady) {
      ensureLadelisteBtn(tr, ready, meta);
      return;
    }

    tr.dataset.kommiReadyState = nextReady;

    btn.className = btn.dataset.origClass;
    btn.title = btn.dataset.origTitle;

    delete tr.dataset.cmrReady;
    delete tr.dataset.cmrAusgang;
    delete tr.dataset.shipper;
    delete tr.dataset.licence;
    delete tr.dataset.lieferschein;

    if (ready) {
      btn.className = btn.dataset.origClass.replace('btn-outline-primary', 'btn-outline-success');
      btn.title = 'CMR erstellen (Verladen OK)';

      tr.dataset.cmrReady = '1';
      tr.dataset.cmrAusgang = (meta?.ausgang_nr || getAusgangNr(tr) || '').trim();
      tr.dataset.shipper = (meta?.shipper || getShipperFromRow(tr) || '').trim();
      tr.dataset.licence = (meta?.licence || getLicenceFromRow(tr) || '').trim();
      tr.dataset.lieferschein = (meta?.lieferschein || getLieferscheinFromRow(tr) || '').trim();

      ensureLadelisteBtn(tr, true, meta);
    } else {
      ensureLadelisteBtn(tr, false, meta);
    }
  }

  async function fetchStatus(ausgangs) {
    const res = await fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ausgang_nrs: ausgangs })
    });

    const ct = res.headers.get('content-type') || '';
    const raw = await res.text();

    if (!ct.includes('application/json')) {
      console.error('kommi status API kein JSON:', ct, raw);
      return [];
    }

    let j;
    try {
      j = JSON.parse(raw);
    } catch (err) {
      console.error('kommi status API parse error:', raw);
      return [];
    }

    if (!j.ok) {
      console.warn('kommi status API error:', j.error);
      return [];
    }

    return j.items || [];
  }

  async function refresh() {
    const rows = getGroupHeaderRows();
    if (!rows.length) return;

    const ausgangs = rows.map(getAusgangNr).filter(Boolean);
    if (!ausgangs.length) return;

    const items = await fetchStatus(ausgangs);
    const map = new Map(items.map(it => [String(it.ausgang_nr).trim(), it]));

    for (const tr of rows) {
      const nr = getAusgangNr(tr).trim();
      const it = map.get(nr) || null;

      const status = String(it?.status || '').trim().toUpperCase();
      const ok = status === 'VERLADEN_OK';

      ensureStatusIcon(tr, status);
      applyPrinterState(tr, ok, it || {});
    }
  }

  function schedule() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      refresh().catch(err => console.warn('Kommi-Status Refresh Fehler:', err));
    }, 250);
  }

  function bindPrinterOverride() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;

      const isPrinter = btn.querySelector('i.bi-printer');
      if (!isPrinter) return;

      const tr = btn.closest('tr');
      if (!tr) return;

      if (tr.dataset.cmrReady === '1') {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const ausgangNr = tr.dataset.cmrAusgang || getAusgangNr(tr);
        window.open(`${CMR_URL}?ausgang_nr=${encodeURIComponent(ausgangNr)}&embed=1`, '_blank');
      }
    }, true);
  }

function init() {
  const tbody = $('#ausgangTable tbody');
  if (!tbody) {
    console.warn('warenausgang.kommi_status: #ausgangTable tbody nicht gefunden');
    return;
  }

  console.log('Kommi-Status init aktiv');

  bindPrinterOverride();

  const triggerRefresh = () => {
    schedule();
    setTimeout(schedule, 350);
    setTimeout(schedule, 900);
  };

  document.addEventListener('wa:rows-ready', triggerRefresh);

  // initial auch versuchen
  triggerRefresh();
}

window.KOMMI_refreshWarenausgangStatus = refresh;
window.KOMMI_scheduleWarenausgangStatus = schedule;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}

})();