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

  $halle = trim((string)($_POST['halle'] ?? 'H3'));
  $zone  = trim((string)($_POST['zone']  ?? 'W1'));

  $id    = (int)($_POST['id'] ?? 0);
  $ref   = trim((string)($_POST['referenznr'] ?? ''));
  $outLs = trim((string)($_POST['ausgebucht_ls'] ?? ''));

  $user = (string)($_SESSION['username'] ?? 'unknown');

  if ($halle === '' || $zone === '' || ($id <= 0 && $ref === '')) {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'halle/zone und (id ODER referenznr) fehlen.'], 400);
  }

  // 1) Slot aus Bestand holen (nur aktive, nicht deleted)
  if ($id > 0) {
    $st = $pdo->prepare("
      SELECT *
      FROM lager_slots
      WHERE id = :id
        AND halle = :h AND zone = :z
        AND deleted_at IS NULL
      LIMIT 1
    ");
    $st->execute([':id'=>$id, ':h'=>$halle, ':z'=>$zone]);
  } else {
    $st = $pdo->prepare("
      SELECT *
      FROM lager_slots
      WHERE halle = :h AND zone = :z
        AND referenznr = :ref
        AND deleted_at IS NULL
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([':h'=>$halle, ':z'=>$zone, ':ref'=>$ref]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    out(['ok'=>false,'error'=>'not_found','msg'=>'Nicht im Bestand gefunden (evtl. schon ausgebucht/gelöscht).'], 404);
  }

  // 2) In OUT-Log schreiben
  $ins = $pdo->prepare("
    INSERT INTO lager_out_log
      (halle, zone, referenznr, sachnummer, reihe, platz, slot_index,
       eingelagert_am, eingelagert_user,
       ausgebucht_am, ausgebucht_ls, ausgebucht_user)
    VALUES
      (:h, :z, :ref, :sach, :reihe, :platz, :slot_index,
       :eing_am, :eing_user,
       NOW(), :out_ls, :out_user)
  ");

  $ins->execute([
    ':h' => (string)$row['halle'],
    ':z' => (string)$row['zone'],
    ':ref' => (string)$row['referenznr'],
    ':sach' => (string)$row['sachnummer'],
    ':reihe' => (string)$row['reihe'],
    ':platz' => (int)$row['platz'],
    ':slot_index' => (int)$row['slot_index'],
    ':eing_am' => $row['eingelagert_am'] ?? null,
    ':eing_user' => $row['user_name'] ?? null,
    ':out_ls' => ($outLs !== '' ? $outLs : null),
    ':out_user' => $user,
  ]);

  $outLogId = (int)$pdo->lastInsertId();

  // 3) Bestand entfernen (hart löschen -> deleted_at bleibt nur fürs "Löschen")
  $del = $pdo->prepare("DELETE FROM lager_slots WHERE id = :id LIMIT 1");
  $del->execute([':id' => (int)$row['id']]);

  out([
    'ok' => true,
    'out_log_id' => $outLogId,
    'deleted_slot_id' => (int)$row['id'],
    'ref' => (string)$row['referenznr']
  ]);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()], 500);
}
