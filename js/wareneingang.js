// wareneingang.js
(function () {
  const TOP_LIMIT = 10;
  const statsRangeEl = document.getElementById("statsRange");

  const table = document.getElementById("eingangTable");
  const tbody = table.querySelector("tbody");
  const btnAdd = document.getElementById("btnAddRow");
  const filterSelect = document.getElementById("filterNumber");
  const searchInput = document.getElementById("searchInput");
  const searchAllCols = document.getElementById("searchAllCols");
  const btnResetFilter = document.getElementById("btnResetFilter");
  const btnWeeklyExport = document.getElementById("btnWeeklyExport");

const API_WE = '/api/wareneingang_api.php';
const API_ATT = '/api/attachments_api.php';

function makeActionGroup(editMode) {
  const frag = document.createDocumentFragment();

  // Bearbeiten / Speichern
  const btnEdit = document.createElement("button");
  btnEdit.type = "button";
  btnEdit.className = "btn btn-outline-secondary btn-sm action-btn me-1";
  btnEdit.innerHTML = editMode ? '<i class="bi bi-check2"></i>' : '<i class="bi bi-pencil"></i>';
  btnEdit.title = editMode ? "Speichern" : "Bearbeiten";

  btnEdit.addEventListener("click", async (e) => {
    const tr = e.currentTarget.closest("tr");
    const isEditing = tr.dataset.mode === "edit";

    if (isEditing) {
      saveRow(tr);
      try {
        await upsertRowToServer(tr);
        tr.dataset.saved = "1";
        setRowMode(tr, "view");
        btnEdit.innerHTML = '<i class="bi bi-pencil"></i>';
        btnEdit.title = "Bearbeiten";
        regroupGroups();
        rebuildFilterOptions();
        applyFilter();
        computeStats?.();
      } catch (err) {
        alert("Speichern am Server fehlgeschlagen: " + err.message);
      }
    } else {
      // Editmodus aktivieren
      editRow(tr);
      tr.dataset.saved = "0";
      setRowMode(tr, "edit");
      btnEdit.innerHTML = '<i class="bi bi-check2"></i>';
      btnEdit.title = "Speichern";
      clearRowBorders(tr);
      removeBadge(tr);

      // ✅ Autocomplete für diese Edit-Zeile aktivieren
      if (window.__bindRowAC) {
        window.__bindRowAC(tr);
      }

      // LG dynamisch aus Sachnummer/Behälter nachführen
      const behnrInp = tr.children[12]?.querySelector('input');
      const sachInp  = tr.children[13]?.querySelector('input');
      const reapplyLG = () => applyLGForRow(tr);
      behnrInp?.addEventListener('input', reapplyLG);
      behnrInp?.addEventListener('blur',  reapplyLG);
      sachInp?.addEventListener('input',  reapplyLG);
      sachInp?.addEventListener('blur',   reapplyLG);
      reapplyLG(); // initial setzen
    }
  });

  // Duplizieren
  const btnCopy = document.createElement("button");
  btnCopy.type = "button";
  btnCopy.className = "btn btn-outline-info btn-sm action-btn me-1";
  btnCopy.innerHTML = '<i class="bi bi-copy"></i>';
  btnCopy.title = "Zeile duplizieren";
  btnCopy.addEventListener("click", (e) => {
    const tr = e.currentTarget.closest("tr");
    duplicateRow(tr);
  });

  // WA-Drucken (Gruppen-Aktion)
  const btnPrint = document.createElement("button");
  btnPrint.type = "button";
  btnPrint.dataset.role = "group";
  btnPrint.className = "btn btn-outline-primary btn-sm action-btn me-1";
  btnPrint.innerHTML = '<i class="bi bi-printer"></i>';
  btnPrint.title = "WA drucken (Excel)";
  btnPrint.addEventListener("click", (e) => {
    const tr = e.currentTarget.closest("tr");
    exportGroupToXlsx(tr);
  });

  // 🟧 NEU: Lagerplatz zuweisen (Gruppen-Aktion)
  const btnLager = document.createElement("button");
  btnLager.type = "button";
  btnLager.dataset.role = "group"; // → nur in erster Zeile der Gruppe sichtbar
  btnLager.className = "btn btn-outline-warning btn-sm action-btn me-1";
  btnLager.innerHTML = '<i class="bi bi-box-seam"></i>';
  btnLager.title = "Lagerplatz zuweisen";
  btnLager.addEventListener("click", (e) => {
    const tr = e.currentTarget.closest("tr");
    openLagerForEingang(tr);
  });

  // Anhänge (Gruppen-Aktion)
  const btnFiles = document.createElement("button");
  btnFiles.type = "button";
  btnFiles.dataset.role = "group";
  btnFiles.className = "btn btn-outline-dark btn-sm action-btn me-1 position-relative";
  btnFiles.innerHTML = '<i class="bi bi-paperclip"></i>';
  btnFiles.title = "Anhänge (Dokumente/Bilder)";
  btnFiles.addEventListener("click", async (e) => {
    const tr = e.currentTarget.closest("tr");
    const nr = getCellValue(tr, 0).trim();
    if (!nr) { alert("Bitte zuerst eine Eingangsnummer eintragen/speichern."); return; }
    await openAttachmentModal(nr, tr, btnFiles);
  });

  // Löschen
  const btnDelete = document.createElement("button");
  btnDelete.type = "button";
  btnDelete.className = "btn btn-outline-danger btn-sm action-btn";
  btnDelete.innerHTML = '<i class="bi bi-trash"></i>';
  btnDelete.title = "Zeile löschen";
  btnDelete.addEventListener("click", async (e) => {
    const tr = e.currentTarget.closest("tr");
    const id = tr.dataset.dbid ? Number(tr.dataset.dbid) : null;
    if (id) {
      try {
        const fd = new FormData();
        fd.append('action','delete');
        fd.append('id', id);
        const res = await fetch(API_WE, { method:'POST', body: fd, credentials:'same-origin' });
        const j = await res.json();
        if (!j.ok) throw new Error(j.error || 'delete failed');
      } catch (err) {
        alert('Server-Delete fehlgeschlagen: ' + err.message);
        return;
      }
    }
    tr.remove();
    regroupGroups(); rebuildFilterOptions(); applyFilter();
  });

  frag.appendChild(btnEdit);
  frag.appendChild(btnCopy);
  frag.appendChild(btnPrint);
  frag.appendChild(btnLager);   // ⬅️ NEU
  frag.appendChild(btnFiles);
  frag.appendChild(btnDelete);
  return frag;
}

function renderRowFromDB(row) {
  const tr = document.createElement('tr');
  const cells = [
    row.eingang_nr, row.lieferschein, row.lagergruppe,
    row.datum || '', row.kennzeichen, row.land, row.spedition,
    row.ankunft || '', row.beginn || '', row.ende || '',
    row.behaelter, row.zus_behaelter, row.behaelternr,
    row.sachnummer, row.menge, row.gebucht, row.gebucht_von
  ];
  for (let i=0;i<cells.length;i++) {
    const td = document.createElement('td');
    td.dataset.raw = (cells[i] ?? '').toString();
    td.textContent = td.dataset.raw;
    tr.appendChild(td);
  }
  const tdAction = document.createElement('td');
  tdAction.appendChild(makeActionGroup(false));
  tr.appendChild(tdAction);

  tr.dataset.saved = '1';
  tr.dataset.mode  = 'view';
  tr.dataset.dbid  = row.id;
  tbody.appendChild(tr);
}


(async function loadAll() {
  try {
    const res = await fetch(`${API_WE}?action=list`, { credentials:'same-origin' });
    const ct  = res.headers.get('content-type') || '';
    const raw = await res.text();

    if (!ct.includes('application/json')) {
      console.error('API_WE?action=list → kein JSON. Content-Type:', ct, '\nRAW:\n', raw);
      throw new Error('API lieferte kein JSON (siehe Console für Details).');
    }

    const j = JSON.parse(raw);

    if (j.ok) {
      // 1) Zeilen in die Tabelle rendern
      j.items.forEach(renderRowFromDB);

      // 2) Initial nach Eing.-Nr. (Spalte 1) aufsteigend sortieren
      // (erste TH muss data-type="number" haben)
      forceSortByEingangAsc();
pinHeaderFirstPerGroup();
regroupGroups();
computeStats();
await refreshAllAttachmentBadges();


      // 3) Rest aktualisieren
      rebuildFilterOptions();
      applyFilter();
      regroupGroups();
      computeStats();
      await refreshAllAttachmentBadges();
    } else {
      console.warn('API meldet Fehler:', j.error);
    }
  } catch (e) {
    console.warn('Server-Load fehlgeschlagen', e);
  }
})();




// --- Sachnummern laden (ERGÄNZT: recomputeLGAllRows) ---
let PARTS = [];
(async () => {
  try {
    const res = await fetch('/api/stammdaten_api.php?type=sachnummer&action=list', { credentials:'same-origin' });
    const j = await res.json();
    PARTS = j.ok ? j.items.map(it => ({
      group: it.lagergruppe || '',
      part:  it.sachnummer,
      norm:  (it.sachnummer || '').toLowerCase().replace(/\s+/g,'')
    })) : [];
    recomputeLGAllRows(); // <<< NEU
  } catch (e) {
    console.warn('Sachnummern-Load fehlgeschlagen', e);
  }
})();

// --- Behälter laden (NEU) ---
let BEHS = [];
(async () => {
  try {
    const res = await fetch('/api/stammdaten_api.php?type=behaelter&action=list', { credentials:'same-origin' });
    const j = await res.json();
    BEHS = j.ok ? j.items.map(it => ({
      group:  it.lagergruppe || '',
      nummer: it.nummer || '',
      norm:   normalizeCode(it.nummer)
    })) : [];
    recomputeLGAllRows(); // <<< NEU
  } catch (e) {
    console.warn('Behälter-Load fehlgeschlagen', e);
  }
})();



 // direkt unter deinen const btnWeeklyExport ...
const ISO2 = new Set([
  "DE","AT","CH","NL","BE","LU","FR","IT","ES","PT","DK","SE","NO","FI","PL",
  "CZ","SK","HU","RO","BG","SI","HR","EE","LV","LT","IE","GB","GR","TR"
]);

const COLUMNS = [
  { type: "text" }, { type: "text" }, { type: "text" }, { type: "date" },
  { type: "text" }, { type: "text" }, { type: "text" },
  { type: "time" }, { type: "time" }, { type: "time" },
  { type: "number", min: 0, step: 1 }, { type: "number", min: 0, step: 1 },
  { type: "text" }, { type: "text" }, { type: "number", min: 0, step: 1 },
  // Gebucht: leere Option zuerst = offen
  { type: "select", options: ["", "Nein", "Ja", "nicht TOP", "Mabon", "Banking", "Verzögert"] },
  { type: "select", options: ["FS DS","FS MD","FS AS","SS DS","SS MD","SS AS"] }
];


// immer sichtbar, nur Titel/Subtitle setzen
(function setupWeeklyButton(){
  const { start, end } = lastWeekRange(new Date());
  if (btnWeeklyExport) {
    btnWeeklyExport.title = `Export für ${formatISO(start)} bis ${formatISO(end)}`;
    // optional: Datumsspanne im Button-Text anzeigen
    // btnWeeklyExport.textContent = `Vorige Woche exportieren (${formatISO(start)}–${formatISO(end)})`;
  }

  // (Optional) Hinweis, falls schon exportiert
  try {
    const last = JSON.parse(localStorage.getItem("lastWeeklyExport") || "null");
    if (last && last.week && last.year) {
      const cur = isoWeek(start);
      if (last.week === cur.week && last.year === cur.year) {
        btnWeeklyExport?.classList.add("btn-warning");
        btnWeeklyExport.title += " (bereits exportiert)";
      }
    }
  } catch {}
})();


// Klick -> Vorwoche filtern & CSV export
btnWeeklyExport?.addEventListener("click", () => {
  const { start, end } = lastWeekRange(new Date());

  // Zeilen sammeln (gespeicherte + sichtbare/echt vorhandene)
  const rows = [...tbody.querySelectorAll("tr")].filter(tr => tr.dataset.saved === "1");

  // nach Datum (Spalte 4 / Index 3) filtern
  const weekRows = rows.filter(tr => {
    const d = parseISODate(getCellValue(tr, 3));
    if (!d) return false;
    d.setHours(0,0,0,0);
    return d >= start && d <= end;
  });

  if (!weekRows.length) {
    alert("Keine Einträge für die vorige Woche gefunden.");
    return;
  }

  // Dateiname mit ISO-KW
  const { week, year } = isoWeek(start);
  const fname = `Wareneingang_KW${String(week).padStart(2,'0')}_${year}.csv`;

  const csv = buildCsvFromRows(weekRows);
  downloadCsv(fname, csv);

  // Optional: „Schon exportiert“-Marker speichern
  try {
    localStorage.setItem("lastWeeklyExport", JSON.stringify({ week, year, ts: Date.now() }));
  } catch {}
});
  // --- Hilfsfunktionen ---
  function normalize(str) {
    return (str || "").toLowerCase().replace(/\s+/g, "");
  }
  function normalizeKey(v) {
  return String(v ?? '')
    .toLowerCase()
    .replace(/\s+/g, '')     // alle Leerzeichen raus
    .trim();
}

  const getCellValue = (tr, idx) => {
    const cell = tr.children[idx];
    const sel = cell.querySelector("select");
    if (sel) return sel.options[sel.selectedIndex].text.trim();
    const inp = cell.querySelector("input");
    if (inp) return (inp.value || "").trim();
    if (cell.dataset && typeof cell.dataset.raw !== "undefined")
      return cell.dataset.raw.trim();
    const clone = cell.cloneNode(true);
    clone.querySelectorAll(".badge-count").forEach(n => n.remove());
    return (clone.textContent || "").trim();
  };

  const setCellRaw = (td, value) => {
    td.dataset.raw = value;
    td.textContent = value;
  };

  const parseByType = (val, type) => {
    if (type === "number") {
      const n = Number(val.toString().replace(/\./g, "").replace(",", "."));
      return isNaN(n) ? Number.NEGATIVE_INFINITY : n;
    }
    if (type === "date") {
      const t = Date.parse(val);
      return isNaN(t) ? -Infinity : t;
    }
    if (type === "time") {
      const m = /^(\d{1,2}):(\d{2})$/.exec(val);
      if (!m) return -Infinity;
      return +m[1] * 3600 + +m[2] * 60;
    }
    return (val || "").toLowerCase();
  };

  // --- Sortierung ---
  let lastSort = { index: -1, dir: 1 };
  table.querySelectorAll("thead th").forEach((th, idx) => {
    const type = th.getAttribute("data-type");
    if (type === "none") return;
    th.addEventListener("click", () => {
      const columnType = th.getAttribute("data-type") || "text";
      if (lastSort.index === idx) lastSort.dir = -lastSort.dir;
      else lastSort = { index: idx, dir: 1 };

      const rows = Array.from(tbody.querySelectorAll("tr"));
      rows.sort((a, b) => {
        const va = parseByType(getCellValue(a, idx), columnType);
        const vb = parseByType(getCellValue(b, idx), columnType);
        if (va < vb) return -1 * lastSort.dir;
        if (va > vb) return 1 * lastSort.dir;
        return 0;
      });
      rows.forEach(r => tbody.appendChild(r));

      table.querySelectorAll("thead th").forEach(h => {
        if (h.getAttribute("data-type") !== "none")
          h.classList.remove("table-active");
      });
      th.classList.add("table-active");
      th.querySelector(".sort-ind")?.remove();
      const ind = document.createElement("span");
      ind.className = "sort-ind";
      ind.textContent = lastSort.dir === 1 ? "↑" : "↓";
      th.appendChild(ind);

      regroupGroups();
      computeStats();
    });
  });

  // ---- Fokus-Helper für eine Zelle (Editor) ----
function focusEditor(tr, colIdx){
  const el = tr?.children?.[colIdx]?.querySelector?.('input,select');
  if (el) { el.focus(); el.select?.(); }
}

// ---- Duplizieren einer Zeile (Button + Shortcut benutzen diese Funktion) ----
function duplicateRow(srcTr, { markAsDup = true } = {}) {
  if (!srcTr) return null;

  const eingang = getCellValue(srcTr, 0);         // gleich lassen
  const lagergrp = getCellValue(srcTr, 2);        // gleich lassen
  const sach     = getCellValue(srcTr, 13);       // gleich lassen
  const behnr    = getCellValue(srcTr, 12);       // (lassen wir gleich)

  const values = [];
  for (let i=0;i<17;i++){
    let v = getCellValue(srcTr, i);
    // Regeln fürs Duplikat:
    if (i===0) v = eingang;           // Eing.-Nr. bleibt
    if (i===1) v = '';                // Lieferschein leer + Fokus später hierhin
    if (i===2) v = lagergrp;          // Lagergruppe aus Vorlage
    if (i===3) v = '';                // Datum leer
    if (i===4) v = '';                // Kennzeichen leer
    if (i===5) v = '';                // Land leer
    if (i===6) v = '';                // Spedition leer
    if (i===7 || i===8 || i===9) v='';// Zeiten leer (Ank./Beg./Ende)
    if (i===10 || i===11 || i===14) v='0'; // Behälter/Zus.-Beh./Menge -> 0
    if (i===12) v = behnr;            // Behälternr. wie Vorlage (anpassbar)
    if (i===13) v = sach;             // Sachnummer wie Vorlage
    if (i===15) v = '';               // Gebucht leer
    // i===16 (Gebucht von) lassen wir wie Vorlage
    values[i] = v;
  }

  const tr = document.createElement('tr');
  for (let i=0;i<17;i++){
    const td = document.createElement('td');
    td.dataset.raw = values[i];
    td.textContent = values[i];
    tr.appendChild(td);
  }
  const tdAction = document.createElement('td');
  tdAction.appendChild(makeActionGroup(true)); // gleich im Editmodus (✔)
  tr.appendChild(tdAction);

  // unter der Quellzeile einfügen
  srcTr.parentElement.insertBefore(tr, srcTr.nextSibling);

  // Edit-Modus aktivieren & Inputs mit Werten füllen
  setRowMode(tr, 'edit');
  tr.dataset.saved = '0';
  if (markAsDup) tr.dataset.isDup = '1';

  editRow(tr); // ersetzt Zellen durch Inputs/Selects

  // Werte in Inputs/Selects setzen (weil editRow die Inputs neu anlegt)
  for (let i=0;i<17;i++){
    if (i===2) { // Lagergruppe ist plaintext
      const span = tr.children[2].querySelector('.form-control-plaintext');
      if (span) span.textContent = values[2];
      continue;
    }
    const sel = tr.children[i].querySelector('select');
    const inp = tr.children[i].querySelector('input');
    if (sel) sel.value = values[i];
    else if (inp) inp.value = values[i];
  }

  // Autocomplete für die neue Zeile binden (falls vorhanden)
  

  // Gruppierung/Filter/Badges neu
  regroupGroups(); rebuildFilterOptions(); applyFilter(); computeStats?.();
  (async ()=>{ try { await refreshAllAttachmentBadges(); } catch(e){} })();

  // Fokus in Fracht/Lieferschein (Spalte 1)
  focusEditor(tr, 1);
  return tr;
}

  // --- Statistik ---
  
  function toInt(x) {
    if (x == null) return 0;
    const n = Number(String(x).replace(/\./g, "").replace(",", "."));
    return isNaN(n) ? 0 : Math.trunc(n);
  }
  function getRangeByKey(key, d = new Date()) {
    if (key === "last") {
      const start = new Date(d.getFullYear(), d.getMonth() - 1, 1);
      const end = new Date(d.getFullYear(), d.getMonth(), 1);
      return { start, end, label: fmtMonth(start) };
    }
    if (key === "all") {
      return { start: null, end: null, label: "Alle Monate" };
    }
    const start = new Date(d.getFullYear(), d.getMonth(), 1);
    const end = new Date(d.getFullYear(), d.getMonth() + 1, 1);
    return { start, end, label: fmtMonth(start) };
  }
  function fmtMonth(date) {
    return new Intl.DateTimeFormat("de-DE", {
      month: "long",
      year: "numeric",
    }).format(date);
  }

function computeStats() {
  const key = statsRangeEl ? statsRangeEl.value : "current";
  const range = getRangeByKey(key);

  // Helpers für Δ Vortag
  function prevDayISO(iso) {
    const d = parseISODate(iso);
    if (!d) return null;
    d.setDate(d.getDate() - 1);
    return formatISO(d);
  }

  const sumBySach = new Map();
  const offeneSet = new Set();
  const offeneList = new Map();

  // Reihenfolge/„Buckets“ beibehalten
  const TARGET_GROUPS = ["W1", "X3", "X3(B)", "G9", "B1", "Bauteile", "BM", "Müll"];
  const sumByLagergruppe = new Map(TARGET_GROUPS.map(g => [g, 0]));

  // Detail-Daten je Lagergruppe
  const detailsByLG = new Map(
    TARGET_GROUPS.map(g => [g, { deliveries:new Set(), zus:0, menge:0, perEingang:new Map() }])
  );

  const rows = [...tbody.querySelectorAll("tr")].filter(
    tr => tr.dataset.saved === "1" || tr.dataset.mode === "edit"
  );
  const nrDateMap = buildDateByAusgang(rows);


  /* ===== Wareneingänge pro Tag (distinct Eingangsnummern) + Δ Vortag ===== */
  const perDayMap = new Map(); // key: 'YYYY-MM-DD' -> Set<Eingangsnummer>

  rows.forEach((tr) => {
    const dtText = getCellValue(tr, 3).trim();
    const dt = parseISODate(dtText);
    if (!dt) return;

    // Zeitraum-Filter respektieren
    if (range.start && dt < range.start) return;
    if (range.end && dt >= range.end) return;

    const dayKey = dtText; // liegt als YYYY-MM-DD vor
    const eing   = (getCellValue(tr, 0) || '').trim();
    if (!eing) return;

    if (!perDayMap.has(dayKey)) perDayMap.set(dayKey, new Set());
    perDayMap.get(dayKey).add(eing);
  });

  const perDayArr = [...perDayMap.entries()]
    .map(([d, set]) => [d, set.size])
    .sort((a, b) => b[0].localeCompare(a[0])); // neueste zuerst

  const perDayBody = document.getElementById('statsPerDay');
  const perDayInfo = document.getElementById('statsPerDayInfo');

  if (perDayBody) {
    perDayBody.innerHTML = '';
    if (perDayArr.length === 0) {
      perDayBody.innerHTML = '<tr><td colspan="3" class="text-muted">Keine Daten im Zeitraum.</td></tr>';
    } else {
      perDayArr.forEach(([dayKey, cnt]) => {
        const prevKey = prevDayISO(dayKey);
        const prevCnt = prevKey && perDayMap.has(prevKey) ? perDayMap.get(prevKey).size : 0;
        const delta = cnt - prevCnt;

        let deltaHtml = '<span class="text-muted"><i class="bi bi-dash-lg"></i> 0</span>';
        if (delta > 0) {
          deltaHtml = `<span class="text-success"><i class="bi bi-arrow-up-short"></i>+${delta}</span>`;
        } else if (delta < 0) {
          deltaHtml = `<span class="text-danger"><i class="bi bi-arrow-down-short"></i>${delta}</span>`;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${dayKey}</td>
          <td class="text-end">${cnt}</td>
          <td class="text-end">${deltaHtml}</td>
        `;

        // Optional: Klick filtert Tabelle auf dieses Datum
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', () => {
          const sf = document.getElementById('searchField');
          if (sf) sf.value = 'all';
          const si = document.getElementById('searchInput');
          if (si) { si.value = dayKey; applyFilter(); }
          document.getElementById('eingangTable')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        perDayBody.appendChild(tr);
      });
    }
  }
  if (perDayInfo) perDayInfo.textContent = `Zeitraum: ${range.label}`;

  /* ===== Restliche Aggregationen ===== */
  rows.forEach((tr) => {
   const dt = rowDateOrGroupDate(tr, nrDateMap);
if (range.start && (!dt || dt < range.start)) return;
if (range.end && (!dt || dt >= range.end)) return;


    const sach = getCellValue(tr, 13).trim();
    const behaelter = toInt(getCellValue(tr, 10));
    const zus       = toInt(getCellValue(tr, 11)); // Zusatz-Behälter
    const menge     = toInt(getCellValue(tr, 14)); // Menge
    const gebucht   = (getCellValue(tr, 15) || "").trim() !== "";
    const eingang   = getCellValue(tr, 0).trim();
    const lg        = getCellValue(tr, 2).trim();

    if (sach) sumBySach.set(sach, (sumBySach.get(sach) || 0) + behaelter);
    if (!gebucht && eingang) { offeneSet.add(eingang); offeneList.set(eingang, true); }

    if (sumByLagergruppe.has(lg)) {
      sumByLagergruppe.set(lg, (sumByLagergruppe.get(lg) || 0) + behaelter);

      const d = detailsByLG.get(lg);
      d.deliveries.add(eingang);
      d.zus   += zus;
      d.menge += menge;

      let pe = d.perEingang.get(eingang);
      if (!pe) { pe = { rows:0, behaelter:0, zus:0, menge:0 }; d.perEingang.set(eingang, pe); }
      pe.rows      += 1;
      pe.behaelter += behaelter;
      pe.zus       += zus;
      pe.menge     += menge;
    }
  });

/* ===== Top-Items (Sachnummer ODER – falls leer – Behälter-Nr.) ===== */
function keyForRow(tr){
  const sach = (getCellValue(tr, 13) || '').trim(); // Sachnummer
  if (sach) return { key: `SN|${sach}`, label: sach };
  const beh  = (getCellValue(tr, 12) || '').trim(); // Behälter-Nr.
  if (beh)  return { key: `BH|${beh}`, label: `Beh.-Nr. ${beh}` };
  return null; // weder noch -> nicht zählen
}

const sumByItem  = new Map();  // key -> Summe Behälter
const labelByKey = new Map();  // key -> angezeigter Text

rows.forEach((tr) => {
  // <<< WICHTIG: Zeitraum-Filter aus Spalte 4 (Datum) anwenden
  const dt = parseISODate(getCellValue(tr, 3));
  if (range.start && (!dt || dt < range.start)) return;
  if (range.end   && (!dt || dt >= range.end))  return;

  const behaelter = toInt(getCellValue(tr, 10));
  const k = keyForRow(tr);
  if (!k) return;

  sumByItem.set(k.key, (sumByItem.get(k.key) || 0) + behaelter);
  if (!labelByKey.has(k.key)) labelByKey.set(k.key, k.label);
});

const top = [...sumByItem.entries()]
  .sort((a,b) => b[1] - a[1])
  .slice(0, TOP_LIMIT);

const tbodyStats = document.getElementById("statsSach");
const info = document.getElementById("statsSachInfo");
if (tbodyStats) {
  tbodyStats.innerHTML = "";
  top.forEach(([key, sum]) => {
    const tr = document.createElement("tr");
    tr.dataset.topkey = key; // für Drilldown
    tr.innerHTML = `<td>${labelByKey.get(key)}</td><td class="text-end">${sum}</td>`;
    tbodyStats.appendChild(tr);
  });
  if (info) info.textContent = `Zeitraum: ${range.label} — Datengrundlage: gespeicherte/aktuelle Zeilen.`;
}
bindSachStatsInteractions?.();



  // Offene Eingänge
  const offCount = offeneSet.size;
  const offCountEl = document.getElementById("statsOffenCount");
  if (offCountEl) offCountEl.textContent = String(offCount);
  const listDiv = document.getElementById("statsOffenList");
  if (listDiv) {
    if (offCount === 0) {
      listDiv.innerHTML = `<span class="text-muted">Alles gebucht – keine offenen Eingangsnummern.</span>`;
    } else {
      const arr = [...offeneList.keys()].sort();
      listDiv.innerHTML = arr
        .map(k => `<a href="#" class="badge bg-warning-subtle text-warning-emphasis me-1 mb-1 open-eingang" data-id="${k}">${k}</a>`)
        .join(" ");
    }
  }

  // Lagergruppen-Karte (klickbar)
  const tbodyGruppen = document.getElementById("statsGruppen");
  const gesamtEl = document.getElementById("statsGruppenGesamt");
  if (tbodyGruppen && gesamtEl) {
    tbodyGruppen.innerHTML = "";
    let gesamt = 0;
    TARGET_GROUPS.forEach(grp => {
      const sum = sumByLagergruppe.get(grp) || 0;
      gesamt += sum;
      const tr = document.createElement("tr");
      tr.dataset.grp = grp;
      tr.innerHTML = `
        <td>
          <i class="bi bi-chevron-right grp-caret"></i>
          ${grp}
        </td>
        <td class="text-end">${sum}</td>`;
      tbodyGruppen.appendChild(tr);
    });
    gesamtEl.textContent = String(gesamt);
  }

  // Details global verfügbar für Accordion/Interaktionen
  window._STATS_DETAILS = detailsByLG;
  bindSachStatsInteractions?.();
}


// Klick auf Lagergruppe -> Detail-Zeile ein/aus
document.getElementById('statsGruppen')?.addEventListener('click', (ev) => {
  const tr = ev.target.closest('tr[data-grp]');
  if (!tr) return;

  // schon offen? -> schließen
  const next = tr.nextElementSibling;
  if (next && next.classList.contains('stats-details')) {
    next.remove();
    tr.querySelector('.grp-caret')?.classList.replace('bi-chevron-down','bi-chevron-right');
    return;
  }

  // neu öffnen
  const grp = tr.dataset.grp;
  const d = (window._STATS_DETAILS || new Map()).get(grp);
  if (!d) return;

  const deliveriesCount = d.deliveries.size;
  const zusSum = d.zus;
  const mengeSum = d.menge;

  // per Eingangsnummer aufbereiten
  const entries = Array.from(d.perEingang.entries());
  entries.sort((a,b) => {
    const ax = a[0], bx = b[0];
    const na = /^\d+$/.test(ax) ? Number(ax) : ax;
    const nb = /^\d+$/.test(bx) ? Number(bx) : bx;
    if (typeof na === 'number' && typeof nb === 'number') return na - nb;
    return String(ax).localeCompare(String(bx), 'de');
  });

  const rowsHtml = entries.length
    ? entries.map(([eing, x]) =>
        `<tr>
          <td>${eing}</td>
          <td class="text-end">${x.rows}</td>
          <td class="text-end">${x.behaelter}</td>
          <td class="text-end">${x.zus}</td>
          <td class="text-end">${x.menge}</td>
        </tr>`).join('')
    : '<tr><td colspan="5" class="text-muted">Keine Daten.</td></tr>';

  const detailsTr = document.createElement('tr');
  detailsTr.className = 'stats-details';
  const col = document.createElement('td');
  col.colSpan = 2;
  col.innerHTML = `
    <div class="p-2">
      <div class="small mb-2">
        <strong>${grp}</strong> — Wareneingänge: <strong>${deliveriesCount}</strong>
        · Zusatz-Beh.: <strong>${zusSum}</strong>
        · Menge: <strong>${mengeSum}</strong>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Eing.-Nr.</th>
              <th class="text-end">Zeilen</th>
              <th class="text-end">Paletten</th>
              <th class="text-end">Beh /KLT</th>
              <th class="text-end">Menge</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    </div>`;
  detailsTr.appendChild(col);

  tr.after(detailsTr);
  tr.querySelector('.grp-caret')?.classList.replace('bi-chevron-right','bi-chevron-down');
});




// === Drilldown: Top-Liste (Sachnummer ODER Beh.-Nr.) ==========================
function getCurrentRange() {
  const key = (document.getElementById('statsRange')?.value) || 'current';
  return getRangeByKey(key);
}

// key ist "SN|<sach>" oder "BH|<behnr>"
function gatherRowsInRangeForSach(key) {
  const { start, end } = getCurrentRange();
  const [type, val] = String(key||'').split('|');

  return [...document.querySelectorAll('#eingangTable tbody tr')]
    .filter(tr => tr.dataset.saved === '1' || tr.dataset.mode === 'edit')
    .filter(tr => {
      const dt = parseISODate(getCellValue(tr, 3));
      if (start && (!dt || dt < start)) return false;
      if (end   && (!dt || dt >= end))  return false;

      const sach = (getCellValue(tr, 13) || '').trim();
      const beh  = (getCellValue(tr, 12) || '').trim();

      if (type === 'SN') return sach === val;
      if (type === 'BH') return !sach && beh === val; // nur Leergut-Fälle
      return false;
    });
}


function toggleSachDetail(anchorTr, key) {
  // zu? -> zu
  const next = anchorTr.nextElementSibling;
  if (next && next.classList.contains('sach-detail')) { next.remove(); return; }
  // andere schließen
  anchorTr.parentElement.querySelectorAll('.sach-detail').forEach(n => n.remove());

  const rows = gatherRowsInRangeForSach(key);
  const label = anchorTr?.querySelector('td')?.textContent?.trim() || key;

  const byEing = new Map();
  let sumZus = 0, sumMenge = 0;

  for (const tr of rows) {
    const eing   = getCellValue(tr, 0).trim();
    const zus    = toInt(getCellValue(tr, 11));
    const menge  = toInt(getCellValue(tr, 14));
    const datum  = getCellValue(tr, 3);
    sumZus   += zus;
    sumMenge += menge;

    const acc = byEing.get(eing) || { eingang: eing, count: 0, sumZus: 0, sumMenge: 0, dates: new Set() };
    acc.count += 1;
    acc.sumZus += zus;
    acc.sumMenge += menge;
    if (datum) acc.dates.add(datum);
    byEing.set(eing, acc);
  }

  const deliveries = byEing.size;

  const rowsHtml = [...byEing.values()]
    .sort((a,b) => a.eingang.localeCompare(b.eingang, 'de', { numeric:true }))
    .map(v => {
      const datesStr = [...v.dates].sort().join(', ');
      return `<tr>
        <td>${v.eingang || '-'}</td>
        <td>${datesStr || '-'}</td>
        <td class="text-end">${v.count}</td>
        <td class="text-end">${v.sumZus}</td>
        <td class="text-end">${v.sumMenge}</td>
      </tr>`;
    }).join('');

  const html = `<tr class="sach-detail">
    <td colspan="2">
      <div class="p-2 bg-body-tertiary rounded border">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge text-bg-primary">${label}</span>
          <span class="badge text-bg-secondary">Lieferungen: <strong>${deliveries}</strong></span>
          <span class="badge text-bg-secondary">Zus.-Beh.: <strong>${sumZus}</strong></span>
          <span class="badge text-bg-success">Menge: <strong>${sumMenge}</strong></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
           <thead>
  <tr>
    <th>Ausg.-Nr.</th>
    <th>Datum</th>
    <th class="text-end">Zeilen</th>
    <th class="text-end">Zus.-Beh.</th>
    <th class="text-end">Menge</th>
  </tr>
</thead>

            <tbody>
              ${rowsHtml || '<tr><td colspan="5" class="text-muted">Keine Daten im Zeitraum.</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>
    </td>
  </tr>`;

  anchorTr.insertAdjacentHTML('afterend', html);
}

function bindSachStatsInteractions() {
  const tb = document.getElementById('statsSach');
  if (!tb) return;
  tb.querySelectorAll('tr').forEach(tr => {
    if (tr.dataset.boundSach === '1') return;
    tr.dataset.boundSach = '1';
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', () => {
      const key = tr.dataset.topkey || ''; // kommt jetzt aus computeStats()
      toggleSachDetail(tr, key);
    });
  });
}

  // --- Action-Spalte ---
  function ensureActionCell(tr) {
    const last = tr.lastElementChild;
    if (!last || last.cellIndex < 18) {
      const td = document.createElement("td");
      td.appendChild(makeActionGroup(false));
      tr.appendChild(td);
    } else if (last && last.childElementCount === 0) {
      last.appendChild(makeActionGroup(false));
    }
  }

btnAdd.addEventListener("click", () => {
  const tr = document.createElement("tr");

  for (let i = 0; i < 17; i++) {
    const def = COLUMNS[i];
    const td  = document.createElement("td");

    if (i === 2) {
      td.innerHTML = `<span class="form-control-plaintext"></span>`;
    } else if (def.type === "select") {
      td.innerHTML = `<select class="form-select form-select-sm">
        ${def.options.map((o, idx) => `<option${idx===0?" selected":""}>${o}</option>`).join("")}
      </select>`;
    }  else {
  const extra = def.min !== undefined ? ` min="${def.min}"` : "";
  const step  = def.step !== undefined ? ` step="${def.step}"` : "";

  if (def.type === "date" && i === 3) {
    td.innerHTML = `<input type="date" class="form-control form-control-sm"${extra}${step} value="${todayISO()}">`;
  } else {
    const val = (def.type === "number" || i === 10 || i === 11 || i === 14) ? "0" : "";
    td.innerHTML = `<input type="${def.type}" class="form-control form-control-sm"${extra}${step} value="${val}" autocomplete="off" autocapitalize="off" spellcheck="false">`;
  }
    }
    tr.appendChild(td);
  }

  // Eing.-Nr. vorbefüllen (falls leer)
  const inpEin = tr.children[0]?.querySelector("input");
  if (inpEin && !inpEin.value) inpEin.value = nextEingangsnummer();

  const tdAction = document.createElement("td");
  tdAction.appendChild(makeActionGroup(true));
  tr.appendChild(tdAction);

  tbody.appendChild(tr);
  setRowMode(tr, "edit");
  tr.dataset.saved = "0";

  
  const landInp = tr.children[5]?.querySelector("input");
  if (landInp) {
    landInp.setAttribute("maxlength","2");
    landInp.setAttribute("inputmode","text");
    landInp.setAttribute("pattern","[A-Za-z]{2}");
    landInp.addEventListener("input", () => {
      landInp.value = landInp.value.replace(/[^A-Za-z]/g,"").toUpperCase().slice(0,2);
    });
  }

  // LG dynamisch aus Sachnummer/Behälter nachführen
  const behnrInp = tr.children[12]?.querySelector('input');
  const sachInp  = tr.children[13]?.querySelector('input');
  const reapplyLG = () => applyLGForRow(tr);
  behnrInp?.addEventListener('input', reapplyLG);
  behnrInp?.addEventListener('blur',  reapplyLG);
  sachInp?.addEventListener('input',  reapplyLG);
  sachInp?.addEventListener('blur',   reapplyLG);
  reapplyLG(); // initial setzen

  

  tr.querySelector("input,select")?.focus();

  rebuildFilterOptions();
  applyFilter();
  computeStats();
  
});


  function setRowMode(tr, mode) {
    tr.dataset.mode = mode;
  }

function editRow(tr) {
  for (let i = 0; i < 17; i++) {
    const def = COLUMNS[i];
    const td  = tr.children[i];
    const currentValue = getCellValue(tr, i);
    delete td.dataset.raw;

    // Lagergruppe (Index 2) NICHT editierbar
    if (i === 2) {
      td.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(currentValue)}</span>`;
      continue;
    }

    if (def.type === "select") {
      td.innerHTML = `<select class="form-select form-select-sm">
        ${def.options.map(o => `<option${o === currentValue ? " selected" : ""}>${o}</option>`).join("")}
      </select>`;
      continue;
    }

    const extra = def.min !== undefined ? ` min="${def.min}"` : "";
    const step  = def.step !== undefined ? ` step="${def.step}"` : "";
    let val     = sanitizeAttr(currentValue);
    if (def.type === "date" && i === 3) val = val || todayISO();

    td.innerHTML = `<input type="${def.type}" class="form-control form-control-sm"${extra}${step} value="${val}" autocomplete="off" autocapitalize="off" spellcheck="false">`;
  }

  
  // Land ISO-2 nur Buchstaben, uppercase (Index 5)
  const landInp = tr.children[5]?.querySelector("input");
  if (landInp) {
    landInp.setAttribute("maxlength","2");
    landInp.setAttribute("inputmode","text");
    landInp.setAttribute("pattern","[A-Za-z]{2}");
    landInp.addEventListener("input", () => {
      landInp.value = landInp.value.replace(/[^A-Za-z]/g,"").toUpperCase().slice(0,2);
    });
  }

}



  function saveRow(tr) {
  for (let i = 0; i < 17; i++) {
    const td  = tr.children[i];
    const sel = td.querySelector("select");
    const inp = td.querySelector("input");
    let value = "";

    if (sel) value = sel.options[sel.selectedIndex]?.text || "";
    else if (inp) value = inp.value || "";
    else value = td.textContent || "";

    
    // Land ISO-2 bereinigen + warnen
    if (i === 5) {
      value = (value || "").toUpperCase().replace(/[^A-Z]/g,"").slice(0,2);
      if (value && !ISO2.has(value)) {
        alert(`Ungültiges Länderkürzel: "${value}". Bitte ISO-2 verwenden (z. B. DE, NL, PL).`);
      }
    }

    setCellRaw(td, (value || "").trim());
  }

  // Lagergruppe aus Sachnummer (Index 13) erzwingen
  const lgTd = tr.children[2];
if (lgTd) setCellRaw(lgTd, computeLGForRow(tr));

}


  function sanitizeAttr(v) {
    return String(v ?? "").replace(/"/g, "&quot;");
  }






  // --- Filter + Suche ---
  filterSelect.addEventListener("change", applyFilter);
  searchAllCols.addEventListener("change", applyFilter);
  btnResetFilter.addEventListener("click", () => {
    filterSelect.value = "";
    searchInput.value = "";
    searchAllCols.checked = false;
    applyFilter();
  });

  function rebuildFilterOptions() {
    const current = filterSelect.value;
    const counts = new Map();
    Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
      const key = getCellValue(tr, 0).trim();
      if (!key) return;
      counts.set(key, (counts.get(key) || 0) + 1);
    });

    filterSelect.innerHTML = '<option value="">Alle</option>';
    Array.from(counts.keys())
      .sort()
      .forEach((k) => {
        const opt = document.createElement("option");
        opt.value = k;
        opt.textContent = `${k} (${counts.get(k)})`;
        filterSelect.appendChild(opt);
      });

    if ([...counts.keys()].includes(current)) filterSelect.value = current;
    else filterSelect.value = "";
  }

  function rowMatchesSearch(tr, needle, fieldKey) {
  if (!needle) return true;

  const terms = needle.toLowerCase().split(/\s+/).filter(Boolean);
  if (!terms.length) return true;

  const idx = SEARCH_FIELDS[fieldKey] ?? 0;

  const containsAll = (val) => {
    const v = (val || "").toLowerCase();
    return terms.every(t => v.includes(t));
  };

  if (idx === -1) {
    // alle Spalten
    for (let i = 0; i < 17; i++) {
      if (containsAll(getCellValue(tr, i))) return true;
    }
    return false;
  } else {
    return containsAll(getCellValue(tr, idx));
  }
}

  // Feld -> Spaltenindex in deiner Tabelle
const SEARCH_FIELDS = {
  eingang: 0,        // Eingangsnummer
  lieferschein: 1,   // Lieferschein-Nr.
  spedition: 6,      // Spedition
  sachnummer: 13,    // Sachnummer
  all: -1            // Spezialfall: alle Spalten
};

const searchField = document.getElementById("searchField");

// optional: Platzhalter dynamisch anpassen
const PLACEHOLDERS = {
  eingang: "z. B. WE-2025-00123",
  lieferschein: "z. B. LS-Nummer",
  spedition: "z. B. DPD / DHL / Rhenus",
  sachnummer: "z. B. 05C 145 785 D",
  all: "frei suchen (alle Spalten)"
};
searchField?.addEventListener("change", () => {
  const key = searchField.value || "eingang";
  document.getElementById("searchInput").placeholder = PLACEHOLDERS[key] || "";
  applyFilter();
});
function applyFilter() {
  const selected  = filterSelect.value;
  const query     = searchInput.value.trim();
  const fieldKey  = searchField?.value || "eingang";
  const activeMonth = window.WE_activeMonth || null; // 👈 Monat vom Accordion

  Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
    const key          = getCellValue(tr, 0);
    const matchDropdown = !selected || key === selected;
    const matchSearch   = rowMatchesSearch(tr, query, fieldKey);

    // Monatsfilter: nur Zeilen des aktiven Monats anzeigen
    let matchMonth = true;
    if (activeMonth) {
      const rowMonth = tr.dataset.weMonth || "";
      // neue / leere Zeilen ohne Datum trotzdem anzeigen
      matchMonth = !rowMonth || rowMonth === activeMonth;
    }

    const visible = matchDropdown && matchSearch && matchMonth;
    tr.style.display = visible ? "" : "none";
  });

  regroupGroups();
  computeStats();
  pinHeaderFirstPerGroup();
}

// 👇 direkt nach der Funktion ergänzen:
window.WE_applyFilters = applyFilter;


// Events
searchInput.addEventListener("input", debounce(applyFilter, 120));
searchField?.addEventListener("change", applyFilter);


  
  function removeBadge(tr) {
    tr.children[0].querySelectorAll(".badge-count").forEach((n) => n.remove());
  }
  function clearRowBorders(tr) {
  tr.classList.remove('saved-standalone','grp','grp-start','grp-end','grp-single');
}

function regroupGroups() {
  // 1) Reset aller Klassen & Badge-Counter
  const allRows = Array.from(tbody.querySelectorAll('tr'));
  allRows.forEach(tr => {
    clearRowBorders(tr);
    tr.children[0]?.querySelectorAll('.badge-count').forEach(n => n.remove());
    // Gruppen-Buttons erstmal sichtbar machen
    tr.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.remove('d-none'));
  });

  // 2) Nur sichtbare Zeilen in aktueller Bildschirm-Reihenfolge
  const rows = allRows.filter(tr => tr.offsetParent !== null);

  // 3) Über zusammenhängende Blöcke gleicher Eing.-Nr. laufen
  let i = 0;
  while (i < rows.length) {
    const key = normalizeKey(getCellValue(rows[i], 0));
    let j = i + 1;
    while (j < rows.length && normalizeKey(getCellValue(rows[j], 0)) === key) j++;

    if (key !== '') {
      const group = rows.slice(i, j);

      if (group.length === 1) {
        const r = group[0];
        r.classList.add('saved-standalone','grp','grp-start','grp-end','grp-single');
        // Buttons bleiben sichtbar (erste/ einzige Zeile)
      } else {
        group.forEach((r, idx) => {
          r.classList.add('grp');
          if (idx === 0) {
            r.classList.add('grp-start');
            // Gruppen-Buttons nur in der ersten Zeile anzeigen
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.remove('d-none'));
          } else if (idx === group.length - 1) {
            r.classList.add('grp-end');
            // In Zwischenzeilen Buttons verstecken
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.add('d-none'));
          } else {
            // Mittlere Zeilen: keine top/bottom, Buttons aus
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.add('d-none'));
          }
        });
      }
    }
    i = j;
  }
}


  // --- Hilfsfunktionen ---
  function debounce(fn, wait) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }
  tbody.addEventListener("input", debounce(() => computeStats(), 200));
  tbody.addEventListener("change", () => computeStats());



