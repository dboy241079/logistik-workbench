<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);

require dirname(__DIR__) . '/api/_db.php';

$run = (int)($_GET['run'] ?? 0);

// Konfiguration Halle 4
$halle = 'H4';
$zone  = 'W1';
$rowsMin = 150;
$rowsMax = 300;
$placesPerRow = 100;
$slotsPerPlace = 4;

$total = ($rowsMax - $rowsMin + 1) * $placesPerRow * $slotsPerPlace;

// Sicherheits-Check: wenn schon was da ist -> abbrechen (nix überschreiben!)
try {
  $check = $pdo->prepare("
    SELECT COUNT(*) 
    FROM lager_slots
    WHERE halle=:h AND zone=:z
      AND CAST(reihe AS UNSIGNED) BETWEEN :rmin AND :rmax
  ");
  $check->execute([
    ':h'=>$halle, ':z'=>$zone,
    ':rmin'=>$rowsMin, ':rmax'=>$rowsMax
  ]);
  $existing = (int)$check->fetchColumn();

  if ($existing > 0) {
    echo json_encode([
      'ok' => false,
      'error' => 'Abbruch: Für H4 existieren bereits Datensätze (nichts geändert).',
      'existing_records' => $existing
    ]);
    exit;
  }

  if ($run !== 1) {
    echo json_encode([
      'ok'=>true,
      'dry_run'=>true,
      'hint'=>'Zum Ausführen: ?run=1',
      'will_create'=>[
        'halle'=>$halle, 'zone'=>$zone,
        'rows'=>"$rowsMin-$rowsMax",
        'places_per_row'=>$placesPerRow,
        'slots_per_place'=>$slotsPerPlace,
        'total_records'=>$total
      ]
    ]);
    exit;
  }

  $pdo->beginTransaction();

  // Chunk Insert (damit es nicht zu groß wird)
  $chunkSize = 1000;
  $values = [];
  $params = [];
  $i = 0;

  $sqlBase = "
    INSERT INTO lager_slots
      (halle, zone, reihe, platz, slot_index, referenznr, sachnummer, eingelagert_am, menge)
    VALUES
  ";

  for ($r = $rowsMin; $r <= $rowsMax; $r++) {
    for ($p = 1; $p <= $placesPerRow; $p++) {
      for ($s = 1; $s <= $slotsPerPlace; $s++) {
        $i++;
        $values[] = "(?,?,?,?,?,?,?,?,?)";
        array_push($params,
          $halle, $zone, (string)$r, $p, $s,
          '', '', '1970-01-01', 0
        );

        if (count($values) >= $chunkSize) {
          $stmt = $pdo->prepare($sqlBase . implode(',', $values));
          $stmt->execute($params);
          $values = [];
          $params = [];
        }
      }
    }
  }

  // Rest
  if (!empty($values)) {
    $stmt = $pdo->prepare($sqlBase . implode(',', $values));
    $stmt->execute($params);
  }

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'created'=>$total,
    'halle'=>$halle,
    'zone'=>$zone,
    'rows'=>"$rowsMin-$rowsMax"
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
