<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require dirname(__DIR__) . '/api/_db.php';

function out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function postv(string $k, $default='') {
  return $_POST[$k] ?? $default;
}
function getv(string $k, $default='') {
  return $_GET[$k] ?? $default;
}

try {
  $action = trim((string)(getv('action', postv('action', 'list'))));
  $user = (string)($_SESSION['username'] ?? 'unknown');

  $T_FLAGS = 'lager_slot_flags';
  $T_SLOTS = 'lager_slots';

  // -------------------------
  // LIST: aktive Flags für Halle/Zone (nur aktive Slots)
  // GET: ?action=list&halle=H3&zone=W1
  // optional: &slot_id=123
  // -------------------------
  if ($action === 'list') {
    $halle = trim((string)getv('halle', postv('halle', '')));
    $zone  = trim((string)getv('zone',  postv('zone',  '')));
    $slotId = (int)getv('slot_id', postv('slot_id', 0));

    if ($slotId > 0) {
      $st = $pdo->prepare("
        SELECT id, slot_id, flag_type, note, expected_reihe, expected_platz, expected_slot_index,
               is_active, created_by, created_at, resolved_by, resolved_at
        FROM {$T_FLAGS}
        WHERE slot_id = :sid
        ORDER BY created_at DESC, id DESC
      ");
      $st->execute([':sid'=>$slotId]);
      out(['ok'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($halle === '' || $zone === '') {
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (halle/zone).'], 400);
    }

    // nur aktive Flags + nur aktive Slots (deleted_at IS NULL)
    $st = $pdo->prepare("
      SELECT f.id, f.slot_id, f.flag_type, f.note, f.expected_reihe, f.expected_platz, f.expected_slot_index,
             f.created_by, f.created_at
      FROM {$T_FLAGS} f
      JOIN {$T_SLOTS} s ON s.id = f.slot_id
      WHERE f.is_active = 1
        AND s.deleted_at IS NULL
        AND s.halle = :h
        AND s.zone  = :z
      ORDER BY f.created_at ASC, f.id ASC
    ");
    $st->execute([':h'=>$halle, ':z'=>$zone]);
    out(['ok'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // -------------------------
  // SET (upsert): setzt EIN aktives Flag pro Slot (alte aktive werden "resolved")
  // POST: action=set, slot_id, flag_type, note?, expected_reihe?, expected_platz?, expected_slot_index?
  // -------------------------
  if ($action === 'set') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      out(['ok'=>false,'error'=>'method','msg'=>'Nur POST erlaubt.'], 405);
    }

    $slotId = (int)postv('slot_id', 0);
    $flagType = trim((string)postv('flag_type', ''));
    $note = trim((string)postv('note', ''));
    $expRow = trim((string)postv('expected_reihe', ''));
    $expPlatzRaw = postv('expected_platz', null);
    $expPlatz = ($expPlatzRaw === null || $expPlatzRaw === '') ? null : (int)$expPlatzRaw;

    $expSlotRaw = postv('expected_slot_index', null);
    $expSlot = ($expSlotRaw === null || $expSlotRaw === '') ? null : (int)$expSlotRaw;

    $allowed = ['VW_MISSING','LOC_WRONG','VW_LOC_WRONG','NEEDS_CHECK'];
    if ($slotId <= 0 || $flagType === '') {
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (slot_id/flag_type).'], 400);
    }
    if (!in_array($flagType, $allowed, true)) {
      out(['ok'=>false,'error'=>'bad_flag','msg'=>'Ungültiger flag_type.'], 400);
    }

    // Slot muss existieren + aktiv sein
    $st = $pdo->prepare("SELECT id FROM {$T_SLOTS} WHERE id=:id AND deleted_at IS NULL LIMIT 1");
    $st->execute([':id'=>$slotId]);
    if (!(int)$st->fetchColumn()) {
      out(['ok'=>false,'error'=>'slot_not_found','msg'=>'Slot nicht gefunden oder archiviert.'], 404);
    }

    $pdo->beginTransaction();

    // alte aktive Flags für Slot "auflösen"
    $st = $pdo->prepare("
      UPDATE {$T_FLAGS}
      SET is_active=0, resolved_by=:u, resolved_at=NOW()
      WHERE slot_id=:sid AND is_active=1
    ");
    $st->execute([':u'=>$user, ':sid'=>$slotId]);

    // neues Flag anlegen
    $ins = $pdo->prepare("
      INSERT INTO {$T_FLAGS}
        (slot_id, flag_type, note, expected_reihe, expected_platz, expected_slot_index, is_active, created_by, created_at)
      VALUES
        (:sid, :t, :n, :er, :ep, :esi, 1, :u, NOW())
    ");
    $ins->execute([
      ':sid'=>$slotId,
      ':t'=>$flagType,
      ':n'=>$note !== '' ? $note : null,
      ':er'=>$expRow !== '' ? $expRow : null,
      ':ep'=>$expPlatz,
      ':esi'=>$expSlot,
      ':u'=>$user
    ]);

    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();

    out([
      'ok'=>true,
      'id'=>$newId,
      'slot_id'=>$slotId,
      'flag_type'=>$flagType,
      'note'=>$note,
      'expected_reihe'=>$expRow,
      'expected_platz'=>$expPlatz,
      'expected_slot_index'=>$expSlot,
      'created_by'=>$user,
      'created_at'=>date('Y-m-d H:i:s')
    ]);
  }

  // -------------------------
  // RESOLVE: Flag(s) eines Slots deaktivieren
  // POST: action=resolve, slot_id
  // -------------------------
  if ($action === 'resolve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      out(['ok'=>false,'error'=>'method','msg'=>'Nur POST erlaubt.'], 405);
    }
    $slotId = (int)postv('slot_id', 0);
    if ($slotId <= 0) out(['ok'=>false,'error'=>'missing_params','msg'=>'slot_id fehlt.'], 400);

    $st = $pdo->prepare("
      UPDATE {$T_FLAGS}
      SET is_active=0, resolved_by=:u, resolved_at=NOW()
      WHERE slot_id=:sid AND is_active=1
    ");
    $st->execute([':u'=>$user, ':sid'=>$slotId]);

    out(['ok'=>true, 'slot_id'=>$slotId, 'resolved'=>$st->rowCount()]);
  }

  out(['ok'=>false,'error'=>'bad_action','msg'=>'Ungültige action (list|set|resolve).'], 400);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(['ok'=>false,'error'=>'server_error','msg'=>'Flags API Fehler.','detail'=>$e->getMessage()], 500);
}
