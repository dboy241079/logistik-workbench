// warenausgang.js
(function () {
  const TOP_LIMIT = 10;
  const statsRangeEl = document.getElementById("statsRange");

  const table = document.getElementById("ausgangTable");
  const tbody = table.querySelector("tbody");
  const btnAdd = document.getElementById("btnAddRow");
  const filterSelect = document.getElementById("filterNumber");
  const searchInput = document.getElementById("searchInput");
  const searchAllCols = document.getElementById("searchAllCols");
  const btnResetFilter = document.getElementById("btnResetFilter");
  const btnWeeklyExport = document.getElementById("btnWeeklyExport");
  const TRUCK_PALLET_LIMIT = 52; // LKW-Deckel für Paletten
  const MAX_TRUCK_KG = 21000;
  const COL_SACH = 10;
  const COL_BEH  = 11;
  const COL_KG   = 14;

const API_WE = '/api/warenausgang_api.php';
const API_ATT = '/api/attachments_api.php';

// Mappe DB-Row -> Tabellen-Zeile
function renderRowFromDB(row) {
  const tr = document.createElement('tr');

  const cells = [
    row.ausgang_nr, row.lieferschein, row.lagergruppe,
    row.datum || '', row.kennzeichen, row.land, row.spedition,
    row.ankunft || '', row.beginn || '', row.ende || '',
    row.sachnummer,
    row.behaelter,
    row.behaelternr,
    row.zus_behaelter,
    row.brt_gew,
    row.gebucht,
    row.gebucht_von
  ];

  for (let i = 0; i < cells.length; i++) {
    const td = document.createElement('td');
    td.dataset.raw = (cells[i] ?? '').toString();
    td.textContent = td.dataset.raw;
    tr.appendChild(td);
  }

  appendCmrCols(tr);

  const tdAction = document.createElement('td');
  tdAction.appendChild(makeActionGroup(false));
  tr.appendChild(tdAction);

  tr.dataset.saved = '1';
  tr.dataset.mode  = 'view';
  tr.dataset.dbid  = row.id;

  tbody.appendChild(tr);
}

tbody.addEventListener("dblclick", (e) => {
  const tr = e.target.closest("tr");
  const td = e.target.closest("td");

  if (!tr || !td) return;
  if (e.target.closest("button,a,input,select,textarea,label")) return;

  const colIdx = td.cellIndex;
  const lastIdx = tr.children.length - 1;

  // Aktionsspalte ignorieren
  if (colIdx === lastIdx) return;

  enterEditMode(tr, colIdx);
});

function appendCmrCols(tr) {
  const actionTd = tr.lastElementChild; // falls Action schon da ist
  const tdCode = document.createElement('td');
  tdCode.dataset.col = 'cmr_code';
  tdCode.dataset.raw = '';
  tdCode.textContent = '';

  const tdName = document.createElement('td');
  tdName.dataset.col = 'cmr_name';
  tdName.dataset.raw = '';
  tdName.textContent = '';

  if (actionTd && actionTd.querySelector?.('button')) {
    tr.insertBefore(tdName, actionTd);
    tr.insertBefore(tdCode, tdName);
  } else {
    tr.appendChild(tdCode);
    tr.appendChild(tdName);
  }
}

function kgPerPalletForSach(sach){
  const key = normalizeCode(sach);
  const hit = PARTS.find(p => p.norm === key);
  return hit ? (Number(hit.kg) || 0) : 0;
}

function bindAutoKgForRow(tr){
  const sachInp = tr.children[COL_SACH]?.querySelector('input');
  const behInp  = tr.children[COL_BEH ]?.querySelector('input');
  const kgInp   = tr.children[COL_KG  ]?.querySelector('input');
  if (!sachInp || !behInp || !kgInp) return;

  // Guard: nicht doppelt binden
  if (kgInp.dataset._kgAutoBound === '1') return;
  kgInp.dataset._kgAutoBound = '1';

  const recompute = () => {
    // wenn manuell gesetzt UND Feld nicht leer -> nicht überschreiben
    if (tr.dataset.kgManual === '1' && (kgInp.value || '').trim() !== '') return;

    const sach = (sachInp.value || '').trim();
    const beh  = Number((behInp.value || '').toString().replace(',', '.')) || 0;
    if (!sach || beh <= 0) return;

    const per = kgPerPalletForSach(sach);
    if (per <= 0) return;

    const total = Math.round(per * beh);
    kgInp.value = String(total);
  };

  // Wenn User Gewicht tippt → manuell; wenn er es löscht → Automatik wieder erlauben
  kgInp.addEventListener('input', () => {
    const v = (kgInp.value || '').trim();
    if (v === '') delete tr.dataset.kgManual;
    else tr.dataset.kgManual = '1';
  });

  // Änderungen, die Rechnen auslösen
  sachInp.addEventListener('input',  recompute);
  sachInp.addEventListener('change', recompute);
  behInp.addEventListener('input',   recompute);
  behInp.addEventListener('change',  recompute);

  // Initialer Vorschlag
  setTimeout(recompute, 0);
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
    j.items.forEach(renderRowFromDB);

forceSortByEingangAsc();
normalizeGroupHeaderRows();
syncAllRowMonthMeta();
window.WA_rebuildMonthAccordion?.();

regroupGroups();
rebuildFilterOptions();
applyFilter();
computeStats();
await refreshAllAttachmentBadges();
    } else {
      console.warn('API meldet Fehler:', j.error);
    }
  } catch (e) {
    console.warn('Server-Load fehlgeschlagen', e);
  }
})();


function isGroupHeaderColumn(colIdx) {
  return [3, 4, 5, 6, 7, 8, 9].includes(Number(colIdx));
}

function getGroupLeadRow(tr) {
  const nr = (getCellValue(tr, 0) || '').trim();
  if (!nr) return tr;

  // WICHTIG: erste Zeile dieser Ausgangsnummer in aktueller Tabellenreihenfolge
  const rows = [...tbody.querySelectorAll('tr')];
  return rows.find(r => (getCellValue(r, 0) || '').trim() === nr) || tr;
}

function getGroupLeadValue(tr, colIdx) {
  const lead = getGroupLeadRow(tr);
  return (getCellValue(lead, colIdx) || '').trim();
}

function getEffectiveCellValue(tr, colIdx) {
  if (isGroupHeaderColumn(colIdx)) {
    return getGroupLeadValue(tr, colIdx);
  }
  return getCellValue(tr, colIdx);
}

function getEffectiveDate(tr) {
  const raw = (getEffectiveCellValue(tr, 3) || '').trim();
  return parseISODate(raw);
}

function syncAllRowMonthMeta() {
  const rows = [...tbody.querySelectorAll('tr')];

  rows.forEach(tr => {
    const d = getEffectiveDate(tr);

    if (!d || Number.isNaN(d.getTime())) {
      delete tr.dataset.waMonth;
      return;
    }

    tr.dataset.waMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  });
}

window.WA_getEffectiveCellValue = getEffectiveCellValue;
window.WA_getEffectiveDate = getEffectiveDate;
window.WA_syncAllRowMonthMeta = syncAllRowMonthMeta;
window.WA_getGroupLeadRow = getGroupLeadRow;

