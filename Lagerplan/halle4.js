console.log("✅ halle3.js geladen");
window.__halle3Loaded = true;
window.__CARTON_STATE__ = window.__CARTON_STATE__ || { inited: false };

const FLAG_LABELS = {
  VW_MISSING:   "VW fehlt",
  LOC_WRONG:    "Falscher Platz",
  VW_LOC_WRONG: "VW-Position abweichend",
  NEEDS_CHECK:  "Prüfen"
};


// ===============================
// REIHEN-NAMEN (nur Anzeige!)
// Key bleibt die echte Reihe (DB/JS), Value ist dein Zusatzname
// ===============================
const API_ROW_LABELS = "/LKW/Lagerplan/lager_row_labels_api.php";

// ===============================
// ROW LABELS (global + sicher)
// ===============================
window.__ROW_LABELS__ = window.__ROW_LABELS__ || {};   // <- persistiert
const ROW_LABELS = window.__ROW_LABELS__;              // <- lokale Referenz

// ===============================
// OVERLAY SLOT CONTEXT (robust)
// ===============================
function setOverlaySlotCtx(slotEl) {
  window.__overlaySlotEl = slotEl || null;

  // optional: für Debug
  window.__overlaySlotId = slotEl?.dataset?.slotId || "";
  window.__overlayRef    = slotEl?.dataset?.ref || "";
}


function rowDisplay(row) {
  const r = String(row ?? "").trim();
  if (!r) return "";
  const extra = ROW_LABELS[r];
  return extra ? `Reihe ${r} – ${extra}` : `Reihe ${r}`;
}

document.getElementById("editClose2")?.addEventListener("click", () => hideModal("editModal"));


const API_STAMM = "/LKW/api/stammdaten_api.php"; // liefert sachnummer + lagergruppe
const LG_OPTIONS = ["W1","X3","X3(B)","G9","B1","B1(T)","Sarajevo"];

let __LG_SELECTED__ = new Set();       // leer = alle
let __SACH_TO_LG__  = new Map();       // sachnummer -> lagergruppe

// function getSelectedLGs() {
  
//   return __LG_SELECTED__.size ? __LG_SELECTED__ : null;
// }

async function loadSachStammMap() {
  const url = new URL(API_STAMM, location.origin);
  url.searchParams.set("type", "sachnummer");
  url.searchParams.set("action", "list");
  url.searchParams.set("q", "");

  const res = await fetch(url.toString(), { credentials:"include", cache:"no-store" });
  const text = await res.text();

  let j;
  try { j = JSON.parse(text); }
  catch {
    console.error("❌ STAMM: kein JSON", res.status, text.slice(0,800));
    throw new Error(`stammdaten_api liefert kein JSON (HTTP ${res.status})`);
  }

  // API kann items/rows/data liefern -> alle akzeptieren
  const items =
    Array.isArray(j.items) ? j.items :
    Array.isArray(j.rows)  ? j.rows  :
    Array.isArray(j.data)  ? j.data  : null;

  if (!res.ok || j.ok !== true || !items) {
    console.error("❌ STAMM: bad response", res.status, j);
    throw new Error(j.msg || j.error || `stammdaten_list_failed (HTTP ${res.status})`);
  }

  __SACH_TO_LG__.clear();
  items.forEach(it => {
    const s  = String(it.sachnummer || it.sach || "").trim();
    const lg = String(it.lagergruppe || it.lg || "").trim();
    if (s) __SACH_TO_LG__.set(s, lg);
  });

  window.__SACH_TO_LG__ = __SACH_TO_LG__;
  console.log("✅ STAMM loaded:", __SACH_TO_LG__.size);
}


const LG_LIST = ["W1","X3","X3(B)","G9","B1","B1(T)","Sarajevo"];

function renderLgFilter() {
  const box = document.getElementById("lgFilterList");
  if (!box) return;

  box.innerHTML = "";

  LG_LIST.forEach((lg) => {
    const col = document.createElement("div");
    col.className = "col-6 col-xl-3"; // ✅ mobil: 2/Zeile, Desktop: 4/Zeile

    const id = "lg_" + String(lg).replace(/[^a-z0-9_()-]/gi, "_");

    col.innerHTML = `
      <div class="form-check m-0">
        <input class="form-check-input" type="checkbox" value="${lg}" id="${id}">
        <label class="form-check-label small fw-semibold" for="${id}">${lg}</label>
      </div>
    `;

    box.appendChild(col);
  });
}

function buildFlagMiniTipHtml(slotEl) {
  const t = String(slotEl?.dataset?.flagType || "").trim();
  if (!t) return "";

  const label = FLAG_LABELS[t] || t;
  const note  = String(slotEl.dataset.flagNote || "").trim();
  const er    = String(slotEl.dataset.flagExpRow || "").trim();
  const epRaw = String(slotEl.dataset.flagExpPlatz || "").trim();
  const ep    = epRaw ? String(parseInt(epRaw,10)).padStart(2,"0") : "";

  const soll = (er || ep) ? ` · Soll: ${er ? "R"+er : ""}${ep ? "/P"+ep : ""}` : "";
  const tip  = `${label}${soll}${note ? " · " + note : ""}`;

  return `
    <span class="flagTipWrap">
      <span class="flagMini">⚠</span>
      <span class="flagTip">${escapeHtml(tip)}</span>
    </span>
  `;
}


function renderLgSummaryFromPlan() {
  const box = document.getElementById("lgSummary");
  if (!box) return;

  const slots = [...document.querySelectorAll(".palette-slot[data-sach]")];
  const sel = getSelectedLGs();

  const sum = new Map(); // lg -> qty
  for (const s of slots) {
    const sach = String(s.dataset.sach || "").trim();
    if (!sach) continue;

    const lg = (__SACH_TO_LG__.get(sach) || "").trim() || "-";
    if (sel && !sel.has(lg)) continue;

    const qty = Math.max(1, parseInt(String(s.dataset.menge || "1"), 10) || 1);
    sum.set(lg, (sum.get(lg) || 0) + qty);
  }

  const html = [...sum.entries()]
    .sort((a,b)=> String(a[0]).localeCompare(String(b[0]), "de"))
    .map(([lg, qty]) => `<div>${lg}: <b>${qty}</b></div>`)
    .join("") || `<div class="text-muted">Keine Daten.</div>`;

  box.innerHTML = html;
}


let __LG_NONE__ = false; // ✅ "Keine" wirklich keine Treffer

function updateLgActiveBadge() {
  const badge = document.getElementById("lgActiveBadge");
  if (!badge) return;

  if (__LG_NONE__) {
    badge.textContent = `Aktiv: 0 LG`;
    badge.className = "badge text-bg-danger align-self-start";
    return;
  }

  if (!__LG_SELECTED__.size) {
    badge.textContent = `Aktiv: alle`;
    badge.className = "badge text-bg-secondary align-self-start";
    return;
  }

  badge.textContent = `Aktiv: ${__LG_SELECTED__.size} LG`;
  badge.className = "badge text-bg-primary align-self-start";
}

function getSelectedLGs() {
  if (__LG_NONE__) return new Set();              // none
  return __LG_SELECTED__.size ? __LG_SELECTED__ : null; // null = alle
}

function lgAllowedForSach(sach) {
  const sel = getSelectedLGs();
  if (sel === null) return true;      // alle
  if (sel.size === 0) return false;   // keine
  const lg = (__SACH_TO_LG__.get(sach) || "").trim();
  return sel.has(lg);
}

async function loadRowLabelsFromServer() {
  const h = window.currentHall || "H4";
  const z = window.currentZone || "W1";

  const url = new URL(API_ROW_LABELS, location.origin);
  url.searchParams.set("action", "list");
  url.searchParams.set("halle", h);
  url.searchParams.set("zone", z);

  const res = await fetch(url, { cache:"no-store", credentials:"same-origin" });
  const js  = await res.json().catch(() => ({}));

  if (!res.ok || !js.ok) throw new Error(js?.msg || "row_labels_list_failed");

  // reset + füllen
  Object.keys(ROW_LABELS).forEach(k => delete ROW_LABELS[k]);
  (js.items || []).forEach(it => {
    const rk = String(it.row_key || "").trim();
    const lb = String(it.label || "").trim();
    if (rk && lb) ROW_LABELS[rk] = lb;
  });

  refreshAllRowLabelsUI();
}

function refreshAllRowLabelsUI() {
  // Toolbars
  document.querySelectorAll(".row-toolbar[data-row]").forEach(tb => {
    const r = String(tb.dataset.row || "").trim();
    const titleEl = tb.querySelector(".fw-semibold");
    if (titleEl) titleEl.textContent = rowDisplay(r);
  });

  // Sidebar/Row-Header (falls du .lager-reihe nutzt)
  document.querySelectorAll(".lager-reihe[data-row]").forEach(el => {
    const r = String(el.dataset.row || "").trim();
    // je nachdem wie dein Element gebaut ist:
    // wenn du innen einen Text-DIV hast, nutze den
    const inner = el.querySelector(".px-2") || el;
    inner.textContent = rowDisplay(r);
  });
}

async function saveRowLabelToServer(row, label) {
  const fd = new FormData();
  fd.append("action", "upsert");
  fd.append("halle", window.currentHall || "H4");
  fd.append("zone",  window.currentZone || "W1");
  fd.append("row_key", String(row));
  fd.append("label", String(label || ""));

  const res = await fetch(API_ROW_LABELS, { method:"POST", body: fd, credentials:"same-origin" });
  const js = await res.json().catch(() => ({}));
  if (!res.ok || !js.ok) throw new Error(js?.msg || "row_labels_upsert_failed");
  return js;
}

async function deleteRowLabelOnServer(row) {
  const fd = new FormData();
  fd.append("action", "delete");
  fd.append("halle", window.currentHall || "H4");
  fd.append("zone",  window.currentZone || "W1");
  fd.append("row_key", String(row));

  const res = await fetch(API_ROW_LABELS, { method:"POST", body: fd, credentials:"same-origin" });
  const js = await res.json().catch(() => ({}));
  if (!res.ok || !js.ok) throw new Error(js?.msg || "row_labels_delete_failed");
  return js;
}


function initLgFilterUI() {
  const box = document.getElementById("lgFilterList");
  if (!box) return;

  // ✅ wichtig: row + cols
  box.classList.add("row", "g-1");
  box.innerHTML = "";

  const mkId = (lg) => "lg_" + String(lg).replace(/[^a-z0-9_()-]/gi, "_");

  LG_OPTIONS.forEach((lg) => {
    const col = document.createElement("div");
    col.className = "col-6 col-xl-3"; // ✅ 2/Zeile mobil, 4/Zeile desktop

    const id = mkId(lg);

    col.innerHTML = `
      <div class="form-check m-0">
        <input class="form-check-input lg-check" type="checkbox" value="${lg}" id="${id}" checked>
        <label class="form-check-label small fw-semibold" for="${id}">${lg}</label>
      </div>
    `;

    box.appendChild(col);
  });

  const readFromUI = () => {
    const checks = [...box.querySelectorAll(".lg-check")];
    const on = checks.filter(c => c.checked).map(c => c.value);

    if (on.length === 0) {
      __LG_NONE__ = true;
      __LG_SELECTED__ = new Set();
      return;
    }

    __LG_NONE__ = false;

    const allOn = on.length === LG_OPTIONS.length;
    __LG_SELECTED__ = allOn ? new Set() : new Set(on);
  };

  box.addEventListener("change", () => {
    readFromUI();
    afterPlanChange();
    renderLgSummaryFromPlan?.();
  });

  document.getElementById("btnLgAll")?.addEventListener("click", () => {
    box.querySelectorAll(".lg-check").forEach(c => c.checked = true);
    __LG_NONE__ = false;
    __LG_SELECTED__ = new Set(); // alle
    afterPlanChange();
    renderLgSummaryFromPlan?.();
  });

  document.getElementById("btnLgNone")?.addEventListener("click", () => {
    box.querySelectorAll(".lg-check").forEach(c => c.checked = false);
    __LG_NONE__ = true;
    __LG_SELECTED__ = new Set();
    afterPlanChange();

    renderLgSummaryFromPlan?.();
  });

  document.getElementById("btnLgExport")?.addEventListener("click", () => exportLgXlsxFromPlan());
   // initial
  readFromUI();
  afterPlanChange();
}

// function lgAllowedForSach(sach) {
//   const sel = getSelectedLGs();
//   if (!sel) return true; 
//   const lg = __SACH_TO_LG__.get(sach) || "";
//   return sel.has(lg);
// }

document.addEventListener("DOMContentLoaded", async () => {
  try {
    await loadRowLabelsFromServer();
  } catch (e) {
    console.warn("Row-Labels konnten nicht geladen werden:", e);
  }
});

document.addEventListener("DOMContentLoaded", () => {
  window.currentHall  = window.currentHall  || "H4";
  window.currentZone  = window.currentZone  || "W1";
  window.currentBatch = window.currentBatch || null;

  injectBlinkStyles();

  // Layout bauen
  Halle3RowsLayout.build({
    mountId: BLOCK_ID,
    zone: window.currentZone || "W1",
    bands: [
      { from: 121, to: 140, label: "Reihen 121–140" },   // ✅ NEU (steht oben)
      { from: 101, to: 120, label: "Reihen 101–120" },
      { from: 76,  to: 100, label: "Reihen 76–100"  },
      { from: 51,  to: 75,  label: "Reihen 51–75"   },
      { from: 26,  to: 50,  label: "Reihen 26–50"   },
      { from: 1,   to: 25,  label: "Reihen 1–25"    },
    ],
    placeMaxForRow
  });

  // ✅ Damit es NICHT leer wirkt: sofort ein paar Reihen rendern
  [1, 20, 26, 51, 76, 101, 121].forEach(ensureRowRendered);

  // Handler / UI einmal
  initDelegatedClicks();
  injectRowPrintButtons();
  bindRowRenameButtons();


  initAssignModalHandlers();
  initDeleteConfirmModal();
  initMoveModal();
  initCartonModal();
  initOutbookFlow();
  initHistoryOverlay();

  initSearchControls();
  initSearchAutocomplete();
  hideHitList();

  initManualEntry();

  // Daten laden einmal
  loadExistingSlots();
});

document.addEventListener("DOMContentLoaded", async () => {
  try {
    await loadSachStammMap();   // Sach->LG Map
    initLgFilterUI();           // UI bauen
    renderLgSummaryFromPlan();  // initiale Übersicht
  } catch (e) {
    console.error(e);
    alert("LG-Filter konnte nicht initialisiert werden: " + (e?.message || e));
  }
});


function exportLgXlsxFromPlan() {
  if (!window.XLSX) {
    alert("XLSX Library fehlt (Script-Tag prüfen).");
    return;
  }

  // alle Slots mit Sachnummer einsammeln (nicht auf nur #w1-block-16-19 begrenzen)
  const slots = [...document.querySelectorAll(".palette-slot[data-sach]")];

  const sel = getSelectedLGs();
  const map = new Map(); // key = sach|lg -> {sach, lg, qty}

  for (const s of slots) {
    const sach = String(s.dataset.sach || "").trim();
    if (!sach) continue;

    const lg = (__SACH_TO_LG__.get(sach) || "").trim() || "-";
    if (sel && !sel.has(lg)) continue;

    const qty = Math.max(1, parseInt(String(s.dataset.menge || "1"), 10) || 1);
    const key = sach + "|" + lg;

    const cur = map.get(key) || { Sachnummer: sach, Lagergruppe: lg, Stueckzahl: 0 };
    cur.Stueckzahl += qty;
    map.set(key, cur);
  }

  const rows = [...map.values()].sort((a,b) =>
    String(a.Lagergruppe).localeCompare(String(b.Lagergruppe), "de") ||
    String(a.Sachnummer).localeCompare(String(b.Sachnummer), "de")
  );

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.json_to_sheet(rows);
  XLSX.utils.book_append_sheet(wb, ws, "Sach_LG");
  XLSX.writeFile(wb, "lagerplan_sachnummern_lg_stueck.xlsx");
}


window.__editCtx = null;

let _overlaySlotEl = null; // welches Slot-Overlay gerade offen ist

const BLOCK_ID     = "w1-block-16-19";

// ===============================
// KONFIG: Reihen & Plätze
// ===============================
const ROW_FROM = 1;
const ROW_TO   = 140;

// Reihe 20: 1–25 | Reihen 16–19: 1–60 | Reihen 21–60: 1–100
function placeMaxForRow(row) {
  const r = parseInt(row, 10);
  if (!Number.isFinite(r)) return 40;

  if (r === 20) return 25;   // Sonderreihe

  if (r === 43) return 35;   // ✅ 35 Plätze * 4 Slots = 140 Slots

  return 40;                 // Standard: 40 Plätze = 160 Slots
}


function cssEscape(v) {
  try { return CSS.escape(String(v)); }
  catch { return String(v).replace(/[^a-zA-Z0-9_-]/g, "\\$&"); }
}

/* ---------------------------
   PLAN / KÄSTCHEN ERZEUGEN
---------------------------- */

function buildPlaetze() {
  const containers = document.querySelectorAll(`#${BLOCK_ID} .platz-container`);

  containers.forEach((container) => {
    container.innerHTML = "";

    const row   = container.dataset.row;
    const start = parseInt(container.dataset.rangeStart, 10);
    const end   = parseInt(container.dataset.rangeEnd, 10);
    if (!row || isNaN(start) || isNaN(end)) return;

    const cap = slotCapacityForRow(row); // ✅ 20 => 20 Slots, sonst 4

    for (let p = start; p <= end; p++) {
      const platz = document.createElement("div");
      platz.className = "platz";
      platz.dataset.row = row;
      platz.dataset.platz = String(p).padStart(2, "0");

      const grid = document.createElement("div");
      grid.className = "platz-grid";

      for (let i = 0; i < cap; i++) {
        const slot = document.createElement("div");
        slot.className = "palette-slot";
        slot.dataset.slotIndex = String(i);

        if (row === "20") slot.dataset.slotLabel = slotTitleForRow(row, i);

        grid.appendChild(slot);
      }

      const label = document.createElement("div");
      label.className = "platz-label";
      label.textContent = `R${row} / P${String(p).padStart(2, "0")}\n0/${cap} belegt`;

      platz.appendChild(grid);
      platz.appendChild(label);
      container.appendChild(platz);
    }
  });
}

function initRowClicks() {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".lager-reihe").forEach((el) => {
    el.addEventListener("click", () => highlightRow(el));
  });
}

function highlightRow(activeEl) {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".lager-reihe").forEach((el) => el.classList.remove("ring-2", "ring-emerald-500"));
  activeEl.classList.add("ring-2", "ring-emerald-500");
}

function initPlatzClicks() {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".platz").forEach((platzEl) => {
    platzEl.addEventListener("click", (e) => {
      e.stopPropagation();
      highlightPlatz(platzEl);
      openPlatzOverlay(platzEl);
    });

    // Doppelklick -> Einlagern auf erstem freien Slot
    platzEl.addEventListener("dblclick", (e) => {
      e.stopPropagation();
      const free = Array.from(platzEl.querySelectorAll(".palette-slot")).find(s => !s.dataset.ref);
      if (free) openAssignModal(free);
      else {
        flashPlatz(platzEl, "error");
        setStatus("Dieser Platz ist voll.", "error");
      }
    });
  });

  // Klick außerhalb -> Overlay zu
  document.addEventListener("click", (e) => {
    const infoDiv = document.getElementById("lager-info");
    const insideInfo  = infoDiv && infoDiv.contains(e.target);
    const insideModal =
  document.getElementById("assignModal")?.contains(e.target) ||
  document.getElementById("confirmDeleteModal")?.contains(e.target) ||
  document.getElementById("moveModal")?.contains(e.target) ||
  document.getElementById("cartonModal")?.contains(e.target) ||
  document.getElementById("outbookModal")?.contains(e.target);


    if (!insideInfo && !insideModal) hideInfoBubble();
  });
}


function highlightPlatz(activeEl) {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".platz").forEach((el) => el.classList.remove("ring-2", "ring-blue-500"));
  activeEl.classList.add("ring-2", "ring-blue-500");
}

/* ---------------------------
   SLOT CLICKS
---------------------------- */

function initSlotClicks() {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".palette-slot").forEach(slot => {
    slot.addEventListener("click", (e) => {
      e.stopPropagation();
      if (slot.dataset.ref) openSlotOverlay(slot);
      else openAssignModal(slot);
    });
  });
}

/* ---------------------------
   MODAL (FREI -> EINLAGERN)
---------------------------- */

let _assignTargetSlot = null;

function openAssignModal(slotEl) {
  _assignTargetSlot = slotEl;

  const platzEl = slotEl.closest(".platz");
  const row     = platzEl?.dataset.row   || "";
  const platz   = platzEl?.dataset.platz || "";
  const idx     = slotEl.dataset.slotIndex || "";

  const modal  = document.getElementById("assignModal");
  const amRow  = document.getElementById("amRow");
  const amPlz  = document.getElementById("amPlatz");
  const amSlot = document.getElementById("amSlot");
  const amRef  = document.getElementById("amRef");
  const amSach = document.getElementById("amSach");
  const amQty  = document.getElementById("amQty"); // ✅ NEU

  if (!modal || !amRow || !amPlz || !amSlot || !amRef || !amSach) return;

  amRow.value  = row;
  amPlz.value  = platz;
  amSlot.value = idx;

  amRef.value  = "";
  amSach.value = "";

  // ✅ Default Menge
  if (amQty) amQty.value = "1";

  if (window.attachSachnummerAC) window.attachSachnummerAC(amSach);

  modal.classList.remove("hidden");
  modal.classList.add("flex");
  setTimeout(() => amRef.focus(), 30);
}


function closeAssignModal() {
  const modal = document.getElementById("assignModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  _assignTargetSlot = null;
}

function initAssignModalHandlers() {
  const modal  = document.getElementById("assignModal");
  const btnX   = document.getElementById("assignClose");
  const btnC   = document.getElementById("assignCancel");
  const btnS   = document.getElementById("assignSave");

  if (btnX) btnX.onclick = closeAssignModal;
  if (btnC) btnC.onclick = closeAssignModal;

  if (modal) {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeAssignModal();
    });
  }

  if (btnS) {
    btnS.onclick = async () => {
      if (!_assignTargetSlot) return;

      const amRef  = document.getElementById("amRef");
      const amSach = document.getElementById("amSach");
      const amQty  = document.getElementById("amQty"); // ✅ NEU

      const ref  = (amRef?.value || "").trim();
      const sach = (amSach?.value || "").trim();

      // ✅ Menge lesen
      const menge = Math.max(1, parseInt(String(amQty?.value || "1"), 10) || 1);

      if (!ref || !sach) {
        setStatus("Bitte Referenznummer und Sachnummer eingeben.", "error");
        return;
      }

      // schneller client-check
      const existing = document.querySelector(`#w1-block-16-19 .palette-slot[data-ref="${cssEscape(ref)}"]`);
      if (existing) {
        focusSlotElement(existing, "duplicate");
        setStatus(`Referenz ${ref} ist bereits eingelagert.`, "error");
        return;
      }

      const platzEl = _assignTargetSlot.closest(".platz");
      const row     = platzEl?.dataset.row   || "";
      const platzNr = platzEl?.dataset.platz || "";
      const slotIdx = parseInt(_assignTargetSlot.dataset.slotIndex || "0", 10);

      try {
        const data = await saveSlotToServer({
          halle: window.currentHall,
          zone: window.currentZone,
          batch_id: window.currentBatch?.id ? String(window.currentBatch.id) : "",
          reihe: row,
          platz: parseInt(platzNr, 10),
          slot_index: slotIdx,
          referenznr: ref,
          sachnummer: sach,
          menge: menge // ✅ NEU
        });

        if (!data.ok) {
          handleSaveError(data);
          return;
        }

        if (data?.id) _assignTargetSlot.dataset.slotId = String(data.id);

        // ✅ Menge ins UI schreiben
        applySlotToUI(_assignTargetSlot, {
  ref,
  sach,
  lieferschein: "",
  user: window.currentUserName || "",
  menge,
  verpackung // <-- neu
});

        updatePlatzLabel(platzEl);
        updateGlobalStatus();

        beepSuccess();
        setStatus(`Erfolgreich verbucht (Menge: ${menge}).`, "success");
        closeAssignModal();

      } catch (err) {
        console.error(err);
        setStatus("Speichern fehlgeschlagen (Server/Netzwerk).", "error");
      }
    };
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeAssignModal();
  });
}


/* ---------------------------
   MANUAL FLOW
   IDs erwartet:
   - btnToggleManual, manualBox, btnCancelManual
   - manualSach, manualLs, manualRef, manualRow, manualPlatz, btnManualSave
   (Wenn dir was davon fehlt: kurz in dein HTML rein)
---------------------------- */

function initManualEntry() {
  fillRowSelect("manualRow", 1, 140);
  // XLSX-Select direkt mitziehen
window.syncXlsxRowSelect?.();
setTimeout(() => window.syncXlsxRowSelect?.(), 200);


  const btnToggle = document.getElementById("btnToggleManual");
  const box       = document.getElementById("manualBox");
  const btnSave   = document.getElementById("btnManualSave");
  const btnCancel = document.getElementById("btnCancelManual");
  const pack = document.getElementById("manualPack");

  const sach = document.getElementById("manualSach");
  const ls   = document.getElementById("manualLs");
  const ref  = document.getElementById("manualRef");
  const row  = document.getElementById("manualRow");
  const plz  = document.getElementById("manualPlatz");

  // ✅ Stückzahl-Feld robust holen (ID muss idealerweise "manualQty" sein)
  const qty =
    document.getElementById("manualQty") ||
    document.querySelector('[name="manualQty"]') ||
    document.querySelector('[data-manual-qty]');

  if (!box) return;

  // Autocomplete immer aktivieren
  if (window.attachSachnummerAC && sach) window.attachSachnummerAC(sach);

  function applyPlatzLimits() {
    if (!plz) return;

    const max = placeMaxForRow(row?.value);

    plz.min = "1";
    plz.max = String(max);
    plz.placeholder = `1–${max}`;

    const v = parseInt(String(plz.value || "0"), 10);
    if (v && v > max) plz.value = String(max);
  }

  // initial
  applyPlatzLimits();

  row?.addEventListener("change", () => {
    applyPlatzLimits();
    highlightManualTarget(row.value, plz?.value);
  });

  plz?.addEventListener("input", () => {
    applyPlatzLimits();
    highlightManualTarget(row?.value, plz.value);
  });

  // Toggle: nur UI
  btnToggle?.addEventListener("click", () => {
    box.classList.toggle("d-none");
    if (!box.classList.contains("d-none")) {
      setTimeout(() => (ref || sach)?.focus(), 50);
      highlightManualTarget(row?.value, plz?.value);
    }
  });

  btnCancel?.addEventListener("click", () => box.classList.add("d-none"));

  // Enter = Save
  ref?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      btnSave?.click();
    }
  });

 // Save
btnSave?.addEventListener("click", async () => {
  const sachnummer   = (sach?.value || "").trim();
  const lieferschein = (ls?.value   || "").trim();
  const reihe        = (row?.value  || "").trim();
  const platzRaw     = String(plz?.value || "").trim();

  const referenznrRaw = (ref?.value || "").trim();
  const referenznr = referenznrRaw.replace(/\s+/g, "");

  // ✅ Verpackung (optional) - als STRING lassen (wegen führender Nullen)
  const verpackungRaw = (pack?.value || "").trim().toUpperCase();
  const verpackung = verpackungRaw !== "" ? verpackungRaw : null;

  // ✅ Erlaubte Verpackungen (Frontend-Validierung)
  const allowedPacks = new Set([
    "GT14488",
    "GT14491",
    "VW0012",
    "114003",
    "006280",
    "003147",
    "006147"
  ]);

  if (verpackung !== null && !allowedPacks.has(verpackung)) {
    setStatus("Ungültige Verpackung gewählt.", "error");
    return;
  }

  if (!/^\d{14}$/.test(referenznr)) {
    setStatus("Referenznummer muss exakt 14-stellig sein.", "error");
    soundError?.();
    ref?.focus?.();
    ref?.select?.();
    return;
  }

  const mengeRaw = String(qty?.value || "1").trim();
  const menge = Math.max(1, parseInt(mengeRaw, 10) || 1);

  // ✅ Pflichtfelder: Verpackung ist NICHT Pflicht
  if (!sachnummer || !lieferschein || !referenznr || !reihe || !platzRaw) {
    setStatus("Bitte Sachnummer, Lieferschein, Referenznummer, Reihe und Platz ausfüllen.", "error");
    return;
  }

  const dupClient = document.querySelector(
    `#w1-block-16-19 .palette-slot[data-ref="${cssEscape(referenznr)}"]`
  );

  if (dupClient) {
    focusSlotElement(dupClient, "duplicate");
    setStatus(`Referenz ${referenznr} ist bereits eingelagert.`, "error");
    return;
  }

  const platzNr = String(parseInt(platzRaw, 10) || "").padStart(2, "0");
  let platzEl = document.querySelector(
    `#w1-block-16-19 .platz[data-row="${reihe}"][data-platz="${platzNr}"]`
  );

  if (!platzEl) {
    setStatus(`Platz R${reihe} / P${platzNr} nicht gefunden.`, "error");
    return;
  }

  let free = getFirstFreeSlot(platzEl);

  if (!free) {
    blinkPlatzRed(platzEl);

    const suggestion = findNextFreePlatzInRow(reihe, parseInt(platzNr, 10) + 1);
    if (suggestion?.platzEl && suggestion?.slotEl) {
      plz.value = String(suggestion.platz).padStart(2, "0");
      highlightPlatz(suggestion.platzEl);
      suggestion.platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
      setStatus(
        `Platz voll → weiter auf R${reihe} / P${String(suggestion.platz).padStart(2, "0")}`,
        "error"
      );

      platzEl = suggestion.platzEl;
      free = suggestion.slotEl;
    } else {
      const cap = platzEl.querySelectorAll(".palette-slot").length;
      setStatus(
        `Dieser Platz ist voll (${cap}/${cap}) und kein weiterer freier Platz in der Reihe gefunden.`,
        "error"
      );
      return;
    }
  }

  try {
    const data = await saveSlotToServer({
      halle: window.currentHall,
      zone: window.currentZone,
      batch_id: "",
      reihe: reihe,
      platz: parseInt(platzEl.dataset.platz, 10),
      slot_index: parseInt(free.dataset.slotIndex, 10),
      referenznr: referenznr,
      sachnummer: sachnummer,
      lieferschein: lieferschein,
      verpackung: verpackung, // ✅ bleibt optional
      menge: menge
    });

    if (!data.ok) {
      handleSaveError(data);
      return;
    }

    applySlotToUI(free, {
      ref: referenznr,
      sach: sachnummer,
      lieferschein,
      user: window.currentUserName || "",
      menge,
      verpackung
    });

    updatePlatzLabel(platzEl);
    updateGlobalStatus();

    beepSuccess();
    setStatus(`Manuell erfolgreich eingelagert (Menge: ${menge}).`, "success");

    console.log("MANUAL SAVE", {
      sachnummer,
      lieferschein,
      referenznr,
      reihe,
      platzRaw,
      menge,
      verpackung,
      serverMode: data.mode || null
    });

    // Scanner-Flow: Ref leeren, Fokus drauf
    ref.value = "";
    ref.focus();

    const nextTarget = computeNextTargetAfterSave(reihe, parseInt(platzEl.dataset.platz, 10));
    if (nextTarget) {
      plz.value = String(nextTarget.platz).padStart(2, "0");
      highlightPlatz(nextTarget.platzEl);
      nextTarget.platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
    }

  } catch (err) {
    console.error(err);
    setStatus("Speichern fehlgeschlagen (Server/Netzwerk).", "error");
  }
});

  
}



