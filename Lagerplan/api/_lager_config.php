<?php
declare(strict_types=1);

function lager_cfg_file_path(): string
{
    return dirname(__DIR__, 2) . '/data/lager_config.json';
}

function lager_cfg_key(string $halle, string $zone): string
{
    return strtoupper(trim($halle)) . '::' . strtoupper(trim($zone));
}

function lager_cfg_default(): array
{
    return [
        'halle' => 'H3',
        'zone' => 'W1',
        'row_from' => 1,
        'row_to' => 200,
        'default_places_per_row' => 40,
        'default_slots_per_place' => 4,
        'row_overrides' => [
            '20' => [
                'places' => 25,
                'slots_per_place' => 20,
            ],
            '43' => [
                'places' => 35,
                'slots_per_place' => 4,
            ],
        ],
        'updated_at' => null,
        'updated_by' => null,
    ];
}

function lager_cfg_normalize_override_map(array $map): array
{
    $out = [];

    foreach ($map as $row => $cfg) {
        $rowNo = (int)$row;
        if ($rowNo < 1 || !is_array($cfg)) {
            continue;
        }

        $places = max(1, (int)($cfg['places'] ?? 0));
        $slots  = max(1, (int)($cfg['slots_per_place'] ?? 0));

        $out[(string)$rowNo] = [
            'places' => $places,
            'slots_per_place' => $slots,
        ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
}

function lager_cfg_load_store(string $path): array
{
    if (!is_file($path)) {
        return ['configs' => []];
    }

    $raw = @file_get_contents($path);
    $js  = json_decode((string)$raw, true);

    if (!is_array($js)) {
        return ['configs' => []];
    }

    // Altformat abfangen:
    // { halle, zone, row_from, row_to, ... }
    if (!isset($js['configs']) && (isset($js['row_from']) || isset($js['row_to']))) {
        $base = lager_cfg_default();
        $cfg = array_merge($base, [
            'halle' => (string)($js['halle'] ?? 'H3'),
            'zone'  => (string)($js['zone'] ?? 'W1'),
            'row_from' => max(1, (int)($js['row_from'] ?? $base['row_from'])),
            'row_to'   => max(1, (int)($js['row_to'] ?? $base['row_to'])),
            'default_places_per_row' => max(1, (int)($js['default_places_per_row'] ?? $base['default_places_per_row'])),
            'default_slots_per_place' => max(1, (int)($js['default_slots_per_place'] ?? $base['default_slots_per_place'])),
            'row_overrides' => lager_cfg_normalize_override_map(
                is_array($js['row_overrides'] ?? null) ? $js['row_overrides'] : $base['row_overrides']
            ),
            'updated_at' => $js['updated_at'] ?? null,
            'updated_by' => $js['updated_by'] ?? null,
        ]);

        return [
            'configs' => [
                lager_cfg_key($cfg['halle'], $cfg['zone']) => $cfg
            ]
        ];
    }

    $configs = is_array($js['configs'] ?? null) ? $js['configs'] : [];
    foreach ($configs as $key => $cfg) {
        if (!is_array($cfg)) {
            unset($configs[$key]);
            continue;
        }

        $base = lager_cfg_default();
        $cfg = array_merge($base, $cfg);

        $cfg['halle'] = (string)($cfg['halle'] ?? 'H3');
        $cfg['zone']  = (string)($cfg['zone'] ?? 'W1');
        $cfg['row_from'] = max(1, (int)($cfg['row_from'] ?? $base['row_from']));
        $cfg['row_to']   = max($cfg['row_from'], (int)($cfg['row_to'] ?? $base['row_to']));
        $cfg['default_places_per_row'] = max(1, (int)($cfg['default_places_per_row'] ?? $base['default_places_per_row']));
        $cfg['default_slots_per_place'] = max(1, (int)($cfg['default_slots_per_place'] ?? $base['default_slots_per_place']));
        $cfg['row_overrides'] = lager_cfg_normalize_override_map(
            is_array($cfg['row_overrides'] ?? null) ? $cfg['row_overrides'] : $base['row_overrides']
        );

        $configs[$key] = $cfg;
    }

    return ['configs' => $configs];
}

function lager_cfg_save_store(string $path, array $store): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Konfig-Ordner konnte nicht erstellt werden.');
    }

    if (is_file($path)) {
        @copy($path, $path . '.bak_' . date('Ymd_His'));
    }

    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON encode fehlgeschlagen.');
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Konfig konnte nicht geschrieben werden.');
    }

    if (!@rename($tmp, $path)) {
        throw new RuntimeException('Konfig konnte nicht final gespeichert werden.');
    }
}

function lager_cfg_get(string $halle, string $zone): array
{
    $store = lager_cfg_load_store(lager_cfg_file_path());
    $key   = lager_cfg_key($halle, $zone);

    if (!isset($store['configs'][$key]) || !is_array($store['configs'][$key])) {
        $base = lager_cfg_default();
        $base['halle'] = strtoupper(trim($halle));
        $base['zone']  = strtoupper(trim($zone));
        return $base;
    }

    return $store['configs'][$key];
}

function lager_cfg_put(string $halle, string $zone, array $cfg): array
{
    $store = lager_cfg_load_store(lager_cfg_file_path());
    $key   = lager_cfg_key($halle, $zone);

    $base = lager_cfg_default();
    $merged = array_merge($base, $cfg);

    $merged['halle'] = strtoupper(trim($halle));
    $merged['zone']  = strtoupper(trim($zone));
    $merged['row_from'] = max(1, (int)($merged['row_from'] ?? $base['row_from']));
    $merged['row_to']   = max($merged['row_from'], (int)($merged['row_to'] ?? $base['row_to']));
    $merged['default_places_per_row'] = max(1, (int)($merged['default_places_per_row'] ?? $base['default_places_per_row']));
    $merged['default_slots_per_place'] = max(1, (int)($merged['default_slots_per_place'] ?? $base['default_slots_per_place']));
    $merged['row_overrides'] = lager_cfg_normalize_override_map(
        is_array($merged['row_overrides'] ?? null) ? $merged['row_overrides'] : $base['row_overrides']
    );

    $store['configs'][$key] = $merged;
    lager_cfg_save_store(lager_cfg_file_path(), $store);

    return $merged;
}

