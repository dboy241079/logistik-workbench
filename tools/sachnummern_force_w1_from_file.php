<?php
declare(strict_types=1);

// /LKW/tools/sachnummern_force_w1_from_file.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Europe/Berlin');
mysqli_report(MYSQLI_REPORT_OFF);

$DB_HOST = 'localhost';
$DB_USER = 'danielstruebig';
$DB_PASS = 'Mikesch01!';
$DB_NAME = 'danielstruebig_lkwfahrer';

$TABLE = 'sachnummern';
$FILE  = __DIR__ . '/sachnummern_neu.txt';
$TARGET_GROUP = 'W1';
$DRY_RUN = isset($_GET['dry']) && $_GET['dry'] === '1';

// ----------------------
// Helper
// ----------------------
function normKey(string $s): string {
    $s = strtoupper(trim(str_replace("\xC2\xA0", ' ', $s)));
    $s = preg_replace('/[^A-Z0-9]/u', '', $s);
    return $s ?? '';
}

function fail(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
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
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

// ----------------------
// Checks
// ----------------------
if (!is_file($FILE)) {
    fail(400, ['ok'=>false, 'error'=>'file_not_found', 'file'=>$FILE]);
}

$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_errno) {
    fail(500, ['ok'=>false, 'error'=>'db_connect_failed', 'details'=>$db->connect_error]);
}
$db->set_charset('utf8mb4');

// ----------------------
// sachnummer_key anlegen (nur wenn fehlt)
// ----------------------
if (!columnExists($db, $TABLE, 'sachnummer_key')) {
    $ok = $db->query("ALTER TABLE `{$TABLE}` ADD COLUMN `sachnummer_key` VARCHAR(64) NULL AFTER `sachnummer`");
    if (!$ok) {
        fail(500, ['ok'=>false, 'error'=>'add_column_failed', 'details'=>$db->error]);
    }
}

// ----------------------
// Backfill fehlender keys
// ----------------------
$backfilled = 0;
$selMissingKey = $db->prepare("SELECT id, sachnummer FROM `{$TABLE}` WHERE sachnummer_key IS NULL OR sachnummer_key = ''");
$updKey        = $db->prepare("UPDATE `{$TABLE}` SET sachnummer_key = ? WHERE id = ?");

if (!$selMissingKey || !$updKey) {
    fail(500, ['ok'=>false, 'error'=>'prepare_backfill_failed', 'details'=>$db->error]);
}

if (!$selMissingKey->execute()) {
    fail(500, ['ok'=>false, 'error'=>'execute_backfill_select_failed', 'details'=>$selMissingKey->error]);
}

$res = $selMissingKey->get_result();
while ($row = $res->fetch_assoc()) {
    $id  = (int)$row['id'];
    $key = normKey((string)$row['sachnummer']);
    if ($key === '') continue;
    $updKey->bind_param('si', $key, $id);
    if ($updKey->execute() && $updKey->affected_rows >= 0) {
        $backfilled++;
    }
}
$selMissingKey->close();
$updKey->close();

// ----------------------
// Keys aus Datei laden (unique)
// ----------------------
$lines = preg_split('/\R/u', (string)file_get_contents($FILE)) ?: [];
$keysSet = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $k = normKey($line);
    if ($k !== '') $keysSet[$k] = true;
}
$keys = array_keys($keysSet);

if (!$keys) {
    echo json_encode([
        'ok' => true,
        'dry_run' => $DRY_RUN,
        'message' => 'Keine gültigen Keys in Datei.',
        'backfilled_existing_keys' => $backfilled
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------
// Sync auf W1
// ----------------------
$countStmt = $db->prepare("
    SELECT 
      COUNT(*) AS total_rows,
      SUM(CASE WHEN lagergruppe = ? THEN 1 ELSE 0 END) AS already_rows
    FROM `{$TABLE}`
    WHERE sachnummer_key = ?
");
$updateStmt = $db->prepare("
    UPDATE `{$TABLE}`
    SET lagergruppe = ?
    WHERE sachnummer_key = ?
      AND lagergruppe <> ?
");

if (!$countStmt || !$updateStmt) {
    fail(500, ['ok'=>false, 'error'=>'prepare_sync_failed', 'details'=>$db->error]);
}

$notFoundKeys = 0;
$alreadyKeys  = 0;
$changedKeys  = 0;
$changedRows  = 0;
$errors       = [];

$db->begin_transaction();
try {
    foreach ($keys as $k) {
        $countStmt->bind_param('ss', $TARGET_GROUP, $k);
        if (!$countStmt->execute()) {
            $errors[] = ['key'=>$k, 'error'=>$countStmt->error];
            continue;
        }
        $row = $countStmt->get_result()->fetch_assoc();
        $total   = (int)($row['total_rows'] ?? 0);
        $already = (int)($row['already_rows'] ?? 0);

        if ($total === 0) {
            $notFoundKeys++;
            continue;
        }

        if ($already === $total) {
            $alreadyKeys++;
            continue;
        }

        if (!$DRY_RUN) {
            $updateStmt->bind_param('sss', $TARGET_GROUP, $k, $TARGET_GROUP);
            if (!$updateStmt->execute()) {
                $errors[] = ['key'=>$k, 'error'=>$updateStmt->error];
                continue;
            }
            $changedRows += max(0, $updateStmt->affected_rows);
        }

        $changedKeys++;
    }

    if ($DRY_RUN) $db->rollback();
    else $db->commit();

} catch (Throwable $e) {
    $db->rollback();
    fail(500, ['ok'=>false, 'error'=>'sync_failed', 'details'=>$e->getMessage()]);
}

$countStmt->close();
$updateStmt->close();

echo json_encode([
    'ok' => true,
    'dry_run' => $DRY_RUN,
    'target_group' => $TARGET_GROUP,
    'keys_in_file_unique' => count($keys),
    'keys_already_target' => $alreadyKeys,
    'keys_changed_to_target' => $changedKeys,
    'rows_changed' => $changedRows,
    'keys_not_found_in_db' => $notFoundKeys,
    'keys_in_target_after_sync' => $alreadyKeys + $changedKeys,
    'backfilled_existing_keys' => $backfilled,
    'errors_count' => count($errors),
    'errors' => $errors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
