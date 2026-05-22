<?php
declare(strict_types=1);

// /LKW/tools/sachnummern_import_w1_new_only.php
// Liest Sachnummern (eine pro Zeile) aus sachnummern_neu.txt
// und fügt NUR neue Nummern in `sachnummern` ein (lagergruppe = W1).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Europe/Berlin');
mysqli_report(MYSQLI_REPORT_OFF);

// ======================
// DB
// ======================
$DB_HOST = 'localhost';
$DB_USER = 'danielstruebig';
$DB_PASS = 'Mikesch01!';
$DB_NAME = 'danielstruebig_lkwfahrer';

// ======================
// Import-Konfig
// ======================
$TABLE = 'sachnummern';
$COL_SACH = 'sachnummer';
$COL_GROUP = 'lagergruppe';
$GROUP_VALUE = 'W1';

// Quelle: eine Nummer pro Zeile
$SOURCE_FILE = __DIR__ . '/sachnummern_neu.txt';

// true => nur prüfen, nichts schreiben
$DRY_RUN = false;

// ======================
// Helper
// ======================
function normSach(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s); // NBSP -> space
    $s = trim($s);
    $s = strtoupper($s);
    // WICHTIG: interne Spaces bleiben erhalten (z.B. "N  01508210")
    return $s;
}

function tableExists(mysqli $db, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
            LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
}

function columnExists(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
}

// ======================
// Start
// ======================
if (!is_file($SOURCE_FILE)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'source_file_not_found',
        'file' => $SOURCE_FILE,
        'hint' => 'Lege /LKW/tools/sachnummern_neu.txt an (eine Sachnummer pro Zeile).'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($SOURCE_FILE);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'source_file_read_failed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_connect_failed',
        'details' => $db->connect_error
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$db->set_charset('utf8mb4');

if (!tableExists($db, $TABLE)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'table_not_found', 'table' => $TABLE], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
if (!columnExists($db, $TABLE, $COL_SACH) || !columnExists($db, $TABLE, $COL_GROUP)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'required_columns_missing',
        'required' => [$COL_SACH, $COL_GROUP]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) Einlesen + normalisieren + dedupe
$lines = preg_split('/\R/u', $raw) ?: [];
$seen = [];
$list = [];
$duplicatesInInput = [];

foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '') continue;
    if (str_starts_with($line, '#')) continue; // optionale Kommentare

    $nr = normSach($line);
    if ($nr === '') continue;

    if (isset($seen[$nr])) {
        $duplicatesInInput[$nr] = true;
        continue;
    }
    $seen[$nr] = true;
    $list[] = $nr;
}

if (!$list) {
    echo json_encode([
        'ok' => true,
        'message' => 'Keine gültigen Sachnummern gefunden.',
        'input_lines' => count($lines)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2) Bereits vorhandene Nummern laden (chunked IN)
$existing = [];
$selectErrors = [];

foreach (array_chunk($list, 500) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "SELECT `$COL_SACH` FROM `$TABLE` WHERE `$COL_SACH` IN ($ph)";
    $st = $db->prepare($sql);
    if (!$st) {
        $selectErrors[] = $db->error;
        continue;
    }

    $types = str_repeat('s', count($chunk));
    $params = [$types];
    foreach ($chunk as $k => $v) $params[] = &$chunk[$k];
    call_user_func_array([$st, 'bind_param'], $params);

    if (!$st->execute()) {
        $selectErrors[] = $st->error;
        $st->close();
        continue;
    }

    $st->bind_result($found);
    while ($st->fetch()) {
        $existing[(string)$found] = true;
    }
    $st->close();
}

// 3) Fehlende bestimmen
$missing = [];
foreach ($list as $nr) {
    if (!isset($existing[$nr])) {
        $missing[] = $nr;
    }
}

if ($DRY_RUN) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'lagergruppe' => $GROUP_VALUE,
        'input_lines' => count($lines),
        'unique_numbers' => count($list),
        'duplicates_in_input_count' => count($duplicatesInInput),
        'already_in_db' => count($existing),
        'to_insert' => count($missing),
        'missing_numbers' => $missing,
        'select_errors' => $selectErrors
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 4) Nur fehlende einfügen (Race-safe mit NOT EXISTS)
$inserted = 0;
$insertErrors = [];

$sqlIns = "INSERT INTO `$TABLE` (`$COL_SACH`, `$COL_GROUP`)
           SELECT ?, ?
           FROM DUAL
           WHERE NOT EXISTS (
             SELECT 1 FROM `$TABLE` WHERE `$COL_SACH` = ?
           )";
$stIns = $db->prepare($sqlIns);
if (!$stIns) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'prepare_insert_failed', 'details' => $db->error], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$db->begin_transaction();
try {
    foreach ($missing as $nr) {
        $stIns->bind_param('sss', $nr, $GROUP_VALUE, $nr);
        if (!$stIns->execute()) {
            $insertErrors[] = ['sachnummer' => $nr, 'error' => $stIns->error];
            continue;
        }
        if ($stIns->affected_rows === 1) $inserted++;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    $stIns->close();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'insert_failed',
        'details' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$stIns->close();

echo json_encode([
    'ok' => true,
    'table' => $TABLE,
    'lagergruppe_for_new' => $GROUP_VALUE,
    'input_lines' => count($lines),
    'unique_numbers_after_cleanup' => count($list),
    'duplicates_in_input_count' => count($duplicatesInInput),
    'already_in_db' => count($existing),
    'missing_before_insert' => count($missing),
    'inserted' => $inserted,
    'skipped_existing' => count($missing) - $inserted,
    'select_errors_count' => count($selectErrors),
    'insert_errors_count' => count($insertErrors),
    'duplicates_in_input' => array_keys($duplicatesInInput),
    'insert_errors' => $insertErrors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
