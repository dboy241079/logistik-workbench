<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api/_db.php';

function out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['ok' => false, 'msg' => 'Nur POST.'], 405);
  }

  $halle = trim((string)($_POST['halle'] ?? 'H3'));
  $zone  = trim((string)($_POST['zone']  ?? 'W1'));

  $reihe = trim((string)($_POST['reihe'] ?? ''));
  $platz = (int)($_POST['platz'] ?? 0);
  $slot  = (int)($_POST['slot_index'] ?? -1);

  $ref   = trim((string)($_POST['referenznr'] ?? ''));
  $sach  = trim((string)($_POST['sachnummer'] ?? ''));

  $batch = (int)($_POST['batch_id'] ?? 0);
  $user  = $_SESSION['username'] ?? null;

  $lieferschein = trim((string)($_POST['lieferschein'] ?? ''));
  $menge = max(1, (int)($_POST['menge'] ?? 1));

 $verpackung = trim((string)($_POST['verpackung'] ?? ''));
if ($verpackung === '') {
  $verpackung = null;
}

function normalizePack(string $value): string {
  return strtoupper(str_replace([' ', '-'], '', trim($value)));
}

$allowedPacks = [
  '001 006',
  '001 210',
  '003 147',
  '004 147',
  '004 280',
  '006 147',
  '006 280',
  '0806 Pal',
  '111 444',
  '111 820',
  '111 925/2',
  '111 940',
  '111 950',
  '114 003',
  '114 333',
  '507 806',
  '512 097',
  '519 179',
  '519 180',
  '519 198',
  '519 199',
  '519 200',
  '519 203',
  '519 206',
  '528 159',
  '530 396',
  '531 653',
  '532 042',
  '532 043',
  '532 044',
  '532 045',
  '532 046',
  '532 047',
  '532 048',
  '532 050',
  '532 051',
  '532 052',
  '532 053',
  '532 054',
  '532 055',
  '532 059',
  '532 061',
  '532 066',
  '532 067',
  '532 071',
  '532 072',
  '532 076',
  '532 239',
  '532 240',
  '532 241',
  '535 547',
  '536 293',
  '537 055',
  '537 102',
  '537 103',
  '539 748',
  '540 294',
  '540 295',
  'DB0011',
  'ESD1210',
  'Styroporbox',
  'VW0001',
  'VW0012',
  'VWPAL',
  '0000FAS',
  '0000PAL',
  '0001PAL',
  '0001SCH',
  '0002PAL',
  '0002SCH',
  '0003PAL',
  '0003SCH',
  '0004PAL',
  '0004SCH',
  '0006SCH',
  '0007PAL',
  '0007SCH',
  '0009PAL',
  '0009SCH',
  '111 965',
  '1208PAL',
  '4000PAL',
  'E120 809',
  'E170 608',
  'E180 608',
  'EWPAL',
  'Karton',
  '155240-0119 TYP 3',
  '155240-0161 Typ 1',
  '155240-0162 Typ 4',
  '155240-0163 Typ 2',
  'GT 14488',
  'GT 14491',
  'A E1 6708',
  'sonstiges',
  '0014SCH',
  '581 134',
  '521 441',
  '532 056',
  'A153720',
  '508 060',
  '300 190',
  '006 047',
  'GT 14443',
  '151 744',
  '111 902',
  'A151744',
  'A153718',
  '615 179'
];

$allowedPackMap = [];
foreach ($allowedPacks as $pack) {
  $allowedPackMap[normalizePack($pack)] = $pack;
}

