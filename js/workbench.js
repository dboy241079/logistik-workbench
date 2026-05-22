console.log('jQuery', !!window.jQuery);

console.log('bootstrap', !!window.bootstrap);

const hasDT = !!(jQuery.fn.dataTable || jQuery.fn.DataTable);
console.log("DataTables", hasDT);

console.log(
  [...document.querySelectorAll('.tab-content > .tab-pane.show.active')]
    .map(p => p.id)
);
/* /LKW/js/workbench.js */

/* -------------------------------------------------------
   A) Dein Global-State + DataTables / Import / Export
------------------------------------------------------- */
(() => {
  // --- Global in-memory state (kein LocalStorage) ---
  const state = {
    drivers: [],
    goods: [],
    inbound: [],
    outbound: [],
    special: [],
    cmr: []
  };
  window.__WB_STATE__ = state;

  // --- Helpers ---
  const $ = window.jQuery;
  const fmtNumber = (n) => (isNaN(+n) ? 0 : +(+n).toFixed(3));

  function setStat() {
    if (!$) return;
    $('#statDrivers').text(state.drivers.length);
    $('#statGoods').text(state.goods.length);
    const inQty = state.inbound.reduce((s, r) => s + fmtNumber(r.qty), 0);
    const outQty = state.outbound.reduce((s, r) => s + fmtNumber(r.qty), 0);
    $('#statInboundQty').text(inQty);
    $('#statOutboundQty').text(outQty);
  }

  function recalcStock() {
    if (!$) return;
    const sums = {};
    state.inbound.forEach(r => {
      const k = r.item_code || '';
      if (!k) return;
      sums[k] = sums[k] || { in: 0, out: 0 };
      sums[k].in += fmtNumber(r.qty);
    });
    state.outbound.forEach(r => {
      const k = r.item_code || '';
      if (!k) return;
      sums[k] = sums[k] || { in: 0, out: 0 };
      sums[k].out += fmtNumber(r.qty);
    });

    const rows = Object.entries(sums).map(([item_code, v]) => {
      const g = state.goods.find(x => x.item_code === item_code) || {};
      return {
        item_code,
        description: g.description || '',
        unit: g.unit || '',
        in_sum: fmtNumber(v.in),
        out_sum: fmtNumber(v.out),
        stock: fmtNumber(v.in - v.out)
      };
    });

    fillTable('#tblStock', rows, ['item_code', 'description', 'unit', 'in_sum', 'out_sum', 'stock']);
  }

  function ensureDataTypes(section, row) {
    const numericFields = {
      inbound: ['qty'],
      outbound: ['qty'],
      goods: ['weight_kg_per_unit'],
      special: ['duration_min'],
      cmr: ['packages', 'weight_kg']
    }[section] || [];
    numericFields.forEach(f => { if (row[f] !== undefined) row[f] = fmtNumber(row[f]); });
    return row;
  }

  function parseFile(file, onRows) {
    const name = file.name.toLowerCase();
    if (name.endsWith('.csv')) {
      Papa.parse(file, {
        header: true,
        skipEmptyLines: true,
        complete: res => onRows(res.data)
      });
    } else {
      const reader = new FileReader();
      reader.onload = (e) => {
        const data = new Uint8Array(e.target.result);
        const wb = XLSX.read(data, { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
        onRows(rows);
      };
      reader.readAsArrayBuffer(file);
    }
  }
function fillTable(selector, rows, columns) {
  if (!$) return;

  const hasDT = !!($.fn.dataTable || $.fn.DataTable);
  if (!hasDT) return;

  const table = $(selector);
  if (!table.length) return;

  const isDT = $.fn.dataTable?.isDataTable || $.fn.DataTable?.isDataTable;
  if (isDT && isDT(table[0])) {
    table.DataTable().clear().destroy();
  }

  const tbody = table.find('tbody');
  tbody.empty();

  rows.forEach(r => {
    const tr = $('<tr/>');
    columns.forEach(c => tr.append($('<td/>').text(r[c] ?? '')));
    tbody.append(tr);
  });

  table.DataTable({
    pageLength: 10,
    lengthMenu: [10, 25, 50, 100],
    order: [],
    language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/de-DE.json' }
  });
}


  function exportData(section, asXLSX = false) {
    const map = {
      drivers: { rows: state.drivers, cols: ['name', 'license_no', 'phone', 'vehicle_no', 'active'] },
      goods: { rows: state.goods, cols: ['item_code', 'description', 'unit', 'weight_kg_per_unit'] },
      inbound: { rows: state.inbound, cols: ['txn_date', 'supplier', 'delivery_note', 'item_code', 'description', 'qty', 'unit', 'lot', 'location', 'receiver_user', 'driver_id'] },
      outbound: { rows: state.outbound, cols: ['txn_date', 'customer', 'order_no', 'item_code', 'description', 'qty', 'unit', 'lot', 'destination', 'picker_user', 'driver_id'] },
      special: { rows: state.special, cols: ['task_date', 'task_type', 'reference_no', 'description', 'duration_min', 'user_name', 'driver_id'] },
      cmr: { rows: state.cmr, cols: ['cmr_no', 'cmr_date', 'shipper', 'consignee', 'place_of_delivery', 'goods_description', 'packages', 'weight_kg', 'vehicle_no', 'driver_id', 'outbound_id', 'notes'] }
    };
    const cfg = map[section];
    if (!cfg) return;

    const { rows, cols } = cfg;

    if (!asXLSX) {
      const csv = Papa.unparse(rows, { columns: cols });
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      saveAs(blob, `${section}.csv`);
    } else {
      const ws = XLSX.utils.json_to_sheet(rows, { header: cols });
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, section);
      const wbout = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
      saveAs(new Blob([wbout], { type: 'application/octet-stream' }), `${section}.xlsx`);
    }
  }

  function attachExportButtons() {
    document.querySelectorAll('[data-export]').forEach(btn => {
      btn.addEventListener('click', () => {
        const section = btn.getAttribute('data-export');
        const asXLSX = document.getElementById('chkXLSX')?.checked ?? false;
        exportData(section, asXLSX);
      });
    });
  }

  function saveSession() {
    const blob = new Blob([JSON.stringify(state, null, 2)], { type: 'application/json' });
    saveAs(blob, `logistics_session_${new Date().toISOString().slice(0, 10)}.json`);
  }

  function loadSession(file) {
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const obj = JSON.parse(e.target.result);
        ['drivers', 'goods', 'inbound', 'outbound', 'special', 'cmr'].forEach(k => {
          state[k] = Array.isArray(obj[k]) ? obj[k] : [];
        });
        refreshAllTables();
      } catch (err) {
        alert('Session-Datei ungültig: ' + err.message);
      }
    };
    reader.readAsText(file);
  }

  function refreshAllTables() {
    setStat();
    recalcStock();
    fillTable('#tblDrivers', state.drivers, ['name', 'license_no', 'phone', 'vehicle_no', 'active']);
    fillTable('#tblGoods', state.goods, ['item_code', 'description', 'unit', 'weight_kg_per_unit']);
    fillTable('#tblInbound', state.inbound, ['txn_date', 'supplier', 'delivery_note', 'item_code', 'description', 'qty', 'unit', 'lot', 'location', 'receiver_user', 'driver_id']);
    fillTable('#tblOutbound', state.outbound, ['txn_date', 'customer', 'order_no', 'item_code', 'description', 'qty', 'unit', 'lot', 'destination', 'picker_user', 'driver_id']);
    fillTable('#tblSpecial', state.special, ['task_date', 'task_type', 'reference_no', 'description', 'duration_min', 'user_name', 'driver_id']);
    fillTable('#tblCMR', state.cmr, ['cmr_no', 'cmr_date', 'shipper', 'consignee', 'place_of_delivery', 'goods_description', 'packages', 'weight_kg', 'vehicle_no', 'driver_id', 'outbound_id', 'notes']);
  }

  function handleSectionFile(inputId, section, columns) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.addEventListener('change', (ev) => {
      const file = ev.target.files[0];
      if (!file) return;
      parseFile(file, rows => {
        const norm = rows.map(r => {
          const o = {};
          columns.forEach(c => { o[c] = r[c] ?? r[c.toUpperCase()] ?? r[c.toLowerCase()] ?? ''; });
          Object.keys(r).forEach(k => { if (!(k in o)) o[k] = r[k]; });
          return ensureDataTypes(section, o);
        });
        state[section] = norm;
        refreshAllTables();
      });
    });
  }

  function attachClearButtons() {
    const map = {
      btnClearDrivers: 'drivers',
      btnClearGoods: 'goods',
      btnClearInbound: 'inbound',
      btnClearOutbound: 'outbound',
      btnClearSpecial: 'special',
      btnClearCMR: 'cmr'
    };
    Object.entries(map).forEach(([btnId, section]) => {
      const btn = document.getElementById(btnId);
      if (!btn) return;
      btn.addEventListener('click', () => {
        state[section] = [];
        refreshAllTables();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    // nur wenn die Inputs existieren (sonst kein Stress)
    handleSectionFile('fileGoods', 'goods', ['item_code', 'description', 'unit', 'weight_kg_per_unit']);
    handleSectionFile('fileInbound', 'inbound', ['txn_date', 'supplier', 'delivery_note', 'item_code', 'description', 'qty', 'unit', 'lot', 'location', 'receiver_user', 'driver_id']);
    handleSectionFile('fileOutbound', 'outbound', ['txn_date', 'customer', 'order_no', 'item_code', 'description', 'qty', 'unit', 'lot', 'destination', 'picker_user', 'driver_id']);
    handleSectionFile('fileSpecial', 'special', ['task_date', 'task_type', 'reference_no', 'description', 'duration_min', 'user_name', 'driver_id']);
    handleSectionFile('fileCMR', 'cmr', ['cmr_no', 'cmr_date', 'shipper', 'consignee', 'place_of_delivery', 'goods_description', 'packages', 'weight_kg', 'vehicle_no', 'driver_id', 'outbound_id', 'notes']);

    attachExportButtons();
    attachClearButtons();

    const on = (id, ev, fn) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener(ev, fn);
    };

    on('btnRecalc', 'click', recalcStock);
    on('btnSaveSession', 'click', saveSession);
    on('btnSaveSession2', 'click', saveSession);
    on('sessionFile', 'change', e => e.target.files[0] && loadSession(e.target.files[0]));
    on('sessionFile2', 'change', e => e.target.files[0] && loadSession(e.target.files[0]));

    refreshAllTables();
  });
})();

