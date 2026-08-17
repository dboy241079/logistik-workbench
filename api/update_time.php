<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

try {
  $veh_id = trim((string)($_POST['veh_id'] ?? ''));
  $date   = trim((string)($_POST['date'] ?? ''));
  $tour   = (int)($_POST['tour'] ?? 0);
  $field  = trim((string)($_POST['field'] ?? ''));
  $value  = trim((string)($_POST['value'] ?? ''));

  if ($veh_id === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || $tour < 1 || $field === '') {
    api_err('Fehlende oder ungültige Parameter', 400);
  }

  $allowed = [
    'workStart','arriveWU','departWU',
    'arriveH','departH','arriveH2','departH2',
    'hannoverHall','hannoverHall2',
    'pauseStart','pauseEnd','workEnd'
  ];

  if (!in_array($field, $allowed, true)) {
    api_err('Ungültiges Feld: ' . $field, 400);
  }

  // Zeile sicherstellen. Dadurch funktioniert auch eine manuelle Eingabe,
  // wenn für die Tour vorher noch kein Stempel vorhanden war.
  $ins = $pdo->prepare(
    'INSERT IGNORE INTO driver_stamps (veh_id, date, tour) VALUES (:veh_id, :date, :tour)'
  );
  $ins->execute([
    ':veh_id' => $veh_id,
    ':date'   => $date,
    ':tour'   => $tour,
  ]);

  $sql = "UPDATE driver_stamps
          SET `$field` = :value, updated_at = NOW()
          WHERE veh_id = :veh_id AND date = :date AND tour = :tour";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':veh_id' => $veh_id,
    ':date'   => $date,
    ':tour'   => $tour,
    ':value'  => ($value === '' ? null : $value),
  ]);

  api_ok([
    'msg' => 'Gespeichert',
    'veh_id' => $veh_id,
    'date' => $date,
    'tour' => $tour,
    'field' => $field,
    'value' => $value,
  ]);
} catch (Throwable $e) {
  api_err($e->getMessage(), 500);
}
