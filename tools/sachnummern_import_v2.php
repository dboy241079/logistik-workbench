<?php
declare(strict_types=1);

/**
 * /LKW/tools/sachnummern_import_v2.php
 *
 * Importiert nur neue Sachnummern in Tabelle `sachnummern`
 * Vergleich über `sachnummer_key` (normalisiert):
 *   - UPPERCASE
 *   - nur A-Z0-9
 *   - entfernt Leerzeichen, '-', '.', etc.
 *
 * Quelle:
 *   1) POST "raw" (Textarea-Inhalt), oder
 *   2) Datei /LKW/tools/sachnummern_neu.txt (eine Nummer pro Zeile)
 *
 * GET-Parameter:
 *   ?dry=1      => nur Vorschau, kein Insert
 *   ?group=W1   => Lagergruppe für neue Nummern (Default W1)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Europe/Berlin');
mysqli_report(MYSQLI_REPORT_OFF);

// =======================
// KONFIG
// =======================
$DB_HOST = 'localhost';
$DB_USER = 'danielstruebig';
$DB_PASS = 'Mikesch01!';
$DB_NAME = 'danielstruebig_lkwfahrer';

$TABLE = 'sachnummern';
$COL_ID = 'id';
$COL_SACH = 'sachnummer';
$COL_KEY = 'sachnummer_key';
$COL_GROUP = 'lagergruppe';

$SOURCE_FILE = __DIR__ . '/sachnummern_neu.txt';
$DEFAULT_GROUP = 'W1';

$ALLOWED_GROUPS = ['W1','X3','X3(B)','G9','B1','B1(T)','Bauteile','BM','Müll','Sarajevo'];

$dryRun = isset($_GET['dry']) && (string)$_GET['dry'] === '1';
$group = isset($_GET['group']) ? trim((string)$_GET['group']) : $DEFAULT_GROUP;
if (!in_array($group, $ALLOWED_GROUPS, true)) {
    $group = $DEFAULT_GROUP;
}

// =======================
// HELPER
// =======================
function fail(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeDisplay(string $s): string {
    $s = str_replace("\xC2\xA0", ' ', $s); // NBSP -> Space
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s); // Mehrfachspaces -> 1
    return strtoupper($s);
}

function normalizeKey(string $s): string {
    $s = strtoupper(trim($s));
    // Nur A-Z0-9 behalten
    $s = preg_replace('/[^A-Z0-9]/u', '', $s);
    return $s ?? '';
}

function tableExists(mysqli $db, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
            LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

function columnExists(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

function indexExists(mysqli $db, string $table, string $indexName): bool {
    $sql = "SHOW INDEX FROM `{$table}` WHERE Key_name = ?";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $indexName);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    $st->close();
    return $ok;
}

function bindDynamicParams(mysqli_stmt $stmt, array $values): void {
    $types = str_repeat('s', count($values));
    $bind = [$types];
    foreach ($values as $k => $v) {
        $bind[] = &$values[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

// =======================
// DB CONNECT
// =======================
$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) {
    fail(500, [
        'ok' => false,
        'error' => 'db_connect_failed',
        'details' => $db->connect_error
    ]);
}
$db->set_charset('utf8mb4');

// =======================
// SCHEMA CHECK
// =======================
if (!tableExists($db, $TABLE)) {
    fail(400, ['ok' => false, 'error' => 'table_not_found', 'table' => $TABLE]);
}
if (!columnExists($db, $TABLE, $COL_ID) ||
    !columnExists($db, $TABLE, $COL_SACH) ||
    !columnExists($db, $TABLE, $COL_GROUP)) {
    fail(400, [
        'ok' => false,
        'error' => 'required_columns_missing',
        'required' => [$COL_ID, $COL_SACH, $COL_GROUP]
    ]);
}

// `sachnummer_key` anlegen, falls nicht vorhanden
if (!columnExists($db, $TABLE, $COL_KEY)) {
    $sqlAdd = "ALTER TABLE `{$TABLE}` ADD COLUMN `{$COL_KEY}` VARCHAR(64) NULL AFTER `{$COL_SACH}`";
    if (!$db->query($sqlAdd)) {
        fail(500, [
            'ok' => false,
            'error' => 'add_key_column_failed',
            'details' => $db->error
        ]);
    }
}

// Backfill key für vorhandene Datensätze (NULL / leer)
$selBackfill = $db->prepare("SELECT `{$COL_ID}`, `{$COL_SACH}` FROM `{$TABLE}` WHERE `{$COL_KEY}` IS NULL OR `{$COL_KEY}` = ''");
if (!$selBackfill) {
    fail(500, ['ok' => false, 'error' => 'prepare_backfill_select_failed', 'details' => $db->error]);
}
if (!$selBackfill->execute()) {
    fail(500, ['ok' => false, 'error' => 'execute_backfill_select_failed', 'details' => $selBackfill->error]);
}
$resBackfill = $selBackfill->get_result();

$updBackfill = $db->prepare("UPDATE `{$TABLE}` SET `{$COL_KEY}` = ? WHERE `{$COL_ID}` = ?");
if (!$updBackfill) {
    fail(500, ['ok' => false, 'error' => 'prepare_backfill_update_failed', 'details' => $db->error]);
}

$backfilled = 0;
while ($row = $resBackfill->fetch_assoc()) {
    $id = (int)$row[$COL_ID];
    $key = normalizeKey((string)$row[$COL_SACH]);
    if ($key === '') continue;
    $updBackfill->bind_param('si', $key, $id);
    if ($updBackfill->execute()) {
        $backfilled++;
    }
}
$updBackfill->close();
$selBackfill->close();

// Duplikate nach KEY in DB prüfen (nach Backfill!)
$sqlDup = "SELECT `{$COL_KEY}` AS key_norm,
                  COUNT(*) AS cnt,
                  GROUP_CONCAT(CONCAT(`{$COL_ID}`, ':', `{$COL_SACH}`) ORDER BY `{$COL_ID}` SEPARATOR ' | ') AS eintraege
           FROM `{$TABLE}`
           WHERE `{$COL_KEY}` IS NOT NULL AND `{$COL_KEY}` <> ''
           GROUP BY `{$COL_KEY}`
           HAVING COUNT(*) > 1
           LIMIT 100";
$dupRes = $db->query($sqlDup);
if ($dupRes === false) {
    fail(500, ['ok' => false, 'error' => 'duplicate_check_failed', 'details' => $db->error]);
}

$duplicateGroups = [];
while ($d = $dupRes->fetch_assoc()) {
    $duplicateGroups[] = [
        'key_norm' => $d['key_norm'],
        'cnt' => (int)$d['cnt'],
        'eintraege' => $d['eintraege']
    ];
}

// Wenn in DB noch Dubletten vorhanden: sauber abbrechen
if (count($duplicateGroups) > 0) {
    fail(409, [
        'ok' => false,
        'error' => 'existing_duplicate_keys_in_db',
        'message' => 'In der DB gibt es noch Dubletten nach sachnummer_key. Erst bereinigen, dann Import erneut starten.',
        'duplicate_group_count' => count($duplicateGroups),
        'examples' => $duplicateGroups
    ]);
}

// Unique-Index auf key setzen, falls fehlt
$uniqueName = 'uq_sachnummer_key';
if (!indexExists($db, $TABLE, $uniqueName)) {
    $sqlUnique = "ALTER TABLE `{$TABLE}` ADD UNIQUE KEY `{$uniqueName}` (`{$COL_KEY}`)";
    if (!$db->query($sqlUnique)) {
        fail(500, [
            'ok' => false,
            'error' => 'add_unique_index_failed',
            'details' => $db->error
        ]);
    }
}

// =======================
// QUELLE LADEN (POST raw ODER Datei)
// =======================
$raw = '';
if (isset($_POST['raw']) && trim((string)$_POST['raw']) !== '') {
    $raw = (string)$_POST['raw'];
} else {
    if (!is_file($SOURCE_FILE)) {
        fail(400, [
            'ok' => false,
            'error' => 'source_file_not_found',
            'file' => $SOURCE_FILE,
            'hint' => 'Datei anlegen (eine Sachnummer pro Zeile) oder per POST[raw] senden.'
        ]);
    }
    $raw = (string)file_get_contents($SOURCE_FILE);
}

$lines = preg_split('/\R/u', $raw) ?: [];

// Input normalisieren + dedupe über KEY
$inputUniqueByKey = []; // key => ['display' => ..., 'line' => ...]
$inputDupKeys = [];     // key => [..werte..]
$invalidLines = [];

foreach ($lines as $idx => $lineRaw) {
    $lineNo = $idx + 1;
    $line = trim((string)$lineRaw);
    if ($line === '') continue;
    if (str_starts_with($line, '#')) continue; // optional Kommentarzeile

    $display = normalizeDisplay($line);
    $key = normalizeKey($display);

    if ($key === '') {
        $invalidLines[] = ['line' => $lineNo, 'value' => $lineRaw, 'error' => 'empty_after_normalization'];
        continue;
    }

    if (isset($inputUniqueByKey[$key])) {
        $inputDupKeys[$key][] = $display;
        continue;
    }

    $inputUniqueByKey[$key] = [
        'display' => $display,
        'line' => $lineNo
    ];
}

if (count($inputUniqueByKey) === 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'Keine gültigen Sachnummern in der Quelle gefunden.',
        'input_lines' => count($lines),
        'invalid_lines' => $invalidLines
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Bereits vorhandene KEYS holen
$keys = array_keys($inputUniqueByKey);
$existingKeys = [];

foreach (array_chunk($keys, 500) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "SELECT `{$COL_KEY}` FROM `{$TABLE}` WHERE `{$COL_KEY}` IN ($ph)";
    $st = $db->prepare($sql);
    if (!$st) {
        fail(500, ['ok' => false, 'error' => 'prepare_existing_select_failed', 'details' => $db->error]);
    }
    bindDynamicParams($st, $chunk);
    if (!$st->execute()) {
        fail(500, ['ok' => false, 'error' => 'execute_existing_select_failed', 'details' => $st->error]);
    }
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $existingKeys[(string)$r[$COL_KEY]] = true;
    }
    $st->close();
}

// Fehlende bestimmen
$missing = []; // [['display'=>..., 'key'=>...], ...]
foreach ($inputUniqueByKey as $key => $obj) {
    if (!isset($existingKeys[$key])) {
        $missing[] = ['display' => $obj['display'], 'key' => $key];
    }
}

if ($dryRun) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'table' => $TABLE,
        'lagergruppe_for_new' => $group,
        'input_lines' => count($lines),
        'valid_unique_keys' => count($inputUniqueByKey),
        'duplicates_in_input_count' => count($inputDupKeys),
        'already_in_db' => count($existingKeys),
        'to_insert' => count($missing),
        'to_insert_preview' => array_slice($missing, 0, 300),
        'invalid_lines' => $invalidLines,
        'backfilled_existing_keys' => $backfilled
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// =======================
// INSERT NUR NEUE
// =======================
$inserted = 0;
$insertErrors = [];
$insertedItems = [];

$stIns = $db->prepare(
    "INSERT INTO `{$TABLE}` (`{$COL_SACH}`, `{$COL_KEY}`, `{$COL_GROUP}`) VALUES (?, ?, ?)"
);
if (!$stIns) {
    fail(500, ['ok' => false, 'error' => 'prepare_insert_failed', 'details' => $db->error]);
}

$db->begin_transaction();
try {
    foreach ($missing as $m) {
        $disp = $m['display'];
        $key  = $m['key'];
        $stIns->bind_param('sss', $disp, $key, $group);

        if (!$stIns->execute()) {
            // Sollte mit Unique-Key nur bei Race/Doppel passieren
            if ((int)$stIns->errno === 1062) {
                continue;
            }
            $insertErrors[] = [
                'sachnummer' => $disp,
                'sachnummer_key' => $key,
                'error' => $stIns->error
            ];
            continue;
        }

        if ($stIns->affected_rows === 1) {
            $inserted++;
            $insertedItems[] = ['sachnummer' => $disp, 'sachnummer_key' => $key];
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    $stIns->close();
    fail(500, [
        'ok' => false,
        'error' => 'insert_transaction_failed',
        'details' => $e->getMessage()
    ]);
}
$stIns->close();

echo json_encode([
    'ok' => true,
    'table' => $TABLE,
    'lagergruppe_for_new' => $group,
    'input_lines' => count($lines),
    'valid_unique_keys' => count($inputUniqueByKey),
    'duplicates_in_input_count' => count($inputDupKeys),
    'already_in_db' => count($existingKeys),
    'missing_before_insert' => count($missing),
    'inserted' => $inserted,
    'insert_errors_count' => count($insertErrors),
    'invalid_lines_count' => count($invalidLines),
    'backfilled_existing_keys' => $backfilled,
    'duplicates_in_input' => array_keys($inputDupKeys),
    'inserted_items' => $insertedItems,
    'insert_errors' => $insertErrors,
    'invalid_lines' => $invalidLines
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
