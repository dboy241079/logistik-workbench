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
    out(['ok'=>false,'msg'=>'Nur POST erlaubt.'], 405);
  }

  $user = (string)($_SESSION['username'] ?? 'unknown');

  $id   = (int)($_POST['id'] ?? 0);

  // Fallback: Koordinaten-Löschung (wenn keine ID da ist)
  $halle = trim((string)($_POST['halle'] ?? 'H3'));
  $zone  = trim((string)($_POST['zone']  ?? 'W1'));
  $reihe = trim((string)($_POST['reihe'] ?? ''));
  $platz = (int)($_POST['platz'] ?? 0);
  $slot  = (int)($_POST['slot_index'] ?? -1);

  // 1) ID-Modus
  if ($id > 0) {
    $stmt = $pdo->prepare("
      UPDATE lager_slots
      SET deleted_at = NOW(),
          deleted_by = :user,
          updated_at = NOW()
      WHERE id = :id
        AND deleted_at IS NULL
      LIMIT 1
    ");
    $stmt->execute([':user' => $user, ':id' => $id]);

    out([
      'ok' => true,
      'mode' => 'id',
      'id' => $id,
      'deleted' => $stmt->rowCount(),
      'deleted_by' => $user,
      'deleted_at' => date('Y-m-d H:i:s'),
    ]);
  }

  // 2) Koordinaten müssen vollständig sein
  if ($reihe === '' || $platz <= 0 || $slot < 0) {
    out(['ok'=>false,'msg'=>'Keine gültige ID oder Koordinaten übergeben.'], 400);
  }

  // Slot per Koordinaten finden (nur wenn nicht schon gelöscht)
  $q = $pdo->prepare("
    SELECT id
    FROM lager_slots
    WHERE halle=:halle AND zone=:zone
      AND reihe=:reihe AND platz=:platz AND slot_index=:slot
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $q->execute([
    ':halle' => $halle,
    ':zone'  => $zone,
    ':reihe' => $reihe,
    ':platz' => $platz,
    ':slot'  => $slot,
  ]);

  $id2 = (int)($q->fetchColumn() ?: 0);
  if ($id2 <= 0) {
    out(['ok'=>false,'mode'=>'coords','msg'=>'Slot nicht gefunden oder schon gelöscht.'], 404);
  }

  $stmt = $pdo->prepare("
    UPDATE lager_slots
    SET deleted_at = NOW(),
        deleted_by = :user,
        updated_at = NOW()
    WHERE id = :id
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->execute([':user' => $user, ':id' => $id2]);

  out([
    'ok' => true,
    'mode' => 'coords',
    'id' => $id2,
    'deleted' => $stmt->rowCount(),
    'deleted_by' => $user,
    'deleted_at' => date('Y-m-d H:i:s'),
  ]);

} catch (Throwable $e) {
  out(['ok'=>false,'msg'=>$e->getMessage()], 500);
}