function applyPalletRunningBadges() {
  // nur sichtbare Zeilen
  const rows = [...tbody.querySelectorAll('tr')].filter(tr => tr.offsetParent !== null);

  // alte Paletten-Badges überall entfernen
  rows.forEach(tr => tr.querySelectorAll('.badge-count.pallet').forEach(n => n.remove()));

  let i = 0;
  while (i < rows.length) {
    const key = normalizeKey(getCellValue(rows[i], 0)); // Ausg.-Nr.
    let j = i + 1;
    while (j < rows.length && normalizeKey(getCellValue(rows[j], 0)) === key) j++;

    if (key !== '') {
      const group = rows.slice(i, j);
      const sumPal = group.reduce((acc, r) => acc + toInt(getCellValue(r, 11)), 0); // Spalte 10 = Behälter

      // Badge nur in der ERSTEN Zeile der Gruppe, in der Behälter-Zelle
      const td = group[0]?.children[11];
      if (td) {
        td.classList.add('position-relative'); // für Eck-Position
        const b = document.createElement('span');
        b.className = 'badge-count pallet badge rounded-pill ' +
                      (sumPal > TRUCK_PALLET_LIMIT ? 'bg-danger' : 'bg-secondary');
        b.title = `Paletten gesamt für Ausg.-Nr. ${getCellValue(group[0],0).trim()}: ${sumPal}/${TRUCK_PALLET_LIMIT}`;
        b.textContent = `${sumPal}/${TRUCK_PALLET_LIMIT}`;

        // dezente Eck-Position (oben rechts) – ohne CSS-Datei
        b.style.position = 'absolute';
        b.style.top = '-.35rem';
        b.style.right = '-.35rem';

        td.appendChild(b);
      }
    }
    i = j;
  }
}
function parseKg(v){
  let s = String(v ?? '').trim();
  if (!s) return 0;

  s = s.replace(/\s+/g, '');

  // Fall 1: deutsches Format: 1.234,5
  if (s.includes(',')) {
    s = s.replace(/\./g, '').replace(',', '.');
    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  // Fall 2: Tausender-Punkte ohne Komma: 1.234 oder 12.345.678
  if (/^\d{1,3}(\.\d{3})+$/.test(s)) {
    s = s.replace(/\./g, '');
    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  // Fall 3: normal / DB: 195.00
  const n = Number(s);
  return Number.isFinite(n) ? n : 0;
}
function parseNumberInput(val) {
  let s = String(val ?? '').trim();
  if (!s) return 0;

  s = s.replace(/\s+/g, '');

  // deutsch: 1.234,56
  if (s.includes(',')) {
    s = s.replace(/\./g, '').replace(',', '.');
  }
  // tausenderpunkte ohne komma: 1.234 oder 12.345
  else if (/^\d{1,3}(\.\d{3})+$/.test(s)) {
    s = s.replace(/\./g, '');
  }

  const n = Number(s);
  return Number.isFinite(n) ? n : 0;
}

function applyGroupBadges() {
  const COL_BEH = 11, COL_ZUS = 13, COL_KG = 14, COL_GEB = 15;

  // 1) Alte Badges entfernen (egal wo sie aktuell stecken)
  const allRows = [...tbody.querySelectorAll('tr')];
  allRows.forEach(tr => {
    for (const sel of [
      '.badge-count.pallet', '.badge-count.weight',
      '.group-badge.pallet', '.group-badge.weight'
    ]) tr.querySelectorAll(sel).forEach(n => n.remove());
  });

  // 2) Nur sichtbare Zeilen betrachten (Bildschirmreihenfolge)
  const rows = allRows.filter(tr => tr.offsetParent !== null);

  // 3) über zusammenhängende Blöcke gleicher Ausg.-Nr. laufen
  let i = 0;
  while (i < rows.length) {
    const key = normalizeKey(getCellValue(rows[i], 0));
    let j = i + 1;
    while (j < rows.length && normalizeKey(getCellValue(rows[j], 0)) === key) j++;

    if (key !== '') {
      const group = rows.slice(i, j);
      const first = group[0];

      // Summen je Gruppe
      const palSum = group.reduce((a, r) => a + toInt(getCellValue(r, COL_BEH)), 0);
      const kgSum = group.reduce((a, r) => a + parseKg(getCellValue(r, COL_KG)), 0);

      // --- Paletten-Badge (zwischen Behälter und Zus.Beh) -------------------
      {
        const bPal = document.createElement('span');
        bPal.className = 'group-badge pallet badge rounded-pill ' +
                         (palSum > TRUCK_PALLET_LIMIT ? 'bg-danger' : 'bg-secondary');
        bPal.title = `Paletten gesamt für Ausg.-Nr. ${getCellValue(first,0).trim()}: ${palSum}/${TRUCK_PALLET_LIMIT}`;
        bPal.textContent = `${palSum}/${TRUCK_PALLET_LIMIT}`;
        const tdBeh = first?.children?.[COL_BEH];
if (tdBeh) {
  // Badge rechts neben Inhalt
  bPal.style.position = 'static';
  bPal.style.transform = 'none';
  bPal.style.left = 'auto';
  bPal.style.top = 'auto';
  bPal.style.zIndex = 'auto';
  bPal.style.pointerEvents = 'auto';

  // schöner Abstand + Ausrichtung
  bPal.classList.add('ms-2', 'align-middle');

  tdBeh.appendChild(bPal);
}
      }

      // --- Gewichts-Badge (zwischen BRT-GEW und Gebucht) --------------------
      {
        const bKg = document.createElement('span');
        bKg.className = 'group-badge weight badge rounded-pill ' +
                        (kgSum > MAX_TRUCK_KG ? 'bg-danger' : 'bg-secondary');
        bKg.title = `Gewicht gesamt für Ausg.-Nr. ${getCellValue(first,0).trim()}: ${kgSum} / ${MAX_TRUCK_KG} kg`;
        bKg.textContent = `${(kgSum/1000).toFixed(1)}t/${(MAX_TRUCK_KG/1000).toFixed(1)}t`;
        placeBetweenCellsBadge(first, COL_KG, COL_GEB, bKg);
      }
    }
    i = j;
  }
}


// Sachnummern
// --- Sachnummern-Loader ---
let PARTS = [];
(async () => {
  try {
    const res = await fetch('/api/stammdaten_api.php?type=sachnummer&action=list', { credentials:'same-origin' });
    const j = await res.json();
    PARTS = j.ok ? j.items.map(it => ({
  group: it.lagergruppe || '',
  part:  it.sachnummer,
  norm:  normalizeCode(it.sachnummer),
  kg:    parseKg(it.brt_gew)   // ✅ Gewicht pro Palette aus Stammdaten
})) : [];
    recomputeLGAllRows();   // <<< HINZUGEFÜGT
  } catch (e) {
    console.warn('Sachnummern-Load fehlgeschlagen', e);
  }
})();

// --- Behälter-Loader ---
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
    recomputeLGAllRows();   // <<< HINZUGEFÜGT
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
  { type: "text" }, // 0 ausgang_nr
  { type: "text" }, // 1 lieferschein
  { type: "text" }, // 2 lagergruppe (bei dir plaintext)
  { type: "date" }, // 3 datum
  { type: "text" }, // 4 kennzeichen
  { type: "text" }, // 5 land
  { type: "text" }, // 6 spedition
  { type: "time" }, // 7 ankunft
  { type: "time" }, // 8 beginn
  { type: "time" }, // 9 ende

  { type: "text" },                         // 10 sachnummer
  { type: "number", min: 0, step: 1 },      // 11 behaelter
  { type: "text" },                         // 12 behaelternr
  { type: "number", min: 0, step: 1 },      // 13 zus_behaelter
  { type: "number", min: 0, step: 1 },      // 14 brt_gew

  { type: "select", options: ["", "Nein", "Ja", "nicht TOP", "Mabon", "Banking", "Verzögert"] }, // 15
  { type: "select", options: ["FS DS","FS MD","FS AS","SS DS","SS MD","SS AS"] }                 // 16
];
function computeLGForRow(tr) {
  const sach = (getCellValue(tr, 10) || '').trim();   // ✅ vorher 13
  if (sach) {
    const hit = PARTS.find(p => p.norm === normalizeCode(sach));
    if (hit && hit.group) return hit.group;
  }

  const behnr = (getCellValue(tr, 12) || '').trim();  // bleibt 12
  if (behnr) {
    const h2 = BEHS.find(b => b.norm === normalizeCode(behnr));
    if (h2 && h2.group) return h2.group;
  }

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

  const rows = [...tbody.querySelectorAll("tr")].filter(tr => tr.dataset.saved === "1");

  const weekRows = rows.filter(tr => {
    const d = getEffectiveDate(tr);
    if (!d) return false;
    d.setHours(0, 0, 0, 0);
    return d >= start && d <= end;
  });

  if (!weekRows.length) {
    alert("Keine Einträge für die vorige Woche gefunden.");
    return;
  }

  const { week, year } = isoWeek(start);
  const fname = `Warenausgang_KW${String(week).padStart(2, '0')}_${year}.csv`;

  const csv = buildCsvFromRows(weekRows);
  downloadCsv(fname, csv);

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
    if (!cell) return "";
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
    const raw = String(val ?? '').trim();
    if (!raw) return Number.NEGATIVE_INFINITY;
    return parseNumberInput(raw);
  }

  if (type === "date") {
    const t = Date.parse(val);
    return isNaN(t) ? -Infinity : t;
  }

  if (type === "time") {
    const m = /^(\d{1,2}):(\d{2})$/.exec(val);
    if (!m) return -Infinity;
    return (+m[1] * 3600) + (+m[2] * 60);
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

function normalizeGroupHeaderRows() {
  const HEADER_COLS = [3, 4, 5, 6, 7, 8, 9];
  const rows = [...tbody.querySelectorAll('tr')];

  let i = 0;
  while (i < rows.length) {
    const nr = (getCellValue(rows[i], 0) || '').trim();
    let j = i + 1;

    while (j < rows.length && (getCellValue(rows[j], 0) || '').trim() === nr) {
      j++;
    }

    if (nr) {
      const group = rows.slice(i, j);
      const first = group[0];

      HEADER_COLS.forEach(col => {
        let value = (getCellValue(first, col) || '').trim();

        if (!value) {
          for (const tr of group.slice(1)) {
            value = (getCellValue(tr, col) || '').trim();
            if (value) break;
          }
        }

        const firstTd = first.children[col];
        if (firstTd) setCellRaw(firstTd, value || '');
      });

      group.slice(1).forEach(tr => {
        HEADER_COLS.forEach(col => {
          const td = tr.children[col];
          if (td) setCellRaw(td, '');
        });
      });
    }

    i = j;
  }
}

  // ---- Fokus-Helper für eine Zelle (Editor) ----
function focusEditor(tr, colIdx){
  const el = tr?.children?.[colIdx]?.querySelector?.('input,select');
  if (el) { el.focus(); el.select?.(); }
}

// ---- Duplizieren einer Zeile (Button + Shortcut benutzen diese Funktion) ----
function duplicateRow(srcTr, { markAsDup = true } = {}) {
  if (!srcTr) return null;

  const ausgang = getCellValue(srcTr, 0);
  const lagergrp = getCellValue(srcTr, 2);
  const sach     = getCellValue(srcTr, 10); // ✅ neu
  const behnr    = getCellValue(srcTr, 12); // bleibt

  const values = [];
  for (let i = 0; i < 17; i++) {
    let v = getCellValue(srcTr, i);

    if (i === 0) v = ausgang;
    if (i === 1) v = '';
    if (i === 2) v = lagergrp;
    if (i === 3) v = '';
    if (i === 4) v = '';
    if (i === 5) v = '';
    if (i === 6) v = '';
    if (i === 7 || i === 8 || i === 9) v = '';

    if (i === 10) v = sach;        // ✅ Sachnr
    if (i === 11) v = '0';         // ✅ Behälter
    if (i === 12) v = behnr;       // ✅ Beh.-Nr
    if (i === 13) v = '0';         // ✅ Zus.-Beh
    if (i === 14) v = '0';         // ✅ BRT-GEW

    if (i === 15) v = '';          // Gebucht leer
    // 16 (Geb. von) kannst du lassen wie Vorlage
    values[i] = v;
  }

  const tr = document.createElement('tr');
  for (let i = 0; i < 17; i++) {
    const td = document.createElement('td');
    td.dataset.raw = values[i];
    td.textContent = values[i];
    tr.appendChild(td);
  }

  appendCmrCols(tr);
  const tdAction = document.createElement('td');
  tdAction.appendChild(makeActionGroup(true));
  tr.appendChild(tdAction);

  srcTr.parentElement.insertBefore(tr, srcTr.nextSibling);

  setRowMode(tr, 'edit');
  tr.dataset.saved = '0';
  if (markAsDup) tr.dataset.isDup = '1';

  editRow(tr);

  // Werte in Inputs/Selects setzen
  for (let i = 0; i < 17; i++) {
    if (i === 2) {
      const span = tr.children[2].querySelector('.form-control-plaintext');
      if (span) span.textContent = values[2];
      continue;
    }
    const sel = tr.children[i].querySelector('select');
    const inp = tr.children[i].querySelector('input');
    if (sel) sel.value = values[i];
    else if (inp) inp.value = values[i];
  }

  try { window.__bindRowAC?.(tr); } catch {}

  regroupGroups(); rebuildFilterOptions(); applyFilter(); computeStats?.();
  (async () => { try { await refreshAllAttachmentBadges(); } catch(e){} })();

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

  // "current" = aktiver Accordion-Monat, falls vorhanden
  const activeYm = window.WA_activeMonth || "";
  if (/^\d{4}-\d{2}$/.test(activeYm)) {
    const [yy, mm] = activeYm.split("-").map(Number);
    const start = new Date(yy, mm - 1, 1);
    const end = new Date(yy, mm, 1);
    return { start, end, label: fmtMonth(start) };
  }

  // Fallback: echter aktueller Kalendermonat
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
  const key   = statsRangeEl ? statsRangeEl.value : "current";
  const range = getRangeByKey(key);

  const TARGET_GROUPS = ["W1", "X3", "X3(B)", "G9", "B1", "Bauteile", "BM", "Müll"];

  const offeneSet       = new Set();
  const offeneList      = new Map();
  const sumByLagergruppe = new Map();
  const detailsByLG      = new Map();

  const rows = [...tbody.querySelectorAll("tr")]
    .filter(tr => tr.dataset.saved === "1" || tr.dataset.mode === "edit");

  const inRange = (tr) => {
    const dt = getEffectiveDate(tr);
    if (range.start && (!dt || dt < range.start)) return false;
    if (range.end   && (!dt || dt >= range.end)) return false;
    return true;
  };

  const fmtNum = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num)
      ? String(num)
      : num.toLocaleString("de-DE", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  // ===== Warenausgänge pro Tag (distinct Ausg.-Nr. pro Führungsdatum) =====
  const perDayMap = new Map(); // YYYY-MM-DD -> Set<Ausg.-Nr.>

  rows.forEach((tr) => {
    if (!inRange(tr)) return;

    const dt = getEffectiveDate(tr);
    const nr = (getCellValue(tr, 0) || "").trim();
    if (!dt || !nr) return;

    const dayKey = formatISO(dt);
    if (!perDayMap.has(dayKey)) perDayMap.set(dayKey, new Set());
    perDayMap.get(dayKey).add(nr);
  });

  const perDayArr = [...perDayMap.entries()]
    .map(([d, set]) => [d, set.size])
    .sort((a, b) => b[0].localeCompare(a[0]));

  function prevDayISO(iso) {
    const d = parseISODate(iso);
    if (!d) return null;
    d.setDate(d.getDate() - 1);
    return formatISO(d);
  }

  const perDayBody = document.getElementById("statsPerDay");
  const perDayInfo = document.getElementById("statsPerDayInfo");

  if (perDayBody) {
    perDayBody.innerHTML = "";

    if (perDayArr.length === 0) {
      perDayBody.innerHTML = '<tr><td colspan="3" class="text-muted">Keine Daten im Zeitraum.</td></tr>';
    } else {
      perDayArr.forEach(([dayKey, cnt]) => {
        const prevKey = prevDayISO(dayKey);
        const prevCnt = prevKey && perDayMap.has(prevKey) ? perDayMap.get(prevKey).size : 0;
        const delta   = cnt - prevCnt;

        let deltaHtml = '<span class="text-muted"><i class="bi bi-dash-lg"></i> 0</span>';
        if (delta > 0) {
          deltaHtml = `<span class="text-success"><i class="bi bi-arrow-up-short"></i>+${delta}</span>`;
        } else if (delta < 0) {
          deltaHtml = `<span class="text-danger"><i class="bi bi-arrow-down-short"></i>${delta}</span>`;
        }

        const trEl = document.createElement("tr");
        trEl.innerHTML = `
          <td>${dayKey}</td>
          <td class="text-end">${cnt}</td>
          <td class="text-end">${deltaHtml}</td>
        `;
        trEl.style.cursor = "pointer";
        trEl.addEventListener("click", () => {
          const sf = document.getElementById("searchField");
          if (sf) sf.value = "all";
          const si = document.getElementById("searchInput");
          if (si) {
            si.value = dayKey;
            applyFilter();
          }
          document.getElementById("ausgangTable")?.scrollIntoView({ behavior: "smooth", block: "start" });
        });

        perDayBody.appendChild(trEl);
      });
    }
  }

  if (perDayInfo) perDayInfo.textContent = `Zeitraum: ${range.label}`;

  // ===== Restliche Aggregation =====
  rows.forEach((tr) => {
    if (!inRange(tr)) return;

    const sach      = (getCellValue(tr, 10) || "").trim();
    const behaelter = toInt(getCellValue(tr, 11));
    const zus       = toInt(getCellValue(tr, 13));
    const brtGew    = parseNumberInput(getCellValue(tr, 14));
    const gebucht   = ((getCellValue(tr, 15) || "").trim() !== "");
    const ausgang   = (getCellValue(tr, 0) || "").trim();
    const lg        = (getCellValue(tr, 2) || "").trim();

    if (!gebucht && ausgang) {
      offeneSet.add(ausgang);
      offeneList.set(ausgang, true);
    }

    if (!detailsByLG.has(lg)) {
      detailsByLG.set(lg, {
        deliveries: new Set(),
        zus: 0,
        brtGew: 0,
        perAusgang: new Map()
      });
    }

    sumByLagergruppe.set(lg, (sumByLagergruppe.get(lg) || 0) + behaelter);

    const d = detailsByLG.get(lg);
    if (ausgang) d.deliveries.add(ausgang);
    d.zus    += zus;
    d.brtGew += brtGew;

    let pe = d.perAusgang.get(ausgang);
    if (!pe) {
      pe = { rows: 0, behaelter: 0, zus: 0, brtGew: 0 };
      d.perAusgang.set(ausgang, pe);
    }

    pe.rows      += 1;
    pe.behaelter += behaelter;
    pe.zus       += zus;
    pe.brtGew    += brtGew;
  });

  // ===== Top-Items (Sachnr. oder Beh.-Nr.) =====
  function keyForRow(tr) {
    const sach = (getCellValue(tr, 10) || "").trim();
    if (sach) return { key: `SN|${sach}`, label: sach };

    const beh = (getCellValue(tr, 12) || "").trim();
    if (beh) return { key: `BH|${beh}`, label: `Beh.-Nr. ${beh}` };

    return null;
  }

  const sumByItem  = new Map();
  const labelByKey = new Map();

  rows.forEach((tr) => {
    if (!inRange(tr)) return;

    const behaelter = toInt(getCellValue(tr, 11));
    const k = keyForRow(tr);
    if (!k) return;

    sumByItem.set(k.key, (sumByItem.get(k.key) || 0) + behaelter);
    if (!labelByKey.has(k.key)) labelByKey.set(k.key, k.label);
  });

  const top = [...sumByItem.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, TOP_LIMIT);

  const tbodyStats = document.getElementById("statsSach");
  const info       = document.getElementById("statsSachInfo");

  if (tbodyStats) {
    tbodyStats.innerHTML = "";
    top.forEach(([topKey, sum]) => {
      const trEl = document.createElement("tr");
      trEl.dataset.topkey = topKey;
      trEl.innerHTML = `<td>${labelByKey.get(topKey)}</td><td class="text-end">${sum}</td>`;
      tbodyStats.appendChild(trEl);
    });
    if (info) {
      info.textContent = `Zeitraum: ${range.label} — Führungszeile je Ausgangsnummer ist maßgeblich.`;
    }
  }

  bindSachStatsInteractions?.();

  // ===== Offene Ausgangsnummern =====
  const offCountEl = document.getElementById("statsOffenCount");
  if (offCountEl) offCountEl.textContent = String(offeneSet.size);

  const listDiv = document.getElementById("statsOffenList");
  if (listDiv) {
    if (offeneSet.size === 0) {
      listDiv.innerHTML = `<span class="text-muted">Alles gebucht – keine offenen Ausgangsnummern.</span>`;
    } else {
      const arr = [...offeneList.keys()].sort((a, b) => a.localeCompare(b, "de", { numeric: true }));
      listDiv.innerHTML = arr
        .map(k => `<a href="#" class="badge bg-warning-subtle text-warning-emphasis me-1 mb-1 open-ausgang" data-id="${k}">${k}</a>`)
        .join(" ");
    }
  }

  // ===== Lagergruppen =====
  const tbodyGruppen = document.getElementById("statsGruppen");
  const gesamtEl     = document.getElementById("statsGruppenGesamt");

  if (tbodyGruppen && gesamtEl) {
    tbodyGruppen.innerHTML = "";

    const allLGs = [...sumByLagergruppe.keys()];
    const extras = allLGs.filter(g => !TARGET_GROUPS.includes(g));
    extras.sort((a, b) => String(a).localeCompare(String(b), "de", { numeric: true }));

    const order = [...TARGET_GROUPS, ...extras];
    let gesamt = 0;

    order.forEach((grp) => {
      const sum = sumByLagergruppe.get(grp) || 0;
      gesamt += sum;

      const label = grp === "" ? "ohne LG" : grp;
      const trEl = document.createElement("tr");
      trEl.dataset.grp = grp;
      trEl.innerHTML = `
        <td>
          <i class="bi bi-chevron-right grp-caret"></i>
          ${label}
        </td>
        <td class="text-end">${sum}</td>
      `;
      tbodyGruppen.appendChild(trEl);
    });

    gesamtEl.textContent = String(gesamt);
  }

  window._STATS_DETAILS = detailsByLG;
  bindSachStatsInteractions?.();
}

function markOverweightGroups() {
  // Summe BRT-GEW je Ausgangsnummer
  const byNr = new Map();
  const rows = [...tbody.querySelectorAll("tr")];

  rows.forEach(tr => {
    const nr = (getCellValue(tr, 0) || '').trim();
    const kg = parseKg(getCellValue(tr, 14));
    if (!nr) return;
    byNr.set(nr, (byNr.get(nr) || 0) + kg);
  });

  // Reset
  rows.forEach(tr => {
    tr.classList.remove('overweight');
    tr.children[0]?.querySelector('.ow-badge')?.remove();
  });

  // Markieren
  byNr.forEach((sumKg, nr) => {
    if (sumKg > MAX_TRUCK_KG) {
      const grp = rows.filter(tr => (getCellValue(tr, 0) || '').trim() === nr);
      grp.forEach((tr, idx) => {
        tr.classList.add('overweight');
        if (idx === 0) {
          const b = document.createElement('span');
          b.className = 'ow-badge badge rounded-pill bg-danger ms-1';
          b.title = `Übergewicht: ${sumKg} kg > ${MAX_TRUCK_KG} kg`;
          b.textContent = 'Übergewicht';
          tr.children[0]?.appendChild(b);
        }
      });
    }
  });
}

document.getElementById('statsGruppen')?.addEventListener('click', (ev) => {
  // 1) Klick auf Ausgangsnummer im Detail -> springen
  const link = ev.target.closest('.lg-ausgang-link');
  if (link) {
    ev.preventDefault();
    ev.stopPropagation();
    window.WA_jumpToAusgangNr?.(link.dataset.id || '');
    return;
  }

  // 2) Klick auf Lagergruppen-Hauptzeile
  const tr = ev.target.closest('tr[data-grp]');
  if (!tr) return;

  // schon offen? -> schließen
  const next = tr.nextElementSibling;
  if (next && next.classList.contains('stats-details')) {
    next.remove();
    tr.querySelector('.grp-caret')?.classList.replace('bi-chevron-down', 'bi-chevron-right');
    return;
  }

  // andere offene Details schließen
  document.querySelectorAll('#statsGruppen .stats-details').forEach(el => el.remove());
  document.querySelectorAll('#statsGruppen .grp-caret').forEach(el => {
    el.classList.remove('bi-chevron-down');
    el.classList.add('bi-chevron-right');
  });

  // neu öffnen
  const grp = tr.dataset.grp;
  const d = (window._STATS_DETAILS || new Map()).get(grp);
  if (!d) return;

  const deliveriesCount = d.deliveries.size;
  const zusSum = d.zus;
  const brtGewSum = d.brtGew;

  const entries = Array.from((d.perAusgang || new Map()).entries());
  entries.sort((a, b) => {
    const ax = a[0], bx = b[0];
    const na = /^\d+$/.test(ax) ? Number(ax) : ax;
    const nb = /^\d+$/.test(bx) ? Number(bx) : bx;
    if (typeof na === 'number' && typeof nb === 'number') return na - nb;
    return String(ax).localeCompare(String(bx), 'de');
  });

  const rowsHtml = entries.length
    ? entries.map(([ausg, x]) => `
        <tr>
          <td>
            <a href="#" class="lg-ausgang-link" data-id="${escapeHtml(ausg)}">${escapeHtml(ausg)}</a>
          </td>
          <td class="text-end">${x.rows}</td>
          <td class="text-end">${x.behaelter}</td>
          <td class="text-end">${x.zus}</td>
          <td class="text-end">${x.brtGew}</td>
        </tr>
      `).join('')
    : '<tr><td colspan="5" class="text-muted">Keine Daten.</td></tr>';

  const detailsTr = document.createElement('tr');
  detailsTr.className = 'stats-details';

  const col = document.createElement('td');
  col.colSpan = 2;
  col.innerHTML = `
    <div class="p-2">
      <div class="small mb-2">
        <strong>${escapeHtml(grp || 'ohne LG')}</strong> — Warenausgänge: <strong>${deliveriesCount}</strong>
        · Zusatz-Beh.: <strong>${zusSum}</strong>
        · brt_gew: <strong>${brtGewSum}</strong>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Ausg.-Nr.</th>
              <th class="text-end">Zeilen</th>
              <th class="text-end">Paletten</th>
              <th class="text-end">Zus.-Beh.</th>
              <th class="text-end">Gewicht</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    </div>
  `;

  detailsTr.appendChild(col);
  tr.after(detailsTr);
  tr.querySelector('.grp-caret')?.classList.replace('bi-chevron-right', 'bi-chevron-down');
});

// === Drilldown: Top-Liste (Sachnummer ODER Beh.-Nr.) ==========================
function getCurrentRange() {
  const key = (document.getElementById('statsRange')?.value) || 'current';
  return getRangeByKey(key);
}

function gatherRowsInRangeForSach(key) {
  const { start, end } = getCurrentRange();
  const [type, val] = String(key || "").split("|");

  return [...document.querySelectorAll("#ausgangTable tbody tr")]
    .filter(tr => tr.dataset.saved === "1" || tr.dataset.mode === "edit")
    .filter(tr => {
      const dt = getEffectiveDate(tr);
      if (start && (!dt || dt < start)) return false;
      if (end   && (!dt || dt >= end)) return false;

      const sach = (getCellValue(tr, 10) || "").trim();
      const beh  = (getCellValue(tr, 12) || "").trim();

      if (type === "SN") return sach === val;
      if (type === "BH") return !sach && beh === val;
      return false;
    });
}
function toggleSachDetail(anchorTr, key) {
  const next = anchorTr.nextElementSibling;
  if (next && next.classList.contains('sach-detail')) {
    next.remove();
    return;
  }

  anchorTr.parentElement.querySelectorAll('.sach-detail').forEach(n => n.remove());

  const rows = gatherRowsInRangeForSach(key);
  const label = anchorTr?.querySelector('td')?.textContent?.trim() || key;

  const byAusgang = new Map();
  let sumBeh = 0;
  let sumZus = 0;
  let sumGew = 0;

  for (const tr of rows) {
    const ausgang = getCellValue(tr, 0).trim();
    const datum   = getEffectiveCellValue(tr, 3).trim();
    const beh     = toInt(getCellValue(tr, 11));
    const zus     = toInt(getCellValue(tr, 13));
    const gew     = parseNumberInput(getCellValue(tr, 14));

    sumBeh += beh;
    sumZus += zus;
    sumGew += gew;

    const acc = byAusgang.get(ausgang) || {
      ausgang,
      count: 0,
      sumBeh: 0,
      sumZus: 0,
      sumGew: 0,
      dates: new Set()
    };

    acc.count += 1;
    acc.sumBeh += beh;
    acc.sumZus += zus;
    acc.sumGew += gew;
    if (datum) acc.dates.add(datum);

    byAusgang.set(ausgang, acc);
  }

  const fmt = (n) =>
    Number.isInteger(n)
      ? String(n)
      : n.toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

  const deliveries = byAusgang.size;

  const rowsHtml = [...byAusgang.values()]
    .sort((a, b) => a.ausgang.localeCompare(b.ausgang, 'de', { numeric: true }))
    .map(v => {
      const datesStr = [...v.dates].sort().join(', ');
      return `
        <tr>
          <td>${escapeHtml(v.ausgang || '-')}</td>
          <td>${escapeHtml(datesStr || '-')}</td>
          <td class="text-end">${v.count}</td>
          <td class="text-end">${v.sumBeh}</td>
          <td class="text-end">${v.sumZus}</td>
          <td class="text-end">${fmt(v.sumGew)}</td>
        </tr>
      `;
    })
    .join('');

  const html = `
    <tr class="sach-detail">
      <td colspan="2">
        <div class="p-2 bg-body-tertiary rounded border">
          <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="badge text-bg-primary">${escapeHtml(label)}</span>
            <span class="badge text-bg-secondary">Ausgänge: <strong>${deliveries}</strong></span>
            <span class="badge text-bg-secondary">Behälter: <strong>${sumBeh}</strong></span>
            <span class="badge text-bg-secondary">Zus.-Beh.: <strong>${sumZus}</strong></span>
            <span class="badge text-bg-success">Gewicht: <strong>${fmt(sumGew)}</strong></span>
          </div>

          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Ausg.-Nr.</th>
                  <th>Datum</th>
                  <th class="text-end">Zeilen</th>
                  <th class="text-end">Behälter</th>
                  <th class="text-end">Zus.-Beh.</th>
                  <th class="text-end">Gewicht</th>
                </tr>
              </thead>
              <tbody>
                ${rowsHtml || '<tr><td colspan="6" class="text-muted">Keine Daten im Zeitraum.</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>
      </td>
    </tr>
  `;

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
function getEmpfaengerCode(tr){
  const td = tr.querySelector('td[data-col="cmr_code"]');
  if (!td) return '';
  const sel = td.querySelector('select');
  if (sel) return (sel.value || '').trim();
  return (td.dataset.raw || td.textContent || '').trim();
}


  // --- Action-Spalte ---
  function ensureActionCell(tr) {
    const last = tr.lastElementChild;
    const headCols = table?.tHead?.rows?.[0]?.cells?.length || 18;
if (!last || last.cellIndex < (headCols - 1)) {
      const td = document.createElement("td");
      td.appendChild(makeActionGroup(false));
      tr.appendChild(td);
    } else if (last && last.childElementCount === 0) {
      last.appendChild(makeActionGroup(false));
    }
  }

  function enterEditMode(tr, focusColIdx = null) {
  if (!tr) return;

  if (tr.dataset.mode !== "edit") {
    editRow(tr);
    tr.dataset.saved = "0";
    setRowMode(tr, "edit");

    const btnEdit = tr.querySelector("td:last-child .action-btn.btn-outline-secondary");
    if (btnEdit) {
      btnEdit.innerHTML = '<i class="bi bi-check2"></i>';
      btnEdit.title = "Speichern";
    }

    clearRowBorders(tr);
    removeBadge(tr);

    window.StammdatenAC?.bindRowAC(tr, {
      colSpedition: 6,
      colKennzeichen: 4,
      colBehaelter: 12,
      colSachnummer: 10,
      colLG: 2
    });

    const behnrInp = tr.children[12]?.querySelector("input");
    const sachInp  = tr.children[10]?.querySelector("input");
    const reapplyLG = () => applyLGForRow(tr);
    behnrInp?.addEventListener("input", reapplyLG);
    sachInp?.addEventListener("input", reapplyLG);
    reapplyLG();

    bindAutoKgForRow(tr);
  }

  if (focusColIdx != null) {
    const direct = tr.children[focusColIdx]?.querySelector("input,select");

    if (direct && !direct.disabled && !direct.readOnly) {
      direct.focus();
      direct.select?.();
      return;
    }

    const firstEditable = [...tr.querySelectorAll("input,select")]
      .find(el => !el.disabled && !el.readOnly);

    firstEditable?.focus();
    firstEditable?.select?.();
  }
}

// ✅ FIX: saubere Version ohne Syntaxfehler, ohne "setLG"-Reste, mit korrekter LG-Logik
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
      // Speichern
      saveRow(tr);
      try {
        await upsertRowToServer(tr);
        tr.dataset.saved = "1";
        setRowMode(tr, "view");
        btnEdit.innerHTML = '<i class="bi bi-pencil"></i>';
        btnEdit.title = "Bearbeiten";

        // UI aktualisieren
        regroupGroups();
        rebuildFilterOptions();
        applyFilter();
        computeStats?.();
        queueRefreshAllAttachmentBadges?.();
        
      } catch (err) {
        alert("Speichern am Server fehlgeschlagen: " + err.message);
      }
    } else {
  enterEditMode(tr);
    }
  });

  // Duplizieren
  const btnCopy = document.createElement("button");
  btnCopy.type = "button";
  btnCopy.className = "btn btn-outline-info btn-sm action-btn me-1";
  btnCopy.innerHTML = '<i class="bi bi-copy"></i>';
  btnCopy.title = "Zeile duplizieren";
  btnCopy.addEventListener("click", (e) => {
    const srcTr = e.currentTarget.closest("tr");
    duplicateRow(srcTr);
  });

  // WA-Drucken (Gruppenbutton)
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

  // Anhänge (Gruppenbutton)
  const btnFiles = document.createElement("button");
  btnFiles.type = "button";
  btnFiles.dataset.role = "group";
  btnFiles.className = "btn btn-outline-dark btn-sm action-btn me-1 position-relative";
  btnFiles.innerHTML = '<i class="bi bi-paperclip"></i>';
  btnFiles.title = "Anhänge (Dokumente/Bilder)";
  btnFiles.addEventListener("click", async (e) => {
    const tr = e.currentTarget.closest("tr");
    const nr = getCellValue(tr, 0).trim();
    if (!nr) { alert("Bitte zuerst eine ausgangsnummer eintragen/speichern."); return; }
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
    regroupGroups(); 
    rebuildFilterOptions(); 
    applyFilter();
    computeStats?.();
    queueRefreshAllAttachmentBadges?.();
  });

  frag.appendChild(btnEdit);
  frag.appendChild(btnCopy);
  frag.appendChild(btnPrint);
  frag.appendChild(btnFiles);
  frag.appendChild(btnDelete);
  return frag;
}



