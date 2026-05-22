<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/inc/session.php';
require dirname(__DIR__, 2) . '/api/_db.php';
require __DIR__ . '/_lager_config.php';

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'standortleiter'], true)) {
    lager_cfg_json_response([
        'ok' => false,
        'msg' => 'Keine Berechtigung.'
    ], 403);
}

$halle = strtoupper(trim((string)($_POST['halle'] ?? 'H3')));
$zone  = strtoupper(trim((string)($_POST['zone'] ?? 'W1')));

$row    = max(1, (int)($_POST['row'] ?? 0));
$places = max(1, (int)($_POST['places'] ?? 0));
$slots  = max(1, (int)($_POST['slots_per_place'] ?? 0));

try {
    $cfg = lager_cfg_get($halle, $zone);

    if ($row < (int)$cfg['row_from'] || $row > (int)$cfg['row_to']) {
        throw new RuntimeException("Reihe {$row} liegt außerhalb des gültigen Bereichs {$cfg['row_from']}–{$cfg['row_to']}.");
    }

    lager_cfg_assert_override_safe($pdo, $halle, $zone, $row, $places, $slots);

    $overrides = is_array($cfg['row_overrides'] ?? null) ? $cfg['row_overrides'] : [];
    $overrides[(string)$row] = [
        'places' => $places,
        'slots_per_place' => $slots,
    ];

    $cfg = lager_cfg_put($halle, $zone, [
        'halle' => $halle,
        'zone'  => $zone,
        'row_from' => $cfg['row_from'],
        'row_to'   => $cfg['row_to'],
        'default_places_per_row' => $cfg['default_places_per_row'],
        'default_slots_per_place' => $cfg['default_slots_per_place'],
        'row_overrides' => $overrides,
        'updated_at' => date('c'),
        'updated_by' => ($_SESSION['username'] ?? $_SESSION['display_name'] ?? 'system'),
    ]);

    lager_cfg_json_response([
        'ok' => true,
        'msg' => "Override für Reihe {$row} gespeichert.",
        'row_overrides' => $cfg['row_overrides'],
        'highest_used_row' => lager_cfg_highest_used_row($pdo, $halle, $zone),
    ]);
} catch (Throwable $e) {
    lager_cfg_json_response([
        'ok' => false,
        'msg' => $e->getMessage(),
    ], 400);
}