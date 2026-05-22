<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__); // /LKW
require $ROOT . '/inc/session.php';
require $ROOT . '/api/_db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $role = $_SESSION['role'] ?? '';
  if (!in_array($role, ['admin', 'standortleiter'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // UI-Lagergruppen (Checkboxen)
  $allowed = ['W1','X3','X3(B)','G9','B1','B1(T)','Sarajevo','UNBEKANNT'];

  $lgRaw = trim((string)($_GET['lg'] ?? '')); // z.B. "W1,X3,..."
  $sel = [];
  if ($lgRaw !== '') {
    $parts = array_filter(array_map('trim', explode(',', $lgRaw)));
    $sel = array_values(array_intersect($parts, $allowed));
  }

  // "neueste" lagergruppe pro sachnummer (falls doppelte Zeilen existieren)
  $SN_JOIN = "
    LEFT JOIN (
      SELECT
        sachnummer,
        SUBSTRING_INDEX(
          GROUP_CONCAT(lagergruppe ORDER BY updated_at DESC SEPARATOR ','),
          ',', 1
        ) AS lagergruppe
      FROM sachnummern
      GROUP BY sachnummer
    ) sn ON sn.sachnummer = ls.sachnummer
  ";

  $LG_EXPR   = "COALESCE(NULLIF(sn.lagergruppe,''),'UNBEKANNT')";
  $PACK_EXPR = "COALESCE(NULLIF(ls.verpackung,''),'UNBEKANNT')";

  // Basis: nur aktive
  $where = "ls.deleted_at IS NULL";
  $params = [];

  // 1) Rohdaten: Gruppiert nach LG + Verpackung (für Pivot)
  $sql = "
    SELECT
      $LG_EXPR   AS lg,
      $PACK_EXPR AS pack,
      COUNT(*) AS pallets,
      COALESCE(SUM(ls.menge),0) AS pieces,
      COUNT(DISTINCT ls.sachnummer) AS sachnr
    FROM lager_slots ls
    $SN_JOIN
    WHERE $where
    GROUP BY lg, pack
    ORDER BY lg, pack
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $raw = $st->fetchAll(PDO::FETCH_ASSOC);

  // Pivot pro LG: totals + Verpackungs-Mix
  $byLg = [];
  foreach ($raw as $r) {
    $lg   = (string)$r['lg'];
    $pack = (string)$r['pack'];

    if (!isset($byLg[$lg])) {
      $byLg[$lg] = [
        'lg' => $lg,
        'pallets' => 0,
        'pieces'  => 0,
        'sachnr'  => 0,
        'packs'   => [],
      ];
    }

    $p = (int)$r['pallets'];
    $byLg[$lg]['pallets'] += $p;
    $byLg[$lg]['pieces']  += (int)$r['pieces'];
    $byLg[$lg]['sachnr']  += (int)$r['sachnr'];
    $byLg[$lg]['packs'][$pack] = ($byLg[$lg]['packs'][$pack] ?? 0) + $p;
  }



$rows = [];

// global (alle LG)
$sumLkwAll = [
  'gt_count'   => 0,
  'vw_count'   => 0,
  'open_total' => 0,
  'done_total' => 0,
];

// gefiltert (nur selektierte LG; wenn keine Auswahl => alle)
$sumLkwFiltered = [
  'gt_count'   => 0,
  'vw_count'   => 0,
  'open_total' => 0,
  'done_total' => 0,
];

foreach ($byLg as $lg => $d) {
  $packs = $d['packs'];
  arsort($packs);

  $open = (int)($packs['UNBEKANNT'] ?? 0);
  $done = max(0, (int)$d['pallets'] - $open);

  $totalP = max(1, (int)$d['pallets']);
  $openPct = (int)round(($open / $totalP) * 100);
  $donePct = 100 - $openPct;

  $lkw = calcLkwFromPacks($packs);

  $rows[] = [
    'lg'         => $lg,
    'pallets'    => (int)$d['pallets'],
    'pieces'     => (int)$d['pieces'],
    'sachnr'     => (int)$d['sachnr'],
    'verpackung' => "offen {$open} ({$openPct}%) · erledigt {$done} ({$donePct}%)",
    'lkw_text'   => $lkw['text'],
    'lkw'        => $lkw,
  ];

  // Summen ALLE
  $sumLkwAll['gt_count']   += $lkw['gt_count'];
  $sumLkwAll['vw_count']   += $lkw['vw_count'];
  $sumLkwAll['open_total'] += $open;
  $sumLkwAll['done_total'] += $done;

  // Summen GEFILTERT (für Frontend-Anzeige)
  $inFilter = empty($sel) || in_array($lg, $sel, true);
  if ($inFilter) {
    $sumLkwFiltered['gt_count']   += $lkw['gt_count'];
    $sumLkwFiltered['vw_count']   += $lkw['vw_count'];
    $sumLkwFiltered['open_total'] += $open;
    $sumLkwFiltered['done_total'] += $done;
  }
}

// Finalisierung: aus Gesamtpaletten pro Typ die LKW berechnen
$finalizeLkw = static function(array $s): array {
  $s['gt_full'] = intdiv($s['gt_count'], 52);
  $s['gt_rest'] = $s['gt_count'] % 52;

  $s['vw_full'] = intdiv($s['vw_count'], 78);
  $s['vw_rest'] = $s['vw_count'] % 78;

  $s['full_total'] = $s['gt_full'] + $s['vw_full'];
  return $s;
};

$sumLkwAll = $finalizeLkw($sumLkwAll);
$sumLkwFiltered = $finalizeLkw($sumLkwFiltered);

usort($rows, fn($a,$b) => strcmp($a['lg'], $b['lg']));




  // Totals (alle)
  $totals = ['pallets'=>0,'pieces'=>0,'sachnr'=>0];
  foreach ($rows as $r) {
    $totals['pallets'] += (int)$r['pallets'];
    $totals['pieces']  += (int)$r['pieces'];
    $totals['sachnr']  += (int)$r['sachnr'];
  }

  // Filtered (nur ausgewählte LGs, wenn Auswahl vorhanden)
  $filtered = $totals;
  if (!empty($sel)) {
    $filtered = ['pallets'=>0,'pieces'=>0,'sachnr'=>0];
    foreach ($rows as $r) {
      if (in_array($r['lg'], $sel, true)) {
        $filtered['pallets'] += (int)$r['pallets'];
        $filtered['pieces']  += (int)$r['pieces'];
        $filtered['sachnr']  += (int)$r['sachnr'];
      }
    }
  }

  echo json_encode([
  'rows'            => $rows,
  'totals'          => $totals,
  'filtered'        => $filtered,
  'selected'        => $sel,
  'lkw_totals'      => $sumLkwFiltered,
  'lkw_totals_all'  => $sumLkwAll,
  'lkw' => [
    'gt_done'    => $sumLkwFiltered['gt_count'],
    'vw_done'    => $sumLkwFiltered['vw_count'],
    'gt_full'    => $sumLkwFiltered['gt_full'],
    'gt_rest'    => $sumLkwFiltered['gt_rest'],
    'vw_full'    => $sumLkwFiltered['vw_full'],
    'vw_rest'    => $sumLkwFiltered['vw_rest'],
    'full_total' => $sumLkwFiltered['full_total'],
    'done_total' => $sumLkwFiltered['done_total'],
    'open_total' => $sumLkwFiltered['open_total'],
  ],
], JSON_UNESCAPED_UNICODE);




} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
function normPack(string $s): string {
  return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($s)));
}

function calcLkwFromPacks(array $packs): array {
  $gt = 0;
  $vw = 0;

  foreach ($packs as $packName => $cntRaw) {
    $cnt = (int)$cntRaw;
    $k = normPack((string)$packName);

    if ($k === 'GT14488' || $k === 'GT14491') {
      $gt += $cnt;
      continue;
    }
    if ($k === 'VW0012' || $k === '114003') {
      $vw += $cnt;
      continue;
    }
  }

  $gtFull = intdiv($gt, 52);
  $gtRest = $gt % 52;

  $vwFull = intdiv($vw, 78);
  $vwRest = $vw % 78;

  $fullTotal = $gtFull + $vwFull;

  return [
    'gt_count'   => $gt,
    'vw_count'   => $vw,
    'gt_full'    => $gtFull,
    'gt_rest'    => $gtRest,
    'vw_full'    => $vwFull,
    'vw_rest'    => $vwRest,
    'full_total' => $fullTotal,
    'text'       => "voll {$fullTotal} | GT: {$gtFull} LKW + {$gtRest} | VW: {$vwFull} LKW + {$vwRest}",
  ];
}

