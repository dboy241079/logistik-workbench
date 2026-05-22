(() => {
  "use strict";

  // Nur auf Seiten laufen, wo der Kunden-Tab existiert
  const hasKundenUi = () =>
    !!document.querySelector("#tabCust") || !!document.querySelector("#btnNewCust");

  if (!hasKundenUi()) return;

  const NEED = [
    "#btnNewCust",
    "#custModal",
    "#custForm",
    "#custCode",
    "#custName",
    "#custAddr1",
    "#custPostal",
    "#custCity",
    "#custCountry"
  ];

  function missing() {
    return NEED.filter(sel => !document.querySelector(sel));
  }

  function bind() {
    if (window.__KUNDEN_BOUND__) return true;

    const miss = missing();
    if (miss.length) {
      console.warn("kunden.js: UI noch nicht da, fehlen:", miss);
      return false;
    }

    if (typeof bootstrap === "undefined") {
      console.warn("kunden.js: bootstrap ist noch nicht geladen (typeof bootstrap === undefined).");
      return false;
    }

    window.__KUNDEN_BOUND__ = true;

    const btn = document.querySelector("#btnNewCust");
    const modalEl = document.querySelector("#custModal");
    const form = document.querySelector("#custForm");

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    btn.addEventListener("click", (e) => {
      e.preventDefault();

      // optional: hidden field, falls vorhanden
      const orig = document.querySelector("#custOrigCode");
      if (orig) orig.value = "";

      form.reset?.();
      modal.show();

      setTimeout(() => {
        document.querySelector("#custCode")?.focus();
      }, 50);
    });

    console.log("kunden.js: bound ✅");
    return true;
  }

  // Module/Defer: DOM ist meist da, aber Workbench kann dynamisch nachladen → Observer als Backup
  function boot() {
    if (bind()) return;

    const obs = new MutationObserver(() => {
      if (bind()) obs.disconnect();
    });
    obs.observe(document.documentElement, { childList: true, subtree: true });

    setTimeout(() => obs.disconnect(), 15000);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
