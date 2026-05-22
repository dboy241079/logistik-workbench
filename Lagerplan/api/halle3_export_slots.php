<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

function fail(string $msg, int $code = 500): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$halle = $_GET['halle'] ?? 'H3';
$zone  = $_GET['zone']  ?? 'W1';

// ✅ DEIN DB-Include:
$dbPath = $_SERVER['DOCUMENT_ROOT'] . '/api/_db.php';
if (!is_file($dbPath)) fail('DB-Datei nicht gefunden: /api/_db.php');
require_once $dbPath;

if (!isset($pdo) || !($pdo instanceof PDO)) fail('PDO Verbindung fehlt in _db.php');

try {
  $sql = "
    SELECT
      id, halle, zone, reihe, platz, slot_index,
      referenznr, sachnummer, lieferschein, batch_id,
      eingelagert_am, user_name, menge, karton_soll
    FROM lager_slots
    WHERE deleted_at IS NULL
      AND halle = :halle
      AND zone  = :zone
    ORDER BY CAST(reihe AS UNSIGNED), platz, slot_index, id
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':halle' => $halle, ':zone' => $zone]);
  $rows = $stmt->fetchAll();

  echo json_encode(['ok' => true, 'count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  fail('DB Fehler beim Lesen der Slots.');
}