function highlightManualTarget(r, p) {
  const reihe = String(r || "").trim();
  if (!reihe) return;

  // ✅ WICHTIG: Reihe bauen, falls noch leer (Lazy Render)
  if (typeof ensureRowRendered === "function") ensureRowRendered(reihe);

  const platzNr = String(p || "").trim().padStart(2, "0");
  if (!platzNr) return;

  const platzEl = document.querySelector(
    `#${BLOCK_ID} .platz[data-row="${cssEscape(reihe)}"][data-platz="${cssEscape(platzNr)}"]`
  );

  if (platzEl) {
    highlightPlatz(platzEl);
    platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
  }
}

function initMobileRowJump() {
  // ✅ ID ggf. anpassen, falls dein Select anders heißt
  const sel =
    document.getElementById("mobileRowSel") ||
    document.getElementById("mobileRow") ||
    document.querySelector("[data-mobile-row]");

  if (!sel) return;

  sel.addEventListener("change", () => {
  const r = String(sel.value || "").trim();
  if (!r) return;

  // ✅ nach dem UI-Open/Close (anderer Listener) springen
  requestAnimationFrame(() => {
    requestAnimationFrame(() => focusRow(r));
  });
});

}

function focusRow(row) {
  const r = String(row || "").trim();
  if (!r) return;

  // UI ruhig halten
  hideInfoBubble?.();
  hideHitList?.();
  clearHighlights?.();

  // ✅ Lazy render (wenn noch nicht gebaut)
  if (typeof ensureRowRendered === "function") ensureRowRendered(r);

  // Anchor suchen (Reihen-Header ODER Toolbar ODER Container)
  const anchor =
    document.querySelector(`#${BLOCK_ID} .lager-reihe[data-row="${cssEscape(r)}"]`) ||
    document.querySelector(`#${BLOCK_ID} .row-toolbar[data-row="${cssEscape(r)}"]`) ||
    document.querySelector(`#${BLOCK_ID} .platz-container[data-row="${cssEscape(r)}"]`) ||
    document.querySelector(`#${BLOCK_ID} .platz[data-row="${cssEscape(r)}"]`);


    console.log("focusRow ->", r, "anchor:", anchor); // ✅ HIER

  if (!anchor) {
    setStatus?.(`Reihe ${r} nicht gefunden.`, "error");
    return;
  }

  // ✅ Highlight der Reihe (wenn Header existiert)
  const rowEl =
    anchor.classList.contains("lager-reihe")
      ? anchor
      : document.querySelector(`#${BLOCK_ID} .lager-reihe[data-row="${cssEscape(r)}"]`);

  if (rowEl && typeof highlightRow === "function") highlightRow(rowEl);

  // ✅ Scrollen (Viewport-Container berücksichtigen)
  // ✅ erst nach Layout-Reflow scrollen
requestAnimationFrame(() => smartScrollTo(anchor));


  setStatus?.(`Reihe ${r} im Fokus.`, "info");
}

function smartScrollTo(el) {
  if (!el) return;

  // 1) Scrollbaren Container suchen (nicht blind #planViewport nehmen!)
  const isScrollable = (node) => {
    if (!node || node === document.body || node === document.documentElement) return false;
    const st = getComputedStyle(node);
    const canScrollY = /(auto|scroll)/.test(st.overflowY);
    return canScrollY && node.scrollHeight > node.clientHeight + 2;
  };

  // Kandidat: planViewport (aber nur wenn er wirklich scrollen kann)
  const vp = document.querySelector("#planViewport");
  if (isScrollable(vp)) {
    const pad = 10;
    const top =
      el.getBoundingClientRect().top -
      vp.getBoundingClientRect().top +
      vp.scrollTop -
      pad;

    vp.scrollTo({ top, behavior: "smooth" });
    return;
  }

  // 2) sonst: nächster scrollbarer Parent (falls vorhanden)
  let p = el.parentElement;
  while (p && p !== document.body) {
    if (isScrollable(p)) {
      const pad = 10;
      const top =
        el.getBoundingClientRect().top -
        p.getBoundingClientRect().top +
        p.scrollTop -
        pad;

      p.scrollTo({ top, behavior: "smooth" });
      return;
    }
    p = p.parentElement;
  }

  // 3) Fallback: Window scroll (das ist bei dir sehr wahrscheinlich richtig)
  const y = window.scrollY + el.getBoundingClientRect().top - 12;
  window.scrollTo({ top: y, behavior: "smooth" });
}



/* ---------------------------
   LOAD / SAVE / DELETE
---------------------------- */

function loadExistingSlots() {
  const batchId = window.currentBatch?.id ? parseInt(window.currentBatch.id, 10) : 0;

  const url =
    `lager_load.php?halle=${encodeURIComponent(window.currentHall)}` +
    `&zone=${encodeURIComponent(window.currentZone)}` +
    `&batch_id=${encodeURIComponent(batchId || 0)}`;

  console.log("LOAD url:", url); // ✅ das darf hier stehen

  fetchJson(url)
    .then(rows => {
      console.log("LOAD rows:", rows.length, rows[0]); // ✅ HIER rein!

      const rowsNeeded = new Set(rows.map(r => String(r.reihe)));
      rowsNeeded.forEach(r => ensureRowRendered(r));

      const touchedPlaces = new Set();

      rows.forEach(row => {
        const platzNr = String(row.platz).padStart(2, "0");
        const platzEl = document.querySelector(
          `#${BLOCK_ID} .platz[data-row="${row.reihe}"][data-platz="${platzNr}"]`
        );
        if (!platzEl) return;

        const slot = Array.from(platzEl.querySelectorAll(".palette-slot"))
          .find(s => s.dataset.slotIndex == row.slot_index);
        if (!slot) return;

        // ✅ Slot füllen (nicht vergessen!)
        slot.dataset.ref  = row.referenznr;
        slot.dataset.sach = row.sachnummer;
        slot.dataset.date = row.eingelagert_am || "";
        if (row.user_name) slot.dataset.userName = row.user_name;
        if (row.lieferschein) slot.dataset.lieferschein = row.lieferschein;
        if (row.id) {
  slot.dataset.slotId = String(row.id);
  slot.dataset.slotId = String(row.id); // bleibt
  slot.dataset.slotId = String(row.id); // ok
  slot.dataset.slotId = String(row.id); // (nur einmal natürlich)
  slot.setAttribute("data-slot-id", String(row.id)); // ✅ für CSS selector
}

        if (row.menge != null) slot.dataset.menge = String(row.menge);

        slot.classList.add("palette-slot-used");
        slot.textContent = String(row.referenznr || "").slice(-4);

        touchedPlaces.add(platzEl);
      });

      touchedPlaces.forEach(p => updatePlatzLabel(p));

// ✅ Corrections nachziehen → dann erst Index/Status
loadCorrectionsForPlan()
  .then(() => {
    // Labels ggf. nochmal (Menge korrigiert beeinflusst Label zwar nicht, aber safe)
    touchedPlaces.forEach(p => updatePlatzLabel(p));
  })
  .finally(() => {
    afterPlanChange(); // ✅ ersetzt rebuild+status+summary
  });

    })
    .catch(err => {
      console.error("Fehler beim Laden:", err);
      setStatus("Fehler beim Laden der Lagerdaten.", "error");
    });
}

async function loadCorrectionsForPlan() {
  // ✅ versucht Corrections für Hall/Zone/(optional Batch) zu laden
  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";
  const batchId = window.currentBatch?.id ? String(window.currentBatch.id) : "0";

  const urls = [
    `/LKW/Lagerplan/lager_corrections_api.php?action=list&halle=${encodeURIComponent(hall)}&zone=${encodeURIComponent(zone)}&batch_id=${encodeURIComponent(batchId)}`,
    `/LKW/Lagerplan/lager_corrections_api.php?action=list&halle=${encodeURIComponent(hall)}&zone=${encodeURIComponent(zone)}`,
    `/LKW/Lagerplan/lager_corrections_api.php?action=list`
  ];

  let js = null;
  for (const u of urls) {
    try {
      const res = await fetch(u, { credentials: "include", cache: "no-store" });
      const text = await res.text();
      const tmp = JSON.parse(text);

      if (res.ok && tmp && tmp.ok) { js = tmp; break; }
    } catch (_) {}
  }

  if (!js) {
    console.warn("Corrections: nichts geladen (Endpoint/Action evtl. anders).");
    return;
  }

  const items =
    Array.isArray(js.items) ? js.items :
    Array.isArray(js.rows)  ? js.rows  :
    Array.isArray(js.data)  ? js.data  : [];

  if (!items.length) return;

  let applied = 0;

  for (const it of items) {
    const slotId = String(it.slot_id ?? it.slotId ?? it.slotID ?? "").trim();
    if (!slotId) continue;

    const s = document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(slotId)}"]`);

    if (!s) continue;

    const sachK = String(it.sach_korr ?? it.sach ?? "").trim();
    const qtyK  = (it.qty_korr ?? it.menge_korr ?? it.qty ?? it.menge);

    // ✅ nur überschreiben wenn Werte vorhanden
    if (sachK) s.dataset.sach = sachK;

    if (qtyK != null) {
      const q = Math.max(1, parseInt(String(qtyK), 10) || 1);
      s.dataset.menge = String(q);
    }

    // optional: note merken
    const note = String(it.note ?? it.notiz ?? "").trim();
    if (note) s.dataset.corrNote = note;

    applied++;
  }

  if (applied) {
    console.log(`✅ Corrections angewendet: ${applied}`);
  }
}



async function fetchJson(url, opts) {
  const res = await fetch(url, opts);
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error("❌ Server liefert kein JSON:", url, text);
    throw e;
  }
}

function handleSaveError(data) {
  // Duplicate Ref -> scroll + blink
  if (data?.error === "duplicate_ref" && data?.existing) {
  const ex = data.existing;
  const platzNr = String(ex.platz).padStart(2, "0");
  const platzEl = document.querySelector(
    `#w1-block-16-19 .platz[data-row="${ex.reihe}"][data-platz="${platzNr}"]`
  );

  if (platzEl) {
    highlightPlatz(platzEl);
    platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
    blinkPlatzRed(platzEl);

    const slotEl = Array.from(platzEl.querySelectorAll(".palette-slot"))
      .find(s => s.dataset.slotIndex == ex.slot_index);

    if (slotEl) {
      slotEl.classList.add("ring-2", "ring-red-500");
      setTimeout(() => slotEl.classList.remove("ring-2", "ring-red-500"), 900);

      // ✅ Overlay direkt öffnen
      openSlotOverlay(slotEl);
    }
  }

  beepError(); // ✅ Sound
  setStatus(data.msg || "Referenz ist bereits eingelagert.", "error");
  return;
}

  setStatus(data?.msg || "Fehler beim Speichern.", "error");
}

function getFirstFreeSlot(platzEl) {
  return Array.from(platzEl.querySelectorAll(".palette-slot")).find(s => !s.dataset.ref) || null;
}

/* Nächsten freien Platz in der gleichen Reihe finden (ab startPlatz) */
function findNextFreePlatzInRow(row, startPlatz) {
  const plaetze = Array.from(document.querySelectorAll(`#w1-block-16-19 .platz[data-row="${row}"]`))
    .sort((a,b) => parseInt(a.dataset.platz,10) - parseInt(b.dataset.platz,10));

  for (const pEl of plaetze) {
    const p = parseInt(pEl.dataset.platz, 10);
    if (p < startPlatz) continue;

    const free = getFirstFreeSlot(pEl);
    if (free) return { platzEl: pEl, slotEl: free, platz: p };
  }
  return null;
}

/* Nach Save: wenn aktueller Platz noch Slots frei -> bleibt, sonst nächster Platz */
function computeNextTargetAfterSave(row, currentPlatz) {
  const currentEl = document.querySelector(`#w1-block-16-19 .platz[data-row="${row}"][data-platz="${String(currentPlatz).padStart(2,"0")}"]`);
  if (currentEl && getFirstFreeSlot(currentEl)) {
    return { platzEl: currentEl, platz: currentPlatz };
  }
  const next = findNextFreePlatzInRow(row, currentPlatz + 1);
  return next ? { platzEl: next.platzEl, platz: next.platz } : null;
}

/* ---------------------------
   LABEL UPDATE
---------------------------- */

function updatePlatzLabel(platzEl) {
  if (!platzEl) return;

  const row     = platzEl.dataset.row || "?";
  const platzNr = platzEl.dataset.platz || "?";
  const slots   = platzEl.querySelectorAll(".palette-slot");
  const used    = Array.from(slots).filter(s => s.dataset.ref);
  const cap     = slots.length;

  const label = platzEl.querySelector(".platz-label");
  if (!label) return;

  label.textContent =
    `R${row} / P${platzNr}\n` +
    `${used.length}/${cap} ${used.length === cap ? "· VOLL" : "belegt"}`;
}


/* ---------------------------
   STATUS
---------------------------- */

function updateGlobalStatus() {
  const ist = document.querySelectorAll('#w1-block-16-19 .palette-slot[data-ref]').length;
  setStatus(`Eingelagert: ${ist} Paletten`, "info");
  window.Flagging?.scheduleRefresh?.();
}



function setStatus(message, type = "info") {
  const statusDiv = document.getElementById("lager-status");
  if (!statusDiv) return;

  let base = "mb-2 inline-flex items-center gap-2 rounded px-2 py-1 text-[11px] border ";
  let icon = "ℹ";

  if (type === "success") { base += "bg-emerald-50 text-emerald-700 border-emerald-300"; icon = "✔"; }
  else if (type === "error") { base += "bg-red-50 text-red-700 border-red-300"; icon = "⚠"; }
  else { base += "bg-slate-50 text-slate-700 border-slate-300"; }

  statusDiv.className = base;
  statusDiv.innerHTML = `<span>${icon}</span><span>${escapeHtml(message)}</span>`;
}

/* ---------------------------
   OVERLAY (BELEGT: DETAILS)
---------------------------- */
function openSlotOverlay(slot) {
    // ✅ Kontext immer setzen
  setOverlaySlotCtx(slot);

  // ✅ zusätzlich Kontext am #lager-info ablegen (Fallback für Edit-Button)
  const infoDiv0 = document.getElementById("lager-info");
  if (infoDiv0) {
    infoDiv0.dataset.slotId = slot?.dataset?.slotId || "";
    infoDiv0.dataset.ref    = slot?.dataset?.ref || "";
  }

  const platzEl = slot.closest(".platz");
  if (!platzEl) return;

    // ✅ Kontext für Edit / Bearbeiten immer setzen
  window.__overlaySlotId = String(slot?.dataset?.slotId || "");
  window.__overlayRef    = String(slot?.dataset?.ref || "");


  _overlaySlotEl = slot;               // ✅ merken
  window.__overlaySlotEl = slot;       // ✅ für Delegation

  const row     = platzEl?.dataset.row || "?";
  const platzNr = platzEl?.dataset.platz || "?";

  const ref   = slot.dataset.ref || "-";
  const sach  = slot.dataset.sach || "-";
  const datum = slot.dataset.date || "-";
  const user  = slot.dataset.userName || "-";
  const ls    = slot.dataset.lieferschein || "-";
  const menge = slot.dataset.menge || "-";

  const idx      = parseInt(slot.dataset.slotIndex || "0", 10);
  const slotName = slotTitleForRow(row, idx);

  const itemCount = slot.dataset.itemsCount || "0";

  let html = "";
  html += `<div class="font-semibold mb-1">Slot-Details</div>`;
  html += `<div class="mb-1 text-xs text-slate-600">
    Ref: <strong>${escapeHtml(ref)}</strong><br>
    Sach: <strong>${escapeHtml(sach)}</strong><br>
    LS: <strong>${escapeHtml(ls)}</strong><br>
    Menge: <strong>${escapeHtml(menge)}</strong><br>
    Position: <strong>R${escapeHtml(row)} / P${escapeHtml(platzNr)} / ${escapeHtml(slotName)}</strong><br>
    Datum: <strong>${escapeHtml(datum)}</strong><br>
    User: <strong>${escapeHtml(user)}</strong>
  </div>`;

  html += `<div class="mt-2 flex gap-2">
    <button type="button" id="btnCartons"
      class="bg-slate-800 text-white text-xs font-semibold px-3 py-1 rounded">
      Kartons scannen (${escapeHtml(itemCount)})
    </button>
    <button type="button" id="btnEditSlot"
      class="bg-indigo-600 text-white text-xs font-semibold px-3 py-1 rounded">
      Bearbeiten
    </button>
    <button type="button" id="btnFlagSlot"
      class="bg-amber-600 text-white text-xs font-semibold px-3 py-1 rounded">
      Abweichung
    </button>
  </div>`;

  // ✅ WICHTIG: NUR showLagerInfo setzt HTML (sonst killt es Listener)
  showLagerInfo(platzEl, html);

  // ✅ Listener NACH showLagerInfo binden
  const infoDiv = document.getElementById("lager-info");
  infoDiv?.querySelector("#btnCartons")?.addEventListener("click", () => openCartonModal(slot));
  infoDiv?.querySelector("#btnFlagSlot")?.addEventListener("click", () => window.Flagging?.openFlagModal(slot));
  infoDiv?.querySelector("#btnEditSlot")?.addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();

  const p = slot.closest(".platz");
  const row = p?.dataset.row || "";
  const platz = parseInt(p?.dataset.platz || "0", 10) || 0;
  const slotIndex = parseInt(slot.dataset.slotIndex || "0", 10) || 0;

  const slotId = String(slot.dataset.slotId || "").trim();
  if (!slotId) {
    setStatus?.("Slot-ID fehlt. Bitte Seite neu laden und Slot erneut öffnen.", "error");
    soundError?.();
    return;
  }

  openEditModal({
    row,
    platz,
    slot_index: slotIndex,
    ref: slot.dataset.ref || "",
    sach: slot.dataset.sach || "",
    qty: parseInt(slot.dataset.menge || "1", 10) || 1,
    note: "",
    slotId
  });

  // wichtig: Save/Close-Handler sicher binden
  bindEditModalHandlers?.();
});


  // Edit-Button wird bei dir delegated gebunden -> passt so.

  // ✅ Carton-Count nachladen, damit (0) korrekt wird
  if (slot.dataset.slotId) {
    ensureCartonStats(slot).catch(()=>{});
  }
}

function openPlatzOverlay(platzEl) {
  const infoDiv = document.getElementById("lager-info");
  if (!infoDiv || !platzEl) return;

  const row = platzEl.dataset.row || "?";
  const plz = platzEl.dataset.platz || "?";

  const slots = Array.from(platzEl.querySelectorAll(".palette-slot"));
  const usedCount = slots.filter(s => s.dataset.ref).length;
  const cap = slots.length;

  let html = "";
  html += `<div class="flex items-center justify-between mb-1">`;
  html += `  <div class="font-semibold">Platz-Info: R${escapeHtml(row)} / P${escapeHtml(plz)} (${usedCount}/${cap})</div>`;
  html += `  <div class="flex gap-2">`;
  html += `    <button id="btnMovePlace" class="bg-emerald-600 text-white text-[11px] font-semibold px-2 py-1 rounded">Platz umbuchen</button>`;
  html += `    <button id="platzInfoClose" class="text-slate-600 hover:text-slate-900 text-lg leading-none">×</button>`;
  html += `  </div>`;
  html += `</div>`;

  html += `<div class="space-y-1">`;
  slots.forEach((s) => {
    const slotIdx = parseInt(s.dataset.slotIndex || "0", 10);
    const slotName = slotTitleForRow(row, slotIdx);

    const ref  = s.dataset.ref  || "";
    const sach = s.dataset.sach || "";
    const date = s.dataset.date || "";
    const user = s.dataset.userName || "";

    if (!ref) {
      html += `
        <div class="text-xs border rounded px-2 py-1 bg-slate-50 flex items-center justify-between">
          <div><strong>${escapeHtml(slotName)}</strong>: frei</div>
          <div class="text-[11px] text-slate-500">Klick auf freien Slot → Einlagern</div>
        </div>`;
    } else {
      html += `
        <div class="text-xs border rounded px-2 py-1 bg-white">
          <div class="flex items-start justify-between gap-2">
            <div>
              <div><strong>${escapeHtml(slotName)}</strong>: <span class="font-semibold">${escapeHtml(ref)}</span></div>
              <div class="text-[11px] text-slate-600">
                Sach: ${escapeHtml(sach)}${date ? " · " + escapeHtml(date) : ""}${user ? " · " + escapeHtml(user) : ""}
              </div>
            </div>
            <div class="flex gap-1 items-start">
              ${buildFlagMiniTipHtml(s)}
              <button class="bg-red-600 text-white text-[11px] font-semibold px-2 py-1 rounded"
                      data-action="delete" data-slot="${slotIdx}">Löschen</button>
              <button class="bg-slate-700 text-white text-[11px] font-semibold px-2 py-1 rounded"
                      data-action="move" data-slot="${slotIdx}">Umbuchen</button>
            </div>
          </div>
        </div>`;
    }
  });
  html += `</div>`;

  // ✅ Nur hier HTML setzen (sonst killt showLagerInfo Listener)
  showLagerInfo(platzEl, html);

  // ✅ Listener NACH showLagerInfo binden
  const info = document.getElementById("lager-info");

  info?.querySelector("#btnMovePlace")?.addEventListener("click", () => openPlaceMoveModal(platzEl));
  info?.querySelector("#platzInfoClose")?.addEventListener("click", hideInfoBubble);

  info?.querySelectorAll("button[data-action]")?.forEach(btn => {
    btn.addEventListener("click", () => {
      const slotIdx = parseInt(btn.dataset.slot || "-1", 10);
      const action  = btn.dataset.action;

      const slotEl = slots.find(s => parseInt(s.dataset.slotIndex || "-1", 10) === slotIdx);
      if (!slotEl || !slotEl.dataset.ref) return;

      if (action === "delete") openConfirmDelete(slotEl, platzEl);
      if (action === "move")   openMoveModal(slotEl);
    });
  });
}



function ensureEditModalDom() {
  // Modal kommt aus halle3.php -> NICHT per JS neu bauen
  return;
}


function openEditModal(data) {
  ensureEditModalDom();

  const slotIndex0 =
    Number.isFinite(parseInt(data?.slot_index, 10)) ? parseInt(data.slot_index, 10) :
    Number.isFinite(parseInt(data?.slotIndex, 10)) ? parseInt(data.slotIndex, 10) :
    Number.isFinite(parseInt(data?.slot, 10)) ? (parseInt(data.slot, 10) - 1) : 0;

  const rowStr   = String(data?.row ?? "").trim();
  const platzNum = parseInt(String(data?.platz ?? "0"), 10) || 0;

  const refNow = String(data?.ref ?? "").trim();
  const slotId = data?.slotId ? String(data.slotId) : (data?.slot_id ? String(data.slot_id) : null);

  window.__editCtx = {
    batch_id: window.currentBatch?.id || null,
    row: rowStr,
    platz: platzNum,
    slot_index: slotIndex0,
    slot: slotIndex0 + 1,
    ref: refNow,
    ref_orig: refNow,
    slotId
  };

  // Labels + Inputs
  const refLbl = document.getElementById("emRefLbl");
  const posLbl = document.getElementById("emPosLbl");
  const emRef  = document.getElementById("emRef");
  const emSach = document.getElementById("emSach");
  const emQty  = document.getElementById("emQty");
  const emNote = document.getElementById("emNote");

  if (refLbl) refLbl.textContent = refNow || "-";
  if (posLbl) posLbl.textContent =
    `R${rowStr} / P${String(platzNum).padStart(2, "0")} / Slot ${slotIndex0 + 1}`;

  if (emRef) {
    emRef.value = refNow;
    setTimeout(() => emRef.focus(), 30);
  }

  if (emSach) emSach.value = String(data?.sach ?? "");
  if (emQty)  emQty.value  = String(parseInt(data?.qty ?? "1", 10) || 1);
  if (emNote) emNote.value = String(data?.note ?? "");

  showModal?.("editModal");
  hideInfoBubble?.();
}

/* =========================================================
   CARTON MODAL (Karton-Refs + Sachnummer + Menge + Lieferschein)
========================================================= */
let _slotItemsById = new Map();   // slotId -> [{id, ref, sach, menge, lieferschein}]
let _itemRefToSlot = new Map();   // kartonRef -> slotEl
let _cartonInitDone = false;
let _cartonSlotEl = null;

