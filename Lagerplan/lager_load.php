<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/_db.php';

$halle = trim($_GET['halle'] ?? 'H3');
$zone  = trim($_GET['zone'] ?? 'W1');
$batch = (int)($_GET['batch_id'] ?? 0);

try {
  $sql = "
    SELECT
      s.id,
      s.batch_id,
      s.halle,
      s.zone,
      s.reihe,
      s.platz,
      s.slot_index,
      s.referenznr,
      s.lieferschein,
      s.eingelagert_am,
      s.user_name,

      -- Originalwerte
      s.sachnummer AS sach_original,
      s.menge      AS menge_original,

      -- ✅ Effective Werte: Korrektur gewinnt
      COALESCE(NULLIF(c.sach_korr, ''), s.sachnummer) AS sachnummer,
      COALESCE(c.qty_korr, s.menge)                   AS menge,

      -- Korrektur-Metadaten
      CASE WHEN c.id IS NULL THEN 0 ELSE 1 END        AS has_correction,
      c.note                                          AS corr_note,
      c.updated_by                                    AS corr_updated_by,
      c.updated_at                                    AS corr_updated_at

    FROM lager_slots s
    LEFT JOIN lager_slot_corrections c
      ON  ( (c.batch_id = s.batch_id) OR (c.batch_id IS NULL AND s.batch_id IS NULL) )
      AND c.row_no   = s.reihe
      AND c.platz_no = s.platz
      AND c.slot_no  = (s.slot_index + 1)

    WHERE s.halle=:halle AND s.zone=:zone AND s.deleted_at IS NULL

  ";

  $params = [':halle' => $halle, ':zone' => $zone];

  if ($batch > 0) {
    $sql .= " AND s.batch_id = :batch";
    $params[':batch'] = $batch;
  }

  $sql .= " ORDER BY s.reihe, s.platz, s.slot_index";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'db_error',
    'msg' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
