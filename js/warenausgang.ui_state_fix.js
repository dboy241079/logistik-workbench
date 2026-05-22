// /LKW/js/warenausgang.ui_state_fix.js
(() => {
  const SELECTORS = {
    monthAccordion: '#waMonthAccordion',
    statsSach: '#statsSach',
    statsGruppen: '#statsGruppen',
  };

  const state = {
    month: null,   // z.B. "2026-03"
    sach: null,    // data-topkey
    gruppe: null   // data-grp
  };

  let restoreTimer = null;
  let isRestoring = false;

  function $(sel, root = document) {
    return root.querySelector(sel);
  }

  function paneIsOpenForMonth(btn) {
    if (!btn) return false;
    const target = btn.getAttribute('data-bs-target');
    if (!target) return false;
    const pane = document.querySelector(target);
    return !!pane && pane.classList.contains('show');
  }

  function readMonthStateFromDom() {
    const openBtn = document.querySelector(
      '#waMonthAccordion button[data-wa-month][aria-expanded="true"]'
    );

    if (openBtn) return openBtn.getAttribute('data-wa-month') || null;

    if (window.WA_activeMonth) return window.WA_activeMonth;

    return null;
  }

  function readSachStateFromDom() {
    const row = document.querySelector('#statsSach tr[data-topkey]');
    if (!row) return null;

    const all = [...document.querySelectorAll('#statsSach tr[data-topkey]')];
    const open = all.find(tr => tr.nextElementSibling?.classList.contains('sach-detail'));
    return open ? (open.dataset.topkey || null) : null;
  }

  function readGruppenStateFromDom() {
    const all = [...document.querySelectorAll('#statsGruppen tr[data-grp]')];
    const open = all.find(tr => tr.nextElementSibling?.classList.contains('stats-details'));
    return open ? (open.dataset.grp || null) : null;
  }

  function captureState() {
    state.month = readMonthStateFromDom();
    state.sach = readSachStateFromDom();
    state.gruppe = readGruppenStateFromDom();
  }

  function restoreMonth() {
    if (!state.month) return;

    const btn = document.querySelector(
      `#waMonthAccordion button[data-wa-month="${CSS.escape(state.month)}"]`
    );
    if (!btn) return;

    if (!paneIsOpenForMonth(btn)) {
      btn.click();
      return;
    }

    if (window.WA_activeMonth !== state.month) {
      window.WA_activeMonth = state.month;
      window.WA_applyFilters?.();
    }
  }

  function restoreSach() {
    if (!state.sach) return;

    const row = document.querySelector(
      `#statsSach tr[data-topkey="${CSS.escape(state.sach)}"]`
    );
    if (!row) return;

    if (!row.nextElementSibling?.classList.contains('sach-detail')) {
      row.click();
    }
  }

  function restoreGruppe() {
    if (!state.gruppe) return;

    const row = document.querySelector(
      `#statsGruppen tr[data-grp="${CSS.escape(state.gruppe)}"]`
    );
    if (!row) return;

    if (!row.nextElementSibling?.classList.contains('stats-details')) {
      row.click();
    }
  }

  function restoreAll() {
    if (isRestoring) return;
    isRestoring = true;

    try {
      restoreMonth();

      // erst Gruppe, dann Sach-Detail
      // getrennte Bereiche, daher kein Problem
      restoreGruppe();
      restoreSach();
    } finally {
      setTimeout(() => {
        isRestoring = false;
      }, 0);
    }
  }

  function scheduleRestore() {
    clearTimeout(restoreTimer);
    restoreTimer = setTimeout(() => {
      restoreAll();
    }, 60);
  }

  function bindStateTracking() {
    document.addEventListener('click', (e) => {
      const monthBtn = e.target.closest('#waMonthAccordion button[data-wa-month]');
      const sachRow = e.target.closest('#statsSach tr[data-topkey]');
      const grpRow = e.target.closest('#statsGruppen tr[data-grp]');

      if (monthBtn || sachRow || grpRow) {
        setTimeout(() => {
          captureState();
        }, 0);
      }
    });

    const monthAccordion = $(SELECTORS.monthAccordion);
    if (monthAccordion) {
      monthAccordion.addEventListener('shown.bs.collapse', () => {
        captureState();
      });

      monthAccordion.addEventListener('hidden.bs.collapse', () => {
        setTimeout(() => {
          captureState();
        }, 0);
      });
    }
  }

  function observeContainer(selector) {
    const el = $(selector);
    if (!el) return;

    const mo = new MutationObserver((mutations) => {
      if (isRestoring) return;

      const hasRelevantChange = mutations.some(m =>
        m.type === 'childList' &&
        (m.addedNodes.length > 0 || m.removedNodes.length > 0)
      );

      if (!hasRelevantChange) return;

      scheduleRestore();
    });

    mo.observe(el, {
      childList: true,
      subtree: true
    });
  }

  function patchGlobals() {
    if (typeof window.WA_applyFilters === 'function' && !window.WA_applyFilters.__uiStateWrapped) {
      const original = window.WA_applyFilters;
      const wrapped = function (...args) {
        captureState();
        const result = original.apply(this, args);
        scheduleRestore();
        return result;
      };
      wrapped.__uiStateWrapped = true;
      window.WA_applyFilters = wrapped;
    }

    if (typeof window.WA_computeStats === 'function' && !window.WA_computeStats.__uiStateWrapped) {
      const original = window.WA_computeStats;
      const wrapped = function (...args) {
        captureState();
        const result = original.apply(this, args);
        scheduleRestore();
        return result;
      };
      wrapped.__uiStateWrapped = true;
      window.WA_computeStats = wrapped;
    }

    if (typeof window.WA_rebuildMonthAccordion === 'function' && !window.WA_rebuildMonthAccordion.__uiStateWrapped) {
      const original = window.WA_rebuildMonthAccordion;
      const wrapped = function (...args) {
        captureState();
        const result = original.apply(this, args);
        scheduleRestore();
        return result;
      };
      wrapped.__uiStateWrapped = true;
      window.WA_rebuildMonthAccordion = wrapped;
    }
  }

  function waitForWorkbenchFunctions(tries = 0) {
    const ready =
      typeof window.WA_applyFilters === 'function' &&
      typeof window.WA_computeStats === 'function' &&
      typeof window.WA_rebuildMonthAccordion === 'function';

    if (ready || tries > 80) {
      patchGlobals();
      captureState();
      return;
    }

    setTimeout(() => waitForWorkbenchFunctions(tries + 1), 100);
  }

  function init() {
    if (!document.querySelector('#ausgangTable')) return;

    captureState();
    bindStateTracking();

    observeContainer(SELECTORS.monthAccordion);
    observeContainer(SELECTORS.statsSach);
    observeContainer(SELECTORS.statsGruppen);

    waitForWorkbenchFunctions();

    // Fallback: falls andere Scripts Bereiche hart neu schreiben
    setTimeout(() => {
      captureState();
      scheduleRestore();
    }, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();