/* ---------- DOM erstellen ---------- */
function ensureCartonModalDom() {
  let modal = document.getElementById("cartonModal");
  if (modal) return modal;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="cartonModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[10000]">
      <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-slate-800 text-sm">Kartons scannen</div>
          <button id="cartonClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
        </div>

        <div class="text-xs text-slate-700 mb-2">
          Position: <strong id="cartonPos">-</strong><br>
          Fortschritt: <strong id="cartonProg">0</strong>
        </div>

        <div class="grid gap-2">
          <div>
            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Karton-Referenz</label>
            <div class="flex gap-2">
              <input id="cartonInput"
                     class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
                     placeholder="Karton-Ref scannen…" autocomplete="off">
              <button id="cartonAdd"
                      class="bg-emerald-600 text-white text-xs font-semibold px-3 py-2 rounded">OK</button>
            </div>
          </div>

          <!-- ✅ Neu: Lieferschein -->
          <div>
            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Lieferschein</label>
            <input id="cartonLs"
                   class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
                   placeholder="LS-Nr. eingeben/scannen…" autocomplete="off">
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-[11px] font-semibold text-slate-700 mb-1">Sachnummer</label>
              <input id="cartonSach"
                     class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
                     placeholder="z.B. 0Z1…" autocomplete="off">
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-slate-700 mb-1">Stückzahl</label>
              <input id="cartonQty" type="number" min="1" step="1"
                     class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
                     value="1">
            </div>
          </div>
        </div>

        <div class="mt-3 max-h-[35vh] overflow-auto text-xs" id="cartonList"></div>
      </div>
    </div>
  `);

  return document.getElementById("cartonModal");
}

/* ---------- Events binden ---------- */
function initCartonModal() {
  const modal = ensureCartonModalDom();

  const st = window.__CARTON_STATE__;
  if (st.inited) return;
  st.inited = true;

  const close = document.getElementById("cartonClose");
  const addBtn = document.getElementById("cartonAdd");
  const inp = document.getElementById("cartonInput");

  close?.addEventListener("click", closeCartonModal);
  modal?.addEventListener("click", (e) => { if (e.target === modal) closeCartonModal(); });

  addBtn?.addEventListener("click", () => addCartonFromInput());
  inp?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); addCartonFromInput(); }
    if (e.key === "Escape") { e.preventDefault(); closeCartonModal(); }
  });
}


function openCartonModal(slotEl) {
  const modal = ensureCartonModalDom();
  initCartonModal();

  _cartonSlotEl = slotEl;

  renderCartonHeader(slotEl);

  // Vorbefüllen aus Slot
  const lsInp   = document.getElementById("cartonLs");
  const sachInp = document.getElementById("cartonSach");
  const qtyInp  = document.getElementById("cartonQty");

  if (lsInp)   lsInp.value   = slotEl?.dataset.lieferschein || "";
  if (sachInp) sachInp.value = slotEl?.dataset.sach || "";
  if (qtyInp)  qtyInp.value  = "1";

  // AC aktivieren
  if (window.attachSachnummerAC && sachInp) window.attachSachnummerAC(sachInp);

  // Modal anzeigen
  modal.classList.remove("hidden");
  modal.classList.add("flex");

  // Liste laden
  loadCartonsForSlot(slotEl).catch(err => console.error("Cartons laden:", err));

  setTimeout(() => document.getElementById("cartonInput")?.focus(), 30);
}

/* ---------- Schließen ---------- */
function closeCartonModal() {
  const modal = document.getElementById("cartonModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  _cartonSlotEl = null;
}

/* ---------- Header (Position) ---------- */
function renderCartonHeader(slotEl) {
  const posEl = document.getElementById("cartonPos");
  if (!posEl) return;

  const p = slotEl.closest(".platz");
  const r = p?.dataset.row || "?";
  const plz = p?.dataset.platz || "??";
  const sHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);

  posEl.textContent = `R${r}-${plz}-${sHuman}`;
}

/* ---------- Liste laden ---------- */
async function loadCartonsForSlot(slotEl) {
  const slotId = parseInt(slotEl.dataset.slotId || "0", 10);
  if (!slotId) {
    setStatus("Kartons scannen geht erst, wenn der Slot gespeichert ist (Slot-ID fehlt).", "error");
    return;
  }

  // alte refs dieses Slots aus Index entfernen
  const old = _slotItemsById.get(slotId) || [];
  old.forEach(it => {
    if (_itemRefToSlot.get(it.ref) === slotEl) _itemRefToSlot.delete(it.ref);
  });

  const data = await fetchJson(`lager_item_list.php?slot_id=${encodeURIComponent(slotId)}`);
  if (!data.ok) throw new Error(data.msg || "Karton-Liste laden fehlgeschlagen.");

  // ✅ Erwartet: items[] hat referenznr, sachnummer, menge, lieferschein
  const items = (data.items || []).map(x => ({
    id: x.id,
    ref: String(x.referenznr || "").trim(),
    sach: String(x.sachnummer || "").trim(),
    menge: (typeof x.menge !== "undefined" && x.menge !== null) ? parseInt(x.menge, 10) : 1,
    lieferschein: String(x.lieferschein || "").trim()
  })).filter(it => it.ref);

  _slotItemsById.set(slotId, items);
  items.forEach(it => _itemRefToSlot.set(it.ref, slotEl));

  updateCartonUI(slotEl);
}

function updateCartonUI(slotEl){
  const slotId = parseInt(slotEl.dataset.slotId || "0", 10);
  const items = _slotItemsById.get(slotId) || [];
  const count = items.length;

  const qtySum = items.reduce((sum, it) => sum + (parseInt(it.menge || "1", 10) || 1), 0);

  slotEl.dataset.itemsCount = String(count);
  slotEl.dataset.itemsQty   = String(qtySum);

  document.getElementById("cartonProg").textContent = String(count); // oder qtySum, wenn du Stück willst

  const list = document.getElementById("cartonList");
  if(list){
    list.innerHTML = items.slice(-30).map(it => {
      const m = parseInt(it.menge || "1", 10) || 1;
      const s = (it.sach || it.sachnummer || "-");
      const l = (it.lieferschein || it.ls || "");
      const lsTxt = l ? ` · LS: ${escapeHtml(l)}` : "";
      return `<div class="border-b border-slate-100 py-1">
        ${escapeHtml(it.ref)}<br>
        <span class="text-[11px] text-slate-600">Menge: ${m} · Sach: ${escapeHtml(s)}${lsTxt}</span>
      </div>`;
    }).join("") || `<div class="text-slate-500 py-2">Noch keine Kartons gescannt.</div>`;
  }

  // ✅ falls Overlay gerade offen ist -> Button aktualisieren
  if (_overlaySlotEl === slotEl) updateCartonButtonInOverlay(slotEl);
}

/* ---------- Add (POST) ---------- */
async function addCartonFromInput() {
  const slotEl = _cartonSlotEl;
  const inp = document.getElementById("cartonInput");
  if (!slotEl || !inp) return;

  const ref = (inp.value || "").trim();
  if (!ref) return;

  const sachInp = document.getElementById("cartonSach");
  const qtyInp  = document.getElementById("cartonQty");
  const lsInp   = document.getElementById("cartonLs");      // ✅ NEU

  const sach = (sachInp?.value || slotEl.dataset.sach || "").trim();
  const menge = Math.max(1, parseInt(String(qtyInp?.value || "1"), 10) || 1);
  const lieferschein = (lsInp?.value || "").trim();          // ✅ NEU

  if (!sach) {
    soundError?.();
    setStatus("Bitte Sachnummer für den Karton angeben.", "error");
    return;
  }

  if (_itemRefToSlot.has(ref) || document.querySelector(`.palette-slot[data-ref="${cssEscape(ref)}"]`)) {
    soundError?.();
    setStatus(`Referenz ${ref} existiert bereits.`, "error");
    inp.value = "";
    inp.focus();
    return;
  }

  const slotId = parseInt(slotEl.dataset.slotId || "0", 10);
  if (!slotId) {
    soundError?.();
    setStatus("Karton speichern nicht möglich: Slot-ID fehlt.", "error");
    return;
  }

  const fd = new FormData();
  fd.append("slot_id", String(slotId));
  fd.append("referenznr", ref);
  fd.append("sachnummer", sach);
  fd.append("menge", String(menge));
  fd.append("lieferschein", lieferschein);                   // ✅ NEU

  const data = await fetchJson("lager_item_add.php", { method: "POST", body: fd });

  if (!data.ok) {
    handleSaveError?.(data);
    soundError?.();
    return;
  }

  const items = _slotItemsById.get(slotId) || [];
  items.push({ id: data.id, ref, sach, menge, lieferschein }); // ✅ NEU
  _slotItemsById.set(slotId, items);
  _itemRefToSlot.set(ref, slotEl);

  soundSuccess?.();
  setStatus("Karton gespeichert.", "success");

  inp.value = "";
  if (qtyInp) qtyInp.value = "1";
  inp.focus();

  updateCartonUI(slotEl);
}



function hideInfoBubble() {
  const infoDiv = document.getElementById("lager-info");
  if (infoDiv) {
    infoDiv.classList.add("hidden");
    infoDiv.innerHTML = "";
  }
}

let _audioCtx = null;

function beep(freq = 880, durationMs = 70, type = "sine", gain = 0.06) {
  try {
    _audioCtx = _audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    const ctx = _audioCtx;

    const o = ctx.createOscillator();
    const g = ctx.createGain();

    o.type = type;
    o.frequency.value = freq;
    g.gain.value = gain;

    o.connect(g);
    g.connect(ctx.destination);

    const now = ctx.currentTime;
    o.start(now);
    o.stop(now + durationMs / 1000);

    // kleines Fade-out (klickfrei)
    g.gain.setValueAtTime(gain, now);
    g.gain.exponentialRampToValueAtTime(0.0001, now + durationMs / 1000);
  } catch (_) {
    // falls AudioContext blockiert -> einfach still
  }
}

/* ---------------------------
   SEARCH
---------------------------- */

// ✅ Alias, damit alte Handler nicht crashen
function searchQuerySmart(q) {
  // searchQuery ist ggf. async -> Promise einfach laufen lassen
  Promise.resolve(searchQuery(q));
}


function initSearchControls() {
  const searchInput = document.getElementById("searchRefInput");
  const btnSearch   = document.getElementById("btnSearchRef");
  if (!searchInput || !btnSearch) return;

  btnSearch.addEventListener("click", () => searchQuerySmart(searchInput.value));
  searchInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") searchQuerySmart(searchInput.value);
  });
}

function clearHighlights() {
  const block = document.getElementById("w1-block-16-19");
  if (!block) return;

  block.querySelectorAll(".platz").forEach(el => el.classList.remove("ring-2", "ring-blue-500"));
  block.querySelectorAll(".palette-slot").forEach(el => el.classList.remove("ring-2", "ring-red-500"));
}

/* ---------------------------
   UI APPLY / ANIMATION
---------------------------- */

function applySlotToUI(slotEl, { ref, sach, lieferschein, user, menge, verpackung, reihe }) {
  const todayDE = new Date().toLocaleDateString("de-DE");

  slotEl.dataset.ref  = ref || "";
  slotEl.dataset.sach = sach || "";
  slotEl.dataset.date = todayDE;

  if (lieferschein) slotEl.dataset.lieferschein = lieferschein;
  if (user) slotEl.dataset.userName = user;

  // Menge
  if (menge != null) slotEl.dataset.menge = String(menge);

  // Verpackung (WICHTIG)
  slotEl.dataset.verpackung = (verpackung && String(verpackung).trim() !== "")
    ? String(verpackung).trim()
    : "UNBEKANNT";

  slotEl.classList.add("palette-slot-used");
  slotEl.textContent = (ref || "").slice(-4);

  const mTxt = slotEl.dataset.menge ? ` · Menge: ${slotEl.dataset.menge}` : "";
  const vTxt = slotEl.dataset.verpackung ? ` · Verpackung: ${slotEl.dataset.verpackung}` : "";
  slotEl.title = `${ref || ""} · ${sach || ""} · ${todayDE}${mTxt}${vTxt}`;

  // Reihe robust bestimmen
  const rowFromDom = slotEl.closest(".platz")?.dataset?.row || slotEl.dataset.row || "";
  const rowFinal = String(reihe || rowFromDom || "").trim();
  if (rowFinal) refreshRowPackInfo(rowFinal);
}


function blinkPlatzGreen(platzEl) {
  if (!platzEl) return;
  ensureSearchFxStyles(); // oder ensureBlinkStyles – je nachdem was du behalten willst
  platzEl.classList.remove("platz-blink-green");
  void platzEl.offsetWidth;
  platzEl.classList.add("platz-blink-green");
  setTimeout(() => platzEl.classList.remove("platz-blink-green"), 900);
}


function focusSlotElement(slotEl, mode) {
  const platzEl = slotEl.closest(".platz");
  if (platzEl) {
    highlightPlatz(platzEl);
    platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
    if (mode === "duplicate") blinkPlatzRed(platzEl);
    else blinkPlatzGreen(platzEl);
  }
}

function blinkPlatzRed(platzEl) {
  platzEl.classList.remove("platz-blink-red");
  void platzEl.offsetWidth;
  platzEl.classList.add("platz-blink-red");
  setTimeout(() => platzEl.classList.remove("platz-blink-red"), 900);
}

function injectBlinkStyles() {
  if (document.getElementById("blinkStyles")) return;

  const style = document.createElement("style");
  style.id = "blinkStyles";
  style.textContent = `
    @keyframes blinkGreen {
      0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.0); }
      30% { box-shadow: 0 0 0 6px rgba(16,185,129,0.55); }
      100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.0); }
    }
    @keyframes blinkRed {
      0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.0); }
      30% { box-shadow: 0 0 0 6px rgba(239,68,68,0.55); }
      100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.0); }
    }
    .platz-blink-green { animation: blinkGreen 0.9s ease-out; }
    .platz-blink-red   { animation: blinkRed 0.9s ease-out; }

    /* ✅ Nur Reihe 20: 6 Slots nebeneinander (statt Quadrat) */
    #w1-block-16-19 .platz[data-row="20"] .platz-grid{
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  grid-template-rows: repeat(4, minmax(0, 1fr));
  gap: 2px;
}

  `;
  document.head.appendChild(style);
}


/* ---------------------------
   HELPERS
---------------------------- */

function escapeHtml(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}



// ✅ Scan-Sound (Datei liegt in /LKW/Bilder/Sound/scann.mp3)
const SCAN_SOUND_URL = "/LKW/Bilder/Sound/scann.mp3";

const _sndOk  = new Audio(SCAN_SOUND_URL);
const _sndErr = new Audio(SCAN_SOUND_URL);
_sndOk.preload  = "auto";
_sndErr.preload = "auto";

function playScanSound(audio, { rate = 1.0, volume = 0.9 } = {}) {
  try {
    audio.pause();
    audio.currentTime = 0;
    audio.playbackRate = rate;
    audio.volume = volume;

    const p = audio.play();
    if (p && typeof p.catch === "function") p.catch(() => {});
  } catch (_) {}
}

// Erfolg: einmal “normal”
function soundSuccess() {
  playScanSound(_sndOk, { rate: 1.0, volume: 0.75 });
}

// Fehler: zweimal + etwas tiefer/langsamer (fällt sofort auf)
function soundError() {
  playScanSound(_sndErr, { rate: 0.85, volume: 0.9 });
  setTimeout(() => playScanSound(_sndErr, { rate: 0.85, volume: 0.9 }), 140);
}

// Optional: einmal “freischalten” nach erster Nutzeraktion (Autoplay-Policies)
document.addEventListener("pointerdown", () => {
  const p = _sndOk.play();
  if (p && typeof p.then === "function") {
    p.then(() => {
      _sndOk.pause();
      _sndOk.currentTime = 0;
    }).catch(() => {});
  }
}, { once: true });

// ---------------------------
// PLATZ-OVERLAY + DELETE/MOVE
// ---------------------------

let _pendingDeleteSlot = null;
let _pendingDeletePlatzEl = null;

let _moveSourceSlot = null;

// ============================
// PLATZ UMBUCHEN (alle Slots)
// ============================
let _pmInited = false;
let _pmSourcePlatzEl = null;

function ensurePlaceMoveModalDom() {
  if (document.getElementById("placeMoveModal")) return;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="placeMoveModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[10000]">
      <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-slate-800 text-sm">Platz umbuchen</div>
          <button id="pmClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
        </div>

        <div class="text-xs text-slate-700 mb-3">
          Von: <b id="pmFromLbl">-</b><br>
          Belegt: <b id="pmCountLbl">0</b> Paletten
        </div>

        <div class="grid gap-2 text-xs">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block font-semibold">Ziel-Reihe</label>
              <select id="pmRow" class="border border-slate-300 rounded px-2 py-1 w-full"></select>
            </div>
            <div>
              <label class="block font-semibold">Ziel-Platz</label>
              <input id="pmPlatz" type="number" min="1" max="120"
                     class="border border-slate-300 rounded px-2 py-1 w-full" placeholder="z.B. 28">
            </div>
          </div>

          <div class="text-[11px] text-slate-500" id="pmHint">
            Slots werden nacheinander auf freie Slots am Zielplatz gelegt.
          </div>

          <!-- Status / Prüfergebnis (DIV statt alert) -->
          <div id="pmInfo" class="text-[11px] mt-1 text-slate-600"></div>

          <!-- Buttons: Prüfen -> Umbuchen freischalten -->
          <div class="flex gap-2 mt-2 justify-end">
            <button id="pmCheck" class="bg-slate-800 text-white text-xs font-semibold px-3 py-1 rounded">
              Prüfen
            </button>
            <button id="pmAsk" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-1 rounded" disabled>
              Umbuchen
            </button>
            <button id="pmCancel" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">
              Abbrechen
            </button>
          </div>

          <!-- Confirm-Box (DIV statt confirm/alert) -->
          <div id="pmConfirm" class="hidden mt-3">
            <div class="border border-amber-300 bg-amber-50 rounded p-2 text-[11px]">
              <div class="font-semibold mb-1">Bestätigung</div>
              <div id="pmConfirmText"></div>
            </div>

            <div class="flex gap-2 mt-2 justify-end">
              <button id="pmNo" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">
                Nein
              </button>
              <button id="pmYes" class="bg-red-600 text-white text-xs font-semibold px-3 py-1 rounded">
                Ja, umbuchen
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>
  `);
}

// function showPmMsg(msg, type="info") {
//   const el = document.getElementById("pmMsg");
//   if (!el) return;
//   el.className = "text-[11px] mt-1 " + (
//     type === "error" ? "text-red-700" :
//     type === "success" ? "text-emerald-700" :
//     "text-slate-600"
//   );
//   el.textContent = msg || "";
// }

function openPlaceMoveModal(platzEl) {
  ensurePlaceMoveModalDom();
  initPlaceMoveModal();

  _pmSourcePlatzEl = platzEl;

  const row = platzEl?.dataset.row || "";
  const plz = platzEl?.dataset.platz || "";

  // Label + Count
  const usedSlots = Array.from(platzEl.querySelectorAll(".palette-slot")).filter(s => s.dataset.ref);
  document.getElementById("pmFromLbl").textContent = `R${row} / P${plz}`;
  document.getElementById("pmCountLbl").textContent = String(usedSlots.length);

  // Reihen füllen (nur wenn leer)
  const pmRow = document.getElementById("pmRow");
  if (pmRow && pmRow.options.length <= 1) {
    fillRowSelect("pmRow", ROW_FROM, ROW_TO);
  }

  // Prefill: gleiche Reihe, Platz +1 (wenn möglich)
  if (pmRow) pmRow.value = String(row);
  const pmPlatz = document.getElementById("pmPlatz");
  if (pmPlatz) {
    const n = parseInt(plz, 10) || 1;
    pmPlatz.value = String(Math.min(n + 1, placeMaxForRow(row)));
  }
  showModal("placeMoveModal");
}

function closePlaceMoveModal() {
  hideModal("placeMoveModal");
  _pmSourcePlatzEl = null;
}

let _pmPlan = null; // { from:{row,plz,platzEl,slots}, to:{row,plzNum,plzStr,platzEl}, need }

function setPmInfo(msg, type="info") {
  const el = document.getElementById("pmInfo");
  if (!el) return;
  el.className = "text-[11px] mt-2 " + (
    type === "error" ? "text-red-700" :
    type === "success" ? "text-emerald-700" :
    type === "warn" ? "text-amber-700" :
    "text-slate-600"
  );
  el.textContent = msg || "";
}

function setPmConfirm(show, text="") {
  const box = document.getElementById("pmConfirm");
  const txt = document.getElementById("pmConfirmText");
  if (txt) txt.textContent = text || "";
  if (!box) return;
  box.classList.toggle("hidden", !show);
}

function setBtn(id, enabled) {
  const b = document.getElementById(id);
  if (!b) return;
  b.disabled = !enabled;
}

function freeSlotCount(platzEl) {
  if (!platzEl) return 0;
  return Array.from(platzEl.querySelectorAll(".palette-slot"))
    .filter(s => !s.dataset.ref).length;
}

function getPlatzEl(row, platzStr2) {
  return document.querySelector(
    `#${BLOCK_ID} .platz[data-row="${cssEscape(row)}"][data-platz="${cssEscape(platzStr2)}"]`
  );
}

/** findet den nächsten Platz in DERSELben Reihe, der mind. need freie Slots hat */
function findNextPlaceWithCapacity(row, startPlatzNum, need) {
  // wichtig bei Lazy-Render:
  if (typeof ensureRowRendered === "function") ensureRowRendered(row);

  const plaetze = Array.from(document.querySelectorAll(`#${BLOCK_ID} .platz[data-row="${cssEscape(row)}"]`))
    .sort((a,b) => parseInt(a.dataset.platz,10) - parseInt(b.dataset.platz,10));

  for (const pEl of plaetze) {
    const pNum = parseInt(pEl.dataset.platz, 10);
    if (pNum < startPlatzNum) continue;
    if (freeSlotCount(pEl) >= need) return pEl;
  }
  return null;
}



function initPlaceMoveModal() {
  if (_pmInited) return;
  _pmInited = true;

  ensurePlaceMoveModalDom();

  const modal = document.getElementById("placeMoveModal");
  const btnClose  = document.getElementById("pmClose");
  const btnCancel = document.getElementById("pmCancel");
  const btnCheck  = document.getElementById("pmCheck");
  const btnAsk    = document.getElementById("pmAsk");
  const btnNo     = document.getElementById("pmNo");
  const btnYes    = document.getElementById("pmYes");

  const close = () => {
    setPmConfirm(false);
    setPmInfo("");
    setBtn("pmAsk", false);
    _pmPlan = null;
    closePlaceMoveModal();
  };

  btnClose?.addEventListener("click", close);
  btnCancel?.addEventListener("click", close);

  modal?.addEventListener("click", (e) => {
    if (e.target === modal) close();
  });

  btnNo?.addEventListener("click", () => setPmConfirm(false));

  btnCheck?.addEventListener("click", () => {
    try {
      setPmConfirm(false);
      setBtn("pmAsk", false);
      _pmPlan = null;

      const srcPlatzEl = _pmSourcePlatzEl;
      if (!srcPlatzEl) return;

      const fromRow = String(srcPlatzEl.dataset.row || "").trim();
      const fromPlz = String(srcPlatzEl.dataset.platz || "").trim();

      const toRow = String(document.getElementById("pmRow")?.value || "").trim();
      const toPlzRaw = String(document.getElementById("pmPlatz")?.value || "").trim();

      if (!toRow || !toPlzRaw) {
        setPmInfo("Bitte Ziel-Reihe und Ziel-Platz eingeben.", "error");
        return;
      }

      const toNum = parseInt(toPlzRaw, 10);
      const maxPlz = placeMaxForRow(toRow);
      if (!Number.isFinite(toNum) || toNum < 1 || toNum > maxPlz) {
        setPmInfo(`Ziel-Platz ungültig. Reihe ${toRow} erlaubt 1–${maxPlz}.`, "error");
        return;
      }

      const toPlz = String(toNum).padStart(2, "0");

      // Source Slots (belegt)
      const srcSlots = Array.from(srcPlatzEl.querySelectorAll(".palette-slot"))
        .filter(s => s.dataset.ref)
        .sort((a,b) => parseInt(a.dataset.slotIndex||"0",10) - parseInt(b.dataset.slotIndex||"0",10));

      if (!srcSlots.length) {
        setPmInfo("Quell-Platz ist leer.", "error");
        return;
      }

      // Zielplatz finden
      if (typeof ensureRowRendered === "function") ensureRowRendered(toRow);
      let targetPlatzEl = getPlatzEl(toRow, toPlz);

      if (!targetPlatzEl) {
        setPmInfo(`Zielplatz R${toRow} / P${toPlz} nicht gefunden (UI).`, "error");
        return;
      }

      const need = srcSlots.length;
      const free = freeSlotCount(targetPlatzEl);

      // wenn Ziel nicht reicht -> Vorschlag
      if (free < need) {
        const sugEl = findNextPlaceWithCapacity(toRow, toNum + 1, need);

        if (!sugEl) {
          setPmInfo(`Zielplatz hat zu wenig freie Slots (${free} frei, ${need} benötigt) und kein Folgeplatz gefunden.`, "error");
          return;
        }

        const sugPlzStr = String(sugEl.dataset.platz || "").padStart(2, "0");
        const sugPlzNum = parseInt(sugPlzStr, 10) || (toNum+1);

        // Vorschlag direkt eintragen
        const inp = document.getElementById("pmPlatz");
        if (inp) inp.value = String(sugPlzNum);

        targetPlatzEl = sugEl;

        setPmInfo(`Zielplatz voll/zu klein → Vorschlag gesetzt: R${toRow} / P${sugPlzStr} (hat genug freie Slots).`, "warn");
      } else {
        setPmInfo(`OK: Zielplatz R${toRow} / P${toPlz} hat genug freie Slots (${free} frei).`, "success");
      }

      const finalPlzStr = String(targetPlatzEl.dataset.platz || "").padStart(2, "0");
      const finalPlzNum = parseInt(finalPlzStr, 10) || toNum;

      _pmPlan = {
        from: { row: fromRow, plz: fromPlz, platzEl: srcPlatzEl, slots: srcSlots },
        to:   { row: toRow, plzNum: finalPlzNum, plzStr: finalPlzStr, platzEl: targetPlatzEl },
        need
      };

      setBtn("pmAsk", true);
    } catch (e) {
      console.error(e);
      setPmInfo(e?.message || "Prüfen fehlgeschlagen.", "error");
    }
  });

  btnAsk?.addEventListener("click", () => {
    if (!_pmPlan) {
      setPmInfo("Bitte erst prüfen.", "error");
      return;
    }

    const n = _pmPlan.need;
    const text = `Wirklich ${n} Paletten von R${_pmPlan.from.row}/P${_pmPlan.from.plz} nach R${_pmPlan.to.row}/P${_pmPlan.to.plzStr} umbuchen?`;
    setPmConfirm(true, text);
  });

  btnYes?.addEventListener("click", async () => {
    if (!_pmPlan) return;

    try {
      setPmConfirm(false);
      setBtn("pmAsk", false);
      setBtn("pmCheck", false);
      setPmInfo("Umbuchen läuft…", "info");

      const srcPlatzEl = _pmPlan.from.platzEl;
      const targetPlatzEl = _pmPlan.to.platzEl;

      for (let i = 0; i < _pmPlan.from.slots.length; i++) {
        const s = _pmPlan.from.slots[i];

        const id = parseInt(s.dataset.slotId || "0", 10);
        if (!id) throw new Error("Slot-ID fehlt (bitte neu laden).");

        const ref  = s.dataset.ref || "";
        const sach = s.dataset.sach || "";
        const ls   = s.dataset.lieferschein || "";
        const user = s.dataset.userName || "";
        const date = s.dataset.date || new Date().toLocaleDateString("de-DE");
        const menge= s.dataset.menge ? parseInt(s.dataset.menge, 10) : 1;

        const moved = await moveSlotOnServer({
          halle: window.currentHall || "H4",
          zone:  window.currentZone || "W1",
          id,
          to_row: _pmPlan.to.row,
          to_platz: _pmPlan.to.plzNum
        });

        const newIdx = String(moved?.to?.slot_index ?? "");
        const targetSlotEl = targetPlatzEl.querySelector(`.palette-slot[data-slot-index="${cssEscape(newIdx)}"]`);
        if (!targetSlotEl) throw new Error("Ziel-Slot im UI nicht gefunden.");

        resetSlotUI(s);

        applySlotToUI(targetSlotEl, { ref, sach, lieferschein: ls, user, menge });
        targetSlotEl.dataset.slotId = String(id);
        targetSlotEl.dataset.date = date;

        setPmInfo(`Umbuche… (${i+1}/${_pmPlan.need})`, "info");
      }

      updatePlatzLabel(srcPlatzEl);
      updatePlatzLabel(targetPlatzEl);
      rebuildSearchIndex?.();
      afterPlanChange();


      closePlaceMoveModal();

      highlightPlatz?.(targetPlatzEl);
      targetPlatzEl.scrollIntoView({ behavior: "smooth", block: "center" });
      flashPlatz?.(targetPlatzEl, "success");
      openPlatzOverlay?.(targetPlatzEl);

      setStatus?.(
        `Platz umbuchen fertig: R${_pmPlan.from.row}/P${_pmPlan.from.plz} → R${_pmPlan.to.row}/P${_pmPlan.to.plzStr}`,
        "success"
      );
      soundSuccess?.();
    } catch (e) {
      console.error(e);
      setPmInfo(e?.message || "Umbuchen fehlgeschlagen.", "error");
      soundError?.();
      setBtn("pmAsk", true);
    } finally {
      setBtn("pmCheck", true);
    }
  });
}

// ===============================
// XLSX-RowSelect: übernimmt Optionen aus manualRow -> xlsxRowSel
// ===============================
function syncXlsxRowSelect() {
  const sel = document.getElementById("xlsxRowSel");
  const src = document.getElementById("manualRow");
  if (!sel || !src) return;

  sel.innerHTML = "";
  for (let i = 0; i < src.options.length; i++) {
    sel.appendChild(src.options[i].cloneNode(true));
  }
}
window.syncXlsxRowSelect = syncXlsxRowSelect;



function openConfirmDelete(slotEl, platzEl) {
  _pendingDeleteSlot = slotEl;
  _pendingDeletePlatzEl = platzEl;

  const modal = document.getElementById("confirmDeleteModal");
  if (!modal) return;

  document.getElementById("cdmRef").textContent  = slotEl.dataset.ref || "-";
  document.getElementById("cdmSach").textContent = slotEl.dataset.sach || "-";

  const p = slotEl.closest(".platz");
  const r = p?.dataset.row || "?";
  const z = p?.dataset.platz || "?";
  const i = slotEl.dataset.slotIndex || "?";
  document.getElementById("cdmPos").textContent = `R${r} / P${z} / Slot ${i}`;

  modal.classList.remove("hidden");
  modal.classList.add("flex");
}

