<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);

require dirname(__DIR__) . '/api/_db.php';

$row = (int)($_GET['row'] ?? 0);
if ($row <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'row fehlt/ungültig']);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      id, halle, zone, reihe, platz, slot_index,
      referenznr, sachnummer, lieferschein, batch_id,
      eingelagert_am, user_name, menge, updated_at
    FROM lager_slots
    WHERE halle = 'H4'
      AND zone  = 'W1'
      AND CAST(reihe AS UNSIGNED) = :row
    ORDER BY platz ASC, slot_index ASC
  ");
  $stmt->execute([':row' => $row]);

  echo json_encode([
    'ok'    => true,
    'halle' => 'H4',
    'zone'  => 'W1',
    'row'   => $row,
    'slots' => $stmt->fetchAll(PDO::FETCH_ASSOC),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