function setRowMode(tr, mode) {
    tr.dataset.mode = mode;
}

function editRow(tr) {
  tr.dataset.originalAusgangNr = (getCellValue(tr, 0) || '').trim();

  for (let i = 0; i < 17; i++) {
    const def = COLUMNS[i];
    const td  = tr.children[i];
    const currentValue = getCellValue(tr, i);

    // wenn wir editieren, wollen wir NICHT mehr den alten data-raw Wert verwenden
    delete td.dataset.raw;

    // Lagergruppe (Index 2) NICHT editierbar
    if (i === 2) {
      td.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(currentValue)}</span>`;
      continue;
    }

    // Select-Spalten
    if (def.type === "select") {
      td.innerHTML = `
        <select class="form-select form-select-sm">
          ${def.options.map(o => `<option${o === currentValue ? " selected" : ""}>${o}</option>`).join("")}
        </select>
      `;
      continue;
    }

    // Input-Spalten
    const extra = def.min !== undefined ? ` min="${def.min}"` : "";
    const step  = def.step !== undefined ? ` step="${def.step}"` : "";
    let val     = sanitizeAttr(currentValue);

    // Datum default auf heute
    if (def.type === "date" && i === 3) val = val || todayISO();

    // ✅ DAS war bei dir faktisch “weg”: Input wirklich in die Zelle schreiben
    td.innerHTML = `<input type="${def.type}" class="form-control form-control-sm"${extra}${step} value="${val}">`;
  }

  // Land ISO-2 nur Buchstaben, uppercase (Index 5)
  const landInp = tr.children[5]?.querySelector("input");
  if (landInp) {
    landInp.setAttribute("maxlength", "2");
    landInp.setAttribute("inputmode", "text");
    landInp.setAttribute("pattern", "[A-Za-z]{2}");
    landInp.addEventListener("input", () => {
      landInp.value = landInp.value.replace(/[^A-Za-z]/g, "").toUpperCase().slice(0, 2);
    });
  }

  // LG live aus Sachnummer ODER Behälternummer ermitteln
  const behnrInp = tr.children[12]?.querySelector("input");
  const sachInp  = tr.children[10]?.querySelector("input");
  const reapplyLG = () => applyLGForRow(tr);
  behnrInp?.addEventListener("input", reapplyLG);
  sachInp?.addEventListener("input",  reapplyLG);
  reapplyLG();

  // Auto-KG
  bindAutoKgForRow(tr);
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

    if (i === 5) {
      value = (value || "").toUpperCase().replace(/[^A-Z]/g, "").slice(0, 2);
      if (value && !ISO2.has(value)) {
        alert(`Ungültiges Länderkürzel: "${value}". Bitte ISO-2 verwenden (z. B. DE, NL, PL).`);
      }
    }

    setCellRaw(td, (value || "").trim());
  }

  const lgTd = tr.children[2];
  if (lgTd) setCellRaw(lgTd, computeLGForRow(tr));

  normalizeGroupHeaderRows();
  syncAllRowMonthMeta();
  window.WA_rebuildMonthAccordion?.();
}

function sanitizeAttr(v) {
    return String(v ?? "").replace(/"/g, "&quot;");
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
    } else {
      const extra = def.min !== undefined ? ` min="${def.min}"` : "";
      const step  = def.step !== undefined ? ` step="${def.step}"` : "";
      if (def.type === "date" && i === 3) {
        td.innerHTML = `<input type="date" class="form-control form-control-sm"${extra}${step} value="${todayISO()}">`;
      } else {
        td.innerHTML = `<input type="${def.type}" class="form-control form-control-sm"${extra}${step}>`;
      }
    }
    tr.appendChild(td);
  }
  // <<< HIER JETZT RICHTIG: nachdem die Zelle existiert >>>
  const inpEin = tr.children[0]?.querySelector("input");
  if (inpEin && !inpEin.value) inpEin.value = nextausgangsnummer();

  appendCmrCols(tr);   // <<< NEU: CMR Spalten auch bei neuen Zeilen
  const tdAction = document.createElement("td");
  tdAction.appendChild(makeActionGroup(true));
  tr.appendChild(tdAction);

  tbody.appendChild(tr);
  setRowMode(tr, "edit");
  tr.dataset.saved = "0";

  normalizeGroupHeaderRows();
syncAllRowMonthMeta();
window.WA_rebuildMonthAccordion?.();

  const landInp = tr.children[5]?.querySelector("input");
  if (landInp) {
    landInp.setAttribute("maxlength","2");
    landInp.setAttribute("inputmode","text");
    landInp.setAttribute("pattern","[A-Za-z]{2}");
    landInp.addEventListener("input", () => {
      landInp.value = landInp.value.replace(/[^A-Za-z]/g,"").toUpperCase().slice(0,2);
    });
  }
  bindAutoKgForRow(tr);

});


  // --- Filter + Suche ---
  filterSelect?.addEventListener("change", applyFilter);
searchAllCols?.addEventListener("change", applyFilter);
btnResetFilter?.addEventListener("click", () => {
  if (filterSelect) filterSelect.value = "";
  if (searchInput)  searchInput.value  = "";
  if (searchAllCols) searchAllCols.checked = false;
  applyFilter();
});

function rebuildFilterOptions() {
  if (!filterSelect) return;

  const current = filterSelect.value;
  const activeMonth = window.WA_activeMonth || null;
  const counts = new Map();

  Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
    const key = getCellValue(tr, 0).trim();
    if (!key) return;

    if (activeMonth) {
      const rowMonth = tr.dataset.waMonth || "";
      if (rowMonth && rowMonth !== activeMonth) return;
    }

    counts.set(key, (counts.get(key) || 0) + 1);
  });

  filterSelect.innerHTML = '<option value="">Alle</option>';

  Array.from(counts.keys())
    .sort((a, b) => a.localeCompare(b, 'de', { numeric: true }))
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
  ausgang: 0,        // Ausgangsnummer
  lieferschein: 1,   // Lieferschein-Nr.
  spedition: 6,      // Spedition
  sachnummer: 10,    // Sachnummer
  all: -1            // Spezialfall: alle Spalten
};

const searchField = document.getElementById("searchField");

// optional: Platzhalter dynamisch anpassen
const PLACEHOLDERS = {
  ausgang: "z. B. WE-2025-00123",
  lieferschein: "z. B. LS-Nummer",
  spedition: "z. B. DPD / DHL / Rhenus",
  sachnummer: "z. B. 05C 145 785 D",
  all: "frei suchen (alle Spalten)"
};
searchField?.addEventListener("change", () => {
  const key = searchField.value || "ausgang";
  document.getElementById("searchInput").placeholder = PLACEHOLDERS[key] || "";
  applyFilter();
});


 function applyFilter() {
  const selected  = filterSelect?.value || "";
  const query     = (searchInput?.value || "").trim();
  const fieldKey  = searchField?.value || "ausgang";

  const activeMonth = window.WA_activeMonth || null; // 👈 neu

  Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
    const key          = getCellValue(tr, 0);
    const matchDropdown = !selected || key === selected;
    const matchSearch   = rowMatchesSearch(tr, query, fieldKey);

    // Monatsfilter: nur Zeilen des aktiven Monats anzeigen
    let matchMonth = true;
    if (activeMonth) {
      const rowMonth = tr.dataset.waMonth || "";
      // neue / leere Zeilen ohne Datum trotzdem anzeigen
      matchMonth = !rowMonth || rowMonth === activeMonth;
    }

    tr.style.display = (matchDropdown && matchSearch && matchMonth) ? "" : "none";
  });

  regroupGroups();
  computeStats();
}

// === Offene Ausg.-Nr. klicken -> ggf. Monat wechseln + zur Zeile springen + Blink (3x) + Center-Toast ===

// === Gemeinsamer Sprung zu Ausg.-Nr. (Offene Nummern + Lagergruppen-Details) ===
(() => {
  function ensureBlinkStyle(){
    if (document.getElementById('wa-blink-style')) return;
    const s = document.createElement('style');
    s.id = 'wa-blink-style';
    s.textContent = `
      @keyframes waRowBlink {
        0%   { background-color: rgba(255, 193, 7, .90); }
        50%  { background-color: rgba(255, 193, 7, .10); }
        100% { background-color: rgba(255, 193, 7, .90); }
      }
      tr.wa-row-blink { animation: waRowBlink .65s ease-in-out 3; }
    `;
    document.head.appendChild(s);
  }

  function blinkRow(tr){
    if (!tr) return;
    ensureBlinkStyle();
    tr.classList.remove('wa-row-blink');
    void tr.offsetWidth;
    tr.classList.add('wa-row-blink');
    setTimeout(() => tr.classList.remove('wa-row-blink'), 2100);
  }

  function ensureToastContainer(){
    let c = document.getElementById('waToastContainer');
    if (c) return c;

    c = document.createElement('div');
    c.id = 'waToastContainer';
    c.className = 'position-fixed top-50 start-50 translate-middle';
    c.style.zIndex = '2500';
    c.style.pointerEvents = 'none';
    document.body.appendChild(c);
    return c;
  }

  function showMiniToastCenter(message){
    if (!window.bootstrap?.Toast) return;

    const c = ensureToastContainer();
    const el = document.createElement('div');
    el.className = 'toast show text-bg-dark border-0 shadow';
    el.setAttribute('role','status');
    el.setAttribute('aria-live','polite');
    el.setAttribute('aria-atomic','true');
    el.style.pointerEvents = 'auto';

    el.innerHTML = `
      <div class="d-flex align-items-center">
        <div class="toast-body fw-semibold">${String(message || '')}</div>
        <button type="button" class="btn-close btn-close-white ms-2 me-2" data-bs-dismiss="toast" aria-label="Schließen"></button>
      </div>
    `;

    c.appendChild(el);
    const t = bootstrap.Toast.getOrCreateInstance(el, { delay: 1400 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  }

  function monthLabel(ym){
    try {
      const d = new Date(`${ym}-01T00:00:00`);
      let s = d.toLocaleDateString('de-DE', { month:'long', year:'numeric' });
      return s.charAt(0).toUpperCase() + s.slice(1);
    } catch {
      return ym;
    }
  }

  function findRowByNr(nr){
    const tbody = document.querySelector('#ausgangTable tbody');
    const rows = [...(tbody?.querySelectorAll('tr') || [])];
    return rows.find(tr => {
      const v = (tr.children?.[0]?.dataset?.raw || tr.children?.[0]?.textContent || '').trim();
      return v === nr;
    }) || null;
  }

  function activateMonthIfNeeded(ym){
    if (!ym) return;

    const cur = window.WA_activeMonth || null;
    if (cur === ym) return;

    showMiniToastCenter(`Wechsel zu Monat ${monthLabel(ym)} …`);

    const btn = document.querySelector(`#waMonthAccordion button[data-wa-month="${ym}"]`);
    if (btn) {
      const targetSel = btn.getAttribute('data-bs-target');
      const pane = targetSel ? document.querySelector(targetSel) : null;

      btn.classList.remove('collapsed');
      btn.setAttribute('aria-expanded', 'true');

      if (pane && window.bootstrap?.Collapse) {
        bootstrap.Collapse.getOrCreateInstance(pane, { toggle: false }).show();
      } else {
        btn.click();
      }
    }

    window.WA_activeMonth = ym;
    window.WA_applyFilters?.();
  }

  function jumpToAusgangNr(nr){
    nr = String(nr || '').trim();
    if (!nr) return;

    const targetRow = findRowByNr(nr);
    const ym = targetRow?.dataset?.waMonth || null;

    if (ym) activateMonthIfNeeded(ym);

    const filterSelect = document.getElementById('filterNumber');
    const searchInput  = document.getElementById('searchInput');
    const searchField  = document.getElementById('searchField');

    if (filterSelect) {
      filterSelect.value = nr;
    } else {
      if (searchField) searchField.value = 'ausgang';
      if (searchInput) searchInput.value = nr;
    }

    window.WA_applyFilters?.();

    const rowNow = findRowByNr(nr);
    if (rowNow && rowNow.offsetParent !== null) {
      rowNow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      blinkRow(rowNow);
    } else {
      showMiniToastCenter(`Ausg.-Nr. ${nr} nicht sichtbar (Filter/Monat prüfen).`);
    }
  }

  // global verfügbar machen
  window.WA_jumpToAusgangNr = jumpToAusgangNr;

  // Offene Ausgangsnummern
  document.getElementById('statsOffenList')?.addEventListener('click', (e) => {
    const a = e.target.closest('a.open-ausgang');
    if (!a) return;
    e.preventDefault();
    jumpToAusgangNr(a.dataset.id || '');
  });
})();
//   document.getElementById('statsOffenList')?.addEventListener('click', (e) => {
//     const a = e.target.closest('a.open-ausgang');
//     if (!a) return;
//     e.preventDefault();

