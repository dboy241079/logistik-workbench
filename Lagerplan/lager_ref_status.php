<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../api/_db.php';

function out(array $p, int $code=200): void {
  http_response_code($code);
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $halle = trim((string)($_GET['halle'] ?? 'H3'));
  $zone  = trim((string)($_GET['zone']  ?? 'W1'));
  $ref   = trim((string)($_GET['referenznr'] ?? ''));

  if ($ref === '') {
    out(['ok'=>false,'error'=>'missing_ref','msg'=>'referenznr fehlt.'], 400);
  }

  // 1) IN (aktiver Bestand)
  $st = $pdo->prepare("
    SELECT id, halle, zone, referenznr, sachnummer, reihe, platz, slot_index,
           eingelagert_am, user_name, lieferschein, menge
    FROM lager_slots
    WHERE halle=:h AND zone=:z
      AND referenznr=:ref
      AND deleted_at IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([':h'=>$halle, ':z'=>$zone, ':ref'=>$ref]);
  $in = $st->fetch(PDO::FETCH_ASSOC);

  if ($in) {
    out(['ok'=>true,'status'=>'IN','data'=>$in]);
  }

  // 2) OUT (Historie)
  $st = $pdo->prepare("
    SELECT id, halle, zone, referenznr, sachnummer, reihe, platz, slot_index,
           eingelagert_am, eingelagert_user,
           ausgebucht_am, ausgebucht_ls, ausgebucht_user
    FROM lager_out_log
    WHERE halle=:h AND zone=:z
      AND referenznr=:ref
    ORDER BY ausgebucht_am DESC, id DESC
    LIMIT 1
  ");
  $st->execute([':h'=>$halle, ':z'=>$zone, ':ref'=>$ref]);
  $outRow = $st->fetch(PDO::FETCH_ASSOC);

  if ($outRow) {
    out(['ok'=>true,'status'=>'OUT','data'=>$outRow]);
  }

  // 3) DELETED (soft delete)
  $st = $pdo->prepare("
    SELECT id, halle, zone, referenznr, sachnummer, reihe, platz, slot_index,
           eingelagert_am, user_name, lieferschein, menge,
           deleted_at, deleted_by
    FROM lager_slots
    WHERE halle=:h AND zone=:z
      AND referenznr=:ref
      AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([':h'=>$halle, ':z'=>$zone, ':ref'=>$ref]);
  $del = $st->fetch(PDO::FETCH_ASSOC);

  if ($del) {
    out(['ok'=>true,'status'=>'DELETED','data'=>$del]);
  }

  out(['ok'=>true,'status'=>'NOT_FOUND','data'=>null]);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()], 500);
}
