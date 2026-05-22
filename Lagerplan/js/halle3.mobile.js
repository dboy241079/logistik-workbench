// /LKW/Lagerplan/js/halle3.mobile.js
(() => {
  const isMobile = window.matchMedia("(max-width: 768px)").matches;
  if (!isMobile) return;

  window.__mobileRowPickerManaged = true;

  const BLOCK_ID = window.BLOCK_ID || "w1-block-16-19";
  const SELECT_ID = "mobileRowSel";
  const API_LAGER_CFG = "/Lagerplan/api/lager_config_get.php";

  function cssEscape(v) {
    try {
      return CSS.escape(String(v));
    } catch {
      return String(v).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
    }
  }

  function getHall() {
    return window.currentHall || window.LAGERPLAN_CFG?.hall || "H3";
  }

  function getZone() {
    return window.currentZone || window.LAGERPLAN_CFG?.zone || "W1";
  }

  function getRangeFromGlobalConfig() {
    const cfg = window.__LAGER_CFG__;
    if (!cfg) return null;

    const from = parseInt(cfg.row_from ?? 1, 10) || 1;
    const to = parseInt(cfg.row_to ?? 0, 10) || 0;

    if (to >= from) {
      return { from, to };
    }

    return null;
  }

  function getRangeFromExistingSelect() {
    const source =
      document.getElementById("manualRow") ||
      document.getElementById("rmFromRow") ||
      document.getElementById("xlsxRowSel");

    if (!source) return null;

    const values = [...source.querySelectorAll("option")]
      .map(opt => parseInt(opt.value, 10))
      .filter(n => Number.isFinite(n) && n > 0);

    if (!values.length) return null;

    return {
      from: Math.min(...values),
      to: Math.max(...values)
    };
  }

  async function getRangeFromApi() {
    const url = new URL(API_LAGER_CFG, location.origin);
    url.searchParams.set("halle", getHall());
    url.searchParams.set("zone", getZone());

    const res = await fetch(url.toString(), {
      credentials: "same-origin",
      cache: "no-store"
    });

    const js = await res.json();

    if (!res.ok || js.ok !== true) {
      throw new Error(js?.msg || "Lager-Konfiguration konnte nicht geladen werden.");
    }

    window.__LAGER_CFG__ = js;

    const from = parseInt(js.row_from ?? 1, 10) || 1;
    const to = parseInt(js.row_to ?? 0, 10) || 0;

    if (to < from) {
      throw new Error("Ungültige Reihen-Konfiguration.");
    }

    return { from, to };
  }

  async function getBestRowRange() {
    return (
      getRangeFromGlobalConfig() ||
      getRangeFromExistingSelect() ||
      await getRangeFromApi().catch(() => null) ||
      { from: 1, to: 180 }
    );
  }

  function fillMobileSelect(sel, from, to) {
    if (!sel) return;

    const oldValue = sel.value;

    let html = `<option value="">– wählen –</option>`;

    for (let i = from; i <= to; i++) {
      html += `<option value="${i}">${i}</option>`;
    }

    sel.innerHTML = html;

    if (oldValue && parseInt(oldValue, 10) >= from && parseInt(oldValue, 10) <= to) {
      sel.value = oldValue;
    }
  }

  async function refreshMobileRowOptions() {
    const sel = document.getElementById(SELECT_ID);
    if (!sel) return;

    const range = await getBestRowRange();
    fillMobileSelect(sel, range.from, range.to);

    console.log("✅ Mobile-Reihen geladen:", range.from, "bis", range.to);
  }

  async function ensureMobileRowPicker() {
    const plan = document.getElementById("planViewport") || document.getElementById(BLOCK_ID);
    if (!plan) return;

    if (document.getElementById(SELECT_ID)) {
      await refreshMobileRowOptions();
      return;
    }

    const wrap = document.createElement("div");
    wrap.className = "card shadow-sm mb-2";
    wrap.innerHTML = `
      <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2">
          <div class="fw-semibold">Mobile: Reihe</div>

          <select id="${SELECT_ID}" data-mobile-row class="form-select form-select-sm" style="max-width:140px">
            <option value="">Lade...</option>
          </select>

          <button id="mobileShowAll" class="btn btn-outline-secondary btn-sm" type="button">
            Alle
          </button>
        </div>

        <div class="form-text mb-0">
          Tipp: Auf Handy nur 1 Reihe anzeigen = viel schneller.
        </div>
      </div>
    `;

    plan.parentElement?.insertBefore(wrap, plan);

    const sel = wrap.querySelector(`#${SELECT_ID}`);
    if (!sel) return;

    await refreshMobileRowOptions();

    sel.addEventListener("change", () => {
      const r = String(sel.value || "").trim();
      if (!r) return;

      showOnlyRow(r);

      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          scrollToRow(r);
        });
      });
    });

    wrap.querySelector("#mobileShowAll")?.addEventListener("click", () => {
      showAllRows();
      sel.value = "";
    });

    // Falls halle3.js die Admin-Konfiguration etwas später setzt:
    setTimeout(refreshMobileRowOptions, 700);
    setTimeout(refreshMobileRowOptions, 1500);
  }

  function showOnlyRow(row) {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    if (typeof window.ensureRowRendered === "function") {
      window.ensureRowRendered(row);
    }

    block.querySelectorAll(".platz-container[data-row]").forEach(c => {
      const isRow = String(c.dataset.row) === String(row);
      c.style.display = isRow ? "" : "none";

      const tb = c.previousElementSibling;
      if (tb?.classList?.contains("row-toolbar")) {
        tb.style.display = isRow ? "" : "none";
      }
    });
  }

  function showAllRows() {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    block.querySelectorAll(".platz-container[data-row]").forEach(c => {
      c.style.display = "";

      const tb = c.previousElementSibling;
      if (tb?.classList?.contains("row-toolbar")) {
        tb.style.display = "";
      }
    });
  }

  function scrollToRow(row) {
    const block = document.getElementById(BLOCK_ID);
    if (!block) return;

    if (typeof window.ensureRowRendered === "function") {
      window.ensureRowRendered(row);
    }

    const target =
      block.querySelector(`.row-toolbar[data-row="${cssEscape(row)}"]`) ||
      block.querySelector(`.platz[data-row="${cssEscape(row)}"]`) ||
      block.querySelector(`.platz-container[data-row="${cssEscape(row)}"]`);

    if (!target) {
      console.warn("mobile jump: target not found for row", row);
      return;
    }

    target.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "center"
    });

    setTimeout(() => {
      window.scrollBy({
        top: -80,
        behavior: "smooth"
      });
    }, 150);
  }

  document.addEventListener("DOMContentLoaded", () => {
    ensureMobileRowPicker().catch(err => {
      console.error("Mobile-Reihen-Auswahl konnte nicht geladen werden:", err);
    });
  });
})();