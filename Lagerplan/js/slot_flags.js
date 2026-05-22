(() => {
  "use strict";

  const FLAG_API = "/Lagerplan/lager_flags_api.php";
  const MOVE_API = "/Lagerplan/lager_move.php";

  const FLAG_LABEL = {
    VW_MISSING:   "VW fehlt",
    LOC_WRONG:    "Falscher Platz",
    VW_LOC_WRONG: "VW-Position abweichend",
    NEEDS_CHECK:  "Prüfen"
  };

  const FLAG_CLASS = {
    VW_MISSING:   "flag-vw-missing",
    LOC_WRONG:    "flag-loc-wrong",
    VW_LOC_WRONG: "flag-vw-loc-wrong",
    NEEDS_CHECK:  "flag-needs-check"
  };

  let _flagsBySlotId = new Map();
  let _tRefresh = null;
  let _onlyFlagsMode = false;

  function getBlockId() {
    // halle3.js hat meist const BLOCK_ID = "w1-block-16-19";
    try {
      if (typeof BLOCK_ID !== "undefined" && BLOCK_ID) return String(BLOCK_ID);
    } catch (_) {}
    return "w1-block-16-19";
  }

  function cssEscape(v) {
    try { return CSS.escape(String(v)); }
    catch { return String(v).replace(/[^a-zA-Z0-9_-]/g, "\\$&"); }
  }

  function apiFetchJson(url, opts) {
    return fetch(url, { cache: "no-store", credentials: "same-origin", ...(opts||{}) })
      .then(async (res) => {
        const txt = await res.text();
        let j = {};
        try { j = JSON.parse(txt); } catch (_) {}
        if (!res.ok || j.ok !== true) {
          const msg = j?.msg || j?.error || `HTTP ${res.status}`;
          const err = new Error(msg);
          err.detail = j?.detail || txt;
          throw err;
        }
        return j;
      });
  }

  function injectFlagStyles() {
    if (document.getElementById("flagStyles")) return;
    const s = document.createElement("style");
    s.id = "flagStyles";
    s.textContent = `
      .palette-slot{ position:relative; }

      /* Slot-Markierung im Plan */
      .palette-slot.flagged::after{
        content:"⚠";
        position:absolute;
        top:-6px; right:-6px;
        font-size:10px;
        background:#fff;
        border:1px solid #cbd5e1;
        border-radius:999px;
        padding:1px 2px;
        line-height:1;
      }

      .palette-slot.flag-vw-missing{ outline:2px solid #ef4444; }
      .palette-slot.flag-loc-wrong{ outline:2px solid #f59e0b; }
      .palette-slot.flag-vw-loc-wrong{ outline:2px solid #f97316; }
      .palette-slot.flag-needs-check{ outline:2px solid #64748b; }

      /* Tooltip im Platz-Overlay (mini ⚠ rechts) */
      .flagTipWrap{ position:relative; display:inline-flex; align-items:center; }
      .flagMini{
        font-size:12px;
        line-height:1;
        padding:2px 4px;
        border-radius:8px;
        border:1px solid #cbd5e1;
        background:#fff;
        cursor:help;
        user-select:none;
      }
      .flagTip{
        display:none;
        position:absolute;
        right:0;
        top:120%;
        background:#111827;
        color:#fff;
        padding:6px 8px;
        border-radius:10px;
        font-size:11px;
        max-width:260px;
        white-space:normal;
        z-index:999999;
        box-shadow:0 8px 24px rgba(0,0,0,.18);
      }
      .flagTip::before{
        content:"";
        position:absolute;
        right:10px;
        top:-6px;
        border-width:6px;
        border-style:solid;
        border-color:transparent transparent #111827 transparent;
      }
      .flagTipWrap:hover .flagTip{ display:block; }

      /* Nur-Abweichungen Modus */
      #${getBlockId()}.only-flags .palette-slot:not(.flagged){
        opacity:.12;
        filter:grayscale(1);
      }
      #${getBlockId()}.only-flags .platz:not(.platz-has-flag){
        opacity:.18;
        filter:grayscale(1);
      }
      #${getBlockId()}.only-flags .platz-label{
        opacity:.55;
      }
    `;
    document.head.appendChild(s);
  }

  function clearFlagUI(slotEl) {
    if (!slotEl) return;
    slotEl.classList.remove("flagged");
    Object.values(FLAG_CLASS).forEach(c => slotEl.classList.remove(c));
    delete slotEl.dataset.flagType;
    delete slotEl.dataset.flagNote;
    delete slotEl.dataset.flagExpRow;
    delete slotEl.dataset.flagExpPlatz;
  }

  function applyFlagUI(slotEl, flag) {
    clearFlagUI(slotEl);
    if (!flag) return;

    const type = String(flag.flag_type || "").trim();
    if (!type) return;

    slotEl.classList.add("flagged");
    if (FLAG_CLASS[type]) slotEl.classList.add(FLAG_CLASS[type]);

    slotEl.dataset.flagType = type;
    slotEl.dataset.flagNote = String(flag.note || "");
    slotEl.dataset.flagExpRow = String(flag.expected_reihe || "");
    slotEl.dataset.flagExpPlatz = (flag.expected_platz == null ? "" : String(flag.expected_platz));
  }

  function markPlacesWithFlags(blockEl) {
    if (!blockEl) return;
    blockEl.querySelectorAll(".platz.platz-has-flag").forEach(p => p.classList.remove("platz-has-flag"));
    blockEl.querySelectorAll(".palette-slot.flagged").forEach(s => {
      const p = s.closest(".platz");
      if (p) p.classList.add("platz-has-flag");
    });
  }

  function applyFlagsToUI() {
    const blockId = getBlockId();
    const blockEl = document.getElementById(blockId);

    // erst alles weg
    document.querySelectorAll(".palette-slot.flagged").forEach(clearFlagUI);

    // dann neu setzen
    document.querySelectorAll('.palette-slot[data-slot-id], .palette-slot[data-slotId]').forEach(slot => {
      const sid = String(slot.dataset.slotId || slot.getAttribute("data-slot-id") || "").trim();
      if (!sid) return;
      const f = _flagsBySlotId.get(sid);
      if (f) applyFlagUI(slot, f);
    });

    markPlacesWithFlags(blockEl);
    updateFlagsButton();
  }

  async function refreshFlags() {
    const halle = (window.currentHall || "H3");
    const zone  = (window.currentZone || "W1");

    const url = `${FLAG_API}?action=list&halle=${encodeURIComponent(halle)}&zone=${encodeURIComponent(zone)}`;
    const data = await apiFetchJson(url);

    _flagsBySlotId = new Map();
    (data.items || []).forEach(f => {
      const sid = String(f.slot_id || "").trim();
      if (sid) _flagsBySlotId.set(sid, f);
    });

    applyFlagsToUI();
  }

  function scheduleRefresh() {
    clearTimeout(_tRefresh);
    _tRefresh = setTimeout(() => refreshFlags().catch(()=>{}), 250);
  }

  // -----------------------------
  // Nur-Abweichungen Button
  // -----------------------------
  function ensureFlagsButton() {
    if (document.getElementById("btnOnlyFlags")) return;

    const card = document.getElementById("lgFilterCard");
    if (!card) return;

    const body = card.querySelector(".card-body") || card;

    const wrap = document.createElement("div");
    wrap.className = "mt-2";
    wrap.innerHTML = `
  <div class="d-grid gap-2 mt-2">
    <button id="btnOnlyFlags" type="button" class="btn btn-sm btn-outline-warning w-100">
      Nur Abweichungen
    </button>

    <button id="btnFlagsExcel" type="button" class="btn btn-sm btn-outline-success w-100">
      Excel Abweichungen
    </button>
  </div>

  <div class="text-muted small mt-1">
    Tipp: „Nur Abweichungen“ dimmt alles andere. „Excel Abweichungen“ exportiert nur markierte Paletten.
  </div>
`;

    body.appendChild(wrap);

    document.getElementById("btnOnlyFlags")?.addEventListener("click", () => {
      _onlyFlagsMode = !_onlyFlagsMode;
      const blockEl = document.getElementById(getBlockId());
      if (blockEl) blockEl.classList.toggle("only-flags", _onlyFlagsMode);

    document.getElementById("btnFlagsExcel")?.addEventListener("click", () => {
        exportFlagDeviationsXlsx();
});


      updateFlagsButton();
      window.setStatus?.(_onlyFlagsMode ? "Nur Abweichungen aktiv." : "Nur Abweichungen aus.", "info");
      
    });

    updateFlagsButton();
  }

  function countFlagsInDOM() {
    return document.querySelectorAll(".palette-slot.flagged").length;
  }

  function updateFlagsButton() {
    const btn = document.getElementById("btnOnlyFlags");
    if (!btn) return;
    const n = countFlagsInDOM();
    btn.textContent = _onlyFlagsMode ? `Nur Abweichungen: AN (${n})` : `Nur Abweichungen (${n})`;
  }

  // ---------- Modal ----------
  let _ctxSlotEl = null;

  function ensureFlagModalDom() {
    if (document.getElementById("flagModal")) return;

    document.body.insertAdjacentHTML("beforeend", `
      <div id="flagModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[999999]">
        <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
          <div class="flex items-center justify-between mb-2">
            <div class="font-semibold text-slate-800 text-sm">Abweichung markieren</div>
            <button id="fmClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
          </div>

          <div class="text-xs text-slate-700 mb-2">
            <div>Ref: <b id="fmRef">-</b></div>
            <div>Position: <b id="fmPos">-</b></div>
            <div id="fmExisting" class="mt-1"></div>
          </div>

          <div class="grid gap-2 text-xs">
            <div class="flex gap-2">
              <button id="fmQuickVW" class="bg-red-600 text-white text-xs font-semibold px-3 py-2 rounded w-full">VW fehlt</button>
              <button id="fmQuickLoc" class="bg-amber-600 text-white text-xs font-semibold px-3 py-2 rounded w-full">Falscher Platz</button>
            </div>

            <div>
              <label class="block font-semibold mb-1">Typ</label>
              <select id="fmType" class="border border-slate-300 rounded px-2 py-2 w-full text-sm">
                <option value="VW_MISSING">VW fehlt</option>
                <option value="LOC_WRONG">Falscher Platz</option>
                <option value="VW_LOC_WRONG">VW-Position abweichend</option>
                <option value="NEEDS_CHECK">Prüfen</option>
              </select>
            </div>

            <div>
              <label class="block font-semibold mb-1">Notiz (optional)</label>
              <input id="fmNote" class="border border-slate-300 rounded px-2 py-2 w-full text-sm" placeholder="z.B. bei VW nicht auffindbar / Klärung mit …">
            </div>

            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block font-semibold mb-1">Soll-Reihe (optional)</label>
                <input id="fmExpRow" class="border border-slate-300 rounded px-2 py-2 w-full text-sm" placeholder="z.B. 26">
              </div>
              <div>
                <label class="block font-semibold mb-1">Soll-Platz (optional)</label>
                <input id="fmExpPlatz" type="number" min="1" class="border border-slate-300 rounded px-2 py-2 w-full text-sm" placeholder="z.B. 2">
              </div>
            </div>

            <div id="fmMsg" class="text-[11px] text-slate-600"></div>

            <div class="flex gap-2 justify-end mt-2 flex-wrap">
              <button id="fmMoveToExpected" class="hidden bg-slate-800 text-white text-xs font-semibold px-3 py-2 rounded">
                Auf Soll umbuchen
              </button>
              <button id="fmResolve" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-2 rounded">Erledigt</button>
              <button id="fmCancel" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-2 rounded">Abbrechen</button>
              <button id="fmSave" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-2 rounded">Speichern</button>
            </div>
          </div>
        </div>
      </div>
    `);

    const modal = document.getElementById("flagModal");

    const hide = () => {
      modal.classList.add("hidden");
      modal.classList.remove("flex");
      _ctxSlotEl = null;
    };

    document.getElementById("fmClose")?.addEventListener("click", hide);
    document.getElementById("fmCancel")?.addEventListener("click", hide);
    modal?.addEventListener("click", (e) => { if (e.target === modal) hide(); });

    document.getElementById("fmQuickVW")?.addEventListener("click", () => {
      document.getElementById("fmType").value = "VW_MISSING";
      updateMoveToExpectedVisibility();
      document.getElementById("fmNote")?.focus();
    });
    document.getElementById("fmQuickLoc")?.addEventListener("click", () => {
      document.getElementById("fmType").value = "LOC_WRONG";
      updateMoveToExpectedVisibility();
      document.getElementById("fmExpRow")?.focus();
    });

    document.getElementById("fmType")?.addEventListener("change", updateMoveToExpectedVisibility);
    document.getElementById("fmExpRow")?.addEventListener("input", updateMoveToExpectedVisibility);
    document.getElementById("fmExpPlatz")?.addEventListener("input", updateMoveToExpectedVisibility);

    document.getElementById("fmSave")?.addEventListener("click", async () => {
      if (!_ctxSlotEl) return;

      const sid = String(_ctxSlotEl.dataset.slotId || "").trim();
      if (!sid) {
        setMsg("fmMsg", "Slot-ID fehlt (bitte einmal neu laden / Slot neu speichern).", "error");
        return;
      }

      const type = String(document.getElementById("fmType")?.value || "").trim();
      const note = String(document.getElementById("fmNote")?.value || "").trim();
      const expRow = String(document.getElementById("fmExpRow")?.value || "").trim();
      const expPlz = String(document.getElementById("fmExpPlatz")?.value || "").trim();

      const fd = new FormData();
      fd.append("action", "set");
      fd.append("slot_id", sid);
      fd.append("flag_type", type);
      fd.append("note", note);
      if (expRow) fd.append("expected_reihe", expRow);
      if (expPlz) fd.append("expected_platz", expPlz);

      try {
        setMsg("fmMsg", "Speichere…", "info");
        const data = await apiFetchJson(FLAG_API, { method: "POST", body: fd });
        applyFlagUI(_ctxSlotEl, data);

        scheduleRefresh();
        hide();

        window.setStatus?.(`Abweichung gesetzt: ${FLAG_LABEL[type] || type}`, "success");
      } catch (e) {
        console.error(e);
        setMsg("fmMsg", (e?.message || "Speichern fehlgeschlagen."), "error");
      }
    });

    document.getElementById("fmResolve")?.addEventListener("click", async () => {
      if (!_ctxSlotEl) return;

      const sid = String(_ctxSlotEl.dataset.slotId || "").trim();
      if (!sid) return;

      const fd = new FormData();
      fd.append("action", "resolve");
      fd.append("slot_id", sid);

      try {
        setMsg("fmMsg", "Erledige…", "info");
        await apiFetchJson(FLAG_API, { method:"POST", body: fd });

        clearFlagUI(_ctxSlotEl);
        scheduleRefresh();
        hide();

        window.setStatus?.("Abweichung erledigt.", "success");
      } catch (e) {
        console.error(e);
        setMsg("fmMsg", e?.message || "Erledigen fehlgeschlagen.", "error");
      }
    });

    document.getElementById("fmMoveToExpected")?.addEventListener("click", async () => {
      if (!_ctxSlotEl) return;

      const expRow = String(document.getElementById("fmExpRow")?.value || "").trim();
      const expPlz = parseInt(String(document.getElementById("fmExpPlatz")?.value || ""), 10);

      if (!expRow || !Number.isFinite(expPlz) || expPlz <= 0) {
        setMsg("fmMsg", "Bitte Soll-Reihe und Soll-Platz setzen.", "error");
        return;
      }

      try {
        setMsg("fmMsg", "Umbuchen auf Soll…", "info");
        await moveSlotToExpected(_ctxSlotEl, expRow, expPlz);

        // Flag automatisch erledigen
        const sid = String(_ctxSlotEl.dataset.slotId || "").trim();
        if (sid) {
          const fd = new FormData();
          fd.append("action", "resolve");
          fd.append("slot_id", sid);
          await apiFetchJson(FLAG_API, { method:"POST", body: fd }).catch(()=>{});
        }

        scheduleRefresh();
        hide();

        window.setStatus?.(`Umbuchung auf Soll erledigt: R${expRow} / P${String(expPlz).padStart(2,"0")}`, "success");
      } catch (e) {
        console.error(e);
        setMsg("fmMsg", e?.message || "Umbuchen fehlgeschlagen.", "error");
      }
    });
  }

  function setMsg(id, msg, type="info") {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = "text-[11px] mt-1 " + (
      type === "error" ? "text-red-700" :
      type === "success" ? "text-emerald-700" :
      "text-slate-600"
    );
    el.textContent = msg || "";
  }

  function updateMoveToExpectedVisibility() {
    const btn = document.getElementById("fmMoveToExpected");
    if (!btn) return;

    const t = String(document.getElementById("fmType")?.value || "").trim();
    const expRow = String(document.getElementById("fmExpRow")?.value || "").trim();
    const expPlz = parseInt(String(document.getElementById("fmExpPlatz")?.value || ""), 10);

    const okType = (t === "LOC_WRONG" || t === "VW_LOC_WRONG");
    const okTarget = !!expRow && Number.isFinite(expPlz) && expPlz > 0;

    btn.classList.toggle("hidden", !(okType && okTarget));
  }

  async function moveSlotToExpected(sourceSlotEl, toRow, toPlatzNum) {
    const sid = parseInt(String(sourceSlotEl.dataset.slotId || "0"), 10);
    if (!sid) throw new Error("Umbuchen nicht möglich: Slot-ID fehlt.");

    const halle = (window.currentHall || "H3");
    const zone  = (window.currentZone || "W1");

    // Server move
    const fd = new FormData();
    fd.append("mode", "slot");
    fd.append("halle", halle);
    fd.append("zone", zone);
    fd.append("id", String(sid));
    fd.append("to_row", String(toRow));
    fd.append("to_platz", String(toPlatzNum));

    const res = await fetch(MOVE_API, { method:"POST", body: fd, credentials:"same-origin" });
    const txt = await res.text();
    let j = {};
    try { j = JSON.parse(txt); } catch (_) {}

    if (!res.ok || j.ok !== true) {
      const msg = j?.msg || j?.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }

    const newIdx = String(j?.to?.slot_index ?? "");
    if (newIdx === "") throw new Error("Serverantwort unvollständig (to.slot_index fehlt).");

    // UI Update
    const fromPlatzEl = sourceSlotEl.closest(".platz");

    if (typeof ensureRowRendered === "function") ensureRowRendered(String(toRow));
    const blockId = getBlockId();
    const toPlzStr = String(parseInt(String(toPlatzNum),10)).padStart(2,"0");

    const toPlatzEl = document.querySelector(
      `#${cssEscape(blockId)} .platz[data-row="${cssEscape(toRow)}"][data-platz="${cssEscape(toPlzStr)}"]`
    );
    if (!toPlatzEl) throw new Error(`Zielplatz R${toRow} / P${toPlzStr} nicht gefunden (UI).`);

    const targetSlotEl = toPlatzEl.querySelector(`.palette-slot[data-slot-index="${cssEscape(newIdx)}"]`);
    if (!targetSlotEl) throw new Error("Ziel-Slot im UI nicht gefunden.");

    // Daten sichern
    const ref  = sourceSlotEl.dataset.ref || "";
    const sach = sourceSlotEl.dataset.sach || "";
    const ls   = sourceSlotEl.dataset.lieferschein || "";
    const user = sourceSlotEl.dataset.userName || "";
    const date = sourceSlotEl.dataset.date || "";
    const menge= sourceSlotEl.dataset.menge ? parseInt(sourceSlotEl.dataset.menge,10) : 1;

    // Quelle leeren
    if (typeof resetSlotUI === "function") resetSlotUI(sourceSlotEl);
    else {
      delete sourceSlotEl.dataset.ref;
      delete sourceSlotEl.dataset.sach;
      delete sourceSlotEl.dataset.lieferschein;
      delete sourceSlotEl.dataset.userName;
      delete sourceSlotEl.dataset.date;
      delete sourceSlotEl.dataset.menge;
      sourceSlotEl.classList.remove("palette-slot-used");
      sourceSlotEl.textContent = "";
    }

    // Ziel setzen
    if (typeof applySlotToUI === "function") {
      applySlotToUI(targetSlotEl, { ref, sach, lieferschein: ls, user, menge });
      if (date) targetSlotEl.dataset.date = date;
    } else {
      targetSlotEl.dataset.ref = ref;
      targetSlotEl.dataset.sach = sach;
      if (ls) targetSlotEl.dataset.lieferschein = ls;
      if (user) targetSlotEl.dataset.userName = user;
      if (date) targetSlotEl.dataset.date = date;
      targetSlotEl.dataset.menge = String(menge || 1);
      targetSlotEl.classList.add("palette-slot-used");
      targetSlotEl.textContent = String(ref).slice(-4);
    }

    targetSlotEl.dataset.slotId = String(sid);

    // Labels/Index/Status
    if (typeof updatePlatzLabel === "function") {
      if (fromPlatzEl) updatePlatzLabel(fromPlatzEl);
      updatePlatzLabel(toPlatzEl);
    }
    if (typeof rebuildSearchIndex === "function") rebuildSearchIndex();
    if (typeof updateGlobalStatus === "function") updateGlobalStatus();

    if (typeof highlightPlatz === "function") highlightPlatz(toPlatzEl);
    toPlatzEl.scrollIntoView({ behavior:"smooth", block:"center" });
  }

  function openFlagModal(slotEl) {
    ensureFlagModalDom();
    _ctxSlotEl = slotEl;

    const modal = document.getElementById("flagModal");
    const ref = slotEl?.dataset.ref || "-";

    const p = slotEl.closest(".platz");
    const r = p?.dataset.row || "?";
    const plz = p?.dataset.platz || "??";
    const sHuman = (parseInt(slotEl.dataset.slotIndex || "0", 10) + 1);

    document.getElementById("fmRef").textContent = ref;
    document.getElementById("fmPos").textContent = `R${r}-${plz}-${sHuman}`;

    // vorhandenes Flag anzeigen
    const ft = slotEl.dataset.flagType || "";
    const fn = slotEl.dataset.flagNote || "";
    const exr = slotEl.dataset.flagExpRow || "";
    const exp = slotEl.dataset.flagExpPlatz || "";

    const ex = document.getElementById("fmExisting");
    if (ex) {
      if (ft) {
        const extra = (exr || exp)
          ? ` · Soll: ${exr ? "R"+exr : ""}${exp ? "/P"+String(exp).padStart(2,"0") : ""}`
          : "";
        ex.textContent = `Aktiv: ${FLAG_LABEL[ft] || ft}${extra}${fn ? " · " + fn : ""}`;
      } else {
        ex.textContent = "";
      }
    }

    // Prefill
    document.getElementById("fmType").value = ft || "VW_MISSING";
    document.getElementById("fmNote").value = fn || "";
    document.getElementById("fmExpRow").value = exr || "";
    document.getElementById("fmExpPlatz").value = exp || "";
    setMsg("fmMsg","");

    updateMoveToExpectedVisibility();

    modal.classList.remove("hidden");
    modal.classList.add("flex");
    setTimeout(() => document.getElementById("fmNote")?.focus(), 40);
  }

  // Expose
  window.Flagging = {
    refreshFlags,
    scheduleRefresh,
    openFlagModal
  };

  document.addEventListener("DOMContentLoaded", () => {
    injectFlagStyles();
    ensureFlagsButton();
    refreshFlags().catch(()=>{});
  });

})();
function exportFlagDeviationsXlsx() {
  if (!window.XLSX) {
    alert("XLSX Library fehlt (Script-Tag prüfen).");
    return;
  }

  const halle = (window.currentHall || "H3");
  const zone  = (window.currentZone || "W1");

  // optional: LG-Filter berücksichtigen, wenn vorhanden
  const selLG = (typeof window.getSelectedLGs === "function") ? window.getSelectedLGs() : null;

  const lgMap = (window.__SACH_TO_LG__ instanceof Map) ? window.__SACH_TO_LG__ : null;

  const flagged = Array.from(document.querySelectorAll('.palette-slot.flagged[data-ref]'));

  const rows = [];
  const sumByType = new Map(); // type -> {count, qty}
  const sumByLG   = new Map(); // lg   -> {count, qty}

  for (const s of flagged) {
    const ref  = String(s.dataset.ref || "").trim();
    if (!ref) continue;

    const sach = String(s.dataset.sach || "").trim();
    const qty  = Math.max(1, parseInt(String(s.dataset.menge || "1"), 10) || 1);

    const p = s.closest(".platz");
    const istRow  = String(p?.dataset.row || "").trim();
    const istPlz  = String(p?.dataset.platz || "").trim();
    const istSlot = (parseInt(String(s.dataset.slotIndex || "0"), 10) + 1);

    const type = String(s.dataset.flagType || "").trim();
    const note = String(s.dataset.flagNote || "").trim();
    const expRow = String(s.dataset.flagExpRow || "").trim();
    const expPlzRaw = String(s.dataset.flagExpPlatz || "").trim();
    const expPlz = expPlzRaw ? String(parseInt(expPlzRaw, 10)).padStart(2, "0") : "";

    const lg = (lgMap && sach) ? String(lgMap.get(sach) || "") : "";
    if (selLG && lg && !selLG.has(lg)) continue; // falls Filter aktiv

    const itemsCount = parseInt(String(s.dataset.itemsCount || "0"), 10) || 0;
    const itemsQty   = parseInt(String(s.dataset.itemsQty || "0"), 10) || 0;

    const datum = String(s.dataset.date || "").trim();
    const user  = String(s.dataset.userName || "").trim();
    const slotId = String(s.dataset.slotId || "").trim();

    rows.push({
      Halle: halle,
      Zone: zone,
      Ist_Reihe: istRow,
      Ist_Platz: istPlz,
      Ist_Slot: istSlot,
      Referenz: ref,
      Sachnummer: sach,
      Lagergruppe: lg || "-",
      Menge: qty,
      Kartons: itemsCount,
      Karton_Stueck: itemsQty,
      Flag_Typ: type || "-",
      Flag_Notiz: note,
      Soll_Reihe: expRow || "",
      Soll_Platz: expPlz || "",
      Datum: datum,
      User: user,
      Slot_ID: slotId
    });

    // Summen
    if (type) {
      const cur = sumByType.get(type) || { count: 0, qty: 0 };
      cur.count += 1;
      cur.qty += qty;
      sumByType.set(type, cur);
    }

    const lgKey = (lg || "-");
    const cur2 = sumByLG.get(lgKey) || { count: 0, qty: 0 };
    cur2.count += 1;
    cur2.qty += qty;
    sumByLG.set(lgKey, cur2);
  }

  if (!rows.length) {
    alert("Keine Abweichungen (Flags) gefunden – Export ist leer.");
    return;
  }

  // Sortierung: Reihe, Platz, Slot
  rows.sort((a,b) =>
    String(a.Ist_Reihe).localeCompare(String(b.Ist_Reihe), "de") ||
    (parseInt(a.Ist_Platz,10) - parseInt(b.Ist_Platz,10)) ||
    (parseInt(a.Ist_Slot,10) - parseInt(b.Ist_Slot,10))
  );

  // Summary Sheets
  const sumTypeRows = Array.from(sumByType.entries())
    .sort((a,b)=> String(a[0]).localeCompare(String(b[0]), "de"))
    .map(([t,v]) => ({ Flag_Typ: t, Paletten: v.count, Stueck: v.qty }));

  const sumLgRows = Array.from(sumByLG.entries())
    .sort((a,b)=> String(a[0]).localeCompare(String(b[0]), "de"))
    .map(([lg,v]) => ({ Lagergruppe: lg, Paletten: v.count, Stueck: v.qty }));

  // Workbook
  const wb = XLSX.utils.book_new();

  const ws1 = XLSX.utils.json_to_sheet(rows);
  ws1["!cols"] = [
    { wch: 6 }, { wch: 6 }, { wch: 8 }, { wch: 8 }, { wch: 7 },
    { wch: 18 }, { wch: 16 }, { wch: 10 }, { wch: 8 },
    { wch: 8 }, { wch: 12 }, { wch: 14 }, { wch: 26 },
    { wch: 10 }, { wch: 10 }, { wch: 12 }, { wch: 10 }, { wch: 10 }
  ];
  XLSX.utils.book_append_sheet(wb, ws1, "Abweichungen");

  const ws2 = XLSX.utils.json_to_sheet(sumTypeRows);
  ws2["!cols"] = [{ wch: 18 }, { wch: 10 }, { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, ws2, "Summary_Flags");

  const ws3 = XLSX.utils.json_to_sheet(sumLgRows);
  ws3["!cols"] = [{ wch: 14 }, { wch: 10 }, { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, ws3, "Summary_LG");

  const d = new Date();
  const iso = d.toISOString().slice(0,10);
  const filename = `abweichungen_${halle}_${zone}_${iso}.xlsx`;

  XLSX.writeFile(wb, filename);
}