function closeConfirmDelete() {
  const modal = document.getElementById("confirmDeleteModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  _pendingDeleteSlot = null;
  _pendingDeletePlatzEl = null;
}

function initDeleteConfirmModal() {
  const modal = document.getElementById("confirmDeleteModal");
  const noBtn = document.getElementById("cdmNo");
  const yesBtn = document.getElementById("cdmYes");

  noBtn?.addEventListener("click", () => {
    closeConfirmDelete();
    if (_pendingDeletePlatzEl) openPlatzOverlay(_pendingDeletePlatzEl);
  });

  // Backdrop
  modal?.addEventListener("click", (e) => {
    if (e.target === modal) {
      closeConfirmDelete();
      if (_pendingDeletePlatzEl) openPlatzOverlay(_pendingDeletePlatzEl);
    }
  });

  yesBtn?.addEventListener("click", async () => {
  const slotEl = _pendingDeleteSlot;
  const platzEl = _pendingDeletePlatzEl;

  if (!slotEl || !platzEl) {
    closeConfirmDelete();
    return;
  }

  try {
    // ✅ 1) erst DB löschen
    await deleteSlotOnServer(slotEl);

    // ✅ 2) dann UI leeren
    resetSlotUI(slotEl);
    updatePlatzLabel(platzEl);

    afterPlanChange();

    closeConfirmDelete();
    openPlatzOverlay(platzEl);

    setStatus("Slot wurde gelöscht (Server).", "success");
    flashPlatz(platzEl, "success");
    soundSuccess?.();

  } catch (err) {
    console.error(err);
    soundError?.();
    setStatus(err?.message || "Löschen fehlgeschlagen.", "error");

    closeConfirmDelete();
    openPlatzOverlay(platzEl);
  }
});

}

// ---------------------------
// MOVE (UMBuchen)
// ---------------------------

function openMoveModal(slotEl) {
  _moveSourceSlot = slotEl;

  const modal = document.getElementById("moveModal");
  if (!modal) return;

  const platzEl = slotEl.closest(".platz");
  const r = platzEl?.dataset.row || "?";
  const p = platzEl?.dataset.platz || "?";
  const i = slotEl.dataset.slotIndex || "?";

  document.getElementById("mmRef").textContent  = slotEl.dataset.ref || "-";
  document.getElementById("mmSach").textContent = slotEl.dataset.sach || "-";
  document.getElementById("mmFrom").textContent = `R${r} / P${p} / Slot ${i}`;

  // Ziel vorbefüllen = aktueller Platz
  document.getElementById("mmRow").value = String(r);
  document.getElementById("mmPlatz").value = parseInt(String(p), 10) || "";

  modal.classList.remove("hidden");
  modal.classList.add("flex");

  // Ziel highlight (live)
  setTimeout(() => highlightMoveTarget(), 30);
}

function closeMoveModal() {
  const modal = document.getElementById("moveModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  _moveSourceSlot = null;
}

function initMoveModal() {
  const modal   = document.getElementById("moveModal");
  const rowSel  = document.getElementById("mmRow");
  const plzInp  = document.getElementById("mmPlatz");
  const saveBtn = document.getElementById("mmSave");
  const closeBtn  = document.getElementById("mmClose");
  const cancelBtn = document.getElementById("mmCancel");

  if (!modal || !rowSel || !plzInp || !saveBtn) return;

  // ✅ 1) Reihen IMMER befüllen (nur 1x)
  if (rowSel.options.length <= 1) {
    fillRowSelect("mmRow", ROW_FROM, ROW_TO); // nutzt deine bestehende Funktion
  }

  // ✅ 2) Handler nur 1x binden (am Modal festmachen, nicht am Button)
  if (modal.dataset.bound === "1") return;
  modal.dataset.bound = "1";

  const applyMoveLimits = () => {
    const max = placeMaxForRow(rowSel.value);
    plzInp.min = "1";
    plzInp.max = String(max);
    const v = parseInt(plzInp.value || "0", 10);
    if (v && v > max) plzInp.value = String(max);
  };

  applyMoveLimits();

  closeBtn?.addEventListener("click", closeMoveModal);
  cancelBtn?.addEventListener("click", closeMoveModal);

  rowSel.addEventListener("change", () => {
    applyMoveLimits();
    highlightMoveTarget();
  });

  plzInp.addEventListener("input", () => {
    applyMoveLimits();
    highlightMoveTarget();
  });

  saveBtn.addEventListener("click", async () => {
    console.log("✅ mmSave clicked"); // <- wenn das nicht kommt, ist der Handler nicht dran / IDs doppelt

    try {
      if (!_moveSourceSlot) {
        setStatus?.("Keine Quelle gewählt (Umbuchen gestartet?)", "error");
        return;
      }

      const sourceSlot = _moveSourceSlot;
      const sourcePlatzEl = sourceSlot.closest(".platz");
      if (!sourcePlatzEl) return;

      const ref  = (sourceSlot.dataset.ref || "").trim();
      const sach = (sourceSlot.dataset.sach || "").trim();
      if (!ref || !sach) {
        setStatus?.("Quelle ungültig (Ref/Sach fehlt).", "error");
        return;
      }

      const targetRow = String(rowSel.value || "").trim();
      const targetPlzRaw = String(plzInp.value || "").trim();

      if (!targetRow || !targetPlzRaw) {
        setStatus?.("Bitte Ziel-Reihe und Ziel-Platz eingeben.", "error");
        return;
      }

      const maxPlz = placeMaxForRow(targetRow);
      const targetNum = parseInt(targetPlzRaw, 10);
      if (!Number.isFinite(targetNum) || targetNum < 1 || targetNum > maxPlz) {
        setStatus?.(`Ziel-Platz ungültig. Reihe ${targetRow} erlaubt 1–${maxPlz}.`, "error");
        return;
      }

      const targetPlz = String(targetNum).padStart(2, "0");
      const targetPlatzEl = document.querySelector(
        `.platz[data-row="${cssEscape(targetRow)}"][data-platz="${cssEscape(targetPlz)}"]`
      );
      if (!targetPlatzEl) {
        setStatus?.(`Zielplatz R${targetRow} / P${targetPlz} nicht gefunden.`, "error");
        return;
      }

      // ✅ Slot-ID Pflicht fürs Move
      const id = parseInt(sourceSlot.dataset.slotId || "0", 10);
      if (!id) {
        setStatus?.("Umbuchen nicht möglich: Slot-ID fehlt.", "error");
        return;
      }

      const moved = await moveSlotOnServer({
        halle: window.currentHall || "H4",
        zone:  window.currentZone || "W1",
        id,
        to_row: targetRow,
        to_platz: targetNum
      });

      const newIdx = String(moved.to.slot_index);
      const targetSlotEl = targetPlatzEl.querySelector(`.palette-slot[data-slot-index="${cssEscape(newIdx)}"]`);
      if (!targetSlotEl) throw new Error("Ziel-Slot im UI nicht gefunden.");

      // UI: Source leeren, Target setzen (wie bei dir)
      const oldDate = sourceSlot.dataset.date || "";
      const oldUser = sourceSlot.dataset.userName || "";
      const oldLs   = sourceSlot.dataset.lieferschein || "";

      resetSlotUI(sourceSlot);

      targetSlotEl.dataset.slotId = String(id);
      targetSlotEl.dataset.ref  = ref;
      targetSlotEl.dataset.sach = sach;
      targetSlotEl.dataset.date = oldDate || new Date().toLocaleDateString("de-DE");
      if (oldUser) targetSlotEl.dataset.userName = oldUser;
      if (oldLs)   targetSlotEl.dataset.lieferschein = oldLs;

      targetSlotEl.classList.add("palette-slot-used");
      targetSlotEl.textContent = ref.slice(-4);

      updatePlatzLabel(sourcePlatzEl);
      updatePlatzLabel(targetPlatzEl);

      afterPlanChange();

      closeMoveModal();

      highlightPlatz(targetPlatzEl);
      targetPlatzEl.scrollIntoView({ behavior: "smooth", block: "center" });

      setStatus?.(`Umbuchung: ${ref} → R${targetRow} / P${targetPlz} / Slot ${parseInt(newIdx,10)+1}`, "success");
      soundSuccess?.();

    } catch (err) {
      console.error("❌ mmSave handler failed:", err);
      soundError?.();
      setStatus?.(err?.message || "Umbuchen fehlgeschlagen.", "error");
    }
  });
}

function getRowNameOnly(row) {
  const r = String(row).trim();

  // 1) aus euren Row-Labels (perfekt)
  const labels = window.__ROW_LABELS__ || {};
  if (labels && labels[r]) return String(labels[r]).trim(); // -> "H4/R109"

  // 2) fallback: aus rowDisplay() "Reihe 109 – H4/R109" -> "H4/R109"
  if (typeof window.rowDisplay === "function") {
    const t = String(window.rowDisplay(r) || "");
    const parts = t.split("–");
    if (parts.length > 1) return parts.slice(1).join("–").trim();
  }

  return "";
}

function countPalletsPerRow() {
  const map = new Map();

  // alle belegten Slots zählen (Paletten)
  document.querySelectorAll('#w1-block-16-19 .palette-slot[data-ref]').forEach(slot => {
    const platzEl = slot.closest(".platz");
    const row = String(platzEl?.dataset?.row || "").trim();
    if (!row) return;
    map.set(row, (map.get(row) || 0) + 1);
  });

  return map;
}
// Variante 1: Objekt
window.__LISON_ROW_PALLETS__ = {
  "109": 112,
  "110": 98
};

// Erwartungswerte aus "Lison" (du setzt das irgendwo global)
function getLisonPallets(row) {
  const r = String(row).trim();
  const src = window.__LISON_ROW_PALLETS__;

  if (!src) return null;

  if (src instanceof Map) {
    const v = src.get(r);
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  if (typeof src === "object") {
    const v = src[r];
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  return null;
}

function exportPalletsPerRowIstSollXlsx() {
  if (!window.XLSX) {
    alert("XLSX Library fehlt (xlsx.full.min.js).");
    return;
  }

  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";
  const fromRow = 1;
  const toRow = 140;

  const palletsMap = countPalletsPerRow();
  const devMap     = countDeviationsPerRow(); // Abweichungen zählen

  // ✅ NEU: zusätzliche Spalte "Diff+Abw"
  const aoa = [["Reihe", "Name", "Paletten", "Lison", "Diff", "Abweichungen", "Diff+Abw"]];

  for (let r = fromRow; r <= toRow; r++) {
    const rowKey  = String(r);
    const name    = getRowNameOnly(rowKey);
    const pallets = palletsMap.get(rowKey) || 0;
    const lison   = getLisonPallets(rowKey);      // null => leer
    const abw     = devMap.get(rowKey) || 0;      // ✅ hier steht erstmal die Anzahl

    // Diff und Diff+Abw kommen als Formel später
    aoa.push([r, name, pallets, (lison == null ? "" : lison), "", abw, ""]);
  }

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  const lastDataRow = 1 + (toRow - fromRow + 1); // Header + 140 Zeilen => 141
  ws["!autofilter"] = { ref: `A1:G${lastDataRow}` };

  // ✅ Diff = IF(D2="","",C2-D2)
  // ✅ Diff+Abw = IF(E2="","",E2+F2)
 // ✅ Spalte H = IST-Gesamt = F * G
for (let i = 2; i <= lastDataRow; i++) {
  ws[`H${i}`] = {
    t: "n",
    f: `IF(AND(ISNUMBER(F${i}),ISNUMBER(G${i})),F${i}*G${i},"")`,
    v: 0
  };
}


  // ✅ Summary unten
  const sumRow = lastDataRow + 2; // 1 Leerzeile
  ws[`A${sumRow}`] = { t: "s", v: "SUMMARY" };
  ws[`C${sumRow}`] = { t: "n", f: `SUM(C2:C${lastDataRow})`, v: 0 };
  ws[`D${sumRow}`] = { t: "n", f: `SUM(D2:D${lastDataRow})`, v: 0 };
  ws[`E${sumRow}`] = { t: "n", f: `C${sumRow}-D${sumRow}`, v: 0 };
  ws[`F${sumRow}`] = { t: "n", f: `SUM(F2:F${lastDataRow})`, v: 0 };
  ws[`G${sumRow}`] = { t: "n", f: `SUM(G2:G${lastDataRow})`, v: 0 };

  // Range erweitern
  ws["!ref"] = XLSX.utils.encode_range({
    s: { r: 0, c: 0 },
    e: { r: sumRow - 1, c: 6 } // bis Spalte G
  });

  // Spaltenbreiten
  ws["!cols"] = [
    { wch: 8 },   // Reihe
    { wch: 18 },  // Name
    { wch: 10 },  // Paletten
    { wch: 10 },  // Lison
    { wch: 10 },  // Diff
    { wch: 14 },  // Abweichungen
    { wch: 12 }   // Diff+Abw
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Row IST-SOLL");

  const date = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, `paletten_ist_soll_${hall}_${zone}_${date}.xlsx`);
}



document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("btnXlsxRowPallets")
    ?.addEventListener("click", exportPalletsPerRowIstSollXlsx);
});



function highlightMoveTarget() {
  const row = document.getElementById("mmRow")?.value || "";
  const pRaw = String(document.getElementById("mmPlatz")?.value || "").trim();
  if (!row || !pRaw) return;

  const p = String(pRaw).padStart(2, "0");
  const el = document.querySelector(`#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${cssEscape(p)}"]`);
  if (el) {
    highlightPlatz(el);
    el.scrollIntoView({behavior:"smooth", block:"center"});
  }
}

// ---------------------------
// Helpers / Robust Delete/Save
// ---------------------------

function resetSlotUI(slotEl) {
  delete slotEl.dataset.ref;
  delete slotEl.dataset.sach;
  delete slotEl.dataset.date;
  delete slotEl.dataset.slotId;
  delete slotEl.dataset.userName;
  delete slotEl.dataset.lieferschein;
  delete slotEl.dataset.menge;        // ✅ NEU
  delete slotEl.dataset.itemsCount;   // ✅ NEU
  delete slotEl.dataset.itemsQty;     // ✅ NEU

  slotEl.classList.remove("palette-slot-used", "ring-2", "ring-red-500");
  slotEl.textContent = "";
  slotEl.title = "";
}


function flashPlatz(platzEl, type = "error") {
  if (!platzEl) return;
  const ring = type === "success" ? ["ring-2","ring-emerald-500"] : ["ring-2","ring-red-500"];
  platzEl.classList.add(...ring, "animate-pulse");
  setTimeout(() => {
    platzEl.classList.remove(...ring, "animate-pulse");
  }, 650);
}

async function saveSlotToServer(payload) {
  const fd = new FormData();
  Object.entries(payload).forEach(([k, v]) => fd.append(k, v));

  const data = await fetchJson("lager_save.php", { method: "POST", body: fd });

  if (!data || typeof data !== "object") {
    throw new Error("Serverantwort ist leer/ungültig (kein JSON-Objekt).");
  }

  // WICHTIG: NICHT throw bei ok:false -> Caller entscheidet (duplicate_ref etc.)
  return data;
}

function beepSuccess(){ soundSuccess(); }
function beepError(){ soundError(); }

// -> Löschen per id ODER per Koordinaten (damit es sofort geht ohne Reload)
function deleteSlotOnServer(slotOrId) {
  const fd = new FormData();

  if (typeof slotOrId === "number" || /^[0-9]+$/.test(String(slotOrId))) {
    fd.append("id", String(slotOrId));
  } else {
    const slotEl = slotOrId;
    const platzEl = slotEl.closest(".platz");
    if (slotEl.dataset.slotId) fd.append("id", String(slotEl.dataset.slotId));

    fd.append("halle", window.currentHall || "H4");
    fd.append("zone",  window.currentZone || "W1");
    fd.append("reihe", platzEl?.dataset.row || "");
    fd.append("platz", platzEl?.dataset.platz || "");
    fd.append("slot_index", slotEl.dataset.slotIndex || "");
  }

  return fetchJson("lager_delete.php", { method: "POST", body: fd })
    .then(data => {
      if (!data.ok) throw new Error(data.msg || "Fehler beim Löschen.");
      return true;
    });
}

/* =========================================================
   SEARCH V2: Live-Dropdown + „Keine Ergebnisse“
   - nutzt lokale Slots (Refs/Sachnummern)
   - optional: Sachnummer-Liveabfrage über bestehende Stammdaten-API
========================================================= */


let _searchDD = null;
let _searchDDState = { open: false, items: [], active: -1, lastQ: "" };
let _searchDebounce = null;

let _searchIndex = { refs: new Map(), sach: new Map(), ls: new Map() };

function rebuildSearchIndex() {
  const refs = new Map();
  const sach = new Map();
  const ls   = new Map();

  const slots = Array.from(document.querySelectorAll("#w1-block-16-19 .palette-slot"));
  for (const s of slots) {
    const r  = (s.dataset.ref || "").trim();
    const a  = (s.dataset.sach || "").trim();
    const l  = (s.dataset.lieferschein || "").trim();

    if (r) { if (!refs.has(r)) refs.set(r, []); refs.get(r).push(s); }
    if (a) { if (!sach.has(a)) sach.set(a, []); sach.get(a).push(s); }
    if (l) { if (!ls.has(l))   ls.set(l,   []); ls.get(l).push(s); }
  }

  _searchIndex = { refs, sach, ls };
}

function initSearchAutocomplete() {
  const input = document.getElementById("searchRefInput");
  if (!input) return;

  const wrap = document.getElementById("searchWrap") || input.closest(".position-relative") || input.parentElement;
  wrap.classList.add("position-relative"); // statt "relative"

 _searchDD = document.getElementById("searchDropdown");
if (!_searchDD) {
  _searchDD = document.createElement("div");
  _searchDD.id = "searchDropdown";
  _searchDD.className = "d-none position-absolute start-0 top-100 mt-1 w-100";
  _searchDD.style.zIndex = "2000";
  wrap.appendChild(_searchDD);
}



  input.addEventListener("input", () => {
    const q = (input.value || "").trim();
    clearTimeout(_searchDebounce);
    _searchDebounce = setTimeout(() => buildSearchDropdown(q), 120);
  });

  input.addEventListener("keydown", (e) => {
    if (!_searchDDState.open) {
      // Enter ohne Dropdown -> normale Suche
      if (e.key === "Enter") {
        e.preventDefault();
        searchQuery(input.value);
      }
      return;
    }

    if (e.key === "ArrowDown") {
      e.preventDefault();
      moveActive(1);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      moveActive(-1);
    } else if (e.key === "Escape") {
      e.preventDefault();
      closeSearchDropdown();
    } else if (e.key === "Enter") {
      e.preventDefault();
      // aktives Item wählen, sonst erstes
      const idx = _searchDDState.active >= 0 ? _searchDDState.active : 0;
      const item = _searchDDState.items[idx];
      if (item) selectSearchItem(item);
      else searchQuery(input.value);
    }
  });

  // Klick außerhalb schließt
  document.addEventListener("click", (e) => {
    if (!_searchDDState.open) return;
    if (_searchDD.contains(e.target) || input.contains(e.target)) return;
    closeSearchDropdown();
  });
}

async function buildSearchDropdown(query) {
  if (!_searchDD) return;

  const q = String(query || "").trim();
  _searchDDState.lastQ = q;

  if (q.length < 1) {
    closeSearchDropdown();
    return;
  }

  // lokale Treffer
  const localItems = getLocalSearchItems(q);

  // Sachnummern live aus Stammdaten (optional, nur wenn nichts/kaum lokal)
  let apiItems = [];
  if (q.length >= 2) {
    try {
      apiItems = await fetchSachnummerSuggestions(q);
    } catch {
      apiItems = [];
    }
  }

  // Merge: lokale zuerst, dann API (ohne Duplikate)
  const seen = new Set();
  const items = [];

  for (const it of localItems) {
    const key = it.type + ":" + it.value;
    if (seen.has(key)) continue;
    seen.add(key);
    items.push(it);
  }

  for (const it of apiItems) {
    const key = it.type + ":" + it.value;
    if (seen.has(key)) continue;
    // API nur Sachnummer, wenn lokal nicht vorhanden oder als Ergänzung
    seen.add(key);
    items.push(it);
  }

  // Wenn Anfrage während await geändert wurde -> abbrechen
  if (_searchDDState.lastQ !== q) return;

  renderSearchDropdown(items, q);
}

function getLocalSearchItems(q) {
  const items = [];
  const qLow = q.toLowerCase();

  // Refs (contains)
  for (const [ref, slotArr] of _searchIndex.refs.entries()) {
    if (ref.toLowerCase().includes(qLow)) {
      const first = slotArr[0];
const p = first.closest(".platz");
const r = p?.dataset.row || "?";
const plz = p?.dataset.platz || "?";
const slotHuman = (parseInt(first.dataset.slotIndex || "0", 10) + 1);

items.push({
  type: "ref",
  value: ref,
  label: `Ref: ${ref} → R${r}-${plz}-${slotHuman}`,
  slot: first
});

      if (items.length >= 30) break;
    }
  }
  // Karton-Refs (contains)
for (const [ref, slotEl] of _itemRefToSlot.entries()) {
  if (ref.toLowerCase().includes(qLow)) {
    const p = slotEl.closest(".platz");
    const r = p?.dataset.row || "?";
    const plz = p?.dataset.platz || "?";
    const slotHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);

    items.push({
      type: "item",
      value: ref,
      label: `Karton: ${ref} → R${r}-${plz}-${slotHuman}`,
      slot: slotEl
    });

    if (items.length >= 40) break;
  }
}


  // Sachnummern (contains)
  for (const [sach, slotArr] of _searchIndex.sach.entries()) {
    if (sach.toLowerCase().includes(qLow)) {
      items.push({
        type: "sach",
        value: sach,
        label: `Sachnummer: ${sach} (${slotArr.length} Treffer)`,
        slots: slotArr
      });
      if (items.length >= 40) break;
    }
  }

  // Lieferscheine (contains)
for (const [lsNo, slotArr] of _searchIndex.ls.entries()) {
  if (lsNo.toLowerCase().includes(qLow)) {
    items.push({
      type: "ls",
      value: lsNo,
      label: `LS: ${lsNo} (${slotArr.length} Treffer)`,
      slots: slotArr
    });
    if (items.length >= 40) break;
  }
}


  // Sort: Refs zuerst
  items.sort((a,b) => (a.type === b.type ? a.label.localeCompare(b.label) : (a.type === "ref" ? -1 : 1)));
  return items.slice(0, 40);
}

async function fetchSachnummerSuggestions(q) {
  const url = new URL(API_STAMM, location.origin);
  url.searchParams.set("type", "sachnummer");
  url.searchParams.set("action", "list");
  url.searchParams.set("q", q);

  const res = await fetch(url, { cache:"no-store", credentials:"same-origin" });
  if (!res.ok) return [];

  const data = await res.json().catch(()=> ({}));
  if (!data?.ok || !Array.isArray(data.items)) return [];

  const sel = getSelectedLGs();

  return data.items
    .map(x => ({
      type: "sach_api",
      value: String(x.sachnummer || "").trim(),
      lg: String(x.lagergruppe || "").trim(),
      label: `Sachnummer: ${String(x.sachnummer||"").trim()} · LG: ${String(x.lagergruppe||"").trim()}`
    }))
    .filter(it => it.value)
    .filter(it => !sel || sel.has(it.lg))
    .slice(0, 10);
}

function renderSearchDropdown(items, q) {
  if (!_searchDD) return;

  _searchDD.innerHTML = "";
  _searchDDState.items = items;
  _searchDDState.active = items.length ? 0 : -1;

  const list = document.createElement("div");
  list.className = "list-group shadow-sm"; // Bootstrap

  if (!items.length) {
    const no = document.createElement("div");
    no.className = "list-group-item disabled text-muted small";
    no.textContent = `Keine Ergebnisse für „${q}“`;
    list.appendChild(no);

    _searchDD.appendChild(list);
    openSearchDropdown();
    return;
  }

  items.forEach((it, idx) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className =
      "list-group-item list-group-item-action d-flex justify-content-between align-items-center";
    btn.dataset.idx = String(idx);

    // Links: Label
    const left = document.createElement("div");
    left.className = "small";
    left.textContent = it.label;

    // Rechts: Badge je Typ
    const badge = document.createElement("span");
    badge.className = "badge text-bg-secondary";
    badge.textContent =
      badge.textContent =
  it.type === "ref" ? "Ref" :
  it.type === "item" ? "Karton" :
  it.type === "sach" ? "Sach" :
  it.type === "sach_api" ? "Stamm" :
  it.type === "ls" ? "LS" : it.type;



    btn.appendChild(left);
    btn.appendChild(badge);

    btn.addEventListener("mouseenter", () => setActive(idx));
    btn.addEventListener("click", () => selectSearchItem(it));

    list.appendChild(btn);
  });

  _searchDD.appendChild(list);

  setActive(_searchDDState.active);
  openSearchDropdown();
}
function setActive(idx) {
  _searchDDState.active = idx;

  const kids = Array.from(_searchDD.querySelectorAll(".list-group-item[data-idx]"));
  kids.forEach(b => b.classList.remove("active"));

  const el = _searchDD.querySelector(`.list-group-item[data-idx="${idx}"]`);
  if (el) el.classList.add("active");
}

function moveActive(dir) {
  const n = _searchDDState.items.length;
  if (!n) return;

  let i = _searchDDState.active;
  i = (i + dir + n) % n;
  setActive(i);

  const el = _searchDD.querySelector(`.list-group-item[data-idx="${i}"]`);
  el?.scrollIntoView({ block: "nearest" });
}
function selectSearchItem(item) {
  // ✅ Wert ins Suchfeld übernehmen
  const input = document.getElementById("searchRefInput");
  if (input && item?.value) {
    input.value = item.value;
    input.focus();
    try { input.setSelectionRange(input.value.length, input.value.length); } catch (_) {}
  }

  closeSearchDropdown();

  // ✅ Ref -> direkt hinspringen + Overlay
  if (item.type === "ref" && item.slot) {
    jumpToSlot(item.slot, true);
    return;
  }
  if (item.type === "item" && item.slot) {
  jumpToSlot(item.slot, true);
  return;
}


  // ✅ Sachnummer (lokal oder Stammdaten) -> Treffer markieren + Liste
  if ((item.type === "sach" || item.type === "sach_api") && item.value) {
    const slots = _searchIndex?.sach?.get(item.value) || [];
    if (!slots.length) {
      setStatus(`Sachnummer ${item.value} ist in diesem Lagerplan aktuell nicht belegt.`, "info");
      return;
    }

    clearHighlights();
    slots.forEach(s => {
      s.classList.add("ring-2", "ring-red-500");
      blinkSlotGreen(s);
      const p = s.closest(".platz");
      if (p) blinkPlatzGreen(p);
    });

    const firstPlatz = slots[0].closest(".platz");
    if (firstPlatz) {
      highlightPlatz(firstPlatz);
      firstPlatz.scrollIntoView({ behavior: "smooth", block: "center" });
      pulsePlatz1s(firstPlatz);
    }

    const pos = slots.slice(0, 10).map(s => {
      const p = s.closest(".platz");
      const r = p?.dataset.row || "?";
      const plz = p?.dataset.platz || "?";
      const slotHuman = (parseInt(s.dataset.slotIndex || "0", 10) + 1);
      return `R${r}-${plz}-${slotHuman}`;
    });

    const more = slots.length > 10 ? ` … (+${slots.length - 10})` : "";
    setStatus(`Sachnummer ${item.value}: ${slots.length} Treffer → ${pos.join(", ")}${more}`, "success");
    showHitList(`Sachnummer ${item.value}: ${slots.length} Treffer`, slots);
    return;
  }

  // ✅ Lieferschein -> Treffer markieren + Liste
  if (item.type === "ls" && item.value) {
    const slots = _searchIndex?.ls?.get(item.value) || [];
    if (!slots.length) {
      setStatus(`Lieferschein ${item.value} hat aktuell keine belegten Treffer.`, "info");
      return;
    }

    clearHighlights();
    slots.forEach(s => {
      s.classList.add("ring-2", "ring-red-500");
      blinkSlotGreen(s);
      const p = s.closest(".platz");
      if (p) blinkPlatzGreen(p);
    });

    const firstPlatz = slots[0].closest(".platz");
    if (firstPlatz) {
      highlightPlatz(firstPlatz);
      firstPlatz.scrollIntoView({ behavior: "smooth", block: "center" });
      pulsePlatz1s(firstPlatz);
    }

    setStatus(`Lieferschein ${item.value}: ${slots.length} Treffer.`, "success");
    showHitList(`Lieferschein ${item.value}: ${slots.length} Treffer`, slots);
    return;
  }

  // Fallback: falls ein neuer Typ reinkommt
  if (item?.value) searchQuerySmart(item.value);
}

async function searchQuery(queryRaw) {
  const query = String(queryRaw || "").trim();
  hideInfoBubble();
  hideHitList();
  clearHighlights();

  if (!query) {
    setStatus("Bitte Referenz, Sachnummer oder Lieferschein eingeben.", "error");
    return;
  }

  const byRefArr = _searchIndex?.refs?.get(query) || [];
  const byRef = byRefArr.length ? byRefArr[0] : null;

  const bySach = _searchIndex?.sach?.get(query) || [];
  const byLs   = _searchIndex?.ls?.get(query)   || [];

  const cartonSlot = _itemRefToSlot.get(query);
  if (cartonSlot) {
    jumpToSlot(cartonSlot, true);
    setStatus(`Karton ${query} gefunden.`, "success");
    return;
  }

  if (byRef) {
    byRef.classList.add("ring-2", "ring-red-500");
    jumpToSlot(byRef, true);
    setStatus(`Referenz ${query} gefunden.`, "success");
    return;
  }

  if (bySach.length) {
    bySach.forEach(s => s.classList.add("ring-2", "ring-red-500"));
    showHitList(`Sachnummer ${query}: ${bySach.length} Treffer`, bySach);
    setStatus(`Sachnummer ${query}: ${bySach.length} Treffer.`, "info");
    return;
  }

  if (byLs.length) {
    byLs.forEach(s => s.classList.add("ring-2", "ring-red-500"));
    showHitList(`Lieferschein ${query}: ${byLs.length} Treffer`, byLs);
    setStatus(`Lieferschein ${query}: ${byLs.length} Treffer.`, "info");
    return;
  }

  // ✅ Nur wenn es wie Ref aussieht -> Status check (IN / OUT / DELETED)
if (isProbablyRef(query)) {
  const shown = await tryShowRefStatus(query);
  if (shown) return;
}

  setStatus(`Keine Treffer für "${query}" gefunden.`, "error");
}


function jumpToSlot(slotEl, openOverlay = false) {
  if (!slotEl) return;
  clearHighlights();

  const platzEl = slotEl.closest(".platz");
  if (platzEl) {
    highlightPlatz(platzEl);
    platzEl.scrollIntoView({ behavior: "smooth", block: "center" });

    // ✅ NEU: statt nur blink -> puls 1s + grün blink
    pulsePlatz1s(platzEl);
    blinkPlatzGreen(platzEl);
  }

  slotEl.classList.add("ring-2", "ring-red-500");
  blinkSlotGreen(slotEl);

  setStatus(`Referenz ${slotEl.dataset.ref || ""} gefunden.`, "success");

  if (openOverlay && typeof openSlotOverlay === "function") {
    openSlotOverlay(slotEl);
  }
}
let _searchFxStylesAdded = false;

