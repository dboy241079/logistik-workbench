(() => {
  const isMobile = window.matchMedia("(max-width: 768px)").matches;
  if (!isMobile) return;

  window.__mobileRowPickerManaged = true; // ✅ verhindert Doppel-Binding in halle3.js (wenn du den Guard nutzt)

  const BLOCK_ID = window.BLOCK_ID || "w1-block-16-19";
  const SELECT_ID = "mobileRowSel";

  function cssEscape(v) {
    try { return CSS.escape(String(v)); }
    catch { return String(v).replace(/[^a-zA-Z0-9_-]/g, "\\$&"); }
  }

  function ensureMobileRowPicker() {
    const plan = document.getElementById("planViewport") || document.getElementById(BLOCK_ID);
    if (!plan) return;

    if (document.getElementById(SELECT_ID)) return;

    const wrap = document.createElement("div");
    wrap.className = "card shadow-sm mb-2";
    wrap.innerHTML = `
      <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2">
          <div class="fw-semibold">Mobile: Reihe</div>
          <select id="${SELECT_ID}" data-mobile-row class="form-select form-select-sm" style="max-width:140px"></select>
          <button id="mobileShowAll" class="btn btn-outline-secondary btn-sm">Alle</button>
        </div>
        <div class="form-text mb-0">Tipp: Auf Handy nur 1 Reihe anzeigen = viel schneller.</div>
      </div>
    `;

    plan.parentElement?.insertBefore(wrap, plan);

    const sel = wrap.querySelector(`#${SELECT_ID}`);
    if (!sel) return;

    sel.innerHTML =
      `<option value="">– wählen –</option>` +
      Array.from({ length: 180 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join("");

    sel.addEventListener("change", () => {
      const r = String(sel.value || "").trim();
      if (!r) return;

      showOnlyRow(r);

      // ✅ erst rendern/anzeigen, dann springen
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          scrollToRow(r);
        });
      });
    });

    wrap.querySelector("#mobileShowAll")?.addEventListener("click", () => {
      showAllRows();
    });
  }

  function showOnlyRow(row) {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    // Optional: wenn du irgendwo Accordion-Header hast, kannst du die hier auch filtern.

    block.querySelectorAll('.platz-container[data-row]').forEach(c => {
      const isRow = (String(c.dataset.row) === String(row));
      c.style.display = isRow ? "" : "none";

      // Toolbar mit ausblenden
      const tb = c.previousElementSibling;
      if (tb?.classList?.contains("row-toolbar")) {
        tb.style.display = isRow ? "" : "none";
      }
    });

    // Lazy render (nur wenn global)
    if (typeof window.ensureRowRendered === "function") window.ensureRowRendered(row);
  }

  function showAllRows() {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    block.querySelectorAll('.platz-container[data-row]').forEach(c => {
      c.style.display = "";
      const tb = c.previousElementSibling;
      if (tb?.classList?.contains("row-toolbar")) tb.style.display = "";
    });
  }

  function scrollToRow(row) {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    // ✅ wenn möglich sicherstellen, dass die Reihe wirklich gerendert ist
    if (typeof window.ensureRowRendered === "function") window.ensureRowRendered(row);

    // ✅ bestes Ziel: Toolbar der Reihe, sonst erster Platz, sonst Container
    const target =
      block.querySelector(`.row-toolbar[data-row="${cssEscape(row)}"]`) ||
      block.querySelector(`.platz[data-row="${cssEscape(row)}"]`) ||
      block.querySelector(`.platz-container[data-row="${cssEscape(row)}"]`);

    if (!target) {
      console.warn("mobile jump: target not found for row", row);
      return;
    }

    target.scrollIntoView({ behavior: "smooth", block: "center", inline: "center" });


    // ✅ falls du eine sticky Navbar hast -> bisschen hochziehen
    const offset = 80;
    setTimeout(() => {
      window.scrollBy({ top: -offset, behavior: "smooth" });
    }, 150);
  }

  document.addEventListener("DOMContentLoaded", ensureMobileRowPicker);
})();