//     const nr = (a.dataset.id || '').trim();
//     if (!nr) return;

//     const targetRow = findRowByNr(nr);

//     const ym = targetRow?.dataset?.waMonth || null;
//     if (ym) activateMonthIfNeeded(ym);

//     const filterSelect = document.getElementById('filterNumber');
//     const searchInput  = document.getElementById('searchInput');
//     const searchField  = document.getElementById('searchField');

//     if (filterSelect) {
//       filterSelect.value = nr;
//     } else {
//       if (searchField) searchField.value = 'ausgang';
//       if (searchInput) searchInput.value = nr;
//     }

//     window.WA_applyFilters?.();

//     const rowNow = findRowByNr(nr);
//     if (rowNow && rowNow.offsetParent !== null) {
//       rowNow.scrollIntoView({ behavior: 'smooth', block: 'center' });
//       blinkRow(rowNow);
//     } else {
//       showMiniToastCenter(`Ausg.-Nr. ${nr} nicht sichtbar (Filter/Monat prüfen).`);
//     }
//   });
// })();

// 👇 direkt danach:
window.WA_applyFilters = applyFilter;
window.WA_getCellValue = getCellValue;
window.WA_setCellRaw = setCellRaw;
window.WA_regroupGroups = regroupGroups;
window.WA_computeStats = computeStats;
window.WA_rebuildFilterOptions = rebuildFilterOptions;
window.WA_upsertRowToServer = upsertRowToServer;
window.WA_refreshAttachmentBadges = queueRefreshAllAttachmentBadges;
window.WA_tbody = tbody;
window.WA_filterSelect = filterSelect;
window.WA_searchInput = searchInput;
window.WA_searchField = searchField;
window.WA_btnResetFilter = btnResetFilter;


