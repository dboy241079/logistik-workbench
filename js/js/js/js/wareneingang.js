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
  
// ===== IndexedDB für Anhänge =====
const ATT_DB = "wareneingang_files_db";
const ATT_STORE = "files";
let attDbPromise = null;

function attOpenDb() {
  if (attDbPromise) return attDbPromise;
  attDbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(ATT_DB, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(ATT_STORE)) {
        const store = db.createObjectStore(ATT_STORE, { keyPath: "id" });
        store.createIndex("eingang", "eingang", { unique: false });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return attDbPromise;
}

async function attAddFiles(eingang, fileList) {
  if (!eingang) return;
  const db = await attOpenDb();
  const tx = db.transaction(ATT_STORE, "readwrite");
  const store = tx.objectStore(ATT_STORE);
  const now = Date.now();
  for (const f of fileList) {
    const id = `${eingang}::${now}::${crypto.randomUUID?.() || Math.random().toString(36).slice(2)}`;
    await new Promise((res, rej) => {
      const rec = { id, eingang, name: f.name, type: f.type, size: f.size, ts: now, blob: f };
      const r = store.add(rec);
      r.onsuccess = () => res();
      r.onerror = () => rej(r.error);
    });
  }
  await new Promise((res, rej) => { tx.oncomplete = res; tx.onerror = () => rej(tx.error); });
}

async function attList(eingang) {
  const db = await attOpenDb();
  const tx = db.transaction(ATT_STORE, "readonly");
  const store = tx.objectStore(ATT_STORE);
  const idx = store.index("eingang");
  return await new Promise((res, rej) => {
    const out = [];
    const req = idx.openCursor(IDBKeyRange.only(eingang));
    req.onsuccess = () => {
      const cur = req.result;
      if (cur) { out.push(cur.value); cur.continue(); }
      else res(out);
    };
    req.onerror = () => rej(req.error);
  });
}

async function attDelete(id) {
  const db = await attOpenDb();
  const tx = db.transaction(ATT_STORE, "readwrite");
  tx.objectStore(ATT_STORE).delete(id);
  return await new Promise((res, rej) => {
    tx.oncomplete = res; tx.onerror = () => rej(tx.error);
  });
}


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

  const PARTS_RAW = `
X3	03L 117 221 B
X3	03N 117 221
X3	03N 117 279
X3(B)	04C 145 749 A
X3	04C 145 851 A
X3(B)	04E 117 021 K
X3	04E 117 275
X3	04E 145 883 E
X3	04E 145 883 F
G9	04L 103 403 M
G9	04L 103 403 R
X3(B)	05C 145 785 D
X3	05C 145 795 A
W1	0Z1 915 604 K
W1	0Z1 915 604 L
W1	0Z1 915 646 E
`; // TODO: vollständige Liste einsetzen

  const PARTS = PARTS_RAW.split("\n")
    .map(l => l.trim())
    .filter(l => l && !l.startsWith("//"))
    .map(line => {
      const m = line.split(/\s{2,}|\t+/);
      const group = (m[0] || "").trim();
      const part = (m[1] || "").trim();
      return { group, part, norm: normalize(part) };
    })
    .filter(x => x.group && x.part);

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
  // --- Statistik ---
  function parseISODate(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((s || "").trim());
    if (!m) return null;
    return new Date(+m[1], +m[2] - 1, +m[3]);
  }
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

  const sumBySach = new Map();
  const offeneSet = new Set();
  const offeneList = new Map();

  // NEU: Zählziele in fixer Reihenfolge
  const TARGET_GROUPS = ["W1", "X3", "X3(B)", "G9", "B1", "Bauteile", "BM", "Müll"];
  const sumByLagergruppe = new Map(TARGET_GROUPS.map(g => [g, 0]));

  const rows = [...tbody.querySelectorAll("tr")].filter(
    tr => tr.dataset.saved === "1" || tr.dataset.mode === "edit"
  );

   rows.forEach((tr) => {
    const dtText = getCellValue(tr, 3);
    const dt = parseISODate(dtText);
    if (range.start && (!dt || dt < range.start)) return;
    if (range.end && (!dt || dt >= range.end)) return;

    const sach = getCellValue(tr, 13).trim();
    const behaelter = toInt(getCellValue(tr, 10));
    const gebucht = (getCellValue(tr, 15) || "").trim() !== "";

    const eingang = getCellValue(tr, 0).trim();

    // bestehend
    if (sach) sumBySach.set(sach, (sumBySach.get(sach) || 0) + behaelter);
    if (!gebucht && eingang) { offeneSet.add(eingang); offeneList.set(eingang, true); }

    // NEU: Lagergruppe zählen (Spalte 3 / Index 2)
    const lg = getCellValue(tr, 2).trim();
    if (sumByLagergruppe.has(lg)) {
      sumByLagergruppe.set(lg, (sumByLagergruppe.get(lg) || 0) + behaelter);
    }
  });

  // --- (bestehende) Top-Liste Sachnummern ---
  const top = [...sumBySach.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, TOP_LIMIT);

  const tbodyStats = document.getElementById("statsSach");
  const info = document.getElementById("statsSachInfo");
  tbodyStats.innerHTML = "";
  top.forEach(([sach, sum]) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${sach}</td><td class="text-end">${sum}</td>`;
    tbodyStats.appendChild(tr);
  });
  info.textContent = `Zeitraum: ${range.label} — Datengrundlage: gespeicherte/aktuelle Zeilen.`;

  // --- (bestehend) Offene Eingänge ---
  const offCount = offeneSet.size;
  document.getElementById("statsOffenCount").textContent = String(offCount);
  const listDiv = document.getElementById("statsOffenList");
  if (offCount === 0) {
    listDiv.innerHTML = `<span class="text-muted">Alles gebucht – keine offenen Eingangsnummern.</span>`;
  } else {
    const arr = [...offeneList.keys()].sort();
    listDiv.innerHTML = arr
      .map(k => `<a href="#" class="badge bg-warning-subtle text-warning-emphasis me-1 mb-1 open-eingang" data-id="${k}">${k}</a>`)
      .join(" ");
  }

  // NEU: Rendering der Lagergruppen-Karte
  const tbodyGruppen = document.getElementById("statsGruppen");
  const gesamtEl = document.getElementById("statsGruppenGesamt");
  if (tbodyGruppen && gesamtEl) {
    tbodyGruppen.innerHTML = "";
    let gesamt = 0;
    TARGET_GROUPS.forEach(grp => {
      const sum = sumByLagergruppe.get(grp) || 0;
      gesamt += sum;
      const tr = document.createElement("tr");
      tr.innerHTML = `<td>${grp}</td><td class="text-end">${sum}</td>`;
      tbodyGruppen.appendChild(tr);
    });
    gesamtEl.textContent = String(gesamt);
  }
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

  function makeActionGroup(editMode) {
    const frag = document.createDocumentFragment();

    // Bearbeiten / Speichern
    const btnEdit = document.createElement("button");
    btnEdit.type = "button";
    btnEdit.className = "btn btn-outline-secondary btn-sm action-btn me-1";
    btnEdit.innerHTML = editMode
      ? '<i class="bi bi-check2"></i>'
      : '<i class="bi bi-pencil"></i>';
    btnEdit.title = editMode ? "Speichern" : "Bearbeiten";

    // Duplizieren
    const btnCopy = document.createElement("button");
    btnCopy.type = "button";
    btnCopy.className = "btn btn-outline-info btn-sm action-btn me-1";
    btnCopy.innerHTML = '<i class="bi bi-copy"></i>';
    btnCopy.title = "Zeile duplizieren";

  
    // WA-Drucken
    const btnPrint = document.createElement("button");
    btnPrint.type = "button";
    btnPrint.className = "btn btn-outline-primary btn-sm action-btn me-1";
    btnPrint.innerHTML = '<i class="bi bi-printer"></i>';
    btnPrint.title = "WA drucken (Excel)";
    btnPrint.addEventListener("click", (e) => {
      const tr = e.currentTarget.closest("tr");
      exportGroupToXlsx(tr); // eigene Funktion unten
    });
    // Anhänge
const btnFiles = document.createElement("button");
btnFiles.type = "button";
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

    // Listener Edit
    btnEdit.addEventListener("click", (e) => {
      const tr = e.currentTarget.closest("tr");
      const isEditing = tr.dataset.mode === "edit";
      if (isEditing) {
        saveRow(tr);
        tr.dataset.saved = "1";
        setRowMode(tr, "view");
        btnEdit.innerHTML = '<i class="bi bi-pencil"></i>';
        btnEdit.title = "Bearbeiten";
        regroupGroups();
        rebuildFilterOptions();
        applyFilter();
      } else {
        editRow(tr);
        tr.dataset.saved = "0";
        setRowMode(tr, "edit");
        btnEdit.innerHTML = '<i class="bi bi-check2"></i>';
        btnEdit.title = "Speichern";
        clearRowBorders(tr);
        removeBadge(tr);
      }
    });

    // Listener Copy
    btnCopy.addEventListener("click", (e) => {
      const tr = e.currentTarget.closest("tr");
      const wasEditing = tr.dataset.mode === "edit";
      if (wasEditing) {
        saveRow(tr);
        tr.dataset.saved = "1";
        setRowMode(tr, "view");
        const editBtn = tr.querySelector(".bi-check2")?.closest("button");
        if (editBtn) {
          editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
          editBtn.title = "Bearbeiten";
        }
      }
      const clone = document.createElement("tr");
      for (let i = 0; i < 17; i++) {
        const td = document.createElement("td");
        const val = getCellValue(tr, i);
        td.dataset.raw = val;
        td.textContent = val;
        clone.appendChild(td);
      }
      const tdAction = document.createElement("td");
      tdAction.appendChild(makeActionGroup(false));
      clone.appendChild(tdAction);

      tbody.insertBefore(clone, tr.nextSibling);
      setRowMode(clone, "view");
      clone.dataset.saved = "1";
      regroupGroups();
      rebuildFilterOptions();
      applyFilter();
    });

    // Listener Delete
    btnDelete.addEventListener("click", (e) => {
      const tr = e.currentTarget.closest("tr");
      tr.remove();
      regroupGroups();
      rebuildFilterOptions();
      applyFilter();
    });

   frag.appendChild(btnEdit);
    frag.appendChild(btnCopy);
    frag.appendChild(btnPrint);
    frag.appendChild(btnFiles); // <- FEHLTE
    frag.appendChild(btnDelete);
    return frag;
  }
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

    td.innerHTML = `<input type="${def.type}" class="form-control form-control-sm"${extra}${step} value="${val}">`;
  }

  // Kennzeichen uppercase (Index 4)
  const kzInp = tr.children[4]?.querySelector("input");
  kzInp?.addEventListener("input", () => { kzInp.value = kzInp.value.toUpperCase(); });

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

  // Sachnummer → Lagergruppe automatisch (Index 13 -> 2)
  const sachInp = tr.children[13]?.querySelector("input");
  if (sachInp) {
    const setLG = () => {
      const norm = (sachInp.value || "").trim().toLowerCase().replace(/\s+/g,"");
      const hit  = PARTS.find(p => p.norm === norm);
      const lg   = hit ? hit.group : "";
      const lgTd = tr.children[2];
      if (lgTd) lgTd.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(lg)}</span>`;
    };
    sachInp.addEventListener("input", setLG);
    try { attachSachnummerAutocomplete(sachInp, (picked) => {
      const lgTd = tr.children[2];
      if (lgTd) lgTd.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(picked.group)}</span>`;
    }); } catch {}
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

    // Kennzeichen uppercase
    if (i === 4) value = (value || "").toUpperCase();

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
  const sachValNorm = (getCellValue(tr,13) || "").trim().toLowerCase().replace(/\s+/g,"");
  const hit = PARTS.find(p => p.norm === sachValNorm);
  const lg  = hit ? hit.group : "";
  const lgTd = tr.children[2];
  if (lgTd) setCellRaw(lgTd, lg);
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
  if (inpEin && !inpEin.value) inpEin.value = nextEingangsnummer();

  const tdAction = document.createElement("td");
  tdAction.appendChild(makeActionGroup(true));
  tr.appendChild(tdAction);

  tbody.appendChild(tr);
  setRowMode(tr, "edit");
  tr.dataset.saved = "0";

  // Live-Regeln sofort aktivieren
  const kzInp = tr.children[4]?.querySelector("input");
  kzInp?.addEventListener("input", () => { kzInp.value = kzInp.value.toUpperCase(); });

  const landInp = tr.children[5]?.querySelector("input");
  if (landInp) {
    landInp.setAttribute("maxlength","2");
    landInp.setAttribute("inputmode","text");
    landInp.setAttribute("pattern","[A-Za-z]{2}");
    landInp.addEventListener("input", () => {
      landInp.value = landInp.value.replace(/[^A-Za-z]/g,"").toUpperCase().slice(0,2);
    });
  }

  const sachInp = tr.children[13]?.querySelector("input");
  if (sachInp) {
    const setLG = () => {
      const norm = (sachInp.value || "").trim().toLowerCase().replace(/\s+/g,"");
      const hit  = PARTS.find(p => p.norm === norm);
      const lg   = hit ? hit.group : "";
      const lgTd = tr.children[2];
      if (lgTd) lgTd.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(lg)}</span>`;
    };
    sachInp.addEventListener("input", setLG);
    try { attachSachnummerAutocomplete(sachInp, (picked) => {
      const lgTd = tr.children[2];
      if (lgTd) lgTd.innerHTML = `<span class="form-control-plaintext">${sanitizeAttr(picked.group)}</span>`;
    }); } catch {}
  }

  tr.querySelector("input,select")?.focus();

  rebuildFilterOptions();
  applyFilter();
  computeStats();
});


  // --- Filter + Suche ---
  filterSelect.addEventListener("change", applyFilter);
  searchInput.addEventListener("input", debounce(applyFilter, 120));
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

  function rowMatchesSearch(tr, needle, allCols) {
    if (!needle) return true;
    const n = needle.toLowerCase();
    if (allCols) {
      for (let i = 0; i < 17; i++) {
        const val = getCellValue(tr, i).toLowerCase();
        if (val.includes(n)) return true;
      }
      return false;
    } else {
      return getCellValue(tr, 0).toLowerCase().includes(n);
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
  const selected = filterSelect.value;
  const query = searchInput.value.trim();
  const fieldKey = searchField?.value || "eingang";

  Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
    const key = getCellValue(tr, 0);
    const matchDropdown = !selected || key === selected;
    const matchSearch   = rowMatchesSearch(tr, query, fieldKey);
    tr.style.display = (matchDropdown && matchSearch) ? "" : "none";
  });

  regroupGroups();
  computeStats();
}

