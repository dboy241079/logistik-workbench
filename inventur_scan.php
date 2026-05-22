<?php
declare(strict_types=1);

require __DIR__ . '/inc/session.php';
require __DIR__ . '/api/_db.php';

// Mitarbeiterliste (display_name)
$users = [];
try {
  $users = $pdo->query("
    SELECT display_name
    FROM users
    WHERE display_name IS NOT NULL AND display_name <> ''
    ORDER BY display_name ASC
  ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
  $users = [];
}

// Default: aktueller User als display_name (falls vorhanden)
$meUser = (string)($_SESSION['username'] ?? '');
$meName = $meUser;

if ($meUser !== '') {
  try {
    $st = $pdo->prepare("SELECT display_name FROM users WHERE username=? LIMIT 1");
    $st->execute([$meUser]);
    $dn = (string)($st->fetchColumn() ?: '');
    if ($dn !== '') $meName = $dn;
  } catch (Throwable $e) {}
}
?>

<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventur Scan</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
  <div class="container py-4" style="max-width:520px;">
    <div class="card shadow-sm">
      <div class="card-header">
        <div class="fw-bold">Inventur</div>
        <div class="text-muted small" id="meta">–</div>
      </div>

      <div class="card-body">
        <div class="alert alert-secondary py-2 small mb-3" id="statusBox">Lade…</div>


        <div class="alert alert-info small mb-3" id="hintBox">
  Lass dir Zeit beim Zählen – lieber ruhig und gewissenhaft als schnell und fehlerhaft.
</div>

<div class="mb-2">
  <label class="form-label small">Mitarbeiter</label>
  <select id="who" class="form-select form-select-lg"></select>
  <div class="form-text">Standard ist der angemeldete Benutzer.</div>
</div>

        <div class="mb-2">
          <label class="form-label small">Menge eingeben</label>
          <input id="qty" type="number" min="0" step="1" class="form-control form-control-lg" placeholder="z.B. 133">
        </div>

        <div class="d-flex gap-2">
          <button id="btnSave" class="btn btn-dark flex-grow-1">Speichern</button>
          <button id="btnReload" class="btn btn-outline-secondary">Neu laden</button>
        </div>

        <div class="mt-3 small text-muted">
          Hinweis: Du musst auf dem Handy im Lagerplan **eingeloggt** sein, sonst kann nicht gespeichert werden.
        </div>
      </div>
    </div>
  </div>

  <script>
  window.INV_USERS = <?= json_encode(array_values($users), JSON_UNESCAPED_UNICODE) ?>;
  window.INV_ME_NAME = <?= json_encode($meName, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
  const qs = new URLSearchParams(location.search);

  const mode  = (qs.get("mode")  || "COUNT").toUpperCase();   // COUNT | CHECK
  const halle = (qs.get("halle") || "H3");
  const zone  = (qs.get("zone")  || "W1");
  const reihe = parseInt(qs.get("reihe") || "0", 10);

  const metaEl = document.getElementById("meta");
  const box    = document.getElementById("statusBox");
  const qtyInp = document.getElementById("qty");
  const whoSel = document.getElementById("who");

// Select Optionen bauen
(function fillUsers(){
  const list = Array.isArray(window.INV_USERS) ? window.INV_USERS : [];
  const me   = window.INV_ME_NAME || "—";

  // 1) Default/Auto
  whoSel.innerHTML = `<option value="">Angemeldet: ${me}</option>` +
    list.map(n => `<option value="${String(n).replaceAll('"','&quot;')}">${n}</option>`).join("");

  // letzter Wert merken
  const last = localStorage.getItem("inv_who") || "";
  if (last) whoSel.value = last;

  whoSel.addEventListener("change", () => {
    localStorage.setItem("inv_who", whoSel.value || "");
  });

  // Modus-spezifischer Text
  const isCount = mode === "COUNT";
  document.getElementById("hintBox").textContent = isCount
    ? "Lass dir Zeit beim Zählen – lieber ruhig und gewissenhaft als schnell und fehlerhaft."
    : "Beim Prüfen: kontrolliere sorgfältig (z.B. nach Liste/Plätzen) – lieber sauber als schnell.";
})();


  if (!reihe) {
    box.className = "alert alert-danger py-2 small";
    box.textContent = "Fehler: reihe fehlt in der URL.";
  } else {
    metaEl.textContent = `Modus: ${mode} · Halle: ${halle} · Zone: ${zone} · Reihe: ${reihe}`;
  }

  function setBox(msg, type="secondary") {
    box.className = "alert alert-" + type + " py-2 small";
    box.textContent = msg;
  }

  async function loadRow() {
    setBox("Lade Daten…", "secondary");
    const url = `/api/inventur_api.php?action=GET_ROW&halle=${encodeURIComponent(halle)}&zone=${encodeURIComponent(zone)}&reihe=${encodeURIComponent(reihe)}`;
    const res = await fetch(url, { credentials: "include", cache: "no-store" });
    const js  = await res.json().catch(()=> ({}));

    if (!res.ok || !js.ok) {
      setBox(js?.msg || "Laden fehlgeschlagen (nicht eingeloggt?)", "danger");
      return;
    }

    const r = js.row;
    setBox(
      `Soll: ${r.soll_menge} · Count: ${r.count_menge ?? "-"} · Check: ${r.check_menge ?? "-"} · Status: ${r.status}`,
      r.status === "ok" ? "success" : (String(r.status).includes("abweichung") ? "warning" : "secondary")
    );

    // Fokus direkt auf Menge
    qtyInp.focus();
    qtyInp.select();
  }

  async function save() {
    const menge = parseInt(qtyInp.value || "0", 10);
    if (!Number.isFinite(menge) || menge < 0) {
      setBox("Bitte eine gültige Menge eingeben.", "danger");
      return;
    }

    setBox("Speichere…", "secondary");

    const res = await fetch("/api/inventur_api.php", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
  action: mode,
  halle, zone, reihe,
  menge,
  actor: (whoSel?.value || "")   // <-- ausgewählter display_name (oder leer = angemeldet)
})

    });

    let js = null;
let raw = "";

try {
  js = await res.clone().json();
} catch (e) {
  raw = await res.text().catch(() => "");
}

if (!res.ok || !js?.ok) {
  const msg = js?.msg || "Speichern fehlgeschlagen.";
  const det = js?.detail ? (" | " + js.detail) : "";
  const hint = raw ? ("\n" + raw.slice(0, 300)) : "";
  setBox(msg + det + hint, "danger");
  return;
}


    // neu laden, zeigt Status + Abweichungen
    qtyInp.value = "";
    await loadRow();
  }

  document.getElementById("btnSave").addEventListener("click", save);
  document.getElementById("btnReload").addEventListener("click", loadRow);

  loadRow();
</script>
</body>
</html>
