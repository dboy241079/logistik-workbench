<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/inc/session.php';
require dirname(__DIR__, 2) . '/api/_db.php';
require dirname(__DIR__, 2) . '/inc/rbac.php';
require __DIR__ . '/_lager_config.php';

/*
 |------------------------------------------------------------
 | Gleicher Guard wie im Lagerplan selbst
 |------------------------------------------------------------
 | Zugriff über app_tab_roles auf Tab-Key "lagerplan"
 | Für APIs immer JSON als Deny-Mode
 */
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_TAB_KEY       = 'lagerplan';
$AUTH_DEFAULT_TAB   = 'lagerplan';
$AUTH_DENY_MODE     = 'json';

require dirname(__DIR__, 2) . '/inc/auth_embed.php';

$halle = strtoupper(trim((string)($_GET['halle'] ?? 'H3')));
$zone  = strtoupper(trim((string)($_GET['zone'] ?? 'W1')));

try {
    $cfg = lager_cfg_get($halle, $zone);

    lager_cfg_json_response([
        'ok' => true,
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
    ], 500);
}