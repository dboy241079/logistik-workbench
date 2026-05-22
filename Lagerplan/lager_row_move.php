<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require dirname(__DIR__) . '/api/_db.php';

function out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

const DEFAULT_MAX_PLACE = 35; // <- wenn du 40 Plätze hast: auf 40 ändern!

function capForRow(string $row): int {
  return ($row === '20') ? 20 : 4;   // Reihe 20 special, sonst 4 Slots
}

function maxPlaceForRow(string $row): int {
  $r = (int)$row;

  if ($r === 20) return 25; // Sonderreihe
  if ($r === 43) return 35; // ✅ 35 Plätze * 4 Slots = 140 Slots

  return DEFAULT_MAX_PLACE; // bleibt bei 30 (oder was du willst)
}


/** archived blocker am Zielslot (FOR UPDATE) */
function lockArchivedBlocker(PDO $pdo, string $T, string $halle, string $zone, string $row, int $platz, int $slotIndex): ?array {
  $st = $pdo->prepare("
    SELECT id, reihe, platz, slot_index
      FROM {$T}
     WHERE halle=:h AND zone=:z AND reihe=:r AND platz=:p AND slot_index=:i
       AND deleted_at IS NOT NULL
     LIMIT 1
     FOR UPDATE
  ");
  $st->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$row, ':p'=>$platz, ':i'=>$slotIndex]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

try {
  $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'check'));

  $halle   = trim((string)($_POST['halle'] ?? $_GET['halle'] ?? ''));
  $zone    = trim((string)($_POST['zone']  ?? $_GET['zone']  ?? ''));
  $fromRow = trim((string)($_POST['from_row'] ?? $_GET['from_row'] ?? ''));
  $toRow   = trim((string)($_POST['to_row']   ?? $_GET['to_row']   ?? ''));
  $keepIdx = (int)($_POST['keep_index'] ?? $_GET['keep_index'] ?? 1);

  if ($halle === '' || $zone === '' || $fromRow === '' || $toRow === '') {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (halle/zone/from_row/to_row).'], 400);
  }
  if ($fromRow === $toRow) {
    out(['ok'=>false,'error'=>'same_row','msg'=>'Von- und Nach-Reihe sind identisch.'], 400);
  }

  $T = 'lager_slots';

  $cap   = capForRow($toRow);
  $toMax = maxPlaceForRow($toRow);

  // ---------------------------
  // Counts source/target (nur aktive)
  // ---------------------------
  $cntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM {$T}
    WHERE halle=:h AND zone=:z AND reihe=:r
      AND deleted_at IS NULL
  ");

  $cntStmt->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$fromRow]);
  $fromCount = (int)$cntStmt->fetchColumn();

  $cntStmt->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$toRow]);
  $toCount = (int)$cntStmt->fetchColumn();

  $capacityTotal = $toMax * $cap;
  $freeTotal = max(0, $capacityTotal - $toCount);
  $canMove = ($fromCount <= $freeTotal);

  // Hinweise ob "gleiches Platzmapping" Konflikte hätte (bezogen auf aktive)
  $perPlaceStmt = $pdo->prepare("
    SELECT platz, COUNT(*) AS cnt
    FROM {$T}
    WHERE halle=:h AND zone=:z AND reihe=:r
      AND deleted_at IS NULL
    GROUP BY platz
  ");

  $perPlaceStmt->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$fromRow]);
  $srcPerPlace = $perPlaceStmt->fetchAll(PDO::FETCH_KEY_PAIR); // platz => cnt

  $perPlaceStmt->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$toRow]);
  $tgtPerPlace = $perPlaceStmt->fetchAll(PDO::FETCH_KEY_PAIR);

  $warnings = [];
  $requiresRepack = false;

  foreach ($srcPerPlace as $platzStr => $srcCntStr) {
    $platz = (int)$platzStr;
    $srcCnt = (int)$srcCntStr;
    $tgtCnt = isset($tgtPerPlace[$platzStr]) ? (int)$tgtPerPlace[$platzStr] : 0;

    if ($platz > $toMax) {
      $requiresRepack = true;
      $warnings[] = [
        'type'=>'out_of_range',
        'platz'=>$platz,
        'msg'=>"Quelle hat Platz {$platz}, Zielreihe {$toRow} hat max {$toMax} → wird umverteilt."
      ];
      continue;
    }

    if (($srcCnt + $tgtCnt) > $cap) {
      $requiresRepack = true;
      $warnings[] = [
        'type'=>'place_conflict',
        'platz'=>$platz,
        'src'=>$srcCnt,
        'tgt'=>$tgtCnt,
        'cap'=>$cap,
        'msg'=>"Platz {$platz}: Quelle {$srcCnt} + Ziel {$tgtCnt} > Kapazität {$cap} → wird umverteilt."
      ];
    }
  }

  if ($action === 'check') {
    out([
      'ok'=>true,
      'action'=>'check',
      'halle'=>$halle,'zone'=>$zone,
      'from_row'=>$fromRow,'to_row'=>$toRow,
      'cap'=>$cap,
      'to_max_place'=>$toMax,
      'from_count'=>$fromCount,
      'to_count'=>$toCount,
      'capacity_total'=>$capacityTotal,
      'free_total'=>$freeTotal,
      'can_move'=>$canMove,
      'requires_repack'=>$requiresRepack,
      'warnings'=>$warnings
    ]);
  }

  if ($action !== 'move') {
    out(['ok'=>false,'error'=>'bad_action','msg'=>'Ungültige action (check|move).'], 400);
  }

  if (!$canMove) {
    out([
      'ok'=>false,
      'error'=>'insufficient_total_capacity',
      'msg'=>"Umbuchen nicht möglich: Zielreihe hat nur {$freeTotal} freie Slots, benötigt {$fromCount}.",
      'free_total'=>$freeTotal,
      'from_count'=>$fromCount
    ], 409);
  }

  // ---------------------------
  // MOVE (mit Archiv-SWAP, damit uq_lager_pos nicht knallt)
  // ---------------------------
  $pdo->beginTransaction();

  // Quelle locken (nur aktive)
  $srcStmt = $pdo->prepare("
    SELECT id, platz, slot_index
    FROM {$T}
    WHERE halle=:h AND zone=:z AND reihe=:r
      AND deleted_at IS NULL
    ORDER BY platz ASC, slot_index ASC, id ASC
    FOR UPDATE
  ");
  $srcStmt->execute([':h'=>$halle,':z'=>$zone,':r'=>$fromRow]);
  $srcRows = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$srcRows) {
    $pdo->commit();
    out(['ok'=>true,'action'=>'move','count'=>0,'moved'=>[],'msg'=>'In der Quellreihe ist nichts vorhanden.']);
  }

  // Ziel locken (nur aktive)
  $tgtStmt = $pdo->prepare("
    SELECT platz, slot_index
    FROM {$T}
    WHERE halle=:h AND zone=:z AND reihe=:r
      AND deleted_at IS NULL
    FOR UPDATE
  ");
  $tgtStmt->execute([':h'=>$halle,':z'=>$zone,':r'=>$toRow]);
  $tgtRows = $tgtStmt->fetchAll(PDO::FETCH_ASSOC);

  // used/free bauen (nur aktive belegen Slots)
  $freeIdx = [];
  for ($p=1; $p<=$toMax; $p++) {
    $used = array_fill(0, $cap, false);
    $freeIdx[$p] = [];

    foreach ($tgtRows as $tr) {
      $pp = (int)$tr['platz'];
      $ii = (int)$tr['slot_index'];
      if ($pp === $p && $ii >= 0 && $ii < $cap) {
        $used[$ii] = true;
      }
    }
    for ($i=0; $i<$cap; $i++) {
      if (!$used[$i]) $freeIdx[$p][] = $i;
    }
  }

  $takeFromPlace = function(int $place, int $oldIdx) use (&$freeIdx, $keepIdx): ?int {
    if (empty($freeIdx[$place])) return null;

    if ($keepIdx === 1) {
      $pos = array_search($oldIdx, $freeIdx[$place], true);
      if ($pos !== false) {
        $idx = $freeIdx[$place][$pos];
        array_splice($freeIdx[$place], $pos, 1);
        return $idx;
      }
    }
    return array_shift($freeIdx[$place]);
  };

  $takeAnyFree = function(int $startPlace) use (&$freeIdx, $toMax): ?array {
    for ($p=$startPlace; $p<=$toMax; $p++) {
      if (!empty($freeIdx[$p])) return [$p, array_shift($freeIdx[$p])];
    }
    for ($p=1; $p<$startPlace; $p++) {
      if (!empty($freeIdx[$p])) return [$p, array_shift($freeIdx[$p])];
    }
    return null;
  };

  // ✅ Park-Zone kurz & sicher (damit Unique frei wird)
  // Achtung: Zone-Feldlänge unbekannt -> absichtlich kurz halten
  $parkZone = 'TMP' . substr(bin2hex(random_bytes(2)), 0, 4); // z.B. TMPa1b2

  $park = $pdo->prepare("
    UPDATE {$T}
       SET zone=:pz, updated_at=NOW()
     WHERE id=:id AND deleted_at IS NULL
     LIMIT 1
  ");

  $moveArchived = $pdo->prepare("
    UPDATE {$T}
       SET reihe=:r, platz=:p, slot_index=:i, zone=:z, halle=:h, updated_at=NOW()
     WHERE id=:id AND deleted_at IS NOT NULL
     LIMIT 1
  ");

  $moveSrcToTarget = $pdo->prepare("
    UPDATE {$T}
       SET reihe=:toRow, platz=:toPlatz, slot_index=:toIdx, zone=:z, halle=:h, updated_at=NOW()
     WHERE id=:id AND deleted_at IS NULL
     LIMIT 1
  ");

  $moved = [];

  foreach ($srcRows as $sr) {
    $id   = (int)$sr['id'];
    $oldP = (int)$sr['platz'];
    $oldI = (int)$sr['slot_index'];

    $assignedP = null;
    $assignedI = null;

    // 1) Versuch: gleicher Platz + gleicher Index (wenn frei)
    if ($oldP >= 1 && $oldP <= $toMax) {
      $try = $takeFromPlace($oldP, $oldI);
      if ($try !== null) {
        $assignedP = $oldP;
        $assignedI = $try;
      }
    }

    // 2) Fallback: nächster freier Slot irgendwo
    if ($assignedP === null) {
      $start = ($oldP >= 1 && $oldP <= $toMax) ? $oldP : 1;
      $any = $takeAnyFree($start);
      if (!$any) {
        $pdo->rollBack();
        out(['ok'=>false,'error'=>'unexpected_no_space','msg'=>'Unerwartet: kein freier Slot gefunden trotz Check.'], 500);
      }
      [$assignedP, $assignedI] = $any;
    }

    // ✅ Schritt A: Quelle parken (Zone temporär ändern => alte Position ist frei im Unique-Key)
    $park->execute([':pz'=>$parkZone, ':id'=>$id]);

    // ✅ Schritt B: falls am Zielslot ein ARCHIV-Datensatz sitzt -> in alte Quellposition schieben
    $arch = lockArchivedBlocker($pdo, $T, $halle, $zone, $toRow, $assignedP, $assignedI);
    $archSwap = null;

    if ($arch) {
      $moveArchived->execute([
        ':r'=>$fromRow,
        ':p'=>$oldP,
        ':i'=>$oldI,
        ':z'=>$zone,
        ':h'=>$halle,
        ':id'=>(int)$arch['id']
      ]);
      $archSwap = ['swapped'=>true,'archived_id'=>(int)$arch['id']];
    } else {
      $archSwap = ['swapped'=>false,'archived_id'=>null];
    }

    // ✅ Schritt C: Quelle auf Zielposition (Zone wieder normal)
    $moveSrcToTarget->execute([
      ':toRow'   => $toRow,
      ':toPlatz' => $assignedP,
      ':toIdx'   => $assignedI,
      ':z'       => $zone,
      ':h'       => $halle,
      ':id'      => $id
    ]);

    $moved[] = [
      'id'=>$id,
      'from'=>['reihe'=>$fromRow,'platz'=>$oldP,'slot_index'=>$oldI],
      'to'  =>['reihe'=>$toRow,'platz'=>$assignedP,'slot_index'=>$assignedI],
      'archiv_swap'=>$archSwap
    ];
  }

  $pdo->commit();

  out([
    'ok'=>true,
    'action'=>'move',
    'halle'=>$halle,'zone'=>$zone,
    'from_row'=>$fromRow,'to_row'=>$toRow,
    'count'=>count($moved),
    'moved'=>$moved
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

  out([
    'ok'=>false,
    'error'=>'server_error',
    'msg'=>'Serverfehler bei Reihen-Umbuchung.',
    'detail'=>$e->getMessage()
  ], 500);
}
