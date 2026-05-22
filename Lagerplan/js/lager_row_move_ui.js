// /LKW/Lagerplan/js/lager_row_move_ui.js
(() => {
  "use strict";

  let ROW_FROM = 1;
let ROW_TO   = 200;

const API_LAGER_CFG = "/Lagerplan/api/lager_config_get.php";

async function loadRowConfigFromServer() {
  const halle = window.currentHall || "H3";
  const zone  = window.currentZone || "W1";

  const url = `${API_LAGER_CFG}?halle=${encodeURIComponent(halle)}&zone=${encodeURIComponent(zone)}`;

  const res = await fetch(url, {
    credentials: "same-origin",
    cache: "no-store"
  });

  const text = await res.text();

  let js = {};
  try {
    js = JSON.parse(text);
  } catch (e) {
    console.error("❌ lager_row_move_ui.js: kein JSON", text);
    throw new Error("Lager-Konfiguration konnte nicht gelesen werden.");
  }

  if (!res.ok || js.ok !== true) {
    throw new Error(js?.msg || "Lager-Konfiguration konnte nicht geladen werden.");
  }

  ROW_FROM = Math.max(1, parseInt(js.row_from ?? 1, 10) || 1);
  ROW_TO   = Math.max(ROW_FROM, parseInt(js.row_to ?? 200, 10) || 200);

  return js;
}
  const $ = (id) => document.getElementById(id);

  function fillRowSelect(id) {
    const sel = $(id);
    if (!sel) return;
    sel.innerHTML =
      `<option value="">– wählen –</option>` +
      Array.from({ length: ROW_TO - ROW_FROM + 1 }, (_, i) => {
        const r = ROW_FROM + i;
        return `<option value="${r}">${r}</option>`;
      }).join("");
  }

  function showInfo(html, type="info") {
    const box = $("rmInfo");
    if (!box) return;
    const cls =
      type === "success" ? "alert alert-success" :
      type === "error"   ? "alert alert-danger"  :
      type === "warn"    ? "alert alert-warning" :
                           "alert alert-info";
    box.innerHTML = `<div class="${cls} mb-0">${html}</div>`;
  }

  function setConfirmVisible(on) {
    const c = $("rmConfirm");
    if (!c) return;
    c.classList.toggle("d-none", !on);
  }

    async function post(action, payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k,v]) => fd.append(k, String(v)));

    const res  = await fetch(`/Lagerplan/lager_row_move.php?action=${encodeURIComponent(action)}`, {
      method: "POST",
      body: fd
    });

    const text = await res.text();

    let data;
    try { data = JSON.parse(text); }
    catch {
      throw { message: "Server liefert kein JSON.", detail: text, status: res.status };
    }

    if (!res.ok || data.ok !== true) {
      // ✅ wir geben detail/rohtext mit
      throw {
        message: data?.msg || data?.error || `Fehler (HTTP ${res.status})`,
        detail: data?.detail || text,
        status: res.status,
        raw: data
      };
    }

    return data;
  }


  function esc(s){
    return String(s ?? "")
      .replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;")
      .replaceAll('"',"&quot;").replaceAll("'","&#039;");
  }

  function applyMovedList(movedList) {
  if (!Array.isArray(movedList) || movedList.length === 0) return;

  const touched = new Set();

  for (const m of movedList) {
    const src = document.querySelector(`.palette-slot[data-slot-id="${m.id}"]`);
    if (!src) continue;

    const srcPlatz = src.closest(".platz");

    const data = {
      slotId: String(m.id),
      ref: src.dataset.ref || "",
      sach: src.dataset.sach || "",
      date: src.dataset.date || "",
      userName: src.dataset.userName || "",
      lieferschein: src.dataset.lieferschein || "",
      menge: src.dataset.menge || "",
      itemsCount: src.dataset.itemsCount || "",
      itemsQty: src.dataset.itemsQty || ""
    };

    const toRow = String(m.to.reihe);
    const toPlz = String(m.to.platz).padStart(2, "0");
    const toIdx = String(m.to.slot_index);

    // Zielreihe sicher rendern
    window.ensureRowRendered?.(toRow);

    const toPlatzEl = document.querySelector(
      `.platz[data-row="${CSS.escape(toRow)}"][data-platz="${CSS.escape(toPlz)}"]`
    );
    if (!toPlatzEl) continue;

    const dst = toPlatzEl.querySelector(
      `.palette-slot[data-slot-index="${CSS.escape(toIdx)}"]`
    );
    if (!dst) continue;

    if (typeof window.resetSlotUI === "function") {
      window.resetSlotUI(src);
    } else {
      src.classList.remove("palette-slot-used");
      src.textContent = "";
      src.title = "";
      ["ref","sach","date","slotId","userName","lieferschein","menge","itemsCount","itemsQty"]
        .forEach(k => delete src.dataset[k]);
    }

    dst.dataset.slotId = data.slotId;
    dst.setAttribute("data-slot-id", data.slotId);

    dst.dataset.ref = data.ref;
    dst.dataset.sach = data.sach;
    if (data.date) dst.dataset.date = data.date;
    if (data.userName) dst.dataset.userName = data.userName;
    if (data.lieferschein) dst.dataset.lieferschein = data.lieferschein;
    if (data.menge) dst.dataset.menge = data.menge;
    if (data.itemsCount) dst.dataset.itemsCount = data.itemsCount;
    if (data.itemsQty) dst.dataset.itemsQty = data.itemsQty;

    dst.classList.add("palette-slot-used");
    dst.textContent = data.ref ? data.ref.slice(-4) : "";

    const mTxt = dst.dataset.menge ? ` · Menge: ${dst.dataset.menge}` : "";
    dst.title = `${data.ref} · ${data.sach} · ${dst.dataset.date || ""}${mTxt}`;

    touched.add(srcPlatz);
    touched.add(toPlatzEl);
  }

  for (const p of touched) {
    if (p && typeof window.updatePlatzLabel === "function") {
      window.updatePlatzLabel(p);
    }
  }

  window.afterPlanChange?.();
}

  let lastCheck = null;

