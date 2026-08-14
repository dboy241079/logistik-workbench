<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/driver_device_auth.php';

driverDeviceEnsureSchema($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$code = preg_replace('/\D+/', '', (string)($input['code'] ?? '')) ?? '';
$deviceName = trim((string)($input['device_name'] ?? ''));

if (!preg_match('/^\d{6}$/', $code)) {
    api_err('Bitte einen gültigen 6-stelligen Einrichtungscode eingeben.', 400, [
        'code' => 'invalid_pair_code_format',
    ]);
}

if (mb_strlen($deviceName) > 160) {
    $deviceName = mb_substr($deviceName, 0, 160);
}

$stmt = $pdo->query("SELECT id, veh_id, code_hash, expires_at
                     FROM driver_pair_codes
                     WHERE used_at IS NULL
                       AND expires_at >= NOW()
                     ORDER BY id DESC
                     LIMIT 50");
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$matched = null;
foreach ($candidates as $candidate) {
    if (password_verify($code, (string)$candidate['code_hash'])) {
        $matched = $candidate;
        break;
    }
}

if (!$matched) {
    usleep(250000);
    api_err('Einrichtungscode ist falsch oder abgelaufen.', 403, [
        'code' => 'pair_code_invalid',
    ]);
}

$vehId = (string)$matched['veh_id'];
$vehicleMap = driverDeviceVehicleMap();
if (!isset($vehicleMap[$vehId])) {
    api_err('Das zugeordnete Fahrzeug ist nicht mehr vorhanden.', 409, [
        'code' => 'vehicle_missing',
    ]);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $pdo->beginTransaction();

    $use = $pdo->prepare("UPDATE driver_pair_codes
                         SET used_at = NOW()
                         WHERE id = :id
                           AND used_at IS NULL
                           AND expires_at >= NOW()");
    $use->execute([':id' => (int)$matched['id']]);

    if ($use->rowCount() !== 1) {
        throw new RuntimeException('Einrichtungscode wurde bereits verwendet.');
    }

    // Pro Fahrzeug genau ein aktives Fahrer-App-Gerät.
    $revoke = $pdo->prepare("UPDATE driver_devices
                            SET active = 0, revoked_at = NOW()
                            WHERE veh_id = :veh AND active = 1");
    $revoke->execute([':veh' => $vehId]);

    $ins = $pdo->prepare("INSERT INTO driver_devices
        (veh_id, device_token_hash, device_name, user_agent, paired_at, last_seen, active)
        VALUES (:veh, :hash, :name, :ua, NOW(), NOW(), 1)");
    $ins->execute([
        ':veh' => $vehId,
        ':hash' => $tokenHash,
        ':name' => ($deviceName !== '' ? $deviceName : null),
        ':ua' => ($userAgent !== '' ? $userAgent : null),
    ]);

    $deviceId = (int)$pdo->lastInsertId();
    $pdo->commit();

    driverDeviceSetCookie($token);

    api_ok([
        'paired' => true,
        'device' => [
            'id' => $deviceId,
            'name' => $deviceName,
        ],
        'vehicle' => driverDeviceVehicleInfo($vehId),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_err($e->getMessage(), 409, [
        'code' => 'pairing_failed',
    ]);
}
