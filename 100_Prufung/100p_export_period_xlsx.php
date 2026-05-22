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

// --- Parameter --------------------------------------------------------
$mode   = $_GET['mode']   ?? 'today'; // today | week | month
$hall   = $_GET['hall']   ?? '';
$reason = $_GET['reason'] ?? '';

$allowedHalls = ['W1','X3','Banking','G9'];
$allowedReasons = [
  '100% Prüfung',
  'Etikettierung KLT',
  'Umpacken auf Palette',
  'Umfüllung in KLT'
];

// Helfer für Wochentag (kurz/lang, wie du magst)
function weekday_de(string $dateOrDatetime): string {
    $ts = strtotime($dateOrDatetime);
    if (!$ts) return '';
    $n = (int)date('N', $ts); // 1=Mo ... 7=So
    $names = [
      1 => 'Montag',
      2 => 'Dienstag',
      3 => 'Mittwoch',
      4 => 'Donnerstag',
      5 => 'Freitag',
      6 => 'Samstag',
      7 => 'Sonntag',
    ];
    return $names[$n] ?? '';
}

// --- Zeitraum bestimmen ----------------------------------------------
$today = date('Y-m-d');

switch ($mode) {
    case 'week':
        $todayTs   = strtotime($today);
        $weekStart = date('Y-m-d', strtotime('monday this week', $todayTs));
        $weekEnd   = date('Y-m-d', strtotime('sunday this week', $todayTs));
        $from      = $weekStart;
        $to        = $weekEnd;
        $label     = 'Woche_' . date('d.m.', strtotime($weekStart)) . '-' . date('d.m.Y', strtotime($weekEnd));
        break;

    case 'month':
        $todayTs   = strtotime($today);
        $monthStart= date('Y-m-01', $todayTs);
        $monthEnd  = date('Y-m-t',  $todayTs);
        $from      = $monthStart;
        $to        = $monthEnd;
        $label     = 'Monat_' . date('m.Y', $todayTs);
        break;

    case 'today':
    default:
        $from  = $today;
        $to    = $today;
        $label = 'Heute_' . date('d.m.Y', strtotime($today));
        break;
}

// --- Daten holen (wie im Dashboard) ----------------------------------
$sql = "
  SELECT 
    q.*,
    COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter
  FROM qc_100_pruefungen q
  LEFT JOIN users u ON q.employee_id = u.id
  WHERE DATE(q.created_at) BETWEEN :von AND :bis
";
$params = [
  ':von' => $from,
  ':bis' => $to,
];

if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
  $sql .= " AND q.hall = :hall";
  $params[':hall'] = $hall;
}

if ($reason !== '' && in_array($reason, $allowedReasons, true)) {
  $sql .= " AND q.reason = :reason";
  $params[':reason'] = $reason;
}

$sql .= " ORDER BY q.created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- Excel aufbauen --------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('QC ' . $label);

// Kopfzeile
$header = [
    'Datum/Zeit',
    'Tag',
    'Start',
    'Ende',
    'Dauer (min)',
    'Palette',
    'Lieferschein',
    'Sachnummer',
    'Grund',
    'Ergebnis',
    'Mitarbeiter',
    'Kommentar',
];

$sheet->fromArray($header, null, 'A1');
$sheet->getStyle('A1:L1')->getFont()->setBold(true);

// Datenzeilen
$rowIndex = 2;

foreach ($rows as $r) {
    $dt      = new DateTimeImmutable($r['created_at']);
    $weekday = weekday_de($r['created_at']);
    $start   = $r['time_start'] ? substr($r['time_start'], 0, 5) : '';
    $end     = $r['time_end']   ? substr($r['time_end'],   0, 5) : '';
    $dur     = $r['duration_min'] !== null ? (int)$r['duration_min'] : null;

    $sheet->setCellValue('A' . $rowIndex, $dt->format('Y-m-d H:i:s'));
    $sheet->setCellValue('B' . $rowIndex, $weekday);
    $sheet->setCellValue('C' . $rowIndex, $start);
    $sheet->setCellValue('D' . $rowIndex, $end);
    $sheet->setCellValue('E' . $rowIndex, $dur);
    $sheet->setCellValue('F' . $rowIndex, $r['pallet_code']   ?? '');
    $sheet->setCellValue('G' . $rowIndex, $r['delivery_note']  ?? '');
    $sheet->setCellValue('H' . $rowIndex, $r['material_no']    ?? '');
    $sheet->setCellValue('I' . $rowIndex, $r['reason']         ?? '');
    $sheet->setCellValue('J' . $rowIndex, $r['result']         ?? '');
    $sheet->setCellValue('K' . $rowIndex, $r['mitarbeiter']    ?? '');
    $sheet->setCellValue('L' . $rowIndex, $r['comment']        ?? '');

    $rowIndex++;
}

// Spaltenbreite automatisch
foreach (range('A', 'L') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- Download senden -------------------------------------------------
$filename = '100p_QC_' . $label . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
