<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/_db.php';
require __DIR__ . '/../inc/session.php';

function out(array $p, int $code = 200): void {
  http_response_code($code);
  echo json_encode($p, JSON_UNESCAPED_UNICODE);
  exit;
}

function readJson(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw ?: '', true);
  return is_array($j) ? $j : [];
}

/**
 * Ermittelt die Lagergruppe (LG) einer Reihe anhand der Sachnummern.
 * Fallback:
 * - wenn nichts gefunden -> $fallbackLg (aus QR/Request)
 * - wenn mehrere gefunden -> wenn $fallbackLg in Trefferliste -> $fallbackLg, sonst "MIX"
 */
function detectLgForRow(PDO $pdo, string $halle, int $reihe, string $fallbackLg): string {
  $sql = "
    SELECT DISTINCT sn.lagergruppe
    FROM lager_slots s
    JOIN sachnummern sn ON sn.sachnummer = s.sachnummer
    WHERE s.halle = :halle
      AND s.reihe = :reihe
      AND s.deleted_at IS NULL
      AND s.sachnummer IS NOT NULL AND s.sachnummer <> ''
      AND sn.lagergruppe IS NOT NULL AND sn.lagergruppe <> ''
    LIMIT 5
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':halle' => $halle, ':reihe' => $reihe]);

  $lgs = [];
  while (($lg = $st->fetchColumn()) !== false) {
    $lg = trim((string)$lg);
    if ($lg !== '') $lgs[] = $lg;
  }
  $lgs = array_values(array_unique($lgs));

  $fallbackLg = trim($fallbackLg);
  if ($fallbackLg === '') $fallbackLg = 'UNBEKANNT';

  if (count($lgs) === 1) return $lgs[0];
  if (count($lgs) === 0) return $fallbackLg;

  // mehrere LGs
  if (in_array($fallbackLg, $lgs, true)) return $fallbackLg;
  return 'MIX';
}

/**
 * Sollmenge = SUM(menge) aller Slots in der Reihe, deren Sachnummer zur Lagergruppe gehört.
 * (Wenn menge NULL ist -> 0)
 */
function calcSoll(PDO $pdo, string $halle, int $reihe, string $lg): int {
  $sql = "
    SELECT COUNT(*) AS soll
    FROM lager_slots s
    JOIN sachnummern sn ON sn.sachnummer = s.sachnummer
    WHERE s.halle = :halle
      AND s.reihe = :reihe
      AND s.deleted_at IS NULL
      AND s.sachnummer IS NOT NULL AND s.sachnummer <> ''
      AND sn.lagergruppe = :lg
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':halle'=>$halle, ':reihe'=>$reihe, ':lg'=>$lg]);
  return (int)$st->fetchColumn();
}

$sessionUser = (string)($_SESSION['username'] ?? 'unknown');

// actor kann aus QR-Seite kommen (display_name) – wenn leer -> Session-User
$actor = trim((string)($in['actor'] ?? ''));



