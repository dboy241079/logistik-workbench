<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php'; // <-- PDO muss vor dem Guard da sein

// Rollen dynamisch pro Tab aus DB holen
function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);

  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
  return $roles ?: ['admin']; // Fallback
}

// ================= Auth / Guard =================
$TAB_KEY = 'special';

$AUTH_DEFAULT_TAB   = $TAB_KEY;
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, $TAB_KEY);
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// --- Datum aus GET ---------------------------------------------------------
$day = $_GET['date'] ?? date('Y-m-d');

// Minimale Validierung: yyyy-mm-dd
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400);
    echo 'Ungültiges Datum.';
    exit;
}

// --- Daten holen: nur dieser Tag ------------------------------------------
$sql = "
  SELECT
    q.*,
    COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter
  FROM qc_100_pruefungen q
  LEFT JOIN users u ON q.employee_id = u.id
  WHERE DATE(q.created_at) = :day
  ORDER BY q.created_at ASC, q.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':day' => $day]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
//  Excel aufbauen – 3 Blätter:
//  Blatt 1: 100% Prüfung              (ohne KLT-Spalte)
//  Blatt 2: Etikettierung KLT         (mit Anzahl KLT + Summenzeile)
//  Blatt 3: Umfüllung + Umpacken      (mit Anzahl KLT + Summenzeile)
//
//  Spalten-Basis:
//  A: Sachnummer      (material_no)
//  B: Referenznummer  (pallet_code – als Zahl/Text)
//  C: Datum           (created_at – Datumsteil als Excel-Datum)
//  D: Prüfer          (mitarbeiter)
//  E: Bemerkung       (comment)
//  F: Anzahl KLT      (klt_count) – nur auf Blättern mit $withKlt = true
// ---------------------------------------------------------------------------

$spreadsheet = new Spreadsheet();

function setupHeader(Worksheet $sheet, string $title, ?string $day = null, bool $withKlt = false): void
{
    if ($day) {
        try {
            $dt = new DateTimeImmutable($day);
            $sheetName = $title . ' ' . $dt->format('d.m.Y');
        } catch (Throwable $e) {
            $sheetName = $title;
        }
    } else {
        $sheetName = $title;
    }

    $sheet->setTitle($sheetName);

    // Kopfzeilen
    $sheet->setCellValue('A1', 'Sachnummer');
    $sheet->setCellValue('B1', 'Referenznummer');   // pallet_code
    $sheet->setCellValue('C1', 'Lieferschein');     // NEU: delivery_note
    $sheet->setCellValue('D1', 'Datum');
    $sheet->setCellValue('E1', 'Prüfer');
    $sheet->setCellValue('F1', 'Bemerkung');

    if ($withKlt) {
        $sheet->setCellValue('G1', 'Anzahl KLT');   // verschiebt sich von F → G
    }
}


function writeDataRow(Worksheet $sheet, int &$rowIndex, array $r, bool $withKlt = false): void
{
    // Sachnummer
    $sheet->setCellValue('A' . $rowIndex, (string)($r['material_no'] ?? ''));

    // Referenznummer = Palette / Prüflabel
    $ref = trim((string)($r['pallet_code'] ?? ''));

    if ($ref !== '' && ctype_digit($ref)) {
        $sheet->setCellValueExplicit(
            'B' . $rowIndex,
            (float)$ref,
            DataType::TYPE_NUMERIC
        );
    } else {
        $sheet->setCellValueExplicit(
            'B' . $rowIndex,
            $ref,
            DataType::TYPE_STRING
        );
    }

    // Lieferschein (immer als Text, damit 0612... nicht zerstört wird)
    $ls = (string)($r['delivery_note'] ?? '');
    $sheet->setCellValueExplicit(
        'C' . $rowIndex,
        $ls,
        DataType::TYPE_STRING
    );

    // Datum aus created_at
    $createdAt = $r['created_at'] ?? null;
    if ($createdAt) {
        try {
            $dtRow = new DateTimeImmutable($createdAt);
            $excelDate = ExcelDate::PHPToExcel($dtRow);
            $sheet->setCellValue('D' . $rowIndex, $excelDate);
        } catch (Throwable $e) {
            $sheet->setCellValue('D' . $rowIndex, (string)$createdAt);
        }
    } else {
        $sheet->setCellValue('D' . $rowIndex, '');
    }

    // Prüfer
    $sheet->setCellValue('E' . $rowIndex, (string)($r['mitarbeiter'] ?? ''));

    // Bemerkung
    $sheet->setCellValue('F' . $rowIndex, (string)($r['comment'] ?? ''));

    // Anzahl KLT (nur auf entsprechenden Blättern)
    if ($withKlt) {
        $klt = isset($r['klt_count']) ? (int)$r['klt_count'] : 0;
        $sheet->setCellValue('G' . $rowIndex, $klt > 0 ? $klt : 0);
    }

    $rowIndex++;
}


/**
 * Styling auf ein Sheet anwenden
 *
 * $withKltSum = true → unten Summenzeile für KLT (nur sinnvoll, wenn $withKlt = true)
 */