function ensureSearchFxStyles() {
  if (_searchFxStylesAdded) return;
  _searchFxStylesAdded = true;

  const style = document.createElement("style");
  style.textContent = `
    @keyframes platzPulse {
      0%   { transform: scale(1);   box-shadow: 0 0 0 0 rgba(16,185,129,.0); }
      50%  { transform: scale(1.02);box-shadow: 0 0 0 6px rgba(16,185,129,.25); }
      100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(16,185,129,.0); }
    }
    .platz-pulse-1s { animation: platzPulse 1s ease-in-out 1; }

    @keyframes greenBlink {
      0%,100% { outline-color: rgba(16,185,129,0); }
      50%     { outline-color: rgba(16,185,129,1); }
    }
    .slot-blink-green {
      outline: 3px solid rgba(16,185,129,0);
      animation: greenBlink .6s ease-in-out 2;
      border-radius: 4px;
    }

    .platz-blink-green {
      outline: 3px solid rgba(16,185,129,0);
      animation: greenBlink .6s ease-in-out 2;
      border-radius: 8px;
    }
  `;
  document.head.appendChild(style);
}
function pulsePlatz1s(platzEl) {
  if (!platzEl) return;
  ensureSearchFxStyles();
  platzEl.classList.remove("platz-pulse-1s");
  // Reflow, damit Animation neu startet
  void platzEl.offsetWidth;
  platzEl.classList.add("platz-pulse-1s");
}
function blinkSlotGreen(slotEl) {
  if (!slotEl) return;
  ensureSearchFxStyles();
  slotEl.classList.remove("slot-blink-green");
  void slotEl.offsetWidth;
  slotEl.classList.add("slot-blink-green");
}
function slotKey(slotEl) {
  const platzEl = slotEl.closest(".platz");
  const row = platzEl?.dataset.row || "?";
  const platz = platzEl?.dataset.platz || "??";
  const idxHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);
  return `R${row}-${platz}-${idxHuman}`;
}
function focusSlot(slotEl, opts = {}) {
  const { openOverlay = false, pulse = true } = opts;

  if (!slotEl) return;

  const platzEl = slotEl.closest(".platz");
  if (platzEl) {
    highlightPlatz(platzEl);
    platzEl.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  // Slot kurz pulsieren (grün)
  if (pulse) pulseGreen(slotEl);

  // optional Overlay öffnen
  if (openOverlay) {
    setTimeout(() => openSlotOverlay(slotEl), 120);
  }
}
function pulseGreen(el) {
  ensureHitListStyles();

  el.classList.remove("pulse-green");
  // reflow trick, damit Animation erneut startet
  void el.offsetWidth;
  el.classList.add("pulse-green");

  setTimeout(() => el.classList.remove("pulse-green"), 1100);
}
function ensureHitListStyles() {
  if (document.getElementById("hitListStyles")) return;

  const style = document.createElement("style");
  style.id = "hitListStyles";
  style.textContent = `
    .pulse-green {
      animation: pulseGreen 1s ease-out 1;
    }
    @keyframes pulseGreen {
      0%   { box-shadow: 0 0 0 0 rgba(16,185,129,.85); }
      70%  { box-shadow: 0 0 0 14px rgba(16,185,129,0); }
      100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
    }
  `;
  document.head.appendChild(style);
}

// ===========================
// HITLIST + PRINT (Inventur)
// ===========================

let _hitListLast = { title: "", slots: [] };

function ensureHitListUI() {
  let panel = document.getElementById("hitListPanel");
  if (panel) return panel;

  panel = document.createElement("div");
  panel.id = "hitListPanel";
  panel.className =
    "fixed right-3 bottom-3 w-[92vw] max-w-sm bg-white border border-slate-300 rounded-xl shadow-lg hidden z-[9999]";

  panel.innerHTML = `
    <div class="flex items-center justify-between px-3 py-2 border-b border-slate-200 gap-2">
      <div class="text-xs font-semibold text-slate-800" id="hitListTitle">Treffer</div>

      <div class="flex items-center gap-2">
      <button type="button"
      class="bg-emerald-600 text-white text-[11px] font-semibold px-2 py-1 rounded hover:bg-emerald-700"
      data-hitlist-xlsx>
      Excel
    </button>
        <button type="button"
                class="bg-slate-800 text-white text-[11px] font-semibold px-2 py-1 rounded hover:bg-slate-900"
                data-hitlist-print>
          Drucken
        </button>

        <button type="button"
                class="text-slate-600 hover:text-slate-900 text-lg leading-none"
                data-hitlist-close>×</button>
      </div>
    </div>

    <div class="max-h-[45vh] overflow-auto p-2" id="hitListBody"></div>
  `;

  document.body.appendChild(panel);

  panel.querySelector("[data-hitlist-close]")?.addEventListener("click", hideHitList);
  panel.querySelector("[data-hitlist-print]")?.addEventListener("click", () => printHitList());

  return panel;
}

// ===============================
// Helper: Select mit Reihen füllen
// ===============================
function fillRowSelect(id, from = 1, to = 140) {
  const sel = document.getElementById(id);
  if (!sel) return;

  sel.innerHTML =
    `<option value="">– wählen –</option>` +
    Array.from({ length: (to - from + 1) }, (_, i) => {
      const r = from + i;
      return `<option value="${r}">${r}</option>`;
    }).join("");
}
window.fillRowSelect = fillRowSelect; // ✅ damit überall verfügbar


function showHitList(title, slotList) {
  const panel = ensureHitListUI();
  const titleEl = document.getElementById("hitListTitle");
  const bodyEl = document.getElementById("hitListBody");
  if (!bodyEl) return;

  // merken für Print
  _hitListLast.title = title || "Trefferliste";
  _hitListLast.slots = Array.isArray(slotList) ? [...slotList] : [];

  if (titleEl) titleEl.textContent = _hitListLast.title;
  bodyEl.innerHTML = "";

  // sortiert: Reihe, Platz, Slot
  const sorted = [..._hitListLast.slots].sort((a, b) => {
    const pa = a.closest(".platz");
    const pb = b.closest(".platz");
    const ra = parseInt(pa?.dataset.row || "0", 10);
    const rb = parseInt(pb?.dataset.row || "0", 10);
    const pla = parseInt(pa?.dataset.platz || "0", 10);
    const plb = parseInt(pb?.dataset.platz || "0", 10);
    const sa = parseInt(a.dataset.slotIndex || "0", 10);
    const sb = parseInt(b.dataset.slotIndex || "0", 10);
    return (ra - rb) || (pla - plb) || (sa - sb);
  });

  if (!sorted.length) {
    const empty = document.createElement("div");
    empty.className = "text-xs text-slate-500 px-2 py-2";
    empty.textContent = "Keine Ergebnisse";
    bodyEl.appendChild(empty);
  }
  window._hitListLast = _hitListLast;


  sorted.forEach((slotEl) => {
    const key = slotKey(slotEl);
    const ref = slotEl.dataset.ref || "";

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className =
      "w-full text-left px-2 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-xs flex items-center justify-between gap-2 mb-2";

    btn.innerHTML = `
      <span class="font-semibold text-slate-800">${key}</span>
      <span class="text-slate-500">${ref ? ref : ""}</span>
    `;

    btn.addEventListener("click", () => {
      hideHitList();
      focusSlot(slotEl, { openOverlay: false, pulse: true });
    });

    bodyEl.appendChild(btn);
  });

  panel.classList.remove("hidden");
}


function hideHitList() {
  const panel = document.getElementById("hitListPanel");
  if (!panel) return;
  panel.classList.add("hidden");
}

/* ---------- PRINT ---------- */

function printHitList() {
  const title = _hitListLast.title || "Inventur Trefferliste";
  const slots = Array.isArray(_hitListLast.slots) ? _hitListLast.slots : [];

  if (!slots.length) {
    setStatus("Keine Trefferliste zum Drucken vorhanden.", "error");
    return;
  }

  const html = buildPrintHtml(title, slots);

  // Hidden iframe print -> druckt nur die Liste (ohne Rest der Seite)
  const iframe = document.createElement("iframe");
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "0";
  iframe.style.height = "0";
  iframe.style.border = "0";
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow?.document;
  if (!doc) {
    setStatus("Drucken nicht möglich (kein Print-Frame).", "error");
    iframe.remove();
    return;
  }

  doc.open();
  doc.write(html);
  doc.close();

  iframe.onload = () => {
    try {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
    } finally {
      setTimeout(() => iframe.remove(), 800);
    }
  };
}

function buildPrintHtml(title, slots) {
  const now = new Date();
  const stamp = now.toLocaleString("de-DE");
  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";

  const sumMenge = slots.reduce((sum, s) => sum + (parseInt(s.dataset.menge || "1", 10) || 1), 0);

  // ✅ NEU: Sachnummern kumulieren
  const bySach = new Map();
  slots.forEach(s => {
    const sach = (s.dataset.sach || "-").trim() || "-";
    const m = Math.max(1, parseInt(String(s.dataset.menge || "1"), 10) || 1);
    bySach.set(sach, (bySach.get(sach) || 0) + m);
  });
  const sachList = Array.from(bySach.entries()).sort((a,b) => a[0].localeCompare(b[0]));

  const sachSummaryRows = sachList.map(([sach, qty]) => `
    <tr>
      <td>${escapeHtml(sach)}</td>
      <td style="text-align:right">${escapeHtml(String(qty))}</td>
    </tr>
  `).join("");

  const sachSummaryHtml = sachList.length ? `
    <div class="sumbox">
      <div class="sumhead">
        <div><b>Sachnummern (kumuliert)</b></div>
        <div class="summeta">Treffer: <b>${escapeHtml(String(slots.length))}</b> · Summe Stück: <b>${escapeHtml(String(sumMenge))}</b></div>
      </div>
      <table class="sumtable">
        <thead>
          <tr>
            <th>Sachnummer</th>
            <th style="text-align:right">Stück</th>
          </tr>
        </thead>
        <tbody>
          ${sachSummaryRows}
        </tbody>
      </table>
    </div>
  ` : "";

  const rows = slots.map((s) => {
    const p = s.closest(".platz");
    const r = p?.dataset.row || "?";
    const plz = p?.dataset.platz || "?";
    const slotHuman = (parseInt(s.dataset.slotIndex || "0", 10) + 1);

    const pos  = `R${r}-${plz}-${slotHuman}`;
    const ref  = escapeHtml(s.dataset.ref || "");
    const sach = escapeHtml(s.dataset.sach || "");
    const ls   = escapeHtml(s.dataset.lieferschein || "");
    const date = escapeHtml(s.dataset.date || "");
    const user = escapeHtml(s.dataset.userName || "");
    const menge = escapeHtml(s.dataset.menge || "1");

    return `
      <tr>
        <td>${pos}</td>
        <td>${ref}</td>
        <td>${sach}</td>
        <td>${ls}</td>
        <td style="text-align:right">${menge}</td>
        <td>${date}</td>
        <td>${user}</td>
        <td class="check"></td>
        <td class="note"></td>
      </tr>
    `;
  }).join("");

  return `
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>${escapeHtml(title)}</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 16px; color: #111; }
    h1 { font-size: 14px; margin: 0 0 6px; }
    .meta { font-size: 11px; color: #444; margin-bottom: 10px; }

    /* ✅ Summary */
    .sumbox { border:1px solid #999; background:#f7f7f7; border-radius:10px; padding:10px; margin: 8px 0 12px; }
    .sumhead { display:flex; justify-content:space-between; gap:10px; align-items:flex-end; margin-bottom:6px; }
    .summeta { font-size: 11px; color:#444; white-space:nowrap; }
    .sumtable { width:100%; border-collapse: collapse; font-size: 11px; }
    .sumtable th, .sumtable td { border:1px solid #999; padding:6px; vertical-align: top; }
    .sumtable th { background:#f2f2f2; text-align:left; }

    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #999; padding: 6px; vertical-align: top; }
    th { background: #f2f2f2; text-align: left; }
    td.check { width: 40px; }
    td.note { width: 220px; }
    tr.summary td { background:#f7f7f7; font-weight:700; }
    @media print { body { padding: 0; } .sumbox { break-inside: avoid; } }
  </style>
</head>
<body>
  <h1>${escapeHtml(title)}</h1>
  <div class="meta">
    Halle: <b>${escapeHtml(hall)}</b> · Zone: <b>${escapeHtml(zone)}</b> · Stand: ${escapeHtml(stamp)}
    · Treffer: <b>${escapeHtml(String(slots.length))}</b> · Summe Menge: <b>${escapeHtml(String(sumMenge))}</b>
  </div>

  ${sachSummaryHtml}

  <table>
    <thead>
      <tr>
        <th>Position</th>
        <th>Referenz</th>
        <th>Sachnummer</th>
        <th>Lieferschein</th>
        <th style="text-align:right">Menge</th>
        <th>Datum</th>
        <th>User</th>
        <th>OK</th>
        <th>Bemerkung</th>
      </tr>
    </thead>
    <tbody>
      ${rows}
      <tr class="summary">
        <td colspan="4">SUMMARY</td>
        <td style="text-align:right">${escapeHtml(String(sumMenge))}</td>
        <td colspan="4">Treffer: ${escapeHtml(String(slots.length))}</td>
      </tr>
    </tbody>
  </table>

  <div style="margin-top:10px;font-size:10px;color:#555;">
    Inventur: abhaken + Bemerkung eintragen, dann wieder zurück ins System.
  </div>
</body>
</html>
  `;
}

let _outClearTimer = null;

function clearOutRefSoon(expectedRef, ms = 1000) {
  const inp = document.getElementById("outRef");
  if (!inp) return;

  if (_outClearTimer) clearTimeout(_outClearTimer);

  _outClearTimer = setTimeout(() => {
    // nur löschen, wenn im Feld noch genau diese Ref steht
    if ((inp.value || "").trim() === String(expectedRef || "").trim()) {
      inp.value = "";
      inp.focus();
    }
  }, ms);
}


let _outbookSlot = null;

function initOutbookFlow() {
  const outRef = document.getElementById("outRef");
  const btn = document.getElementById("btnOutbook");
  if (!outRef || !btn) return;

  // Enter = sofort vorbereiten + Modal öffnen
  outRef.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      startOutbookFromRef();
    }
  });

  btn.addEventListener("click", startOutbookFromRef);

  // Modal Buttons
  document.getElementById("outClose")?.addEventListener("click", closeOutbookModal);
  document.getElementById("outNo")?.addEventListener("click", () => {
    closeOutbookModal();
    document.getElementById("outRef")?.focus();
  });

  // ✅ HIER: nicht löschen, sondern OUTBOOK (Historie + Slot frei)
  document.getElementById("outYes")?.addEventListener("click", async () => {
    if (!_outbookSlot) return;

    const slotEl  = _outbookSlot;
    const platzEl = slotEl.closest(".platz");

    const ref  = (slotEl.dataset.ref || "").trim();
    const sach = (slotEl.dataset.sach || "").trim();

    const outLsVal = (document.getElementById("outLs")?.value || "").trim(); // Versand-LS

    try {
      const id = slotEl.dataset.slotId ? parseInt(slotEl.dataset.slotId, 10) : 0;

      const data = await outbookOnServer({
        halle: window.currentHall || "H4",
        zone:  window.currentZone || "W1",
        id: id || null,
        referenznr: ref,
        ausgebucht_ls: outLsVal
      });

      // falls dein outbookOnServer nicht throwt:
      if (!data || data.ok !== true) {
        throw new Error(data?.msg || data?.error || "Ausbuchen fehlgeschlagen.");
      }

      // UI frei machen
      resetSlotUI(slotEl);
      if (platzEl) updatePlatzLabel(platzEl);

      afterPlanChange();

      // Sound + Status
      if (typeof soundSuccess === "function") soundSuccess();
      else if (typeof beepSuccess === "function") beepSuccess();

      setOutStatus(`Ausgebucht: ${ref} (Historie gespeichert)`, "success");

      // Scanner-Flow: ✅ LS stehen lassen, nur Ref leeren + restliche Info leeren
      const outRef = document.getElementById("outRef");
      const outSach = document.getElementById("outSach");
      const outPos = document.getElementById("outPos");

      if (outRef) outRef.value = "";
      if (outSach) outSach.value = "";
      if (outPos) outPos.value = "";

      closeOutbookModal();
      outRef?.focus();

    } catch (err) {
      console.error(err);
      if (typeof soundError === "function") soundError();
      else if (typeof beepError === "function") beepError();

      setOutStatus(err?.message || "Ausbuchen fehlgeschlagen.", "error");
      closeOutbookModal();
      document.getElementById("outRef")?.focus();
    }
  });
}

async function startOutbookFromRef() {
  const outRef  = document.getElementById("outRef");
  const outLs   = document.getElementById("outLs");
  const outSach = document.getElementById("outSach");
  const outPos  = document.getElementById("outPos");

  const ref = (outRef?.value || "").trim();
  const ls  = (outLs?.value  || "").trim();

  if (!ref) {
    setOutStatus("Bitte Referenznummer scannen/eingeben.", "error");
    return;
  }

  // Slot finden (Map bevorzugt, Fallback DOM)
  let slotEl = null;
  if (typeof _searchIndex !== "undefined" && _searchIndex?.refs?.get) {
    const arr = _searchIndex.refs.get(ref);
    if (arr && arr.length) slotEl = arr[0];
  }
  if (!slotEl) {
    slotEl = document.querySelector(`#w1-block-16-19 .palette-slot[data-ref="${cssEscape(ref)}"]`);
  }

  // ❗ Wenn nicht im Bestand: optional Status aus Historie anzeigen (falls Endpoint existiert)
  if (!slotEl) {
    try {
      const st = await fetchJson(
        `lager_ref_status.php?halle=${encodeURIComponent(window.currentHall || "H4")}` +
        `&zone=${encodeURIComponent(window.currentZone || "W1")}` +
        `&referenznr=${encodeURIComponent(ref)}`
      );

      if (st?.ok && st.status === "OUT" && st.data) {
  // ✅ NUR das Modal zeigen (zentriert)
  showHistoryOutModal(st.data);

  // ✅ Out-Status NICHT mehr als Text anzeigen
  setOutStatus("", "info");   // oder: document.getElementById("out-status").textContent = "";

  // optional: Referenzfeld direkt wieder bereit machen
  // document.getElementById("outRef").value = "";
  // document.getElementById("outRef")?.focus();

  if (typeof soundError === "function") soundError();
  else if (typeof beepError === "function") beepError();

  return;
}


    } catch (_) {
      // Endpoint ggf. nicht vorhanden -> ignorieren
    }

    if (typeof soundError === "function") soundError();
    else if (typeof beepError === "function") beepError();

    setOutStatus(`Nicht gefunden im Lager: ${ref}`, "error");

// ✅ nach 1 Sekunde Ref-Feld leeren (für schnelles Weiter-Scannen)
clearOutRefSoon(ref, 1000);

return;

  }

  // Gefunden -> Felder füllen + scroll + highlight
  const platzEl = slotEl.closest(".platz");
  const r = platzEl?.dataset.row || "?";
  const p = platzEl?.dataset.platz || "??";
  const sHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);

  if (outSach) outSach.value = slotEl.dataset.sach || "";
  if (outPos)  outPos.value  = `R${r}-${p}-${sHuman}`;

  highlightPlatz?.(platzEl);
  platzEl?.scrollIntoView({ behavior: "smooth", block: "center" });
  blinkPlatzGreen?.(platzEl);

  _outbookSlot = slotEl;
  openOutbookModal(slotEl, ls);
}

function openOutbookModal(slotEl, lsFallback = "") {
  const modal = document.getElementById("outbookModal");
  if (!modal || !slotEl) return;

  const platzEl = slotEl.closest(".platz");
  const r = platzEl?.dataset.row || "?";
  const p = platzEl?.dataset.platz || "??";
  const sHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);

  const ref  = slotEl.dataset.ref || "-";
  const sach = slotEl.dataset.sach || "-";
  const ls   = (document.getElementById("outLs")?.value || lsFallback || "-").trim();

  document.getElementById("outMRef").textContent  = ref;
  document.getElementById("outMSach").textContent = sach;
  document.getElementById("outMPos").textContent  = `R${r}-${p}-${sHuman}`;
  document.getElementById("outMLs").textContent   = ls || "-";

  modal.classList.remove("hidden");
  modal.classList.add("flex");
}

function closeOutbookModal() {
  const modal = document.getElementById("outbookModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  _outbookSlot = null;
}

function setOutStatus(msg, type="info") {
  const el = document.getElementById("out-status");
  if (!el) return;

  let cls = "text-[11px] ";
  if (type === "success") cls += "text-emerald-700";
  else if (type === "error") cls += "text-red-700";
  else cls += "text-slate-600";

  el.className = cls;
  el.textContent = msg;
}
async function outbookOnServer({ halle, zone, id, referenznr, ausgebucht_ls }) {
  const fd = new FormData();
  fd.append("halle", halle);
  fd.append("zone", zone);
  if (id) fd.append("id", String(id));
  if (referenznr) fd.append("referenznr", referenznr);
  if (ausgebucht_ls) fd.append("ausgebucht_ls", ausgebucht_ls);

  const data = await fetchJson("lager_outbook.php", { method: "POST", body: fd });

  if (data?.ok !== true) {
    // ✅ wenn schon OUT → Overlay öffnen
    if (data?.error === "already_out" && data?.data) {
      showHistoryOverlay(data.data);
    }
    throw new Error(data?.msg || data?.error || "Ausbuchen fehlgeschlagen.");
  }

  return data;
}

let _histPanel = null;

function initHistoryOverlay() {
  const input = document.getElementById("searchRefInput");
  if (!input) return;

  const wrap = input.parentElement;
  if (wrap) wrap.classList.add("relative");

  if (!document.getElementById("histStyles")) {
    const s = document.createElement("style");
    s.id = "histStyles";
    s.textContent = `
      .hist-pop { position:absolute; left:0; top:calc(100% + 8px); width:min(420px, 92vw); z-index:9999; }
    `;
    document.head.appendChild(s);
  }

  _histPanel = document.getElementById("histPanel");
  if (!_histPanel) {
    _histPanel = document.createElement("div");
    _histPanel.id = "histPanel";
    _histPanel.className = "hist-pop hidden bg-white border border-slate-300 rounded-xl shadow-lg";
    wrap.appendChild(_histPanel);
  }
}

async function tryShowRefStatus(ref) {
  try {
    const url =
      `lager_ref_status.php?halle=${encodeURIComponent(window.currentHall || "H4")}` +
      `&zone=${encodeURIComponent(window.currentZone || "W1")}` +
      `&referenznr=${encodeURIComponent(ref)}`;

    const st = await fetchJson(url);

    if (st?.ok && st.status === "OUT" && st.data) {
      showHistoryOverlay(st.data);
      soundError?.();
      return true;
    }

    if (st?.ok && st.status === "DELETED" && st.data) {
      showDeletedOverlay(st.data);
      setStatus(`Ref ${ref} wurde gelöscht (${st.data.deleted_by || "?"}).`, "error");
      soundError?.();
      return true;
    }
  } catch (e) {}
  return false;
}

// ===============================
// HISTORY OUT MODAL (zentriert)
// ===============================
let _histOutModalInited = false;

function ensureHistoryOutModal() {
  if (_histOutModalInited) return;
  _histOutModalInited = true;

  if (document.getElementById("historyOutModal")) return;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="historyOutModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[999999]">
      <div class="bg-white rounded-xl shadow-xl w-[95vw] max-w-md">
        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
          <div class="text-sm font-semibold text-slate-800" id="homTitle">Historie (OUT)</div>
          <button type="button" id="homClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
        </div>

        <div class="p-4 text-xs space-y-1" id="homBody"></div>

        <div class="px-4 pb-4 pt-2 flex gap-2 justify-end" id="homActions"></div>
      </div>
    </div>
  `);

  const modal = document.getElementById("historyOutModal");

  document.getElementById("homClose")?.addEventListener("click", hideHistoryOutModal);
  modal?.addEventListener("click", (e) => {
    if (e.target === modal) hideHistoryOutModal();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") hideHistoryOutModal();
  });
}

function hideHistoryOutModal() {
  const modal = document.getElementById("historyOutModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");

  // Fokus zurück ins Warenausgang-Ref (damit Scannen schnell bleibt)
  document.getElementById("outRef")?.focus();
}

function showHistoryOutModal(data) {
  ensureHistoryOutModal();

  const modal   = document.getElementById("historyOutModal");
  const titleEl = document.getElementById("homTitle");
  const bodyEl  = document.getElementById("homBody");
  const actEl   = document.getElementById("homActions");
  if (!modal || !titleEl || !bodyEl || !actEl) return;

  const ref = data?.referenznr || "-";
  const sach = data?.sachnummer || "-";
  const outAm = data?.ausgebucht_am || "-";
  const outLs = data?.ausgebucht_ls || "-";
  const outUser = data?.ausgebucht_user || "-";
  const lastPos = `R${data?.reihe || "?"}-${String(data?.platz ?? "??").padStart(2,"0")}-${(parseInt(data?.slot_index ?? 0,10)+1)}`;

  titleEl.textContent = "Historie (OUT)";

  bodyEl.innerHTML = `
    <div>Ref: <strong>${escapeHtml(ref)}</strong></div>
    <div>Sach: <strong>${escapeHtml(sach)}</strong></div>
    <div>Letzter Platz: <strong>${escapeHtml(lastPos)}</strong></div>
    <div>Versendet am: <strong>${escapeHtml(outAm)}</strong></div>
    <div>LS: <strong>${escapeHtml(outLs)}</strong></div>
    <div>User: <strong>${escapeHtml(outUser)}</strong></div>
  `;

  // Actions
  actEl.innerHTML = `
    <button type="button" class="bg-slate-100 text-slate-800 text-[11px] font-semibold px-3 py-2 rounded border" id="homSearch">
      Im Plan suchen
    </button>
    ${typeof startRebookFlow === "function" ? `
    <button type="button" class="bg-slate-800 text-white text-[11px] font-semibold px-3 py-2 rounded" id="homRebook">
      Rückbuchen
    </button>` : ``}
    <button type="button" class="bg-emerald-600 text-white text-[11px] font-semibold px-3 py-2 rounded" id="homOk">
      OK
    </button>
  `;

  document.getElementById("homOk")?.addEventListener("click", hideHistoryOutModal);

  document.getElementById("homSearch")?.addEventListener("click", () => {
    hideHistoryOutModal();
    const inp = document.getElementById("searchRefInput");
    if (inp) inp.value = ref;
    if (typeof searchQuery === "function") searchQuery(ref);
  });

  document.getElementById("homRebook")?.addEventListener("click", () => {
    // nutzt deine bestehende Rückbuchen-Logik
    hideHistoryOutModal();
    startRebookFlow?.(data);
  });

  modal.classList.remove("hidden");
  modal.classList.add("flex");
}


function hideHistoryOverlay() {
  if (!_histPanel) return;
  _histPanel.classList.add("hidden");
  _histPanel.innerHTML = "";
}

function showHistoryOverlay(data) {
  if (!_histPanel) initHistoryOverlay();
  if (!_histPanel) return;

  const ref = data.referenznr || "-";
  const sach = data.sachnummer || "-";
  const outAm = data.ausgebucht_am || "-";
  const outLs = data.ausgebucht_ls || "-";
  const outUser = data.ausgebucht_user || "-";
  const lastPos = `R${data.reihe}-${String(data.platz).padStart(2,"0")}-${(parseInt(data.slot_index,10)+1)}`;

  _histPanel.innerHTML = `
    <div class="flex items-center justify-between px-3 py-2 border-b border-slate-200">
      <div class="text-xs font-semibold text-slate-800">Historie (OUT)</div>
      <button type="button" class="text-slate-600 hover:text-slate-900 text-lg leading-none" id="histClose">×</button>
    </div>
    <div class="p-3 text-xs space-y-1">
      <div>Ref: <strong>${escapeHtml(ref)}</strong></div>
      <div>Sach: <strong>${escapeHtml(sach)}</strong></div>
      <div>Letzter Platz: <strong>${escapeHtml(lastPos)}</strong></div>
      <div>Versendet am: <strong>${escapeHtml(outAm)}</strong></div>
      <div>LS: <strong>${escapeHtml(outLs)}</strong></div>
      <div>User: <strong>${escapeHtml(outUser)}</strong></div>

      <div class="pt-2 flex gap-2">
        <button type="button" class="bg-slate-800 text-white text-[11px] font-semibold px-3 py-1 rounded" id="btnRebook">
          Rückbuchen
        </button>
        <button type="button" class="bg-slate-100 text-slate-800 text-[11px] font-semibold px-3 py-1 rounded border" id="btnHistSearch">
          Im Plan suchen
        </button>
      </div>
    </div>
  `;

  _histPanel.classList.remove("hidden");
  _histPanel.querySelector("#histClose")?.addEventListener("click", hideHistoryOverlay);

  _histPanel.querySelector("#btnHistSearch")?.addEventListener("click", () => {
    hideHistoryOverlay();
    const inp = document.getElementById("searchRefInput");
    if (inp) inp.value = ref;
    searchQuery(ref);
  });

_histPanel.querySelector("#btnRebook")?.addEventListener("click", () => {
  startRebookFlow(data);
});

}

/* =========================================================
   STATUS MODAL (OUT + DELETED) – zentriert
   nutzt die Daten aus lager_ref_status.php
========================================================= */

function ensureHistoryStatusModalDom() {
  let modal = document.getElementById("histStatusModal");
  if (modal) return modal;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="histStatusModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 z-[999999]">
      <div class="bg-white rounded-xl shadow-xl w-[95vw] max-w-md p-4">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm font-semibold text-slate-800" id="hsmTitle">Historie</div>
          <button type="button" id="hsmClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
        </div>

        <div id="hsmBody" class="text-xs text-slate-700 space-y-1"></div>

        <div class="mt-3 flex justify-end gap-2">
  <button type="button" id="hsmRebook"
    class="hidden bg-emerald-600 text-white text-xs font-semibold px-3 py-2 rounded">
    Wieder einlagern
  </button>

  <button type="button" id="hsmOk"
    class="bg-slate-800 text-white text-xs font-semibold px-3 py-2 rounded">
    OK
  </button>
</div>

      </div>
    </div>
  `);

  modal = document.getElementById("histStatusModal");

  const close = () => hideHistoryStatusModal();

  modal.querySelector("#hsmClose")?.addEventListener("click", close);
  modal.querySelector("#hsmOk")?.addEventListener("click", close);

  // Klick auf Backdrop schließen
  modal.addEventListener("click", (e) => {
    if (e.target === modal) close();
  });

  // ESC schließen
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") close();
  });

  return modal;
}

