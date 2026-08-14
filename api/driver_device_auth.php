<?php
declare(strict_types=1);

/**
 * Gemeinsame Geräte-Kopplung für den Fahrer-Kiosk.
 * Voraussetzung: api/_bootstrap.php wurde bereits eingebunden und $pdo existiert.
 */

function driverDeviceEnsureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS driver_devices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        veh_id VARCHAR(64) NOT NULL,
        device_token_hash CHAR(64) NOT NULL,
        device_name VARCHAR(160) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        paired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT DEFAULT NULL,
        revoked_at DATETIME DEFAULT NULL,
        revoked_by BIGINT DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_driver_device_token_hash (device_token_hash),
        KEY idx_driver_devices_vehicle_active (veh_id, active),
        KEY idx_driver_devices_last_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS driver_pair_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        veh_id VARCHAR(64) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_by BIGINT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_driver_pair_codes_vehicle (veh_id),
        KEY idx_driver_pair_codes_expiry (expires_at, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function driverDeviceIsWorkbenchSession(): bool
{
    return !empty($_SESSION['user_id']);
}

function driverDeviceReadToken(): string
{
    $token = trim((string)($_COOKIE['driver_device_token'] ?? ''));

    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_DRIVER_DEVICE_TOKEN'] ?? ''));
    }

    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return '';
    }

    return strtolower($token);
}

function driverDeviceVehicleMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    $cfgPath = dirname(__DIR__) . '/data/veh_cfg.json';
    if (!is_file($cfgPath)) {
        return $map;
    }

    $cfg = json_decode((string)file_get_contents($cfgPath), true);
    $vehicles = is_array($cfg['vehicles'] ?? null) ? $cfg['vehicles'] : [];

    foreach ($vehicles as $vehicle) {
        $id = trim((string)($vehicle['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'id' => $id,
            'title' => trim((string)($vehicle['title'] ?? $id)),
            'plate' => trim((string)($vehicle['plate'] ?? '')),
            'driver' => trim((string)($vehicle['driver'] ?? '')),
        ];
    }

    return $map;
}

function driverDeviceVehicleInfo(string $vehId): array
{
    $map = driverDeviceVehicleMap();
    return $map[$vehId] ?? [
        'id' => $vehId,
        'title' => $vehId,
        'plate' => '',
        'driver' => '',
    ];
}

function driverDeviceLookup(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    driverDeviceEnsureSchema($pdo);
    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare("SELECT * FROM driver_devices WHERE device_token_hash = :h AND active = 1 LIMIT 1");
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function driverDeviceTouch(PDO $pdo, int $deviceId): void
{
    $stmt = $pdo->prepare("UPDATE driver_devices SET last_seen = NOW() WHERE id = :id AND active = 1");
    $stmt->execute([':id' => $deviceId]);
}

function driverDeviceAuthorizeVehicle(PDO $pdo, string $requestedVehId): array
{
    // Angemeldete Workbench-Benutzer behalten die bisherige freie Fahrzeugwahl.
    if (driverDeviceIsWorkbenchSession()) {
        return [
            'mode' => 'workbench',
            'veh_id' => $requestedVehId,
            'device_id' => null,
        ];
    }

    $token = driverDeviceReadToken();
    if ($token === '') {
        api_err('Fahrer-App ist noch keinem Fahrzeug zugeordnet.', 401, [
            'code' => 'device_not_paired',
        ]);
    }

    $device = driverDeviceLookup($pdo, $token);
    if (!$device) {
        api_err('Diese Gerätefreigabe ist nicht mehr gültig. Bitte neu koppeln.', 403, [
            'code' => 'device_not_authorized',
        ]);
    }

    $allowedVehId = (string)$device['veh_id'];
    if (!hash_equals($allowedVehId, $requestedVehId)) {
        $vehicle = driverDeviceVehicleInfo($allowedVehId);
        api_err('Dieses Gerät ist ausschließlich für ' . ($vehicle['plate'] ?: $vehicle['title']) . ' freigegeben.', 403, [
            'code' => 'vehicle_not_allowed',
            'veh_id' => $allowedVehId,
        ]);
    }

    driverDeviceTouch($pdo, (int)$device['id']);

    return [
        'mode' => 'device',
        'veh_id' => $allowedVehId,
        'device_id' => (int)$device['id'],
    ];
}

function driverDeviceSetCookie(string $token): void
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie('driver_device_token', $token, [
        'expires' => time() + (86400 * 365 * 3),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