function lager_cfg_highest_used_row(PDO $pdo, string $halle, string $zone): int
{
    $sql = "
        SELECT COALESCE(MAX(CAST(reihe AS UNSIGNED)), 0)
        FROM lager_slots
        WHERE halle = :halle
          AND zone = :zone
          AND deleted_at IS NULL
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':halle' => $halle,
        ':zone'  => $zone,
    ]);

    return (int)$st->fetchColumn();
}

function lager_cfg_assert_row_range_safe(PDO $pdo, string $halle, string $zone, int $rowTo): void
{
    $sql = "
        SELECT COALESCE(MAX(CAST(reihe AS UNSIGNED)), 0)
        FROM lager_slots
        WHERE halle = :halle
          AND zone = :zone
          AND deleted_at IS NULL
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':halle' => $halle,
        ':zone'  => $zone,
    ]);

    $maxUsed = (int)$st->fetchColumn();
    if ($maxUsed > $rowTo) {
        throw new RuntimeException(
            "Speichern blockiert: Es existieren noch aktive Lagerplätze bis Reihe {$maxUsed}. Neue Endreihe {$rowTo} ist zu klein."
        );
    }
}

function lager_cfg_assert_override_safe(
    PDO $pdo,
    string $halle,
    string $zone,
    int $row,
    int $places,
    int $slotsPerPlace
): void {
    $sql = "
        SELECT
            COALESCE(MAX(CAST(platz AS UNSIGNED)), 0) AS max_platz,
            COALESCE(MAX(slot_index), -1) AS max_slot_index,
            COUNT(*) AS cnt
        FROM lager_slots
        WHERE halle = :halle
          AND zone = :zone
          AND deleted_at IS NULL
          AND CAST(reihe AS UNSIGNED) = :reihe
          AND (
                CAST(platz AS UNSIGNED) > :places
                OR slot_index >= :slots
              )
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':halle'  => $halle,
        ':zone'   => $zone,
        ':reihe'  => $row,
        ':places' => $places,
        ':slots'  => $slotsPerPlace,
    ]);

    $rowData = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $cnt     = (int)($rowData['cnt'] ?? 0);

    if ($cnt > 0) {
        $maxPlatz = (int)($rowData['max_platz'] ?? 0);
        $maxSlot  = (int)($rowData['max_slot_index'] ?? -1);

        throw new RuntimeException(
            "Reihe {$row} kann nicht auf {$places} Plätze / {$slotsPerPlace} Slots gesetzt werden. "
            . "Aktive Daten liegen noch bis Platz {$maxPlatz}, Slot " . ($maxSlot + 1) . "."
        );
    }
}

function lager_cfg_assert_defaults_safe(
    PDO $pdo,
    string $halle,
    string $zone,
    int $rowFrom,
    int $rowTo,
    int $defaultPlacesPerRow,
    int $defaultSlotsPerPlace,
    array $rowOverrides = []
): void {
    $overrideRows = [];

    foreach ($rowOverrides as $row => $cfg) {
        $rowNo = (int)$row;
        if ($rowNo >= 1) {
            $overrideRows[] = $rowNo;
        }
    }

    $params = [
        ':halle'   => $halle,
        ':zone'    => $zone,
        ':rowFrom' => $rowFrom,
        ':rowTo'   => $rowTo,
        ':places'  => $defaultPlacesPerRow,
        ':slots'   => $defaultSlotsPerPlace,
    ];

    $sql = "
        SELECT
            CAST(reihe AS UNSIGNED) AS bad_row,
            COALESCE(MAX(CAST(platz AS UNSIGNED)), 0) AS max_platz,
            COALESCE(MAX(slot_index), -1) AS max_slot_index,
            COUNT(*) AS cnt
        FROM lager_slots
        WHERE halle = :halle
          AND zone = :zone
          AND deleted_at IS NULL
          AND CAST(reihe AS UNSIGNED) BETWEEN :rowFrom AND :rowTo
          AND (
                CAST(platz AS UNSIGNED) > :places
                OR slot_index >= :slots
              )
    ";

    if ($overrideRows) {
        $ph = [];
        foreach ($overrideRows as $i => $ovRow) {
            $key = ':ov' . $i;
            $ph[] = $key;
            $params[$key] = $ovRow;
        }
        $sql .= " AND CAST(reihe AS UNSIGNED) NOT IN (" . implode(',', $ph) . ")";
    }

    $sql .= "
        GROUP BY CAST(reihe AS UNSIGNED)
        ORDER BY bad_row ASC
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $bad = $st->fetch(PDO::FETCH_ASSOC);
    if ($bad) {
        $badRow   = (int)($bad['bad_row'] ?? 0);
        $maxPlatz = (int)($bad['max_platz'] ?? 0);
        $maxSlot  = (int)($bad['max_slot_index'] ?? -1);

        throw new RuntimeException(
            "Standardwerte können nicht auf {$defaultPlacesPerRow} Plätze / {$defaultSlotsPerPlace} Slots gesetzt werden. "
            . "Mindestens Reihe {$badRow} hat noch aktive Daten bis Platz {$maxPlatz}, Slot " . ($maxSlot + 1) . "."
        );
    }
}

function lager_cfg_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}