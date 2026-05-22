<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../inc/session.php';

require __DIR__ . '/_db.php';

function out($ok, $extra=[], $code=200){
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$items = $data['items'] ?? null;
if (!is_array($items)) out(false, ['error'=>'bad_payload','msg'=>'items fehlt'], 400);

// TODO: Tabellenname anpassen!
// Voraussetzung: UNIQUE KEY (veh_id, date, tour)
$sql = "
  INSERT INTO driver_stamps
    (veh_id, date, tour, arriveWU, departWU, updated_at)
  VALUES
    (:veh_id, :date, :tour, :arriveWU, :departWU, NOW())
  ON DUPLICATE KEY UPDATE
    arriveWU = VALUES(arriveWU),
    departWU = VALUES(departWU),
    updated_at = NOW()
";

$stmt = $pdo->prepare($sql);

$imported = 0;
$pdo->beginTransaction();
try{
  foreach($items as $it){
    $veh = trim((string)($it['veh_id'] ?? ''));
    $date = trim((string)($it['date'] ?? ''));
    $tour = trim((string)($it['tour'] ?? '1'));
    $arr  = trim((string)($it['arriveWU'] ?? ''));
    $dep  = trim((string)($it['departWU'] ?? ''));

    if($veh==='' || $date==='' || $tour==='') continue;

    $stmt->execute([
  ':veh_id'   => $veh,
  ':date'     => $date,
  ':tour'     => (int)$tour,
  ':arriveWU' => ($arr===''?null:$arr),
  ':departWU' => ($dep===''?null:$dep),
]);

    $imported++;
  }
  $pdo->commit();
  out(true, ['imported'=>$imported]);
}catch(Throwable $e){
  $pdo->rollBack();
  out(false, ['error'=>'exception','msg'=>$e->getMessage()], 500);
}
