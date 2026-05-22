<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';



try{
  $in   = json_decode(file_get_contents('php://input'), true) ?: [];
  $veh  = (string)($in['veh_id'] ?? '');
  $date = (string)($in['date']   ?? '');
  $tour = (int)($in['tour']      ?? 0);
  $f    = (array)($in['fields']  ?? []);

  if (!$veh || !preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) || $tour<1) {
    throw new RuntimeException('Bad params');
  }

  // existierende Zeile sicherstellen
  $pdo->prepare("INSERT IGNORE INTO driver_stamps (veh_id,date,tour) VALUES (:v,:d,:t)")
      ->execute([':v'=>$veh,':d'=>$date,':t'=>$tour]);

 $allowed = [
  'workStart','arriveWU','departWU',
  'arriveH','departH','hannoverHall',
  'arriveH2','departH2','hannoverHall2',   // 🆕
  'note','reported','reportedWhy',
  'workEnd','pauseStart','pauseEnd'
];


  $set = []; $par = [':v'=>$veh,':d'=>$date,':t'=>$tour];
  foreach ($f as $k=>$val){
    if (!in_array($k, $allowed, true)) continue;
    $set[] = "`$k` = :$k";
    $par[":$k"] = ($val === '' ? null : (string)$val);
  }
  if (!$set) throw new RuntimeException('No fields');

  $sql = "UPDATE driver_stamps SET ".implode(',',$set)." WHERE veh_id=:v AND date=:d AND tour=:t";
  $pdo->prepare($sql)->execute($par);

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
