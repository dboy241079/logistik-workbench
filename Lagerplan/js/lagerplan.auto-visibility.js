// /LKW/Lagerplan/js/lagerplan.auto-visibility.js
(() => {
  "use strict";

  const PLAN_ID = "planViewport";
  const PLACEHOLDER_ID = "planAutoPlaceholder";

  function getPlan() {
    return document.getElementById(PLAN_ID);
  }

  function ensurePlaceholder() {
    const plan = getPlan();
    if (!plan) return null;

    let box = document.getElementById(PLACEHOLDER_ID);
    if (box) return box;

    box = document.createElement("div");
    box.id = PLACEHOLDER_ID;
    box.className = "card shadow-sm mb-3";

    box.innerHTML = `
      <div class="card-body py-3">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
          <div>
            <div class="fw-semibold">Lagerplan ist ausgeblendet</div>
            <div class="text-muted small">
              Der Plan wird automatisch angezeigt, sobald du suchst, einlagerst oder Warenausgang buchst.
            </div>
          </div>

          <button type="button" id="btnShowPlanManual" class="btn btn-outline-primary btn-sm">
            Lagerplan anzeigen
          </button>
        </div>
      </div>
    `;

    plan.parentElement?.insertBefore(box, plan);

    box.querySelector("#btnShowPlanManual")?.addEventListener("click", () => {
      showPlanView("manual-open", true);
    });

    return box;
  }

  function hidePlanView() {
    const plan = getPlan();
    const box = ensurePlaceholder();

    if (!plan) return;

    plan.style.display = "none";
    box?.classList.remove("d-none");
  }

  function showPlanView(reason = "", scrollToPlan = false) {
    const plan = getPlan();
    const box = ensurePlaceholder();

    if (!plan) return;

    plan.style.display = "";
    box?.classList.add("d-none");

    if (scrollToPlan) {
      requestAnimationFrame(() => {
        plan.scrollIntoView({
          behavior: "smooth",
          block: "start"
        });
      });
    }

    if (window.setStatus && reason) {
      const label = {
        search: "Lagerplan für Suche geöffnet.",
        manual: "Lagerplan für manuelle Einlagerung geöffnet.",
        outbook: "Lagerplan für Warenausgang geöffnet.",
        jump: "Lagerplan für Positionssprung geöffnet.",
        "mobile-row": "Lagerplan für Reihe geöffnet."
      }[reason] || "Lagerplan geöffnet.";

      window.setStatus(label, "info");
    }
  }

  function addHideButtonInsidePlan() {
    const plan = getPlan();
    if (!plan || document.getElementById("btnHidePlanAgain")) return;

    const btn = document.createElement("button");
    btn.id = "btnHidePlanAgain";
    btn.type = "button";
    btn.className = "btn btn-sm btn-outline-secondary position-absolute";
    btn.style.right = "8px";
    btn.style.top = "8px";
    btn.style.zIndex = "1000000";
    btn.textContent = "Plan ausblenden";

    btn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      hidePlanView();
    });

    plan.appendChild(btn);
  }

  function bindAutoOpenTriggers() {
    // Klicks: Suche, Scanner, manuelle Einlagerung, Warenausgang, LG-Positionen
    document.addEventListener("click", (e) => {
      const target = e.target;

      if (
        target.closest("#btnSearchRef") ||
        target.closest("#btnOpenMobileScanner") ||
        target.closest("#btnToggleManual") ||
        target.closest("#btnManualSave") ||
        target.closest("#btnOutbook") ||
        target.closest("#btnOpenOutRefScanner") ||
        target.closest(".lg-position-btn") ||
        target.closest("[data-search-show-all-rows]")
      ) {
        showPlanView("jump", false);
      }
    }, true);

    // Eingaben / Auswahl
    document.addEventListener("change", (e) => {
      const target = e.target;

      if (
        target.matches("#manualRow") ||
        target.matches("#manualPlatz") ||
        target.matches("#mobileRowSel") ||
        target.matches("[data-mobile-row]")
      ) {
        showPlanView("manual", false);
      }
    }, true);

    document.addEventListener("input", (e) => {
      const target = e.target;

      if (
        target.matches("#manualPlatz") ||
        target.matches("#outRef")
      ) {
        showPlanView("manual", false);
      }
    }, true);

    // Enter in Suchfeldern
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;

      const target = e.target;

      if (
        target.matches("#searchRefInput") ||
        target.matches("#outRef") ||
        target.matches("#manualRef")
      ) {
        showPlanView("search", false);
      }
    }, true);
  }

  function patchGlobalJumpFunctions() {
    const patch = (name, reason) => {
      const oldFn = window[name];

      if (typeof oldFn !== "function") return;
      if (oldFn.__planVisibilityPatched) return;

      const patched = function (...args) {
        showPlanView(reason || "jump", false);
        return oldFn.apply(this, args);
      };

      patched.__planVisibilityPatched = true;
      window[name] = patched;
    };

    patch("jumpToSlot", "jump");
    patch("focusRow", "mobile-row");
    patch("showOnlySearchRow", "search");
    patch("showOnlyLgRow", "jump");
    patch("openAssignModal", "manual");
    patch("openSlotOverlay", "jump");
  }

  function initPlanAutoVisibility() {
    ensurePlaceholder();
    addHideButtonInsidePlan();

    // Standard: Plan verstecken
    hidePlanView();

    bindAutoOpenTriggers();

    // Kurz verzögert, weil halle3.js Funktionen evtl. erst dann global verfügbar sind
    setTimeout(patchGlobalJumpFunctions, 300);
    setTimeout(patchGlobalJumpFunctions, 1000);
  }

  window.showPlanView = showPlanView;
  window.hidePlanView = hidePlanView;

  document.addEventListener("DOMContentLoaded", initPlanAutoVisibility);
})();