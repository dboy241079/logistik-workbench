<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_db.php'; // enthält $pdo

/**
 * Statistik über einen Zeitraum aus Tabelle warenausgang
 */
function getStatsByRange(PDO $pdo, string $from, string $to): array {
  $sql = "
    SELECT 
      COUNT(DISTINCT ausgang_nr)      AS cases,
      COALESCE(SUM(behaelter), 0)     AS pallets,
      COALESCE(SUM(zus_behaelter), 0) AS klts,
      COALESCE(SUM(brt_gew), 0)       AS units
    FROM warenausgang
    WHERE datum BETWEEN :from AND :to
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['from' => $from, 'to' => $to]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cases'=>0,'pallets'=>0,'klts'=>0,'units'=>0];
}

/**
 * Unterschiedsausgabe für Dashboard mit Richtungspfeilen
 */
function diffText($curr, $prev): string {
  $diff = $curr - $prev;
  if ($diff === 0) return "±0";
  $sign = $diff > 0 ? "↗" : "↘";
  $pct  = $prev > 0 ? round(($diff / $prev) * 100, 1) : null;
  $pctText = $pct !== null ? " (" . ($pct > 0 ? "+" : "") . $pct . "%)" : "";
  return "$sign " . number_format($diff, 0, ',', '.') . $pctText;
}

try {
  // === Datumsbereiche ===
  $today     = date('Y-m-d');
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $weekStart = date('Y-m-d', strtotime('-7 days'));
  $yearStart = date('Y-m-d', strtotime('-1 year'));

  // === Werte laden ===
  $todayData = getStatsByRange($pdo, $today, $today);
  $yestData  = getStatsByRange($pdo, $yesterday, $yesterday);
  $weekData  = getStatsByRange($pdo, $weekStart, $today);
  $yearData  = getStatsByRange($pdo, $yearStart, $today);

  // === Trend-Struktur für Frontend ===
  $trend = [
    'today'     => $todayData,
    'yesterday' => $yestData,
    'week'      => $weekData,
    'year'      => $yearData,
    'diffs' => [
      'cases'   => diffText((float)$todayData['cases'],   (float)$yestData['cases']),
      'pallets' => diffText((float)$todayData['pallets'], (float)$yestData['pallets']),
      'klts'    => diffText((float)$todayData['klts'],    (float)$yestData['klts']),
      'units'   => diffText((float)$todayData['units'],   (float)$yestData['units'])
    ]
  ];

  // === Totals (Gesamtsummen über alles) ===
  $stmt = $pdo->query("
    SELECT 
      COUNT(DISTINCT ausgang_nr)      AS cases,
      COALESCE(SUM(behaelter), 0)     AS pallets,
      COALESCE(SUM(zus_behaelter), 0) AS klts,
      COALESCE(SUM(brt_gew), 0)       AS units
    FROM warenausgang
  ");
  $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cases'=>0,'pallets'=>0,'klts'=>0,'units'=>0];

  echo json_encode(['ok'=>true, 'trend'=>$trend, 'totals'=>$totals], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