function styleSheet(Worksheet $sheet, int $lastRow, bool $withKlt = false, bool $withKltSum = false): void
{
    if ($lastRow < 1) {
        $lastRow = 1;
    }

    // Durch neue Spalte "Lieferschein" verschieben sich die letzten Spalten
    $lastCol = $withKlt ? 'G' : 'F';

    // Datenbereich endet bei der letzten Datenzeile (ohne Summenzeile)
    $dataEndRow = $lastRow;

    // Summenzeile für KLT (nur wenn es Datenzeilen gibt)
    if ($withKlt && $withKltSum && $lastRow >= 2) {
        $totalRow = $lastRow + 1;

        // Label + Formel (jetzt F+G, weil G die KLT-Spalte ist)
        $sheet->setCellValue('F' . $totalRow, 'Summe KLT');
        $sheet->setCellValue('G' . $totalRow, sprintf('=SUM(G2:G%d)', $lastRow));

        // Fett markieren
        $sheet->getStyle('F' . $totalRow . ':G' . $totalRow)
              ->getFont()
              ->setBold(true);

        // Summenzeile in unseren "letzten Row" aufnehmen
        $lastRow = $totalRow;
    }

    // Header-Stil
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'color' => ['rgb' => '2563EB'], // blau
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => 'CBD5E1'],
            ],
        ],
    ];

    $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(20);

    // Rahmen für gesamten Bereich (inkl. Summenzeile, falls vorhanden)
    $fullRange = 'A1:' . $lastCol . $lastRow;
    $sheet->getStyle($fullRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => 'E5E7EB'],
            ],
        ],
    ]);

    // Spaltenbreiten
    $cols = ['A', 'B', 'C', 'D', 'E', 'F'];
    if ($withKlt) {
        $cols[] = 'G';
    }
    foreach ($cols as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    if ($dataEndRow >= 2) {
        // B: Referenznummer als Zahl ohne Komma
        $sheet->getStyle('B2:B' . $dataEndRow)
              ->getNumberFormat()
              ->setFormatCode('0');

        // D: Datum
        $sheet->getStyle('D2:D' . $dataEndRow)
              ->getNumberFormat()
              ->setFormatCode('dd.mm.yyyy');

        // G: Anzahl KLT (falls vorhanden, inkl. Summenzeile)
        if ($withKlt) {
            $sheet->getStyle('G2:G' . $lastRow)
                  ->getNumberFormat()
                  ->setFormatCode('0');
        }

        // Ausrichtungen
        $sheet->getStyle('A2:A' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('B2:B' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Lieferschein als Text links
        $sheet->getStyle('C2:C' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Datum mittig
        $sheet->getStyle('D2:D' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('E2:E' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('F2:F' . $lastRow)
              ->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_LEFT)
              ->setWrapText(true);

        if ($withKlt) {
            $sheet->getStyle('G2:G' . $lastRow)
                  ->getAlignment()
                  ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    // AutoFilter nur über Datenbereich (ohne Summenzeile)
    $sheet->setAutoFilter('A1:' . $lastCol . $dataEndRow);

    // Header fixieren
    $sheet->freezePane('A2');
}


// ---------------------------------------------------------------------------
//  Blätter anlegen
// ---------------------------------------------------------------------------

// Blatt 1: 100% Prüfung – ohne KLT-Spalte
$sheet100 = $spreadsheet->getActiveSheet();
setupHeader($sheet100, '100% Prüfung', $day, false);
$row100 = 2;

// Blatt 2: Etikettierung KLT – mit Anzahl KLT + Summenzeile
$sheetEtik = $spreadsheet->createSheet();
setupHeader($sheetEtik, 'Etikettierung KLT', $day, true);
$rowEtik = 2;

// Blatt 3: Umfüllung + Umpacken – mit Anzahl KLT + Summenzeile
$sheetUmp = $spreadsheet->createSheet();
setupHeader($sheetUmp, 'Umfüllung + Umpacken', $day, true);
$rowUmp = 2;

// ---------------------------------------------------------------------------
//  Daten nach Grund verteilen
// ---------------------------------------------------------------------------

foreach ($rows as $r) {
    $reason = $r['reason'] ?? '';

    switch ($reason) {
        case '100% Prüfung':
            writeDataRow($sheet100, $row100, $r, false);
            break;

        case 'Etikettierung KLT':
            writeDataRow($sheetEtik, $rowEtik, $r, true);
            break;

        case 'Umfüllung in KLT':
        case 'Umpacken auf Palette':
            writeDataRow($sheetUmp, $rowUmp, $r, true);
            break;

        default:
            // andere Gründe aktuell ignorieren
            break;
    }
}

// Letzte Datenzeilen bestimmen
$lastRow100 = max(1, $row100 - 1);
$lastRowEtik = max(1, $rowEtik - 1);
$lastRowUmp  = max(1, $rowUmp - 1);

// Styling pro Blatt
styleSheet($sheet100, $lastRow100, false, false); // keine KLT-Summe
styleSheet($sheetEtik, $lastRowEtik, true, true); // mit KLT-Summe
styleSheet($sheetUmp,  $lastRowUmp,  true, true); // mit KLT-Summe

// ---------------------------------------------------------------------------
//  Download an den Browser schicken
// ---------------------------------------------------------------------------

$filename = 'Identitaetspruefung_' . $day . '.xlsx';

// Falls vorher schon Output passiert ist (zur Sicherheit)
if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