// Events
searchInput?.addEventListener("input", debounce(applyFilter, 120));

searchField?.addEventListener("change", applyFilter);


  
  function removeBadge(tr) {
    tr.children[0].querySelectorAll(".badge-count").forEach((n) => n.remove());
  }
  function clearRowBorders(tr) {
  tr.classList.remove('saved-standalone','grp','grp-start','grp-end','grp-single');
}

function regroupGroups() {
  const allRows = Array.from(tbody.querySelectorAll('tr'));
  allRows.forEach(tr => {
    clearRowBorders(tr);
    tr.children[0]?.querySelectorAll('.badge-count').forEach(n => n.remove());
    tr.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.remove('d-none'));
  });

  const rows = allRows.filter(tr => tr.offsetParent !== null);

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
      } else {
        group.forEach((r, idx) => {
          r.classList.add('grp');
          if (idx === 0) {
            r.classList.add('grp-start');
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.remove('d-none'));
          } else if (idx === group.length - 1) {
            r.classList.add('grp-end');
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.add('d-none'));
          } else {
            r.querySelectorAll('.action-btn[data-role="group"]').forEach(b => b.classList.add('d-none'));
          }
        });
      }
    }
    i = j;
  }

  applyGroupBadges();
  markOverweightGroups?.();

  document.dispatchEvent(new CustomEvent('wa:rows-ready'));
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

  // --- Autocomplete für Sachnummern ---
