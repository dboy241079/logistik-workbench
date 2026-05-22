<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if ($jobId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM excel_import_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->query("SELECT * FROM excel_import_jobs ORDER BY id DESC LIMIT 1");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$job) {
    exit('Kein Import gefunden.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM excel_import_rows
    WHERE job_id = ?
    ORDER BY row_number ASC
    LIMIT 300
");
$stmt->execute([(int)$job['id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e(?string $v): string {
    return htmlspecialchars((string)$v);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Import-Vorschau</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{
      font-family: Arial, sans-serif;
      background:#0f172a;
      color:#e5e7eb;
      margin:0;
      padding:24px;
    }
    .wrap{
      max-width:1400px;
      margin:0 auto;
    }
    .card{
      background:#111827;
      border:1px solid #334155;
      border-radius:16px;
      padding:20px;
      margin-bottom:20px;
    }
    .stats{
      display:flex;
      gap:16px;
      flex-wrap:wrap;
    }
    .stat{
      background:#1e293b;
      border-radius:12px;
      padding:14px 18px;
      min-width:180px;
    }
    table{
      width:100%;
      border-collapse:collapse;
      background:#111827;
      border-radius:12px;
      overflow:hidden;
    }
    th,td{
      border:1px solid #334155;
      padding:10px;
      text-align:left;
      vertical-align:top;
      font-size:14px;
    }
    th{
      background:#1e293b;
      position:sticky;
      top:0;
    }
    .ok{
      background:rgba(34,197,94,.12);
    }
    .error{
      background:rgba(239,68,68,.12);
    }
    a{
      color:#93c5fd;
      text-decoration:none;
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Import-Vorschau</h1>
    <p><strong>Job-ID:</strong> <?= (int)$job['id'] ?></p>
    <p><strong>Modul:</strong> <?= e($job['module']) ?></p>
    <p><strong>Datei:</strong> <?= e($job['original_filename']) ?></p>
    <p><strong>Hochgeladen von:</strong> <?= e($job['uploaded_by']) ?></p>
    <p><strong>Status:</strong> <?= e($job['status']) ?></p>
    <p><strong>Erstellt:</strong> <?= e($job['created_at']) ?></p>

    <div class="stats">
      <div class="stat"><strong>Gesamtzeilen</strong><br><?= (int)$job['total_rows'] ?></div>
      <div class="stat"><strong>OK</strong><br><?= (int)$job['valid_rows'] ?></div>
      <div class="stat"><strong>Fehler</strong><br><?= (int)$job['error_rows'] ?></div>
    </div>

    <p style="margin-top:16px;">
      <a href="excel_import.php">Neuen Import starten</a>
    </p>
  </div>

  <div class="card">
    <h2>Erkannte Zeilen (max. 300)</h2>
    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
  <th>Zeile</th>
  <th>Status</th>
  <th>Fehler</th>
  <th>Eing.-Nr.</th>
  <th>Fracht/Lief.</th>
  <th>Lagergrp.</th>
  <th>Datum</th>
  <th>Kennz.</th>
  <th>Land</th>
  <th>Spedit.</th>
  <th>Ank.</th>
  <th>Beg. Entl.</th>
  <th>Ende Entl.</th>
  <th>Behälter</th>
  <th>Zus.-Beh.</th>
  <th>Beh.-Nr.</th>
  <th>Sachnr.</th>
  <th>Menge</th>
  <th>Gebucht</th>
  <th>Geb. von</th>
</tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['status'] === 'ok' ? 'ok' : 'error' ?>">
            <td><?= (int)$r['row_number'] ?></td>
            <td><?= e($r['status']) ?></td>
            <td><?= e($r['error_text']) ?></td>
            <td><?= e($r['referenznummer']) ?></td>
            <td><?= e($r['sachnummer']) ?></td>
            <td><?= e($r['lieferschein']) ?></td>
            <td><?= e($r['menge']) ?></td>
            <td><?= e($r['behaelter']) ?></td>
            <td><?= e($r['zus_behaelter']) ?></td>
            <td><?= e($r['lagergruppe']) ?></td>
            <td><?= e($r['reihe']) ?></td>
            <td><?= e($r['platz']) ?></td>
            <td><?= e($r['datum']) ?></td>
            <td><?= e($r['bemerkung']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>