<?php
declare(strict_types=1);
require __DIR__ . '/../inc/session.php';

require __DIR__ . '/_db.php';


// TODO: Hier deine Admin-Prüfung einbauen:
// if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
//     die('Kein Zugriff');
// }

// Mitarbeiter hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name_neu'])) {
    $name = trim($_POST['name_neu']);
    if ($name !== '') {
        $stmt = $pdo->prepare("INSERT INTO mitarbeiter (name, aktiv) VALUES (?, 1)");
        $stmt->execute([$name]);
    }
    header('Location: admin_mitarbeiter.php');
    exit;
}

// Aktiv/Inaktiv toggeln
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE mitarbeiter SET aktiv = 1 - aktiv WHERE id = ?")->execute([$id]);
    header('Location: admin_mitarbeiter.php');
    exit;
}

// Löschen
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM mitarbeiter WHERE id = ?")->execute([$id]);
    header('Location: admin_mitarbeiter.php');
    exit;
}

// Liste laden
$stmt = $pdo->query("SELECT id, name, aktiv, created_at FROM mitarbeiter ORDER BY aktiv DESC, name ASC");
$mitarbeiter = $stmt->fetchAll();

// === Sachnummern Sollzeiten speichern ===
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'save_sn_soll'
    && !empty($_POST['sn'])) {

    foreach ($_POST['sn'] as $id => $row) {
        if (!ctype_digit((string)$id)) {
            continue;
        }
        $id = (int)$id;

        $zU = trim($row['zeit_umpacken'] ?? '');
        $zE = trim($row['zeit_etikettieren'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE sachnummern
               SET zeit_umpacken = ?, zeit_etikettieren = ?
             WHERE id = ?
        ");

        $stmt->execute([
            $zU !== '' ? (float)$zU : null,
            $zE !== '' ? (float)$zE : null,
            $id
        ]);
    }

    header('Location: admin_mitarbeiter.php');
    exit;
}

// Sachnummern laden
$stmtSn = $pdo->query("
    SELECT id, sachnummer, lagergruppe, zeit_umpacken, zeit_etikettieren, updated_at
    FROM sachnummern
    ORDER BY sachnummer ASC
");
$rows = $stmtSn->fetchAll();

$sachnummernMitZeit = [];
$sachnummernOhneZeit = [];

foreach ($rows as $sn) {
    if ($sn['zeit_umpacken'] !== null || $sn['zeit_etikettieren'] !== null) {
        $sachnummernMitZeit[] = $sn;
    } else {
        $sachnummernOhneZeit[] = $sn;
    }
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Admin – Mitarbeiter verwalten</title>
  <style>
    body { font-family: Arial, sans-serif; background:#f4f6f8; padding:20px; }
    h1 { margin-bottom:10px; }
    form { margin-bottom:15px; }
    input[type="text"] {
      padding:6px 8px; font-size:13px; border:1px solid #ccc;
      border-radius:4px; width:220px; box-sizing:border-box;
    }
    button {
      padding:6px 10px; font-size:12px; border:none;
      border-radius:4px; cursor:pointer; background:#1976d2; color:#fff;
      margin-left:6px;
    }
    table { width:100%; border-collapse:collapse; background:#fff; }
    th, td {
      border:1px solid #ddd; padding:6px 8px; font-size:12px; text-align:left;
    }
    th { background:#e9eef5; }
    .tag {
      display:inline-block; padding:2px 6px; border-radius:3px;
      font-size:10px; color:#fff;
    }
    .aktiv { background:#2e7d32; }
    .inaktiv { background:#9e9e9e; }
    a.action {
      font-size:11px; margin-right:8px; text-decoration:none; color:#1976d2;
    }
    a.action.delete { color:#c62828; }

    th.sortable { cursor: pointer; user-select: none; }
th.sortable::after { content: ' ⇅'; font-size: 9px; color:#888; }
th.sortable.asc::after { content: ' ↑'; }
th.sortable.desc::after { content: ' ↓'; }

  </style>
</head>
<body>

<h1>Admin – Mitarbeiter verwalten</h1>

<form method="post">
  <label>
    Neuer Mitarbeiter:
    <input type="text" name="name_neu" placeholder="Name eingeben" required>
  </label>
  <button type="submit">Hinzufügen</button>
</form>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Status</th>
      <th>Seit</th>
      <th>Aktionen</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($mitarbeiter as $m): ?>
      <tr>
        <td><?= htmlspecialchars((string)$m['id']) ?></td>
        <td><?= htmlspecialchars($m['name']) ?></td>
        <td>
          <?php if ($m['aktiv']): ?>
            <span class="tag aktiv">aktiv</span>
          <?php else: ?>
            <span class="tag inaktiv">inaktiv</span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($m['created_at']) ?></td>
        <td>
          <a class="action" href="?toggle=<?= (int)$m['id'] ?>">
            <?= $m['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?>
          </a>
          <a class="action delete" href="?delete=<?= (int)$m['id'] ?>" onclick="return confirm('Wirklich löschen?');">
            Löschen
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2>Sachnummern – Sollzeiten</h2>
<p class="small-hint">
  Zeiten in Minuten pro Stück. Leer = keine Vorgabe.
  Oben: Einträge mit Sollzeit, unten: ohne Sollzeit.
  Klick auf Spaltenkopf = sortieren. Suchfeld filtert beide Tabellen live.
</p>

<input type="text"
       id="snSearch"
       placeholder="Sachnummer oder Lagergruppe suchen..."
       style="padding:4px 6px; font-size:11px; width:260px; margin-bottom:8px;">

<form method="post">
  <input type="hidden" name="action" value="save_sn_soll">

  <!-- Tabelle 1: mit Sollzeiten -->
  <h3>Sachnummern mit Sollzeit</h3>
  <table id="snTableWith">
    <thead>
      <tr>
        <th class="sortable" data-col="0">ID</th>
        <th class="sortable" data-col="1">Sachnummer</th>
        <th class="sortable" data-col="2">Lagergruppe</th>
        <th class="sortable" data-col="3">Umpacken (Min/Stk)</th>
        <th class="sortable" data-col="4">Etikettieren (Min/Stk)</th>
        <th class="sortable" data-col="5">Geändert</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sachnummernMitZeit as $sn): ?>
        <tr>
          <td><?= (int)$sn['id'] ?></td>
          <td><?= htmlspecialchars($sn['sachnummer']) ?></td>
          <td><?= htmlspecialchars((string)$sn['lagergruppe']) ?></td>
          <td>
            <input type="number"
                   name="sn[<?= (int)$sn['id'] ?>][zeit_umpacken]"
                   value="<?= $sn['zeit_umpacken'] !== null ? (float)$sn['zeit_umpacken'] : '' ?>"
                   step="0.01" min="0">
          </td>
          <td>
            <input type="number"
                   name="sn[<?= (int)$sn['id'] ?>][zeit_etikettieren]"
                   value="<?= $sn['zeit_etikettieren'] !== null ? (float)$sn['zeit_etikettieren'] : '' ?>"
                   step="0.01" min="0">
          </td>
          <td><?= htmlspecialchars((string)$sn['updated_at']) ?></td>
          <td>
            <a class="action delete"
               href="?delete_sn=<?= (int)$sn['id'] ?>"
               onclick="return confirm('Sachnummer wirklich löschen?');">
              Löschen
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Tabelle 2: ohne Sollzeiten -->
  <h3>Sachnummern ohne Sollzeit</h3>
  <table id="snTableWithout">
    <thead>
      <tr>
        <th class="sortable" data-col="0">ID</th>
        <th class="sortable" data-col="1">Sachnummer</th>
        <th class="sortable" data-col="2">Lagergruppe</th>
        <th class="sortable" data-col="3">Umpacken (Min/Stk)</th>
        <th class="sortable" data-col="4">Etikettieren (Min/Stk)</th>
        <th class="sortable" data-col="5">Geändert</th>
        <th>Aktion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sachnummernOhneZeit as $sn): ?>
        <tr>
          <td><?= (int)$sn['id'] ?></td>
          <td><?= htmlspecialchars($sn['sachnummer']) ?></td>
          <td><?= htmlspecialchars((string)$sn['lagergruppe']) ?></td>
          <td>
            <input type="number"
                   name="sn[<?= (int)$sn['id'] ?>][zeit_umpacken]"
                   value=""
                   step="0.01" min="0">
          </td>
          <td>
            <input type="number"
                   name="sn[<?= (int)$sn['id'] ?>][zeit_etikettieren]"
                   value=""
                   step="0.01" min="0">
          </td>
          <td><?= htmlspecialchars((string)$sn['updated_at']) ?></td>
          <td>
            <a class="action delete"
               href="?delete_sn=<?= (int)$sn['id'] ?>"
               onclick="return confirm('Sachnummer wirklich löschen?');">
              Löschen
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <button type="submit">Sollzeiten speichern</button>
</form>

<script>
  // Live-Suche für Sachnummern-Tabelle
  (function() {
    const input = document.getElementById('snSearch');
    const table = document.getElementById('sachnummernTable');
    if (!input || !table) return;

    input.addEventListener('input', function () {
      const q = this.value.trim().toLowerCase();
      const rows = table.tBodies[0].rows;

      for (const row of rows) {
        const sach = row.cells[1].textContent.toLowerCase();
        const lg   = row.cells[2].textContent.toLowerCase();
        row.style.display = (q === '' || sach.includes(q) || lg.includes(q))
          ? ''
          : 'none';
      }
    });
  })();

  // Sortierung für Sachnummern-Tabelle
  (function() {
    const table = document.getElementById('sachnummernTable');
    if (!table) return;

    const headers = table.querySelectorAll('th.sortable');
    let currentSort = { index: null, dir: 1 };

    headers.forEach(th => {
      th.addEventListener('click', () => {
        const colIndex = parseInt(th.dataset.col, 10);
        let dir = 1;

        // Toggle Richtung, wenn gleiche Spalte nochmal geklickt
        if (currentSort.index === colIndex) {
          dir = -currentSort.dir;
        }

        currentSort = { index: colIndex, dir };

        // CSS-Klassen aktualisieren
        headers.forEach(h => h.classList.remove('asc', 'desc'));
        th.classList.add(dir === 1 ? 'asc' : 'desc');

        const tbody = table.tBodies[0];
        const rows = Array.from(tbody.rows);

        rows.sort((a, b) => {
          const A = a.cells[colIndex].innerText.trim();
          const B = b.cells[colIndex].innerText.trim();

          const numA = parseFloat(A.replace(',', '.'));
          const numB = parseFloat(B.replace(',', '.'));

          // Zahlen-Spalten numerisch sortieren
          if (!isNaN(numA) && !isNaN(numB)) {
            return (numA - numB) * dir;
          }

          // sonst String-Vergleich
          return A.localeCompare(B, 'de', { numeric: true }) * dir;
        });

        rows.forEach(r => tbody.appendChild(r));
      });
    });
  })();
</script>

<script>
  // Live-Suche für beide Tabellen
  (function () {
    const input = document.getElementById('snSearch');
    const tables = [
      document.getElementById('snTableWith'),
      document.getElementById('snTableWithout')
    ];
    if (!input) return;

    input.addEventListener('input', function () {
      const q = this.value.trim().toLowerCase();
      tables.forEach(table => {
        if (!table) return;
        const rows = table.tBodies[0].rows;
        for (const row of rows) {
          const sach = row.cells[1].textContent.toLowerCase();
          const lg   = row.cells[2].textContent.toLowerCase();
          row.style.display = (!q || sach.includes(q) || lg.includes(q)) ? '' : 'none';
        }
      });
    });
  })();

  // Sortierung (pro Tabelle separat)
  (function () {
    ['snTableWith', 'snTableWithout'].forEach(tableId => {
      const table = document.getElementById(tableId);
      if (!table) return;

      const headers = table.querySelectorAll('th.sortable');
      let currentSort = { index: null, dir: 1 };

      headers.forEach(th => {
        th.addEventListener('click', () => {
          const colIndex = parseInt(th.dataset.col, 10);
          let dir = 1;

          if (currentSort.index === colIndex) {
            dir = -currentSort.dir;
          }
          currentSort = { index: colIndex, dir };

          headers.forEach(h => h.classList.remove('asc', 'desc'));
          th.classList.add(dir === 1 ? 'asc' : 'desc');

          const tbody = table.tBodies[0];
          const rows = Array.from(tbody.rows);

          rows.sort((a, b) => {
            const A = a.cells[colIndex].innerText.trim();
            const B = b.cells[colIndex].innerText.trim();

            const numA = parseFloat(A.replace(',', '.'));
            const numB = parseFloat(B.replace(',', '.'));

            if (!isNaN(numA) && !isNaN(numB)) {
              return (numA - numB) * dir;
            }
            return A.localeCompare(B, 'de', { numeric: true }) * dir;
          });

          rows.forEach(r => tbody.appendChild(r));
        });
      });
    });
  })();
</script>

</body>
</html>
