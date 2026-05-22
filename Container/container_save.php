<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0');
error_reporting(E_ALL);

$DB = realpath(__DIR__ . '/../../api/_db.php'); // ✅ genau dein Pfad
if (!$DB || !file_exists($DB)) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "msg" => "DB Include nicht gefunden",
    "try" => __DIR__ . "/../../api/_db.php"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once $DB;

$unitId    = (int)($_POST['unit_id'] ?? 0);
$slotIndex = (int)($_POST['slot_index'] ?? -1);
$ref       = trim((string)($_POST['referenznr'] ?? ''));
$sach      = trim((string)($_POST['sachnummer'] ?? ''));
$ls        = trim((string)($_POST['lieferschein'] ?? ''));
$menge     = max(1, (int)($_POST['menge'] ?? 1));
$userName  = trim((string)($_POST['user_name'] ?? ''));

if ($unitId<=0 || $slotIndex<0 || $ref==='' || $sach==='') {
  echo json_encode(['ok'=>false,'msg'=>'Pflichtfelder fehlen']); exit;
}

try {
  // Unit prüfen + capacity
  $u = $pdo->prepare("SELECT id, code, capacity FROM storage_units WHERE id=? AND type='CONTAINER' AND active=1");
  $u->execute([$unitId]);
  $unit = $u->fetch(PDO::FETCH_ASSOC);
  if (!$unit) { echo json_encode(['ok'=>false,'msg'=>'Container nicht gefunden']); exit; }
  if ($slotIndex >= (int)$unit['capacity']) { echo json_encode(['ok'=>false,'msg'=>'slot_index außerhalb capacity']); exit; }

  // Duplicate Ref global (in storage_slots)
  $d = $pdo->prepare("SELECT s.id, u.code AS unit_code, s.slot_index
                      FROM storage_slots s
                      JOIN storage_units u ON u.id=s.unit_id
                      WHERE s.referenznr=? LIMIT 1");
  $d->execute([$ref]);
  $dup = $d->fetch(PDO::FETCH_ASSOC);
  if ($dup) {
    echo json_encode([
      'ok'=>false,
      'error'=>'duplicate_ref',
      'msg'=>"Referenz ist bereits vorhanden: {$dup['unit_code']} / Slot ".((int)$dup['slot_index']+1),
      'existing'=>$dup
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Slot belegt?
  $s = $pdo->prepare("SELECT id, referenznr FROM storage_slots WHERE unit_id=? AND slot_index=? LIMIT 1");
  $s->execute([$unitId, $slotIndex]);
  $existingSlot = $s->fetch(PDO::FETCH_ASSOC);
  if ($existingSlot) {
    echo json_encode(['ok'=>false,'error'=>'slot_occupied','msg'=>'Slot ist bereits belegt'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $ins = $pdo->prepare("INSERT INTO storage_slots (unit_id, slot_index, referenznr, sachnummer, lieferschein, menge, user_name)
                        VALUES (?,?,?,?,?,?,?)");
  $ins->execute([$unitId, $slotIndex, $ref, $sach, ($ls!==''?$ls:null), $menge, ($userName!==''?$userName:null)]);

  $id = (int)$pdo->lastInsertId();

  echo json_encode(['ok'=>true, 'id'=>$id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
