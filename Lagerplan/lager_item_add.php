<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api/_db.php';

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'msg'=>'Nur POST erlaubt.']);
    exit;
  }

  $slotId = (int)($_POST['slot_id'] ?? 0);
  $ref    = trim((string)($_POST['referenznr'] ?? ''));

  // ✅ neu
  $sachnummer   = trim((string)($_POST['sachnummer'] ?? ''));
  $lieferschein = trim((string)($_POST['lieferschein'] ?? ''));
  $mengeRaw     = (string)($_POST['menge'] ?? '1');
  $menge        = (int)$mengeRaw;
  if ($menge < 1) $menge = 1;

  $user = (string)($_SESSION['username'] ?? '');

  if ($slotId <= 0 || $ref === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'slot_id und referenznr sind Pflicht.']);
    exit;
  }

  // (Optional) Wenn du es wirklich erzwingen willst:
  // if ($sachnummer === '' || $lieferschein === '') {
  //   http_response_code(400);
  //   echo json_encode(['ok'=>false,'msg'=>'sachnummer und lieferschein sind Pflicht.']);
  //   exit;
  // }

  // Slot existiert?
  $st = $pdo->prepare("SELECT id, reihe, platz, slot_index FROM lager_slots WHERE id=? LIMIT 1");
  $st->execute([$slotId]);
  $slot = $st->fetch(PDO::FETCH_ASSOC);
  if (!$slot) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'msg'=>'Slot nicht gefunden.']);
    exit;
  }

  // Duplicate check: Karton darf nirgendwo sonst existieren (Palette)
  $st = $pdo->prepare("SELECT id, reihe, platz, slot_index FROM lager_slots WHERE referenznr=? LIMIT 1");
  $st->execute([$ref]);
  $dupSlot = $st->fetch(PDO::FETCH_ASSOC);
  if ($dupSlot) {
    echo json_encode([
      'ok'=>false,
      'error'=>'duplicate_ref',
      'msg'=>'Referenz existiert bereits als Paletten-Ref.',
      'existing'=>$dupSlot
    ]);
    exit;
  }

  // Duplicate check: Karton existiert bereits als Karton
  $st = $pdo->prepare("SELECT i.id, s.reihe, s.platz, s.slot_index
                         FROM lager_slot_items i
                         JOIN lager_slots s ON s.id=i.slot_id
                        WHERE i.referenznr=? LIMIT 1");
  $st->execute([$ref]);
  $dupItem = $st->fetch(PDO::FETCH_ASSOC);
  if ($dupItem) {
    echo json_encode([
      'ok'=>false,
      'error'=>'duplicate_ref',
      'msg'=>'Karton-Referenz existiert bereits.',
      'existing'=>$dupItem
    ]);
    exit;
  }

  // Insert (✅ neu: sachnummer, menge, lieferschein)
  $ins = $pdo->prepare("
    INSERT INTO lager_slot_items (slot_id, referenznr, sachnummer, menge, lieferschein, created_by)
    VALUES (?,?,?,?,?,?)
  ");
  $ins->execute([$slotId, $ref, ($sachnummer !== '' ? $sachnummer : null), $menge, ($lieferschein !== '' ? $lieferschein : null), $user]);

  $newId = (int)$pdo->lastInsertId();

  // Optional: frisch eingefügten Datensatz zurückgeben (praktisch fürs UI)
  $st = $pdo->prepare("SELECT id, slot_id, referenznr, sachnummer, menge, lieferschein, created_at, created_by
                         FROM lager_slot_items
                        WHERE id=? LIMIT 1");
  $st->execute([$newId]);
  $item = $st->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok'=>true,
    'id'=>$newId,
    'item'=>$item,
    'slot'=>$slot
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
