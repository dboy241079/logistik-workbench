<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_db.php'; // deine DB-Verbindung

try {
  $veh_id = $_POST['veh_id'] ?? '';
  $date = $_POST['date'] ?? '';
  $tour = $_POST['tour'] ?? '';
  $field = $_POST['field'] ?? '';
  $value = trim($_POST['value'] ?? '');

  if (!$veh_id || !$date || !$tour || !$field) {
    throw new Exception('Fehlende Parameter');
  }

  // Whitelist erlaubter Felder
  $allowed = [
    'workStart','arriveWU','departWU',
    'arriveH','departH','arriveH2','departH2',
    'hannoverHall','hannoverHall2',
    'pauseStart','pauseEnd','workEnd'
  ];

  if (!in_array($field, $allowed, true)) {
    throw new Exception('Ungültiges Feld: ' . $field);
  }

  // Existiert der Datensatz?
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM driver_stamps WHERE veh_id=? AND date=? AND tour=?");
  $stmt->execute([$veh_id, $date, $tour]);
  $exists = $stmt->fetchColumn() > 0;

  if ($exists) {
    // Update bestehender Eintrag
    $sql = "UPDATE driver_stamps 
            SET `$field` = :value, updated_at = NOW() 
            WHERE veh_id = :veh_id AND date = :date AND tour = :tour";
  } else {
    // Neu anlegen (wenn z. B. Fahrer vergessen hat zu starten)
    $sql = "INSERT INTO driver_stamps (veh_id, date, tour, `$field`, created_at, updated_at)
            VALUES (:veh_id, :date, :tour, :value, NOW(), NOW())";
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    'veh_id' => $veh_id,
    'date' => $date,
    'tour' => $tour,
    'value' => $value
  ]);

  echo json_encode(['ok' => true, 'msg' => 'Gespeichert']);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
