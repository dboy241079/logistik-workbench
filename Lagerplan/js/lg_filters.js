// /LKW/Lagerplan/js/lg_filter.js
(() => {
  "use strict";

  // === Konfig ===
  const API_STAMM = "/api/stammdaten_api.php"; // Sachnummern -> Lagergruppe
  const DEFAULT_LGS = ["W1","X3","X3(B)","G9","B1","B1(T)","Sarajevo"];

  const SEL = {
    list: "#lgFilterList",
    all: "#btnLgAll",
    none: "#btnLgNone",
    export: "#btnLgExport",
    badge: "#lgActiveBadge",
    table: "#lgSummaryTable",
    total: "#lgSummaryTotal",
  };

  const $ = (sel) => document.querySelector(sel);

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, m => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[m]));
  }

  function num(v) {
    const n = parseInt(String(v ?? "0"), 10);
    return Number.isFinite(n) ? n : 0;
  }

  function getUsedSlots() {
    // nur belegte Slots
    return Array.from(document.querySelectorAll(".palette-slot[data-sach]"))
      .filter(el => (el.dataset.sach || "").trim() !== "");
  }

  // === Stammdaten: Sachnummer -> LG ===
  let sachToLg = new Map();

  async function loadSachToLgMap() {
    // Achtung: deine API liefert max 500 – reicht i.d.R. fürs Mapping
    const url = new URL(API_STAMM, location.origin);
    url.searchParams.set("type", "sachnummer");
    url.searchParams.set("action", "list");

    const res = await fetch(url, { cache: "no-store", credentials: "same-origin" });
    const j = await res.json().catch(() => ({}));
    if (!res.ok || !j.ok || !Array.isArray(j.items)) {
      console.warn("LG-Filter: Stammdaten konnten nicht geladen werden.", j);
      return;
    }

    const map = new Map();
    j.items.forEach(it => {
      const s = String(it.sachnummer || "").trim();
      const lg = String(it.lagergruppe || "").trim();
      if (s) map.set(s, lg || "");
    });
    sachToLg = map;
  }

  function getLgForSach(sach) {
    const s = String(sach || "").trim();
    return sachToLg.get(s) || "UNBEKANNT";
  }

  // === UI: Checkboxen ===
  function readSelectedLgs() {
    const wrap = $(SEL.list);
    if (!wrap) return new Set();
    const checks = wrap.querySelectorAll('input[type="checkbox"][data-lg]');
    const picked = new Set();
    checks.forEach(ch => { if (ch.checked) picked.add(ch.dataset.lg); });
    return picked;
  }

  function setAllChecks(state) {
    const wrap = $(SEL.list);
    if (!wrap) return;
    wrap.querySelectorAll('input[type="checkbox"][data-lg]').forEach(ch => {
      ch.checked = state;
    });
  }

  function updateBadge() {
    const badge = $(SEL.badge);
    if (!badge) return;

    const wrap = $(SEL.list);
    const all = wrap ? Array.from(wrap.querySelectorAll('input[type="checkbox"][data-lg]')) : [];
    const sel = readSelectedLgs();

    // Regel: "nichts ausgewählt" => gilt als "alle"
    if (sel.size === 0 || sel.size === all.length) {
      badge.textContent = "Aktiv: alle";
      badge.className = "badge text-bg-secondary align-self-start";
      return;
    }

    badge.textContent = `Aktiv: ${sel.size} LG`;
    badge.className = "badge text-bg-primary align-self-start";
  }

  function buildLgList(lgs) {
    const wrap = $(SEL.list);
    if (!wrap) return;

    wrap.innerHTML = lgs.map(lg => `
      <label class="d-flex align-items-center gap-2">
        <input class="form-check-input m-0" type="checkbox" data-lg="${esc(lg)}">
        <span class="small">${esc(lg)}</span>
      </label>
    `).join("");

    // default: alle angehakt
    setAllChecks(true);

    wrap.addEventListener("change", () => {
      updateBadge();
      renderSummary(); // live aktualisieren
    });

    updateBadge();
  }

  // === Auswertung ===
  function computeStats() {
    const slots = getUsedSlots();
    const byLg = new Map();     // lg -> { pallets, pieces, sachSet }
    const bySach = new Map();   // sach -> { lg, pallets, pieces }

    slots.forEach(slot => {
      const sach = String(slot.dataset.sach || "").trim();
      const ref = String(slot.dataset.ref || "").trim(); // optional
      const qty = Math.max(1, num(slot.dataset.menge || "1"));

      const lg = getLgForSach(sach);

      // pro LG
      if (!byLg.has(lg)) byLg.set(lg, { pallets: 0, pieces: 0, sachSet: new Set() });
      const g = byLg.get(lg);
      g.pallets += 1;
      g.pieces += qty;
      if (sach) g.sachSet.add(sach);

      // pro Sachnummer
      if (!bySach.has(sach)) bySach.set(sach, { sach, lg, pallets: 0, pieces: 0, refs: new Set() });
      const s = bySach.get(sach);
      s.pallets += 1;
      s.pieces += qty;
      if (ref) s.refs.add(ref);
    });

    return { byLg, bySach };
  }

  function selectedLgsOrAll(allLgs) {
    const picked = readSelectedLgs();
    // nichts gewählt => alle
    if (picked.size === 0) return new Set(allLgs);
    return picked;
  }

  function renderSummary() {
    const table = $(SEL.table);
    const tbody = table?.querySelector("tbody");
    if (!tbody) return;

    const { byLg } = computeStats();

    // LG-Order: bekannte zuerst, dann Rest
    const lgsAll = Array.from(new Set([...DEFAULT_LGS, ...byLg.keys()]));
    const known = lgsAll.filter(lg => DEFAULT_LGS.includes(lg));
    const rest  = lgsAll.filter(lg => !DEFAULT_LGS.includes(lg)).sort((a,b)=>a.localeCompare(b,"de"));
    const order = [...known, ...rest];

    const active = selectedLgsOrAll(order);

    let totPal = 0, totQty = 0, totSach = 0;
    let actPal = 0, actQty = 0, actSach = 0;

    tbody.innerHTML = "";

    order.forEach(lg => {
      const g = byLg.get(lg);
      const pallets = g ? g.pallets : 0;
      const pieces  = g ? g.pieces : 0;
      const sachCnt = g ? g.sachSet.size : 0;

      totPal += pallets; totQty += pieces; totSach += sachCnt;

      const isActive = active.has(lg);
      if (isActive) { actPal += pallets; actQty += pieces; actSach += sachCnt; }

      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td class="fw-semibold">${esc(lg)}</td>
        <td class="text-end">${pallets}</td>
        <td class="text-end">${pieces}</td>
        <td class="text-end">${sachCnt}</td>
      `;
      tbody.appendChild(tr);
    });

    const totalEl = $(SEL.total);
    if (totalEl) {
      totalEl.innerHTML =
        `<div><b>Gesamt:</b> Paletten ${totPal} · Stück ${totQty} · Sachnr ${totSach}</div>` +
        `<div class="text-muted">Gefiltert:</b> Paletten ${actPal} · Stück ${actQty} · Sachnr ${actSach}</div>`;
    }
  }

  // === Excel Export ===
  function exportExcel() {
    if (!window.XLSX) {
      alert("XLSX Library fehlt – Script-Tags prüfen.");
      return;
    }

    const { byLg, bySach } = computeStats();

    // LG order
    const lgsAll = Array.from(new Set([...DEFAULT_LGS, ...byLg.keys()]));
    const known = lgsAll.filter(lg => DEFAULT_LGS.includes(lg));
    const rest  = lgsAll.filter(lg => !DEFAULT_LGS.includes(lg)).sort((a,b)=>a.localeCompare(b,"de"));
    const order = [...known, ...rest];

    const active = selectedLgsOrAll(order);

    // Sheet 1: Summary
    const summary = order.map(lg => {
      const g = byLg.get(lg) || { pallets: 0, pieces: 0, sachSet: new Set() };
      return {
        Lagergruppe: lg,
        Paletten: g.pallets,
        Stueck: g.pieces,
        Sachnummern: g.sachSet.size
      };
    });

    // Sheet 2: Details pro Sachnummer (nur aktive LGs)
    const details = Array.from(bySach.values())
      .filter(x => active.has(x.lg))
      .sort((a,b) => String(a.lg).localeCompare(String(b.lg), "de") || String(a.sach).localeCompare(String(b.sach), "de"))
      .map(x => ({
        Sachnummer: x.sach,
        Lagergruppe: x.lg,
        Paletten: x.pallets,
        Stueck: x.pieces,
        Refs: x.refs.size
      }));

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(summary), "Summary");
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(details), "Sachnummern");

    const stamp = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `lagergruppen_export_${stamp}.xlsx`);
  }

  // === Boot ===
  async function boot() {
    // UI vorhanden?
    if (!$(SEL.list) || !$(SEL.export) || !$(SEL.table)) return;

    await loadSachToLgMap();

    // LGs aus Stammdaten + Defaults
    const lgsFromStamm = Array.from(new Set([...sachToLg.values()].filter(Boolean)));
    const lgs = Array.from(new Set([...DEFAULT_LGS, ...lgsFromStamm, "UNBEKANNT"]));

    // Liste bauen + Buttons binden
    buildLgList(lgs);

    $(SEL.all)?.addEventListener("click", () => { setAllChecks(true); updateBadge(); renderSummary(); });
    $(SEL.none)?.addEventListener("click", () => { setAllChecks(false); updateBadge(); renderSummary(); });
    $(SEL.export)?.addEventListener("click", exportExcel);

    // Erste Auswertung
    renderSummary();

    // Plan ändert sich dauernd -> regelmäßig neu berechnen (leicht, robust)
    // (damit nach Einlagern/Ausbuchen automatisch aktualisiert)
    setInterval(renderSummary, 2500);
  }

  document.addEventListener("DOMContentLoaded", boot);
})();
