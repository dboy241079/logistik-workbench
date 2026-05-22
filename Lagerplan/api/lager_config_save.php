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

$rowFrom = max(1, (int)($_POST['row_from'] ?? 1));
$rowTo   = max($rowFrom, (int)($_POST['row_to'] ?? $rowFrom));

$defaultPlacesPerRow  = max(1, (int)($_POST['default_places_per_row'] ?? 40));
$defaultSlotsPerPlace = max(1, (int)($_POST['default_slots_per_place'] ?? 4));

try {
    $oldCfg = lager_cfg_get($halle, $zone);

    // Endreihe nicht unter belegte Reihen kürzen
    lager_cfg_assert_row_range_safe($pdo, $halle, $zone, $rowTo);

    // Neue Standardwerte nur dann zulassen, wenn alle NICHT überschriebenen Reihen
    // mit aktiven Daten weiterhin gültig sind.
    lager_cfg_assert_defaults_safe(
        $pdo,
        $halle,
        $zone,
        $rowFrom,
        $rowTo,
        $defaultPlacesPerRow,
        $defaultSlotsPerPlace,
        is_array($oldCfg['row_overrides'] ?? null) ? $oldCfg['row_overrides'] : []
    );

    $cfg = lager_cfg_put($halle, $zone, [
        'halle' => $halle,
        'zone'  => $zone,
        'row_from' => $rowFrom,
        'row_to'   => $rowTo,
        'default_places_per_row'  => $defaultPlacesPerRow,
        'default_slots_per_place' => $defaultSlotsPerPlace,
        'row_overrides' => $oldCfg['row_overrides'] ?? [],
        'updated_at' => date('c'),
        'updated_by' => ($_SESSION['username'] ?? $_SESSION['display_name'] ?? 'system'),
    ]);

    lager_cfg_json_response([
        'ok' => true,
        'msg' => 'Basis-Konfiguration gespeichert.',
        'halle' => $cfg['halle'],
        'zone'  => $cfg['zone'],
        'row_from' => (int)$cfg['row_from'],
        'row_to'   => (int)$cfg['row_to'],
        'default_places_per_row' => (int)$cfg['default_places_per_row'],
        'default_slots_per_place' => (int)$cfg['default_slots_per_place'],
        'row_overrides' => $cfg['row_overrides'],
        'highest_used_row' => lager_cfg_highest_used_row($pdo, $halle, $zone),
        'updated_at' => $cfg['updated_at'] ?? null,
        'updated_by' => $cfg['updated_by'] ?? null,
    ]);
} catch (Throwable $e) {
    lager_cfg_json_response([
        'ok' => false,
        'msg' => $e->getMessage(),
    ], 400);
}