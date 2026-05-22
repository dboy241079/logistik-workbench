<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../api/_db.php';

try {
  $slotId = (int)($_GET['slot_id'] ?? 0);
  if ($slotId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'slot_id fehlt.']);
    exit;
  }

  $st = $pdo->prepare("
    SELECT id,
           referenznr,
           sachnummer,
           menge,
           lieferschein,
           created_at,
           created_by
      FROM lager_slot_items
     WHERE slot_id=?
     ORDER BY id ASC
  ");
  $st->execute([$slotId]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'items'=>$items]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