function hideHistoryStatusModal() {
  const modal = document.getElementById("histStatusModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
}

function showHistoryStatusModal(type, data) {
  const modal = ensureHistoryStatusModalDom();

  const t = String(type || "").toUpperCase();
  const ref  = data?.referenznr || "-";
  const sach = data?.sachnummer || "-";

  const row = data?.reihe ?? data?.row ?? "-";
  const platz = data?.platz ?? data?.platz_no ?? "-";
  const slotIndex = (data?.slot_index ?? data?.slotIndex ?? 0);
  const lastPos = `R${row}-${String(platz).padStart(2,"0")}-${(parseInt(slotIndex,10)+1)}`;

  // OUT-Felder
  const outAm   = data?.ausgebucht_am || "-";
  const outLs   = data?.ausgebucht_ls || "-";
  const outUser = data?.ausgebucht_user || "-";

  // DELETED-Felder
  const delAt = data?.deleted_at || "-";
  const delBy = data?.deleted_by || "-";

  const title =
    t === "OUT" ? "Historie: AUSGEBUCHT" :
    t === "DELETED" ? "Historie: ARCHIVIERT (GELÖSCHT)" :
    "Historie";

  const badge =
    t === "OUT"
      ? `<span class="inline-flex items-center rounded bg-red-100 text-red-700 px-2 py-0.5 text-[11px] font-semibold">OUT</span>`
      : t === "DELETED"
      ? `<span class="inline-flex items-center rounded bg-slate-200 text-slate-700 px-2 py-0.5 text-[11px] font-semibold">DELETED</span>`
      : "";

  modal.querySelector("#hsmTitle").innerHTML = `${escapeHtml(title)} ${badge}`;

  let body = "";
  body += `<div>Ref: <strong>${escapeHtml(ref)}</strong></div>`;
  body += `<div>Sach: <strong>${escapeHtml(sach)}</strong></div>`;
  body += `<div>Letzte Position: <strong>${escapeHtml(lastPos)}</strong></div>`;

  if (t === "OUT") {
    body += `<div class="mt-2 pt-2 border-t border-slate-200"></div>`;
    body += `<div>Versendet am: <strong>${escapeHtml(outAm)}</strong></div>`;
    body += `<div>LS: <strong>${escapeHtml(outLs)}</strong></div>`;
    body += `<div>User: <strong>${escapeHtml(outUser)}</strong></div>`;
  }

  if (t === "DELETED") {
    body += `<div class="mt-2 pt-2 border-t border-slate-200"></div>`;
    body += `<div>Gelöscht am: <strong>${escapeHtml(delAt)}</strong></div>`;
    body += `<div>Gelöscht von: <strong>${escapeHtml(delBy)}</strong></div>`;
  }

  modal.querySelector("#hsmBody").innerHTML = body;

  modal.classList.remove("hidden");
  modal.classList.add("flex");
}


function showDeletedModal(data){ showHistoryStatusModal("DELETED", data); }


function looksLikeRef(q) {
  q = String(q || "").trim();
  if (!q) return false;
  // grob: Referenzen sind meist länger/nummerisch – passt du bei Bedarf an
  return q.length >= 6;
}

// ✅ Heuristik: sieht aus wie eine Referenznummer?
function isProbablyRef(q) {
  const s = String(q || "").trim();
  if (s.length < 4) return false;
  // typisch: viele Ziffern oder alphanumerisch ohne Leerzeichen
  return /^[A-Za-z0-9\-_.]+$/.test(s) && !s.includes(" ");
}

// ✅ Neues Overlay (DELETED)
function showDeletedOverlay(data) {
  // nutzt denselben Panel-Container wie Historie
  if (typeof initHistoryOverlay === "function") initHistoryOverlay();
  if (typeof hideHistoryOverlay === "function") hideHistoryOverlay();

  const ref = data.referenznr || "-";
  const sach = data.sachnummer || "-";
  const delAt = data.deleted_at || "-";
  const delBy = data.deleted_by || "-";
  const lastPos = `R${data.reihe}-${String(data.platz).padStart(2,"0")}-${(parseInt(data.slot_index,10)+1)}`;

  // _histPanel wird bei dir in initHistoryOverlay() gebaut
  const panel = document.getElementById("histPanel");
  if (!panel) return;

  panel.innerHTML = `
    <div class="flex items-center justify-between px-3 py-2 border-b border-slate-200">
      <div class="text-xs font-semibold text-slate-800">Status: GELÖSCHT</div>
      <button type="button" class="text-slate-600 hover:text-slate-900 text-lg leading-none" id="histClose">×</button>
    </div>
    <div class="p-3 text-xs space-y-1">
      <div>Ref: <strong>${escapeHtml(ref)}</strong></div>
      <div>Sach: <strong>${escapeHtml(sach)}</strong></div>
      <div>Letzte Position: <strong>${escapeHtml(lastPos)}</strong></div>
      <div>Gelöscht am: <strong>${escapeHtml(delAt)}</strong></div>
      <div>Gelöscht von: <strong>${escapeHtml(delBy)}</strong></div>
    </div>
  `;

  panel.classList.remove("hidden");
  panel.querySelector("#histClose")?.addEventListener("click", () => {
  if (typeof hideHistoryOverlay === "function") hideHistoryOverlay();
  else panel.classList.add("hidden");
});

}




function findBestFreeSlotForRebook(h) {
  const row = String(h.reihe || "").trim();
  const platz = parseInt(h.platz || "0", 10);
  const slotIndex = parseInt(h.slot_index || "0", 10);

  if (!row || !platz) return null;

  const platzEl = document.querySelector(`#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${String(platz).padStart(2,"0")}"]`);
  if (!platzEl) return null;

  const slots = Array.from(platzEl.querySelectorAll(".palette-slot"));

  // 1) gleicher Slot frei?
  const exact = slots.find(s => parseInt(s.dataset.slotIndex,10) === slotIndex && !s.dataset.ref);
  if (exact) return { platzEl, slotEl: exact, row, platz, slotIndex };

  // 2) irgendein Slot am gleichen Platz frei?
  const any = slots.find(s => !s.dataset.ref);
  if (any) return { platzEl, slotEl: any, row, platz, slotIndex: parseInt(any.dataset.slotIndex,10) };

  // 3) nächster freier Platz in der Reihe (nutzt deine Funktion, falls vorhanden)
  if (typeof findNextFreePlatzInRow === "function") {
    const next = findNextFreePlatzInRow(row, platz + 1);
    if (next?.platzEl && next?.slotEl) {
      return {
        platzEl: next.platzEl,
        slotEl: next.slotEl,
        row,
        platz: next.platz,
        slotIndex: parseInt(next.slotEl.dataset.slotIndex,10)
      };
    }
  }

  return null;
}
/* =========================================================
   REBOOK FLOW: Wenn alter Platz/Slot belegt -> Nachfrage
   - Ja  => vorgeschlagenes Ziel buchen
   - Nein=> manuell Ziel-Reihe/Platz wählen
========================================================= */

let _rebookCtx = null; // { history, ref, sach, suggestedTarget }

function fmtPos(row, platz, slotIndex) {
  const p = String(platz).padStart(2, "0");
  const s = (parseInt(slotIndex,10) + 1);
  return `R${row}-${p}-${s}`;
}

function ensureRebookModals() {
  if (document.getElementById("rebookChoiceModal")) return;

  const wrap = document.createElement("div");
  wrap.innerHTML = `
  <!-- Rebook Choice Modal -->
  <div id="rebookChoiceModal" class="hidden fixed inset-0 z-[10000] items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-[92vw] max-w-md p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold text-slate-800 text-sm">Rückbuchen</div>
        <button type="button" id="rcmClose" class="text-slate-600 hover:text-slate-900 text-lg leading-none">×</button>
      </div>

      <div class="text-xs text-slate-700 space-y-2">
        <div id="rcmText"></div>
        <div class="p-2 rounded bg-slate-50 border border-slate-200">
          <div class="text-[11px] text-slate-500">Alter Platz</div>
          <div class="font-semibold" id="rcmOld">-</div>
          <div class="text-[11px] text-slate-500 mt-2">Vorschlag</div>
          <div class="font-semibold" id="rcmNew">-</div>
        </div>
      </div>

      <div class="mt-3 flex gap-2 justify-end">
        <button type="button" id="rcmNo" class="px-3 py-1.5 text-xs font-semibold rounded border border-slate-300 bg-white hover:bg-slate-50">
          Nein (manuell wählen)
        </button>
        <button type="button" id="rcmYes" class="px-3 py-1.5 text-xs font-semibold rounded bg-slate-800 text-white hover:bg-slate-900">
          Ja (Vorschlag buchen)
        </button>
      </div>
    </div>
  </div>

  <!-- Rebook Manual Modal -->
  <div id="rebookManualModal" class="hidden fixed inset-0 z-[10000] items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-[92vw] max-w-md p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold text-slate-800 text-sm">Rückbuchen – Ziel manuell wählen</div>
        <button type="button" id="rmmClose" class="text-slate-600 hover:text-slate-900 text-lg leading-none">×</button>
      </div>

      <div class="text-xs text-slate-700 space-y-2">
        <div class="p-2 rounded bg-slate-50 border border-slate-200">
          <div class="text-[11px] text-slate-500">Artikel</div>
          <div class="font-semibold" id="rmmRef">-</div>
          <div class="text-[11px] text-slate-500">Sachnummer</div>
          <div class="font-semibold" id="rmmSach">-</div>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-[11px] text-slate-600 mb-1">Ziel-Reihe</label>
            <select id="rmmRow" class="w-full border border-slate-300 rounded px-2 py-1 text-xs">
  <option value="">– wählen –</option>
  <option value="16">16</option>
  <option value="17">17</option>
  <option value="18">18</option>
  <option value="19">19</option>
  <option value="20">20</option>
</select>

          </div>
          <div>
            <label class="block text-[11px] text-slate-600 mb-1">Ziel-Platz</label>
            <input id="rmmPlatz" class="w-full border border-slate-300 rounded px-2 py-1 text-xs" placeholder="z.B. 29">
          </div>
        </div>

        <div class="text-[11px] text-slate-500" id="rmmHint">Tipp: Eingabe markiert den Platz im Plan.</div>
        <div class="text-[11px] mt-1" id="rmmStatus"></div>
      </div>

      <div class="mt-3 flex gap-2 justify-end">
        <button type="button" id="rmmCancel" class="px-3 py-1.5 text-xs font-semibold rounded border border-slate-300 bg-white hover:bg-slate-50">
          Abbrechen
        </button>
        <button type="button" id="rmmSave" class="px-3 py-1.5 text-xs font-semibold rounded bg-emerald-600 text-white hover:bg-emerald-700">
          Buchen
        </button>
      </div>
    </div>
  </div>
  `;
  document.body.appendChild(wrap);

  // Choice modal events
  const cModal = document.getElementById("rebookChoiceModal");
  document.getElementById("rcmClose")?.addEventListener("click", closeRebookChoiceModal);
  cModal?.addEventListener("click", (e) => { if (e.target === cModal) closeRebookChoiceModal(); });

  document.getElementById("rcmYes")?.addEventListener("click", async () => {
    if (!_rebookCtx?.suggestedTarget) return;
    await doRebookToTarget(_rebookCtx.suggestedTarget, _rebookCtx.history);
    closeRebookChoiceModal();
  });

  document.getElementById("rcmNo")?.addEventListener("click", () => {
    closeRebookChoiceModal();
    openRebookManualModal(_rebookCtx?.history, _rebookCtx?.suggestedTarget);
  });

  // Manual modal events
  const mModal = document.getElementById("rebookManualModal");
  document.getElementById("rmmClose")?.addEventListener("click", closeRebookManualModal);
  document.getElementById("rmmCancel")?.addEventListener("click", closeRebookManualModal);
  mModal?.addEventListener("click", (e) => { if (e.target === mModal) closeRebookManualModal(); });

  document.getElementById("rmmRow")?.addEventListener("change", () => {
  highlightRebookManualTarget();
  updateRebookManualAvailability();
});
document.getElementById("rmmPlatz")?.addEventListener("input", () => {
  highlightRebookManualTarget();
  updateRebookManualAvailability();
});

  document.getElementById("rmmSave")?.addEventListener("click", async () => {
    const row = String(document.getElementById("rmmRow")?.value || "").trim();
    const platzRaw = String(document.getElementById("rmmPlatz")?.value || "").trim();
    if (!row || !platzRaw) {
      setStatus("Bitte Ziel-Reihe und Ziel-Platz eingeben.", "error");
      return;
    }

    const platz = parseInt(platzRaw, 10);
    const platzEl = document.querySelector(
      `#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${String(platz).padStart(2,"0")}"]`
    );
    if (!platzEl) {
      setStatus(`Zielplatz R${row} / P${String(platz).padStart(2,"0")} nicht gefunden.`, "error");
      return;
    }

    const slotEl = Array.from(platzEl.querySelectorAll(".palette-slot")).find(s => !s.dataset.ref);
    if (!slotEl) {
      flashPlatz?.(platzEl, "error");
      setStatus("Zielplatz ist voll (4/4). Bitte anderen Platz wählen.", "error");
      return;
    }

    const target = { platzEl, slotEl, row, platz, slotIndex: parseInt(slotEl.dataset.slotIndex,10) };
    await doRebookToTarget(target, _rebookCtx?.history);
    closeRebookManualModal();
  });
}

function clamp(n, min, max){ return Math.max(min, Math.min(max, n)); }

function positionInfoBubble(anchorEl){
  const info = document.getElementById("lager-info");
  if (!info || !anchorEl) return;

  info.style.position = "fixed";     // wichtig!
  info.style.zIndex = "999999";

  requestAnimationFrame(() => {
    const r = anchorEl.getBoundingClientRect();

    let left = r.right + 10;
    let top  = r.top;

    const pad = 8;
    const maxLeft = window.innerWidth  - info.offsetWidth  - pad;
    const maxTop  = window.innerHeight - info.offsetHeight - pad;

    if (left > maxLeft) left = r.left - info.offsetWidth - 10;  // nach links

    // wenn unten kein Platz: oberhalb anzeigen
    if (top > maxTop) top = r.bottom - info.offsetHeight;

    info.style.left = clamp(left, pad, maxLeft) + "px";
    info.style.top  = clamp(top,  pad, maxTop)  + "px";
  });
}

function openRebookChoiceModal(oldPos, newPos) {
  ensureRebookModals();
  document.getElementById("rcmText").textContent =
    "Der alte Platz ist belegt. Sollen wir den vorgeschlagenen Platz nehmen?";
  document.getElementById("rcmOld").textContent = oldPos;
  document.getElementById("rcmNew").textContent = newPos;

  const modal = document.getElementById("rebookChoiceModal");
  modal.classList.remove("hidden");
  modal.classList.add("flex");
}

function closeRebookChoiceModal() {
  const modal = document.getElementById("rebookChoiceModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
}

function openRebookManualModal(history, suggestedTarget) {
  ensureRebookModals();

  const ref  = history?.referenznr || "-";
  const sach = history?.sachnummer || "-";

  document.getElementById("rmmRef").textContent = ref;
  document.getElementById("rmmSach").textContent = sach;

  // Prefill: Vorschlag, wenn vorhanden – sonst alter Platz
  const rowPref = (suggestedTarget?.row || history?.reihe || "");
  const plzPref = (suggestedTarget?.platz || history?.platz || "");

  document.getElementById("rmmRow").value = String(rowPref);
  document.getElementById("rmmPlatz").value = plzPref ? String(plzPref) : "";

  const modal = document.getElementById("rebookManualModal");
  modal.classList.remove("hidden");
  modal.classList.add("flex");

  setTimeout(highlightRebookManualTarget, 30);
  setTimeout(updateRebookManualAvailability, 40);
}

function closeRebookManualModal() {
  const modal = document.getElementById("rebookManualModal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.classList.remove("flex");
}

function highlightRebookManualTarget() {
  const row = String(document.getElementById("rmmRow")?.value || "").trim();
  const pRaw = String(document.getElementById("rmmPlatz")?.value || "").trim();
  if (!row || !pRaw) return;

  const p = String(parseInt(pRaw,10) || "").padStart(2,"0");
  const el = document.querySelector(`#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${cssEscape(p)}"]`);
  if (el) {
    highlightPlatz?.(el);
    el.scrollIntoView({ behavior:"smooth", block:"center" });
    blinkPlatzGreen?.(el);
  }
}
function startRebookFlow(history) {
  ensureRebookModals();

  const ref  = String(history?.referenznr || "").trim();
  const sach = String(history?.sachnummer || "").trim();
  if (!ref || !sach) {
    setStatus("Rückbuchen nicht möglich: Historie-Daten unvollständig.", "error");
    return;
  }

  // 1) gewünschten alten Platz prüfen
  const row = String(history?.reihe || "").trim();
  const platz = parseInt(history?.platz || "0", 10);
  const slotIndex = parseInt(history?.slot_index || "0", 10);

  const oldPos = fmtPos(row, platz, slotIndex);

  const platzEl = document.querySelector(
    `#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${String(platz).padStart(2,"0")}"]`
  );

  // Wenn Platz nicht existiert -> direkt manuell
  if (!platzEl) {
    _rebookCtx = { history, ref, sach, suggestedTarget: null };
    openRebookManualModal(history, null);
    return;
  }

  const slots = Array.from(platzEl.querySelectorAll(".palette-slot"));

  // a) exakt gleicher Slot frei? -> ohne Nachfrage buchen
  const exact = slots.find(s => parseInt(s.dataset.slotIndex,10) === slotIndex && !s.dataset.ref);
  if (exact) {
    const target = { platzEl, slotEl: exact, row, platz, slotIndex };
    doRebookToTarget(target, history);
    return;
  }

  // b) sonst Vorschlag im gleichen Platz: erster freier Slot
  const anyFreeSamePlatz = slots.find(s => !s.dataset.ref);
  if (anyFreeSamePlatz) {
    const sugIdx = parseInt(anyFreeSamePlatz.dataset.slotIndex,10);
    const suggested = { platzEl, slotEl: anyFreeSamePlatz, row, platz, slotIndex: sugIdx };

    _rebookCtx = { history, ref, sach, suggestedTarget: suggested };

    // Frage: alter Slot belegt -> soll anderer Slot am selben Platz genutzt werden?
    openRebookChoiceModal(oldPos, fmtPos(row, platz, sugIdx));
    return;
  }

  // c) Platz voll -> nächster freier Platz in Reihe (deine Funktion)
  let next = null;
  if (typeof findNextFreePlatzInRow === "function") {
    next = findNextFreePlatzInRow(row, platz + 1);
  }
  if (next?.platzEl && next?.slotEl) {
    const suggested = {
      platzEl: next.platzEl,
      slotEl: next.slotEl,
      row,
      platz: next.platz,
      slotIndex: parseInt(next.slotEl.dataset.slotIndex,10)
    };

    _rebookCtx = { history, ref, sach, suggestedTarget: suggested };
    openRebookChoiceModal(oldPos, fmtPos(row, next.platz, suggested.slotIndex));
    return;
  }

  // nix frei
  setStatus("Rückbuchen nicht möglich: alter Platz voll und kein freier Folgeplatz gefunden.", "error");
}
async function doRebookToTarget(target, history) {
  const ref  = String(history?.referenznr || "").trim();
  const sach = String(history?.sachnummer || "").trim();

  if (!target?.platzEl || !target?.slotEl) {
    setStatus("Rückbuchen nicht möglich: kein gültiges Ziel.", "error");
    return;
  }

  try {
    await saveSlotToServer({
      halle: window.currentHall || "H4",
      zone:  window.currentZone || "W1",
      batch_id: "",
      reihe: target.row,
      platz: target.platz,
      slot_index: target.slotIndex,
      referenznr: ref,
      sachnummer: sach
    });

    applySlotToUI(target.slotEl, { ref, sach, lieferschein: "", user: window.currentUserName || "" });
    updatePlatzLabel(target.platzEl);
    afterPlanChange();

    hideHistoryOverlay?.();

    highlightPlatz?.(target.platzEl);
    target.platzEl.scrollIntoView({ behavior:"smooth", block:"center" });
    pulsePlatz1s?.(target.platzEl);

    setStatus(`Rückgebucht: ${ref} → ${fmtPos(target.row, target.platz, target.slotIndex)}`, "success");
    soundSuccess?.();
  } catch (e) {
    console.error(e);
    setStatus(e.message || "Rückbuchen fehlgeschlagen.", "error");
    soundError?.();
  }
}
function updateRebookManualAvailability() {
  const row = String(document.getElementById("rmmRow")?.value || "").trim();
  const pRaw = String(document.getElementById("rmmPlatz")?.value || "").trim();
  const statusEl = document.getElementById("rmmStatus");
  const saveBtn = document.getElementById("rmmSave");

  if (!statusEl || !saveBtn) return;

  if (!row || !pRaw) {
    statusEl.textContent = "";
    saveBtn.disabled = false;
    saveBtn.classList.remove("opacity-50", "cursor-not-allowed");
    return;
  }

  const pNum = parseInt(pRaw, 10);
  const p = String(pNum || "").padStart(2, "0");

  const platzEl = document.querySelector(
    `#w1-block-16-19 .platz[data-row="${cssEscape(row)}"][data-platz="${cssEscape(p)}"]`
  );

  if (!platzEl) {
    statusEl.textContent = `Zielplatz R${row} / P${p} nicht gefunden`;
    statusEl.className = "text-[11px] mt-1 text-red-700";
    saveBtn.disabled = true;
    saveBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  const free = Array.from(platzEl.querySelectorAll(".palette-slot")).find(s => !s.dataset.ref);

  if (!free) {
    const cap = platzEl.querySelectorAll(".palette-slot").length;
statusEl.textContent = `Ziel ist voll (${cap}/${cap})`;

    statusEl.className = "text-[11px] mt-1 text-red-700";
    saveBtn.disabled = true;
    saveBtn.classList.add("opacity-50", "cursor-not-allowed");
    flashPlatz?.(platzEl, "error");
    return;
  }

  statusEl.textContent = `Ziel frei → Slot ${parseInt(free.dataset.slotIndex,10) + 1} wird genutzt`;
  statusEl.className = "text-[11px] mt-1 text-emerald-700";
  saveBtn.disabled = false;
  saveBtn.classList.remove("opacity-50", "cursor-not-allowed");
}
async function moveSlotOnServer({ halle, zone, id, to_row, to_platz }) {
  const fd = new FormData();
  fd.append("halle", halle);
  fd.append("zone", zone);
  fd.append("id", String(id));
  fd.append("to_row", String(to_row));
  fd.append("to_platz", String(to_platz));

  const res = await fetch("lager_move.php", { method: "POST", body: fd });
  const text = await res.text();

  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    console.error("❌ MOVE: Kein JSON. HTTP:", res.status, "Body:", text);
    throw new Error(`Umbuchen: Server liefert kein JSON (HTTP ${res.status}).`);
  }

  if (!res.ok || data.ok !== true) {
    console.error("❌ MOVE: Server-Error", res.status, data);
    throw new Error(data?.msg || data?.error || `Umbuchen fehlgeschlagen (HTTP ${res.status}).`);
  }

  if (!data.to || typeof data.to.slot_index === "undefined") {
    console.error("❌ MOVE: Unerwartete Struktur:", data);
    throw new Error("Umbuchen fehlgeschlagen: Serverantwort unvollständig (to.slot_index fehlt).");
  }

  return data;
}
function openSearchDropdown() {
  if (!_searchDD) return;
  _searchDD.classList.remove("d-none");
  _searchDDState.open = true;
}
function closeSearchDropdown() {
  if (!_searchDD) return;
  _searchDD.classList.add("d-none");
  _searchDDState.open = false;
  _searchDDState.items = [];
  _searchDDState.active = -1;
}
function slotCapacityForRow(row) {
  return String(row) === "20" ? 20 : 4;
}
function slotTitleForRow(row, slotIndex) {
  const idx = parseInt(slotIndex, 10);
  if (String(row) === "20") {
    return `20-${String(idx + 1).padStart(2, "0")}`; // 20-01..20-20
  }
  return `Slot ${idx + 1}`; // Slot 1..4
}
function getCartonStatsFromCache(slotEl){
  const slotId = parseInt(slotEl.dataset.slotId || "0", 10);
  if (!slotId) return { count: 0, qty: 0 };

  const items = _slotItemsById.get(slotId);
  if (!items) return { count: 0, qty: 0 };

  const count = items.length;
  const qty = items.reduce((sum, it) => sum + (parseInt(it.menge || "1", 10) || 1), 0);
  return { count, qty };
}
function updateCartonButtonInOverlay(slotEl){
  const infoDiv = document.getElementById("lager-info");
  const btn = infoDiv?.querySelector("#btnCartons");
  if (!btn) return;

  const count = slotEl.dataset.itemsCount || "0";
  // wenn du lieber Stückzahl zeigen willst: slotEl.dataset.itemsQty
  btn.textContent = `Kartons scannen (${count})`;
}
async function ensureCartonStats(slotEl){
  const slotId = parseInt(slotEl.dataset.slotId || "0", 10);
  if (!slotId) return;

  // Cache vorhanden? dann direkt setzen
  const cached = getCartonStatsFromCache(slotEl);
  if (cached.count > 0 || cached.qty > 0) {
    slotEl.dataset.itemsCount = String(cached.count);
    slotEl.dataset.itemsQty   = String(cached.qty);
    updateCartonButtonInOverlay(slotEl);
    return;
  }

  // sonst nachladen (nutzt deine bestehende API)
  await loadCartonsForSlot(slotEl);

  // loadCartonsForSlot ruft updateCartonUI -> die setzt dataset + UI
  updateCartonButtonInOverlay(slotEl);
}

function normalizePack(v) {
  const x = String(v ?? "").trim();
  return x !== "" ? x : "UNBEKANNT";
}

function isOccupiedSlot(slot) {
  const ref  = String(slot.dataset.ref ?? slot.dataset.referenznr ?? "").trim();
  const sach = String(slot.dataset.sach ?? slot.dataset.sachnummer ?? "").trim();

  if (ref || sach) return true;

  // Fallback, falls du Klassen nutzt
  if (slot.classList.contains("occupied") || slot.classList.contains("is-occupied")) return true;

  return false;
}

function buildRowPackText(platzContainerEl) {
  const usedSlots = Array.from(
    platzContainerEl.querySelectorAll(".palette-slot.palette-slot-used, .palette-slot[data-ref]")
  );

  if (!usedSlots.length) return "Verpackung: —";

  const counts = new Map();
  let unknown = 0;

  for (const slot of usedSlots) {
    const pack = String(slot.dataset.verpackung || "").trim();
    if (!pack) {
      unknown++;
      continue;
    }
    counts.set(pack, (counts.get(pack) || 0) + 1);
  }

  if (counts.size === 0) return `Verpackung: UNBEKANNT (${unknown})`;

  const parts = [...counts.entries()]
    .sort((a, b) => b[1] - a[1])
    .map(([pack, cnt]) => `${pack} (${cnt})`);

  if (unknown > 0) parts.push(`UNBEKANNT (${unknown})`);

  return `Verpackung: ${parts.join(" · ")}`;
}

function refreshRowPackInfo(row) {
  const r = String(row ?? "").trim();
  if (!r) return;

  const pc  = document.querySelector(`.platz-container[data-row="${cssEscape(r)}"]`);
  const out = document.querySelector(`[data-row-pack-info][data-row="${cssEscape(r)}"]`);

  if (!pc || !out) return;
  out.textContent = buildRowPackText(pc);
}

function refreshAllRowPackInfo() {
  document.querySelectorAll(".platz-container[data-row]").forEach(pc => {
    refreshRowPackInfo(pc.dataset.row);
  });
}

// ===============================
// PRINT: Pro Reihe Button + Druckansicht
// ===============================
function injectRowPrintButtons() {
  // doppelte Toolbars verhindern
  document.querySelectorAll('.platz-container[data-row]').forEach(pc => {
    const row = String(pc.dataset.row || "").trim();
    if (!row) return;

    // schon vorhanden?
    if (
      pc.previousElementSibling?.classList?.contains("row-toolbar") &&
      pc.previousElementSibling?.dataset?.row === row
    ) {
      refreshRowPackInfo(row);
      return;
    }
    if (document.querySelector(`.row-toolbar[data-row="${cssEscape(row)}"]`)) {
      refreshRowPackInfo(row);
      return;
    }

    const toolbar = document.createElement("div");
    toolbar.className = "row-toolbar d-flex align-items-center justify-content-between px-2 py-1 border-bottom bg-white";
    toolbar.dataset.row = row;

    toolbar.innerHTML = `
      <div class="w-100">

        <!-- Zeile 1: Titel -->
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-semibold text-truncate" style="font-size:11px;color:#334155; max-width: 100%;">
            ${rowDisplay(row)}
          </div>

          <div class="form-check form-switch m-0" title="Inventur: Reihe markiert">
            <input class="form-check-input inv-check" type="checkbox" role="switch" data-inv-row="${row}">
          </div>
        </div>

        <!-- Zeile 2: Verpackung -->
        <div class="small text-muted mt-1" data-row-pack-info="1" data-row="${row}">Verpackung: —</div>



        <!-- Zeile 3: Inventur-Status (nur wenn Inventur-Live aktiv) -->
        <div class="inv-live-only mt-1 d-none">
          <span class="inv-status" data-inv-status-row="${row}">
            <span class="inv-b inv-b-miss">COUNT —</span>
            <span class="inv-b inv-b-miss">CHECK —</span>
          </span>
        </div>

        <!-- Zeile 4: Buttons -->
        <div class="d-flex gap-1 mt-1 flex-wrap">
          <button type="button"
            class="btn btn-outline-primary btn-sm py-0 px-2"
            data-rename-row="${row}"
            title="Reihe umbenennen">
            <i class="bi bi-pencil"></i>
          </button>

          <button type="button"
            class="btn btn-outline-secondary btn-sm py-0 px-2"
            data-print-row="${row}">
            Drucken
          </button>

          <button type="button"
            class="btn btn-outline-dark btn-sm py-0 px-2"
            data-inv-label-row="${row}"
            title="Etikett drucken">
            🏷
          </button>
        </div>

      </div>
    `;

    // erst ins DOM, dann refresh
    pc.insertAdjacentElement("beforebegin", toolbar);
    refreshRowPackInfo(row);
    window.refreshInventoryUI?.();
  });

  // Clicks binden
  document.querySelectorAll("[data-print-row]").forEach(btn => {
    if (btn.dataset.bound === "1") return;
    btn.dataset.bound = "1";
    btn.addEventListener("click", () => printRow(btn.dataset.printRow));
  });

  bindRowRenameButtons?.();

  // einmal global nachziehen
  refreshAllRowPackInfo();
}


let _renameRow = null;

function ensureRowRenameModalDom() {
  if (document.getElementById("rowRenameModal")) return;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="rowRenameModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[999999]">
      <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-slate-800 text-sm">Reihe umbenennen</div>
          <button id="rrmClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
        </div>

        <div class="text-xs text-slate-700 mb-2">
          Reihe: <b id="rrmRowLbl">-</b>
        </div>

        <div class="grid gap-2">
          <div>
            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Anzeigename</label>
            <input id="rrmLabel" class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
                   placeholder="z.B. Sonderplätze / Hochregal / WE-Zone …">
            <div class="text-[11px] text-slate-500 mt-1">
              Leer lassen = nur „Reihe X“ anzeigen.
            </div>
          </div>
        </div>

        <div class="mt-3 flex gap-2 justify-end">
          <button type="button" id="rrmClear"
                  class="px-3 py-2 text-xs font-semibold rounded border border-slate-300 bg-white">
            Zurücksetzen
          </button>
          <button type="button" id="rrmCancel"
                  class="px-3 py-2 text-xs font-semibold rounded border border-slate-300 bg-white">
            Abbrechen
          </button>
          <button type="button" id="rrmSave"
                  class="px-3 py-2 text-xs font-semibold rounded bg-emerald-600 text-white">
            Speichern
          </button>
        </div>
      </div>
    </div>
  `);

  const modal = document.getElementById("rowRenameModal");

  const close = () => {
    modal.classList.add("hidden");
    modal.classList.remove("flex");
    _renameRow = null;
  };

  document.getElementById("rrmClose")?.addEventListener("click", close);
  document.getElementById("rrmCancel")?.addEventListener("click", close);

  modal.addEventListener("click", (e) => { if (e.target === modal) close(); });

  document.getElementById("rrmClear")?.addEventListener("click", async () => {
  if (!_renameRow) return;
  try {
    await deleteRowLabelOnServer(_renameRow);
    delete ROW_LABELS[String(_renameRow)];
    applyRowLabelToUI(_renameRow);
    document.getElementById("rrmLabel").value = "";
  } catch (e) {
    setStatus?.((e?.message || "Zurücksetzen fehlgeschlagen."), "error");
  }
});

  document.getElementById("rrmSave")?.addEventListener("click", async () => {
  if (!_renameRow) return;
  const label = (document.getElementById("rrmLabel")?.value || "").trim();

  try {
    await saveRowLabelToServer(_renameRow, label);

    if (label) ROW_LABELS[String(_renameRow)] = label;
    else delete ROW_LABELS[String(_renameRow)];

    applyRowLabelToUI(_renameRow);
    hideModal("rowRenameModal");
  } catch (e) {
    setStatus?.((e?.message || "Speichern fehlgeschlagen."), "error");
  }
});


  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) close();
  });
}

function openRowRenameModal(row) {
  ensureRowRenameModalDom();
  _renameRow = String(row);

  document.getElementById("rrmRowLbl").textContent = _renameRow;

  const current = ROW_LABELS[_renameRow] || "";
  const inp = document.getElementById("rrmLabel");
  if (inp) {
    inp.value = current;
    setTimeout(() => inp.focus(), 30);
  }

  const modal = document.getElementById("rowRenameModal");
  modal.classList.remove("hidden");
  modal.classList.add("flex");
}

function applyRowLabelToUI(row) {
  const r = String(row);

  // 1) Toolbar-Text
  const tb = document.querySelector(`.row-toolbar[data-row="${cssEscape(r)}"] .fw-semibold`);
  if (tb) tb.textContent = rowDisplay(r);

  // 2) Sidebar/Row-Header (falls vorhanden)
  const rowEl = document.querySelector(`.lager-reihe[data-row="${cssEscape(r)}"]`);
  if (rowEl) {
    // Falls dein Row-Element innen ein Text-DIV hat, nimm den – sonst direkt setzen
    const inner = rowEl.querySelector(".px-2, .row-title, div") || rowEl;
    inner.textContent = rowDisplay(r);
  }
}

// Button-Handler binden (nach dem Inject)
function bindRowRenameButtons() {
  document.querySelectorAll("[data-rename-row]").forEach(btn => {
    if (btn.dataset.bound === "1") return;
    btn.dataset.bound = "1";
    btn.addEventListener("click", () => openRowRenameModal(btn.dataset.renameRow));
  });
}


function printRow(row) {
  const r = String(row || '').trim();
  if (!r) return;

  // Daten sammeln: alle belegten Slots in dieser Reihe
  const usedSlots = Array.from(
    document.querySelectorAll(`.platz[data-row="${cssEscape(r)}"] .palette-slot.palette-slot-used`)
  );

  const rows = usedSlots.map(slot => {
    const platzEl = slot.closest('.platz');
    const platz = platzEl?.dataset.platz || '?';

    const ref   = slot.dataset.ref || '-';
    const sach  = slot.dataset.sach || '-';
    const ls    = slot.dataset.lieferschein || '-';
    const menge = slot.dataset.menge || '1';
    const datum = slot.dataset.date || '-';
    const user  = slot.dataset.userName || '-';
    const items = slot.dataset.itemsCount || '0';

    const idx = parseInt(slot.dataset.slotIndex || "0", 10);
    const slotName = (typeof slotTitleForRow === 'function')
      ? slotTitleForRow(r, idx)
      : `Slot ${idx + 1}`;

    return {
      platzNum: parseInt(String(platz).replace(/\D/g, "") || "0", 10),
      slotIdx:  idx,
      platz:    platz,
      slotName: slotName,
      ref, sach, ls, menge, datum, user, items
    };
  });

  // sortieren: Platz aufsteigend, dann Slot
  rows.sort((a, b) => (a.platzNum - b.platzNum) || (a.slotIdx - b.slotIdx));

  const now = new Date();
  const nowStr = now.toLocaleString("de-DE");

  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";

  const esc = (s) => {
    const str = String(s ?? "");
    return str.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  };

  // ✅ NEU: Sachnummern kumulieren (Summe Menge)
  const bySach = new Map();
  for (const x of rows) {
    const key = String(x.sach || "-").trim() || "-";
    const m = Math.max(1, parseInt(String(x.menge || "1"), 10) || 1);
    bySach.set(key, (bySach.get(key) || 0) + m);
  }
  const sachList = Array.from(bySach.entries())
    .sort((a,b) => a[0].localeCompare(b[0]));

  const sumMenge = sachList.reduce((s, [,m]) => s + (parseInt(m,10)||0), 0);
  const sumPos   = rows.length;

  const sachSummaryHtml = sachList.length ? `
    <div class="sumbox">
      <div class="sumhead">
        <div><b>Sachnummern (kumuliert)</b></div>
        <div class="summeta">Positionen: <b>${esc(sumPos)}</b> · Summe Stück: <b>${esc(sumMenge)}</b></div>
      </div>
      <table class="sumtable">
        <thead>
          <tr>
            <th>Sachnummer</th>
            <th style="text-align:right;">Stück</th>
          </tr>
        </thead>
        <tbody>
          ${sachList.map(([sach, m]) => `
            <tr>
              <td>${esc(sach)}</td>
              <td style="text-align:right;">${esc(m)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  ` : '';

  const tableHtml = rows.length
    ? `
      <table>
        <thead>
          <tr>
            <th>Position</th>
            <th>Ref</th>
            <th>Sachnummer</th>
            <th>LS</th>
            <th>Menge</th>
            <th>Datum</th>
            <th>User</th>
            <th>Kartons</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(x => `
            <tr>
              <td>R${esc(r)} / P${esc(x.platz)} / ${esc(x.slotName)}</td>
              <td>${esc(x.ref)}</td>
              <td>${esc(x.sach)}</td>
              <td>${esc(x.ls)}</td>
              <td style="text-align:right;">${esc(x.menge)}</td>
              <td>${esc(x.datum)}</td>
              <td>${esc(x.user)}</td>
              <td style="text-align:right;">${esc(x.items)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `
    : `<div class="empty">In Reihe ${esc(r)} ist aktuell nichts eingelagert.</div>`;

  // Druckfenster
  const w = window.open("", "_blank", "width=900,height=650");
  if (!w) {
    setStatus?.("Popup blockiert. Bitte Popups erlauben, dann erneut drucken.", "error");
    return;
  }

  w.document.open();
  w.document.write(`
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <title>Druck – Reihe ${esc(r)}</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; padding: 16px; color: #111827; }
    h1 { font-size: 16px; margin: 0 0 6px; }
    .meta { font-size: 12px; color: #374151; margin-bottom: 12px; }
    .empty { padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 8px; font-size: 12px; }

    /* ✅ Summary */
    .sumbox { border:1px solid #e5e7eb; background:#f9fafb; border-radius:10px; padding:10px; margin: 8px 0 12px; }
    .sumhead { display:flex; justify-content:space-between; gap:10px; align-items:flex-end; margin-bottom:6px; }
    .summeta { font-size:12px; color:#374151; white-space:nowrap; }
    .sumtable { width:100%; border-collapse:collapse; font-size:12px; }
    .sumtable th, .sumtable td { border:1px solid #e5e7eb; padding:6px 8px; vertical-align:top; }
    .sumtable th { background:#f3f4f6; text-align:left; }

    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th, td { border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
    th { background: #f3f4f6; text-align: left; }

    @media print {
      body { padding: 0; }
      .sumbox { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <h1>Lagerplan ${esc(hall)} / ${esc(zone)} – Reihe ${esc(r)}</h1>
  <div class="meta">Stand: ${esc(nowStr)}${window.currentBatch?.title ? ` · Vorgang: ${esc(window.currentBatch.title)}` : ""}</div>

  ${sachSummaryHtml}
  ${tableHtml}

  <script>
    window.onload = () => {
      setTimeout(() => window.print(), 50);
      window.onafterprint = () => window.close();
    };
  </script>
</body>
</html>
  `);
  w.document.close();
}


(function () {
 
  function qsaToArray(sel) {
    return Array.prototype.slice.call(document.querySelectorAll(sel));
  }

  function readDataset(el, keys) {
    if (!el || !el.dataset) return '';
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      if (el.dataset[k] !== undefined && el.dataset[k] !== '') return el.dataset[k];
    }
    return '';
  }

  function normText(s) {
    return (s || '').replace(/\s+/g, ' ').trim();
  }

  function collectRows() {
    var candidates = []
      .concat(qsaToArray('[data-export-item="1"]'))
      .concat(qsaToArray('[data-ref], [data-sach], [data-ls]'))
      .concat(qsaToArray('.slot, .lager-slot, .slot-item'));

    var out = [];
    var seen = {};

    for (var i = 0; i < candidates.length; i++) {
      var el = candidates[i];
      var rowEl = (el.closest && el.closest('[data-row]')) ? el.closest('[data-row]') : el;

      var row   = readDataset(el, ['row']) || readDataset(rowEl, ['row']) || '';
      var platz = readDataset(el, ['platz','place']) || readDataset(rowEl, ['platz','place']) || '';
      var slot  = readDataset(el, ['slot']) || '';

      var ref  = readDataset(el, ['ref','refnr','reference','referenz','referenznr']) || '';
      var sach = readDataset(el, ['sach','sachnummer','item','itemCode']) || '';
      var ls   = readDataset(el, ['ls','lieferschein','delivery','deliverynote']) || '';
      var qty  = readDataset(el, ['qty','menge','quantity']) || '';

      var flagType = readDataset(el, ['flagType','flag_type']) || '';
      var flagNote = readDataset(el, ['flagNote','flag_note']) || '';
      var expRow   = readDataset(el, ['flagExpRow','expected_reihe']) || '';
      var expPlatz = readDataset(el, ['flagExpPlatz','expected_platz']) || '';


      var batch = readDataset(el, ['batch','batchId']);
      if (!batch && window.currentBatch && window.currentBatch.id) batch = String(window.currentBatch.id);

      var upd = readDataset(el, ['updated','updatedAt','updated_at']) || '';

      var t = normText(el.textContent);
      var hasAny = (ref || sach || ls || t.length > 0);
      if (!hasAny) continue;

      var key = [row, platz, slot, ref, sach].join('|');
      if (seen[key]) continue;
      seen[key] = true;

      var refFallback  = ref || ((t.match(/(06\d{2}[\w\-]*)/i) || [])[1] || '');
      var sachFallback = sach || ((t.match(/(0Z1[\w\s\-]+)/i) || [])[1] || '');

      out.push({
        Reihe: row,
        Platz: platz,
        Slot: slot,
        Referenz: refFallback,
        Sachnummer: sachFallback,
        Lieferschein: ls,
        Menge: qty,
        Vorgang: batch ? String(batch) : '',
        Updated: upd,
        Abweichung: flagType,
        Abw_Notiz: flagNote,
        Soll_Reihe: expRow,
        Soll_Platz: expPlatz
      });
    }

    out.sort(function(a,b){
      var ar = String(a.Reihe||'');
      var br = String(b.Reihe||'');
      if (ar !== br) return ar.localeCompare(br, 'de');
      return (parseInt(a.Platz||'0',10) - parseInt(b.Platz||'0',10)) ||
             (parseInt(a.Slot||'0',10)  - parseInt(b.Slot||'0',10));
    });

    return out;
  }

  function downloadXlsx(rows, filename, sheetName) {
    sheetName = sheetName || 'Lager';
    if (!rows.length) { alert('Keine Daten gefunden (Export ist leer).'); return; }
    if (!window.XLSX) { alert('XLSX Library fehlt – Script-Tag prüfen.'); return; }

    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.json_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, String(sheetName).slice(0,31));
    XLSX.writeFile(wb, filename);
  }

  function downloadXlsxPerRow(rows, filename) {
    if (!rows.length) { alert('Keine Daten gefunden (Export ist leer).'); return; }
    if (!window.XLSX) { alert('XLSX Library fehlt – Script-Tag prüfen.'); return; }

    var wb = XLSX.utils.book_new();
    var groups = {};

    for (var i = 0; i < rows.length; i++) {
      var k = String(rows[i].Reihe || 'Unbekannt');
      (groups[k] = groups[k] || []).push(rows[i]);
    }

    for (var rk in groups) {
      var ws = XLSX.utils.json_to_sheet(groups[rk]);
      XLSX.utils.book_append_sheet(wb, ws, ('Reihe ' + rk).slice(0,31));
    }
    XLSX.writeFile(wb, filename);
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Achtung: wenn manualRow erst später von halle3.js gefüllt wird, ggf. nochmal aufrufen:
      window.syncXlsxRowSelect?.();
  setTimeout(() => window.syncXlsxRowSelect?.(), 500);

        var btnAll = document.getElementById('btnXlsxAll');

    if (btnAll) btnAll.addEventListener('click', function(){
      var rows = collectRows();
      downloadXlsx(rows, 'lagerplan_h4_gesamt_' + new Date().toISOString().slice(0,10) + '.xlsx', 'Gesamt');
    });

    var btnPerRow = document.getElementById('btnXlsxPerRow');
    if (btnPerRow) btnPerRow.addEventListener('click', function(){
      var rows = collectRows();
      downloadXlsxPerRow(rows, 'lagerplan_h4_reihen_' + new Date().toISOString().slice(0,10) + '.xlsx');
    });

    var btnRow = document.getElementById('btnXlsxRow');
    if (btnRow) btnRow.addEventListener('click', function(){
      var sel = document.getElementById('xlsxRowSel');
      var r = sel ? sel.value : '';
      var rows = collectRows().filter(function(x){ return String(x.Reihe) === String(r); });
      downloadXlsx(rows, 'lagerplan_h4_reihe_' + r + '_' + new Date().toISOString().slice(0,10) + '.xlsx', 'Reihe ' + r);
    });
  });
})();

function getItemByCtx(ctx) {
  // Beispiel-Datenstruktur (passen wir gleich an, wenn du mir 10 Zeilen zeigst)
  // state.grid[row][platz].slots[slot_index]

  const r = String(ctx.row);
  const p = String(ctx.platz);
  const i = parseInt(ctx.slot_index, 10);

  const cell = window.state?.grid?.[r]?.[p];
  if (!cell || !Array.isArray(cell.slots)) return null;

  return cell.slots[i] || null;
}

document.addEventListener("DOMContentLoaded", () => {
  if (!window.LagerplanViewport) {
    console.error("LagerplanViewport fehlt – Script nicht geladen?");
    return;
  }

  LagerplanViewport.init({
    wrap: "#planViewport",
    content: "#planContent",
    ctrlZoomOnly: true
  });
});
document.addEventListener('DOMContentLoaded', () => {
  const modalIds = [
    'assignModal',
    'editModal',
    'confirmDeleteModal',
    'moveModal',
    'outbookModal',
    'cartonModal'
  ];

  modalIds.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;

    // Wichtig: Modal direkt unter <body> hängen, damit es NICHT vom Plan-Transform beeinflusst wird
    if (el.parentElement !== document.body) {
      document.body.appendChild(el);
    }

    // Sicherheit: immer fixed + oben drüber
    el.style.position = 'fixed';
    el.style.inset = '0';
    el.style.zIndex = '999999';
  });
});

function showLagerInfo(anchorEl, html) {
  const info = document.getElementById('lager-info');
  if (!info || !anchorEl) return;

  if (info.parentElement !== document.body) document.body.appendChild(info);

  info.innerHTML = html;
  info.classList.remove('hidden');

  // Erzwingen, damit es nicht "absolute" bleibt (falls irgendwo gesetzt)
  info.style.position = 'fixed';

  requestAnimationFrame(() => {
    const r = anchorEl.getBoundingClientRect();

    let left = r.right + 10;
    let top  = r.top;

    const pad = 8;
    const maxLeft = window.innerWidth  - info.offsetWidth  - pad;
    const maxTop  = window.innerHeight - info.offsetHeight - pad;

    if (left > maxLeft) left = r.left - info.offsetWidth - 10;

    info.style.left = clamp(left, pad, maxLeft) + 'px';
    info.style.top  = clamp(top,  pad, maxTop)  + 'px';
  });
}


function hideLagerInfo() {
  const info = document.getElementById('lager-info');
  if (info) info.classList.add('hidden');
}

function showModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove("hidden", "d-none");
  el.classList.add("flex");
}

function hideModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add("hidden");
  el.classList.remove("flex");
  // optional: wenn du irgendwo Bootstrap nutzt:
  // el.classList.add("d-none");
}


function bindEditModalHandlers() {
  // Modal muss existieren
  const modal = document.getElementById("editModal");
  if (!modal) return;

  // Close (einmalig)
  if (!modal.dataset.closeBound) {
    modal.dataset.closeBound = "1";
    modal.querySelector("#editClose")?.addEventListener("click", () => hideModal("editModal"));
    modal.addEventListener("click", (e) => {
      if (e.target === modal) hideModal("editModal");
    });
  }

  // -------- SAVE: alte Listener killen, indem Button geklont wird --------
  const oldBtn = document.getElementById("emSave");
  if (!oldBtn) return;

  // wenn wir schon unseren „frischen“ Button haben -> nichts tun
  if (oldBtn.dataset.h4SaveBound === "1") return;

  const freshBtn = oldBtn.cloneNode(true);
  oldBtn.parentNode.replaceChild(freshBtn, oldBtn);
  freshBtn.dataset.h4SaveBound = "1";

  freshBtn.addEventListener("click", async (e) => {
    e.preventDefault();

    const ctx = window.__editCtx || {};
    const slotEl = window.__overlaySlotEl || null;

    // slotId robust holen
    const slotId =
      String(ctx.slotId || ctx.slot_id || slotEl?.dataset?.slotId || "").trim();

    if (!slotId) {
      console.log("❌ Save: slotId fehlt. ctx=", ctx, "slotEl=", slotEl);
      if (!slotId) {
  console.log("❌ Save: slotId fehlt. ctx=", ctx, "slotEl=", slotEl);
  setStatus?.("Speichern geht nicht: Slot-ID fehlt. Bitte Seite neu laden und Slot erneut öffnen.", "error");
  soundError?.();
  return;
}
     return;
    }

    // ---------------------------
    // 1) REF UPDATE (vor Correction!)
    // ---------------------------
    const emRef = document.getElementById("emRef");
    const newRef = String(emRef?.value || "").trim();
    const oldRef = String(ctx.ref_orig || ctx.ref || "").trim();

    if (!newRef) {
      setStatus?.("Referenz darf nicht leer sein.", "error");
      emRef?.focus();
      return;
    }

    // Nur wenn geändert
    if (newRef !== oldRef) {
      try {
        console.log("✅ REF update ->", { slotId, oldRef, newRef });

        await updateRefOnServer(slotId, newRef);

        // UI sofort updaten (direkt am Overlay-Slot, das ist am sichersten)
        const s = slotEl || document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(slotId)}"]`);
        if (s) {
          s.dataset.ref = newRef;
          s.textContent = newRef.slice(-4);
        }

        // Modal Label updaten
        const refLbl = document.getElementById("emRefLbl");
        if (refLbl) refLbl.textContent = newRef;

        // ctx aktualisieren
        ctx.ref = newRef;
        ctx.ref_orig = newRef;
        window.__editCtx = ctx;

        rebuildSearchIndex?.();

      } catch (err) {
        console.error("❌ REF update failed:", err);
        setStatus?.(err?.message || "Ref-Update fehlgeschlagen.", "error");
        emRef?.focus();
        emRef?.select?.();
        return; // ❗ keine Correction speichern wenn Ref-Update scheitert
      }
    }

    // ---------------------------
    // 2) CORRECTION (wie gehabt)
    // ---------------------------
   // ---------------------------
// 2) CORRECTION (wie gehabt)  ✅ aber mit row/platz/slot
// ---------------------------
const sach_korr = (document.getElementById("emSach")?.value || "").trim();
const qty_korr  = Math.max(1, parseInt(document.getElementById("emQty")?.value || "1", 10) || 1);
const note      = (document.getElementById("emNote")?.value || "").trim();

// ✅ row/platz/slot aus ctx ziehen
const row   = String(ctx.row || "").trim();
const platz = parseInt(ctx.platz, 10) || 0;

// slot ist meistens 1-basiert – du setzt es in openEditModal ja schon als ctx.slot
const slot  =
  parseInt(ctx.slot, 10) ||
  (Number.isFinite(parseInt(ctx.slot_index, 10)) ? (parseInt(ctx.slot_index, 10) + 1) : 0);

if (!row || !platz || !slot) {
  console.log("❌ CORR ctx fehlt:", ctx);
  setStatus?.("Konnte nicht speichern: row/platz/slot fehlt im Edit-Kontext. Bitte Slot neu öffnen.", "error");
  return;
}

const payload = {
  slot_id: Number(slotId),   // kann bleiben
  row,                       // ✅ MUSS rein
  platz,                     // ✅ MUSS rein
  slot,                      // ✅ sehr wahrscheinlich nötig
  // optional (falls dein PHP das kennt):
  // batch_id: ctx.batch_id ?? null,
  // ref: ctx.ref ?? "",
  sach_korr,
  qty_korr,
  note
};

console.log("✅ CORR upsert payload:", payload);

const res = await fetch("/LKW/Lagerplan/lager_corrections_api.php?action=upsert", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  credentials: "include",
  body: JSON.stringify(payload)
});

const text = await res.text();
let js = {};
try { js = JSON.parse(text); } catch (_) {}

if (!res.ok || !js.ok) {
  console.log("❌ Correction failed:", res.status, text);
  setStatus?.(js?.msg || js?.error || "Fehler beim Speichern (Correction).", "error");
  return;
}

// wenn Overlay offen -> neu rendern
const s2 = slotEl || document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(slotId)}"]`);
if (s2) {
  if (sach_korr) s2.dataset.sach = sach_korr;
  s2.dataset.menge = String(qty_korr);
  if (typeof openSlotOverlay === "function") openSlotOverlay(s2);
  const platzEl = s2.closest(".platz");
  if (platzEl && typeof updatePlatzLabel === "function") updatePlatzLabel(platzEl);
}

hideModal("editModal");
afterPlanChange();
setStatus?.("Gespeichert.", "success");

  });
}



async function updateRefOnServer(slotId, newRef) {
  const fd = new FormData();
  fd.append("slot_id", String(slotId));
  fd.append("referenznr", String(newRef));

  const res = await fetch("/LKW/Lagerplan/lager_ref_update.php", {
    method: "POST",
    body: fd,
    credentials: "include",
    cache: "no-store"
  });

  const text = await res.text();
  let data = {};
  try { data = JSON.parse(text); } catch (_) {}

  if (!res.ok || data.ok !== true) {
    throw new Error(data?.msg || data?.error || `Ref-Update fehlgeschlagen (HTTP ${res.status}).`);
  }
  return data;
}
function applyRefToUI(slotId, newRef) {
  const slotEl = findSlotElById(slotId);
  if (!slotEl) return;

  slotEl.dataset.ref = String(newRef);
  slotEl.textContent = String(newRef).slice(-4);

  const sach = slotEl.dataset.sach || "";
  const date = slotEl.dataset.date || new Date().toLocaleDateString("de-DE");
  const menge = slotEl.dataset.menge || "";
  const mTxt = menge ? ` · Menge: ${menge}` : "";
  slotEl.title = `${newRef} · ${sach} · ${date}${mTxt}`;
}


// async function onEditSave(e){
//   e.preventDefault();

//   const ctx = window.__editCtx; // ✅ NUR DAS benutzen
//   if(!ctx){
//     alert("Kein Edit-Kontext (window.__editCtx fehlt).");
//     return;
//   }

//   const payload = {
//     batch_id: window.currentBatch?.id || null,
//     row:  ctx.row,
//     platz: ctx.platz,
//     slot: ctx.slot,        // ✅ 1-basiert wie du es baust
//     ref:  ctx.ref,

//     sach_korr: (document.getElementById("emSach")?.value || "").trim(),
//     qty_korr:  Math.max(1, parseInt(document.getElementById("emQty")?.value || "1", 10) || 1),
//     note:      (document.getElementById("emNote")?.value || "").trim()
//   };

//   const res = await fetch("/LKW/Lagerplan/lager_corrections_api.php?action=upsert", {
//     method: "POST",
//     headers: {"Content-Type":"application/json"},
//     credentials: "include",
//     body: JSON.stringify(payload)
//   });

//   const js = await res.json().catch(() => ({}));
//   if(!js.ok){
//     alert(js.error || "Fehler beim Speichern");
//     return;
//   }

//   hideModal("editModal");

//   // ✅ UI sofort updaten (ohne reloadSlots)
//   if (_overlaySlotEl) {
//     // wenn du willst: echte Slot-Daten überschreiben
//     _overlaySlotEl.dataset.sach = payload.sach_korr || _overlaySlotEl.dataset.sach || "";
//     _overlaySlotEl.dataset.menge = String(payload.qty_korr);
//     openSlotOverlay(_overlaySlotEl); // Overlay neu rendern
//   }

//   setStatus("Korrektur gespeichert.", "success");
// }

// async function onEditSaveClick() {
//   const ctx = window.__editCtx;
//   if (!ctx) { alert("Kein Edit-Kontext (window.__editCtx fehlt)."); return; }

//   const slotId = String(ctx.slotId || "");
//   if (!slotId) { alert("slot_id fehlt – Slot hat keine ID."); return; }

//   const emRef = document.getElementById("emRef");
//   const emRefLbl = document.getElementById("emRefLbl");

//   const newRef = String(emRef?.value || "").trim();
//   if (!newRef) { setStatus?.("Referenz darf nicht leer sein.", "error"); return; }

//   // 1) Ref updaten (nur wenn geändert)
//   const oldRef = String(ctx.ref_orig || ctx.ref || "").trim();
//   if (newRef !== oldRef) {
//     try {
//       await updateRefOnServer(slotId, newRef);

//       applyRefToUI(slotId, newRef);

//       ctx.ref = newRef;
//       ctx.ref_orig = newRef;
//       if (emRefLbl) emRefLbl.textContent = newRef;

//       rebuildSearchIndex?.();
//     } catch (err) {
//       // duplicate_ref etc.
//       console.error(err);
//       setStatus?.(err?.message || "Ref-Update fehlgeschlagen.", "error");
//       emRef?.focus();
//       emRef?.select?.();
//       return; // ❗ wichtig: Correction NICHT speichern wenn Ref-Update scheitert
//     }
//   }

//   // 2) Danach: deine Correction wie gehabt (sach/qty/note)
//   // -> hier rufst du deine bestehende Upsert-Logik auf
//   // (oder lässt einfach deinen bisherigen Code danach laufen)
// }


// async function onEditUndo(e){
//   e.preventDefault();

//   const ctx = window.__editCtx;
//   if(!ctx){
//     alert("Kein Edit-Kontext (window.__editCtx fehlt).");
//     return;
//   }

//   const res = await fetch("/LKW/Lagerplan/lager_corrections_api.php?action=delete", {
//     method: "POST",
//     headers: {"Content-Type":"application/json"},
//     credentials: "include",
//     body: JSON.stringify({
//       batch_id: window.currentBatch?.id || null,
//       row: ctx.row,
//       platz: ctx.platz,
//       slot: ctx.slot
//     })
//   });

//   const js = await res.json().catch(() => ({}));
//   if(!js.ok){
//     alert(js.error || "Fehler beim Undo");
//     return;
//   }

//   hideModal("editModal");
//   setStatus("Korrektur entfernt (Undo).", "success");
// }


function initDelegatedClicks() {
  const block = document.getElementById(BLOCK_ID);
  if (!block) return;

  // Klick irgendwo im Block
  block.addEventListener("click", (e) => {
    // 1) Slot klick
    const slot = e.target.closest(".palette-slot");
    if (slot) {
      e.stopPropagation();
      if (slot.dataset.ref) openSlotOverlay(slot);
      else openAssignModal(slot);
      return;
    }

    // 2) Platz klick
    const platzEl = e.target.closest(".platz");
    if (platzEl) {
      e.stopPropagation();
      highlightPlatz(platzEl);
      openPlatzOverlay(platzEl);
      return;
    }

    // 3) Reihe klick
    const rowEl = e.target.closest(".lager-reihe");
    if (rowEl) {
      highlightRow(rowEl);

      // Lazy: Reihe erst jetzt rendern
      ensureRowRendered(rowEl.dataset.row);

      return;
    }
  });

  // Doppelklick: Einlagern auf erstem freien Slot
  block.addEventListener("dblclick", (e) => {
    const platzEl = e.target.closest(".platz");
    if (!platzEl) return;
    e.stopPropagation();

    const free = Array.from(platzEl.querySelectorAll(".palette-slot")).find(s => !s.dataset.ref);
    if (free) openAssignModal(free);
    else {
      flashPlatz(platzEl, "error");
      setStatus("Dieser Platz ist voll.", "error");
    }
  });

  // Klick außerhalb -> Overlay zu (dein Code kann bleiben)
  document.addEventListener("click", (e) => {
    const infoDiv = document.getElementById("lager-info");
    const insideInfo  = infoDiv && infoDiv.contains(e.target);
    const insideModal =
      document.getElementById("assignModal")?.contains(e.target) ||
      document.getElementById("confirmDeleteModal")?.contains(e.target) ||
      document.getElementById("moveModal")?.contains(e.target) ||
      document.getElementById("cartonModal")?.contains(e.target) ||
      document.getElementById("outbookModal")?.contains(e.target);

    if (!insideInfo && !insideModal) hideInfoBubble();
  });
}
function ensureRowRendered(row) {
  const r = String(row || "").trim();
  if (!r) return;

  const c = document.querySelector(`#${BLOCK_ID} .platz-container[data-row="${cssEscape(r)}"]`);
  if (!c) return;

  if (c.dataset.rendered === "1") return;

  buildRowPlaces(c);          // baut nur diese eine Reihe
  c.dataset.rendered = "1";
}
function findSlotElById(slotId) {
  const id = CSS.escape(String(slotId));
  return document.querySelector(`.palette-slot[data-slot-id="${id}"]`);
}


function buildRowPlaces(container) {
  container.innerHTML = "";

  const row   = container.dataset.row;
  const start = 1;
  const end   = placeMaxForRow(row);
  const cap   = slotCapacityForRow(row);

  const frag = document.createDocumentFragment();

  for (let p = start; p <= end; p++) {
    const platz = document.createElement("div");
    platz.className = "platz";
    platz.dataset.row = row;
    platz.dataset.platz = String(p).padStart(2, "0");

    const grid = document.createElement("div");
    grid.className = "platz-grid";

    for (let i = 0; i < cap; i++) {
      const slot = document.createElement("div");
      slot.className = "palette-slot";
      slot.dataset.slotIndex = String(i);
      if (row === "20") slot.dataset.slotLabel = slotTitleForRow(row, i);
      grid.appendChild(slot);
    }

    const label = document.createElement("div");
    label.className = "platz-label";
    label.textContent = `R${row} / P${String(p).padStart(2, "0")}\n0/${cap} belegt`;

    platz.appendChild(grid);
    platz.appendChild(label);
    frag.appendChild(platz);
  }

  container.appendChild(frag);
}
(function bindEditButtonDelegatedOnce(){
  if (window.__EDIT_BTN_DELEGATED__) return;
  window.__EDIT_BTN_DELEGATED__ = true;

  document.addEventListener("click", (e) => {
    // ✅ NUR reagieren, wenn wirklich auf "Bearbeiten" geklickt wurde
    const btn = e.target.closest("#btnEditSlot");
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    // ✅ Slot-Kontext: primär global, Fallback über #lager-info
    let slotEl = window.__overlaySlotEl || null;

    if (!slotEl) {
      const info = document.getElementById("lager-info");
      const sid  = (info?.dataset?.slotId || "").trim();
      if (sid) {
        try {
          slotEl = document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(sid)}"]`);
        } catch (_) {}
      }
    }

    // ✅ KEIN throw mehr!
    if (!slotEl) {
      setStatus?.("Kein Slot-Kontext. Klick zuerst auf eine belegte Palette (Ref), dann auf Bearbeiten.", "error");
      soundError?.();
      return;
    }

    const p = slotEl.closest(".platz");
    const row = p?.dataset.row || "";
    const platz = parseInt(p?.dataset.platz || "0", 10) || 0;
    const slotIndex = parseInt(slotEl.dataset.slotIndex || "0", 10) || 0;

    const slotId = String(slotEl.dataset.slotId || "").trim();
    if (!slotId) {
      setStatus?.("Slot-ID fehlt. Bitte Seite neu laden und Slot erneut öffnen.", "error");
      soundError?.();
      return;
    }

    console.log("✏️ Bearbeiten clicked:", { slotId, row, platz, slotIndex, ref: slotEl.dataset.ref });

    openEditModal({
      row,
      platz,
      slot_index: slotIndex,
      ref: slotEl.dataset.ref || "",
      sach: slotEl.dataset.sach || "",
      qty: parseInt(slotEl.dataset.menge || "1", 10) || 1,
      note: "",
      slotId
    });

  }, false);
})();


// ===============================
// GLOBAL CAPTURE CLICK: setzt Overlay-Kontext immer
// ===============================
(function bindOverlayCtxCaptureOnce(){
  if (window.__OVERLAY_CTX_CAPTURE__) return;
  window.__OVERLAY_CTX_CAPTURE__ = true;

  document.addEventListener("click", (e) => {
    const slot = e.target?.closest?.(".palette-slot");
    if (slot && slot.dataset && slot.dataset.ref) {
      setOverlaySlotCtx(slot);

      // Fallback Daten auch am infoDiv halten (falls Edit später kommt)
      const info = document.getElementById("lager-info");
      if (info) {
        info.dataset.slotId = slot.dataset.slotId || "";
        info.dataset.ref    = slot.dataset.ref || "";
      }
    }
  }, true); // ✅ capture = läuft VOR anderen Handlern
})();

// ✅ zentral: nach jeder Änderung im Plan aufrufen
function afterPlanChange() {
  try { rebuildSearchIndex?.(); } catch (_) {}
  try { renderLgSummaryFromPlan?.(); } catch (_) {}
  try { updateLgActiveBadge?.(); } catch (_) {}
  try { updateGlobalStatus?.(); } catch (_) {}
}
// ✅ falls irgendwo refreshLgFilter genutzt wird:
window.refreshLgFilter = () => {
  renderLgSummaryFromPlan?.();
  updateLgActiveBadge?.();
};
// ===============================
// INVENTUR CHECK (Row Toolbar)
// Speichert pro Hall/Zone im localStorage
// ===============================
(function initInventoryOnce(){
  if (window.__INV_INIT__) return;
  window.__INV_INIT__ = true;

  const invKey = () => `inv_checked_${window.currentHall || "H4"}_${window.currentZone || "W1"}`;

  const loadSet = () => {
    try {
      const arr = JSON.parse(localStorage.getItem(invKey()) || "[]");
      return new Set(Array.isArray(arr) ? arr.map(String) : []);
    } catch {
      return new Set();
    }
  };

  const saveSet = (set) => {
    localStorage.setItem(invKey(), JSON.stringify([...set]));
  };

  let done = loadSet();

  function applyRowDoneUI(row, checked) {
    const tb = document.querySelector(`.row-toolbar[data-row="${cssEscape(row)}"]`);
    if (tb) tb.classList.toggle("inv-done", !!checked);

    const cb = document.querySelector(`.inv-check[data-inv-row="${cssEscape(row)}"]`);
    if (cb) cb.checked = !!checked;
  }

  function allRows() {
    return [...document.querySelectorAll(".row-toolbar[data-row]")]
      .map(tb => String(tb.dataset.row || "").trim())
      .filter(Boolean);
  }

  function updateInvSummary() {
    const rows = allRows();
    const total = rows.length;
    const count = done.size;
    const pct = total ? Math.round((count / total) * 100) : 0;

    const t = document.getElementById("invCountText");
    if (t) t.textContent = `${count} von ${total} geprüft (${pct}%)`;

    const bar = document.getElementById("invProgressBar");
    if (bar) {
      bar.style.width = pct + "%";
      bar.textContent = pct + "%";
      bar.setAttribute("aria-valuenow", String(pct));
    }

    const list = document.getElementById("invCheckedList");
    if (list) {
      const arr = [...done].sort((a,b) => (parseInt(a,10)||0) - (parseInt(b,10)||0));
      list.innerHTML = arr.length
        ? arr.map(r => `<span class="badge text-bg-success">R${escapeHtml(r)}</span>`).join("")
        : `<span class="text-muted small">Noch keine Reihe markiert.</span>`;
    }
  }

  function syncAllCheckboxes() {
    allRows().forEach(r => applyRowDoneUI(r, done.has(r)));
    updateInvSummary();
  }

  // ✅ Delegation: reagiert auf alle Toolbar-Checkboxen
  document.addEventListener("change", (e) => {
    const cb = e.target.closest(".inv-check[data-inv-row]");
    if (!cb) return;

    const row = String(cb.dataset.invRow || "").trim();
    if (!row) return;

    if (cb.checked) done.add(row);
    else done.delete(row);

    saveSet(done);
    applyRowDoneUI(row, cb.checked);
    updateInvSummary();
  });

  // ✅ Reset Button
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("#btnInvReset");
    if (!btn) return;

    done = new Set();
    saveSet(done);
    syncAllCheckboxes();
  });

  // ✅ Initial nach DOM ready + nach Toolbar-Injection
  document.addEventListener("DOMContentLoaded", () => {
    // kleine Verzögerung, falls Toolbars erst danach kommen
    setTimeout(syncAllCheckboxes, 150);
  });

  // falls du Toolbars später nochmal injizierst:
  window.refreshInventoryUI = () => syncAllCheckboxes();
})();

function safeFileName(name) {
  return String(name || "hitlist")
    .replace(/[\\/:*?"<>|]+/g, "_")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 120);
}


function exportHitlistXlsx() {
  if (!window.XLSX) {
    alert("XLSX Library fehlt. Prüfe: xlsx.full.min.js");
    return;
  }

  // Quelle: aktuelle Trefferliste
  const slots =
    (window._hitListLast?.slots && Array.isArray(window._hitListLast.slots))
      ? window._hitListLast.slots
      : (typeof _hitListLast !== "undefined" ? _hitListLast.slots : []);

  if (!slots || !slots.length) {
    alert("Keine Treffer zum Export.");
    return;
  }

  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";

  // Suchbegriff / Titel
  const searchTerm =
    (document.getElementById("searchRefInput")?.value || "").trim() ||
    (window._hitListLast?.title || "Trefferliste");

  // LG Filter Text (optional)
  const lgFilterText = (() => {
    if (typeof getSelectedLGs === "function") {
      const sel = getSelectedLGs(); // null=alle, Set()=keine, Set([...])=Auswahl
      if (sel === null) return "alle";
      if (sel instanceof Set && sel.size === 0) return "keine";
      if (sel instanceof Set) return [...sel].join(", ");
    }
    if (window.__LG_NONE__ === true) return "keine";
    if (window.__LG_SELECTED__ instanceof Set && window.__LG_SELECTED__.size) {
      return [...window.__LG_SELECTED__].join(", ");
    }
    return "alle";
  })();

  // Hilfen
  const nowStr = new Date().toLocaleString("de-DE");

  // ✅ feste Spalten-Reihenfolge (damit Menge immer Spalte H ist!)
  const HEADERS = [
    "Position", "Reihe", "Platz", "Slot",
    "Referenz", "Sachnummer", "Lieferschein",
    "Menge",
    "Datum", "User", "Kartons", "Slot_ID",
    "Status", "Abweichung", "Abw_Notiz"
  ];
  // Spalten: A..O  (Menge = H)

  // Datenzeilen
  const rows = slots.map((s) => {
    const p = s.closest(".platz");
    const row = p?.dataset.row || "";
    const platz = p?.dataset.platz || "";
    const slotHuman = (parseInt(s.dataset.slotIndex || "0", 10) + 1);

    const ref  = s.dataset.ref || "";
    const sach = s.dataset.sach || "";
    const ls   = s.dataset.lieferschein || "";
    const menge = Math.max(1, parseInt(String(s.dataset.menge || "1"), 10) || 1);

    // Abweichung/Flag aus dataset (wenn du das nutzt)
    const flagType = String(s.dataset.flagType || "").trim();
    const flagNote = String(s.dataset.flagNote || "").trim();

    // Status (sichtbar + filterbar)
    const status =
      flagType ? `⚠ ${flagType}` : "✅ OK";

    return [
      `R${row}-${platz}-${slotHuman}`,  // Position
      row,                              // Reihe
      platz,                            // Platz
      slotHuman,                        // Slot
      ref,                              // Referenz
      sach,                             // Sachnummer
      ls,                               // Lieferschein
      menge,                            // Menge
      s.dataset.date || "",             // Datum
      s.dataset.userName || "",         // User
      parseInt(String(s.dataset.itemsCount || "0"), 10) || 0, // Kartons
      s.dataset.slotId || "",           // Slot_ID
      status,                           // Status
      flagType,                         // Abweichung
      flagNote                          // Abw_Notiz
    ];
  });

  // sortiert nach Reihe/Platz/Slot
  rows.sort((a,b) =>
    (parseInt(a[1],10)||0)-(parseInt(b[1],10)||0) ||
    (parseInt(a[2],10)||0)-(parseInt(b[2],10)||0) ||
    (parseInt(a[3],10)||0)-(parseInt(b[3],10)||0)
  );

  // Workbook
  const wb = XLSX.utils.book_new();

  // =========================
  // Sheet 1: Summary
  // =========================
  const wsSummary = XLSX.utils.aoa_to_sheet([
    ["Titel",       "Suchbegriff", "Filter_LG", "Halle", "Zone", "Export_Am", "Treffer", "Summe_Menge"],
    ["Trefferliste", searchTerm,    lgFilterText, hall,    zone,  nowStr,     "",        ""]
  ]);

  // =========================
  // Sheet 2: Treffer (mit Formel-SUM unten)
  // =========================
  const aoaTreffer = [HEADERS, ...rows];
  const wsTreffer = XLSX.utils.aoa_to_sheet(aoaTreffer);

  const n = rows.length;            // Datenzeilen
  const dataLastRow = n + 1;        // inkl Header: letzte Datenzeile = Zeile n+1 (1-basiert)
  const summaryRow  = n + 3;        // Summary 2 Zeilen unter Daten (damit Sort/Filter sie nicht “verschiebt”)

  // AutoFilter nur über die Daten (Header + Daten) => Summary bleibt sauber unten
  const lastColLetter = XLSX.utils.encode_col(HEADERS.length - 1); // O
  wsTreffer["!autofilter"] = { ref: `A1:${lastColLetter}${dataLastRow}` };

  // Summary-Zeile schreiben
  wsTreffer[`A${summaryRow}`] = { t: "s", v: "SUMMARY" };
  // Menge ist Spalte H => Formel SUM(H2:H{dataLastRow})
  wsTreffer[`H${summaryRow}`] = { t: "n", f: `SUM(H2:H${dataLastRow})`, v: 0 };

  // !ref erweitern, damit Summary im sichtbaren Bereich liegt
  wsTreffer["!ref"] = XLSX.utils.encode_range({
    s: { r: 0, c: 0 },
    e: { r: summaryRow - 1, c: HEADERS.length - 1 }
  });

  // =========================
  // Sheet 3: Sach-Summary (SUMIF, damit es sich bei Excel-Änderungen aktualisiert!)
  // =========================
  const sachSet = new Set(rows.map(r => String(r[5] || "-").trim() || "-")); // Sachnummer = Spalte F (Index 5)
  const sachList = [...sachSet].sort((a,b)=>a.localeCompare(b, "de"));

  const aoaSach = [
    ["Sachnummer", "Summe_Menge"],
    ...sachList.map(sach => [sach, ""]) // Summe kommt als Formel rein
  ];
  const wsSach = XLSX.utils.aoa_to_sheet(aoaSach);

  // Für jede Sachnummer: =SUMIF(Treffer!F:F, A2, Treffer!H:H)
  // (Sachnummer Spalte F, Menge Spalte H)
  for (let i = 0; i < sachList.length; i++) {
    const rExcel = i + 2; // ab Zeile 2
    wsSach[`B${rExcel}`] = { t: "n", f: `SUMIF(Treffer!F:F, A${rExcel}, Treffer!H:H)`, v: 0 };
  }

  const sachDataLastRow = sachList.length + 1;
  const sachSummaryRow  = sachList.length + 3;

  wsSach["!autofilter"] = { ref: `A1:B${sachDataLastRow}` };
  wsSach[`A${sachSummaryRow}`] = { t: "s", v: "SUMMARY" };
  wsSach[`B${sachSummaryRow}`] = { t: "n", f: `SUM(B2:B${sachDataLastRow})`, v: 0 };

  wsSach["!ref"] = XLSX.utils.encode_range({
    s: { r: 0, c: 0 },
    e: { r: sachSummaryRow - 1, c: 1 }
  });

  // ✅ Summary Sheet: Werte per Formel referenzieren (immer live)
  wsSummary["G2"] = { t: "n", f: `COUNTA(Treffer!A2:A${dataLastRow})`, v: 0 }; // Treffer
  wsSummary["H2"] = { t: "n", f: `Treffer!H${summaryRow}`, v: 0 };            // Summe Menge

  // Sheets rein
  XLSX.utils.book_append_sheet(wb, wsSummary, "Summary");
  XLSX.utils.book_append_sheet(wb, wsTreffer, "Treffer");
  XLSX.utils.book_append_sheet(wb, wsSach, "Sach-Summary");

  // Datei
  const date = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, safeFileName(`${searchTerm}_${date}`) + ".xlsx");
}



window._hitListLast = _hitListLast;


// Delegierter Klick (damit es immer klappt, auch wenn Modal dynamisch ist)
document.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-hitlist-xlsx]");
  if (!btn) return;
  e.preventDefault();
  exportHitlistXlsx();
});

function exportPalletsPerRowXlsx() {
  if (!window.XLSX) {
    alert("XLSX Library fehlt (xlsx.full.min.js).");
    return;
  }

  const hall = window.currentHall || "H4";
  const zone = window.currentZone || "W1";
  const fromRow = 1;
  const toRow = 140;

  // ✅ zählt Paletten pro Reihe (belegte Slots im Plan)
  const map = new Map(); // row -> count
  document.querySelectorAll(`#w1-block-16-19 .palette-slot[data-ref]`).forEach(slot => {
    const platzEl = slot.closest(".platz");
    const row = String(platzEl?.dataset?.row || "").trim();
    if (!row) return;
    map.set(row, (map.get(row) || 0) + 1);
  });

  // Tabelle bauen: Reihe 1..140
  const data = [];
  for (let r = fromRow; r <= toRow; r++) {
    data.push({ Reihe: r, Paletten: map.get(String(r)) || 0 });
  }

  // Workbook
  const wb = XLSX.utils.book_new();

  // Sheet
  const ws = XLSX.utils.json_to_sheet(data, { header: ["Reihe", "Paletten"] });
  XLSX.utils.book_append_sheet(wb, ws, "Paletten je Reihe");

  // AutoFilter (sortieren/filtern ohne Ctrl+T)
  const lastDataRow = (toRow - fromRow + 1) + 1; // + Header
  ws["!autofilter"] = { ref: `A1:B${lastDataRow}` };

  // ✅ Summary-Zeile mit Formel (ändert sich automatisch, wenn du Werte änderst)
  const sumRow = lastDataRow + 2; // 1 Leerzeile
  ws[`A${sumRow}`] = { t: "s", v: "SUMMARY" };
  ws[`B${sumRow}`] = { t: "n", f: `SUM(B2:B${lastDataRow})`, v: 0 };

  // ref erweitern (damit Summary im Bereich liegt)
  ws["!ref"] = XLSX.utils.encode_range({
    s: { r: 0, c: 0 },
    e: { r: sumRow - 1, c: 1 }
  });

  // Datei
  const date = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, `paletten_je_reihe_${hall}_${zone}_${date}.xlsx`);
}function countDeviationsPerRow() {
  const map = new Map();

  // alle Slots mit Abweichung (Flag)
  document.querySelectorAll('#planContent .palette-slot[data-flag-type]').forEach(slot => {
    const platzEl = slot.closest(".platz");
    const row = String(platzEl?.dataset?.row || "").trim();
    if (!row) return;

    map.set(row, (map.get(row) || 0) + 1);
  });

  return map;
}

(() => {
  const $ = (id) => document.getElementById(id);

  const btnAll      = $("btnXlsxAll");
  const btnPerRow   = $("btnXlsxPerRow");
  const btnRow      = $("btnXlsxRow");
  const btnPallets  = $("btnXlsxRowPallets");
  const selRow      = $("xlsxRowSel");

  const toRowName = (x) => {
    if (typeof x === "string" && x.startsWith("H4-R")) return x;
    const n = Number(x);
    return `H4-R${String(n).padStart(2, "0")}`; // 1->H4-R01, 140->H4-R140
  };

  const getData = () => {
    const data = window.h4ExportData;
    if (!Array.isArray(data)) {
      alert("❌ window.h4ExportData fehlt (Export-Daten nicht vorhanden).");
      return null;
    }
    // reihe normalisieren
    return data.map(d => ({
      ...d,
      reihe: toRowName(d.reihe ?? d.reiheNum ?? d.row ?? d.reihe_nr)
    }));
  };

  const groupBy = (arr, keyFn) => {
    const m = new Map();
    for (const it of arr) {
      const k = keyFn(it);
      if (!m.has(k)) m.set(k, []);
      m.get(k).push(it);
    }
    return m;
  };

  const downloadWb = (wb, filename) => {
    XLSX.writeFile(wb, filename);
  };

  btnAll?.addEventListener("click", () => {
    const data = getData(); if (!data) return;
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makeGesamtSheet(data), "Gesamt");
    downloadWb(wb, "Lagerplatzkontrolle_Gesamt.xlsx");
  });

  btnPerRow?.addEventListener("click", () => {
    const data = getData(); if (!data) return;
    const wb = XLSX.utils.book_new();
    const grouped = groupBy(data, x => x.reihe);
    for (const [reihe, items] of grouped.entries()) {
      XLSX.utils.book_append_sheet(wb, makeRowSheet(reihe, items), reihe);
    }
    downloadWb(wb, "Lagerplatzkontrolle_JeReihe.xlsx");
  });

  btnRow?.addEventListener("click", () => {
    const data = getData(); if (!data) return;
    const n = selRow?.value;
    if (!n) return alert("Bitte eine Reihe auswählen.");
    const reihe = toRowName(n);
    const items = data.filter(x => x.reihe === reihe);
    if (!items.length) return alert(`Keine Daten für ${reihe} gefunden.`);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makeRowSheet(reihe, items), reihe);
    downloadWb(wb, `Lagerplatzkontrolle_${reihe}.xlsx`);
  });

  btnPallets?.addEventListener("click", () => {
    const data = getData(); if (!data) return;
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makePalletsPerRowSheet(data), "Paletten je Reihe");
    downloadWb(wb, "Paletten_je_Reihe.xlsx");
  });
})();
function sanitizeSheetName(name) {
  let s = String(name || "Sheet");
  s = s.replace(/[:\\/?*\[\]]/g, "-");
  s = s.replace(/\s+/g, " ").trim();
  if (s.length > 31) s = s.slice(0, 31);
  return s || "Sheet";
}