/* -------------------------------------------------------
   B) Tabs: Lazy load + Initial-Tab automatisch laden
   ✅ Wichtig: Listener jetzt auf document (damit Offcanvas-Buttons auch gehen)
------------------------------------------------------- */
(() => {
  if (typeof bootstrap === 'undefined') return;

  const cache = new Map();

  function pickRoot(htmlText) {
    const doc = new DOMParser().parseFromString(htmlText, 'text/html');
    const root = doc.querySelector('[data-tab-root]') || doc.querySelector('main') || doc.body;
    return root ? root.innerHTML : htmlText;
  }
  function forceSingleActiveTab(tabId) {
  // Alle Pane sauber zurücksetzen
  document.querySelectorAll('.tab-content > .tab-pane').forEach(pane => {
    const isTarget = pane.id === tabId;
    pane.classList.toggle('active', isTarget);
    pane.classList.toggle('show', isTarget);
  });

  // Alle Trigger synchronisieren (Desktop + Offcanvas + Dropdown-Items)
  document.querySelectorAll('[data-bs-toggle="tab"][data-bs-target]').forEach(btn => {
    const target = (btn.getAttribute('data-bs-target') || '').replace(/^#/, '');
    const isTarget = target === tabId;

    btn.classList.toggle('active', isTarget);
    btn.setAttribute('aria-selected', isTarget ? 'true' : 'false');
  });
}

  async function loadInto(pane, url) {
    if (!pane || !url) return;
    pane.innerHTML = '<div class="p-3 text-muted">Lade…</div>';

    const res = await fetch(url, { cache: 'no-store', credentials: 'include' });
    if (!res.ok) {
      const body = await res.text().catch(() => '');
      console.error('loadInto failed:', { url, status: res.status, body: body.slice(0, 300) });
      throw new Error(`HTTP ${res.status} (${url})`);
    }

    const html = await res.text();
    pane.innerHTML = pickRoot(html);
  }

  async function maybeInit(pane, modUrl, initName, tabId) {
    if (!pane || !modUrl || !initName) return;

    const entry = cache.get(tabId) || {};
    if (entry.inited) return;

    const ver = (window.__APP_VER__ ||= Math.floor(Date.now() / 60000));
    const mod = await import(`${modUrl}?v=${ver}`);

    const fn = mod[initName] || mod.default || window[initName];
    if (typeof fn === 'function') {
      await fn(pane);
      entry.inited = true;
      cache.set(tabId, entry);
    }
  }

  function updateFlowActive(tabId) {
  const isFlowTab = ['inbound', 'outbound', 'kommi_orders'].includes(tabId);

  const flowToggle = document.getElementById('tab-warenfluss');
  if (flowToggle) {
    if (isFlowTab) flowToggle.classList.add('active');
    else flowToggle.classList.remove('active');
  }

  const ocFlow = document.getElementById('ocWarenfluss');
  if (ocFlow) {
    if (isFlowTab) ocFlow.classList.add('show');
    else ocFlow.classList.remove('show');
  }
}

  function updateOffcanvasActive(tabId) {
    const oc = document.getElementById('wbOffcanvas');
    if (!oc) return;

    oc.querySelectorAll('[data-bs-toggle="tab"][data-bs-target]').forEach(btn => {
      const target = (btn.getAttribute('data-bs-target') || '').replace(/^#/, '');
      btn.classList.toggle('active', target === tabId);
    });
  }
 function isWorkbenchMainTab(btn) {
  if (!btn) return false;

  return !!(
    btn.closest('#mainTabs') ||
    btn.closest('#wbOffcanvas')
  );
}

function isWorkbenchTabButton(btn) {
  if (!btn) return false;
  return btn.matches('[data-bs-toggle="tab"][data-bs-target]');
}

document.addEventListener('click', (ev) => {
  const btn = ev.target?.closest?.('[data-bs-toggle="tab"][data-bs-target]');
  if (!btn) return;

  if (!isWorkbenchMainTab(btn)) return;
  if (!isWorkbenchTabButton(btn)) return;

  handleTabButton(btn).catch(console.error);
});

document.addEventListener('shown.bs.tab', (ev) => {
  const btn = ev.target?.closest?.('[data-bs-toggle="tab"]');
  if (!btn) return;

  // Nur Haupttabs der Workbench behandeln
  if (!isWorkbenchMainTab(btn)) return;

  handleTabButton(btn).catch(console.error);
});

  async function handleTabButton(btn) {
    if (!btn) return;

    const paneSel = btn.getAttribute('data-bs-target') || btn.getAttribute('href');
    if (!paneSel) return;

    const pane = document.querySelector(paneSel);
    if (!pane) return;

    const url = btn.dataset.url || '';
    const modUrl = btn.dataset.module || '';
    const initFn = btn.dataset.init || '';
    const tabId = paneSel.replace(/^#/, '');

// Ganz wichtig: nur EIN Tab/Pain darf aktiv sein
forceSingleActiveTab(tabId);

updateFlowActive(tabId);
updateDriversActive(tabId);
updateOffcanvasActive(tabId);

    // URL updaten
    const u = new URL(location.href);
    u.searchParams.set('tab', tabId);
    history.replaceState({}, '', u);

    // HTML laden (einmal)
    if (url && !pane.dataset.loaded) {
      await loadInto(pane, url);
      pane.dataset.loaded = '1';
    }

    // Init (einmal)
    await maybeInit(pane, modUrl, initFn, tabId);
  }

  // ✅ Deep-Link: ?tab=...
  const params = new URLSearchParams(location.search);
  const deeplink = params.get('tab');
  if (deeplink) {
    const btn = document.querySelector(`[data-bs-toggle="tab"][data-bs-target="#${deeplink}"]`);
    if (btn) {
      bootstrap.Tab.getOrCreateInstance(btn).show();
      // sicherheitshalber direkt laden
      handleTabButton(btn).catch(console.error);
    }
  }

  // ✅ GLOBAL: fängt Desktop + Offcanvas ab
  document.addEventListener('shown.bs.tab', (ev) => {
  const btn = ev.target?.closest?.('[data-bs-toggle="tab"]');
  if (!btn) return;

  // Nur Haupttabs der Workbench behandeln
  if (!isWorkbenchMainTab(btn)) return;

  handleTabButton(btn).catch(console.error);
});

  // ✅ Initial: aktiven Tab triggern, damit sofort geladen wird
  document.addEventListener('DOMContentLoaded', () => {
  const activeBtn =
    document.querySelector('#mainTabs [data-bs-toggle="tab"].active') ||
    document.querySelector('#wbOffcanvas [data-bs-toggle="tab"].active');

  if (activeBtn && isWorkbenchMainTab(activeBtn)) {
    bootstrap.Tab.getOrCreateInstance(activeBtn).show();
    handleTabButton(activeBtn).catch(console.error);
  }
});
})();

function updateDriversActive(tabId) {
  const driversToggle = document.getElementById('tab-drivers');
  if (driversToggle) {
    if (tabId === 'drivers' || tabId === 'drivers_kiosk') {
      driversToggle.classList.add('active');
    } else {
      driversToggle.classList.remove('active');
    }
  }

  const ocDrivers = document.getElementById('ocDrivers');
  if (ocDrivers) {
    if (tabId === 'drivers' || tabId === 'drivers_kiosk') {
      ocDrivers.classList.add('show');
    } else {
      ocDrivers.classList.remove('show');
    }
  }
}

/* -------------------------------------------------------
   C) Live Online-Badge: ping.php → Badge/Dot updaten
   ✅ auch Offcanvas Badge mitnehmen
------------------------------------------------------- */
(() => {
  async function pingOnline() {
    const badge = document.getElementById('onlineCountBadge');
    const num = document.getElementById('onlineCountNum');
    const dot = document.getElementById('onlineDot');

    const ocBadge = document.getElementById('ocOnlineCountBadge');
    const ocNum = document.getElementById('ocOnlineCountNum');

    // Wenn nichts existiert, ist User vermutlich nicht eingeloggt
    if (!badge && !dot && !num && !ocBadge && !ocNum) return;

    try {
      const res = await fetch('/api/ping.php', { credentials: 'include', cache: 'no-store' });
      if (!res.ok) return;

      const data = await res.json();
      if (!data.loggedIn) return;

      if (dot) dot.classList.toggle('d-none', !data.isOnline);

      const c = Number(data.onlineUsersCount || 0);

      if (num) num.textContent = String(c);
      if (badge) badge.classList.toggle('d-none', !(c > 0));

      if (ocNum) ocNum.textContent = String(c);
      if (ocBadge) ocBadge.classList.toggle('d-none', !(c > 0));
    } catch (e) {
      // still quiet
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    pingOnline();
    setInterval(pingOnline, 60000);
  });
})();

/* -------------------------------------------------------
   D) CSS-Var für Nav-Höhe (Iframe / Layout)
------------------------------------------------------- */
(() => {
  function setNavHeightVar() {
    const nav = document.querySelector('nav.navbar');
    const h = nav ? nav.offsetHeight : 140;
    document.documentElement.style.setProperty('--nav-h', (h + 24) + 'px'); // +padding
  }
  window.addEventListener('resize', setNavHeightVar);
  document.addEventListener('DOMContentLoaded', setNavHeightVar);

  document.addEventListener('shown.bs.collapse', setNavHeightVar);
  document.addEventListener('hidden.bs.collapse', setNavHeightVar);

  document.addEventListener('shown.bs.offcanvas', setNavHeightVar);
  document.addEventListener('hidden.bs.offcanvas', setNavHeightVar);
})();

/* -------------------------------------------------------
   E) Mobile Verhalten:
   - Navbar default "zu" (Offcanvas)
   - Scroll: Offcanvas schließt, Navbar blendet aus/ein
   - Navbar nur ausblenden, wenn Menü zu ist
   - Tap außerhalb schließt Menü
   - iframe-Klick/Scroll schließt Menü ebenfalls
------------------------------------------------------- */
(() => {
  if (typeof bootstrap === 'undefined') return;

  const mq = window.matchMedia('(max-width: 991.98px)');
  const nav = document.getElementById('mainNavbar');

  const collapseEl = document.getElementById('mainTabsCollapse');
  const offcanvasEl = document.getElementById('wbOffcanvas');

  if (!nav) return;

  const SCROLL_THRESHOLD = 12;
  const TOP_LOCK_PX = 20;
  const ENABLE_HAPTIC = true;

  let lastY = window.scrollY;
  let ticking = false;
  let collapseInst = null;
  let offInst = null;

  const isMobile = () => mq.matches;

  function vibrate(ms = 10) {
    if (!ENABLE_HAPTIC) return;
    if (navigator.vibrate) navigator.vibrate(ms);
  }

  function getCollapse() {
    if (!collapseEl) return null;
    if (!collapseInst) {
      collapseInst = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
    }
    return collapseInst;
  }

  function getOffcanvas() {
    if (!offcanvasEl) return null;
    if (!offInst) {
      offInst = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
    }
    return offInst;
  }

  function isMenuOpen() {
    const collapseOpen = !!(collapseEl && collapseEl.classList.contains('show'));
    const offOpen = !!(offcanvasEl && offcanvasEl.classList.contains('show'));
    return collapseOpen || offOpen;
  }

  function closeMenu() {
    const c = getCollapse();
    if (collapseEl?.classList.contains('show')) c?.hide();

    const o = getOffcanvas();
    if (offcanvasEl?.classList.contains('show')) o?.hide();
  }
  function bindImmediateOffcanvasClose() {
  const offcanvas = document.getElementById('wbOffcanvas');
  if (!offcanvas) return;

  offcanvas.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
    if (btn.dataset.ocBound === '1') return;
    btn.dataset.ocBound = '1';

    btn.addEventListener('click', () => {
      if (isMobile()) {
        closeMenu();
      }
    });
  });
}

  function showNav() {
    nav.classList.remove('nav-hidden');
  }

  function hideNav() {
    nav.classList.add('nav-hidden');
  }

  function handleScroll() {
    if (!isMobile()) {
      showNav();
      lastY = window.scrollY;
      return;
    }

    const y = window.scrollY;
    const dy = y - lastY;

    // Wenn Menü offen und User scrollt -> sofort schließen
    if (isMenuOpen()) closeMenu();

    // Ganz oben: immer sichtbar
    if (y < TOP_LOCK_PX) {
      showNav();
      lastY = y;
      return;
    }

    // Navbar nur ausblenden wenn Menü NICHT offen
    if (isMenuOpen()) {
      showNav();
      lastY = y;
      return;
    }

    if (dy > SCROLL_THRESHOLD) {
      hideNav();   // runter
    } else if (dy < -SCROLL_THRESHOLD) {
      showNav();   // hoch
    }

    lastY = y;
  }

  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      handleScroll();
      ticking = false;
    });
  }

  function initMobileState() {
    if (isMobile()) {
      closeMenu();
      showNav();
    } else {
      showNav();
    }
    lastY = window.scrollY;
    bindImmediateOffcanvasClose();
  }

  // Wenn Offcanvas/Collapse geöffnet wird -> Navbar sichtbar
  if (collapseEl) collapseEl.addEventListener('show.bs.collapse', () => { showNav(); vibrate(8); });
  if (offcanvasEl) offcanvasEl.addEventListener('show.bs.offcanvas', () => { showNav(); vibrate(8); });

  if (collapseEl) collapseEl.addEventListener('hidden.bs.collapse', () => vibrate(6));
  if (offcanvasEl) offcanvasEl.addEventListener('hidden.bs.offcanvas', () => vibrate(6));

  // Klick außerhalb schließt Menü
  document.addEventListener('click', (e) => {
    if (!isMobile()) return;
    if (!isMenuOpen()) return;

    const inOff = offcanvasEl && e.target.closest('#wbOffcanvas');
    const inCol = collapseEl && e.target.closest('#mainTabsCollapse');

    const offToggler = e.target.closest('[data-bs-toggle="offcanvas"][data-bs-target="#wbOffcanvas"]');
    const colToggler = e.target.closest('[data-bs-toggle="collapse"][data-bs-target="#mainTabsCollapse"]');

    if (!inOff && !inCol && !offToggler && !colToggler) {
      closeMenu();
    }
  }, { capture: true });

  // WICHTIG: Nachrichten aus iframes empfangen
  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) return;

    const data = event.data;
    if (!data || typeof data !== 'object') return;

    // Klick / Interaktion im iframe
    if (data.type === 'workbench:iframe-close-menu') {
      if (isMobile() && isMenuOpen()) {
        closeMenu();
      }
      return;
    }

    // Scroll im iframe
    if (data.type === 'workbench:iframe-scroll') {
      if (isMobile() && isMenuOpen()) {
        closeMenu();
      }
    }
  });

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', initMobileState);

  if (mq.addEventListener) {
    mq.addEventListener('change', initMobileState);
  }

  document.addEventListener('DOMContentLoaded', initMobileState);
})();

