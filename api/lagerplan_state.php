<?php
// /LKW/api/lagerplan_state.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

session_start();
if (empty($_SESSION['username'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
  exit;
}

$plan  = $_GET['plan'] ?? '';
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

$allowed = ['halle3', 'container'];
if (!in_array($plan, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_plan']);
  exit;
}

// ✅ DB connect: passe den include an deine Struktur an
require_once __DIR__ . '/../inc/db.php'; // <-- z.B. PDO in $pdo

// WICHTIG: Passe Tabelle + Spalten an!
// Erwartet: slot_code (string), status (string/int), label (string), updated_at (datetime)
$sql = "
  SELECT
    slot_code AS slot,
    status,
    label,
    UNIX_TIMESTAMP(updated_at) AS ts
  FROM lagerplan_state
  WHERE plan = :plan
    AND UNIX_TIMESTAMP(updated_at) > :since
  ORDER BY updated_at ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':plan' => $plan,
  ':since' => $since
]);

$changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$lastTs = $since;

foreach ($changes as $c) {
  $t = (int)($c['ts'] ?? 0);
  if ($t > $lastTs) $lastTs = $t;
}

echo json_encode([
  'ok' => true,
  'plan' => $plan,
  'serverTs' => time(),
  'lastTs' => $lastTs,
  'changes' => $changes
]);
