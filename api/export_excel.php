<?php
// export_excel.php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require __DIR__ . '/vendor/autoload.php';

// ---- Payload lesen ----
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo 'Invalid JSON';
  exit;
}

// Erwartete Struktur:
// {
//   "eingangsnummer":"...",
//   "ank":"HH:MM",
//   "datum":"YYYY-MM-DD",
//   "spedition":"...",
//   "positionen":[
//     {"fracht":"...", "lagergrp":"...", "sachnr":"...", "behaelter": 0},
//     ...
//   ]
// }

// Fallbacks
$eingang   = trim($data['eingangsnummer'] ?? '');
$ank       = trim($data['ank'] ?? '');
$datum     = trim($data['datum'] ?? '');
$spedition = trim($data['spedition'] ?? '');
$positionen = is_array($data['positionen'] ?? null) ? $data['positionen'] : [];

$templatePath = __DIR__ . '/templates/Wareneingang.xlsx';
if (!is_file($templatePath)) {
  http_response_code(500);
  echo 'Vorlage nicht gefunden: ' . $templatePath;
  exit;
}

// ---- Vorlage laden ----
$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0); // Tabelle1 (erste Tabelle)

// ---- Fixe Spaltenbreiten (falls in Vorlage nicht gesetzt / zur Sicherheit) ----
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(11.57);
$sheet->getColumnDimension('C')->setWidth(33.57);
$sheet->getColumnDimension('D')->setWidth(15.86);
$sheet->getColumnDimension('E')->setWidth(6.71);

// ---- Kopf / feste Zellen ----
$sheet->setCellValue('A1', 'TeamProjekt-Outcourcing');
$sheet->setCellValue('A2', 'Lise-Meitner-Straße 21');
$sheet->setCellValue('A3', '31515 Wunstorf');
$sheet->setCellValue('A5', 'Auszufüllen durch  Büro:');
$sheet->getRowDimension(5)->setRowHeight(26.25);

// Merged-Bereiche sind in der Vorlage bereits definiert.
// Wir schreiben einfach in die linke Zelle (B-Spalte).
$sheet->setCellValue('B7',  $eingang);   // Eingangsnummer (B7:C7)
$sheet->setCellValue('B9',  $ank);       // Ankunft (B9:C9)
$sheet->setCellValue('B11', $datum);     // Datum (B11:C11)
$sheet->setCellValue('B17', $spedition); // Spedition (B17:C17)

// ---- Positionsbereich: Zeilen 58–75 | A:Fracht, B:Lagergrp, C:Sachnr, D:Behälter ----
$startRow = 58;
$maxRows  = 18; // 58..75
$total = 0;

for ($i = 0; $i < $maxRows; $i++) {
  $r = $startRow + $i;
  $pos = $positionen[$i] ?? null;

  $fracht   = is_array($pos) ? (string)($pos['fracht'] ?? '') : '';
  $lagergrp = is_array($pos) ? (string)($pos['lagergrp'] ?? '') : '';
  $sachnr   = is_array($pos) ? (string)($pos['sachnr'] ?? '') : '';
  $behaelter= is_array($pos) ? (int)($pos['behaelter'] ?? 0) : 0;

  // schreiben
  $sheet->setCellValue("A{$r}", $fracht);
  $sheet->setCellValue("B{$r}", $lagergrp);
  $sheet->setCellValue("C{$r}", $sachnr);
  $sheet->setCellValue("D{$r}", $behaelter);

  $total += (int)$behaelter;
}

// ---- Summen ----
// In D77 die Summe (kann Formel sein oder direkt Wert)
$sheet->setCellValue('D77', "=SUM(D58:D75)");
// Zusätzlich in Zeile 29 Spalte D den gleichen Wert anzeigen
$sheet->setCellValue('D29', '=D77');

// Optional: wenn du die tatsächliche Zahl statt Formel willst, kommentier oben aus
// und nimm hier stattdessen:
// $sheet->setCellValue('D77', $total);
// $sheet->setCellValue('D29', $total);

// ---- Ausgeben ----
$filename = 'WA_' . ($eingang !== '' ? preg_replace('/[^\w\-]+/','_',$eingang) : 'Export') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
