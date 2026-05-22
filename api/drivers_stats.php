<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);

try {
  require_once __DIR__ . '/_db.php';

  $date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');

  $stmt = $pdo->prepare("
    SELECT veh_id, tour, workStart, workEnd,
           arriveWU, departWU, arriveH, departH, arriveH2, departH2,
           hannoverHall, hannoverHall2,
           pauseStart, pauseEnd
    FROM driver_stamps
    WHERE date = :date
    ORDER BY veh_id ASC
  ");
  $stmt->execute(['date' => $date]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo json_encode([
      'active' => 0,
      'avgShiftMin' => null,
      'firstStamp' => null,
      'activeList' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $now = new DateTime();
  $active = 0;
  $totalMin = 0;
  $countForAvg = 0;
  $firstStamp = null;
  $activeList = [];

  foreach ($rows as $r) {
    $vehId = $r['veh_id'];
    $start = $r['workStart'];
    $end   = $r['workEnd'];

    if ($start && (!$firstStamp || $start < $firstStamp)) $firstStamp = $start;

    if ($start && !$end) {
      $active++;
      [$h,$m] = array_map('intval', explode(':', substr($start,0,5)));
      $startDT = new DateTime("$date $h:$m:00");
      $diffMin = max(0, round(($now->getTimestamp() - $startDT->getTimestamp()) / 60));

      // Live-Ort bestimmen:
      $position = 'Unterwegs';
      if ($r['hannoverHall2']) $position = "Halle ".$r['hannoverHall2'];
      elseif ($r['hannoverHall']) $position = "Halle ".$r['hannoverHall'];
      elseif ($r['arriveH2']) $position = "Ziel 2 erreicht";
      elseif ($r['arriveH']) $position = "Ziel 1 erreicht";
      elseif ($r['arriveWU']) $position = "Werk Unna";
      elseif ($r['tour']) $position = "Tour ".$r['tour'];

      $activeList[] = [
        'name'       => "Fahrzeug $vehId",
        'start'      => substr($start,0,5),
        'sinceMin'   => $diffMin,
        'status'     => 'working',
        'position'   => $position,
        'tour'       => $r['tour']
      ];
    }

    if ($start && $end) {
      [$sh,$sm] = array_map('intval', explode(':', substr($start,0,5)));
      [$eh,$em] = array_map('intval', explode(':', substr($end,0,5)));
      $dur = (($eh*60 + $em) - ($sh*60 + $sm));
      if ($dur > 0) {
        $totalMin += $dur;
        $countForAvg++;
      }
    }
  }

  $avg = $countForAvg ? (int) round($totalMin / $countForAvg) : null;

  echo json_encode([
    'active'      => $active,
    'avgShiftMin' => $avg,
    'firstStamp'  => $firstStamp ? substr($firstStamp,0,5) : null,
    'activeList'  => $activeList
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