(() => {
  const KEY = 'wb_lagerplan';

  function getEls() {
    return {
      sel: document.getElementById('lagerplanSelect'),
      frame: document.getElementById('lagerplanFrame')
    };
  }

  function syncFromStoredOrSelect() {
    const { sel, frame } = getEls();
    if (!sel || !frame) return;

    const saved = sessionStorage.getItem(KEY);
    if (saved) sel.value = saved;

    // immer auf Select setzen (Select ist die Quelle)
    frame.src = sel.value;
  }

  // Delegation: funktioniert auch wenn Elemente später in DOM kommen
  document.addEventListener('change', (e) => {
    if (e.target?.id !== 'lagerplanSelect') return;

    const { frame } = getEls();
    if (!frame) return;

    sessionStorage.setItem(KEY, e.target.value);
    frame.src = e.target.value;
  });

  // beim Laden einmal syncen
  document.addEventListener('DOMContentLoaded', syncFromStoredOrSelect);

  // optional: wenn Tab angezeigt wird, nochmal syncen
  document.addEventListener('shown.bs.tab', (e) => {
    const btn = e.target?.closest?.('[data-bs-toggle="tab"]');
    const target = btn?.getAttribute('data-bs-target');
    if (target === '#lagerplan') syncFromStoredOrSelect();
  });
})();