function inventurRowSheetName(rowKey) {
  const hall = window.currentHall || "H4";

  // wenn ihr Row-Labels habt (z.B. "H4/R109"), nimm die:
  if (typeof getRowNameOnly === "function") {
    const n = (getRowNameOnly(String(rowKey)) || "").trim();
    if (n) return sanitizeSheetName(n.replaceAll("/", "-"));
  }

  // fallback
  const r = parseInt(rowKey, 10);
  const rn = (r < 100) ? String(r).padStart(2, "0") : String(r);
  return sanitizeSheetName(`${hall}-R${rn}`);
}

function makeUniqueSheetName(wb, baseName) {
  let name = sanitizeSheetName(baseName);

  // Excel-Limit
  if (name.length > 31) name = name.slice(0, 31);

  // Falls Name schon existiert -> " (2)", " (3)", ...
  if (!wb.SheetNames.includes(name)) return name;

  let i = 2;
  while (true) {
    const suffix = ` (${i})`;
    let tryName = name;

    // Platz für Suffix schaffen
    if (tryName.length + suffix.length > 31) {
      tryName = tryName.slice(0, 31 - suffix.length);
    }
    tryName = tryName + suffix;

    if (!wb.SheetNames.includes(tryName)) return tryName;
    i++;
  }
}
function inventurRowSheetBaseName(rowKey) {
  const hall = window.currentHall || "H4";
  const r = parseInt(rowKey, 10);
  const rn = (r < 100) ? String(r).padStart(2, "0") : String(r);

  // Wenn ihr schöne Namen habt, ok – aber "Frei" ist ungeeignet als Sheetname
  if (typeof getRowNameOnly === "function") {
    const n = (getRowNameOnly(String(rowKey)) || "").trim();
    if (n && n.toLowerCase() !== "frei") {
      return n.replaceAll("/", "-");
    }
  }

  // Fallback ist immer eindeutig
  return `${hall}-R${rn}`;
}
const TEMPLATE_URL = "/LKW/Lagerplan/templates/Lagerplatzkontrolle_Vorlage.xlsx";

