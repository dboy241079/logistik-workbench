<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/driver_device_auth.php';

$role = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !in_array($role, ['admin', 'standortleiter'], true)) {
    http_response_code(403);
    exit('Keine Berechtigung.');
}

driverDeviceEnsureSchema($pdo);

$message = '';
$messageType = 'success';
$newPairCode = '';
$newPairVehicle = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $vehId = trim((string)($_POST['veh_id'] ?? ''));
    $vehicles = driverDeviceVehicleMap();

    try {
        if ($vehId === '' || !isset($vehicles[$vehId])) {
            throw new RuntimeException('Fahrzeug wurde nicht gefunden.');
        }

        if ($action === 'generate_code') {
            $code = (string)random_int(100000, 999999);
            $hash = password_hash($code, PASSWORD_DEFAULT);

            $pdo->beginTransaction();
            $expireOld = $pdo->prepare("UPDATE driver_pair_codes
                                       SET used_at = NOW()
                                       WHERE veh_id = :veh
                                         AND used_at IS NULL");
            $expireOld->execute([':veh' => $vehId]);

            $stmt = $pdo->prepare("INSERT INTO driver_pair_codes
                (veh_id, code_hash, expires_at, created_by, created_at)
                VALUES (:veh, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), :uid, NOW())");
            $stmt->execute([
                ':veh' => $vehId,
                ':hash' => $hash,
                ':uid' => $userId,
            ]);
            $pdo->commit();

            $newPairCode = $code;
            $newPairVehicle = $vehicles[$vehId]['plate'] ?: $vehicles[$vehId]['title'];
            $message = 'Neuer Einrichtungscode wurde erzeugt und ist 10 Minuten gültig.';
        } elseif ($action === 'revoke_device') {
            $stmt = $pdo->prepare("UPDATE driver_devices
                                   SET active = 0,
                                       revoked_at = NOW(),
                                       revoked_by = :uid
                                   WHERE veh_id = :veh AND active = 1");
            $stmt->execute([
                ':uid' => $userId,
                ':veh' => $vehId,
            ]);
            $message = $stmt->rowCount() > 0
                ? 'Gerätefreigabe wurde aufgehoben.'
                : 'Für dieses Fahrzeug war kein aktives Gerät vorhanden.';
        } elseif ($action === 'cancel_code') {
            $stmt = $pdo->prepare("UPDATE driver_pair_codes
                                   SET used_at = NOW()
                                   WHERE veh_id = :veh
                                     AND used_at IS NULL
                                     AND expires_at >= NOW()");
            $stmt->execute([':veh' => $vehId]);
            $message = 'Offener Einrichtungscode wurde ungültig gemacht.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$vehicles = driverDeviceVehicleMap();

$devicesStmt = $pdo->query("SELECT * FROM driver_devices ORDER BY active DESC, COALESCE(last_seen, paired_at) DESC");
$allDevices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
$activeByVehicle = [];
foreach ($allDevices as $device) {
    if ((int)$device['active'] === 1 && !isset($activeByVehicle[$device['veh_id']])) {
        $activeByVehicle[$device['veh_id']] = $device;
    }
}

$codesStmt = $pdo->query("SELECT veh_id, MAX(expires_at) AS expires_at
                          FROM driver_pair_codes
                          WHERE used_at IS NULL AND expires_at >= NOW()
                          GROUP BY veh_id");
$openCodes = [];
foreach ($codesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $openCodes[(string)$row['veh_id']] = (string)$row['expires_at'];
}

function ddh(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ddTime(?string $value): string {
    if (!$value) return '–';
    $ts = strtotime($value);
    return $ts ? date('d.m.Y H:i', $ts) : '–';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fahrer-App Geräte</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f7f8fa; }
        .device-code { font-size:2rem; letter-spacing:.35rem; font-weight:800; font-variant-numeric:tabular-nums; }
        .vehicle-name { font-weight:700; }
        .status-dot { width:.65rem; height:.65rem; border-radius:50%; display:inline-block; }
    </style>
</head>
<body>
<div class="container-fluid p-3 p-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-phone-fill-lock me-2 text-primary"></i>Fahrer-App Geräte</h1>
            <div class="text-muted small">Play-Store-App sicher einem Fahrzeug zuordnen.</div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/admin.php?embed=1&section=vehicles">
            <i class="bi bi-arrow-left me-1"></i>Adminbereich
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?=ddh($messageType)?> py-2"><?=ddh($message)?></div>
    <?php endif; ?>

    <?php if ($newPairCode !== ''): ?>
        <div class="card border-primary shadow-sm mb-3">
            <div class="card-body text-center py-4">
                <div class="text-muted small mb-1">Einrichtungscode für</div>
                <div class="fw-semibold mb-2"><?=ddh($newPairVehicle)?></div>
                <div class="device-code text-primary"><?=ddh($newPairCode)?></div>
                <div class="small text-danger mt-2"><i class="bi bi-clock me-1"></i>10 Minuten gültig · nur einmal verwendbar</div>
                <div class="small text-muted mt-2">Diesen Code gibt der Fahrer einmalig beim ersten Start der App ein.</div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Fahrzeug</th>
                        <th>Gerät</th>
                        <th>Status</th>
                        <th>Gekoppelt</th>
                        <th>Letzte Nutzung</th>
                        <th>Einrichtungscode</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vehicles as $vehId => $vehicle):
                        $device = $activeByVehicle[$vehId] ?? null;
                        $openUntil = $openCodes[$vehId] ?? '';
                        $label = $vehicle['plate'] ?: $vehicle['title'];
                    ?>
                        <tr>
                            <td>
                                <div class="vehicle-name"><?=ddh($label)?></div>
                                <div class="small text-muted"><?=ddh($vehId)?></div>
                            </td>
                            <td>
                                <?php if ($device): ?>
                                    <div><?=ddh($device['device_name'] ?: 'Android-Gerät')?></div>
                                    <div class="small text-muted">Gerät #<?= (int)$device['id'] ?></div>
                                <?php else: ?>
                                    <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($device): ?>
                                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>gekoppelt</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">nicht gekoppelt</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $device ? ddTime($device['paired_at']) : '–' ?></td>
                            <td><?= $device ? ddTime($device['last_seen']) : '–' ?></td>
                            <td>
                                <?php if ($openUntil): ?>
                                    <span class="badge text-bg-warning">offen bis <?=ddh(date('H:i', strtotime($openUntil)))?> Uhr</span>
                                <?php else: ?>
                                    <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="generate_code">
                                    <input type="hidden" name="veh_id" value="<?=ddh($vehId)?>">
                                    <button class="btn btn-primary btn-sm" type="submit">
                                        <i class="bi bi-key me-1"></i><?= $device ? 'Neu koppeln' : 'Koppeln' ?>
                                    </button>
                                </form>
                                <?php if ($openUntil): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="cancel_code">
                                        <input type="hidden" name="veh_id" value="<?=ddh($vehId)?>">
                                        <button class="btn btn-outline-secondary btn-sm" type="submit" title="Einrichtungscode ungültig machen">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($device): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Gerätefreigabe für <?=ddh($label)?> wirklich aufheben?');">
                                        <input type="hidden" name="action" value="revoke_device">
                                        <input type="hidden" name="veh_id" value="<?=ddh($vehId)?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">
                                            <i class="bi bi-lock-fill me-1"></i>Sperren
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-info mt-3 mb-0 small">
        <strong>Sicherheitsprinzip:</strong> Die Fahrzeugzuordnung wird serverseitig geprüft. Auch eine manipulierte App kann nicht auf ein anderes Fahrzeug stempeln.
    </div>
</div>
</body>
</html>