function pickHeaderRow(rows) {
  const HEAD_COLS = [3,4,5,6,7,8,9]; // Datum, Kennz., Land, Spedition, Ankunft, Beginn, Ende
  const score = tr => HEAD_COLS.reduce((s,i)=> s + (getCellValue(tr,i).trim()?1:0), 0);
  return rows.reduce((best, tr) => (score(tr) > score(best) ? tr : best), rows[0]);
}

// Öffnet den Lagerplan (Halle 3) für die gewählte Eingangsnummer
function openLagerForEingang(tr) {
  if (!tr) return;

  const eingangNr = (getCellValue(tr, 0) || "").trim();   // Spalte 0: Eing.-Nr.
  const gebucht   = (getCellValue(tr, 15) || "").trim();  // Spalte 15: Gebucht
  const dbId      = tr.dataset.dbid ? Number(tr.dataset.dbid) : null;

  if (!eingangNr) {
    alert("Bitte zuerst eine Eingangsnummer eintragen und speichern.");
    return;
  }

  // Optional: nur bei 'Ja' erlauben (sonst Hinweis)
  if (gebucht !== "Ja") {
    const ok = confirm(
      "Der Wareneingang ist noch nicht als 'Gebucht' markiert.\n" +
      "Trotzdem Lagerplatz für diese Eingangsnummer zuweisen?"
    );
    if (!ok) return;
  }

  // URL für Halle 3 (Pfad bei dir ggf. anpassen: .php / .html)
  const params = new URLSearchParams();
  params.set("eingang", eingangNr);
  if (dbId) params.set("we_id", String(dbId));

  const url = `/Lagerplan/halle3.php?${params.toString()}`;
  window.open(url, "_blank");
}

