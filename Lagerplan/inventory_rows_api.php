<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'not_logged_in'], JSON_UNESCAPED_UNICODE);
  exit;
}

$halle = trim((string)($_GET['halle'] ?? ''));
$lgs   = trim((string)($_GET['lgs'] ?? ''));

$lgArr = array_values(array_filter(array_map('trim', explode(',', $lgs))));
$lgArr = array_values(array_unique($lgArr));

if ($halle === '') {
  echo json_encode(['ok'=>false,'msg'=>'missing_halle'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!$lgArr) {
  echo json_encode(['ok'=>true,'rows'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $in = implode(',', array_fill(0, count($lgArr), '?'));

  // ✅ Reihen, in denen Sachnummern liegen, deren lagergruppe in den gewählten LGs ist
  $sql = "
    SELECT DISTINCT s.reihe
    FROM lager_slots s
    JOIN sachnummern sn
      ON sn.sachnummer = s.sachnummer
    WHERE s.halle = ?
      AND sn.lagergruppe IN ($in)
      AND s.deleted_at IS NULL
      AND s.sachnummer IS NOT NULL
      AND s.sachnummer <> ''
    ORDER BY s.reihe
  ";

  $params = array_merge([$halle], $lgArr);

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
  $rows = array_map('strval', $rows);

  echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
