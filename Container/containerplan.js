/* containerplan.js
   - Unterstützt 2 Layouts:
     (A) 2-Grids wie im Foto: #ringNorth + #ringSouth
     (B) Fallback: alter Ring (#ring) mit gridPosFor()

   - Features:
     ✓ Summary (used/capacity) + Farbstatus
     ✓ 📷 Icon im Button wenn Bilder vorhanden
     ✓ Container-Modal: Plätze anzeigen + einlagern + löschen
     ✓ 2 Bilder pro Container: Upload / Delete / Preview + Großansicht (Lightbox)
*/

(() => {
  "use strict";

  /* ------------------ Konfiguration ------------------ */

  const API = "/Container/api";
  


  // Layout-Tuning für die 2-Grids (Foto-Ansicht)
  // -> brauchst dazu passendes CSS Grid (z.B. 12 Spalten, 24 Zeilen)
  const YARD_GRID = {
    rows: 24,
    cols: 12,
    // Spalten im "oben"-Grid (north)
    north: { outerCol: 8, innerCol: 12, topRow: 2 },
    // Spalten im "unten"-Grid (south)
    south: { innerCol: 1, outerCol: 5, topRow: 2 },
  };

  /* ------------------ DOM ------------------ */

  const ringNorth = document.getElementById("ringNorth");
  const ringSouth = document.getElementById("ringSouth");
  const ringSingle = document.getElementById("ring"); // Fallback (alter Ring)

  const imgPrev1 = document.getElementById("imgPrev1");
  const imgPrev2 = document.getElementById("imgPrev2");

  // Filter/Search
  const elOnlyFree = document.getElementById("onlyFree");
  const elOnlyFull = document.getElementById("onlyFull");
  const elQ = document.getElementById("q");
  const elBtnClear = document.getElementById("btnClear");

  // Modal (Container-Inhalt)
  const modal = document.getElementById("modal");
  const mTitle = document.getElementById("mTitle");
  const mMeta = document.getElementById("mMeta");
  const mClose = document.getElementById("mClose");
  const mStatus = document.getElementById("mStatus");
  const posGrid = document.getElementById("posGrid");

  const mRef = document.getElementById("mRef");
  const mSach = document.getElementById("mSach");
  const mLs = document.getElementById("mLs");
  const mQty = document.getElementById("mQty");
  const mAdd = document.getElementById("mAdd");

  // Image-Upload Buttons
  const btnUp1 = document.getElementById("imgUp1");
  const btnUp2 = document.getElementById("imgUp2");
  const btnDel1 = document.getElementById("imgDel1");
  const btnDel2 = document.getElementById("imgDel2");

  // Lightbox (Großansicht)
  const imgBox = document.getElementById("imgBox");
  const imgBoxImg = document.getElementById("imgBoxImg");
  const imgBoxClose = document.getElementById("imgBoxClose");

  const btnPrint = document.getElementById("btnPrint");

  const slotPhotoModal = document.getElementById("slotPhotoModal");
const spmTitle = document.getElementById("spmTitle");
const spmMeta = document.getElementById("spmMeta");
const spmClose = document.getElementById("spmClose");
const spmImg = document.getElementById("spmImg");
const spmPlaceholder = document.getElementById("spmPlaceholder");
const spmFile = document.getElementById("spmFile");
const spmUpload = document.getElementById("spmUpload");
const spmDelete = document.getElementById("spmDelete");
const spmView = document.getElementById("spmView");
const spmMsg = document.getElementById("spmMsg");

  /* ------------------ State ------------------ */

  const state = {
  summary: new Map(),
  cells: new Map(),
  activeCode: null,
  searchMatches: new Set(),
  slotPhotoCtx: null
};

  /* ------------------ Helpers ------------------ */

  async function fetchJson(url, opts) {
    const res = await fetch(url, opts);
    const text = await res.text();

    if (!res.ok) {
      throw new Error(`HTTP ${res.status} ${res.statusText}: ${text.slice(0, 160)}`);
    }

    try {
      return JSON.parse(text);
    } catch {
      throw new Error("Kein JSON: " + text.slice(0, 180));
    }
  }

  function code(n) {
    return "C" + String(n).padStart(2, "0");
  }

  function range(a, b) {
    const out = [];
    for (let i = a; i <= b; i++) out.push(i);
    return out;
  }

  function hasImages(summaryRow) {
    if (!summaryRow) return false;
    // toleranter Check (je nachdem, was dein Summary liefert)
    const img1 = summaryRow.img1 && String(summaryRow.img1).trim() !== "";
    const img2 = summaryRow.img2 && String(summaryRow.img2).trim() !== "";
    const flag = parseInt(summaryRow.has_images || "0", 10) === 1;
    return img1 || img2 || flag;
  }

  function applyClass(btn, used, cap) {
    btn.classList.remove("full", "mid", "low", "empty");
    if (used <= 0) return btn.classList.add("empty");

    const p = used / cap;
    if (p >= 0.85) btn.classList.add("full");
    else if (p >= 0.50) btn.classList.add("mid");
    else btn.classList.add("low");
  }

  function setCamIcon(btn, on) {
    btn.querySelector("[data-cam]")?.classList.toggle("d-none", !on);
  }

  function printContainer(codeStr){
  if (!codeStr) return;

  // Druckseite (auto=1 sorgt dafür, dass container_print.php window.print() ausführt)
  const url = `/Container/container_print.php?code=${encodeURIComponent(codeStr)}&auto=1&t=${Date.now()}`;

  // Hidden iframe (kein Popup, kein neuer Tab)
  let frame = document.getElementById("printFrame");
  if (!frame) {
    frame = document.createElement("iframe");
    frame.id = "printFrame";
    frame.style.position = "fixed";
    frame.style.right = "0";
    frame.style.bottom = "0";
    frame.style.width = "0";
    frame.style.height = "0";
    frame.style.border = "0";
    frame.style.visibility = "hidden";
    document.body.appendChild(frame);
  }

  frame.src = url;
}


  /* ------------------ Layout Rendering ------------------ */

  // (B) Fallback: alter Ring (deine ursprüngliche 15x15-Idee)
  function gridPosForRing(codeStr) {
    const n = parseInt(codeStr.slice(1), 10); // 1..52
    if (n >= 1 && n <= 13) return { r: 1, c: 1 + (n + 1) };
    if (n >= 14 && n <= 26) return { r: 1 + (n - 13 + 1), c: 15 };
    if (n >= 27 && n <= 39) return { r: 15, c: 15 - (n - 26 + 1) };
    return { r: 15 - (n - 39 + 1), c: 1 };
  }

 function createButton(codeStr, parent, pos) {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "cbtn";
  btn.dataset.code = codeStr;

  if (pos) {
    btn.style.gridRow = String(pos.r);
    btn.style.gridColumn = String(pos.c);
  }

  btn.innerHTML = `
    <div class="fw-semibold">${codeStr}</div>
    <div class="badge bg-dark badge-mini" data-badge>0/48</div>
    <span class="camIcon d-none" data-cam title="Bild-Schnellansicht">📷</span>
  `;

  // Standard-Klick: Container öffnen
  btn.addEventListener("click", () => openContainer(codeStr));

  // Kamera-Icon: Schnellansicht (Containerbild)
  const camEl = btn.querySelector("[data-cam]");
  camEl?.addEventListener("click", async (e) => {
    e.preventDefault();
    e.stopPropagation();

    try {
      const d = await fetchJson(`${API}/container_image_list.php?container_code=${encodeURIComponent(codeStr)}`);
      if (!d.ok) return;

      const map = new Map((d.items || []).map(x => [String(x.slot), x.url]));
      const url = map.get("1") || map.get("2");

      if (url) {
        openImgBox(url + `?t=${Date.now()}`);
      } else {
        // Falls nur Slot-Bilder da sind (aber keine Containerbilder): Modal öffnen
        openContainer(codeStr);
      }
    } catch (err) {
      console.error(err);
    }
  });

  parent.appendChild(btn);
  state.cells.set(codeStr, btn);
}

  function renderLayout() {
    state.cells.clear();

    // Foto-Layout aktiv, wenn beide Grids existieren
    if (ringNorth && ringSouth) {
      ringNorth.innerHTML = "";
      ringSouth.innerHTML = "";

      // --- NORTH (oberhalb Gebäude) ---
      // Außen: C32..C52 (oben->unten: C52, C51, ... C32)
      const northOuter = range(32, 52).map(code).reverse();
      // Innen: C27..C31 (oben->unten: C31..C27)
      const northInner = range(27, 31).map(code).reverse();

      // Außen-Spur (links)
      northOuter.forEach((c, i) => {
        createButton(c, ringNorth, { r: YARD_GRID.north.topRow + i, c: YARD_GRID.north.outerCol });
      });
      // Innen-Spur (rechts, näher am Gebäude)
      northInner.forEach((c, i) => {
        createButton(c, ringNorth, { r: YARD_GRID.north.topRow + (northOuter.length - northInner.length) + i, c: YARD_GRID.north.innerCol });
      });

      // --- SOUTH (unterhalb Gebäude) ---
      // Innen: C01..C20 (oben->unten 1..20)
      const southInner = range(1, 20).map(code);
      // Außen: C21..C26 (oben->unten: 26..21)
      const southOuter = range(21, 26).map(code).reverse();

      southInner.forEach((c, i) => {
        createButton(c, ringSouth, { r: YARD_GRID.south.topRow + i, c: YARD_GRID.south.innerCol });
      });
      southOuter.forEach((c, i) => {
        createButton(c, ringSouth, { r: YARD_GRID.south.topRow + i, c: YARD_GRID.south.outerCol });
      });

      return;
    }

    // Fallback: alter Ring
    if (ringSingle) {
      // Lass dein "building" DIV im HTML drin – wir hängen nur Buttons dran.
      for (let i = 1; i <= 52; i++) {
        const c = code(i);
        const pos = gridPosForRing(c);
        createButton(c, ringSingle, pos);
      }
    }
  }

  /* ------------------ Summary + UI Update ------------------ */

 async function loadSummary() {
  const data = await fetchJson(`${API}/container_summary.php`);
  if (!data.ok) throw new Error(data.msg || "summary failed");

  state.summary.clear();
  (data.items || []).forEach(it => state.summary.set(it.code, it));

  // ✅ Debug: prüfe C40
  const dbg = state.summary.get("C40");
  if (dbg) console.log("Summary C40:", dbg);

  for (const [codeStr, btn] of state.cells.entries()) {
    const s = state.summary.get(codeStr);
    const used = s ? parseInt(s.used, 10) : 0;
    const cap = s ? parseInt(s.capacity, 10) : 48;

    btn.querySelector("[data-badge]").textContent = `${used}/${cap}`;
    applyClass(btn, used, cap);
    setCamIcon(btn, hasImages(s));
  }

  applyFilters();
}

  /* ------------------ Filter/Search ------------------ */

  function applyFilters() {
    const onlyFree = !!elOnlyFree?.checked;
    const onlyFull = !!elOnlyFull?.checked;
    const q = (elQ?.value || "").trim();

    for (const [codeStr, btn] of state.cells.entries()) {
      const s = state.summary.get(codeStr);
      const used = s ? parseInt(s.used, 10) : 0;
      const cap = s ? parseInt(s.capacity, 10) : 48;

      let ok = true;
      if (onlyFree) ok = ok && (used < cap);
      if (onlyFull) ok = ok && (used >= cap);

      // Suche: wenn Matches existieren -> dimme alle anderen
      if (q.length >= 1 && state.searchMatches.size) {
        btn.classList.toggle("dim", !state.searchMatches.has(codeStr));
      } else {
        btn.classList.remove("dim");
      }

      btn.style.display = ok ? "" : "none";
    }
  }

  let searchTimer = null;

  async function doSearch() {
    const q = (elQ?.value || "").trim();
    state.searchMatches.clear();

    if (q.length < 1) {
      applyFilters();
      return;
    }

    try {
      const data = await fetchJson(`${API}/container_search.php?q=${encodeURIComponent(q)}`);
      if (data.ok) {
        (data.items || []).forEach(x => state.searchMatches.add(String(x.container_code)));
      }
      applyFilters();
    } catch (e) {
      console.error(e);
    }
  }

  /* ------------------ Modal: Open + Render ------------------ */

  function showModal() {
    modal?.classList.add("show");
  }

  function hideModal() {
    modal?.classList.remove("show");
  }

 async function openContainer(codeStr) {
  state.activeCode = codeStr;

  if (mTitle) mTitle.textContent = `Container ${codeStr}`;
  if (mStatus) mStatus.textContent = "";
  showModal();

  const data = await fetchJson(`${API}/container_load.php?code=${encodeURIComponent(codeStr)}`);
  if (!data.ok) {
    if (mStatus) mStatus.textContent = data.msg || "Load failed";
    return;
  }

  const used = (data.items || []).length;
  const capNum = parseInt(data.capacity || "48", 10) || 48;

  if (mMeta) mMeta.textContent = `Belegt: ${used}/${capNum}`;

  renderPositions(capNum, data.items || []);
  await loadContainerImages(codeStr);

  // ✅ Lokale Sofort-Sync der Übersicht (schnell sichtbar)
  const btn = state.cells.get(codeStr);
  if (btn) {
    btn.querySelector("[data-badge]").textContent = `${used}/${capNum}`;
    applyClass(btn, used, capNum);

    // Wenn Slot-Foto sichtbar vorhanden -> Kamera an
    const hasSlotPhotoVisible = !!posGrid?.querySelector(".js-item-img:not(.d-none)");
    const hasContainerPhotoVisible = !!(
      (imgPrev1 && !imgPrev1.classList.contains("d-none")) ||
      (imgPrev2 && !imgPrev2.classList.contains("d-none"))
    );
    setCamIcon(btn, hasSlotPhotoVisible || hasContainerPhotoVisible);
  }

  // ✅ Backend-Summary nachziehen (dauerhaft korrekt für alle Container)
  await loadSummary();

  setTimeout(() => mRef?.focus(), 30);
}

function renderPositions(capacity, items) {
  if (!posGrid) return;
  posGrid.innerHTML = "";

  const map = new Map(); // pos -> item
  items.forEach(it => map.set(parseInt(it.pos, 10), it));

  for (let p = 1; p <= capacity; p++) {
    const it = map.get(p);
    const cell = document.createElement("div");
    cell.className = "posCell " + (it ? "used" : "free");

    // ---------------- Frei ----------------
    if (!it) {
      cell.innerHTML = `
        <div class="slot-head">
          <div class="slot-pos">Pos ${p}</div>
          <div class="slot-state" style="background:#e2e8f0;border-color:#cbd5e1;color:#475569;">frei</div>
        </div>
        <div class="slot-free-text">Leer</div>
      `;
      posGrid.appendChild(cell);
      continue;
    }

    // ---------------- Belegt ----------------
    const ref = String(it.referenznr || "");
    const itemId = parseInt(it.id, 10);

    cell.innerHTML = `
      <div class="slot-head">
        <div class="slot-pos">Pos ${p}</div>
        <div class="slot-state">belegt</div>
      </div>

      <div class="slot-lines">
        <div class="slot-ref">${ref.slice(-12) || "—"}</div>
        <div class="slot-meta">Sach: ${String(it.sachnummer || "—").slice(0, 18)}</div>
        <div class="slot-meta">Menge: ${it.menge || 1}</div>
      </div>

      <div class="slot-actions">
        <button class="btn btn-sm btn-outline-danger js-del-item" type="button">Löschen</button>
        <button class="btn btn-sm btn-outline-secondary js-open-photo" type="button">📷 Bild</button>
      </div>
    `;

    const delItemBtn = cell.querySelector(".js-del-item");
    const openPhotoBtn = cell.querySelector(".js-open-photo");

    // Paletten-Eintrag löschen
    delItemBtn?.addEventListener("click", async (e) => {
      e.stopPropagation();
      await deletePallet(itemId);
    });

    // Foto-Modal (zentriert) öffnen
    openPhotoBtn?.addEventListener("click", async (e) => {
      e.stopPropagation();
      await openSlotPhotoModal({
        itemId,
        codeStr: state.activeCode || "",
        pos: p
      });
    });

    posGrid.appendChild(cell);
  }
}

  /* ------------------ Paletten: Add / Delete ------------------ */
async function addPalletAuto() {
  const codeStr = state.activeCode;
  if (!codeStr) return;

  const ref = (mRef?.value || "").trim();
  const sach = (mSach?.value || "").trim();
  const ls = (mLs?.value || "").trim();
  const menge = Math.max(1, parseInt(mQty?.value || "1", 10) || 1);

  if (!ref || !sach) {
    if (mStatus) mStatus.textContent = "Bitte Referenz + Sachnummer eingeben.";
    return;
  }

  const fd = new FormData();
  fd.append("code", codeStr);
  fd.append("referenznr", ref);
  fd.append("sachnummer", sach);
  fd.append("lieferschein", ls);
  fd.append("menge", String(menge));

  const data = await fetchJson(`${API}/container_add.php`, { method: "POST", body: fd });
  if (!data.ok) {
    if (mStatus) mStatus.textContent = data.msg || "Speichern fehlgeschlagen.";
    return;
  }

  if (mStatus) mStatus.textContent = `Gespeichert auf Pos ${data.pos}.`;

  if (mRef) mRef.value = "";
  if (mQty) mQty.value = "1";
  mRef?.focus();

  await openContainer(codeStr); // lädt bereits Summary mit
}
 
async function deletePallet(id) {
  const fd = new FormData();
  fd.append("id", String(id));

  const data = await fetchJson(`${API}/container_delete.php`, { method: "POST", body: fd });
  if (!data.ok) {
    if (mStatus) mStatus.textContent = data.msg || "Löschen fehlgeschlagen.";
    return;
  }

  if (mStatus) mStatus.textContent = "Gelöscht.";
  await openContainer(state.activeCode); // lädt bereits Summary mit
}

 

  /* ------------------ Bilder: List / Upload / Delete ------------------ */

  async function loadContainerImages(codeStr) {
    if (!imgPrev1 || !imgPrev2) return;

    const d = await fetchJson(`${API}/container_image_list.php?container_code=${encodeURIComponent(codeStr)}`);
    const map = new Map((d.items || []).map(x => [String(x.slot), x.url]));

    const u1 = map.get("1") ? (map.get("1") + `?t=${Date.now()}`) : "";
    const u2 = map.get("2") ? (map.get("2") + `?t=${Date.now()}`) : "";

    imgPrev1.src = u1;
    imgPrev2.src = u2;

    imgPrev1.classList.toggle("d-none", !u1);
    imgPrev2.classList.toggle("d-none", !u2);
  }

  async function uploadContainerImage(slot) {
    const codeStr = state.activeCode;
    const fileInp = document.getElementById(slot === 1 ? "imgFile1" : "imgFile2");
    if (!codeStr || !fileInp || !fileInp.files?.[0]) return;

    const fd = new FormData();
    fd.append("container_code", codeStr);
    fd.append("slot", String(slot));
    fd.append("image", fileInp.files[0]);

    const d = await fetchJson(`${API}/container_image_upload.php`, { method: "POST", body: fd });
    if (!d.ok) throw new Error(d.msg || "Upload fehlgeschlagen");

    fileInp.value = "";
    await loadContainerImages(codeStr);
    await loadSummary(); // 📷 Icon an/aus
  }

  async function deleteContainerImage(slot) {
    const codeStr = state.activeCode;
    if (!codeStr) return;

    const fd = new FormData();
    fd.append("container_code", codeStr);
    fd.append("slot", String(slot));

    await fetchJson(`${API}/container_image_delete.php`, { method: "POST", body: fd });
    await loadContainerImages(codeStr);
    await loadSummary(); // 📷 Icon an/aus
  }

  /* ------------------ Lightbox (Bild groß) ------------------ */

  function openImgBox(src) {
    if (!imgBox || !imgBoxImg) return;
    if (!src) return;

    imgBoxImg.src = src;
    imgBox.classList.add("show");
  }

  function closeImgBox() {
    if (!imgBox || !imgBoxImg) return;
    imgBox.classList.remove("show");
    imgBoxImg.src = "";
  }


  function resetSlotPhotoModal() {
  state.slotPhotoCtx = null;
  if (spmTitle) spmTitle.textContent = "Slot-Foto";
  if (spmMeta) spmMeta.textContent = "Container / Pos";
  if (spmMsg) spmMsg.textContent = "";

  if (spmImg) {
    spmImg.src = "";
    spmImg.classList.add("d-none");
  }
  spmPlaceholder?.classList.remove("d-none");

  if (spmFile) spmFile.value = "";
}

function showSlotPhotoModal() {
  slotPhotoModal?.classList.add("show");
}

function hideSlotPhotoModal() {
  slotPhotoModal?.classList.remove("show");
  resetSlotPhotoModal();
}

async function openSlotPhotoModal({ itemId, codeStr, pos }) {
  state.slotPhotoCtx = { itemId, codeStr, pos };

  if (spmTitle) spmTitle.textContent = `Slot-Foto`;
  if (spmMeta) spmMeta.textContent = `${codeStr} · Pos ${pos}`;
  if (spmMsg) spmMsg.textContent = "";
  if (spmFile) spmFile.value = "";

  showSlotPhotoModal();

  try {
    const url = await loadItemImage(itemId);

    if (url && spmImg) {
      spmImg.src = url;
      spmImg.classList.remove("d-none");
      spmPlaceholder?.classList.add("d-none");
    } else {
      if (spmImg) {
        spmImg.src = "";
        spmImg.classList.add("d-none");
      }
      spmPlaceholder?.classList.remove("d-none");
    }
  } catch (err) {
    console.error(err);
    if (spmMsg) spmMsg.textContent = "Bild konnte nicht geladen werden.";
  }
}

async function slotPhotoUploadCurrent() {
  const ctx = state.slotPhotoCtx;
  if (!ctx?.itemId) return;

  const file = spmFile?.files?.[0];
  if (!file) {
    if (spmMsg) spmMsg.textContent = "Bitte zuerst ein Bild auswählen.";
    return;
  }

  try {
    if (spmMsg) spmMsg.textContent = "Upload läuft…";

    const d = await uploadItemImage(ctx.itemId, file);
    if (!d.ok) {
      if (spmMsg) spmMsg.textContent = d.msg || "Upload fehlgeschlagen.";
      return;
    }

    const fresh = await loadItemImage(ctx.itemId);
    if (fresh && spmImg) {
      spmImg.src = fresh;
      spmImg.classList.remove("d-none");
      spmPlaceholder?.classList.add("d-none");
    }

    if (spmFile) spmFile.value = "";
    if (spmMsg) spmMsg.textContent = "Bild gespeichert.";

    await loadSummary(); // Übersicht-📷 aktualisieren
    // Optional Slotkarten neu rendern:
    // await openContainer(state.activeCode);
  } catch (err) {
    console.error(err);
    if (spmMsg) spmMsg.textContent = "Upload-Fehler.";
  }
}

async function slotPhotoDeleteCurrent() {
  const ctx = state.slotPhotoCtx;
  if (!ctx?.itemId) return;

  try {
    if (spmMsg) spmMsg.textContent = "Löschen…";

    const d = await deleteItemImage(ctx.itemId);
    if (!d.ok) {
      if (spmMsg) spmMsg.textContent = d.msg || "Löschen fehlgeschlagen.";
      return;
    }

    if (spmImg) {
      spmImg.src = "";
      spmImg.classList.add("d-none");
    }
    spmPlaceholder?.classList.remove("d-none");

    if (spmFile) spmFile.value = "";
    if (spmMsg) spmMsg.textContent = "Bild gelöscht.";

    await loadSummary(); // Übersicht-📷 aktualisieren
  } catch (err) {
    console.error(err);
    if (spmMsg) spmMsg.textContent = "Lösch-Fehler.";
  }
}
  /* ------------------ Init + Event Binding ------------------ */

  function bindEvents() {
    // Filter
    elOnlyFree?.addEventListener("change", applyFilters);
    elOnlyFull?.addEventListener("change", applyFilters);

    // Suche
    elQ?.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(doSearch, 140);
    });

    // Reset
    elBtnClear?.addEventListener("click", () => {
      if (elQ) elQ.value = "";
      if (elOnlyFree) elOnlyFree.checked = false;
      if (elOnlyFull) elOnlyFull.checked = false;
      state.searchMatches.clear();
      applyFilters();
    });

    // Modal close
    mClose?.addEventListener("click", hideModal);
    modal?.addEventListener("click", (e) => { if (e.target === modal) hideModal(); });

    // Add via Enter + Button
    mRef?.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addPalletAuto().catch(console.error);
      }
    });
    mAdd?.addEventListener("click", () => addPalletAuto().catch(console.error));

    // Upload/Delete Buttons
    btnUp1?.addEventListener("click", () => uploadContainerImage(1).catch(console.error));
    btnUp2?.addEventListener("click", () => uploadContainerImage(2).catch(console.error));
    btnDel1?.addEventListener("click", () => deleteContainerImage(1).catch(console.error));
    btnDel2?.addEventListener("click", () => deleteContainerImage(2).catch(console.error));

    // Preview click -> lightbox
    imgPrev1?.addEventListener("click", (e) => openImgBox(e.target.src));
    imgPrev2?.addEventListener("click", (e) => openImgBox(e.target.src));

    // Lightbox close
    if (imgBox && imgBoxClose) {
      imgBoxClose.addEventListener("click", closeImgBox);
      imgBox.addEventListener("click", (e) => { if (e.target === imgBox) closeImgBox(); });
    }
    // Drucken (ohne Popup, über hidden iframe)