// Öffnet druck_wa.html und übergibt die Daten der gewählten Eingangsnummer
async function exportGroupToXlsx(clickedTr) {
  // 1) Gruppe ermitteln
  const eingangNr = getCellValue(clickedTr, 0).trim(); // Spalte A
  if (!eingangNr) { alert("Keine Eingangsnummer in der Zeile."); return; }

  const allRows = [...table.querySelectorAll("tbody tr")]
    .filter(tr => (tr.dataset.saved === "1" || tr.dataset.mode === "edit") && tr.offsetParent !== null);

  const groupRows = allRows.filter(tr => getCellValue(tr, 0).trim() === eingangNr);
  if (!groupRows.length) { alert("Zur Eingangsnummer wurden keine Zeilen gefunden."); return; }

  // 2) Header-Werte aus der ersten Gruppenzeile (exakt nach deiner Vorgabe)
  const headerRow = pickHeaderRow(groupRows);
  const header = {
    eingangNr,
    ankunft:   getCellValue(headerRow, 7),
    datum:     getCellValue(headerRow, 3),
    spedition: getCellValue(headerRow, 6),
    kennz:     getCellValue(headerRow, 4),
    beginn:    getCellValue(headerRow, 8),
    ende:      getCellValue(headerRow, 9)
  };

  // 3) Tabellenzeilen (Seite 2) – LS, Verk, Sach, qty
  const rows = groupRows.map(tr => ({
    ls:   getCellValue(tr, 1),                 // B -> LS-Nr.
    verk: getCellValue(tr, 2),                 // C -> Verk (Lagergruppe)
    sach: getCellValue(tr, 13),                // N -> Sachnummer
    qty:  Number(String(getCellValue(tr, 10)).replace(/\./g,'').replace(',','.')) || 0, // K -> Paletten/KLT
    noLabel: false
  }));

  const sum = rows.reduce((a,r)=>a+(r.qty||0), 0);

  // 4) Payload able
  const payload = { header, rows, sum };
  const KEY = "waPrintPayload";
  try {
    sessionStorage.setItem(KEY, JSON.stringify(payload));
  } catch (e) {
    console.warn("sessionStorage gesperrt – nutze window.name", e);
    window.name = JSON.stringify({ [KEY]: payload });
  }

  // 5) Druckseite öffnen
  window.open("/druck_wa.html", "_blank");
}
// ... ganz unten in wareneingang.js, nach den anderen Inits
if (statsRangeEl) {
  statsRangeEl.addEventListener("change", () => computeStats());
}

