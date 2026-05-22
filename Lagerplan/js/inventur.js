/* inventur.js (mit Admin-Schalter AN/AUS)
   - Inventur wird nur aktiv, wenn inventory_cfg_api.php active=true liefert
   - AUS => alles Inventur-UI komplett ausgeblendet, keine Listener gebunden
*/

(() => {
  "use strict";

  const INV_CFG_API = "/Lagerplan/inventory_cfg_api.php";
  const ROWS_API    = "/Lagerplan/inventory_rows_api.php";
  const STATUS_API = "/Lagerplan/inventory_status_api.php";

  let invCfg = null;
  let activeRowsSet = null;   // Set("47","48",...)
  let selectedLGs = [];       // ["W1","G9"]
  let invHall = null;
  let invDay  = null;

  if (!(window.__INV_DONE_ROWS__ instanceof Set)) window.__INV_DONE_ROWS__ = new Set();


  function localYMD(d = new Date()) {
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  async function fetchInvCfg(){
    const res = await fetch(INV_CFG_API, { credentials:"include", cache:"no-store" });
    const js = await res.json().catch(() => null);
    if (!js || !js.ok) throw new Error("cfg_failed");
    return js;
  }

  async function fetchRowsWithItems(halle, lgsArr){
    const url = new URL(ROWS_API, location.origin);
    url.searchParams.set("halle", halle || "");
    url.searchParams.set("lgs", (lgsArr || []).join(","));

    const res = await fetch(url.toString(), { credentials:"include", cache:"no-store" });
    const js = await res.json().catch(() => null);
    if (!js || !js.ok) throw new Error("rows_failed");
    return new Set((js.rows || []).map(String));
  }

  function disableInventurUI() {
    document.body.classList.add("inv-disabled");

    if (!document.getElementById("invDisabledStyles")) {
      const s = document.createElement("style");
      s.id = "invDisabledStyles";
      s.textContent = `
        body.inv-disabled [data-inv-panel="1"]{ display:none !important; }
        body.inv-disabled .inv-check,
        body.inv-disabled .inv-status,
        body.inv-disabled [data-inv-label-row],
        body.inv-disabled #btnInvReset,
        body.inv-disabled #btnInvExportWord,
        body.inv-disabled #btnInvExportXlsx,
        body.inv-disabled #btnInvMode,
        body.inv-disabled #invProgressBadge,
        body.inv-disabled #invProgressBar,
        body.inv-disabled .inv-live-only { display:none !important; }
      `;
      document.head.appendChild(s);
    }

    // alle Reihen neutralisieren
    document.querySelectorAll(".row-toolbar").forEach(tb => {
      tb.classList.remove("inv-done", "inv-both-ok");
      tb.querySelector(".inv-live-only")?.classList.add("d-none");
      tb.querySelector('[data-inv-label-row]')?.classList.add("d-none");
      const sw = tb.querySelector(".inv-check")?.closest(".form-check");
      if (sw) sw.classList.add("d-none");
    });
  }

function updateInvHeaderText() {
  const badge  = document.getElementById("invProgressBadge");
  const header = badge?.closest(".card-header");
  const small  = header?.querySelector(".text-muted.small");
  if (!small) return;

  const lgTxt  = selectedLGs.length ? selectedLGs.join(", ") : "—";
  const dayTxt = invDay ? ` · Tag: ${invDay}` : "";

  small.textContent = `LG: ${lgTxt} · Reihen markieren → Etiketten/Export${dayTxt}`;
}


  // -------------------------
  // Inventur State (localStorage pro Tag/Halle/Zone)
  // -------------------------
  const invKey = () => {
    const hall = window.currentHall || "H3";
    const zone = window.currentZone || "W1";
    const day  = localYMD();
    return `inv:${hall}:${zone}:${day}`;
  };

  const state = { rows: new Set() };

  function loadInv() {
    try {
      const raw = localStorage.getItem(invKey());
      if (!raw) return;
      const data = JSON.parse(raw);
      if (Array.isArray(data.rows)) state.rows = new Set(data.rows.map(String));
    } catch (_) {}
  }

  function saveInv() {
    try {
      localStorage.setItem(invKey(), JSON.stringify({ rows: [...state.rows] }));
    } catch (_) {}
  }

 

  function checkedRowsInUI() {
    return [...document.querySelectorAll('.inv-check[data-inv-row]:checked')]
      .map(cb => String(cb.dataset.invRow));
  }

  function updateProgressUI() {
    const all = allRowsInUI();
    const checked = checkedRowsInUI();

    const badge = document.getElementById("invProgressBadge");
    const bar   = document.getElementById("invProgressBar");

    const total = all.length || 0;
    const done  = checked.length || 0;
    const pct   = total ? Math.round((done / total) * 100) : 0;

    if (badge) badge.textContent = `${done}/${total} geprüft`;
    if (bar) bar.style.width = `${pct}%`;
  }

  function markToolbar(row, on) {
    const tb = document.querySelector(`.row-toolbar[data-row="${CSS.escape(String(row))}"]`);
    if (!tb) return;
    tb.classList.toggle("inv-done", !!on);
  }

   function allRowsInUI() {
    const rows = [...document.querySelectorAll(".row-toolbar[data-row]")]
      .map(x => String(x.dataset.row));
    const uniq = [...new Set(rows)].sort((a,b)=>parseInt(a,10)-parseInt(b,10));

    // ✅ nur betroffene Reihen zählen
    if (activeRowsSet instanceof Set) {
      return uniq.filter(r => activeRowsSet.has(r));
    }
    return uniq;
  }

function refreshInventoryUI() {
  const doneSet = (window.__INV_DONE_ROWS__ instanceof Set)
    ? window.__INV_DONE_ROWS__
    : new Set();

  // 1) Rows optisch an/aus
  document.querySelectorAll(".row-toolbar[data-row]").forEach(tb => {
    const row = String(tb.dataset.row || "").trim();
    const isActiveRow = (activeRowsSet instanceof Set) ? activeRowsSet.has(row) : false;

    const sw       = tb.querySelector(".inv-check")?.closest(".form-check");
    const live     = tb.querySelector(".inv-live-only");
    const labelBtn = tb.querySelector('[data-inv-label-row]');
    const cb       = tb.querySelector('.inv-check[data-inv-row]');

    if (!isActiveRow) {
      tb.classList.remove("inv-done", "inv-both-ok");
      if (sw) sw.classList.add("d-none");
      if (live) live.classList.add("d-none");
      if (labelBtn) labelBtn.classList.add("d-none");
      if (cb) cb.checked = false;
      return;
    }

    // Inventur AN (nur für betroffene Reihen)
    if (sw) sw.classList.remove("d-none");
    if (labelBtn) labelBtn.classList.remove("d-none");
    if (live) live.classList.remove("d-none");

    // ✅ Switch + inv-done = nur wenn COUNT UND CHECK vorhanden (doneSet)
    const on = doneSet.has(row);
    if (cb) cb.checked = on;
    tb.classList.toggle("inv-done", on);
  });

  // 2) Progress nur für aktive Reihen (Intersection activeRowsSet ∩ doneSet)
  const badge = document.getElementById("invProgressBadge");
  const bar   = document.getElementById("invProgressBar");

  const total = (activeRowsSet instanceof Set) ? activeRowsSet.size : 0;

  let done = 0;
  if (activeRowsSet instanceof Set) {
    activeRowsSet.forEach(r => { if (doneSet.has(String(r))) done++; });
  }

  const pct = total ? Math.round((done / total) * 100) : 0;
  if (badge) badge.textContent = `${done}/${total} geprüft`;
  if (bar) bar.style.width = `${pct}%`;
}

window.refreshInventoryUI = refreshInventoryUI;

  function ensureInvStyles() {
    if (document.getElementById("invStyles")) return;
    const s = document.createElement("style");
    s.id = "invStyles";
    s.textContent = `
  .row-toolbar.inv-done { background: #ecfdf5 !important; }
  .row-toolbar.inv-done .fw-semibold { color:#065f46 !important; }
  .row-toolbar.inv-both-ok { outline: 2px solid #86efac; outline-offset: 2px; }

  .inv-status { display:inline-flex; gap:6px; align-items:center; }
  .inv-b { font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; border:1px solid transparent; }
  .inv-b-ok   { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
  .inv-b-warn { background:#fff7ed; color:#9a3412; border-color:#fdba74; }
  .inv-b-miss { background:#f1f5f9; color:#334155; border-color:#cbd5e1; }
  .inv-check { pointer-events: none; }
  .inv-check { cursor: default; }


`;

    document.head.appendChild(s);
  }

  // -------------------------
  // Export: Word + XLSX
  // -------------------------
  async function exportInvWordLabels() {
    const rows = checkedRowsInUI().map(r => ({
      row: r,
      label: (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`
    }));

    if (!rows.length) return alert("Keine Reihen markiert.");

    const payload = {
      halle: window.currentHall || "H3",
      zone:  window.currentZone || "W1",
      user:  window.currentUserName || "",
      stamp: new Date().toLocaleString("de-DE"),
      rows
    };

    const res = await fetch("/Lagerplan/inventory_export_word.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(payload)
    });

    if (!res.ok) {
      const t = await res.text().catch(()=> "");
      return alert("Export fehlgeschlagen: " + res.status + "\n" + t.slice(0,200));
    }

    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);

    const a = document.createElement("a");
    a.href = url;
    a.download = `inventur_etiketten_${payload.halle}_${payload.zone}_${localYMD()}.doc`;
    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function exportInvXlsxList() {
    if (!window.XLSX) return alert("XLSX Library fehlt (Script-Tag prüfen).");

    const hall = window.currentHall || "H3";
    const zone = window.currentZone || "W1";
    const stamp = new Date().toLocaleString("de-DE");
    const user = window.currentUserName || "";

    const rows = checkedRowsInUI().map(r => ({
      Halle: hall,
      Zone: zone,
      Reihe: r,
      Label: (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`,
      Datum: stamp,
      User: user
    }));

    if (!rows.length) return alert("Keine Reihen markiert.");

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, "Inventur");

    XLSX.writeFile(wb, `inventur_${hall}_${zone}_${localYMD()}.xlsx`);
  }

  // -------------------------
  // A4 Labels + QR
  // -------------------------
  function invGetMarkedRows() {
    return Array.from(document.querySelectorAll('.inv-check[data-inv-row]'))
      .filter(ch => ch.checked)
      .map(ch => String(ch.dataset.invRow || "").trim())
      .filter(Boolean)
      .sort((a,b) => parseInt(a,10)-parseInt(b,10));
  }

  function invQrPayload({ type, hall, zone, row }) {
  // Ziel fürs QR (kannst du später ändern)
  const base = window.__INV_SCAN_BASE__ || location.origin;
  const endpoint = window.__INV_QR_ENDPOINT__ || "/inventur_scan.php";

  return `${base}${endpoint}` +
    `?mode=${encodeURIComponent(type)}` +
    `&halle=${encodeURIComponent(hall)}` +
    `&zone=${encodeURIComponent(zone)}` +
    `&reihe=${encodeURIComponent(row)}`;
}

function invPrintLabelsA4(rows) {
  const hall = window.currentHall || "H3";
  const zone = window.currentZone || "W1";
  const user = window.currentUserName || window.currentUser || "—";
  const dt   = new Date().toLocaleString("de-DE");

  const titleForRow = (r) =>
    (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`;

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, m => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
  }[m]));

  if (!rows || !rows.length) return alert("Keine Reihen markiert.");

  const pages = rows.map(r => {
    const qrCount = invQrPayload({ type:"COUNT", hall, zone, row:r });
    const qrCheck = invQrPayload({ type:"CHECK", hall, zone, row:r });

    return `
    <section class="page">
      <div class="box">
        <div class="head">
          <div class="title">${esc(titleForRow(r))}</div>
          <div class="meta">
            Halle: <b>${esc(hall)}</b> · Zone: <b>${esc(zone)}</b><br>
            Datum: <b>${esc(dt)}</b><br>
            User: <b>${esc(user)}</b><br>
            Reihe: <b>${esc(r)}</b>
          </div>
        </div>

        <div class="hint">
          Inventur: Reihe vollständig zählen → QR „ZÄHLEN“ scannen → danach prüfen → QR „PRÜFEN“ scannen.
        </div>

        <div class="qrWrap">
          <div class="qrBox">
            <div class="qrLabel">ZÄHLEN</div>
            <canvas class="qrCanvas" data-qr="${esc(qrCount)}" width="220" height="220"></canvas>
            <div class="qrText">${esc(qrCount)}</div>
          </div>

          <div class="qrBox">
            <div class="qrLabel">PRÜFEN</div>
            <canvas class="qrCanvas" data-qr="${esc(qrCheck)}" width="220" height="220"></canvas>
            <div class="qrText">${esc(qrCheck)}</div>
          </div>
        </div>

        <div class="signRow">
          <div class="sign">Gezählt von (Unterschrift)<div class="line"></div></div>
          <div class="sign">Geprüft von (Unterschrift)<div class="line"></div></div>
        </div>
      </div>
    </section>`;
  }).join("");

  const w = window.open("", "_blank", "width=980,height=780");
  if (!w) return alert("Popup blockiert. Bitte Popups erlauben (für Etikett-Druck).");

  w.document.open();
  w.document.write(`<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Inventur Etiketten (A4)</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"><\/script>
  <style>
    @page { size: A4; margin: 16mm; }
    body { margin:0; font-family: Arial, sans-serif; color:#111; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .box { border:2px solid #000; border-radius:12px; padding:12mm;
           min-height: calc(297mm - 32mm); box-sizing:border-box;
           display:flex; flex-direction:column; gap:8mm; }
    .head { display:flex; justify-content:space-between; gap:12mm; }
    .title { font-size:28pt; font-weight:800; line-height:1.05; }
    .meta { font-size:12pt; text-align:right; white-space:nowrap; }
    .hint { font-size:12pt; color:#222; }

    .qrWrap{ display:flex; justify-content:center; gap:18mm; margin:2mm 0; }
    .qrBox{ text-align:center; }
    .qrLabel{ font-size:14pt; font-weight:800; margin-bottom:4mm; letter-spacing:.5px; }
    .qrCanvas{ border:2px solid #000; border-radius:10px; padding:6px; background:#fff; }
    .qrText{ margin-top:3mm; font-size:10pt; color:#333; white-space: nowrap; }

    .signRow{ display:grid; grid-template-columns:1fr 1fr; gap:14mm; margin-top:auto; }
    .line{ border-bottom:2px solid #000; margin-top:18mm; }
  </style>
</head>
<body>
  ${pages}
  <script>
    (function(){
      function renderQr(){
        document.querySelectorAll('canvas.qrCanvas[data-qr]').forEach(function(c){
          var val = c.getAttribute('data-qr') || '';
          try {
            new QRious({ element: c, value: val, size: 220, level: 'M' });
          } catch(e) {
            var img = document.createElement('img');
            img.width = 220; img.height = 220;
            img.style.border = '2px solid #000';
            img.style.borderRadius = '10px';
            img.style.padding = '6px';
            img.style.background = '#fff';
            img.src = 'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=' + encodeURIComponent(val);
            c.replaceWith(img);
          }
        });
      }
      window.onload = function(){
        renderQr();
        setTimeout(function(){
          window.print();
          window.onafterprint = function(){ window.close(); };
        }, 250);
      };
    })();
  <\/script>
</body>
</html>`);
  w.document.close();
}

// wichtig: global sichtbar lassen (falls du Delegation nutzt)
window.invPrintLabelsA4 = invPrintLabelsA4;

async function fetchStatusMap() {
  const hall = window.currentHall || "H3";
  const zonesToFetch = (Array.isArray(selectedLGs) && selectedLGs.length)
    ? selectedLGs
    : [window.currentZone || "W1"];

  async function fetchForDay(dayStr) {
    const map = new Map();

    for (const z of zonesToFetch) {
      const url = new URL(STATUS_API, location.origin);
      url.searchParams.set("action", "list");
      url.searchParams.set("halle", hall);
      url.searchParams.set("zone", z);
      url.searchParams.set("day", dayStr);

      const res = await fetch(url.toString(), { credentials: "include", cache: "no-store" });
      const js  = await res.json().catch(() => ({}));
      if (!res.ok || !js.ok) continue;

      (js.items || []).forEach(it => {
        const r = String(it.reihe ?? it.row ?? "").trim();
        if (r) map.set(r, it);
      });
    }

    return map;
  }

  for (let back = 0; back <= 7; back++) {
    const d = new Date();
    d.setDate(d.getDate() - back);
    const dayStr = localYMD(d);

    const map = await fetchForDay(dayStr);
    if (map.size) {
      invDay = dayStr;
      updateInvHeaderText(); // Header direkt aktualisieren
      return map;
    }
  }

  invDay = localYMD();
  updateInvHeaderText();
  return new Map();
}




// -------------------------
// Status Badges (COUNT/CHECK)
// -------------------------
function num(v) {
  if (v === null || v === undefined || v === "") return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function badgeHtml(label, state, val, soll) {
  const cls =
    state === "ok"   ? "inv-b-ok" :
    state === "warn" ? "inv-b-warn" :
                       "inv-b-miss";

  const mid = (val != null && soll != null) ? `${val}/${soll}` : "—";
  const icon = state === "ok" ? "✅" : (state === "warn" ? "⚠️" : "");

  return `<span class="inv-b ${cls}">${label} ${mid}${icon ? " " + icon : ""}</span>`;
}

function applyRowBadges(row, st) {
  const host = document.querySelector(
    `.inv-status[data-inv-status-row="${CSS.escape(String(row))}"]`
  );
  if (!host) return;

  const soll  = num(st?.soll_menge);
  const count = num(st?.count_menge);
  const check = num(st?.check_menge);
  const status = String(st?.status ?? "").toLowerCase();

  const countDone = (count != null);
  const checkDone = (check != null);
  const bothDone  = countDone && checkDone;      // ✅ Switch/Progress
  const isOk      = (status === "ok");           // optional fürs grüne Highlight

  const countState = (!countDone || soll == null) ? "miss" : (count === soll ? "ok" : "warn");
  const checkState = (!checkDone || soll == null) ? "miss" : (check === soll ? "ok" : "warn");

  host.innerHTML =
    badgeHtml("COUNT", countState, count, soll) +
    badgeHtml("CHECK", checkState, check, soll);

  const tb = host.closest(".row-toolbar");
  const cb = tb ? tb.querySelector('.inv-check[data-inv-row]') : null; // ✅ HIER definieren!

  if (tb) {
    tb.classList.toggle("inv-both-ok", isOk);    // optional
    tb.classList.toggle("inv-done", bothDone);  // ✅ fertig sobald COUNT+CHECK vorhanden
  }
  if (cb) cb.checked = bothDone;

  if (!(window.__INV_DONE_ROWS__ instanceof Set)) window.__INV_DONE_ROWS__ = new Set();
  if (bothDone) window.__INV_DONE_ROWS__.add(String(row));
  else window.__INV_DONE_ROWS__.delete(String(row));
}


async function refreshInvBadges() {
  try {
    const map = await fetchStatusMap();

    document.querySelectorAll(".row-toolbar[data-row]").forEach(tb => {
      const row = String(tb.dataset.row || "").trim();
      if (activeRowsSet instanceof Set && !activeRowsSet.has(row)) return;

      applyRowBadges(row, map.get(row) || null);
    });

    window.refreshInventoryUI?.();
  } catch (e) {
    console.error("[Inventur] refreshInvBadges failed:", e);
  }
}


  // -------------------------
  // Inventur Mode (optional)
  // -------------------------
  function initInvMode() {
    if (!document.getElementById("invModeStyles")) {
      const s = document.createElement("style");
      s.id = "invModeStyles";
      s.textContent = `
        body.inv-mode [data-inv-hide="1"] { display:none !important; }
        body.inv-mode #planViewport { height: calc(100vh - 120px); }
      `;
      document.head.appendChild(s);
    }

    function setInvMode(on){
      document.body.classList.toggle("inv-mode", !!on);
      localStorage.setItem("inv_mode", on ? "1" : "0");
    }

    document.getElementById("btnInvMode")?.addEventListener("click", () => {
      setInvMode(!document.body.classList.contains("inv-mode"));
    });

    setInvMode(localStorage.getItem("inv_mode") === "1");
  }

  // -------------------------
  // setInvLive helper
  // -------------------------
  window.setInvLive = function(on){
    document.body.classList.toggle("inv-live", !!on);
    document.querySelectorAll(".row-toolbar .inv-live-only").forEach(el => {
      el.classList.toggle("d-none", !on);
    });
    window.refreshInvBadges?.();
  };

 // =========================
  // INIT – HIER ist der entscheidende Teil
  // =========================
async function init() {
  const hall = window.currentHall || "H3";

  try {
    invCfg = await fetchInvCfg();
    invHall = window.currentHall || "H3";
    invDay  = invCfg.day || localYMD();   // wenn du day noch nicht in cfg hast, ist das zumindest stabil

console.log("[Inventur] invHall/invDay", invHall, invDay, "selectedLGs", selectedLGs);

  } catch (e) {
    console.error("[Inventur] cfg_api failed", e);
    disableInventurUI();
    return;
  }

  // Inventur aus -> UI weg + Poll stoppen
  if (!invCfg.active) {
    disableInventurUI();
    if (window.__INV_STATUS_TIMER__) {
      clearInterval(window.__INV_STATUS_TIMER__);
      window.__INV_STATUS_TIMER__ = null;
    }
    return;
  }

  ensureInvStyles();
  bindInvEventsOnce();

  // ✅ State laden, damit Progress stimmt
  loadInv();
  if (!(window.__INV_STATE_ROWS__ instanceof Set)) window.__INV_STATE_ROWS__ = new Set();
  window.__INV_STATE_ROWS__ = new Set([...state.rows]);

  selectedLGs = Array.isArray(invCfg.zones) ? invCfg.zones : [];
  updateInvHeaderText();

  // aktive Reihen bestimmen
  if (!selectedLGs.length) {
    activeRowsSet = new Set(); // keine LG gewählt => 0 Reihen
  } else {
    try {
      activeRowsSet = await fetchRowsWithItems(hall, selectedLGs);
    } catch (e) {
      console.error("[Inventur] rows_api failed", e);
      activeRowsSet = new Set(); // 0 Reihen aktiv statt „alle“
    }
  }

  refreshInventoryUI();

window.refreshInvBadges = refreshInvBadges;
setTimeout(refreshInvBadges, 200);
if (!window.__INV_STATUS_TIMER__) {
  window.__INV_STATUS_TIMER__ = setInterval(refreshInvBadges, 12000);
}

}

init();



})();
function bindInvEventsOnce() {
  if (window.__INV_EVENTS_BOUND__) return;
  window.__INV_EVENTS_BOUND__ = true;

  // ✅ CLICKs (delegiert)
  document.addEventListener("click", async (e) => {
    const t = e.target;

    // Inventur-Modus Toggle
    if (t.closest("#btnInvMode")) {
      e.preventDefault();
      document.body.classList.toggle("inv-mode");
      localStorage.setItem("inv_mode", document.body.classList.contains("inv-mode") ? "1" : "0");
      return;
    }

    // Reset
    if (t.closest("#btnInvReset")) {
      e.preventDefault();
      if (!confirm("Inventur-Markierungen für heute wirklich zurücksetzen?")) return;

      // state.rows muss bei dir existieren -> wenn du state in Closure hast, nutze window.__INV_STATE_ROWS__
      if (window.__INV_STATE_ROWS__ instanceof Set) {
        window.__INV_STATE_ROWS__.clear();
      }
      // wenn du zusätzlich localStorage nutzt:
      try { localStorage.setItem(invKey(), JSON.stringify({ rows: [] })); } catch (_) {}

      window.refreshInventoryUI?.();
      return;
    }

    // Export Word/A4
    if (t.closest("#btnInvExportWord")) {
      e.preventDefault();

      // ✅ Entscheide hier, was du willst:
      // 1) A4 Druck (empfohlen)
      if (typeof invGetMarkedRows === "function" && typeof invPrintLabelsA4 === "function") {
        const rows = invGetMarkedRows();
        if (!rows.length) return alert("Keine Reihen markiert.");
        invPrintLabelsA4(rows);
        return;
      }

      // 2) Wenn du wirklich Word-Download willst:
      // if (typeof exportInvWordLabels === "function") return exportInvWordLabels();

      alert("Inventur: Export-Funktion nicht gefunden (invPrintLabelsA4/exportInvWordLabels).");
      return;
    }

    // Export XLSX
    if (t.closest("#btnInvExportXlsx")) {
      e.preventDefault();
      if (typeof exportInvXlsxList === "function") {
        exportInvXlsxList();
      } else {
        alert("Inventur: exportInvXlsxList() fehlt.");
      }
      return;
    }

    // 🏷 pro Reihe
    const labelBtn = t.closest("[data-inv-label-row]");
    if (labelBtn) {
      e.preventDefault();
      e.stopPropagation();

      const row = String(labelBtn.dataset.invLabelRow || "").trim();
      if (!row) return;

      if (typeof invPrintLabelsA4 === "function") {
        invPrintLabelsA4([row]);
      } else if (typeof printSingleRowLabel === "function") {
        printSingleRowLabel(row);
      } else {
        alert("Inventur: Label-Print Funktion fehlt (invPrintLabelsA4/printSingleRowLabel).");
      }
      return;
    }
  }, true);

  // ✅ CHANGE (Checkboxen) delegiert
  document.addEventListener("change", (e) => {
    const cb = e.target?.closest?.(".inv-check[data-inv-row]");
    if (!cb) return;

    const row = String(cb.dataset.invRow || "").trim();
    if (!row) return;

    if (!(window.__INV_STATE_ROWS__ instanceof Set)) window.__INV_STATE_ROWS__ = new Set();

    if (cb.checked) window.__INV_STATE_ROWS__.add(row);
    else window.__INV_STATE_ROWS__.delete(row);

    // speichern (wenn du invKey() nutzt)
    try {
      localStorage.setItem(invKey(), JSON.stringify({ rows: [...window.__INV_STATE_ROWS__] }));
    } catch (_) {}

    window.refreshInventoryUI?.();
  });
}

// ===============================
// INVENTUR: Public Funktionen (damit Buttons wieder gehen)
// ===============================
(function(){
  const WORD_API = "/Lagerplan/inventory_export_word.php";

  function localYMD(d = new Date()) {
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function escHtml(s) {
    return String(s ?? "").replace(/[&<>"']/g, m => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
    }[m]));
  }

  function checkedRowsInUI() {
    return [...document.querySelectorAll('.inv-check[data-inv-row]:checked')]
      .map(cb => String(cb.dataset.invRow || "").trim())
      .filter(Boolean);
  }

  // für A4/QR: nutzt die markierten Reihen
  function invGetMarkedRows() {
    return checkedRowsInUI().sort((a,b)=>parseInt(a,10)-parseInt(b,10));
  }

  async function exportInvWordLabels() {
    const rows = invGetMarkedRows().map(r => ({
      row: r,
      label: (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`
    }));

    if (!rows.length) return alert("Keine Reihen markiert.");

    const payload = {
      halle: window.currentHall || "H3",
      zone:  window.currentZone || "W1",
      user:  window.currentUserName || "",
      stamp: new Date().toLocaleString("de-DE"),
      rows
    };

    const res = await fetch(WORD_API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(payload)
    });

    if (!res.ok) {
      const t = await res.text().catch(()=> "");
      return alert("Word-Export fehlgeschlagen: " + res.status + "\n" + t.slice(0,200));
    }

    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);

    const a = document.createElement("a");
    a.href = url;
    a.download = `inventur_etiketten_${payload.halle}_${payload.zone}_${localYMD()}.doc`;
    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function exportInvXlsxList() {
    if (!window.XLSX) {
      return alert("XLSX Library fehlt (SheetJS). Prüfe dein <script> für XLSX.");
    }

    const hall = window.currentHall || "H3";
    const zone = window.currentZone || "W1";
    const stamp = new Date().toLocaleString("de-DE");
    const user = window.currentUserName || "";

    const rows = invGetMarkedRows().map(r => ({
      Halle: hall,
      Zone: zone,
      Reihe: r,
      Label: (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`,
      Datum: stamp,
      User: user
    }));

    if (!rows.length) return alert("Keine Reihen markiert.");

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, "Inventur");

    XLSX.writeFile(wb, `inventur_${hall}_${zone}_${localYMD()}.xlsx`);
  }

  function invQrPayload({ type, hall, zone, row }) {
    const base = window.__INV_SCAN_BASE__ || location.origin;
    return `${base}/inventur_scan.php` +
      `?mode=${encodeURIComponent(type)}` +
      `&halle=${encodeURIComponent(hall)}` +
      `&zone=${encodeURIComponent(zone)}` +
      `&reihe=${encodeURIComponent(row)}`;
  }

function invPrintLabelsA4(rows) {
  const hall = window.currentHall || "H3";
  const zone = window.currentZone || "W1";
  const user = window.currentUserName || window.currentUser || "—";
  const dt   = new Date().toLocaleString("de-DE");

  const titleForRow = (r) =>
    (typeof window.rowDisplay === "function") ? window.rowDisplay(r) : `Reihe ${r}`;

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, m => ({
    "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"
  }[m]));

  if (!rows || !rows.length) return alert("Keine Reihen markiert.");

  // ✅ NUR EIN invQrPayload verwenden (du hast ihn aktuell doppelt!)
  const qrUrl = (type, row) => {
    const base = window.__INV_SCAN_BASE__ || location.origin;
    const endpoint = window.__INV_QR_ENDPOINT__ || "/inventur_scan.php";
    return `${base}${endpoint}`
      + `?mode=${encodeURIComponent(type)}`
      + `&halle=${encodeURIComponent(hall)}`
      + `&zone=${encodeURIComponent(zone)}`
      + `&reihe=${encodeURIComponent(row)}`;
  };

  const pages = rows.map(r => {
    const qrCount = qrUrl("COUNT", r);
    const qrCheck = qrUrl("CHECK", r);

    return `
    <section class="page">
      <div class="box">
        <div class="head">
          <div class="title">${esc(titleForRow(r))}</div>
          <div class="meta">
            Halle: <b>${esc(hall)}</b> · Zone: <b>${esc(zone)}</b><br>
            Datum: <b>${esc(dt)}</b><br>
            User: <b>${esc(user)}</b><br>
            Reihe: <b>${esc(r)}</b>
          </div>
        </div>

        <div class="hint">
          Inventur: Reihe vollständig zählen → QR „ZÄHLEN“ scannen → danach prüfen → QR „PRÜFEN“ scannen.
        </div>

        <div class="qrWrap">
          <div class="qrBox">
            <div class="qrLabel">ZÄHLEN</div>
            <canvas class="qrCanvas" data-qr="${esc(qrCount)}" width="220" height="220"></canvas>
            <div class="qrText">${esc(qrCount)}</div>
          </div>

          <div class="qrBox">
            <div class="qrLabel">PRÜFEN</div>
            <canvas class="qrCanvas" data-qr="${esc(qrCheck)}" width="220" height="220"></canvas>
            <div class="qrText">${esc(qrCheck)}</div>
          </div>
        </div>

        <div class="signRow">
          <div class="sign">Gezählt von (Unterschrift)<div class="line"></div></div>
          <div class="sign">Geprüft von (Unterschrift)<div class="line"></div></div>
        </div>
      </div>
    </section>`;
  }).join("");

  const w = window.open("", "_blank", "width=980,height=780");
  if (!w) return alert("Popup blockiert. Bitte Popups erlauben (für Etikett-Druck).");

  w.document.open();
  w.document.write(`<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Inventur Etiketten (A4)</title>

  <!-- QR Library -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"><\/script>

  <style>
    @page { size: A4; margin: 16mm; }
    body { margin:0; font-family: Arial, sans-serif; color:#111; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .box { border:2px solid #000; border-radius:12px; padding:12mm;
           min-height: calc(297mm - 32mm); box-sizing:border-box;
           display:flex; flex-direction:column; gap:8mm; }
    .head { display:flex; justify-content:space-between; gap:12mm; }
    .title { font-size:28pt; font-weight:800; line-height:1.05; }
    .meta { font-size:12pt; text-align:right; white-space:nowrap; }
    .hint { font-size:12pt; color:#222; }

    .qrWrap{ display:flex; justify-content:center; gap:18mm; margin:2mm 0; }
    .qrBox{ text-align:center; }
    .qrLabel{ font-size:14pt; font-weight:800; margin-bottom:4mm; letter-spacing:.5px; }
    .qrCanvas{ border:2px solid #000; border-radius:10px; padding:6px; background:#fff; }
    .qrText{ margin-top:3mm; font-size:10pt; color:#333; white-space: nowrap; }

    .signRow{ display:grid; grid-template-columns:1fr 1fr; gap:14mm; margin-top:auto; }
    .line{ border-bottom:2px solid #000; margin-top:18mm; }
  </style>
</head>
<body>
  ${pages}

  <script>
    (function(){
      function renderQr(){
        document.querySelectorAll('canvas.qrCanvas[data-qr]').forEach(function(c){
          var val = c.getAttribute('data-qr') || '';
          try {
            new QRious({ element: c, value: val, size: 220, level: 'M' });
          } catch(e){
            // Fallback: Google QR als IMG
            var img = document.createElement('img');
            img.width = 220; img.height = 220;
            img.style.border='2px solid #000';
            img.style.borderRadius='10px';
            img.style.padding='6px';
            img.style.background='#fff';
            img.src = 'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=' + encodeURIComponent(val);
            c.replaceWith(img);
          }
        });
      }
      window.onload = function(){
        renderQr();
        setTimeout(function(){
          window.print();
          window.onafterprint = function(){ window.close(); };
        }, 250);
      };
    })();
  <\/script>
</body>
</html>`);
  w.document.close();
}



  // ✅ jetzt sind die Funktionen für deine Button-Handler sichtbar
  window.invGetMarkedRows     = invGetMarkedRows;
  window.exportInvWordLabels  = exportInvWordLabels;
  window.exportInvXlsxList    = exportInvXlsxList;
  window.invPrintLabelsA4     = invPrintLabelsA4;
})();
