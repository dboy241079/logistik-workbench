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
          <td class="center"><input type="checkbox" ${r.noLabel ? "checked" : ""}></td>
          <td class="center"><button class="btn-del" title="Zeile löschen">×</button></td>
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
      <td class="center"><input type="checkbox" ${prefill.noLabel ? "checked" : ""}></td>
      <td class="center"><button class="btn-del" title="Zeile löschen">×</button></td>
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
    tbody.addEventListener("click", (e) => {
      if (e.target.classList.contains("btn-del")) {
        const tr = e.target.closest("tr");
        tr.remove();
        if (!tbody.children.length) addRow();
        recalc();
      }
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
 * Tipp: 22–26 ist meist gut – je nach Drucker/Zoom. Hier nehmen wir 24.
 */
function paginateBackPages(maxRows = 24) {
  const pages = Array.from(document.querySelectorAll(".page.a4"));
  const baseBackPage = pages[1];            // Seite 2 (Rückseite) als Vorlage
  if (!baseBackPage) return;

  const table = baseBackPage.querySelector("#tbl");
  const body  = table?.querySelector("tbody");
  const tfoot = table?.querySelector("tfoot");
  if (!table || !body) return;

  // alle Datenzeilen einsammeln
  const allRows = Array.from(body.querySelectorAll("tr"));
  if (allRows.length <= maxRows) {
    // passt auf eine Seite – nur Summe neu berechnen & raus.
    recalc();
    return;
  }

  // Hilfsfunktionen
  const cloneEmptyBackPage = () => {
    const clone = baseBackPage.cloneNode(true);
    // TBody leeren, aber Struktur behalten
    const tb = clone.querySelector("tbody");
    if (tb) tb.innerHTML = "";
    // Footersumme wird nur auf der letzten Seite angezeigt – hier erstmal leeren
    const tf = clone.querySelector("tfoot");
    if (tf) tf.style.visibility = "hidden";
    return clone;
  };

  // Seite 2 vorbereiten: erste Portion drinlassen
  const firstChunk = allRows.slice(0, maxRows);
  const rest       = allRows.slice(maxRows);
  body.innerHTML = "";
  firstChunk.forEach(tr => body.appendChild(tr));

  // Rest in blöcken auf weitere Seiten verteilen
  let idx = 0;
  while (idx < rest.length) {
    const page = cloneEmptyBackPage();
    const tb   = page.querySelector("tbody");
    rest.slice(idx, idx + maxRows).forEach(tr => tb.appendChild(tr));
    document.body.appendChild(page);
    idx += maxRows;
  }

  // Footer-Summe nur auf der letzten Seite sichtbar machen
  const newPages = Array.from(document.querySelectorAll(".page.a4"));
  const lastBack = newPages[newPages.length - 1];
  const lastTf   = lastBack.querySelector("tfoot");
  if (lastTf) lastTf.style.visibility = "visible";

  // Summe einmal neu schreiben (wir zeigen die Gesamtsumme nur auf letzter Seite)
  recalc();
}

// direkt nach dem Befüllen/Payload-Apply und recalc() aufrufen:
paginateBackPages(24);

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
    .normalize("NFKD")              // Umlaute zerlegen
    .replace(/[\u0300-\u036f]/g, "")// Akzente entfernen
    .replace(/Ä/g,"AE").replace(/Ö/g,"OE").replace(/Ü/g,"UE")
    .replace(/ä/g,"ae").replace(/ö/g,"oe").replace(/ü/g,"ue")
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "");     // nur A-Z/0-9 behalten
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
  const found = collectCodesFromTable(1); // 1 = "Verk"-Spalte
  const boxes = document.querySelectorAll('.checkgrid input[type="checkbox"]');
  if (!boxes.length) return;

  // erst alles abwählen
  boxes.forEach(cb => cb.checked = false);

  // dann passende Werte anhaken
  boxes.forEach(cb => {
    const v = normCode(cb.value || "");
    if (v && found.has(v)) cb.checked = true;
  });
}

/* ===== Gruppenumrandung für gleiche Eing.-Nr. (neu berechnen) ===== */

