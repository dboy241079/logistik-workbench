<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../inc/session.php';


require __DIR__ . '/_db.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function out($ok, $extra = [], $code = 200) {
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function normHeader(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = preg_replace('/\s+/', ' ', $s);
  $s = str_replace(['.', '–', '—'], ['','-','-'], $s);
  return $s;
}

function normalizeTime(?string $v): ?string {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;

  $v = preg_replace('/\s+/', '', $v);
  $v = str_replace('.', ':', $v);

  if (preg_match('/^\d{1,2}:\d{2}$/', $v)) {
    [$h,$m] = explode(':',$v);
    return str_pad($h,2,'0',STR_PAD_LEFT).':'.$m;
  }
  if (preg_match('/^\d{1,2}$/', $v)) return str_pad($v,2,'0',STR_PAD_LEFT).':00';
  if (preg_match('/^\d{3,4}$/', $v)) {
    $h = strlen($v)===3 ? substr($v,0,1) : substr($v,0,2);
    $m = substr($v,-2);
    return str_pad($h,2,'0',STR_PAD_LEFT).':'.$m;
  }
  return null; // alles andere ignorieren
}

function normalizeDate($cell): ?string {
  // Erwartung: entweder YYYY-MM-DD oder deutsches Datum oder Excel-Serienzahl
  if ($cell === null || $cell === '') return null;

  // Excel Date numeric?
  if (is_numeric($cell)) {
    // PhpSpreadsheet kann Excel-Dates konvertieren, aber wir halten es simpel:
    // 25569 = 1970-01-01 (Excel epoch offset). Das ist nicht 100% bulletproof, aber ok.
    $unix = ((int)$cell - 25569) * 86400;
    if ($unix > 0) return gmdate('Y-m-d', $unix);
  }

  $s = trim((string)$cell);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

  // dd.mm.yyyy
  if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
  }

  return null;
}

try {
  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    out(false, ['error'=>'upload_failed','msg'=>'Datei-Upload fehlgeschlagen.'], 400);
  }

  $vehId = trim($_POST['veh_id'] ?? '');
  if ($vehId === '') out(false, ['error'=>'missing_veh','msg'=>'veh_id fehlt.'], 400);

  $tmp = $_FILES['file']['tmp_name'];

  $spreadsheet = IOFactory::load($tmp);
  $sheet = $spreadsheet->getActiveSheet();
  $highestRow = $sheet->getHighestDataRow();
  $highestCol = $sheet->getHighestDataColumn();

  // --- Header (Row 1) ---
  $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true)[1];
  $colMap = []; // dbField => columnLetter

  // Spalten-Mapping: du kannst hier flexibel erweitern
  $aliases = [
    'tour'         => ['tour','fahrt','runde'],
    'date'         => ['datum','tag','date'],
    'workStart'    => ['start','arbeitsbeginn','work start'],
    'arriveWU'     => ['wu an','wunstorf an','ankunft wu'],
    'departWU'     => ['wu ab','wunstorf ab','abfahrt wu'],
    'arriveH'      => ['h an','hannover an','ankunft h'],
    'hannoverHall' => ['halle','hall','halle 1'],
    'departH'      => ['h ab','hannover ab','abfahrt h'],
    'arriveH2'     => ['h2 an','hannover2 an','ankunft h2'],
    'hannoverHall2'=> ['halle 2','hall 2','halle2'],
    'departH2'     => ['h2 ab','hannover2 ab','abfahrt h2'],
    'pauseStart'   => ['pause start','pause von'],
    'pauseEnd'     => ['pause ende','pause bis'],
    'workEnd'      => ['feierabend','arbeitsende','work end'],
  ];

  foreach ($headerRow as $col => $name) {
    $h = normHeader((string)$name);
    foreach ($aliases as $field => $list) {
      foreach ($list as $alias) {
        if ($h === normHeader($alias)) $colMap[$field] = $col;
      }
    }
  }

  foreach (['tour','date'] as $must) {
    if (!isset($colMap[$must])) {
      out(false, ['error'=>'missing_columns','msg'=>"Pflichtspalte fehlt: {$must} (Header prüfen)","colMap"=>$colMap], 400);
    }
  }

  $sql = "
    INSERT INTO driver_days
      (veh_id, date, tour, workStart, arriveWU, departWU, arriveH, hannoverHall, departH, arriveH2, hannoverHall2, departH2, pauseStart, pauseEnd, workEnd)
    VALUES
      (:veh_id, :date, :tour, :workStart, :arriveWU, :departWU, :arriveH, :hannoverHall, :departH, :arriveH2, :hannoverHall2, :departH2, :pauseStart, :pauseEnd, :workEnd)
    ON DUPLICATE KEY UPDATE
      workStart=VALUES(workStart),
      arriveWU=VALUES(arriveWU),
      departWU=VALUES(departWU),
      arriveH=VALUES(arriveH),
      hannoverHall=VALUES(hannoverHall),
      departH=VALUES(departH),
      arriveH2=VALUES(arriveH2),
      hannoverHall2=VALUES(hannoverHall2),
      departH2=VALUES(departH2),
      pauseStart=VALUES(pauseStart),
      pauseEnd=VALUES(pauseEnd),
      workEnd=VALUES(workEnd)
  ";

  $stmt = $pdo->prepare($sql);

  $imported = 0;
  for ($r = 2; $r <= $highestRow; $r++) {
    $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true)[$r];

    $tour = trim((string)($row[$colMap['tour']] ?? ''));
    $date = normalizeDate($row[$colMap['date']] ?? null);
    if ($tour === '' || !$date) continue;

    $payload = [
      'veh_id' => $vehId,
      'date' => $date,
      'tour' => $tour,
      'workStart' => normalizeTime($row[$colMap['workStart']] ?? null),
      'arriveWU'  => normalizeTime($row[$colMap['arriveWU']] ?? null),
      'departWU'  => normalizeTime($row[$colMap['departWU']] ?? null),
      'arriveH'   => normalizeTime($row[$colMap['arriveH']] ?? null),
      'hannoverHall'  => trim((string)($row[$colMap['hannoverHall']] ?? '')) ?: null,
      'departH'   => normalizeTime($row[$colMap['departH']] ?? null),
      'arriveH2'  => normalizeTime($row[$colMap['arriveH2']] ?? null),
      'hannoverHall2' => trim((string)($row[$colMap['hannoverHall2']] ?? '')) ?: null,
      'departH2'  => normalizeTime($row[$colMap['departH2']] ?? null),
      'pauseStart'=> normalizeTime($row[$colMap['pauseStart']] ?? null),
      'pauseEnd'  => normalizeTime($row[$colMap['pauseEnd']] ?? null),
      'workEnd'   => normalizeTime($row[$colMap['workEnd']] ?? null),
    ];

    $stmt->execute($payload);
    $imported++;
  }

  out(true, ['imported'=>$imported, 'veh_id'=>$vehId]);

} catch (Throwable $e) {
  out(false, ['error'=>'exception','msg'=>$e->getMessage()], 500);
}
