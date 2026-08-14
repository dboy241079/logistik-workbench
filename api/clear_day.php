<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require_once __DIR__ . '/driver_device_auth.php';
require_once __DIR__ . '/realtime_outbox.php';

try {
  $j = json_decode(file_get_contents('php://input'), true) ?: [];
  $vehId = (string)($j['veh_id'] ?? '');
  $date  = (string)($j['date']   ?? '');
  if ($vehId==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
    throw new RuntimeException('Bad params');
  }

  driverDeviceAuthorizeVehicle($pdo, $vehId);

  $stmt = $pdo->prepare("DELETE FROM driver_stamps WHERE veh_id=:v AND date=:d");
  $stmt->execute([':v'=>$vehId, ':d'=>$date]);

  realtimeEmit($pdo, 'drivers:update', [
    'veh_id' => $vehId,
    'date' => $date,
    'tour' => null,
    'fields' => [],
    'action' => 'clear_day',
    'source' => 'kiosk',
  ], null, 'driver_day', $vehId . ':' . $date);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