// Spaltenindex der Eing.-Nr. dynamisch finden (falls Header sich mal verschiebt)
function getEingangNrColIdx() {
  const ths = Array.from(document.querySelectorAll('#eingangTable thead th'));
  let idx = ths.findIndex(th => th.textContent.trim().toLowerCase().startsWith('eing.-nr'));
  if (idx < 0) idx = 0; // Fallback: erste Spalte
  return idx;
}

// Eing.-Nr. aus einer Tabellenzeile lesen (Input oder Text)
function getCellVal(tr, colIdx) {
  const td = tr.cells[colIdx];
  if (!td) return '';
  const inp = td.querySelector('input, select');
  const val = inp ? inp.value : td.textContent;
  return String(val).trim();
}

// "09" -> "9", "  9 " -> "9"; nicht-numerische Werte bleiben wie sie sind
function normalizeKey(v) {
  const s = String(v).trim();
  if (s === '') return '';
  const n = Number(s.replace(',', '.'));
  return Number.isFinite(n) ? String(n) : s;
}

// Wendet die Klassen grp / grp-start / grp-end pro zusammenhängender Gruppe an
function reapplyGroups() {
  const table = document.getElementById('eingangTable');
  if (!table || !table.tBodies.length) return;

  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody.rows);
  const col   = getEingangNrColIdx();

  // Alte Klassen entfernen
  rows.forEach(r => r.classList.remove('grp','grp-start','grp-end'));

  // Über zusammenhängende Abschnitte gleicher Eing.-Nr. iterieren
  let i = 0;
  while (i < rows.length) {
    const key = normalizeKey(getCellVal(rows[i], col));
    let j = i + 1;
    while (j < rows.length && normalizeKey(getCellVal(rows[j], col)) === key) j++;

    // Nur gruppieren, wenn key nicht leer ist
    if (key !== '') {
      const group = rows.slice(i, j);
      group.forEach(r => r.classList.add('grp'));
      group[0].classList.add('grp-start');
      group[group.length - 1].classList.add('grp-end');

      // Sonderfall: Einzeiler – oben & unten Rahmen
      if (group.length === 1) {
        group[0].classList.add('grp-single');
      }
    }
    i = j;
  }
}

/* Auto-Trigger: bei Änderungen in der Tabelle neu gruppieren */
(() => {
  const table = document.getElementById('eingangTable');
  if (!table) return;
  const tbody = table.tBodies[0];

  // Nach jedem Input/Change in der Tabelle neu berechnen
  tbody.addEventListener('input',  reapplyGroups);
  tbody.addEventListener('change', reapplyGroups);

  // Falls du Sortierung/Filter hast: nach Re-Render/Sort auch aufrufen
  // Beispiel: window.addEventListener('rowsUpdated', reapplyGroups);

  // Initial einmal anwenden
  reapplyGroups();
})();



  // ====== PDF-Export (html2pdf) ======
  const btnPdf = byId("btnPdf");
  if (btnPdf && window.html2pdf) {
    btnPdf.addEventListener("click", async () => {
      try {
        // vor PDF: Sperren erzwingen, damit keine Cursors/Fokusse erscheinen
        const wasChecked = toggleEdit ? toggleEdit.checked : false;
        setLocked(true);
        if (toggleEdit) toggleEdit.checked = false;

        const wf = (byId("wfNr") && byId("wfNr").value) ? byId("wfNr").value.trim() : "";
        const filename = `Wareneingang_${wf || "ohneNr"}.pdf`;

        // Container mit beiden Seiten exportieren
        const pages = $$(".page.a4");
        const wrapper = document.createElement("div");
        pages.forEach(p => wrapper.appendChild(p.cloneNode(true)));

        const opt = {
          margin:       [10, 10, 10, 10],     // mm (Sicherheitsrand; @page setzt zusätzlich)
          filename,
          image:        { type: 'jpeg', quality: 0.95 },
          html2canvas:  { scale: 2, useCORS: true },
          jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
          pagebreak:    { mode: ['css', 'legacy'] }
        };

        await html2pdf().set(opt).from(wrapper).save();

        // Zustand wiederherstellen
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
