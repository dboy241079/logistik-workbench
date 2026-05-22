<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_db.php';

function getCount(PDO $pdo, string $table, string $from, string $to): int {
  $sql = "SELECT COUNT(*) FROM `$table` WHERE created_at >= :from AND created_at < :to";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['from'=>$from, 'to'=>$to]);
  return (int)$stmt->fetchColumn();
}

function diffText($curr, $prev): string {
  $diff = $curr - $prev;
  if ($diff === 0) return "±0";
  $sign = $diff > 0 ? "↗" : "↘";
  return "$sign " . number_format($diff, 0, ',', '.');
}

try {
  $today = date('Y-m-d 00:00:00');
  $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
  $yesterday = date('Y-m-d 00:00:00', strtotime('-1 day'));
  $lastWeek = date('Y-m-d 00:00:00', strtotime('-7 days'));
  $lastYear = date('Y-m-d 00:00:00', strtotime('-1 year'));

  $tables = [
    'speditionen' => 'Speditionen',
    'behaelter'   => 'Behälter',
    'sachnummern' => 'Sachnummern'
  ];

  $stats = [];

  foreach ($tables as $tbl => $label) {
    $todayCount = getCount($pdo, $tbl, $today, $tomorrow);
    $yestCount  = getCount($pdo, $tbl, $yesterday, $today);
    $weekCount  = getCount($pdo, $tbl, $lastWeek, $tomorrow);
    $yearCount  = getCount($pdo, $tbl, $lastYear, $tomorrow);

    $stats[$tbl] = [
      'label' => $label,
      'today' => $todayCount,
      'yesterday' => $yestCount,
      'week' => $weekCount,
      'year' => $yearCount,
      'diffs' => [
        'day'  => diffText($todayCount, $yestCount),
        'week' => diffText($todayCount, $weekCount),
        'year' => diffText($todayCount, $yearCount)
      ]
    ];
  }

  $totalToday = array_sum(array_column($stats, 'today'));
  $totalYest  = array_sum(array_column($stats, 'yesterday'));
  $totalWeek  = array_sum(array_column($stats, 'week'));
  $totalYear  = array_sum(array_column($stats, 'year'));

  $stats['total'] = [
    'label' => 'Gesamt',
    'today' => $totalToday,
    'yesterday' => $totalYest,
    'week' => $totalWeek,
    'year' => $totalYear,
    'diffs' => [
      'day'  => diffText($totalToday, $totalYest),
      'week' => diffText($totalToday, $totalWeek),
      'year' => diffText($totalToday, $totalYear)
    ]
  ];

  // Totals für Gesamtbestand
  $totals = [];
  foreach ($tables as $tbl => $label) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$tbl`");
    $totals[$tbl] = (int)$stmt->fetchColumn();
  }
  $stats['totals_all'] = $totals;

  echo json_encode(['ok'=>true, 'stats'=>$stats], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
