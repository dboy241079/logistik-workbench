(function () {
  // ====== Payload aus sessionStorage / window.name übernehmen ======
  const KEY = "waPrintPayload";
  let payload = null;
  try {
    const raw = sessionStorage.getItem(KEY);
    if (raw) {
      payload = JSON.parse(raw);
    } else if (window.name) {
      const tmp = JSON.parse(window.name);
      if (tmp && tmp[KEY]) payload = tmp[KEY];
    }
  } catch (e) {
    console.warn("Keine/ungültige Payload:", e);
  }

  // Helpers
  const $  = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
  const byId = (id) => document.getElementById(id);

  // Zeit '09:05' sicherstellen (leer bleibt leer)
  const setHHMM = (v) => {
    if (!v) return "";
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(v).trim());
    if (!m) return String(v);
    return `${String(+m[1]).padStart(2,"0")}:${m[2]}`;
  };

  // ====== Seite 1 & 2 mit Payload befüllen ======
  if (payload) {
    const H = payload.header || {};

    const elWf     = byId("wfNr");
    const elAnk    = byId("ankunft");
    const elDat    = byId("datum");
    const elSped   = byId("spedition");
    const elKennz  = byId("kennz");
    const elLief   = byId("lieferungDurch");
    const elBeg    = byId("beginn");
    const elEnde   = byId("ende");

    if (elWf)    elWf.value    = H.eingangNr || "";
    if (elAnk)   elAnk.value   = setHHMM(H.ankunft || H.ankunftzeit || "");
    if (elDat)   elDat.value   = H.datum || "";
    if (elSped)  elSped.value  = H.spedition || "";
    if (elKennz) elKennz.value = H.kennz || "";
    if (elLief)  elLief.value  = H.lieferungDurch || "";
    if (elBeg)   elBeg.value   = setHHMM(H.beginn || "");
    if (elEnde)  elEnde.value  = setHHMM(H.ende || "");

    // Seite 2 – Tabelle
    const tbody = $("#tbl tbody");
    if (tbody) {
      tbody.innerHTML = "";
      (payload.rows || []).forEach((r) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td><input type="text"   class="cell"       value="${r.ls   ?? ""}"></td>
          <td><input type="text"   class="cell"       value="${r.verk ?? ""}"></td>
          <td><input type="text"   class="cell"       value="${r.sach ?? ""}"></td>
          <td><input type="number" class="cell num pal" min="0" value="${r.qty ?? 0}"></td>
        `;
        tbody.appendChild(tr);
      });
    }

    // Summe setzen + in "Anzahl laut Lieferschein" spiegeln
    const sum =
      payload.sum != null
        ? payload.sum
        : (payload.rows || []).reduce((a, r) => a + (r.qty || 0), 0);
    byId("sum").textContent = String(sum);

    // HINWEIS: gesamtGez bleibt absichtlich leer (wird händisch eingetragen)
    const lsField = byId("anzahlLs");
    if (lsField) lsField.value = String(sum);
  }

  // ====== Tabelle Seite 2 – Interaktion ======
  const tbl   = $("#tbl");
  const tbody = $("#tbl tbody");
  const sumEl = $("#sum");

  function recalc() {
    if (!tbody || !sumEl) return 0;
    const vals = $$(".pal", tbody).map((i) => Number(i.value || 0));
    const sum = vals.reduce((a, b) => a + b, 0);
    sumEl.textContent = sum.toString();
    return sum;
  }

  function addRow(prefill = {}) {
    if (!tbody) return;
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td><input type="text"   class="cell"       value="${prefill.ls   ?? ""}"></td>
      <td><input type="text"   class="cell"       value="${prefill.verk ?? ""}"></td>
      <td><input type="text"   class="cell"       value="${prefill.sach ?? ""}"></td>
      <td><input type="number" class="cell num pal" min="0" value="${prefill.qty ?? 0}"></td>
    `;
    tbody.appendChild(tr);
    recalc();
  }

  function clearAll() {
    if (!tbody) return;
    tbody.innerHTML = "";
    addRow();
    recalc();
  }

  // Delegation
  if (tbody) {
    tbody.addEventListener("input", (e) => {
      if (e.target.classList.contains("pal")) recalc();
    });
  }

  // Buttons (Seite 2)
  const btnAdd = byId("addRow");
  const btnClr = byId("clearAll");
  const btnSync= byId("syncToFront");
  if (btnAdd) btnAdd.addEventListener("click", () => addRow());
  if (btnClr) btnClr.addEventListener("click", clearAll);
  if (btnSync) btnSync.addEventListener("click", () => {
    const sum = recalc();
    const frontField = byId("gesamtGez");
    if (frontField) {
      frontField.value = String(sum);
      frontField.scrollIntoView({ behavior: "smooth", block: "center" });
      frontField.classList.add("flash");
      setTimeout(() => frontField.classList.remove("flash"), 900);
    }
  });

  // Init
  if (tbody && !tbody.children.length) addRow(); // falls Payload leer war
  recalc();

  // Datum auto auf heute (wenn leer)
  const dt = byId("datum");
  if (dt && !dt.value) {
    const t = new Date();
    dt.value = `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
  }

  // Toolbar-Buttons
  const btnBack = document.getElementById("btnBack");
  if (btnBack) {
    btnBack.addEventListener("click", () => {
      // wenn aus der App geöffnet: Tab schließen & Hauptfenster fokussieren
      if (window.opener && !window.opener.closed) {
        window.close();
        try { window.opener.focus(); } catch (_) {}
      } else {
        history.length > 1 ? history.back() : window.close();
      }
    });
  }

  /**
   * Splittet die Palettenliste (Seite 2) auf mehrere Seiten, wenn zu viele Zeilen.
   * maxRows: wie viele Tabellenzeilen pro Seite auf die Rückseite passen.
   */
  function paginateBackPages(maxRows = 24) {
    const pages = Array.from(document.querySelectorAll(".page.a4"));
    const baseBackPage = pages[1];
    if (!baseBackPage) return;

    const table = baseBackPage.querySelector("#tbl");
    const body  = table?.querySelector("tbody");
    if (!table || !body) return;

    const allRows = Array.from(body.querySelectorAll("tr"));
    if (allRows.length <= maxRows) {
      recalc();
      return;
    }

    const cloneEmptyBackPage = () => {
      const clone = baseBackPage.cloneNode(true);
      const tb = clone.querySelector("tbody");
      if (tb) tb.innerHTML = "";
      const tf = clone.querySelector("tfoot");
      if (tf) tf.style.visibility = "hidden";
      return clone;
    };

    const firstChunk = allRows.slice(0, maxRows);
    const rest       = allRows.slice(maxRows);
    body.innerHTML = "";
    firstChunk.forEach(tr => body.appendChild(tr));

    let idx = 0;
    while (idx < rest.length) {
      const page = cloneEmptyBackPage();
      const tb   = page.querySelector("tbody");
      rest.slice(idx, idx + maxRows).forEach(tr => tb.appendChild(tr));
      document.body.appendChild(page);
      idx += maxRows;
    }

    const newPages = Array.from(document.querySelectorAll(".page.a4"));
    const lastBack = newPages[newPages.length - 1];
    const lastTf   = lastBack.querySelector("tfoot");
    if (lastTf) lastTf.style.visibility = "visible";

    recalc();
  }

  // Druckdatum und Seitenzahl nach ggf. erzeugten Rückseiten aktualisieren
  function updateDocumentFooters() {
    const now = new Date();
    const date = now.toLocaleDateString("de-DE", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric"
    });

    const pages = Array.from(document.querySelectorAll(".page.a4"));
    pages.forEach((page, index) => {
      page.querySelectorAll(".print-date").forEach(el => {
        el.textContent = date;
      });
      page.querySelectorAll(".page-no").forEach(el => {
        el.textContent = String(index + 1);
      });
    });
  }

  paginateBackPages(24);
  updateDocumentFooters();

  // ====== Felder sperren (nur lesen) – Toggle ======
  function setLocked(lock) {
    const fields = $$("input, select, textarea");
    fields.forEach(el => {
      const type = (el.type || "").toLowerCase();
      if (type === "checkbox" || type === "radio") {
        el.disabled = lock;
      } else {
        el.readOnly = lock;
      }
    });
  }

  // Default: gesperrt (nur lesen)
  setLocked(true);

  const toggleEdit = byId("toggleEdit");
  if (toggleEdit) {
    toggleEdit.addEventListener("change", () => {
      setLocked(!toggleEdit.checked);
    });
  }

  // ===== Checkgrid anhand der Tabelle automatisch setzen =====
  function normCode(s){
    return String(s || "")
      .normalize("NFKD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/Ä/g,"AE").replace(/Ö/g,"OE").replace(/Ü/g,"UE")
      .replace(/ä/g,"ae").replace(/ö/g,"oe").replace(/ü/g,"ue")
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "");
  }

  function collectCodesFromTable(colIdx = 1){
    const codes = new Set();
    const table = document.getElementById("tbl");
    if(!table) return codes;

    const rows = table.querySelectorAll("tbody tr");
    rows.forEach(tr => {
      const tds = tr.querySelectorAll("td");
      const inp = tds[colIdx]?.querySelector("input");
      const val = inp ? inp.value : "";
      const code = normCode(val);
      if (code) codes.add(code);
    });

    return codes;
  }

  function syncCheckgridFromTable(){
    const found = collectCodesFromTable(1);
    const boxes = document.querySelectorAll('.checkgrid input[type="checkbox"]');
    if (!boxes.length) return;

    boxes.forEach(cb => cb.checked = false);
    boxes.forEach(cb => {
      const v = normCode(cb.value || "");
      if (v && found.has(v)) cb.checked = true;
    });
  }

  /* ===== Gruppenumrandung für gleiche Eing.-Nr. (neu berechnen) ===== */
  function getEingangNrColIdx() {
    const ths = Array.from(document.querySelectorAll('#eingangTable thead th'));
    let idx = ths.findIndex(th => th.textContent.trim().toLowerCase().startsWith('eing.-nr'));
    if (idx < 0) idx = 0;
    return idx;
  }

  function getCellVal(tr, colIdx) {
    const td = tr.cells[colIdx];
    if (!td) return '';
    const inp = td.querySelector('input, select');
    const val = inp ? inp.value : td.textContent;
    return String(val).trim();
  }

  function normalizeKey(v) {
    const s = String(v).trim();
    if (s === '') return '';
    const n = Number(s.replace(',', '.'));
    return Number.isFinite(n) ? String(n) : s;
  }

  function reapplyGroups() {
    const table = document.getElementById('eingangTable');
    if (!table || !table.tBodies.length) return;

    const tbody = table.tBodies[0];
    const rows  = Array.from(tbody.rows);
    const col   = getEingangNrColIdx();

    rows.forEach(r => r.classList.remove('grp','grp-start','grp-end'));

    let i = 0;
    while (i < rows.length) {
      const key = normalizeKey(getCellVal(rows[i], col));
      let j = i + 1;
      while (j < rows.length && normalizeKey(getCellVal(rows[j], col)) === key) j++;

      if (key !== '') {
        const group = rows.slice(i, j);
        group.forEach(r => r.classList.add('grp'));
        group[0].classList.add('grp-start');
        group[group.length - 1].classList.add('grp-end');

        if (group.length === 1) {
          group[0].classList.add('grp-single');
        }
      }
      i = j;
    }
  }

  (() => {
    const table = document.getElementById('eingangTable');
    if (!table) return;
    const tbody = table.tBodies[0];

    tbody.addEventListener('input',  reapplyGroups);
    tbody.addEventListener('change', reapplyGroups);
    reapplyGroups();
  })();

  // ====== PDF-Export (html2pdf) ======
  const btnPdf = byId("btnPdf");
  if (btnPdf && window.html2pdf) {
    btnPdf.addEventListener("click", async () => {
      try {
        const wasChecked = toggleEdit ? toggleEdit.checked : false;
        setLocked(true);
        if (toggleEdit) toggleEdit.checked = false;

        updateDocumentFooters();

        const wf = (byId("wfNr") && byId("wfNr").value) ? byId("wfNr").value.trim() : "";
        const filename = `Wareneingang_${wf || "ohneNr"}.pdf`;

        const pages = $$(".page.a4");
        const wrapper = document.createElement("div");
        pages.forEach(p => wrapper.appendChild(p.cloneNode(true)));

        const opt = {
          margin:       [10, 10, 10, 10],
          filename,
          image:        { type: 'jpeg', quality: 0.95 },
          html2canvas:  { scale: 2, useCORS: true },
          jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
          pagebreak:    { mode: ['css', 'legacy'] }
        };

        await html2pdf().set(opt).from(wrapper).save();

        if (toggleEdit) {
          toggleEdit.checked = wasChecked;
          setLocked(!wasChecked);
        }
      } catch (err) {
        console.error(err);
        alert("PDF konnte nicht erstellt werden: " + err.message);
      }
    });
  }
})();