// Einmal initial berechnen
computeStats();
// Hilfsfunktion oben bei den anderen Utils ergänzen:
function todayISO() {
  const t = new Date();
  return `${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,'0')}-${String(t.getDate()).padStart(2,'0')}`;
}
document.getElementById("btnExportCsv")?.addEventListener("click", () => {
  exportTableToCSV("wareneingang.csv");
});


function csvEscape(text) {
  let t = String(text ?? "");
  t = t.replace(/"/g, '""');     // Quotes escapen
  if (/[;\n"]/.test(t)) t = `"${t}"`; // bei Trennzeichen/Zeilenumbruch/Quote einkapseln
  return t;
}
// Baut eine CSV NUR aus den übergebenen <tr>-Zeilen (z.B. Vorwoche)
function buildCsvFromRows(rows) {
  const table = document.getElementById("eingangTable");
  const out = [];

  // Header: nur Text aus .th-lines (ohne Sort-Pfeile)
  if (table.tHead && table.tHead.rows.length) {
    const headerCells = Array.from(table.tHead.rows[0].cells);
    const headerRow = headerCells.map(th => {
      const labelEl = th.querySelector(".th-lines");
      const text = (labelEl ? labelEl.textContent : th.textContent || "")
        .replace(/\s+/g, " ")
        .trim();
      return csvEscape(text);
    }).join(";");
    out.push(headerRow);
  }

  // Body-Zeilen: genau die übergebenen rows
  rows.forEach(tr => {
    const cols = Array.from(tr.cells).map(td => {
      // Rohwert bevorzugen
      let val = td.dataset?.raw;

      // Falls im Edit-Modus
      if (val == null) {
        const sel = td.querySelector("select");
        const inp = td.querySelector("input");
        if (sel) val = sel.options[sel.selectedIndex]?.text ?? "";
        else if (inp) val = inp.value ?? "";
      }

      // Fallback: reiner Text ohne Badges
      if (val == null) {
        const clone = td.cloneNode(true);
        clone.querySelectorAll(".badge-count, .sort-ind").forEach(n => n.remove());
        val = (clone.textContent || "").trim();
      }

      return csvEscape(val);
    });
    out.push(cols.join(";")); // DE-Excel: Semikolon
  });

  return out.join("\n");
}

// Download-Helfer
function downloadCsv(filename, csvString) {
  const blob = new Blob([csvString], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
}

function startOfWeek(d) { // Montag als Wochenstart
  const dt = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const day = (dt.getDay() + 6) % 7; // Mo=0 ... So=6
  dt.setDate(dt.getDate() - day);
  dt.setHours(0,0,0,0);
  return dt;
}
function lastWeekRange(today = new Date()) {
  const thisMon = startOfWeek(today);
  const lastMon = new Date(thisMon); lastMon.setDate(thisMon.getDate() - 7);
  const lastSun = new Date(lastMon); lastSun.setDate(lastMon.getDate() + 6);
  return { start: lastMon, end: lastSun }; // inkl. beider Tage
}
function parseISODate(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((s || "").trim());
  if (!m) return null;
  return new Date(+m[1], +m[2]-1, +m[3]);
}
function formatISO(d) {
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}
function isoWeek(d) { // ISO-KW für Date
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = date.getUTCDay() || 7;
  date.setUTCDate(date.getUTCDate() + 4 - dayNum);
  const yearStart = new Date(Date.UTC(date.getUTCFullYear(),0,1));
  const week = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
  return { week, year: date.getUTCFullYear() };
}
function nextEingangsnummer() {
  const nums = [...document.querySelectorAll("#eingangTable tbody tr")]
    .map(tr => (tr.children[0]?.dataset?.raw || "").trim())
    .map(v => /^\d+$/.test(v) ? Number(v) : null)
    .filter(v => v != null);
  const max = nums.length ? Math.max(...nums) : 0;
  return String(max + 1);
}

// --- Vorschau-State ---
let _attPreview = { url: null, mime: null, filename: null };

function guessMimeFromName(name = "") {
  const ext = String(name).toLowerCase().split('.').pop();
  const map = {
    jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif',
    webp: 'image/webp', bmp: 'image/bmp', svg: 'image/svg+xml',
    pdf: 'application/pdf'
  };
  return map[ext] || '';
}

function isPreviewable(mimeOrName = "") {
  const m = (mimeOrName.includes('/') ? mimeOrName : guessMimeFromName(mimeOrName)).toLowerCase();
  return m.startsWith('image/') || m === 'application/pdf';
}

function clearPreview() {
  _attPreview = { url: null, mime: null, filename: null };
  const wrap = document.getElementById('attPreview');
  if (wrap) wrap.innerHTML = '<div class="text-muted mt-5">Keine Vorschau ausgewählt.</div>';
  const printBtn = document.getElementById('attPrintBtn');
  if (printBtn) printBtn.disabled = true;
}
function showPreview(viewUrl, mimeOrName, filename) {
  const wrap = document.getElementById('attPreview');
  const printBtn = document.getElementById('attPrintBtn');
  if (!wrap) { console.warn('#attPreview fehlt'); return; }

  const mime = (mimeOrName && mimeOrName.includes('/')) ? mimeOrName : guessMimeFromName(filename || mimeOrName || '');

  wrap.innerHTML = ''; // leeren
  _attPreview = { url: viewUrl, mime: mime || '', filename: filename || '' };

  if (mime && mime.startsWith('image/')) {
    const img = document.createElement('img');
    img.src = viewUrl;
    img.alt = filename || 'Bild';
    img.style.maxWidth = '100%';
    img.style.maxHeight = '70vh';
    img.loading = 'lazy';
    wrap.appendChild(img);
  } else if (mime === 'application/pdf') {
    const iframe = document.createElement('iframe');
    iframe.src = viewUrl;
    iframe.title = filename || 'PDF';
    iframe.style.width = '100%';
    iframe.style.height = '70vh';
    iframe.setAttribute('loading', 'lazy');
    wrap.appendChild(iframe);
  } else {
    wrap.innerHTML = `
      <div class="py-5">
        <div class="text-muted mb-2">Keine Vorschau verfügbar.</div>
        <a class="btn btn-sm btn-outline-primary" href="${viewUrl}" target="_blank" rel="noopener">Öffnen</a>
      </div>`;
  }

  if (printBtn) printBtn.disabled = !(mime && (mime.startsWith('image/') || mime === 'application/pdf'));
}

function printCurrentPreview() {
  const { url, mime, filename } = _attPreview || {};
  if (!url || !isPreviewable(mime)) return;

  // Versuch 1: In neuem Fenster anzeigen und direkt drucken
  const w = window.open('', '_blank');
  if (!w) { alert('Popup-Blocker verhindert Druckvorschau.'); return; }

  if ((mime || '').toLowerCase().startsWith('image/')) {
    // Einfaches HTML mit dem Bild
    w.document.write(`
      <!doctype html><html><head><meta charset="utf-8">
      <title>${filename ? ('Druck – ' + filename) : 'Druck'}</title>
      <style>body{margin:0}img{max-width:100%;max-height:100vh;display:block;margin:0 auto}</style>
      </head><body><img src="${url}" onload="window.print();window.onfocus=function(){window.close();}"></body></html>`);
    w.document.close();
  } else if ((mime || '').toLowerCase() === 'application/pdf') {
    // PDF direkt einbetten – die meisten Browser drucken das via print()
    w.document.write(`
      <!doctype html><html><head><meta charset="utf-8">
      <title>${filename ? ('Druck – ' + filename) : 'Druck'}</title>
      <style>html,body,iframe{height:100%;width:100%;margin:0;border:0}</style>
      </head><body>
        <iframe src="${url}" onload="setTimeout(()=>{this.contentWindow && this.contentWindow.print ? this.contentWindow.print() : window.print();}, 200); window.onfocus=function(){setTimeout(()=>window.close(),100);}"></iframe>
      </body></html>`);
    w.document.close();
  } else {
    // Fallback – nur öffnen
    w.location.href = url;
  }
}
function exportTableToCSV(
  filename,
  { onlySaved = false, onlyVisible = false } = {}
) {
  const table = document.getElementById("eingangTable");
  if (!table || !table.tBodies.length) {
    alert("Tabelle nicht gefunden.");
    return;
  }

  const rowsOut = [];

  // === Header: Text nur aus .th-lines (ohne Sort-Indikator) ===
  if (table.tHead && table.tHead.rows.length) {
    const headerCells = Array.from(table.tHead.rows[0].cells);
    const headerRow = headerCells
      .map((th) => {
        const labelEl = th.querySelector(".th-lines");
        const text = (labelEl ? labelEl.textContent : th.textContent || "")
          .replace(/\s+/g, " ")
          .trim();
        return csvEscape(text);
      })
      .join(";");
    rowsOut.push(headerRow);
  }

  // === Body-Zeilen sammeln ===
  const bodyRows = Array.from(table.tBodies[0].rows);
  bodyRows.forEach((tr) => {
    if (onlySaved && tr.dataset.saved !== "1") return;
    if (onlyVisible && tr.offsetParent === null) return;

    const cols = Array.from(tr.cells).map((td) => {
      // Rohwert bevorzugen (sauber ohne Badges/Icons)
      let val = td.dataset?.raw;

      // Falls im Edit-Modus: Input/Select auslesen
      if (val == null) {
        const sel = td.querySelector("select");
        const inp = td.querySelector("input");
        if (sel) val = sel.options[sel.selectedIndex]?.text ?? "";
        else if (inp) val = inp.value ?? "";
      }

      // Fallback: reiner Text (Badges/Sort-Indikator entfernen)
      if (val == null) {
        const clone = td.cloneNode(true);
        clone.querySelectorAll(".badge-count, .sort-ind").forEach((n) => n.remove());
        val = (clone.textContent || "").trim();
      }

      return csvEscape(val);
    });

    rowsOut.push(cols.join(";")); // DE-Excel: Semikolon
  });

  // === Download erzeugen ===
  const csvContent = rowsOut.join("\n");
  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
}


  /* ===== Gruppelayout für gleiche Eing.-Nr.: robustes Re-Apply ===== */

/** Header-Spaltenindex der "Eing.-Nr." ermitteln */
function getEingangColIdx() {
  const ths = Array.from(document.querySelectorAll('#eingangTable thead th'));
  let idx = ths.findIndex(th => /eing\.\s*-?\s*nr/i.test(th.textContent.trim()));
  return idx >= 0 ? idx : 0;
}

/** Zellwert ermitteln: bevorzugt data-raw, sonst Input/Select, sonst Text */
function getCellVal(tr, colIdx) {
  const td = tr.cells[colIdx];
  if (!td) return '';
  if (td.dataset && typeof td.dataset.raw !== 'undefined') {
    return String(td.dataset.raw ?? '').trim();
  }
  const inp = td.querySelector('input, select');
  if (inp) return String(inp.value ?? '').trim();
  return String(td.textContent ?? '').trim();
}


/** Alle alten Gruppierungs-Klassen entfernen */
function clearGroupClasses(rows) {
  rows.forEach(r => r.classList.remove('grp','grp-start','grp-end','grp-single'));
}

/** Gruppen neu anwenden: zusammenhängende Blöcke gleicher Eing.-Nr. */
function reapplyGroups() {
  const table = document.getElementById('eingangTable');
  if (!table || !table.tBodies.length) return;
  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody.rows);
  const col   = getEingangColIdx();

  // erst alles zurücksetzen
  clearGroupClasses(rows);

  // dann neu gruppieren
  let i = 0;
  while (i < rows.length) {
    const key = normalizeKey(getCellVal(rows[i], col));
    let j = i + 1;
    while (j < rows.length && normalizeKey(getCellVal(rows[j], col)) === key) j++;

    if (key !== '') {
      const group = rows.slice(i, j);
      group.forEach(r => r.classList.add('grp'));
      if (group.length === 1) {
        group[0].classList.add('grp-single','grp-start','grp-end');
      } else {
        group[0].classList.add('grp-start');
        group[group.length - 1].classList.add('grp-end');
      }
    }
    i = j;
  }
}

/** Debounce-Helfer */
function debounce(fn, ms=60){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }

/** Beobachter installieren: reagiert auf Edits, Saves, Sortierungen, DOM-Changes */
function installGroupingObservers() {
  const table = document.getElementById('eingangTable');
  if (!table || !table.tBodies.length) return;
  const tbody = table.tBodies[0];

  const debounced = debounce(reapplyGroups, 20);

  // Eingaben/Änderungen (z. B. wenn Eing.-Nr. editiert wird)
  tbody.addEventListener('input',  debounced);
  tbody.addEventListener('change', debounced);

  // DOM-Änderungen (Bearbeiten/Speichern rendert oft neu)
  const mo = new MutationObserver(debounced);
  mo.observe(tbody, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-raw','class'] });

  // Initial anwenden
  reapplyGroups();

  // optional global verfügbar machen
  window._reapplyGroups = reapplyGroups;
}

// beim Setup (nachdem die Tabelle gerendert/befüllt ist) einmal aufrufen:
installGroupingObservers();
// EINZIGE gültige Definition:
async function renderAttachmentList(eingangNr) {
  const list = document.getElementById("attList");
  if (!list) {
    console.warn("#attList fehlt im Modal – bitte <div id=\"attList\"></div> einbauen.");
    return;
  }

  list.innerHTML = "";
  const files = await serverListAttachments(eingangNr);

  if (!files.length) {
    list.innerHTML = '<div class="text-muted">Keine Anhänge vorhanden.</div>';
    clearPreview();
    return;
  }

  const keepUrl = _attPreview?.url;

  files.forEach((f, idx) => {
    const viewUrl = `/${f.path_rel}`;
    const effMime = (f.mime_type && f.mime_type !== 'application/octet-stream')
      ? f.mime_type
      : guessMimeFromName(f.filename);

    const item = document.createElement("div");
    item.className = "list-group-item d-flex justify-content-between align-items-center";

    const metaLine = `${effMime || "Datei"} • ${(f.size_bytes/1024).toFixed(1)} KB • ${new Date(f.uploaded_at).toLocaleString()}`;

    item.innerHTML = `
      <div class="me-3 overflow-hidden">
        <div class="fw-semibold text-truncate" title="${f.filename}">${f.filename}</div>
        <div class="text-muted">${metaLine}</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-outline-secondary act-preview">Vorschau</button>
        <a class="btn btn-sm btn-outline-primary" href="${viewUrl}" target="_blank" rel="noopener">Ansehen</a>
        <a class="btn btn-sm btn-outline-success" href="${viewUrl}" download>Download</a>
        <button class="btn btn-sm btn-outline-danger act-delete">Löschen</button>
      </div>
    `;

    // Vorschau
    item.querySelector('.act-preview')?.addEventListener('click', () => {
      if (isPreviewable(effMime) || isPreviewable(f.filename)) {
        showPreview(viewUrl, effMime || f.filename, f.filename);
      } else {
        const wrap = document.getElementById('attPreview');
        if (wrap) {
          wrap.innerHTML = `
            <div class="py-5">
              <div class="text-muted mb-2">Dieser Dateityp hat keine integrierte Vorschau.</div>
              <a class="btn btn-sm btn-outline-primary" href="${viewUrl}" target="_blank" rel="noopener">Im neuen Tab öffnen</a>
            </div>`;
        }
      }
    });

    // Löschen
    item.querySelector(".act-delete")?.addEventListener("click", async () => {
      if (confirm(`„${f.filename}“ wirklich löschen?`)) {
        await serverDeleteAttachment(f.id);
        await renderAttachmentList(eingangNr);

        const tr = [...tbody.querySelectorAll("tr")].find(tr => getCellValue(tr,0).trim() === eingangNr);
        const paperclipBtn = tr?.querySelector(".action-btn.btn-outline-dark");
        if (tr && paperclipBtn) await refreshAttachmentBadgeForRow(tr, paperclipBtn);
      }
    });

    list.appendChild(item);

    // beim ersten Laden direkt zeigen
    if (!keepUrl && idx === 0 && (isPreviewable(effMime) || isPreviewable(f.filename))) {
      showPreview(viewUrl, effMime || f.filename, f.filename);
    }
  });

  // bestehende Vorschau beibehalten, falls Datei noch da
  if (keepUrl) {
    const hit = files.find(x => `/${x.path_rel}` === keepUrl);
    if (!hit) clearPreview();
  }
}



async function refreshAttachmentBadgeForRow(tr, btn) {
  const nr = getCellValue(tr, 0).trim();
  const files = nr ? await serverListAttachments(nr) : [];
  btn.querySelector(".att-badge")?.remove();
  if (files.length) {
    const b = document.createElement("span");
    b.className = "att-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark";
    b.textContent = files.length;
    btn.appendChild(b);
  }
}
// Lädt für alle sichtbaren Zeilen die Attachment-Badges nach
async function refreshAllAttachmentBadges() {
  const rows = [...tbody.querySelectorAll('tr')];
  for (const tr of rows) {
    // nur sichtbare Paperclip-Buttons (die erste Gruppenzeile)
    const btn = tr.querySelector('.action-btn.btn-outline-dark:not(.d-none)');
    if (btn) {
      try { await refreshAttachmentBadgeForRow(tr, btn); }
      catch (e) { console.warn('Badge-Refresh failed for row', getCellValue(tr,0), e); }
    }
  }
}



async function openAttachmentModal(eingangNr, tr, btn) {
  document.getElementById("attModalNr").textContent = eingangNr;
  await renderAttachmentList(eingangNr);

  const inp = document.getElementById("attFileInput");
  const printBtn = document.getElementById("attPrintBtn");

  if (inp) {
    inp.value = "";
    inp.onchange = async () => {
      if (inp.files && inp.files.length) {
        await serverUploadAttachments(eingangNr, inp.files);
        await renderAttachmentList(eingangNr);
        await refreshAttachmentBadgeForRow(tr, btn);
      }
    };
  }

  if (printBtn && !printBtn.dataset._bound) {
    printBtn.dataset._bound = '1';
    printBtn.addEventListener('click', () => printCurrentPreview());
  }

  const modalEl = document.getElementById("attModal");
  if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}



async function serverListAttachments(eingang) {
  try {
    const url = `${API_ATT}?action=list&eingang=${encodeURIComponent(eingang)}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const ct  = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();

    if (!res.ok) {
      console.warn('attachments_api.php list → HTTP', res.status, raw.slice(0, 300));
      return []; // weich fallen
    }

    if (!ct.includes('application/json')) {
      console.warn('attachments_api.php list → kein JSON. CT:', ct, 'RAW:', raw.slice(0, 300));
      return []; // weich fallen
    }

    let j;
    try { j = JSON.parse(raw); }
    catch (e) {
      console.warn('attachments_api.php list → JSON parse failed. RAW:', raw.slice(0, 300));
      return []; // weich fallen
    }

    if (!j || j.ok !== true || !Array.isArray(j.items)) {
      console.warn('attachments_api.php list → ungültiges JSON:', j);
      return []; // weich fallen
    }

    return j.items;
  } catch (e) {
    console.warn('attachments_api.php list → Netzwerk/Fetch-Fehler:', e);
    return []; // weich fallen
  }
}

async function serverUploadAttachments(eingang, fileList) {
  try {
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('eingang', eingang);
    [...fileList].forEach(f => fd.append('files[]', f, f.name));

    const res = await fetch(API_ATT, { method: 'POST', body: fd, credentials: 'same-origin' });
    const ct  = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();

    if (!res.ok) throw new Error(`HTTP ${res.status}: ${raw.slice(0, 300)}`);

    if (!ct.includes('application/json')) {
      throw new Error(`Kein JSON (Upload). CT=${ct} RAW=${raw.slice(0, 300)}`);
    }

    let j;
    try { j = JSON.parse(raw); }
    catch { throw new Error(`JSON parse fail (Upload): ${raw.slice(0, 300)}`); }

    if (!j.ok) throw new Error(j.error || 'upload failed');
    return j.items || [];
  } catch (e) {
    console.error('attachments_api.php upload → Fehler:', e);
    throw e; // beim Upload ruhig zeigen
  }
}

async function serverDeleteAttachment(id) {
  try {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);

    const res = await fetch(API_ATT, { method: 'POST', body: fd, credentials: 'same-origin' });
    const ct  = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();

    if (!res.ok) throw new Error(`HTTP ${res.status}: ${raw.slice(0, 300)}`);

    if (!ct.includes('application/json')) {
      throw new Error(`Kein JSON (Delete). CT=${ct} RAW=${raw.slice(0, 300)}`);
    }

    let j;
    try { j = JSON.parse(raw); }
    catch { throw new Error(`JSON parse fail (Delete): ${raw.slice(0, 300)}`); }

    if (!j.ok) throw new Error(j.error || 'delete failed');
  } catch (e) {
    console.error('attachments_api.php delete → Fehler:', e);
    throw e;
  }
}

// nach relevanten Stellen aufrufen:
document.addEventListener("DOMContentLoaded", () => { refreshAllAttachmentBadges().catch(()=>{}); });
// und am Ende von regroupGroups(), applyFilter(), saveRow(), btnAdd-Handler nach dem Einfügen:
(async ()=>{ try { await refreshAllAttachmentBadges(); } catch(e){} })();

const attModalEl = document.getElementById("attModal");
if (attModalEl) {
  attModalEl.addEventListener("hidden.bs.modal", () => {
    const inp = document.getElementById("attFileInput");
    if (inp) inp.value = "";
    clearPreview();
  });
}
 /* ====================== AUTOCOMPLETE+ (Spedition, Behälter, Sachnummer) ======================
   - ↑/↓ Navigation, PageUp/PageDown (±10)
   - Enter / ArrowRight / Tab übernehmen; Esc schließt
   - Tab: übernimmt + springt weiter; Shift+Tab: übernimmt + springt zurück
   - Auto-Open bei Fokus; Highlight der Treffer
   - Stammdaten-Caching (localStorage, TTL 24h)
   - Pick feuert 'input' -> triggert deine LG-Logik
   =============================================================================== */
(() => {
  const API       = '/api/stammdaten_api.php';
  const COL_SPED  = 6, COL_BEH = 12, COL_SACH = 13;
  const TTL_MS    = 24 * 60 * 60 * 1000; // 24h Cache
  const MAX_ROWS  = 50;
  const COL_KENNZ = 4; // Kennzeichen (Spalte 5)

  let SPEDS_AC = [];   // [{name, norm}]
  let BEHS_AC  = [];   // [{nummer, lagergruppe?, norm}]
  let PARTS_AC = [];   // [{sachnummer, lagergruppe, norm}]
  let PLATES_AC = []; // [{kennzeichen, land?, norm}]
  let _loaded  = false;

  // ---------- Utils ----------
  const normalize = s => String(s||'').toLowerCase().replace(/\s+/g,'');
  const lower     = s => String(s||'').toLowerCase();
  const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[m]));

  function isSubsequence(needle, hay) {
    let i=0,j=0; while(i<needle.length && j<hay.length){ if(needle[i]===hay[j++]) i++; } return i===needle.length;
  }
  function scoreText(valNorm, qNorm, valRawLower, qRawLower) {
    if (!qNorm) return 0;
    let s = 0;
    if (valNorm.startsWith(qNorm)) s += 3;
    else if (valNorm.includes(qNorm)) s += 2;
    if (isSubsequence(qNorm, valNorm)) s += 1;
    if (qRawLower && valRawLower.includes(qRawLower)) s += 1;
    return s;
  }
  function highlight(text, tokens) {
    let out = escapeHtml(text);
    tokens.filter(Boolean).forEach(tok => {
      const re = new RegExp(`(${tok.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'ig');
      out = out.replace(re, '<mark>$1</mark>');
    });
    return out;
  }

  // Fokus zum nächsten/vorherigen Editor (input/select) in derselben Zeile
 function focusSiblingEditor(input, dir = 1) {
  if (!input || !input.closest) return;           // <— Guard
  const td = input.closest('td');
  const tr = input.closest('tr');
  if (!td || !tr) return;

  const lastIndex = Math.min(tr.children.length - 1, 16); // 0..16 = Datenzellen
  let idx = td.cellIndex + dir;
  while (idx >= 0 && idx <= lastIndex) {
    const el = tr.children[idx]?.querySelector?.('input,select');
    if (el && !el.disabled) { el.focus(); el.select?.(); return; }
    idx += dir;
  }
}


  // ---------- Cache ----------
  function cacheKey(type){ return `stmd_v1_${type}`; }
  function readCache(type) {
    try {
      const raw = localStorage.getItem(cacheKey(type));
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || !obj.ts || !Array.isArray(obj.items)) return null;
      if (Date.now() - obj.ts > TTL_MS) return null;
      return obj.items;
    } catch { return null; }
  }
  function writeCache(type, items) {
    try { localStorage.setItem(cacheKey(type), JSON.stringify({ ts: Date.now(), items })); } catch {}
  }
  async function fetchList(url, mapFn) {
    const r = await fetch(url, { credentials:'same-origin' });
    const j = await r.json();
    if (!j.ok) return [];
    return (j.items || []).map(mapFn).filter(Boolean);
  }
  async function loadSpeds() {
    const c = readCache('spedition'); if (c) return c;
    const items = await fetchList(`${API}?type=spedition&action=list`, it => {
      const name = (it.name || '').trim(); if (!name) return null;
      return { name, norm: lower(name) };
    });
    writeCache('spedition', items); return items;
  }
  async function loadBehs() {
    const c = readCache('behaelter'); if (c) return c;
    const items = await fetchList(`${API}?type=behaelter&action=list`, it => {
      const nummer = (it.nummer || '').trim(); if (!nummer) return null;
      const lg = (it.lagergruppe || '').trim(); // optional
      return { nummer, lagergruppe: lg, norm: normalize(nummer) };
    });
    writeCache('behaelter', items); return items;
  }
  async function loadPlates() {
  const c = readCache('kennzeichen'); if (c) return c;

  // Primär: Stammdaten-API (falls vorhanden)
  try {
    const items = await fetchList(`${API}?type=kennzeichen&action=list`, it => {
      const k = (it.kennzeichen || it.plate || '').trim();
      if (!k) return null;
      const land = (it.land || it.country || '').trim(); // optional
      return { kennzeichen: k, land, norm: normalizeCode(k) };
    });
    if (items.length) { writeCache('kennzeichen', items); return items; }
  } catch(e) { /* weich fallen */ }

  // Fallback: aus vorhandener Tabelle einsammeln
  try {
    const seen = new Map();
    document.querySelectorAll('#eingangTable tbody tr').forEach(tr => {
      const k = (tr.children?.[COL_KENNZ]?.dataset?.raw || tr.children?.[COL_KENNZ]?.textContent || '').trim();
      if (!k) return;
      const land = (tr.children?.[5]?.dataset?.raw || tr.children?.[5]?.textContent || '').trim();
      const norm = normalizeCode(k);
      if (!seen.has(norm)) seen.set(norm, { kennzeichen: k, land, norm });
    });
    const arr = [...seen.values()];
    writeCache('kennzeichen', arr);
    return arr;
  } catch { return []; }
}

  async function loadParts() {
    const c = readCache('sachnummer'); if (c) return c;
    const items = await fetchList(`${API}?type=sachnummer&action=list`, it => {
      const sn = (it.sachnummer || '').trim(); if (!sn) return null;
      const lg = (it.lagergruppe || '').trim();
      return { sachnummer: sn, lagergruppe: lg, norm: normalize(sn) };
    });
    writeCache('sachnummer', items); return items;
  }
  async function ensureDataLoaded() {
  if (_loaded) return;

  // Cache-Vorbelegung (inkl. Kennzeichen)
  [SPEDS_AC, BEHS_AC, PARTS_AC, PLATES_AC] = [
    readCache('spedition') || [],
    readCache('behaelter') || [],
    readCache('sachnummer') || [],
    readCache('kennzeichen') || []   // << neu
  ];

  _loaded = true;

  // Frisch nachladen (weich fallend)
  try { SPEDS_AC  = await loadSpeds();  } catch(e){ console.warn('Speds load fail', e); }
  try { BEHS_AC   = await loadBehs();   } catch(e){ console.warn('Behs load fail', e); }
  try { PARTS_AC  = await loadParts();  } catch(e){ console.warn('Parts load fail', e); }
  try { PLATES_AC = await loadPlates(); } catch(e){ console.warn('Plates load fail', e); } // << neu
}



  // ---------- Globales Menü ----------
  let menu = document.querySelector('#ac-global-menu');
  if (!menu) {
    menu = document.createElement('div');
    menu.id = 'ac-global-menu';
    menu.className = 'ac-menu d-none';
    Object.assign(menu.style, { position:'absolute' });
    document.body.appendChild(menu);
  }
  const isOpen = () => !menu.classList.contains('d-none');
  let activeIndex = -1;
  let currentItems = [];
  let currentPickFn = null;
  let currentInput = null;

  function openMenuFor(input, items, renderFn, pickFn) {
    if (!items.length) return closeMenu();
    currentItems = items.slice(0, MAX_ROWS);
    currentPickFn = pickFn;
    currentInput  = input;

    menu.innerHTML = '';
    currentItems.forEach((it, idx) => {
      const row = document.createElement('div');
      row.className = 'ac-item';
      row.innerHTML = renderFn(it);
      row.addEventListener('mouseenter', () => setActive(idx));
      // Mausklick: übernehmen, aber NICHT springen (nur Keyboard springt)
      row.addEventListener('mousedown', (e) => { e.preventDefault(); pick(idx, { move:false }); });
      menu.appendChild(row);
    });

    const r = input.getBoundingClientRect();
    menu.style.left  = (r.left + window.scrollX) + 'px';
    menu.style.top   = (r.bottom + window.scrollY) + 'px';
    menu.style.width = r.width + 'px';
    menu.classList.remove('d-none');
    setActive(0);
  }
  function setActive(idx) {
    const nodes = [...menu.querySelectorAll('.ac-item')];
    nodes.forEach(n => n.classList.remove('active'));
    activeIndex = idx;
    if (nodes[idx]) {
      nodes[idx].classList.add('active');
      nodes[idx].scrollIntoView({ block:'nearest' });
    }
  }
  function closeMenu() {
    if (isOpen()) menu.classList.add('d-none');
    menu.innerHTML = '';
    currentItems = [];
    currentPickFn = null;
    currentInput = null;
    activeIndex = -1;
  }
  function pick(idx, { move = false, dir = 1 } = {}) {
  const it = currentItems[idx];
  // lokale Kopien anlegen, bevor closeMenu() sie nullt
  const inputRef  = currentInput;
  const pickFnRef = currentPickFn;

  if (!it || !pickFnRef || !inputRef) return;

  // Wert setzen
  pickFnRef(it);
  // LG-Logik etc. triggern
  inputRef.dispatchEvent(new Event('input', { bubbles: true }));

  // Menü schließen (nullt currentInput)
  closeMenu();

  // Danach sicher zum Nachbarfeld springen
  if (move) focusSiblingEditor(inputRef, dir);
}

  // ---------- Core: attachAutocomplete ----------
  function attachAutocomplete(input, options) {
    const { listFn, renderFn, pickFn, valueForScore } = options;

    async function runQuery(q) {
      await ensureDataLoaded();
      const list = listFn();
      const qNorm = normalize(q);
      const qRaw  = lower(q);
      const scored = list.map(it => {
        const val = valueForScore(it);
        return { it, s: scoreText(val.norm, qNorm, val.rawLower, qRaw), pos: val.norm.indexOf(qNorm) };
      }).filter(x => q ? x.s > 0 : true);

      scored.sort((a,b) => {
        if (b.s !== a.s) return b.s - a.s;
        if (a.pos !== b.pos) return a.pos - b.pos;
        const av = valueForScore(a.it).text;
        const bv = valueForScore(b.it).text;
        return av.localeCompare(bv, 'de');
      });
      return scored.map(x => x.it).slice(0, MAX_ROWS);
    }

    input.addEventListener('focus', async () => {
      const q = (input.value || '').trim();
      const items = q ? await runQuery(q) : (await ensureDataLoaded(), listFn().slice(0, MAX_ROWS));
      if (!items.length) return closeMenu();
      openMenuFor(input, items, it => {
        const tokens = q.toLowerCase().split(/\s+/).filter(Boolean);
        return renderFn(it, tokens);
      }, pickFn);
    });
    // Schließt das Menü, wenn das Feld den Fokus verliert (ohne Auswahl)
input.addEventListener('blur', () => {
  // Ein Tick warten, damit ein evtl. mousedown auf dem Menü zuerst feuert
  setTimeout(() => {
    const ae = document.activeElement;
    // Nur schließen, wenn das Menü noch zu diesem Input gehört
    if (currentInput === input && (!ae || !menu.contains(ae))) {
      closeMenu();
    }
  }, 0);
});


    input.addEventListener('input', async () => {
      const q = (input.value || '').trim();
      if (!q) { closeMenu(); return; }
      const items = await runQuery(q);
      if (!items.length) { closeMenu(); return; }
      openMenuFor(input, items, it => {
        const tokens = q.toLowerCase().split(/\s+/).filter(Boolean);
        return renderFn(it, tokens);
      }, pickFn);
    });

    input.addEventListener('keydown', (e) => {
      // Menü auf mit ↓
      if (!isOpen()) {
        if (e.key === 'ArrowDown') { e.preventDefault(); input.dispatchEvent(new Event('focus')); }
        return;
      }
      const max = menu.querySelectorAll('.ac-item').length || 0;
      if (!max) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault(); setActive((activeIndex + 1) % max);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); setActive((activeIndex - 1 + max) % max);
      } else if (e.key === 'PageDown') {
        e.preventDefault(); setActive(Math.min(max - 1, activeIndex + 10));
      } else if (e.key === 'PageUp') {
        e.preventDefault(); setActive(Math.max(0, activeIndex - 10));
      } else if (e.key === 'Enter' || e.key === 'ArrowRight') {
        if (activeIndex >= 0) { e.preventDefault(); pick(activeIndex, { move:true, dir: 1 }); }
      } else if (e.key === 'Tab') {
        if (activeIndex < 0) closeMenu();
        if (activeIndex >= 0) {
          e.preventDefault(); // wir übernehmen + springen, statt Browser-Tab
          const dir = e.shiftKey ? -1 : 1;
          pick(activeIndex, { move:true, dir });
        }
        // Wenn kein aktiver Eintrag oder Menü zu, lassen wir Tab normal laufen
      } else if (e.key === 'Escape') {
        e.preventDefault(); closeMenu();
      }
    });

    // Klick außerhalb -> schließen
    document.addEventListener('click', (ev) => {
      if (ev.target !== input && !menu.contains(ev.target)) closeMenu();
    });
  }

  // ---------- Spezialisierungen ----------
  function attachSpeditionAC(input) {
    attachAutocomplete(input, {
      listFn: () => SPEDS_AC,
      valueForScore: (it) => ({ norm: it.norm, rawLower: it.norm, text: it.name }),
      renderFn: (it, tokens) => `<div class="label">${highlight(it.name, tokens)}</div>`,
      pickFn:  (it) => { input.value = it.name; }
    });
  }
function attachKennzeichenAC(input) {
  attachAutocomplete(input, {
    listFn: () => PLATES_AC,
    valueForScore: (it) => ({
      norm: it.norm,
      rawLower: String(it.kennzeichen||'').toLowerCase(),
      text: it.kennzeichen
    }),
    renderFn: (it, tokens) => `
      <div class="label"><span class="ac-code">${highlight(it.kennzeichen, tokens)}</span></div>
      <div class="meta">${escapeHtml(it.land || '')}</div>`,
    pickFn:  (it) => {
      input.value = it.kennzeichen;
      applyPlateLand(input, it.land);
    }
  });

  // NEU: auch ohne Menüauswahl Land setzen (exakter Treffer)
  async function syncLandFromTyped() {
    try { await ensureDataLoaded(); } catch {}
    const norm = normalizeCode(input.value || '');
    if (!norm) { applyPlateLand(input, ''); return; }
    const hit = Array.isArray(PLATES_AC) ? PLATES_AC.find(p => p.norm === norm) : null;
    applyPlateLand(input, hit?.land || '');
  }

  input.addEventListener('input', () => { syncLandFromTyped(); });
  input.addEventListener('blur',  () => { syncLandFromTyped(); });
}

// Helfer: Land (Spalte 6 / Index 5) in der aktuellen Zeile setzen
function applyPlateLand(input, land) {
  const tr = input.closest('tr');
  if (!tr) return;
  const val = (land || '').toUpperCase().slice(0,2);
  const td  = tr.children[5];
  const inp = td?.querySelector('input');

  if (inp) inp.value = val;
  else if (td) { td.dataset.raw = val; td.textContent = val; }
}


  
  // Helper: setzt die Lagergruppe in Spalte 2 – klappt in Edit (Plaintext-Span) und View
function setLGCell(tr, val) {
  const td = tr?.children?.[2];
  if (!td) return;
  const span = td.querySelector('.form-control-plaintext');
  if (span) {
    // Edit-Modus: nur das sichtbare Plaintext-Element setzen
    span.textContent = val || '';
  } else {
    // View-Modus: normaler Text + data-raw (für CSV/Export)
    td.dataset.raw = val || '';
    td.textContent = val || '';
  }
}


function attachBehaelterAC(input) {
  // 1) Menü + Auswahl
  attachAutocomplete(input, {
    listFn: () => BEHS_AC,
    valueForScore: (it) => ({ norm: it.norm, rawLower: it.norm, text: it.nummer }),
    renderFn: (it, tokens) => `
      <div class="label"><span class="ac-code">${highlight(it.nummer, tokens)}</span></div>
      <div class="meta">${escapeHtml(it.lagergruppe || '')}</div>`,
    pickFn: (it) => {
      input.value = it.nummer;
      const tr = input.closest('tr');
      if (tr) setLGCell(tr, it.lagergruppe || '');
    }
  });

  // 2) Beim Tippen/Verlassen: exakter Treffer -> LG setzen (ohne Menü-Pick)
  async function syncLGFromTyped() {
    const tr = input.closest('tr'); if (!tr) return;

    try { await ensureDataLoaded(); } catch {}

    const norm = String(input.value || '').toLowerCase().replace(/\s+/g, '');
    if (!norm) { setLGCell(tr, ''); return; }

    const hit = Array.isArray(BEHS_AC) ? BEHS_AC.find(b => b.norm === norm) : null;
    setLGCell(tr, hit ? (hit.lagergruppe || '') : '');
  }

  input.addEventListener('input',  () => { syncLGFromTyped(); });
  input.addEventListener('blur',   () => { syncLGFromTyped(); });
}


// (optional global, falls anderswo benötigt)
window.attachBehaelterAC = attachBehaelterAC;


  function attachSachnummerAC(input) {
    attachAutocomplete(input, {
      listFn: () => PARTS_AC,
      valueForScore: (it) => ({ norm: it.norm, rawLower: it.norm, text: it.sachnummer }),
      renderFn: (it, tokens) => `
        <div class="label"><span class="ac-code">${highlight(it.sachnummer, tokens)}</span></div>
        <div class="meta">${escapeHtml(it.lagergruppe || '')}</div>`,
      pickFn:  (it) => { input.value = it.sachnummer; }
    });
  }

 function bindRowAC(tr) {
  const spedInp = tr.children[COL_SPED]?.querySelector('input');
  const behInp  = tr.children[COL_BEH ]?.querySelector('input');
  const sachInp = tr.children[COL_SACH]?.querySelector('input');
  const plateInp= tr.children[COL_KENNZ]?.querySelector('input'); // <— NEU

  if (spedInp && !spedInp.dataset._acSped) { spedInp.dataset._acSped = '1'; attachSpeditionAC(spedInp); }
  if (behInp  && !behInp.dataset._acBeh ) { behInp.dataset._acBeh  = '1'; attachBehaelterAC(behInp);  }
  if (sachInp && !sachInp.dataset._acSach){ sachInp.dataset._acSach= '1'; attachSachnummerAC(sachInp);}
  if (plateInp&& !plateInp.dataset._acPlate){ plateInp.dataset._acPlate='1'; attachKennzeichenAC(plateInp);} // <— NEU
}

window.__bindRowAC = bindRowAC;



 

  // vorher (überschattet):
// const tbody = document.getElementById('eingangTable')?.querySelector('tbody');

// nachher:
const tbodyAC = document.getElementById('eingangTable')?.querySelector('tbody');
if (!tbodyAC) return;

tbodyAC.addEventListener('click', (e) => {
  const btn = e.target.closest('button.btn-outline-secondary');
  if (!btn) return;
  setTimeout(() => {
    const tr = btn.closest('tr');
    if (tr && tr.dataset.mode === 'edit') bindRowAC(tr);
  }, 0);
});
const mo = new MutationObserver((muts) => {
  muts.forEach(m => m.addedNodes.forEach(n => {
    if (n.nodeType === 1 && n.matches('tr')) {
   // ein Tick warten, bis setRowMode durch ist
   setTimeout(() => bindRowAC(n), 0);
 }
  }));
});
mo.observe(tbodyAC, { childList: true, subtree: true });

[...tbodyAC.querySelectorAll('tr')].forEach(tr => { if (tr.dataset.mode === 'edit') bindRowAC(tr); });

})();