document.getElementById("btnInventurExcel").addEventListener("click", async () => {
  try {
    if (!Array.isArray(window.lagerSlots) || window.lagerSlots.length === 0) {
      throw new Error("Keine Lager-Slots im Frontend gefunden (window.lagerSlots leer).");
    }
    await exportInventurExcel(window.lagerSlots);
  } catch (e) {
    console.error(e);
    alert(e.message || e);
  }
});



async function exportInventurExcel(slots) {
  const wb = await loadTemplateWorkbook(TEMPLATE_URL);

  // 1) Daten gruppieren: pro Reihe -> pro Sachnummer(+Stück/Palette) -> Paletten zählen
  const byRow = groupInventur(slots);

  // 2) Jede Reihe in passendes Tabellenblatt schreiben (H4-R04 usw.)
  for (const [rowNoStr, items] of Object.entries(byRow)) {
    const rowNo = Number(rowNoStr);
    const sheetName = `H4-R${String(rowNo).padStart(2, "0")}`;

    let ws = wb.Sheets[sheetName];

    // Falls die Vorlage das Blatt nicht hat: aus erstem Blatt kopieren
    if (!ws) {
      const base = wb.Sheets[wb.SheetNames[0]];
      ws = cloneSheet(base);
      XLSX.utils.book_append_sheet(wb, ws, uniqueSheetName(wb, sheetName));
    } else {
      // nur Eingabespalten leeren, Format/Formeln bleiben (so gut es die Library kann)
      clearInventurArea(ws, 2, 200);
    }

    // Ab Zeile 2 befüllen (deine Vorlage hat Header in Zeile 1)
    let r = 2;
    for (const it of items) {
      setCellKeepStyle(ws, `B${r}`, it.sachnr);        // Sachnummer
      setCellKeepStyle(ws, `F${r}`, it.stueckPalette); // Stück/Palette
      setCellKeepStyle(ws, `G${r}`, it.paletten);      // Paletten
      // H bleibt Vorlage (Formel)
      r++;
    }
  }

  // ✅ Excel zwingen, alle Formeln beim Öffnen neu zu berechnen
wb.Workbook = wb.Workbook || {};
wb.Workbook.CalcPr = {
  fullCalcOnLoad: true
};

const filename = `Inventur_H4_${new Date().toISOString().slice(0, 10)}.xlsx`;
XLSX.writeFile(wb, filename, { compression: true });

}

async function loadTemplateWorkbook(url) {
  const res = await fetch(url, { cache: "no-store" });
  if (!res.ok) throw new Error("template_load_failed: " + res.status);
  const ab = await res.arrayBuffer();

  // cellStyles hilft beim Lesen, aber:
  // WICHTIG: SheetJS Community schreibt Styles NICHT zuverlässig zurück (siehe Hinweis unten).
  return XLSX.read(ab, { type: "array", cellStyles: true });
}

// slots -> {4:[{sachnr, stueckPalette, paletten}], 5:[...], ...}
function groupInventur(slots) {
  const map = {}; // row -> key -> item

  for (const s of slots) {
    const row = Number(s.reihe ?? s.row);
    const sach = String(s.sachnummer ?? s.sachnr ?? "").trim();
    if (!row || !sach) continue;

    const stueck = Number(s.stueck_palette ?? s.stueckPalette ?? s.stueck ?? 0);

    if (!map[row]) map[row] = {};

    // wenn gleiche Sachnummer aber unterschiedliche Stück/Palette existiert, getrennt zählen
    const key = sach + "||" + stueck;

    if (!map[row][key]) {
      map[row][key] = { sachnr: sach, stueckPalette: stueck, paletten: 0 };
    }
    map[row][key].paletten += 1;
  }

  const out = {};
  for (const [row, obj] of Object.entries(map)) {
    out[row] = Object.values(obj).sort((a, b) => a.sachnr.localeCompare(b.sachnr));
  }
  return out;
}

function clearInventurArea(ws, fromRow = 2, toRow = 200) {
  for (let r = fromRow; r <= toRow; r++) {

    // Eingabespalten leeren
    for (const col of ["B", "F", "G"]) {
      const a = col + r;
      if (ws[a]) {
        ws[a].t = "s";
        ws[a].v = "";
        delete ws[a].f;
      }
    }

    // Spalte E NUR neutralisieren, NICHT löschen
    const e = `E${r}`;
    if (ws[e]) {
      ws[e].t = "s";
      ws[e].v = "";
      delete ws[e].f;
    }
  }
}


function setCellKeepStyle(ws, addr, value) {
  const cell = ws[addr] || {};
  if (typeof value === "number" && Number.isFinite(value)) {
    cell.t = "n";
    cell.v = value;
  } else {
    cell.t = "s";
    cell.v = value ?? "";
  }
  ws[addr] = cell;
}

function cloneSheet(ws) {
  return (typeof structuredClone === "function")
    ? structuredClone(ws)
    : JSON.parse(JSON.stringify(ws));
}

// verhindert Fehler wie: "Worksheet with name |Frei| already exists!"
function uniqueSheetName(wb, name) {
  let n = name, i = 1;
  while (wb.Sheets[n]) {
    i++;
    n = (name.slice(0, 28) + "_" + i); // Excel max ~31 Zeichen
  }
  return n;
}
(function () {
  function initInventurExcelBtn() {
    const btn = document.getElementById("btnInventurExcel");
    if (!btn) {
      console.warn("❌ btnInventurExcel nicht gefunden (ID prüfen / Script läuft zu früh).");
      return;
    }

    btn.addEventListener("click", onInventurExcelClick);
    console.log("✅ Inventur Excel Button aktiv");
  }

  async function onInventurExcelClick() {
    try {
      if (typeof exportInventurExcel !== "function") {
        throw new Error("exportInventurExcel() fehlt – Script mit Export-Funktion wird nicht geladen.");
      }

      // Daten holen (siehe Punkt 2)
      const data = await getInventurExportData();
      if (!data || data.length === 0) {
        throw new Error("Export-Daten nicht vorhanden.");
      }

      await exportInventurExcel(data);
    } catch (e) {
      console.error(e);
      alert(e.message || e);
    }
  }

  async function getInventurExportData(row = "") {
  const qs = row ? `?row=${encodeURIComponent(row)}` : "";
  const url = `/LKW/Lagerplan/api/halle3_export_slots.php${qs}`;

  const res = await fetch(url, { cache: "no-store" });
  const raw = await res.text(); // <-- wichtig!

  let data;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    // Hier siehst du direkt die PHP-Fehlermeldung als HTML
    console.error("API liefert kein JSON. Antwort (Anfang):", raw.slice(0, 500));
    throw new Error("API liefert kein JSON (PHP-Fehler). Schau in die Konsole (Antwort-Anfang geloggt).");
  }

  if (!res.ok) {
    throw new Error(data?.error || `Export-API Fehler (${res.status})`);
  }

  // falls du {ok:true, rows:[...]} zurückgibst:
  return Array.isArray(data.rows) ? data.rows : data;
}



  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initInventurExcelBtn);
  } else {
    initInventurExcelBtn();
  }
})();
