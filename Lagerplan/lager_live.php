<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/_db.php';

$halle = trim($_GET['halle'] ?? 'H3');
$zone  = trim($_GET['zone'] ?? 'W1');
$batch = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$peek  = isset($_GET['peek']) ? (int)$_GET['peek'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 500;

function jsonOut(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // --- max_ts Slots (filter: halle/zone/batch) ---
  $maxSlotsSql = "
    SELECT MAX(UNIX_TIMESTAMP(GREATEST(created_at, IFNULL(updated_at, created_at))))
    FROM lager_slots
    WHERE halle = :halle AND zone = :zone
  ";
  $p = [':halle'=>$halle, ':zone'=>$zone];
  if ($batch > 0) { $maxSlotsSql .= " AND batch_id = :batch"; $p[':batch'] = $batch; }

  $st = $pdo->prepare($maxSlotsSql);
  $st->execute($p);
  $maxSlots = (int)($st->fetchColumn() ?: 0);

  // --- max_ts Corrections (über Join auf Slots, damit nur diese Halle/Zone zählt) ---
  $maxCorrSql = "
    SELECT MAX(UNIX_TIMESTAMP(c.updated_at))
    FROM lager_slot_corrections c
    JOIN lager_slots s
      ON  ( (c.batch_id = s.batch_id) OR (c.batch_id IS NULL AND s.batch_id IS NULL) )
      AND c.row_no   = s.reihe
      AND c.platz_no = s.platz
      AND c.slot_no  = (s.slot_index + 1)
    WHERE s.halle = :halle AND s.zone = :zone
  ";
  $pc = [':halle'=>$halle, ':zone'=>$zone];
  if ($batch > 0) { $maxCorrSql .= " AND s.batch_id = :batch"; $pc[':batch'] = $batch; }

  $st = $pdo->prepare($maxCorrSql);
  $st->execute($pc);
  $maxCorr = (int)($st->fetchColumn() ?: 0);

  $maxAll = max($maxSlots, $maxCorr);

  if ($peek === 1) {
    jsonOut([
      'ok' => true,
      'since' => $maxAll,
      'server_time' => time(),
      'count_slots' => 0,
      'count_corr'  => 0,
      'rows' => [],
      'corr' => []
    ]);
  }

  // --- 1) Slot-Änderungen seit since (mit effective Menge/Sach via LEFT JOIN) ---
  $slotSql = "
    SELECT
      s.id, s.halle, s.zone, s.reihe, s.platz, s.slot_index,
      s.referenznr, s.lieferschein, s.batch_id,
      s.eingelagert_am, s.user_name,

      -- effective Werte (wie in lager_load.php)
      COALESCE(NULLIF(c.sach_korr, ''), s.sachnummer) AS sachnummer,
      COALESCE(c.qty_korr, s.menge)                   AS menge,

      s.created_at, s.updated_at,
      UNIX_TIMESTAMP(GREATEST(s.created_at, IFNULL(s.updated_at, s.created_at))) AS ts
    FROM lager_slots s
    LEFT JOIN lager_slot_corrections c
      ON  ( (c.batch_id = s.batch_id) OR (c.batch_id IS NULL AND s.batch_id IS NULL) )
      AND c.row_no   = s.reihe
      AND c.platz_no = s.platz
      AND c.slot_no  = (s.slot_index + 1)
    WHERE s.halle = :halle AND s.zone = :zone
      AND UNIX_TIMESTAMP(GREATEST(s.created_at, IFNULL(s.updated_at, s.created_at))) > :since
  ";
  $ps = [':halle'=>$halle, ':zone'=>$zone, ':since'=>$since];
  if ($batch > 0) { $slotSql .= " AND s.batch_id = :batch"; $ps[':batch'] = $batch; }
  $slotSql .= " ORDER BY ts ASC, s.id ASC LIMIT $limit";

  $st = $pdo->prepare($slotSql);
  $st->execute($ps);
  $slotRows = $st->fetchAll(PDO::FETCH_ASSOC);

  // --- 2) Corrections-Änderungen seit since (für Fälle ohne Slot-update) ---
  $corrSql = "
    SELECT
      s.id AS slot_id,
      c.id AS corr_id,
      c.batch_id, c.row_no, c.platz_no, c.slot_no, c.ref,
      c.sach_korr, c.qty_korr, c.note, c.updated_by, c.updated_at,
      UNIX_TIMESTAMP(c.updated_at) AS ts
    FROM lager_slot_corrections c
    JOIN lager_slots s
      ON  ( (c.batch_id = s.batch_id) OR (c.batch_id IS NULL AND s.batch_id IS NULL) )
      AND c.row_no   = s.reihe
      AND c.platz_no = s.platz
      AND c.slot_no  = (s.slot_index + 1)
    WHERE s.halle = :halle AND s.zone = :zone
      AND UNIX_TIMESTAMP(c.updated_at) > :since
  ";
  $pc2 = [':halle'=>$halle, ':zone'=>$zone, ':since'=>$since];
  if ($batch > 0) { $corrSql .= " AND s.batch_id = :batch"; $pc2[':batch'] = $batch; }
  $corrSql .= " ORDER BY ts ASC, c.id ASC LIMIT $limit";

  $st = $pdo->prepare($corrSql);
  $st->execute($pc2);
  $corrRows = $st->fetchAll(PDO::FETCH_ASSOC);

  // since fürs nächste Polling
  $maxRows = $since;
  foreach ($slotRows as $r) $maxRows = max($maxRows, (int)($r['ts'] ?? 0));
  foreach ($corrRows as $r) $maxRows = max($maxRows, (int)($r['ts'] ?? 0));

  $sinceOut = max($maxAll, $maxRows);

  jsonOut([
    'ok' => true,
    'since' => $sinceOut,
    'server_time' => time(),
    'count_slots' => count($slotRows),
    'count_corr'  => count($corrRows),
    'rows' => $slotRows,
    'corr' => $corrRows
  ]);

} catch (Throwable $e) {
  jsonOut(['ok'=>false,'error'=>'db_error','msg'=>$e->getMessage()], 500);
}