// ===== Shortcuts & Auto-Save (Zeile) =====
function getFocusedEditRow() {
  const ae = document.activeElement;
  const tr = ae?.closest?.('tr');
  return (tr && tr.dataset.mode === 'edit') ? tr : null;
}


async function upsertRowToServer(tr) {
  // Werte einsammeln
  const payload = {
    id:        tr.dataset.dbid ? Number(tr.dataset.dbid) : null,
    eingang_nr:   getCellValue(tr,0),
    lieferschein: getCellValue(tr,1),
    lagergruppe:  getCellValue(tr,2),
    datum:        getCellValue(tr,3) || null,
    kennzeichen:  getCellValue(tr,4),
    land:         getCellValue(tr,5),
    spedition:    getCellValue(tr,6),
    ankunft:      getCellValue(tr,7) || null,
    beginn:       getCellValue(tr,8) || null,
    ende:         getCellValue(tr,9) || null,
    behaelter:    Number(getCellValue(tr,10))||0,
    zus_behaelter:Number(getCellValue(tr,11))||0,
    behaelternr:  getCellValue(tr,12),
    sachnummer:   getCellValue(tr,13),
    menge:        Number(getCellValue(tr,14))||0,
    gebucht:      getCellValue(tr,15),
    gebucht_von:  getCellValue(tr,16),
  };

  const res = await fetch(`${API_WE}?action=upsert`, {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    credentials:'same-origin',
    body: JSON.stringify(payload)
  });
  const j = await res.json();
  if (!j.ok) throw new Error(j.error || 'save failed');
  if (j.row && j.row.id) tr.dataset.dbid = j.row.id; // DB-ID merken
}

