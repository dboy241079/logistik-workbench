<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require dirname(__DIR__) . '/api/_db.php';

function out(array $p, int $code = 200): void {
  http_response_code($code);
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list'));
  $halle  = trim((string)($_GET['halle']  ?? $_POST['halle']  ?? ''));
  $zone   = trim((string)($_GET['zone']   ?? $_POST['zone']   ?? ''));
  $user   = trim((string)($_SESSION['username'] ?? 'unknown'));

  if ($halle === '' || $zone === '') {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (halle/zone).'], 400);
  }

  // ----------------------------
  // LIST
  // ----------------------------
  if ($action === 'list') {
    $st = $pdo->prepare("
      SELECT row_key, label
      FROM lager_row_labels
      WHERE halle = :h AND zone = :z
      ORDER BY CAST(row_key AS UNSIGNED) ASC
    ");
    $st->execute([':h'=>$halle, ':z'=>$zone]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    out(['ok'=>true,'items'=>$rows]);
  }

  // JSON Body optional (falls du später willst)
  $raw = file_get_contents('php://input');
  $json = [];
  if ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $json = $tmp;
  }

  $row_key = trim((string)($_POST['row_key'] ?? $json['row_key'] ?? ''));
  $label   = trim((string)($_POST['label']   ?? $json['label']   ?? ''));

  if ($row_key === '') {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'row_key fehlt.'], 400);
  }

  // ----------------------------
  // DELETE (Label entfernen)
  // ----------------------------
  if ($action === 'delete') {
    $del = $pdo->prepare("
      DELETE FROM lager_row_labels
      WHERE halle=:h AND zone=:z AND row_key=:r
      LIMIT 1
    ");
    $del->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$row_key]);

    out(['ok'=>true,'deleted'=>$del->rowCount()]);
  }

  // ----------------------------
  // UPSERT (insert/update)
  // ----------------------------
  if ($action === 'upsert') {
    if ($label === '') {
      // leer = wie delete behandeln (damit UI "zurücksetzen" kann)
      $del = $pdo->prepare("
        DELETE FROM lager_row_labels
        WHERE halle=:h AND zone=:z AND row_key=:r
        LIMIT 1
      ");
      $del->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$row_key]);
      out(['ok'=>true,'mode'=>'deleted_empty_label','deleted'=>$del->rowCount()]);
    }

    $sql = "
      INSERT INTO lager_row_labels (halle, zone, row_key, label, created_by, updated_by)
      VALUES (:h, :z, :r, :l, :u, :u)
      ON DUPLICATE KEY UPDATE
        label = VALUES(label),
        updated_by = VALUES(updated_by),
        updated_at = NOW()
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':h'=>$halle,
      ':z'=>$zone,
      ':r'=>$row_key,
      ':l'=>$label,
      ':u'=>$user
    ]);

    out(['ok'=>true,'mode'=>'upsert','row_key'=>$row_key,'label'=>$label]);
  }

  out(['ok'=>false,'error'=>'bad_action','msg'=>'Ungültige action (list|upsert|delete).'], 400);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'server_error','msg'=>'Serverfehler.', 'detail'=>$e->getMessage()], 500);
}
