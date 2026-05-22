<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api/_db.php';

function out(array $p, int $code = 200): void {
  http_response_code($code);
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok'=>false,'error'=>'method','msg'=>'Nur POST erlaubt.'], 405);
  }

  $user = (string)($_SESSION['username'] ?? 'unknown');

  $slotId = (int)($_POST['slot_id'] ?? 0);
  $newRef = trim((string)($_POST['referenznr'] ?? ''));

  if ($slotId <= 0 || $newRef === '') {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'slot_id / referenznr fehlt.'], 400);
  }

  // Quelle laden (nur aktiv)
  $st = $pdo->prepare("
    SELECT id, halle, zone, reihe, platz, slot_index, referenznr
    FROM lager_slots
    WHERE id = :id
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $st->execute([':id'=>$slotId]);
  $src = $st->fetch(PDO::FETCH_ASSOC);

  if (!$src) {
    out(['ok'=>false,'error'=>'not_found','msg'=>'Slot nicht gefunden oder archiviert.'], 404);
  }

  $oldRef = (string)$src['referenznr'];

  // wenn gleich -> ok
  if ($newRef === $oldRef) {
    out([
      'ok'=>true,
      'changed'=>0,
      'slot_id'=>$slotId,
      'old_ref'=>$oldRef,
      'new_ref'=>$newRef
    ]);
  }

  // Duplicate-Check (nur aktive Slots)
  $dup = $pdo->prepare("
    SELECT id
    FROM lager_slots
    WHERE referenznr = :ref
      AND deleted_at IS NULL
      AND id <> :id
    LIMIT 1
  ");
  $dup->execute([':ref'=>$newRef, ':id'=>$slotId]);
  $dupId = (int)($dup->fetchColumn() ?: 0);

  if ($dupId > 0) {
    out([
      'ok'=>false,
      'error'=>'duplicate_ref',
      'msg'=>"Referenz {$newRef} existiert bereits (Slot-ID {$dupId})."
    ], 409);
  }

  // Update
  $upd = $pdo->prepare("
    UPDATE lager_slots
    SET referenznr = :ref,
        updated_at = NOW()
    WHERE id = :id
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $upd->execute([':ref'=>$newRef, ':id'=>$slotId]);

  out([
    'ok'=>true,
    'changed'=>(int)$upd->rowCount(),
    'slot_id'=>$slotId,
    'old_ref'=>$oldRef,
    'new_ref'=>$newRef,
    'updated_by'=>$user
  ]);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'server_error','msg'=>'Ref-Update fehlgeschlagen.','detail'=>$e->getMessage()], 500);
}