btnPrint?.addEventListener("click", (e) => {
  e.preventDefault();
  e.stopPropagation();
  printContainer(state.activeCode);
});

// Slot-Foto-Modal close
spmClose?.addEventListener("click", hideSlotPhotoModal);
slotPhotoModal?.addEventListener("click", (e) => {
  if (e.target === slotPhotoModal) hideSlotPhotoModal();
});

// Slot-Foto-Modal Aktionen
spmUpload?.addEventListener("click", () => slotPhotoUploadCurrent().catch(console.error));
spmDelete?.addEventListener("click", () => slotPhotoDeleteCurrent().catch(console.error));

spmView?.addEventListener("click", (e) => {
  e.preventDefault();
  if (spmImg && !spmImg.classList.contains("d-none") && spmImg.src) {
    openImgBox(spmImg.src);
  } else if (spmMsg) {
    spmMsg.textContent = "Kein Bild vorhanden.";
  }
});

spmImg?.addEventListener("click", (e) => {
  e.preventDefault();
  if (spmImg.src) openImgBox(spmImg.src);
});

  }

  /* ------------------ Item/Slot-Bilder: List / Upload / Delete ------------------ */

async function loadItemImage(itemId) {
  const d = await fetchJson(`${API}/container_item_image_list.php?item_id=${encodeURIComponent(itemId)}`);
  if (!d.ok) return null;
  return d.url ? (d.url + `?t=${Date.now()}`) : null;
}

async function uploadItemImage(itemId, file) {
  if (!itemId || !file) return { ok:false, msg:"item_id oder Datei fehlt" };

  const fd = new FormData();
  fd.append("item_id", String(itemId));
  fd.append("image", file);

  const d = await fetchJson(`${API}/container_item_image_upload.php`, {
    method: "POST",
    body: fd
  });
  return d;
}

async function deleteItemImage(itemId) {
  const fd = new FormData();
  fd.append("item_id", String(itemId));

  const d = await fetchJson(`${API}/container_item_image_delete.php`, {
    method: "POST",
    body: fd
  });
  return d;
}


  async function init() {
    renderLayout();
    bindEvents();
    await loadSummary();
  }

  // Script ist bei dir meist am Ende von <body> geladen -> init direkt ok
  init().catch(console.error);

})();