// Events
searchInput.addEventListener("input", debounce(applyFilter, 120));
searchField?.addEventListener("change", applyFilter);


  // --- Gruppen / Borders ---
  function clearRowBorders(tr) {
    tr.classList.remove("saved-standalone", "grp", "grp-start", "grp-end");
  }
  function removeBadge(tr) {
    tr.children[0].querySelectorAll(".badge-count").forEach((n) => n.remove());
  }
  function regroupGroups() {
    Array.from(tbody.querySelectorAll("tr")).forEach((tr) => {
      clearRowBorders(tr);
      removeBadge(tr);
    });

    const visibleSaved = Array.from(tbody.querySelectorAll("tr")).filter(
      (tr) => tr.dataset.saved === "1" && tr.offsetParent !== null
    );

    const groups = new Map();
    visibleSaved.forEach((tr) => {
      const key = getCellValue(tr, 0).trim();
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(tr);
    });

    groups.forEach((list) => {
      if (list.length <= 1) {
        list[0].classList.add("saved-standalone");
      } else {
        list.forEach((tr, idx) => {
          tr.classList.add("grp");
          if (idx === 0) {
            tr.classList.add("grp-start");
            const td0 = tr.children[0];
            const count = list.length;
            const badge = document.createElement("span");
            badge.className = "badge bg-primary ms-2 badge-count";
            badge.textContent = `x${count}`;
            td0.appendChild(badge);
          }
          if (idx === list.length - 1) tr.classList.add("grp-end");
        });
      }
    });
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

  document.addEventListener("click", (e) => {
    if (e.target !== inputEl && !menu.contains(e.target)) closeMenu();
  });
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
  const first = groupRows[0];
  const header = {
    eingangNr,                                 // -> #wfNr
    ankunft:  getCellValue(first, 7),          // H -> #ankunft (hh:mm)
    datum:    getCellValue(first, 3),          // D -> #datum (yyyy-mm-dd)
    spedition:getCellValue(first, 6),          // G -> #spedition
    kennz:    getCellValue(first, 4),          // E -> #kennz
    // restliche Felder lässt du leer/optional
    lieferungDurch: "" ,
    beginn:   getCellValue(first, 8),          // optional
    ende:     getCellValue(first, 9)           // optional
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
document.getElementById("btnExportCsv").addEventListener("click", () => {
  exportTableToCSV("wareneingang.csv");
});

document.getElementById("btnExportCsv")?.addEventListener("click", () => {
  exportTableToCSV("wareneingang.csv");
});

function exportTableToCSV(filename, { onlySaved = false, onlyVisible = false } = {}) {
  const table = document.getElementById("eingangTable");
  const rowsOut = [];

  // --- Header: nur .th-lines Text (ohne Sort-Indikator) ---
  if (table.tHead && table.tHead.rows.length) {
    const headerCells = Array.from(table.tHead.rows[0].cells);
    const headerRow = headerCells.map(th => {
      const labelEl = th.querySelector(".th-lines");
      const text = (labelEl ? labelEl.textContent : th.textContent || "")
        .replace(/\s+/g, " ") // Mehrfachspaces homogenisieren
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
// ===== Modal-Logik für Anhänge =====
async function openAttachmentModal(eingangNr, tr, btn) {
  // Titel setzen
  document.getElementById("attModalNr").textContent = eingangNr;

  // Liste rendern
  await renderAttachmentList(eingangNr);

  // Fileinput neu binden (jede Öffnung frisch)
  const inp = document.getElementById("attFileInput");
  inp.value = "";
  inp.onchange = async () => {
    if (inp.files && inp.files.length) {
      await attAddFiles(eingangNr, inp.files);
      await renderAttachmentList(eingangNr);
      await refreshAttachmentBadgeForRow(tr, btn);
    }
  };

  // Modal zeigen (Bootstrap 5)
  let modal;
  if (window.bootstrap?.Modal) {
    modal = bootstrap.Modal.getOrCreateInstance(document.getElementById("attModal"));
    modal.show();
  } else {
    // Fallback: simple Anzeige
    document.getElementById("attModal").style.display = "block";
  }
}

async function renderAttachmentList(eingangNr) {
  const list = document.getElementById("attList");
  list.innerHTML = "";
  const files = await attList(eingangNr);
  if (!files.length) {
    list.innerHTML = '<div class="text-muted">Keine Anhänge vorhanden.</div>';
    return;
  }
  files
    .sort((a,b) => b.ts - a.ts)
    .forEach(f => {
      const aUrl = URL.createObjectURL(f.blob);
      const item = document.createElement("div");
      item.className = "list-group-item d-flex justify-content-between align-items-center";
      item.innerHTML = `
        <div class="me-3">
          <div class="fw-semibold">${f.name}</div>
          <div class="text-muted">${f.type || "Datei"} • ${(f.size/1024).toFixed(1)} KB • ${new Date(f.ts).toLocaleString()}</div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-primary" href="${aUrl}" target="_blank" rel="noopener">Ansehen</a>
          <a class="btn btn-sm btn-outline-success" href="${aUrl}" download="${f.name}">Download</a>
          <button class="btn btn-sm btn-outline-danger">Löschen</button>
        </div>
      `;
      item.querySelector("button.btn-outline-danger").addEventListener("click", async () => {
        if (confirm(`„${f.name}“ wirklich löschen?`)) {
          await attDelete(f.id);
          await renderAttachmentList(eingangNr);
          // Badge evtl. in Tabelle aktualisieren
          const tr = [...tbody.querySelectorAll("tr")].find(tr => getCellValue(tr,0).trim() === eingangNr);
          const btn = tr?.querySelector(".action-btn.btn-outline-dark");
          if (tr && btn) await refreshAttachmentBadgeForRow(tr, btn);
        }
      });
      list.appendChild(item);
    });
}

// Badge mit Anzahl an den Paperclip-Button hängen
async function refreshAttachmentBadgeForRow(tr, btn) {
  const nr = getCellValue(tr, 0).trim();
  const files = nr ? await attList(nr) : [];
  // alte Badge entfernen
  btn.querySelector(".att-badge")?.remove();
  if (files.length) {
    const b = document.createElement("span");
    b.className = "att-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark";
    b.textContent = files.length;
    btn.appendChild(b);
  }
}

// beim Neuaufbau der Gruppen/Ansicht die Badges updaten
async function refreshAllAttachmentBadges() {
  const rows = [...tbody.querySelectorAll("tr")];
  for (const tr of rows) {
    const btn = tr.querySelector(".action-btn.btn-outline-dark"); // unser Paperclip
    if (btn) await refreshAttachmentBadgeForRow(tr, btn);
  }
}

// nach relevanten Stellen aufrufen:
document.addEventListener("DOMContentLoaded", () => { refreshAllAttachmentBadges().catch(()=>{}); });
// und am Ende von regroupGroups(), applyFilter(), saveRow(), btnAdd-Handler nach dem Einfügen:
(async ()=>{ try { await refreshAllAttachmentBadges(); } catch(e){} })();

const el = document.getElementById("attModal");
el.addEventListener("hidden.bs.modal", () => {
  const inp = document.getElementById("attFileInput");
  if (inp) inp.value = "";
  // Optional: falls du URL.createObjectURL nutzt, hier URLs wieder revoke’n.
});


})();