if ($verpackung !== null) {
  $normalizedPack = normalizePack($verpackung);

  if (!isset($allowedPackMap[$normalizedPack])) {
    out([
      'ok' => false,
      'msg' => 'Ungültige Verpackung.',
      'debug' => [
        'received_verpackung' => $verpackung,
        'normalized_verpackung' => $normalizedPack
      ]
    ], 400);
  }

  // Hier wird der offizielle, schön formatierte Wert gespeichert
  $verpackung = $allowedPackMap[$normalizedPack];
}
  if ($reihe === '' || $platz <= 0 || $slot < 0 || $ref === '' || $sach === '') {
    out(['ok' => false, 'msg' => 'Pflichtfelder fehlen (Reihe/Platz/Slot/Ref/Sach).'], 400);
  }

  $date = date('Y-m-d');

  // ------------------------------------------------------------
  // 1) Duplicate Ref verhindern (NUR aktive!)
  // ------------------------------------------------------------
  $chk = $pdo->prepare("
    SELECT id, reihe, platz, slot_index
    FROM lager_slots
    WHERE halle = ? AND zone = ? AND referenznr = ?
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $chk->execute([$halle, $zone, $ref]);
  $hit = $chk->fetch(PDO::FETCH_ASSOC);

  if ($hit) {
    $samePos =
      (string)$hit['reihe'] === $reihe &&
      (int)$hit['platz'] === $platz &&
      (int)$hit['slot_index'] === $slot;

    if (!$samePos) {
      out([
        'ok' => false,
        'error' => 'duplicate_ref',
        'msg' => 'Referenz ist bereits eingelagert.',
        'existing' => [
          'id' => (int)$hit['id'],
          'reihe' => (string)$hit['reihe'],
          'platz' => (int)$hit['platz'],
          'slot_index' => (int)$hit['slot_index'],
        ]
      ], 409);
    }

    // gleiche Position + gleiche Ref -> wir lassen weiterlaufen (Update/Restore/Reuse)
  }

  // ------------------------------------------------------------
  // 2) Zielslot aktiv belegt? -> blocken (nicht überschreiben)
  // ------------------------------------------------------------
  $posActive = $pdo->prepare("
    SELECT id, referenznr
    FROM lager_slots
    WHERE halle=? AND zone=? AND reihe=? AND platz=? AND slot_index=?
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $posActive->execute([$halle, $zone, $reihe, $platz, $slot]);
  $active = $posActive->fetch(PDO::FETCH_ASSOC);

  if ($active) {
    // Wenn das exakt derselbe aktive Datensatz ist (z.B. gleiche Ref am gleichen Platz) -> Update erlauben
    // (hier kann es z.B. um Menge/Lieferschein/Verpackung Korrektur gehen)
    $activeId  = (int)$active['id'];
    $activeRef = (string)($active['referenznr'] ?? '');

    if ($activeRef !== '' && $activeRef === $ref) {
      $sql = "
        UPDATE lager_slots
        SET
          sachnummer = :sach,
          verpackung = :verpackung,
          batch_id = :batch,
          eingelagert_am = :date,
          user_name = :user,
          lieferschein = :ls,
          menge = :menge,
          updated_at = NOW()
        WHERE id = :id
        LIMIT 1
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':sach'       => $sach,
        ':verpackung' => $verpackung,
        ':batch'      => ($batch > 0 ? $batch : null),
        ':date'       => $date,
        ':user'       => $user,
        ':ls'         => ($lieferschein !== '' ? $lieferschein : null),
        ':menge'      => $menge,
        ':id'         => $activeId,
      ]);

      out([
        'ok' => true,
        'id' => $activeId,
        'menge' => $menge,
        'verpackung' => $verpackung,
        'mode' => 'update_same_ref'
      ]);
    }

    // sonst: aktiv belegt -> blocken
    out([
      'ok' => false,
      'error' => 'slot_occupied',
      'msg' => 'Zielslot ist bereits belegt.',
      'existing' => [
        'id' => (int)$active['id'],
        'referenznr' => (string)$activeRef
      ]
    ], 409);
  }

  
  // ------------------------------------------------------------
  // 3) Wenn diese Referenz schon mal gelöscht wurde -> RESTORE (Nachvollziehbarkeit)
  // ------------------------------------------------------------
  $refDeleted = $pdo->prepare("
    SELECT id
    FROM lager_slots
    WHERE halle=? AND zone=? AND referenznr=?
      AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
    LIMIT 1
  ");
  $refDeleted->execute([$halle, $zone, $ref]);
  $deletedRefId = (int)($refDeleted->fetchColumn() ?: 0);

  if ($deletedRefId > 0) {
    $sql = "
      UPDATE lager_slots
      SET
        reihe = :reihe,
        platz = :platz,
        slot_index = :slot,
        referenznr = :ref,
        sachnummer = :sach,
        verpackung = :verpackung,
        batch_id = :batch,
        eingelagert_am = :date,
        user_name = :user,
        lieferschein = :ls,
        menge = :menge,
        deleted_at = NULL,
        deleted_by = NULL,
        updated_at = NOW()
      WHERE id = :id
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':reihe'      => $reihe,
      ':platz'      => $platz,
      ':slot'       => $slot,
      ':ref'        => $ref,
      ':sach'       => $sach,
      ':verpackung' => $verpackung,
      ':batch'      => ($batch > 0 ? $batch : null),
      ':date'       => $date,
      ':user'       => $user,
      ':ls'         => ($lieferschein !== '' ? $lieferschein : null),
      ':menge'      => $menge,
      ':id'         => $deletedRefId,
    ]);

    out([
      'ok' => true,
      'id' => $deletedRefId,
      'menge' => $menge,
      'verpackung' => $verpackung,
      'mode' => 'restore_ref'
    ]);
  }

  // ------------------------------------------------------------
  // 4) Wenn am Zielslot ein gelöschter Datensatz liegt -> wiederverwenden (restore_slot)
  // ------------------------------------------------------------
  $posDeleted = $pdo->prepare("
    SELECT id
    FROM lager_slots
    WHERE halle=? AND zone=? AND reihe=? AND platz=? AND slot_index=?
      AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
    LIMIT 1
  ");
  $posDeleted->execute([$halle, $zone, $reihe, $platz, $slot]);
  $deletedSlotId = (int)($posDeleted->fetchColumn() ?: 0);

  if ($deletedSlotId > 0) {
    $sql = "
      UPDATE lager_slots
      SET
        referenznr = :ref,
        sachnummer = :sach,
        verpackung = :verpackung,
        batch_id = :batch,
        eingelagert_am = :date,
        user_name = :user,
        lieferschein = :ls,
        menge = :menge,
        deleted_at = NULL,
        deleted_by = NULL,
        updated_at = NOW()
      WHERE id = :id
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':ref'        => $ref,
      ':sach'       => $sach,
      ':verpackung' => $verpackung,
      ':batch'      => ($batch > 0 ? $batch : null),
      ':date'       => $date,
      ':user'       => $user,
      ':ls'         => ($lieferschein !== '' ? $lieferschein : null),
      ':menge'      => $menge,
      ':id'         => $deletedSlotId,
    ]);

    out([
      'ok' => true,
      'id' => $deletedSlotId,
      'menge' => $menge,
      'verpackung' => $verpackung,
      'mode' => 'restore_slot'
    ]);
  }

  // ------------------------------------------------------------
  // 5) INSERT (Slot ist frei, Ref ist nicht aktiv doppelt)
  // ------------------------------------------------------------
  $sql = "
    INSERT INTO lager_slots
      (halle, zone, reihe, platz, slot_index, referenznr, sachnummer, verpackung, batch_id, eingelagert_am, user_name, lieferschein, menge)
    VALUES
      (:halle,:zone,:reihe,:platz,:slot,:ref,:sach,:verpackung,:batch,:date,:user,:ls,:menge)
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':halle'      => $halle,
    ':zone'       => $zone,
    ':reihe'      => $reihe,
    ':platz'      => $platz,
    ':slot'       => $slot,
    ':ref'        => $ref,
    ':sach'       => $sach,
    ':verpackung' => $verpackung,
    ':batch'      => ($batch > 0 ? $batch : null),
    ':date'       => $date,
    ':user'       => $user,
    ':ls'         => ($lieferschein !== '' ? $lieferschein : null),
    ':menge'      => $menge,
  ]);

  $id = (int)$pdo->lastInsertId();
  out([
    'ok' => true,
    'id' => $id,
    'menge' => $menge,
    'verpackung' => $verpackung,
    'mode' => 'insert'
  ]);

} catch (PDOException $e) {
  // Duplicate Key / Constraint
  if ($e->getCode() === '23000') {
    $halle = trim((string)($_POST['halle'] ?? 'H3'));
    $zone  = trim((string)($_POST['zone']  ?? 'W1'));
    $ref   = trim((string)($_POST['referenznr'] ?? ''));

    if ($ref !== '') {
      $q = $pdo->prepare("
        SELECT id, reihe, platz, slot_index
        FROM lager_slots
        WHERE halle=? AND zone=? AND referenznr=?
          AND deleted_at IS NULL
        LIMIT 1
      ");
      $q->execute([$halle, $zone, $ref]);
      $hit = $q->fetch(PDO::FETCH_ASSOC);

      if ($hit) {
        out([
          'ok' => false,
          'error' => 'duplicate_ref',
          'msg' => 'Referenz ist bereits eingelagert.',
          'existing' => [
            'id' => (int)$hit['id'],
            'reihe' => (string)$hit['reihe'],
            'platz' => (int)$hit['platz'],
            'slot_index' => (int)$hit['slot_index'],
          ]
        ], 409);
      }
    }
  }

  out(['ok' => false, 'msg' => $e->getMessage()], 500);

} catch (Throwable $e) {
  out(['ok' => false, 'msg' => $e->getMessage()], 500);
}
