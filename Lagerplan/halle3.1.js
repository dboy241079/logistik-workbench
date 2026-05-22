/* -------------------------------------------------------
   UI Helper: Buttons kurz deaktivieren + Bootstrap-Spinner
------------------------------------------------------- */

function setBtnLoading(btn, isLoading, opts = {}) {
  if (!btn) return;

  if (isLoading) {
    // Original-Text merken (einmalig)
    if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;

    const loadingText = opts.loadingText || btn.dataset.loadingText || "Export läuft…";

    btn.disabled = true;
    btn.innerHTML = `
      <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
      <span>${loadingText}</span>
    `;
  } else {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
  }
}

/**
 * Wrapper: zeigt Loading an, verhindert Doppelklick.
 * - Wenn fn ein Promise returned => wartet bis fertig
 * - Wenn fn sync ist (z.B. Download per a.click()/window.location) => zeigt Spinner kurz (fallbackMs)
 */
async function withBtnLoading(btn, fn, { minMs = 400, fallbackMs = 1200, loadingText } = {}) {
  const t0 = (typeof performance !== "undefined" ? performance.now() : Date.now());

  setBtnLoading(btn, true, { loadingText });

  try {
    const res = fn?.();

    // Async? dann warten. Sync? dann kurze "Profi"-Wartezeit.
    if (res && typeof res.then === "function") {
      await res;
    } else {
      await new Promise(r => setTimeout(r, fallbackMs));
    }

    // mind. minMs sichtbar lassen (gegen Flackern)
    const t1 = (typeof performance !== "undefined" ? performance.now() : Date.now());
    const elapsed = t1 - t0;
    if (elapsed < minMs) {
      await new Promise(r => setTimeout(r, minMs - elapsed));
    }
  } finally {
    setBtnLoading(btn, false);
  }
}

/* -------------------------------------------------------
   Excel Export Buttons: Loading integrieren
------------------------------------------------------- */
function initExcelExportButtonsLoading() {
  const btnAll = document.getElementById("btnXlsxAll");
  const btnPerRow = document.getElementById("btnXlsxPerRow");
  const btnRow = document.getElementById("btnXlsxRow");
  const rowSel = document.getElementById("xlsxRowSel");

  // Optional: Button "Reihe" deaktivieren, wenn nichts gewählt
  const updateRowBtnState = () => {
    if (!btnRow) return;
    const hasValue = !!rowSel?.value;
    btnRow.disabled = !hasValue;
  };
  rowSel?.addEventListener("change", updateRowBtnState);
  updateRowBtnState();

  btnAll?.addEventListener("click", (e) => {
    const btn = e.currentTarget;
    withBtnLoading(btn, () => {
      // ⬇️ HIER deine bestehende Funktion aufrufen:
      // exportXlsxAll();
      return exportXlsxAll?.(); // falls sie existiert
    }, { loadingText: "Exportiere Gesamt…" });
  });

  btnPerRow?.addEventListener("click", (e) => {
    const btn = e.currentTarget;
    withBtnLoading(btn, () => {
      // ⬇️ HIER deine bestehende Funktion aufrufen:
      // exportXlsxPerRow();
      return exportXlsxPerRow?.();
    }, { loadingText: "Exportiere Blätter…" });
  });

  btnRow?.addEventListener("click", (e) => {
    const btn = e.currentTarget;

    // falls nix gewählt: raus
    if (!rowSel?.value) return;

    withBtnLoading(btn, () => {
      // ⬇️ HIER deine bestehende Funktion aufrufen:
      // exportXlsxRow(rowSel.value);
      return exportXlsxRow?.(rowSel.value);
    }, { loadingText: `Exportiere Reihe ${rowSel.value}…` });
  });
}

/* -------------------------------------------------------
   Init (einmal beim Laden)
------------------------------------------------------- */
document.addEventListener("DOMContentLoaded", () => {
  initExcelExportButtonsLoading();
});
// Helper: holt eine Funktion sicher aus dem globalen Scope
function getGlobalFn(name) {
  const fn = window[name];
  return (typeof fn === "function") ? fn : null;
}

function initExcelExportButtonsLoading() {
  const btnAll = document.getElementById("btnXlsxAll");
  const btnPerRow = document.getElementById("btnXlsxPerRow");
  const btnRow = document.getElementById("btnXlsxRow");
  const rowSel = document.getElementById("xlsxRowSel");

  const updateRowBtnState = () => {
    if (!btnRow) return;
    const hasValue = !!rowSel?.value;
    btnRow.disabled = !hasValue;
  };
  rowSel?.addEventListener("change", updateRowBtnState);
  updateRowBtnState();

  btnAll?.addEventListener("click", (e) => {
    const btn = e.currentTarget;
    const fn = getGlobalFn("exportXlsxAll");
    if (!fn) return console.error("exportXlsxAll() nicht gefunden. Prüfe Funktionsnamen / Scope.");

    withBtnLoading(btn, () => fn(), { loadingText: "Exportiere Gesamt…" });
  });

  btnPerRow?.addEventListener("click", (e) => {
    const btn = e.currentTarget;
    const fn = getGlobalFn("exportXlsxPerRow");
    if (!fn) return console.error("exportXlsxPerRow() nicht gefunden. Prüfe Funktionsnamen / Scope.");

    withBtnLoading(btn, () => fn(), { loadingText: "Exportiere Blätter…" });
  });

  btnRow?.addEventListener("click", (e) => {
    const btn = e.currentTarget;
    if (!rowSel?.value) return;

    const fn = getGlobalFn("exportXlsxRow");
    if (!fn) return console.error("exportXlsxRow(row) nicht gefunden. Prüfe Funktionsnamen / Scope.");

    withBtnLoading(btn, () => fn(rowSel.value), { loadingText: `Exportiere Reihe ${rowSel.value}…` });
  });
}