function isVisibleRow(tr){ return !!(tr && tr.offsetParent !== null); }

async function commitRow(tr) {
  if (!tr || tr.dataset.mode !== 'edit') return;
  saveRow(tr);

  try {
    await upsertRowToServer(tr);
    tr.dataset.saved = '1';
    setRowMode(tr, 'view');
    const btn = tr.querySelector('button.btn-outline-secondary');
    if (btn) { btn.innerHTML = '<i class="bi bi-pencil"></i>'; btn.title = 'Bearbeiten'; }

    // UI aktualisieren
    regroupGroups(); rebuildFilterOptions(); applyFilter(); computeStats?.();

    // --- Auto-Fokus für Duplikats-Workflow (nur bei Erfolg) ---
    if (tr.dataset.isDup === '1') {
      setTimeout(() => {
        const eing = getCellValue(tr, 0).trim();

        // nächste Zeile gleicher Eing.-Nr. mit LEEREM Lieferschein und SICHTBAR
        let next = tr.nextElementSibling;
        while (
          next &&
          getCellValue(next,0).trim() === eing &&
          (getCellValue(next,1).trim() !== '' || !isVisibleRow(next))
        ) {
          next = next.nextElementSibling;
        }

        if (next && getCellValue(next,0).trim() === eing && isVisibleRow(next)) {
          if (next.dataset.mode !== 'edit') {
            editRow(next);
            next.dataset.saved = '0';
            setRowMode(next, 'edit');
            const eb = next.querySelector('button.btn-outline-secondary');
            if (eb) { eb.innerHTML = '<i class="bi bi-check2"></i>'; eb.title = 'Speichern'; }
          }
          // Fokus in Fracht/Lieferschein (Spalte 1)
          focusEditor(next, 1);
        } else {
          // keine passende Folgereihe -> neue leere Zeile direkt anlegen
          const clone = duplicateRow(tr, { markAsDup: false });
          focusEditor(clone, 1);
        }
      }, 0);
      delete tr.dataset.isDup;
    }
  } catch (e) {
    alert('Speichern am Server fehlgeschlagen: ' + e.message);
    // optional: tr.dataset.saved = '0';
  }
}