async function onCheck() {
  setConfirmVisible(false);
  lastCheck = null;

  const fromRow = String($("rmFromRow")?.value || "").trim();
  const toRow   = String($("rmToRow")?.value || "").trim();

  if (!fromRow || !toRow) {
    showInfo("Bitte Von- und Nach-Reihe auswählen.", "error");
    $("rmAskBtn").disabled = true;
    return;
  }
  if (fromRow === toRow) {
    showInfo("Von- und Nach-Reihe sind identisch.", "warn");
    $("rmAskBtn").disabled = true;
    return;
  }

  try {
    const data = await post("check", {
      halle: window.currentHall || "H3",
      zone:  window.currentZone || "W1",
      from_row: fromRow,
      to_row: toRow,
      keep_index: 1
    });

    lastCheck = data;

    const free = Number(data.free_total || 0);
    const need = Number(data.from_count || 0);
    const tgt  = Number(data.to_count || 0);

    const targetLine =
      tgt > 0
        ? `<div class="mt-1"><b>Achtung:</b> In Zielreihe ${esc(toRow)} liegen bereits <b>${esc(tgt)}</b> Paletten.</div>`
        : `<div class="mt-1">Zielreihe ${esc(toRow)} ist leer.</div>`;

    const repackLine =
      data.requires_repack
        ? `<div class="mt-1 text-muted small">Hinweis: Es wird ggf. auf andere Plätze/Slots in der Zielreihe verteilt (weil einzelne Plätze voll sind).</div>`
        : `<div class="mt-1 text-muted small">Platz/Slot kann größtenteils gleich bleiben.</div>`;

    if (data.can_move) {
      showInfo(
        `✅ Gesamtprüfung ok: freie Slots in Zielreihe: <b>${esc(free)}</b> · zu bewegen: <b>${esc(need)}</b>.` +
        targetLine +
        repackLine +
        `<div class="mt-1 text-muted small">Klicke jetzt „Umbuchen“ → dann kommt die Sicherheitsfrage (Ja/Nein).</div>`,
        "success"
      );
      $("rmAskBtn").disabled = false;
    } else {
      showInfo(
        `❌ Umbuchen nicht möglich: Zielreihe hat nur <b>${esc(free)}</b> freie Slots, benötigt <b>${esc(need)}</b>.` +
        targetLine,
        "error"
      );
      $("rmAskBtn").disabled = true;
    }
    } catch (e) {
    showInfo(esc(e.message || e?.message || "Prüfen fehlgeschlagen."), "error");

    rmSetMsg(
      "error",
      "Prüfung fehlgeschlagen",
      "Bitte Seite neu laden und erneut versuchen. Wenn es bleibt: Admin informieren.",
      e.detail || e?.detail || ""
    );

    $("rmAskBtn").disabled = true;
  }

}

