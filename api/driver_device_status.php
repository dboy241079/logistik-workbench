<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/driver_device_auth.php';

driverDeviceEnsureSchema($pdo);

$token = driverDeviceReadToken();
if ($token === '') {
    api_ok([
        'paired' => false,
        'reason' => 'missing_token',
    ]);
}

$device = driverDeviceLookup($pdo, $token);
if (!$device) {
    api_ok([
        'paired' => false,
        'reason' => 'invalid_or_revoked',
    ]);
}

driverDeviceTouch($pdo, (int)$device['id']);
$vehicle = driverDeviceVehicleInfo((string)$device['veh_id']);

api_ok([
    'paired' => true,
    'device' => [
        'id' => (int)$device['id'],
        'name' => (string)($device['device_name'] ?? ''),
        'paired_at' => (string)($device['paired_at'] ?? ''),
        'last_seen' => (string)($device['last_seen'] ?? ''),
    ],
    'vehicle' => $vehicle,
]);