function _menuEl(){ return document.getElementById('ac-global-menu'); }
// Ctrl/Meta + S -> aktuelle Edit-Zeile speichern
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
    const tr = getFocusedEditRow();
    if (tr) { e.preventDefault(); commitRow(tr); }
  }
});


tbody.addEventListener('focusin', (e) => {
  const tr = e.target.closest('tr');
  if (tr?.dataset.mode === 'edit') _rowWithFocus = tr;
});

tbody.addEventListener('focusout', () => {
  const tr = _rowWithFocus;
  if (!tr) return;
  setTimeout(() => {
    const ae = document.activeElement;
    const leftRow = !ae || !tr.contains(ae);
    const inMenu  = _menuEl()?.contains?.(ae);
    if (leftRow && !inMenu) {
      commitRow(tr);
      _rowWithFocus = null;
    }
  }, 0);
});

// Höhe der Filterleiste als Offset für sticky thead
function updateStickyOffset(){
  const fb = document.getElementById('filterBar');
  const h  = fb ? fb.offsetHeight : 0;
  document.documentElement.style.setProperty('--sticky-offset', h + 'px');
}
window.addEventListener('load',  updateStickyOffset);
window.addEventListener('resize',updateStickyOffset);


function normalizeCode(s){
  return String(s||'').toLowerCase().replace(/[\s\-_.\/]/g,'');
}