// Input-Feld (Spalte 14 / Index 13) automatisch mit Vorschlägen füllen
function attachSachnummerAutocomplete(inputEl, onPick) {
  let menu = document.querySelector("#ac-global-menu");
  if (!menu) {
    menu = document.createElement("div");
    menu.id = "ac-global-menu";
    menu.className = "ac-menu d-none";
    document.body.appendChild(menu);
  }

  let items = [];
  let activeIndex = -1;

  function placeMenu() {
    const r = inputEl.getBoundingClientRect();
    menu.style.left = r.left + window.scrollX + "px";
    menu.style.top = r.bottom + window.scrollY + "px";
    menu.style.width = r.width + "px";
  }

  function openMenu(list) {
    menu.innerHTML = "";
    if (!list.length) { closeMenu(); return; }
    list.slice(0, 50).forEach((it, idx) => {
      const row = document.createElement("div");
      row.className = "ac-item";
      row.innerHTML = `<span class="ac-group">${it.group}</span><span class="ac-code">${it.part}</span>`;
      row.addEventListener("mousedown", (e) => { e.preventDefault(); pick(idx); });
      menu.appendChild(row);
    });
    placeMenu();
    menu.classList.remove("d-none");
    activeIndex = -1;
  }

  function closeMenu() {
    if (!menu.classList.contains("d-none")) {
      menu.classList.add("d-none");
      activeIndex = -1;
    }
  }

  function pick(idx) {
    const it = items[idx];
    if (!it) return;
    inputEl.value = it.part;
    closeMenu();
    onPick?.(it);
  }

  function move(delta) {
    const nodes = [...menu.querySelectorAll(".ac-item")];
    if (!nodes.length) return;
    activeIndex = (activeIndex + delta + nodes.length) % nodes.length;
    nodes.forEach((n) => n.classList.remove("active"));
    nodes[activeIndex].classList.add("active");
    nodes[activeIndex].scrollIntoView({ block: "nearest" });
  }

  inputEl.addEventListener("input", () => {
    const q = normalize(inputEl.value);
    if (!q) { closeMenu(); return; }
    items = PARTS.filter((p) => p.norm.startsWith(q) || p.norm.includes(q));
    openMenu(items);
  });

  inputEl.addEventListener("keydown", (e) => {
    if (menu.classList.contains("d-none")) return;
    if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
    else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
    else if (e.key === "Enter") {
      if (activeIndex >= 0) { e.preventDefault(); pick(activeIndex); }
    } else if (e.key === "Escape") {
      closeMenu();
    }
  });
  document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
    const tr = document.activeElement?.closest?.('tr');
    if (tr && tr.parentElement?.id !== 'ac-global-menu') { // nicht im AC-Menü
      e.preventDefault();
      duplicateRow(tr);
    }
  }
});


  document.addEventListener("click", (e) => {
    if (e.target !== inputEl && !menu.contains(e.target)) closeMenu();
  });
}

function syncRowMonthMeta(tr) {
  const raw = (getCellValue(tr, 3) || '').trim(); // Datumsspalte
  let d = null;

  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    d = parseISODate(raw);
  } else if (/^\d{2}\.\d{2}\.\d{4}$/.test(raw)) {
    const [dd, mm, yyyy] = raw.split('.');
    d = new Date(`${yyyy}-${mm}-${dd}T00:00:00`);
  }

  if (!d || Number.isNaN(d.getTime())) {
    delete tr.dataset.waMonth;
    return;
  }

  tr.dataset.waMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
async function exportGroupToXlsx(clickedTr) {
  // 1) Gruppe ermitteln
  const ausgangNr = getCellValue(clickedTr, 0).trim(); // Spalte A
  if (!ausgangNr) { alert("Keine Ausgangsnummer in der Zeile."); return; }
  const allRows = [...table.querySelectorAll("tbody tr")]
    .filter(tr => (tr.dataset.saved === "1" || tr.dataset.mode === "edit") && tr.offsetParent !== null);

  // ⚠️ hier war der Tippfehler: 'ausgangNr' → 'ausgangNr'
  const groupRows = allRows.filter(tr => getCellValue(tr, 0).trim() === ausgangNr);
  if (!groupRows.length) { alert("Zur ausgangsnummer wurden keine Zeilen gefunden."); return; }

  // 2) Header aus erster Zeile
  const first = groupRows[0];
  const header = {
    ausgangNr,                               // -> #wfNr
    ankunft:   getCellValue(first, 7),       // H -> #ankunft (hh:mm)
    datum:     getCellValue(first, 3),       // D -> #datum (yyyy-mm-dd)
    spedition: getCellValue(first, 6),       // G -> #spedition
    kennz:     getCellValue(first, 4),       // E -> #kennz
    // falls gewünscht: gebucht_von: getCellValue(first, 16),
    beginn:    getCellValue(first, 8),
    ende:      getCellValue(first, 9)
  };

  // 3) Positionszeilen
  const rows = groupRows.map(tr => ({
    ls:   getCellValue(tr, 1),               // B -> LS
    verk: getCellValue(tr, 2),               // C -> Lagergruppe
    sach: getCellValue(tr, 10),              // N -> Sachnummer
    qty:  Number(String(getCellValue(tr, 11)).replace(/\./g,'').replace(',','.')) || 0, // K -> Behälter/KLT
    noLabel: false
  }));
  const sum = rows.reduce((a, r) => a + (r.qty || 0), 0);

  // 4) Übergabe
  const payload = { header, rows, sum };
  const KEY = "waPrintPayload";
  try {
    sessionStorage.setItem(KEY, JSON.stringify(payload));
  } catch {
    window.name = JSON.stringify({ [KEY]: payload });
  }
  window.open("/druck_wa.html", "_blank");
}


// ... ganz unten in warenausgang.js, nach den anderen Inits
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
  exportTableToCSV("warenausgang.csv");
});

// platziert ein Badge "über" der Zeile, genau zwischen zwei Spalten
function placeBetweenCellsBadge(firstRow, colLeft, colRight, badgeEl) {
  const tdL = firstRow?.children?.[colLeft];
  const tdR = firstRow?.children?.[colRight];
  if (!tdL || !tdR) return;

  // Anker relativ im linken TD
  tdL.style.position = tdL.style.position || 'relative';

  // Position relativ zum linken TD ausrechnen
  const rL = tdL.getBoundingClientRect();
  const rR = tdR.getBoundingClientRect();
  const centerX = (rL.right + rR.left) / 2;           // Viewport-Mitte zwischen beiden Zellen
  const leftWithinL = centerX - rL.left;               // px innerhalb des linken TD

  Object.assign(badgeEl.style, {
    position: 'absolute',
    top: '-0.6rem',            // leicht über der Zelle
    left: leftWithinL + 'px',
    transform: 'translate(-50%, -100%)',
    zIndex: '10',              // vor Tabelleninhalt
    pointerEvents: 'auto'      // Tooltip/Title nutzbar
  });

  tdL.appendChild(badgeEl);
}