function onAsk() {
  if (!lastCheck?.can_move) return;

  const fromRow = String($("rmFromRow")?.value || "").trim();
  const toRow   = String($("rmToRow")?.value || "").trim();

  const tgt  = Number(lastCheck.to_count || 0);
  const free = Number(lastCheck.free_total || 0);
  const need = Number(lastCheck.from_count || 0);

  const extra =
    tgt > 0
      ? `Du möchtest in Reihe <b>${esc(toRow)}</b> umbuchen – dort sind aber noch <b>${esc(tgt)}</b> Paletten drin.<br>
         Freie Slots: <b>${esc(free)}</b> · Zu bewegen: <b>${esc(need)}</b>.<br>
         <b>Trotzdem umbuchen</b>, wenn der Platz ausreicht?`
      : `Zielreihe <b>${esc(toRow)}</b> ist leer. Freie Slots: <b>${esc(free)}</b> · Zu bewegen: <b>${esc(need)}</b>.`;

  const repack =
    lastCheck.requires_repack
      ? `<div class="mt-2 small text-muted">Hinweis: Es wird ggf. auf andere Plätze/Slots verteilt.</div>`
      : "";

  $("rmConfirmText").innerHTML =
    `Quelle: Reihe <b>${esc(fromRow)}</b> → Ziel: Reihe <b>${esc(toRow)}</b>.<br>` +
    extra + repack;

  setConfirmVisible(true);
}

  async function onYes() {
    if (!lastCheck?.can_move) return;

    const fromRow = String($("rmFromRow")?.value || "").trim();
    const toRow   = String($("rmToRow")?.value || "").trim();

    try {
      const data = await post("move", {
        halle: window.currentHall || "H3",
        zone:  window.currentZone || "W1",
        from_row: fromRow,
        to_row: toRow,
        keep_index: 1
      });

      applyMovedList(data.moved || []);
      setConfirmVisible(false);
      $("rmAskBtn").disabled = true;
      lastCheck = null;

      showInfo(`✅ Reihe <b>${esc(fromRow)}</b> wurde komplett nach <b>${esc(toRow)}</b> umgebucht. (${esc(data.count || 0)} Paletten)`, "success");
      window.soundSuccess?.();
        } catch (e) {
      const msg = e.message || e?.message || "Umbuchen fehlgeschlagen.";
      const detail = e.detail || e?.detail || "";

      showInfo(`❌ ${esc(msg)}`, "error");
      window.soundError?.();

      const isDup = detail.includes("uq_position") || detail.includes("Duplicate entry");

      if (isDup) {
        rmSetMsg(
          "error",
          "Zielreihe ist nicht wirklich leer (Archiv blockiert).",
          `
            <div>
              In der Zielreihe gibt es noch <b>alte/archivierte Datensätze</b> (deleted_at gesetzt),
              die die Position blockieren.<br><br>
              <b>So löst du es:</b>
              <ol class="mb-0 mt-2">
                <li>Zielreihe in der DB prüfen (auch <b>deleted_at</b>-Einträge).</li>
                <li>Diese Einträge löschen/aufräumen.</li>
                <li>Dann erneut: <b>Prüfen</b> → <b>Umbuchen</b>.</li>
              </ol>
            </div>
          `,
          detail
        );
      } else {
        rmSetMsg("error", "Umbuchen fehlgeschlagen", esc(msg), detail);
      }
    }
  }

  function onNo() {
    setConfirmVisible(false);
  }

 async function init() {
  if (!$("rmFromRow")) return;

  try {
    await loadRowConfigFromServer();
  } catch (e) {
    console.error("❌ lager_row_move_ui.js: Konfig-Laden fehlgeschlagen, nutze Fallback 1..200", e);
  }

  console.log("✅ lager_row_move_ui init", {
    ROW_FROM,
    ROW_TO,
    rmFromRow: $("rmFromRow")?.id,
    rmToRow: $("rmToRow")?.id
  });

  fillRowSelect("rmFromRow");
  fillRowSelect("rmToRow");

  console.log(
    "✅ rmFromRow letzte Option =",
    $("rmFromRow")?.options?.[$("rmFromRow").options.length - 1]?.value
  );

  $("rmFromRow").value = "";
  $("rmToRow").value = "";

  $("rmCheckBtn")?.addEventListener("click", onCheck);
  $("rmAskBtn")?.addEventListener("click", onAsk);
  $("rmYesBtn")?.addEventListener("click", onYes);
  $("rmNoBtn")?.addEventListener("click", onNo);
}

document.addEventListener("DOMContentLoaded", init);
})();
function escapeHtml(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function rmSetMsg(kind, title, text, detail = "") {
  const box = document.getElementById("rmMsg");
  if (!box) return;

  const cls =
    kind === "error" ? "alert-danger" :
    kind === "success" ? "alert-success" :
    "alert-info";

  const icon =
    kind === "error" ? "⚠️" :
    kind === "success" ? "✅" :
    "ℹ️";

  const detailHtml = detail ? `
    <details class="mt-2">
      <summary class="small">Details anzeigen</summary>
      <pre class="mt-2 mb-0 small" style="white-space:pre-wrap;">${escapeHtml(detail)}</pre>
    </details>
  ` : "";

  box.innerHTML = `
    <div class="alert ${cls} mb-0">
      <div class="d-flex gap-2">
        <div style="font-size:18px;line-height:1">${icon}</div>
        <div>
          <div class="fw-semibold">${escapeHtml(title)}</div>
          <div class="small mt-1">${text}</div>
          ${detailHtml}
        </div>
      </div>
    </div>
  `;
}
