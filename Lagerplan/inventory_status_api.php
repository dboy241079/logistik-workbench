<?php
declare(strict_types=1);
require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
if ($action !== 'list') {
  echo json_encode(['ok' => false, 'msg' => 'bad_action'], JSON_UNESCAPED_UNICODE);
  exit;
}

$halle = trim((string)($_GET['halle'] ?? ''));
$zone  = trim((string)($_GET['zone']  ?? ''));
$day   = trim((string)($_GET['day']   ?? ''));

if ($day === '') $day = date('Y-m-d');

if ($halle === '' || $zone === '') {
  echo json_encode(['ok' => false, 'msg' => 'missing_params'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      reihe,
      soll_menge,
      count_menge,
      count_user,
      count_time,
      check_menge,
      check_user,
      check_time,
      status
    FROM inventur_rows
    WHERE halle = :h
      AND zone  = :z
      AND (
        (count_time IS NOT NULL AND DATE(count_time) = :d)
        OR
        (check_time IS NOT NULL AND DATE(check_time) = :d)
      )
    ORDER BY CAST(reihe AS UNSIGNED)
  ");
  $stmt->execute([':h' => $halle, ':z' => $zone, ':d' => $day]);

  $items = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // ✅ Backward-Compat (falls irgendwo noch count_at/check_at erwartet wird)
    $r['count_at'] = $r['count_time'];
    $r['check_at'] = $r['check_time'];
    $items[] = $r;
  }

  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
