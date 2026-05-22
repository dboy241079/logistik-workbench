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
$row   = max(1, (int)($_POST['row'] ?? 0));

try {
    $cfg = lager_cfg_get($halle, $zone);
    $overrides = is_array($cfg['row_overrides'] ?? null) ? $cfg['row_overrides'] : [];

    if (!isset($overrides[(string)$row])) {
        throw new RuntimeException("Für Reihe {$row} existiert keine Sonderregel.");
    }

    // Beim Löschen fällt die Reihe auf die Standardwerte zurück.
    // Deshalb vorher prüfen, ob aktive Daten mit den Standardwerten noch gültig wären.
    lager_cfg_assert_override_safe(
        $pdo,
        $halle,
        $zone,
        $row,
        (int)$cfg['default_places_per_row'],
        (int)$cfg['default_slots_per_place']
    );

    unset($overrides[(string)$row]);

    $cfg = lager_cfg_put($halle, $zone, [
        'halle' => $halle,
        'zone'  => $zone,
        'row_from' => $cfg['row_from'],
        'row_to'   => $cfg['row_to'],
        'default_places_per_row'  => $cfg['default_places_per_row'],
        'default_slots_per_place' => $cfg['default_slots_per_place'],
        'row_overrides' => $overrides,
        'updated_at' => date('c'),
        'updated_by' => ($_SESSION['username'] ?? $_SESSION['display_name'] ?? 'system'),
    ]);

    lager_cfg_json_response([
        'ok' => true,
        'msg' => "Sonderregel für Reihe {$row} gelöscht.",
        'row_overrides' => $cfg['row_overrides'],
        'highest_used_row' => lager_cfg_highest_used_row($pdo, $halle, $zone),
    ]);
} catch (Throwable $e) {
    lager_cfg_json_response([
        'ok' => false,
        'msg' => $e->getMessage(),
    ], 400);
}