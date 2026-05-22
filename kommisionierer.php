<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';         // in /LKW/admin/*.php oder /LKW/dokumente/*.php


// Pfad anpassen, wenn die Datei in /LKW/api/ liegt:
require __DIR__ . '/../_db.php';

// aktive Mitarbeiter laden
$stmt = $pdo->query("SELECT name FROM mitarbeiter WHERE aktiv = 1 ORDER BY name ASC");
$employees = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Leistungsnachweis Umpacken / Etikettieren</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      padding: 20px;
    }
    h1 { margin-bottom: 4px; }
    h2 { margin-top: 18px; font-size: 16px; }
    p  { font-size: 12px; margin: 4px 0 10px; }

    .meta {
      display: flex;
      gap: 12px;
      align-items: center;
      margin: 8px 0 14px;
      font-size: 12px;
      flex-wrap: wrap;
    }
    .meta label {
      display: flex;
      gap: 4px;
      align-items: center;
    }
    .meta input {
      padding: 3px 4px;
      font-size: 11px;
      border: 1px solid #ccc;
      border-radius: 3px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      margin-bottom: 10px;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 6px 8px;
      font-size: 12px;
      text-align: center;
    }
    th {
      background: #e9eef5;
      font-weight: 600;
    }
    input, select {
      width: 100%;
      box-sizing: border-box;
      border: 1px solid #ccc;
      padding: 3px 4px;
      font-size: 11px;
    }

    .ok {
      background: #d4f6d4;
      color: #176317;
      font-weight: 600;
    }
    .bad {
      background: #ffd6d6;
      color: #8b0000;
      font-weight: 600;
    }

    .outlier td {
      background: #ffb3b3 !important;
    }

    .perf-good {
      color: #176317;
      font-weight: 600;
    }
    .perf-bad {
      color: #8b0000;
      font-weight: 600;
    }

    .btn {
      display: inline-block;
      padding: 6px 10px;
      margin: 5px 4px 12px 0;
      font-size: 11px;
      border-radius: 4px;
      border: none;
      cursor: pointer;
      background: #1976d2;
      color: #fff;
    }
    .btn-secondary {
      background: #607d8b;
    }

    @media print {
      body { background: #fff; padding: 0; }
      .btn, .meta { display: none !important; }
      h1, h2, p { margin-left: 10px; }
      table { font-size: 10px; }
    }
  </style>
</head>
<body>

<h1>Leistungsübersicht Mitarbeiter</h1>
<p>Ist-Dauer = Ende − Start. Vorgabe = Min pro Einheit, Soll = Vorgabe × Menge.</p>

<div class="meta">
  <label>
    Datum:
    <input type="date" id="reportDate">
  </label>
  <label>
    Mitarbeiter (Tagesnachweis):
    <input type="text" id="reportEmployee" list="mitarbeiterliste" placeholder="Name aus Liste wählen">
  </label>
</div>

<!-- Live-Auswahl der Mitarbeiter aus DB -->
<datalist id="mitarbeiterliste">
  <?php foreach ($employees as $name): ?>
    <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
  <?php endforeach; ?>
</datalist>

<table id="leistungstabelle">
  <thead>
    <tr>
      <th>Mitarbeiter</th>
      <th>Start</th>
      <th>Ende</th>
      <th>Ist-Dauer (Min)</th>
      <th>Sachnummer</th>
      <th>Tätigkeit</th>
      <th>Menge</th>
      <th>Soll (Min)</th>
      <th>Differenz (Ist - Soll)</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><input type="text" list="mitarbeiterliste" placeholder="Mitarbeiter"></td>
      <td><input type="time"></td>
      <td><input type="time"></td>
      <td class="ist"></td>
      <td><input list="sachnummern" placeholder="Sachnummer"></td>
      <td>
        <select>
          <option value="">- wählen -</option>
          <option value="umpacken">Umpacken</option>
          <option value="etikettieren">Etikettieren</option>
        </select>
      </td>
      <td><input type="number" min="1" step="1" value="1"></td>
      <td class="soll"></td>
      <td class="diff"></td>
      <td class="status"></td>
    </tr>
  </tbody>
</table>

<button class="btn" onclick="addRow()">+ Zeile hinzufügen</button>
<button class="btn btn-secondary" onclick="recalculateAll()">Neu berechnen</button>

<h2>Tagesübersicht nach Mitarbeiter</h2>
<table id="summaryTable">
  <thead>
    <tr>
      <th>Mitarbeiter</th>
      <th>Anzahl Vorgänge</th>
      <th>Summe Ist (Min)</th>
      <th>Summe Soll (Min)</th>
      <th>Leistung (%)</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>

<!-- Sachnummern-Auswahl (deine Liste) -->
<datalist id="sachnummern">
  <option value="W1 0Z1 010 010">
  <!-- ... Rest unverändert ... -->
</datalist>

<script>
  // Mitarbeiter aus PHP als JS-Array
  const EMPLOYEES = <?php echo json_encode(
    $employees,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ); ?>;

  const REFERENCE_TIMES = {
    // Beispiel / später aus DB:
    // "W1 0Z1 010 010": { "umpacken": 10, "etikettieren": 5 },
  };

  function parseTimeToMinutes(timeStr) {
    if (!timeStr) return null;
    const [h, m] = timeStr.split(':').map(Number);
    if (Number.isNaN(h) || Number.isNaN(m)) return null;
    return h * 60 + m;
  }

  function getPerUnitTime(sachnummer, taetigkeit) {
    if (!sachnummer || !taetigkeit) return null;
    const sn = sachnummer.trim();
    const ref = REFERENCE_TIMES[sn];
    if (!ref) return null;
    return ref[taetigkeit] ?? null;
  }

  function calculateRow(row) {
    const startInput = row.cells[1].querySelector('input');
    const endInput   = row.cells[2].querySelector('input');
    const istCell    = row.cells[3];
    const sachInput  = row.cells[4].querySelector('input');
    const taetigSel  = row.cells[5].querySelector('select');
    const mengeInput = row.cells[6].querySelector('input');
    const sollCell   = row.cells[7];
    const diffCell   = row.cells[8];
    const statusCell = row.cells[9];

    row.classList.remove('outlier');
    istCell.textContent = '';
    sollCell.textContent = '';
    diffCell.textContent = '';
    statusCell.textContent = '';
    statusCell.className = 'status';

    const startMin = parseTimeToMinutes(startInput.value);
    const endMin   = parseTimeToMinutes(endInput.value);
    if (startMin == null || endMin == null || endMin <= startMin) return;

    const ist = endMin - startMin;
    istCell.textContent = ist.toFixed(1);
    istCell.classList.add('ist');

    let menge = parseFloat((mengeInput.value || '').toString().replace(',', '.'));
    if (Number.isNaN(menge) || menge <= 0) menge = 1;

    const perUnit = getPerUnitTime(sachInput.value, taetigSel.value);

    if (perUnit != null) {
      const soll = perUnit * menge;
      sollCell.textContent = soll.toFixed(1);

      const diff = ist - soll;
      diffCell.textContent = diff.toFixed(1);

      if (diff <= 0) {
        statusCell.textContent = 'OK';
        statusCell.classList.add('ok');
      } else {
        statusCell.textContent = 'Über Soll';
        statusCell.classList.add('bad');
      }

      if (soll > 0 && ist > soll * 1.5) {
        row.classList.add('outlier');
      }
    } else {
      sollCell.textContent = '-';
      statusCell.textContent = 'Keine Vorgabe';
    }
  }

  function updateSummary() {
    const rows = document.querySelectorAll('#leistungstabelle tbody tr');
    const stats = {};

    // Basis: alle Mitarbeiter aus Admin-Tool
    EMPLOYEES.forEach(name => {
      stats[name] = { count: 0, ist: 0, soll: 0 };
    });

    rows.forEach(row => {
      const ma = (row.cells[0].querySelector('input')?.value || '').trim();
      if (!ma) return;

      const ist = parseFloat((row.querySelector('.ist')?.textContent || '').replace(',', '.'));
      const soll = parseFloat((row.querySelector('.soll')?.textContent || '').replace(',', '.'));
      const hasData = (!Number.isNaN(ist) && ist > 0) || (!Number.isNaN(soll) && soll > 0);
      if (!hasData) return;

      if (!stats[ma]) {
        stats[ma] = { count: 0, ist: 0, soll: 0 }; // Name nicht in Admin-Liste (Tippfehler etc.)
      }

      stats[ma].count += 1;
      if (!Number.isNaN(ist))  stats[ma].ist  += ist;
      if (!Number.isNaN(soll)) stats[ma].soll += soll;
    });

    const tbody = document.querySelector('#summaryTable tbody');
    tbody.innerHTML = '';

    Object.keys(stats).forEach(ma => {
      const { count, ist, soll } = stats[ma];
      let leistungText = '-';
      let perfClass = '';

      if (ist > 0 && soll > 0) {
        const leistung = (soll / ist) * 100; // >100% = schneller = gut
        leistungText = leistung.toFixed(1) + '%';
        perfClass = leistung >= 100 ? 'perf-good' : 'perf-bad';
      }

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${ma}</td>
        <td>${count}</td>
        <td>${ist > 0 ? ist.toFixed(1) : '-'}</td>
        <td>${soll > 0 ? soll.toFixed(1) : '-'}</td>
        <td class="${perfClass}">${leistungText}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  function recalculateAll() {
    const rows = document.querySelectorAll('#leistungstabelle tbody tr');
    rows.forEach(calculateRow);
    updateSummary();
  }

  function addRow() {
    const tbody = document.querySelector('#leistungstabelle tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="text" list="mitarbeiterliste" placeholder="Mitarbeiter"></td>
      <td><input type="time"></td>
      <td><input type="time"></td>
      <td class="ist"></td>
      <td><input list="sachnummern" placeholder="Sachnummer"></td>
      <td>
        <select>
          <option value="">- wählen -</option>
          <option value="umpacken">Umpacken</option>
          <option value="etikettieren">Etikettieren</option>
        </select>
      </td>
      <td><input type="number" min="1" step="1" value="1"></td>
      <td class="soll"></td>
      <td class="diff"></td>
      <td class="status"></td>
    `;
    tbody.appendChild(tr);

    tr.querySelectorAll('input, select').forEach(el => {
      el.addEventListener('change', () => {
        calculateRow(tr);
        updateSummary();
      });
    });

    const headerName = (document.getElementById('reportEmployee').value || '').trim();
    if (headerName) {
      const maInput = tr.cells[0].querySelector('input');
      if (!maInput.value.trim()) maInput.value = headerName;
    }
  }

  document.getElementById('reportEmployee').addEventListener('change', () => {
    const name = (document.getElementById('reportEmployee').value || '').trim();
    if (!name) return;
    document.querySelectorAll('#leistungstabelle tbody tr').forEach(row => {
      const maInput = row.cells[0].querySelector('input');
      if (!maInput.value.trim()) maInput.value = name;
    });
    recalculateAll();
  });

  document
    .querySelectorAll('#leistungstabelle tbody tr input, #leistungstabelle tbody tr select')
    .forEach(el => el.addEventListener('change', recalculateAll));

  recalculateAll();
</script>

</body>
</html>
