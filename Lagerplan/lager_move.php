<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__) . '/api/_db.php';

function out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// --- Helfer: Plätze sperren + laden (NUR aktive Datensätze) ---
function lockPlace(PDO $pdo, string $T, string $halle, string $zone, string $row, int $platz, string $ACTIVE_WHERE): array {
  $st = $pdo->prepare("
    SELECT id, halle, zone, reihe, platz, slot_index
      FROM {$T}
     WHERE halle=:h AND zone=:z AND reihe=:r AND platz=:p
       AND {$ACTIVE_WHERE}
     FOR UPDATE
  ");
  $st->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$row, ':p'=>$platz]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function keyPlace(string $row, int $platz): string {
  return $row . '#' . str_pad((string)$platz, 5, '0', STR_PAD_LEFT);
}

// --- NEU: Archiv-Datensatz am Zielslot finden + sperren ---
function lockArchivedAt(PDO $pdo, string $T, string $halle, string $zone, string $row, int $platz, int $slotIndex): ?array {
  $st = $pdo->prepare("
    SELECT id, halle, zone, reihe, platz, slot_index
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

// --- NEU: Quelle kurz parken (unique-sicher) ---
function parkActiveRow(PDO $pdo, string $T, int $id, string $ACTIVE_WHERE): array {
  // Parkposition: Reihe 0, Platz sehr groß (pro ID eindeutig), Slot 0
  // (funktioniert bei INT oder VARCHAR für reihe, und int platz)
  $tmpRow  = '0';
  $tmpPlz  = 900000 + $id;
  $tmpIdx  = 0;

  $st = $pdo->prepare("
    UPDATE {$T}
       SET reihe=:r, platz=:p, slot_index=:i
     WHERE id=:id
       AND {$ACTIVE_WHERE}
  ");
  $st->execute([':r'=>$tmpRow, ':p'=>$tmpPlz, ':i'=>$tmpIdx, ':id'=>$id]);

  return ['reihe'=>$tmpRow, 'platz'=>$tmpPlz, 'slot_index'=>$tmpIdx];
}

// --- NEU: Move mit Archiv-Swap (damit uq_position nicht knallt) ---
function moveActiveWithArchiveSwap(
  PDO $pdo,
  string $T,
  string $halle,
  string $zone,
  int $srcId,
  string $srcFromRow,
  int $srcFromPlz,
  int $srcFromIdx,
  string $toRow,
  int $toPlz,
  int $toIdx,
  string $ACTIVE_WHERE
): array {

  // Prüfe: sitzt auf dem Zielslot ein archivierter Datensatz?
  $arch = lockArchivedAt($pdo, $T, $halle, $zone, $toRow, $toPlz, $toIdx);

  // Wenn kein Archiv blockiert -> normal updaten
  if (!$arch) {
    $upd = $pdo->prepare("
      UPDATE {$T}
         SET reihe=:r, platz=:p, slot_index=:i
       WHERE id=:id
         AND {$ACTIVE_WHERE}
    ");
    $upd->execute([':r'=>$toRow, ':p'=>$toPlz, ':i'=>$toIdx, ':id'=>$srcId]);

    return ['swapped'=>false, 'archived_id'=>null];
  }

  // Archiv blockiert -> Swap:
  // 1) Quelle parken (damit Quell-Koordinate frei wird)
  parkActiveRow($pdo, $T, $srcId, $ACTIVE_WHERE);

  // 2) Archivdatensatz auf die alte Quellposition schieben (bleibt archiviert!)
  $updArch = $pdo->prepare("
    UPDATE {$T}
       SET reihe=:r, platz=:p, slot_index=:i
     WHERE id=:id
       AND deleted_at IS NOT NULL
  ");
  $updArch->execute([
    ':r'  => $srcFromRow,
    ':p'  => $srcFromPlz,
    ':i'  => $srcFromIdx,
    ':id' => (int)$arch['id']
  ]);

  // 3) Quelle von Parkposition auf Zielposition schieben
  $updSrc = $pdo->prepare("
    UPDATE {$T}
       SET reihe=:r, platz=:p, slot_index=:i
     WHERE id=:id
       AND {$ACTIVE_WHERE}
  ");
  $updSrc->execute([
    ':r'  => $toRow,
    ':p'  => $toPlz,
    ':i'  => $toIdx,
    ':id' => $srcId
  ]);

  return ['swapped'=>true, 'archived_id'=>(int)$arch['id']];
}

// --- Helfer: Slot-Index Mapping bauen (kollisionsfrei, gegen aktive!) ---
function buildMoves(array $srcRows, array $tgtRows, bool $keepIndex = true): array {
  $used = [];
  foreach ($tgtRows as $r) $used[(int)$r['slot_index']] = true;

  $free = [];
  for ($i=0; $i<4; $i++) if (!isset($used[$i])) $free[] = $i;

  // sortiere Quelle stabil (erst slot_index, dann id)
  usort($srcRows, function($a, $b){
    $ai = (int)$a['slot_index']; $bi = (int)$b['slot_index'];
    if ($ai === $bi) return (int)$a['id'] <=> (int)$b['id'];
    return $ai <=> $bi;
  });

  $moves = [];
  foreach ($srcRows as $r) {
    $fromIdx = (int)$r['slot_index'];
    $newIdx = null;

    if ($keepIndex && !isset($used[$fromIdx])) {
      $newIdx = $fromIdx;
      $used[$newIdx] = true;

      $k = array_search($newIdx, $free, true);
      if ($k !== false) { unset($free[$k]); $free = array_values($free); }
    } else {
      if (count($free) === 0) {
        throw new RuntimeException('target_full');
      }
      $newIdx = array_shift($free);
      $used[$newIdx] = true;
    }

    $moves[] = [
      'id' => (int)$r['id'],
      'from_slot_index' => $fromIdx,
      'to_slot_index' => $newIdx,
      'from_reihe' => (string)$r['reihe'],
      'from_platz' => (int)$r['platz'],
    ];
  }

  return $moves;
}

try {
  // !!! Tabellenname hier anpassen !!!
  $T = 'lager_slots';

  // ✅ NUR aktive Slots zählen/prüfen (archivierte blockieren NICHT beim Kapazitätscheck)
  $ACTIVE_WHERE = "deleted_at IS NULL";

  $mode  = trim((string)($_POST['mode'] ?? 'slot')); // slot|place|row
  $halle = trim($_POST['halle'] ?? '');
  $zone  = trim($_POST['zone'] ?? '');

  if ($halle === '' || $zone === '') {
    out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (halle/zone).'], 400);
  }

  // --------------------------------------------------------------------
  // MODE: SLOT (einzelne Palette umbuchen)
  // --------------------------------------------------------------------
  if ($mode === 'slot' || $mode === '') {
    $id    = (int)($_POST['id'] ?? 0);
    $toRow = trim($_POST['to_row'] ?? '');
    $toPlz = (int)($_POST['to_platz'] ?? 0);

    if ($id <= 0 || $toRow === '' || $toPlz <= 0) {
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (id/to_row/to_platz).'], 400);
    }

    $pdo->beginTransaction();

    // Quelle laden + sperren (✅ nur aktiv)
    $st = $pdo->prepare("
      SELECT id, halle, zone, reihe, platz, slot_index
        FROM {$T}
       WHERE id = :id
         AND {$ACTIVE_WHERE}
       FOR UPDATE
    ");
    $st->execute([':id' => $id]);
    $src = $st->fetch(PDO::FETCH_ASSOC);

    if (!$src) {
      $pdo->rollBack();
      out(['ok'=>false,'error'=>'not_found','msg'=>'Datensatz nicht gefunden oder bereits archiviert.'], 404);
    }

    if ($src['halle'] !== $halle || $src['zone'] !== $zone) {
      $pdo->rollBack();
      out(['ok'=>false,'error'=>'mismatch','msg'=>'Halle/Zone passt nicht zum Datensatz.'], 400);
    }

    $fromRow = (string)$src['reihe'];
    $fromPlz = (int)$src['platz'];
    $fromIdx = (int)$src['slot_index'];

    // Wenn Ziel = Quelle -> ok zurück
    if ($fromRow === $toRow && $fromPlz === $toPlz) {
      $pdo->commit();
      out([
        'ok'=>true,
        'mode'=>'slot',
        'from'=>['reihe'=>$fromRow,'platz'=>$fromPlz,'slot_index'=>$fromIdx],
        'to'  =>['reihe'=>$toRow, 'platz'=>$toPlz, 'slot_index'=>$fromIdx],
      ]);
    }

    // belegte Slots am Ziel holen (✅ nur aktive, ohne diesen Datensatz)
    $st = $pdo->prepare("
      SELECT slot_index
        FROM {$T}
       WHERE halle=:h AND zone=:z AND reihe=:r AND platz=:p
         AND id<>:id
         AND {$ACTIVE_WHERE}
       FOR UPDATE
    ");
    $st->execute([
      ':h'=>$halle, ':z'=>$zone, ':r'=>$toRow, ':p'=>$toPlz, ':id'=>$id
    ]);
    $used = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    // freien Slot 0..3 finden (gegen aktive!)
    $free = null;
    for ($i=0; $i<4; $i++) {
      if (!in_array($i, $used, true)) { $free = $i; break; }
    }
    if ($free === null) {
      $pdo->rollBack();
      out(['ok'=>false,'error'=>'target_full','msg'=>'Zielplatz ist voll (4/4).'], 409);
    }

    // ✅ NEU: Move mit Archiv-Swap (verhindert uq_position Duplicate)
    $swapInfo = moveActiveWithArchiveSwap(
      $pdo, $T, $halle, $zone,
      $id, $fromRow, $fromPlz, $fromIdx,
      $toRow, $toPlz, $free,
      $ACTIVE_WHERE
    );

    $pdo->commit();

    out([
      'ok'=>true,
      'mode'=>'slot',
      'from'=>['reihe'=>$fromRow,'platz'=>$fromPlz,'slot_index'=>$fromIdx],
      'to'  =>['reihe'=>$toRow, 'platz'=>$toPlz, 'slot_index'=>$free],
      'archiv_swap'=>$swapInfo
    ]);
  }

  // --------------------------------------------------------------------
  // MODE: PLACE (ganzen Lagerplatz umbuchen: alle Slots von A nach B)
  // --------------------------------------------------------------------
  if ($mode === 'place') {
    $id        = (int)($_POST['id'] ?? 0);            // optional
    $fromRowIn = trim($_POST['from_row'] ?? '');      // optional, wenn id da ist
    $fromPlzIn = (int)($_POST['from_platz'] ?? 0);    // optional, wenn id da ist
    $toRow     = trim($_POST['to_row'] ?? '');
    $toPlz     = (int)($_POST['to_platz'] ?? 0);
    $keepIndex = (int)($_POST['keep_index'] ?? 1) === 1;

    if ($toRow === '' || $toPlz <= 0) {
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (to_row/to_platz).'], 400);
    }

    $pdo->beginTransaction();

    // Quelle bestimmen (entweder über id oder über from_row/from_platz)
    $fromRow = $fromRowIn;
    $fromPlz = $fromPlzIn;

    if ($id > 0) {
      $st = $pdo->prepare("
        SELECT id, halle, zone, reihe, platz
          FROM {$T}
         WHERE id=:id
           AND {$ACTIVE_WHERE}
         FOR UPDATE
      ");
      $st->execute([':id'=>$id]);
      $one = $st->fetch(PDO::FETCH_ASSOC);

      if (!$one) {
        $pdo->rollBack();
        out(['ok'=>false,'error'=>'not_found','msg'=>'Datensatz nicht gefunden oder bereits archiviert.'], 404);
      }
      if ($one['halle'] !== $halle || $one['zone'] !== $zone) {
        $pdo->rollBack();
        out(['ok'=>false,'error'=>'mismatch','msg'=>'Halle/Zone passt nicht zum Datensatz.'], 400);
      }
      $fromRow = (string)$one['reihe'];
      $fromPlz = (int)$one['platz'];
    }

    if ($fromRow === '' || $fromPlz <= 0) {
      $pdo->rollBack();
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (from_row/from_platz oder id).'], 400);
    }

    if ($fromRow === $toRow && $fromPlz === $toPlz) {
      $pdo->commit();
      out(['ok'=>true,'mode'=>'place','moved'=>[],'msg'=>'Quelle und Ziel sind identisch.']);
    }

    // Lock-Reihenfolge stabil (Deadlock vermeiden)
    $kFrom = keyPlace($fromRow, $fromPlz);
    $kTo   = keyPlace($toRow,   $toPlz);

    if ($kFrom <= $kTo) {
      $srcRows = lockPlace($pdo, $T, $halle, $zone, $fromRow, $fromPlz, $ACTIVE_WHERE);
      $tgtRows = lockPlace($pdo, $T, $halle, $zone, $toRow,   $toPlz,   $ACTIVE_WHERE);
    } else {
      $tgtRows = lockPlace($pdo, $T, $halle, $zone, $toRow,   $toPlz,   $ACTIVE_WHERE);
      $srcRows = lockPlace($pdo, $T, $halle, $zone, $fromRow, $fromPlz, $ACTIVE_WHERE);
    }

    if (count($srcRows) === 0) {
      $pdo->commit();
      out(['ok'=>true,'mode'=>'place','moved'=>[],'msg'=>'Am Quellplatz ist nichts (aktives) zu verschieben.']);
    }

    // ✅ Ziel-Kapazität prüfen (nur aktive zählen!)
    if (count($srcRows) + count($tgtRows) > 4) {
      $pdo->rollBack();
      out([
        'ok'=>false,
        'error'=>'target_full',
        'msg'=>'Zielplatz hat nicht genug freie Slots (max 4).',
        'detail'=>['src'=>count($srcRows),'tgt'=>count($tgtRows)]
      ], 409);
    }

    $moves = buildMoves($srcRows, $tgtRows, $keepIndex);

    $moved = [];
    foreach ($moves as $m) {
      // ✅ NEU: pro Palette Archiv-Swap möglich
      $swapInfo = moveActiveWithArchiveSwap(
        $pdo, $T, $halle, $zone,
        (int)$m['id'],
        (string)$m['from_reihe'], (int)$m['from_platz'], (int)$m['from_slot_index'],
        $toRow, $toPlz, (int)$m['to_slot_index'],
        $ACTIVE_WHERE
      );

      $moved[] = [
        'id'=>$m['id'],
        'from'=>['reihe'=>$m['from_reihe'],'platz'=>$m['from_platz'],'slot_index'=>$m['from_slot_index']],
        'to'  =>['reihe'=>$toRow,'platz'=>$toPlz,'slot_index'=>$m['to_slot_index']],
        'archiv_swap'=>$swapInfo
      ];
    }

    $pdo->commit();
    out([
      'ok'=>true,
      'mode'=>'place',
      'from'=>['reihe'=>$fromRow,'platz'=>$fromPlz],
      'to'  =>['reihe'=>$toRow,'platz'=>$toPlz],
      'moved'=>$moved,
      'count'=>count($moved),
      'keep_index'=>$keepIndex
    ]);
  }

  // --------------------------------------------------------------------
  // MODE: ROW (ganze Reihe umbuchen: z.B. 114 -> 115, platz bleibt)
  // --------------------------------------------------------------------
  if ($mode === 'row') {
    $fromRow = trim($_POST['from_row'] ?? '');
    $toRow   = trim($_POST['to_row'] ?? '');
    $keepIndex = (int)($_POST['keep_index'] ?? 1) === 1;

    if ($fromRow === '' || $toRow === '') {
      out(['ok'=>false,'error'=>'missing_params','msg'=>'Parameter fehlen (from_row/to_row).'], 400);
    }
    if ($fromRow === $toRow) {
      out(['ok'=>true,'mode'=>'row','moved'=>[],'msg'=>'from_row und to_row sind identisch.']);
    }

    $pdo->beginTransaction();

    // Welche Plätze gibt es in der Quellreihe? (✅ nur aktive!)
    $st = $pdo->prepare("
      SELECT DISTINCT platz
        FROM {$T}
       WHERE halle=:h AND zone=:z AND reihe=:r
         AND {$ACTIVE_WHERE}
       ORDER BY platz ASC
    ");
    $st->execute([':h'=>$halle, ':z'=>$zone, ':r'=>$fromRow]);
    $plaetze = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (count($plaetze) === 0) {
      $pdo->commit();
      out(['ok'=>true,'mode'=>'row','moved'=>[],'msg'=>'In der Quellreihe ist nichts (aktives) vorhanden.']);
    }

    $movedAll = [];

    foreach ($plaetze as $platz) {
      // Lock Reihenfolge stabil pro Platz (Deadlock vermeiden)
      $kFrom = keyPlace($fromRow, $platz);
      $kTo   = keyPlace($toRow,   $platz);

      if ($kFrom <= $kTo) {
        $srcRows = lockPlace($pdo, $T, $halle, $zone, $fromRow, $platz, $ACTIVE_WHERE);
        $tgtRows = lockPlace($pdo, $T, $halle, $zone, $toRow,   $platz, $ACTIVE_WHERE);
      } else {
        $tgtRows = lockPlace($pdo, $T, $halle, $zone, $toRow,   $platz, $ACTIVE_WHERE);
        $srcRows = lockPlace($pdo, $T, $halle, $zone, $fromRow, $platz, $ACTIVE_WHERE);
      }

      if (count($srcRows) === 0) continue;

      if (count($srcRows) + count($tgtRows) > 4) {
        $pdo->rollBack();
        out([
          'ok'=>false,
          'error'=>'target_full',
          'msg'=>"Zielreihe hat an Platz {$platz} nicht genug freie Slots (max 4).",
          'detail'=>['platz'=>$platz,'src'=>count($srcRows),'tgt'=>count($tgtRows)]
        ], 409);
      }

      $moves = buildMoves($srcRows, $tgtRows, $keepIndex);

      foreach ($moves as $m) {
        // ✅ NEU: pro Palette Archiv-Swap möglich
        $swapInfo = moveActiveWithArchiveSwap(
          $pdo, $T, $halle, $zone,
          (int)$m['id'],
          (string)$fromRow, (int)$platz, (int)$m['from_slot_index'],
          (string)$toRow,   (int)$platz, (int)$m['to_slot_index'],
          $ACTIVE_WHERE
        );

        $movedAll[] = [
          'id'=>$m['id'],
          'from'=>['reihe'=>$fromRow,'platz'=>$platz,'slot_index'=>$m['from_slot_index']],
          'to'  =>['reihe'=>$toRow,'platz'=>$platz,'slot_index'=>$m['to_slot_index']],
          'archiv_swap'=>$swapInfo
        ];
      }
    }

    $pdo->commit();
    out([
      'ok'=>true,
      'mode'=>'row',
      'from'=>['reihe'=>$fromRow],
      'to'  =>['reihe'=>$toRow],
      'moved'=>$movedAll,
      'count'=>count($movedAll),
      'keep_index'=>$keepIndex
    ]);
  }

  out(['ok'=>false,'error'=>'invalid_mode','msg'=>'Unbekannter mode. Erlaubt: slot|place|row'], 400);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

  // RuntimeException('target_full') sauber als 409 zurückgeben
  if ($e instanceof RuntimeException && $e->getMessage() === 'target_full') {
    out(['ok'=>false,'error'=>'target_full','msg'=>'Zielplatz ist voll (4/4).'], 409);
  }

  // Duplicate-Key sauberer Fehlertext (hilft im Frontend)
  $msg = $e->getMessage();
  if ($e instanceof PDOException && str_contains($msg, 'SQLSTATE[23000]') && str_contains($msg, '1062')) {
    out([
      'ok'=>false,
      'error'=>'duplicate_key',
      'msg'=>'Duplicate-Key beim Umbuchen (Unique-Index uq_position).',
      'detail'=>$msg,
      'hint'=>'Durch Archiv-Swap sollte das i.d.R. weg sein. Wenn es noch passiert: sag mir die Duplicate-entry (z.B. H3-W1-17-26-0).'
    ], 409);
  }

  out([
    'ok'=>false,
    'error'=>'server_error',
    'msg'=>'Serverfehler beim Umbuchen.',
    'detail'=>$msg
  ], 500);
}