function computeLGForRow(tr) {
  // 1) Sachnummer → LG (Priorität)
  const sach = (getCellValue(tr, 13) || '').trim();
  if (sach) {
    const hit = PARTS.find(p => p.norm === (sach.toLowerCase().replace(/\s+/g,'')));
    if (hit && hit.group) return hit.group;
  }
  // 2) Behälter-Nr. → LG (Fallback)
  const behnr = (getCellValue(tr, 12) || '').trim();
  if (behnr) {
    const h2 = BEHS.find(b => b.norm === normalizeCode(behnr));
    if (h2 && h2.group) return h2.group;
  }
  // 3) Nichts gefunden
  return '';
}

function applyLGForRow(tr) {
  const lg = computeLGForRow(tr);
  const td = tr.children[2];
  if (!td) return;
  if (tr.dataset.mode === 'edit') {
    td.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(lg)}</span>`;
  } else {
    setCellRaw(td, lg);
  }
}

function recomputeLGAllRows() {
  [...tbody.querySelectorAll('tr')].forEach(applyLGForRow);
}

function forceSortByEingangAsc() {
  const idx = 0; // Eing.-Nr.
  const type = 'number';
  const rows = [...tbody.querySelectorAll('tr')];

  rows.sort((a, b) => {
    const va = parseByType(getCellValue(a, idx), type);
    const vb = parseByType(getCellValue(b, idx), type);
    if (va < vb) return -1;
    if (va > vb) return  1;
    return 0; // gleiche Eing.-Nr.: Reihenfolge bleibt unverändert
  });

  rows.forEach(r => tbody.appendChild(r));

  // (optional) Sort-Indikator im Header aktualisieren
  const th = table.querySelector('thead th:nth-child(1)');
  if (th) {
    table.querySelectorAll('thead th').forEach(h => h.classList.remove('table-active'));
    th.classList.add('table-active');
    th.querySelector('.sort-ind')?.remove();
    const ind = document.createElement('span');
    ind.className = 'sort-ind';
    ind.textContent = '↑';
    th.appendChild(ind);
  }
}

function scoreHeaderRow(tr) {
  // zählt „Kopf-Felder“: Datum, Kennz., Land, Sped., Ankunft, Beginn, Ende
  const cols = [3,4,5,6,7,8,9];
  return cols.reduce((s,i)=> s + (getCellValue(tr,i).trim()?1:0), 0);
}

function pinHeaderFirstPerGroup() {
  const rows = [...tbody.querySelectorAll('tr')];
  let i = 0;
  while (i < rows.length) {
    const key = (getCellValue(rows[i],0) || '').trim().toLowerCase();
    let j = i + 1;
    while (j < rows.length && (getCellValue(rows[j],0) || '').trim().toLowerCase() === key) j++;

    if (key) {
      const group = rows.slice(i, j);
      const best  = group.reduce((a,b) => (scoreHeaderRow(a) >= scoreHeaderRow(b) ? a : b));
      if (best !== group[0]) tbody.insertBefore(best, group[0]);
    }
    i = j;
  }
}
function buildDateByAusgang(rows) {
  const map = new Map();
  rows.forEach(tr => {
    const nr = (getCellValue(tr,0) || '').trim();
    const dt = parseISODate(getCellValue(tr,3));
    if (nr && dt && !map.has(nr)) map.set(nr, dt); // erstes bekanntes Datum merken
  });
  return map;
}
function rowDateOrGroupDate(tr, nrDateMap) {
  const own = parseISODate(getCellValue(tr,3));
  if (own) return own;
  const nr = (getCellValue(tr,0) || '').trim();
  return nr ? (nrDateMap.get(nr) || null) : null;
}


})();
// ======================================================
// Monatsfilter Wareneingang (Accordion + Filter)
// ======================================================
(function () {
  const DATE_COL_IDX    = 3; // Spalte "Datum" (0-basiert)
  const EINGANG_COL_IDX = 0; // Spalte "Eing.-Nr." (0-basiert)
  let activeYm = null;       // aktuell ausgewählter Monat, z.B. "2025-11"

  function parseDateString(raw) {
    raw = (raw || "").trim();
    if (!raw) return null;

    let d = null;

    // ISO: 2025-11-30
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      d = new Date(raw + "T00:00:00");
    }
    // DE: 30.11.2025
    else if (/^\d{2}\.\d{2}\.\d{4}$/.test(raw)) {
      const [dd, mm, yyyy] = raw.split(".");
      d = new Date(`${yyyy}-${mm}-${dd}T00:00:00`);
    }
    // DE kurz: 30.11.25  -> 2025-11-30
    else if (/^\d{2}\.\d{2}\.\d{2}$/.test(raw)) {
      const [dd, mm, yy] = raw.split(".");
      const year = 2000 + Number(yy); // 25 -> 2025
      d = new Date(`${year}-${mm}-${dd}T00:00:00`);
    }
    // Fallback: Date versucht selbst zu parsen
    else {
      d = new Date(raw);
    }

    if (!d || Number.isNaN(d.getTime())) return null;
    return d;
  }

    function applyMonthFilter(tbody, ym) {
    activeYm = ym;
    // global merken, damit die zentrale Filterfunktion das nutzen kann
    window.WE_activeMonth = ym;

    // Wenn es eine zentrale Filterfunktion gibt → die benutzen
    if (typeof window.WE_applyFilters === "function") {
      window.WE_applyFilters();
      return;
    }

    // Fallback (falls keine zentrale Funktion existiert)
    const rows = Array.from(tbody.rows);
    rows.forEach(tr => {
      if (!ym) {
        tr.style.display = "";
        return;
      }
      const rowMonth = tr.dataset.weMonth || "";
      tr.style.display = (rowMonth === ym) ? "" : "none";
    });
  }


  function rebuildMonthAccordion() {
    const table     = document.getElementById("eingangTable");
    const accordion = document.getElementById("weMonthAccordion");
    if (!table || !accordion) return;

    const tbody = table.tBodies[0];
    if (!tbody) return;

    const rows = Array.from(tbody.rows);
    const monthsMap = new Map(); // key "YYYY-MM" -> { label, count, set }
    let anyRow = false;

    rows.forEach(tr => {
      anyRow = true;

      const tdDate = tr.children[DATE_COL_IDX];
      if (!tdDate) return;

      const rawDate = (tdDate.dataset.raw || tdDate.textContent || "").trim();
      const d = parseDateString(rawDate);
      if (!d) return;

      const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
      tr.dataset.weMonth = ym;

      // Eingangsnummer holen (für "unique")
      const tdEing = tr.children[EINGANG_COL_IDX];
      const eingNr = (tdEing?.dataset.raw || tdEing?.textContent || "").trim();
      if (!eingNr) return;

      if (!monthsMap.has(ym)) {
        const label = d.toLocaleDateString("de-DE", { month: "long", year: "numeric" });
        monthsMap.set(ym, { label, set: new Set(), count: 0 });
      }

      const info = monthsMap.get(ym);
      info.set.add(eingNr);       // unique Eingangsnummern sammeln
      info.count = info.set.size; // Anzeige-Zahl = Anzahl unterschiedlicher Eingangsnummern
    });

    // Noch gar keine Zeilen -> Hinweis, aber nichts filtern
    if (!anyRow) {
      accordion.innerHTML = `
        <div class="alert alert-light mb-0 small">
          Noch keine Wareneingänge vorhanden – Monatsfilter wird aktiv, sobald Daten da sind.
        </div>`;
      return;
    }

    // Zeilen vorhanden, aber kein Datum lesbar
    if (!monthsMap.size) {
      accordion.innerHTML = `
        <div class="alert alert-warning mb-0 small">
          Es konnten keine gültigen Datumsangaben aus der Spalte „Datum“ gelesen werden.
          Bitte Ausgabeformat oder <code>data-raw</code> prüfen.
        </div>`;
      return;
    }

    // Monate nach Neuigkeit sortieren (neuester zuerst)
    const months = Array.from(monthsMap.entries()).sort((a, b) => b[0].localeCompare(a[0]));

    // Aktiven Monat bestimmen (beibehalten, wenn noch vorhanden)
    if (!activeYm || !monthsMap.has(activeYm)) {
      activeYm = months[0][0]; // neuester Monat
    }

    let html = "";
    months.forEach(([ym, info]) => {
      const collapseId = `weMonthCollapse_${ym}`;
      const headingId  = `weMonthHeading_${ym}`;
      const isActive   = ym === activeYm;

      html += `
        <div class="accordion-item">
          <h2 class="accordion-header" id="${headingId}">
            <button class="accordion-button ${isActive ? "" : "collapsed"} py-1"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#${collapseId}"
                    aria-expanded="${isActive ? "true" : "false"}"
                    aria-controls="${collapseId}"
                    data-we-month="${ym}">
              <div class="d-flex justify-content-between w-100">
                <span>${info.label}</span>
                <span class="badge bg-secondary ms-2">${info.count}</span>
              </div>
            </button>
          </h2>
          <div id="${collapseId}"
               class="accordion-collapse collapse ${isActive ? "show" : ""}"
               aria-labelledby="${headingId}"
               data-bs-parent="#weMonthAccordion">
            <div class="accordion-body py-2">
              <small class="text-muted">
                Zeigt alle Wareneingangs-Zeilen aus dem Monat <strong>${info.label}</strong>.
                (${info.count} unterschiedliche Eingangsnummern)
              </small>
            </div>
          </div>
        </div>`;
    });

    accordion.innerHTML = html;

    // Filter nach dem aktiven Monat anwenden
    applyMonthFilter(tbody, activeYm);
  }

  document.addEventListener("DOMContentLoaded", () => {
    const table     = document.getElementById("eingangTable");
    const accordion = document.getElementById("weMonthAccordion");
    if (!table || !accordion) return;

    const tbody = table.tBodies[0];
    if (!tbody) return;

    // 1) Initialer Build (falls Zeilen serverseitig gerendert)
    rebuildMonthAccordion();

    // 2) Beobachter: wenn wareneingang.js später Zeilen hinzufügt/ändert
    const mo = new MutationObserver(() => {
      if (tbody._weMonthDebounce) return;
      tbody._weMonthDebounce = true;
      setTimeout(() => {
        tbody._weMonthDebounce = false;
        rebuildMonthAccordion();
      }, 50);
    });
    mo.observe(tbody, { childList: true });

    // 3) Klick auf Accordion-Buttons -> aktiven Monat setzen + filtern
    accordion.addEventListener("click", (ev) => {
      const btn = ev.target.closest("button[data-we-month]");
      if (!btn) return;

      const ym = btn.getAttribute("data-we-month") || "";
      activeYm = ym;
      // ✅ immer nach dem wirklich aktiven Monat filtern
applyMonthFilter(tbody, activeYm);

    });
  });
})();
