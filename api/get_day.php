<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';

const TOURS_PER_DAY = 4;

try {
  $veh_id = $_GET['veh_id'] ?? '';
  $date   = $_GET['date']   ?? '';
  if (!preg_match('~^[\w\-]{1,64}$~',$veh_id)) throw new RuntimeException("veh_id ungültig");
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~',$date)) throw new RuntimeException("date ungültig");

  $pdo->exec("CREATE TABLE IF NOT EXISTS driver_stamps (
    veh_id        VARCHAR(64)  NOT NULL,
    date          DATE         NOT NULL,
    tour          INT UNSIGNED NOT NULL,
    arriveH2      CHAR(5)      DEFAULT NULL,
    departH2      CHAR(5)      DEFAULT NULL,
    hannoverHall2 VARCHAR(10)  DEFAULT NULL,
    workStart     CHAR(5)      DEFAULT NULL,
    arriveWU      CHAR(5)      DEFAULT NULL,
    departWU      CHAR(5)      DEFAULT NULL,
    arriveH       CHAR(5)      DEFAULT NULL,
    departH       CHAR(5)      DEFAULT NULL,
    hannoverHall  VARCHAR(10)  DEFAULT NULL,
    note          TEXT         DEFAULT NULL,
    reported      VARCHAR(20)  DEFAULT NULL,
    reportedWhy   TEXT         DEFAULT NULL,
    workEnd       CHAR(5)      DEFAULT NULL,
    pauseStart    CHAR(5)      DEFAULT NULL,
    pauseEnd      CHAR(5)      DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (veh_id, date, tour)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->beginTransaction();
  $ins = $pdo->prepare("INSERT IGNORE INTO driver_stamps (veh_id,date,tour) VALUES (:veh,:d,:t)");
  for ($t=1;$t<=TOURS_PER_DAY;$t++) $ins->execute([':veh'=>$veh_id,':d'=>$date,':t'=>$t]);
  $pdo->commit();

  $stmt = $pdo->prepare("SELECT * FROM driver_stamps WHERE veh_id=:veh AND date=:d ORDER BY tour ASC");
  $stmt->execute([':veh'=>$veh_id, ':d'=>$date]);

  $rows = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = [
      'tour'         => (int)$r['tour'],
      'date'         => $r['date'],
      'workStart'    => $r['workStart']    ?? '',
      'arriveWU'     => $r['arriveWU']     ?? '',
      'departWU'     => $r['departWU']     ?? '',
      'arriveH'      => $r['arriveH']      ?? '',
      'departH'      => $r['departH']      ?? '',
      'hannoverHall' => $r['hannoverHall'] ?? '',
      'note'         => $r['note']         ?? '',
      'reported'     => $r['reported']     ?? '',
      'reportedWhy'  => $r['reportedWhy']  ?? '',
      'workEnd'      => $r['workEnd']      ?? '',
      'pauseStart'   => $r['pauseStart']   ?? '',
      'pauseEnd'     => $r['pauseEnd']     ?? '',
      'arriveH2'     => $r['arriveH2']     ?? '',
      'departH2'     => $r['departH2']     ?? '',
      'hannoverHall2'=> $r['hannoverHall2']?? ''
    ];
  }

  // 🔹 Dashboard-Erweiterung – beeinflusst alte Seiten NICHT
  $active = 0;
  $firstStamp = null;
  $totalMinutes = 0;
  $countShifts = 0;
  $activeList = [];

  foreach ($rows as $r) {
    if ($r['workStart'] && !$r['workEnd']) {
      $active++;
      $since = (time() - strtotime($r['date'].' '.$r['workStart'])) / 60;
      $activeList[] = [
        'name' => $veh_id,
        'start' => $r['workStart'],
        'sinceMin' => round($since),
        'status' => 'working'
      ];
    }
    if ($r['workStart'] && $r['workEnd']) {
      $dur = (strtotime($r['date'].' '.$r['workEnd']) - strtotime($r['date'].' '.$r['workStart'])) / 60;
      if ($dur > 0) { $totalMinutes += $dur; $countShifts++; }
    }
    if ($r['workStart'] && (!$firstStamp || $r['workStart'] < $firstStamp))
      $firstStamp = $r['workStart'];
  }

  echo json_encode([
    'ok'=>true,
    'rows'=>$rows,
    // Zusatzfelder für Dashboard:
    'active'=>$active,
    'avgShiftMin'=>$countShifts ? round($totalMinutes/$countShifts) : null,
    'firstStamp'=>$firstStamp,
    'activeList'=>$activeList
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