function exportTableToCSV(filename, { onlySaved = false, onlyVisible = false } = {}) {
  const table = document.getElementById("ausgangTable");
  const rowsOut = [];

  // --- Header: nur .th-lines Text (ohne Sort-Indikator) ---
  if (table.tHead && table.tHead.rows.length) {
    const headerCells = Array.from(table.tHead.rows[0].cells);
    const headerRow = headerCells.map(th => {
      const labelEl = th.querySelector(".th-lines");
      const text = (labelEl ? labelEl.textContent : th.textContent || "")
        .replace(/\s+/g, " ")
        .trim();
      return csvEscape(text);
    }).join(";");
    rowsOut.push(headerRow);
  }

  // --- Body-Zeilen ---
  const bodyRows = Array.from(table.tBodies[0].rows);
  bodyRows.forEach(tr => {
    if (onlySaved && tr.dataset.saved !== "1") return;
    if (onlyVisible && tr.offsetParent === null) return;

    const cols = Array.from(tr.cells).map(td => {
      // Rohwert bevorzugen (sauber ohne Badges/Icons)
      let val = td.dataset?.raw;

      // Falls kein data-raw: Inputs/Selects lesen (falls im Edit-Modus)
      if (val == null) {
        const sel = td.querySelector("select");
        const inp = td.querySelector("input");
        if (sel) val = sel.options[sel.selectedIndex]?.text ?? "";
        else if (inp) val = inp.value ?? "";
      }

      // Sonst Fallback: reiner Text (Badges entfernen)
      if (val == null) {
        const clone = td.cloneNode(true);
        clone.querySelectorAll(".badge-count, .sort-ind").forEach(n => n.remove());
        val = (clone.textContent || "").trim();
      }

      return csvEscape(val);
    });

    rowsOut.push(cols.join(";")); // DE-Excel: Semikolon
  });

  const csvContent = rowsOut.join("\n");
  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
}


/** Header-Spaltenindex der "Ausg.-Nr." ermitteln */
function getausgangColIdx() {
  const ths = Array.from(document.querySelectorAll('#ausgangTable thead th'));
  let idx = ths.findIndex(th => /ausg\.\s*-?\s*nr/i.test(th.textContent.trim()));
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

/** Gruppen neu anwenden: zusammenhängende Blöcke gleicher Ausg.-Nr. */
function reapplyGroups() {
  const table = document.getElementById('ausgangTable');
  if (!table || !table.tBodies.length) return;
  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody.rows);
  const col   = getausgangColIdx();

  // Reset
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

  // Palettenlauf-Badges für sichtbare Reihen erneuern
  regroupGroups();
  
}

function installGroupingObservers() {
  const table = document.getElementById('ausgangTable');
  if (!table || !table.tBodies.length) return;
  const tbody = table.tBodies[0];

  const debounced = debounce(regroupGroups, 20);

  tbody.addEventListener('input',  debounced);
  tbody.addEventListener('change', debounced);

  const mo = new MutationObserver(debounced);
  mo.observe(tbody, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-raw','class'] });

  // Initial anwenden
  regroupGroups();

  window._reapplyGroups = regroupGroups; // falls extern genutzt
}

// --- EINMAL GLOBAL REGISTRIEREN ---
(function initDuplicateShortcut(){
  if (window.__dupShortcutBound) return;
  window.__dupShortcutBound = true;

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
      const tr = document.activeElement?.closest?.('tr');
      const inTable = tr?.closest?.('#ausgangTable');
      if (tr && inTable) {
        e.preventDefault();
        duplicateRow(tr);
      }
    }
  });
})();
function escapeHtml(s){
  return String(s ?? '')
    .replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}