try {
  $in = array_merge($_GET, $_POST, readJson());


 $action = strtoupper(trim((string)($in['action'] ?? 'GET_ROW')));
$halle  = trim((string)($in['halle'] ?? ''));
$reihe  = (int)($in['reihe'] ?? 0);
$menge  = array_key_exists('menge', $in) ? (int)$in['menge'] : null;

// Zone aus QR / Request (Fallback W1)
$zoneFromReq = trim((string)($in['zone'] ?? ''));
$zone = ($zoneFromReq !== '') ? $zoneFromReq : 'W1';

// Für LIST: Zone bleibt wie übergeben (Admin will gezielt LG sehen)
if ($action !== 'LIST') {
  if ($reihe <= 0) out(['ok'=>false,'msg'=>'reihe fehlt.'], 400);

  // Zone automatisch aus DB erkennen (LG anhand Sachnummern in der Reihe)
  $det = detectLgForRow($pdo, $halle, $reihe, $zone);


  // Wenn erkannt -> nehmen. Wenn UNBEKANNT -> QR-Zone benutzen.
  if ($det !== '' && $det !== 'UNBEKANNT') {
    $zone = $det;
  }
}

if ($halle === '' || $zone === '') {
  out(['ok'=>false,'msg'=>'Parameter fehlen (halle/zone).'], 400);
}


  // Alle anderen Actions brauchen eine Reihe
  if ($halle === '' || $reihe <= 0) out(['ok'=>false,'msg'=>'Parameter fehlen (halle/reihe).'], 400);

  // ✅ Lagergruppe automatisch erkennen (und NICHT stumpf W1 übernehmen)
  $lgDetected = detectLgForRow($pdo, $halle, $reihe, $zone);


  // Sollmenge passend zur erkannten LG
  $soll = calcSoll($pdo, $halle, $reihe, $lgDetected);

  // Row sicher anlegen / updaten
  // Wichtig: zone/LG wird hier bewusst aktualisiert (falls früher mal W1 drin stand)
  $up = $pdo->prepare("
    INSERT INTO inventur_rows (halle, zone, reihe, soll_menge)
    VALUES (:h,:z,:r,:s)
    ON DUPLICATE KEY UPDATE
      zone      = VALUES(zone),
      soll_menge= VALUES(soll_menge)
  ");
  $up->execute([':h'=>$halle, ':z'=>$lgDetected, ':r'=>$reihe, ':s'=>$soll]);

  // Row laden (immer mit erkannter LG)
  $stGet = $pdo->prepare("SELECT * FROM inventur_rows WHERE halle=:h AND zone=:z AND reihe=:r LIMIT 1");
  $stGet->execute([':h'=>$halle, ':z'=>$lgDetected, ':r'=>$reihe]);
  $row = $stGet->fetch(PDO::FETCH_ASSOC);
  if (!$row) out(['ok'=>false,'msg'=>'Row nicht gefunden.'], 404);

  // Status neu berechnen
  $hasCount = ($row['count_menge'] !== null && $row['count_menge'] !== '');
  $hasCheck = ($row['check_menge'] !== null && $row['check_menge'] !== '');

  $status = 'offen';
  if ($hasCount && !$hasCheck) $status = 'gezaehlt';
  if ($hasCheck) $status = 'geprueft';

  if ($hasCount && $hasCheck) {
    if ((int)$row['count_menge'] !== (int)$row['check_menge']) {
      $status = 'abweichung_mensch';
    } elseif ((int)$row['count_menge'] !== (int)$row['soll_menge']) {
      $status = 'abweichung_system';
    } else {
      $status = 'ok';
    }
  }

  // GET_ROW
  if ($action === 'GET_ROW') {
    $pdo->prepare("UPDATE inventur_rows SET status=:s WHERE id=:id LIMIT 1")
        ->execute([':s'=>$status, ':id'=>(int)$row['id']]);
    $row['status'] = $status;
    out(['ok'=>true,'row'=>$row, 'zone_detected'=>$lgDetected]);
  }

  // COUNT / CHECK speichern
  if ($action === 'COUNT' || $action === 'CHECK') {
    if ($menge === null || $menge < 0) out(['ok'=>false,'msg'=>'Ungültige menge.'], 400);

    if ($action === 'COUNT') {
      $st = $pdo->prepare("
        UPDATE inventur_rows
        SET count_menge=:m, count_user=:u, count_time=NOW()
        WHERE id=:id
        LIMIT 1
      ");
      $st->execute([':m'=>$menge,':u'=>$actor,':h'=>$halle,':z'=>$zone,':r'=>$reihe]);

    } else {
      $st = $pdo->prepare("
        UPDATE inventur_rows
        SET check_menge=:m, check_user=:u, check_time=NOW()
        WHERE id=:id
        LIMIT 1
      ");
      $st->execute([':m'=>$menge,':u'=>$actor,':h'=>$halle,':z'=>$zone,':r'=>$reihe]);

    }

    // neu laden + Status
    $stGet->execute([':h'=>$halle, ':z'=>$lgDetected, ':r'=>$reihe]);
    $row = $stGet->fetch(PDO::FETCH_ASSOC) ?: $row;

    $hasCount = ($row['count_menge'] !== null && $row['count_menge'] !== '');
    $hasCheck = ($row['check_menge'] !== null && $row['check_menge'] !== '');

    $status = 'offen';
    if ($hasCount && !$hasCheck) $status = 'gezaehlt';
    if ($hasCheck) $status = 'geprueft';

    if ($hasCount && $hasCheck) {
      if ((int)$row['count_menge'] !== (int)$row['check_menge']) $status = 'abweichung_mensch';
      elseif ((int)$row['count_menge'] !== (int)$row['soll_menge']) $status = 'abweichung_system';
      else $status = 'ok';
    }

    $pdo->prepare("UPDATE inventur_rows SET status=:s WHERE id=:id LIMIT 1")
        ->execute([':s'=>$status, ':id'=>(int)$row['id']]);

    $row['status'] = $status;
    out(['ok'=>true,'row'=>$row,'msg'=>"Gespeichert. LG: {$lgDetected} · Status: {$status}"]);
  }

  // RESET
  if ($action === 'RESET') {
    $pdo->prepare("
      UPDATE inventur_rows
      SET count_menge=NULL,count_user=NULL,count_time=NULL,
          check_menge=NULL,check_user=NULL,check_time=NULL,
          status='offen'
      WHERE id=:id
      LIMIT 1
    ")->execute([':id'=>(int)$row['id']]);

    out(['ok'=>true,'msg'=>'Reihe zurückgesetzt.','zone_detected'=>$lgDetected]);
  }

  out(['ok'=>false,'msg'=>'Unknown action'], 400);

} catch (Throwable $e) {
  out(['ok'=>false,'msg'=>'Serverfehler','detail'=>$e->getMessage()], 500);
}
