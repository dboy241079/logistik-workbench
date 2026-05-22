<?php
declare(strict_types=1);
require __DIR__ . '/../inc/session.php';


require __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB nicht verfügbar'], JSON_UNESCAPED_UNICODE);
  exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
  echo json_encode(['ok' => true, 'loggedIn' => false], JSON_UNESCAPED_UNICODE);
  exit;
}

$sid = session_id();

$stmt = $pdo->prepare("
  INSERT INTO user_sessions (session_id, user_id, last_activity)
  VALUES (:sid, :uid, NOW())
  ON DUPLICATE KEY UPDATE
    user_id = :uid2,
    last_activity = NOW()
");
$stmt->execute([
  ':sid'  => $sid,
  ':uid'  => (int)$uid,
  ':uid2' => (int)$uid,
]);


// Online-User zählen (distinct users)
$stmt = $pdo->query("
  SELECT COUNT(DISTINCT user_id)
  FROM user_sessions
  WHERE last_activity >= (NOW() - INTERVAL 5 MINUTE)
");
$onlineUsersCount = (int)$stmt->fetchColumn();

// Diese Session online?
$stmt = $pdo->prepare("
  SELECT (last_activity >= (NOW() - INTERVAL 5 MINUTE))
  FROM user_sessions
  WHERE session_id = :sid
");
$stmt->execute([':sid' => $sid]);
$isOnline = (bool)$stmt->fetchColumn();

echo json_encode([
  'ok' => true,
  'loggedIn' => true,
  'isOnline' => $isOnline,
  'onlineUsersCount' => $onlineUsersCount,
  'ts' => date('c'),
], JSON_UNESCAPED_UNICODE);
