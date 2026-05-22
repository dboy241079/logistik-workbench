<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors','0');
error_reporting(E_ALL);

$dbFile = dirname(__DIR__) . '/api/_db.php';
if (!file_exists($dbFile)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB include not found', 'path'=>$dbFile]);
  exit;
}
require $dbFile;

/**
 * Extrahiert Ziffern und gibt int zurück.
 * "R17" -> 17
 */
function digits_int($v): int {
  $s = preg_replace('/\D+/', '', (string)($v ?? ''));
  return $s === '' ? 0 : (int)$s;
}

try {
  $action = $_GET['action'] ?? '';
  $body   = json_decode(file_get_contents('php://input'), true) ?? [];
  $user   = $_SESSION['username'] ?? 'unknown';

  // -----------------------------
  // Robust einlesen (alt + neu)
  // -----------------------------
  $batchIdRaw = $body['batch_id'] ?? null;
  $batchId = (is_numeric($batchIdRaw) ? (int)$batchIdRaw : null);
  if ($batchId !== null && $batchId <= 0) $batchId = null; // 0 => NULL

  $rowRaw   = $body['row']   ?? ($body['row_no'] ?? null);
  $platzRaw = $body['platz'] ?? ($body['platz_no'] ?? null);

  $row   = digits_int($rowRaw);     // "R17" -> 17
  $platz = digits_int($platzRaw);

  // slot: akzeptiere slot / slot_no / slot_index
  $slotRaw = $body['slot'] ?? ($body['slot_no'] ?? ($body['slot_index'] ?? null));
  $slot = digits_int($slotRaw);

  // Wenn Frontend 0-basiert (0..3) sendet, und du es 1-basiert brauchst:
  if (isset($body['slot_index']) && $slot >= 0) $slot = $slot + 1;

  $ref      = $body['ref'] ?? null;
  $sachKorr = $body['sach_korr'] ?? ($body['sach'] ?? null);
  $qtyKorr  = $body['qty_korr'] ?? ($body['qty'] ?? null);
  $note     = $body['note'] ?? null;

  $qtyKorrInt = (int)digits_int($qtyKorr);

  // slot_id (PK aus lager_slots) – wird für Weg A benötigt
  $slotId = (int)($body['slot_id'] ?? 0);

  // -----------------------------
  // Actions
  // -----------------------------

  if ($action === 'list') {
  $halle   = trim((string)($_GET['halle'] ?? 'H3'));
  $zone    = trim((string)($_GET['zone'] ?? 'W1'));
  $batchIdGet = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

  $sql = "
    SELECT
      s.id AS slot_id,
      c.batch_id,
      c.row_no,
      c.platz_no,
      c.slot_no,
      c.ref,
      c.sach_korr,
      c.qty_korr,
      c.note,
      c.updated_by,
      c.updated_at
    FROM lager_slot_corrections c
    INNER JOIN lager_slots s
      ON s.reihe = c.row_no
     AND s.platz = c.platz_no
     AND (s.slot_index + 1) = c.slot_no
     AND (s.batch_id <=> c.batch_id)
    WHERE s.halle = :halle
      AND s.zone = :zone
      AND s.deleted_at IS NULL
  ";

  $params = [
    ':halle' => $halle,
    ':zone'  => $zone
  ];

  if ($batchIdGet > 0) {
    $sql .= " AND s.batch_id = :batch_id ";
    $params[':batch_id'] = $batchIdGet;
  }

  $sql .= " ORDER BY c.updated_at DESC ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode([
    'ok' => true,
    'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ], JSON_UNESCAPED_UNICODE);

  exit;
}

  if ($action === 'upsert') {
    // Pflichtfelder
    if ($row <= 0 || $platz <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'row/platz fehlt oder 0', 'row'=>$rowRaw, 'platz'=>$platzRaw]);
      exit;
    }
    if ($slotRaw === null || $slot <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'slot fehlt/ungültig', 'slot_raw'=>$slotRaw, 'slot_no'=>$slot]);
      exit;
    }
    if ($slotId <= 0) {
      http_response_code(400);
      echo json_encode([
        'ok'=>false,
        'error'=>'slot_id fehlt (Frontend muss lager_slots.id mitsenden)',
        'hint'=>'Beim Öffnen des Modals slot_id aus dataset/Row mitgeben.'
      ]);
      exit;
    }

    $pdo->beginTransaction();
    try {
      // 1) Slot sperren + alte Menge holen
      // MySQL Syntax: LIMIT 1 VOR FOR UPDATE!
      $stmt = $pdo->prepare("
        SELECT menge
        FROM lager_slots
        WHERE id = :id
        LIMIT 1 FOR UPDATE
      ");
      $stmt->execute([':id' => $slotId]);
      $oldQty = $stmt->fetchColumn();

      if ($oldQty === false) {
        throw new Exception("lager_slots Slot nicht gefunden: id=".$slotId);
      }

      // 2) lager_slots.menge updaten (damit Reload korrekt ist)
      $stmt = $pdo->prepare("
        UPDATE lager_slots
        SET menge = :menge
        WHERE id = :id
        LIMIT 1
      ");
      $stmt->execute([
        ':menge' => $qtyKorrInt,
        ':id'    => $slotId
      ]);

      // 3) Correction upsert (Historie/Protokoll)
      $stmt = $pdo->prepare("
        INSERT INTO lager_slot_corrections
          (batch_id, row_no, platz_no, slot_no, ref, sach_korr, qty_korr, note, updated_by)
        VALUES
          (:batch_id, :row_no, :platz_no, :slot_no, :ref, :sach_korr, :qty_korr, :note, :updated_by)
        ON DUPLICATE KEY UPDATE
          ref=VALUES(ref),
          sach_korr=VALUES(sach_korr),
          qty_korr=VALUES(qty_korr),
          note=VALUES(note),
          updated_by=VALUES(updated_by),
          updated_at=NOW()
      ");
      $stmt->execute([
        ':batch_id'   => $batchId,        // NULL möglich
        ':row_no'     => $row,
        ':platz_no'   => $platz,
        ':slot_no'    => $slot,
        ':ref'        => $ref,
        ':sach_korr'  => $sachKorr,
        ':qty_korr'   => $qtyKorrInt,
        ':note'       => $note,
        ':updated_by' => $user
      ]);

      $pdo->commit();

      echo json_encode([
        'ok' => true,
        'slot_id' => $slotId,
        'old_qty' => (int)$oldQty,
        'new_qty' => $qtyKorrInt,
        'correction_affected' => $stmt->rowCount(),
        'saved' => [
          'batch_id' => $batchId,
          'row_no'   => $row,
          'platz_no' => $platz,
          'slot_no'  => $slot,
          'ref'      => $ref
        ]
      ]);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  if ($action === 'delete') {
    // Hinweis: delete entfernt nur den Correction-Eintrag.
    // Es setzt lager_slots.menge NICHT zurück, weil wir ohne gespeicherten old_qty nicht wissen, wohin.
    if ($row <= 0 || $platz <= 0 || $slot <= 0) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'row/platz/slot fehlt oder ungültig']);
      exit;
    }

    $stmt = $pdo->prepare("
      DELETE FROM lager_slot_corrections
      WHERE (batch_id <=> :batch_id)
        AND row_no=:row_no AND platz_no=:platz_no AND slot_no=:slot_no
    ");
    $stmt->execute([
      ':batch_id' => $batchId,
      ':row_no'   => $row,
      ':platz_no' => $platz,
      ':slot_no'  => $slot,
    ]);

    echo json_encode(['ok'=>true]);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'unknown action', 'action'=>$action]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ]);
  exit;
}
