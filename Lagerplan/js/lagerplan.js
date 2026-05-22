document.addEventListener("DOMContentLoaded", () => {

  function applyPatch(patch) {
    if (!patch) return;

    // Wir unterstützen 2 Formate:
    // 1) patch.row (dein altes Schema)
    // 2) patch direkt als row
    const row = patch.row || patch;
    if (!row) return;

    // deleted -> UI leeren (wenn du soft delete nutzt)
    if (row.deleted_at) {
      const slotEl = document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(String(row.id))}"]`);
      if (slotEl && typeof resetSlotUI === "function") {
        const platzEl = slotEl.closest(".platz");
        resetSlotUI(slotEl);
        if (platzEl && typeof updatePlatzLabel === "function") updatePlatzLabel(platzEl);
      }
      return;
    }

    // normale Updates (Einlagerung/Move/Korrektur)
    if (typeof window.applyLiveRow === "function") {
      window.applyLiveRow(row); // optional wenn du sowas schon hast
    } else {
      // fallback: nur Menge/Sach aktualisieren per slot_id
      const slotEl =
        document.querySelector(`.palette-slot[data-slot-id="${CSS.escape(String(row.id))}"]`);
      if (slotEl) {
        if (row.sachnummer != null) slotEl.dataset.sach = row.sachnummer;
        if (row.menge != null) slotEl.dataset.menge = String(row.menge);
      }
    }
  }

  // ✅ Guard: kein Crash wenn Live nicht geladen
  if (!window.LagerplanLive || typeof window.LagerplanLive.init !== "function") {
    console.warn("⚠ LagerplanLive fehlt – js/lagerplan.live.js lädt nicht oder exportiert nicht window.LagerplanLive");
    return;
  }

  window.LagerplanLive.init({
    // ✅ ABSOLUTER Pfad, damit’s immer stimmt
    pollUrl: "/Lagerplan/lager_live.php",
    pollInterval: 1500,
    debug: false,
    onPatch: applyPatch
  });

});