function csvEscape(text) {
  let t = String(text ?? "");
  t = t.replace(/"/g, '""');     // Quotes escapen
  if (/[;\n"]/.test(t)) t = `"${t}"`; // bei Trennzeichen/Zeilenumbruch/Quote einkapseln
  return t;
}
// Baut eine CSV NUR aus den übergebenen <tr>-Zeilen (z.B. Vorwoche)
function buildCsvFromRows(rows) {
  const table = document.getElementById("ausgangTable");
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
function nextausgangsnummer() {
  const nums = [...document.querySelectorAll("#ausgangTable tbody tr")]
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
// --- SAFE REPLACEMENT (komplette Funktion) ---
async function renderAttachmentList(ausgangNr) {
  const list = document.getElementById("attList");
  if (!list) {
    console.warn('#attList fehlt im Modal – bitte <div id="attList"></div> einbauen.');
    return;
  }

  list.innerHTML = "";
  const files = await serverListAttachments(ausgangNr);

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

    const safeName = escapeHtml(f.filename);
    const metaLine = `${escapeHtml(effMime || "Datei")} • ${(f.size_bytes/1024).toFixed(1)} KB • ${new Date(f.uploaded_at).toLocaleString()}`;

    const item = document.createElement("div");
    item.className = "list-group-item d-flex justify-content-between align-items-center";
    item.innerHTML = `
      <div class="me-3 overflow-hidden">
        <div class="fw-semibold text-truncate" title="${safeName}">${safeName}</div>
        <div class="text-muted">${metaLine}</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-outline-secondary act-preview">Vorschau</button>
        <a class="btn btn-sm btn-outline-primary" href="${viewUrl}" target="_blank" rel="noopener">Ansehen</a>
        <a class="btn btn-sm btn-outline-success" href="${viewUrl}" download>Download</a>
        <button class="btn btn-sm btn-outline-danger act-delete">Löschen</button>
      </div>
    `;

    // Vorschau-Button
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

    // Löschen-Button
    item.querySelector(".act-delete")?.addEventListener("click", async () => {
      if (confirm(`„${f.filename}“ wirklich löschen?`)) {
        await serverDeleteAttachment(f.id);
        await renderAttachmentList(ausgangNr);

        // Badge am passenden Reihen-Button aktualisieren
        const tr = [...tbody.querySelectorAll("tr")]
          .find(tr => getCellValue(tr,0).trim() === ausgangNr);
        const paperclipBtn = tr?.querySelector(".action-btn.btn-outline-dark");
        if (tr && paperclipBtn) await refreshAttachmentBadgeForRow(tr, paperclipBtn);
      }
    });

    list.appendChild(item);

    // beim ersten Laden direkt die 1. vorschau-fähige Datei zeigen (falls keine alte Auswahl)
    if (!keepUrl && idx === 0 && (isPreviewable(effMime) || isPreviewable(f.filename))) {
      showPreview(viewUrl, effMime || f.filename, f.filename);
    }
  });

  // bestehende Vorschau beibehalten, falls Datei noch existiert – sonst leeren
  if (keepUrl) {
    const stillThere = files.some(x => `/${x.path_rel}` === keepUrl);
    if (!stillThere) clearPreview();
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



async function openAttachmentModal(ausgangNr, tr, btn) {
  document.getElementById("attModalNr").textContent = ausgangNr;
  await renderAttachmentList(ausgangNr);

  const inp = document.getElementById("attFileInput");
  const printBtn = document.getElementById("attPrintBtn");

  if (inp) {
    inp.value = "";
    inp.onchange = async () => {
      if (inp.files && inp.files.length) {
        await serverUploadAttachments(ausgangNr, inp.files);
        await renderAttachmentList(ausgangNr);
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



async function serverListAttachments(ausgang) {
  try {
    const url = `${API_ATT}?action=list&ausgang=${encodeURIComponent(ausgang)}`;
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

async function serverUploadAttachments(ausgang, fileList) {
  try {
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('ausgang', ausgang);
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

// --- NEU HINZUFÜGEN ---
const _debouncedRefreshAllAttachmentBadges = debounce(refreshAllAttachmentBadges, 300);
function queueRefreshAllAttachmentBadges() {
  _debouncedRefreshAllAttachmentBadges();
}



// nach relevanten Stellen aufrufen:
document.addEventListener("DOMContentLoaded", () => { refreshAllAttachmentBadges().catch(()=>{}); });
// und am Ende von regroupGroups(), applyFilter(), saveRow(), btnAdd-Handler nach dem Einfügen:
(async ()=>{ try { await refreshAllAttachmentBadges(); } catch(e){} })();

{
  const el = document.getElementById("attModal");
  if (el) {
    el.addEventListener("hidden.bs.modal", () => {
      const inp = document.getElementById("attFileInput");
      if (inp) inp.value = "";
      clearPreview(); // Vorschau zurücksetzen
    });
  }
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
  const COL_SPED  = 6, COL_BEH = 12, COL_SACH = 10;
  const TTL_MS    = 24 * 60 * 60 * 1000; // 24h Cache
  const MAX_ROWS  = 50;

  let SPEDS_AC = [];   // [{name, norm}]
  let BEHS_AC  = [];   // [{nummer, lagergruppe?, norm}]
  let PARTS_AC = [];   // [{sachnummer, lagergruppe, norm}]
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
    [SPEDS_AC, BEHS_AC, PARTS_AC] = [
      readCache('spedition') || [], readCache('behaelter') || [], readCache('sachnummer') || []
    ];
    _loaded = true;
    try { SPEDS_AC = await loadSpeds(); } catch(e){ console.warn('Speds load fail', e); }
    try { BEHS_AC  = await loadBehs();  } catch(e){ console.warn('Behs load fail', e); }
    try { PARTS_AC = await loadParts(); } catch(e){ console.warn('Parts load fail', e); }
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
  function attachBehaelterAC(input) {
    attachAutocomplete(input, {
      listFn: () => BEHS_AC,
      valueForScore: (it) => ({ norm: it.norm, rawLower: it.norm, text: it.nummer }),
      renderFn: (it, tokens) => `
        <div class="label"><span class="ac-code">${highlight(it.nummer, tokens)}</span></div>
        <div class="meta">${escapeHtml(it.lagergruppe || '')}</div>`,
      pickFn:  (it) => { input.value = it.nummer; }
    });
  }
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

  // ---------- Zeilenbindung ----------
  function bindRowAC(tr) {
    const spedInp = tr.children[COL_SPED]?.querySelector('input');
    const behInp  = tr.children[COL_BEH ]?.querySelector('input');
    const sachInp = tr.children[COL_SACH]?.querySelector('input');

    if (spedInp && !spedInp.dataset._acSped) { spedInp.dataset._acSped = '1'; attachSpeditionAC(spedInp); }
    if (behInp  && !behInp.dataset._acBeh ) { behInp.dataset._acBeh  = '1'; attachBehaelterAC(behInp);  }
    if (sachInp && !sachInp.dataset._acSach){ sachInp.dataset._acSach= '1'; attachSachnummerAC(sachInp); }
  }
  window.__bindRowAC = bindRowAC;

 

  // vorher (überschattet):
// const tbody = document.getElementById('ausgangTable')?.querySelector('tbody');

// nachher:
const tbodyAC = document.getElementById('ausgangTable')?.querySelector('tbody');
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
    if (n.nodeType === 1 && n.matches('tr') && n.dataset.mode === 'edit') bindRowAC(n);
  }));
});
mo.observe(tbodyAC, { childList: true });

[...tbodyAC.querySelectorAll('tr')].forEach(tr => { if (tr.dataset.mode === 'edit') bindRowAC(tr); });

})();

// ===== Shortcuts & Auto-Save (Zeile) =====
function getFocusedEditRow() {
  const ae = document.activeElement;
  const tr = ae?.closest?.('tr');
  return (tr && tr.dataset.mode === 'edit') ? tr : null;
}


async function upsertRowToServer(tr) {
  const payload = {
    id:              tr.dataset.dbid ? Number(tr.dataset.dbid) : null,
    ausgang_nr:      getCellValue(tr, 0),
    lieferschein:    getCellValue(tr, 1),
    lagergruppe:     getCellValue(tr, 2),
    datum:           getCellValue(tr, 3) || null,
    kennzeichen:     getCellValue(tr, 4),
    land:            getCellValue(tr, 5),
    spedition:       getCellValue(tr, 6),
    ankunft:         getCellValue(tr, 7) || null,
    beginn:          getCellValue(tr, 8) || null,
    ende:            getCellValue(tr, 9) || null,

    sachnummer:      getCellValue(tr, 10),
    behaelter:       Math.trunc(parseNumberInput(getCellValue(tr, 11))),
    behaelternr:     getCellValue(tr, 12),
    zus_behaelter:   Math.trunc(parseNumberInput(getCellValue(tr, 13))),
    brt_gew:         parseNumberInput(getCellValue(tr, 14)),

    gebucht:         getCellValue(tr, 15),
    gebucht_von:     getCellValue(tr, 16),
    empfaenger_code: getEmpfaengerCode(tr) || null,
  };

  const res = await fetch(`${API_WE}?action=upsert`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  });

  const j = await res.json();
  if (!j.ok) throw new Error(j.error || 'save failed');
  if (j.row && j.row.id) tr.dataset.dbid = j.row.id;
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
    regroupGroups();
    rebuildFilterOptions();
    applyFilter();
    computeStats?.();

    if (window.WA_afterCommitRow) {
      await window.WA_afterCommitRow(tr);
    }

    if (tr.dataset.isDup === '1') {
      setTimeout(() => {
        const ausg = getCellValue(tr, 0).trim();

        let next = tr.nextElementSibling;
        while (
          next &&
          getCellValue(next,0).trim() === ausg &&
          (getCellValue(next,1).trim() !== '' || !isVisibleRow(next))
        ) {
          next = next.nextElementSibling;
        }

        if (next && getCellValue(next,0).trim() === ausg && isVisibleRow(next)) {
          if (next.dataset.mode !== 'edit') {
            editRow(next);
            next.dataset.saved = '0';
            setRowMode(next, 'edit');
            const eb = next.querySelector('button.btn-outline-secondary');
            if (eb) { eb.innerHTML = '<i class="bi bi-check2"></i>'; eb.title = 'Speichern'; }
          }
          focusEditor(next, 1);
        } else {
          const clone = duplicateRow(tr, { markAsDup: false });
          focusEditor(clone, 1);
        }
      }, 0);
      delete tr.dataset.isDup;
    }
  } catch (e) {
    alert('Speichern am Server fehlgeschlagen: ' + e.message);
  }
}



// Ctrl/Meta + S -> aktuelle Edit-Zeile speichern
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
    const tr = getFocusedEditRow();
    if (tr) { e.preventDefault(); commitRow(tr); }
  }
});

// Auto-Save wenn Fokus die Zeile verlässt (nicht beim Autocomplete-Menü)
let _rowWithFocus = null;
const _menuEl = () => document.getElementById('ac-global-menu');

tbody.addEventListener('focusin', (e) => {
  const tr = e.target.closest('tr');
  if (tr?.dataset.mode === 'edit') _rowWithFocus = tr;
});

tbody.addEventListener('focusout', () => {
  const tr = _rowWithFocus;
  if (!tr) return;
  setTimeout(() => {
  if (tr?.dataset?.waHold === '1') return;

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


// irgendwo bei den Utils
function normalizeCode(s){
  return String(s||'').toLowerCase().replace(/[\s\-_.\/]/g,'');
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
  // bewusst leer:
  // Bei euch ist die erste Zeile der Ausgangsnummer führend.
  // Es wird nichts automatisch umsortiert.
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
// Monatsfilter Warenausgang (Accordion + Filter) - FIXED
// ======================================================
(function () {
  const DATE_COL_IDX    = 3; // Datum-Spalte im Warenausgang
  const AUSGANG_COL_IDX = 0; // Ausg.-Nr. Spalte

  let activeYm = null;       // "YYYY-MM" oder null = kein Filter
  let _didInit = false;      // verhindert, dass rebuild immer wieder den neuesten Monat erzwingt

  function parseDateString(raw) {
    raw = (raw || "").trim();
    if (!raw) return null;

    let d = null;

    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) d = new Date(raw + "T00:00:00");
    else if (/^\d{2}\.\d{2}\.\d{4}$/.test(raw)) {
      const [dd, mm, yyyy] = raw.split(".");
      d = new Date(`${yyyy}-${mm}-${dd}T00:00:00`);
    } else if (/^\d{2}\.\d{2}\.\d{2}$/.test(raw)) {
      const [dd, mm, yy] = raw.split(".");
      d = new Date(`${2000 + Number(yy)}-${mm}-${dd}T00:00:00`);
    } else {
      d = new Date(raw);
    }

    return (d && !Number.isNaN(d.getTime())) ? d : null;
  }

  function applyMonthFilter(tbody, ym) {
    activeYm = ym;
    window.WA_activeMonth = ym;

    // nutze deine zentrale Filterfunktion
    if (typeof window.WA_applyFilters === "function") {
      window.WA_applyFilters();
      return;
    }

    // Fallback (falls WA_applyFilters nicht existiert)
    Array.from(tbody.rows).forEach(tr => {
      if (!ym) { tr.style.display = ""; return; }
      const rowMonth = tr.dataset.waMonth || "";
      tr.style.display = (!rowMonth || rowMonth === ym) ? "" : "none";
    });
  }

  function rebuildMonthAccordion() {
  const table     = document.getElementById("ausgangTable");
  const accordion = document.getElementById("waMonthAccordion");
  if (!table || !accordion) return;

  const tbody = table.tBodies[0];
  if (!tbody) return;

  const rows = Array.from(tbody.rows);
  const monthsMap = new Map(); // YYYY-MM -> {label, set, count}
  let anyRow = false;

  rows.forEach(tr => {
    anyRow = true;

    const rawDate = (
      window.WA_getEffectiveCellValue?.(tr, DATE_COL_IDX) ||
      tr.children[DATE_COL_IDX]?.dataset?.raw ||
      tr.children[DATE_COL_IDX]?.textContent ||
      ""
    ).trim();

    const d = parseDateString(rawDate);

    if (!d) {
      delete tr.dataset.waMonth;
      return;
    }

    const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    tr.dataset.waMonth = ym;

    const nr = (
      tr.children[AUSGANG_COL_IDX]?.dataset?.raw ||
      tr.children[AUSGANG_COL_IDX]?.textContent ||
      ""
    ).trim();

    if (!nr) return;

    if (!monthsMap.has(ym)) {
      const label = d.toLocaleDateString("de-DE", { month: "long", year: "numeric" });
      monthsMap.set(ym, { label, set: new Set(), count: 0 });
    }

    const info = monthsMap.get(ym);
    info.set.add(nr);
    info.count = info.set.size;
  });

  if (!anyRow) {
    accordion.innerHTML = `
      <div class="alert alert-light mb-0 small">
        Noch keine Warenausgänge vorhanden – Monatsfilter wird aktiv, sobald Daten da sind.
      </div>`;
    return;
  }

  if (!monthsMap.size) {
    accordion.innerHTML = `
      <div class="alert alert-warning mb-0 small">
        Es konnten keine gültigen Datumsangaben aus der Führungszeile gelesen werden.
      </div>`;
    return;
  }

  const months = Array.from(monthsMap.entries()).sort((a, b) => b[0].localeCompare(a[0]));

  if (!_didInit) {
    activeYm = months[0][0];
    _didInit = true;
  }

  if (activeYm && !monthsMap.has(activeYm)) {
    activeYm = months[0][0];
  }

  let html = "";
  months.forEach(([ym, info]) => {
    const collapseId = `waMonthCollapse_${ym}`;
    const headingId  = `waMonthHeading_${ym}`;
    const isActive   = activeYm === ym;

    html += `
      <div class="accordion-item">
        <h2 class="accordion-header" id="${headingId}">
          <button class="accordion-button ${isActive ? "" : "collapsed"} py-1"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#${collapseId}"
                  aria-expanded="${isActive ? "true" : "false"}"
                  aria-controls="${collapseId}"
                  data-wa-month="${ym}">
            <div class="d-flex justify-content-between w-100">
              <span>${info.label}</span>
              <span class="badge bg-secondary ms-2">${info.count}</span>
            </div>
          </button>
        </h2>
        <div id="${collapseId}"
             class="accordion-collapse collapse ${isActive ? "show" : ""}"
             aria-labelledby="${headingId}"
             data-bs-parent="#waMonthAccordion">
          <div class="accordion-body py-2">
            <small class="text-muted">
              Zeigt alle Warenausgangs-Zeilen aus <strong>${info.label}</strong>.
              (${info.count} unterschiedliche Ausgangsnummern)
            </small>
          </div>
        </div>
      </div>`;
  });

  accordion.innerHTML = html;

  window.WA_activeMonth = activeYm;
  applyMonthFilter(tbody, activeYm);
}

  window.WA_rebuildMonthAccordion = rebuildMonthAccordion;

  document.addEventListener("DOMContentLoaded", () => {
    const table     = document.getElementById("ausgangTable");
    const accordion = document.getElementById("waMonthAccordion");
    if (!table || !accordion) return;

    const tbody = table.tBodies[0];
    if (!tbody) return;

    rebuildMonthAccordion();

    // ✅ Observer nur auf Row-Count Änderungen (Reorder ignorieren!)
    let lastCount = tbody.rows.length;
    let t = null;
    const schedule = () => {
      clearTimeout(t);
      t = setTimeout(rebuildMonthAccordion, 80);
    };

    const mo = new MutationObserver(() => {
      const now = tbody.rows.length;
      if (now === lastCount) return;   // <-- Reorder / regroupGroups ignorieren
      lastCount = now;
      schedule();
    });
    mo.observe(tbody, { childList: true });

    // ✅ Statt click: auf Bootstrap Collapse Events reagieren
    accordion.addEventListener('shown.bs.collapse', (ev) => {
  const item = ev.target.closest('.accordion-item');
  const btn  = item?.querySelector('button[data-wa-month]');
  const ym   = btn?.getAttribute('data-wa-month') || null;
  activeYm = ym;
  applyMonthFilter(tbody, activeYm);
  if (typeof computeStats === 'function') computeStats();
  if (typeof rebuildFilterOptions === 'function') rebuildFilterOptions();
});

    accordion.addEventListener('hidden.bs.collapse', (ev) => {
  const item = ev.target.closest('.accordion-item');
  const btn  = item?.querySelector('button[data-wa-month]');
  const ym   = btn?.getAttribute('data-wa-month') || null;

  if (ym && ym === activeYm) {
    activeYm = null;
    applyMonthFilter(tbody, null);
    if (typeof computeStats === 'function') computeStats();
    if (typeof rebuildFilterOptions === 'function') rebuildFilterOptions();
  }
});
  });
})();


(function () {
  function notifyParentCloseMenu() {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage(
        { type: 'workbench:iframe-close-menu' },
        window.location.origin
      );
    }
  }

  document.addEventListener('click', () => {
    notifyParentCloseMenu();
  }, true);

  let lastY = window.scrollY || 0;
  let ticking = false;

  function onScroll() {
    if (ticking) return;
    ticking = true;

    requestAnimationFrame(() => {
      const y = window.scrollY || 0;
      const diff = Math.abs(y - lastY);

      if (diff > 8) {
        notifyParentCloseMenu();
        lastY = y;
      }

      ticking = false;
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
})();
