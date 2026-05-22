<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// 1) DB einbinden (robust)
$found = false;
$paths = [
  __DIR__ . '/_db.php',
  dirname(__DIR__) . '/api/_db.php',
  dirname(__DIR__, 2) . '/api/_db.php',
];

foreach ($paths as $p) {
  if (is_file($p)) { require $p; $found = true; break; }
}

if (!$found) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB include not found']);
  exit;
}

// 2) Input
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$peek  = isset($_GET['peek']) ? (int)$_GET['peek'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500;

// 3) Aktuellen max_ts aus der DB holen (Quelle der Wahrheit)
$maxAllSql = "
  SELECT MAX(
    UNIX_TIMESTAMP(GREATEST(created_at, IFNULL(updated_at, created_at)))
  ) AS max_ts
  FROM lager_slots
";
$maxAll = (int)($pdo->query($maxAllSql)->fetchColumn() ?: 0);

// Peek-Modus: nur den aktuellen Stand zurückgeben, keine rows (ideal zum Starten)
if ($peek === 1) {
  echo json_encode([
    'ok' => true,
    'since' => $maxAll,
    'server_time' => time(),
    'count' => 0,
    'rows' => []
  ]);
  exit;
}

// 4) Änderungen seit "since"
$sql = "
  SELECT
    id, halle, zone, reihe, platz, slot_index,
    referenznr, sachnummer, lieferschein, batch_id,
    eingelagert_am, user_name, menge, karton_soll,
    created_at, updated_at,
    UNIX_TIMESTAMP(GREATEST(created_at, IFNULL(updated_at, created_at))) AS ts
  FROM lager_slots
  WHERE UNIX_TIMESTAMP(GREATEST(created_at, IFNULL(updated_at, created_at))) > :since
  ORDER BY ts ASC, id ASC
  LIMIT $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':since' => $since]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5) since fürs nächste Polling = max(ts aus rows) ODER maxAll
$maxRows = $since;
foreach ($rows as $r) {
  $t = (int)($r['ts'] ?? 0);
  if ($t > $maxRows) $maxRows = $t;
}
$sinceOut = max($maxAll, $maxRows);

echo json_encode([
  'ok' => true,
  'since' => $sinceOut,
  'server_time' => time(),
  'count' => count($rows),
  'rows' => $rows
]);
