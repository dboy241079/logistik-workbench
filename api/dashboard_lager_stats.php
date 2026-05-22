<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_db.php';

/**
 * PHP-Normalisierung (für BM-Sachnummernset etc.)
 */
function normCode(?string $s): string
{
    $s = trim((string)$s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace([' ', '-', '_', '/'], '', $s);
    return $s;
}
function normPackTransport(?string $s): string {
    $s = trim((string)$s);
    $s = strtoupper($s);
    $s = str_replace([' ', '-', '_', '/'], '', $s);
    return $s;
}

/**
 * SQL-Ausdruck zur Sachnummer-Normalisierung
 */
function sqlNormExpr(string $col): string
{
    return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM({$col}), ' ', ''), '-', ''), '_', ''), '/', ''))";
}

function getGtCapacityForLg(string $lg): int
{
    return match ($lg) {
        'X3(B)' => 52,
        default => 52,
    };
}

function getVwCapacityForLg(string $lg): int
{
    return match ($lg) {
        'X3(B)' => 78,
        default => 78,
    };
}

function getVw0001CapacityForLg(string $lg): int
{
    return match ($lg) {
        'X3(B)' => 78,
        default => 78,
    };
}
function getKartonCapacityForLg(string $lg): int
{
    return match ($lg) {
        'Sarajevo' => 52,
        default => 52,
    };
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function pickExistingTable(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $table) {
        if (tableExists($pdo, $table)) {
            return $table;
        }
    }
    return null;
}

/**
 * Liest alle Spalten einer Tabelle in EINER Query.
 */
function getTableColumns(PDO $pdo, string $table): array
{
    if (!tableExists($pdo, $table)) {
        return [];
    }

    $st = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $st->execute([$table]);

    $cols = [];
    while ($col = $st->fetchColumn()) {
        $cols[(string)$col] = true;
    }
    return $cols;
}

/**
 * BM-Daten je Sachnummer laden
 * Fachliche Logik bewusst wie bisher beibehalten.
 */
function bmFetchBySach(PDO $pdo, string $table, array $cols): array
{
    if (!isset($cols['lagergruppe'])) {
        return [];
    }

    $hasSach  = isset($cols['sachnummer']);
    $hasBeh   = isset($cols['behaelter']);
    $hasZus   = isset($cols['zus_behaelter']);
    $hasMenge = isset($cols['menge']);
    $hasPack  = isset($cols['verpackung']);

    $palletExprParts = [];
    if ($hasBeh) $palletExprParts[] = "COALESCE(behaelter,0)";
    if ($hasZus) $palletExprParts[] = "COALESCE(zus_behaelter,0)";
    $palletExpr = $palletExprParts ? implode("+", $palletExprParts) : "1";

    $unitExpr = $hasMenge ? "COALESCE(menge,0)" : "0";
    $sachExpr = $hasSach ? "TRIM(sachnummer)" : "''";
    $packExpr = $hasPack ? "verpackung" : "''";

    $sql = "
        SELECT
            {$sachExpr} AS sachnummer,
            SUM({$palletExpr}) AS paletten,
            SUM({$unitExpr})   AS stueck,
            SUM(CASE WHEN {$packExpr} IN ('GT14488','GT14491') THEN ({$palletExpr}) ELSE 0 END) AS gt_count,
            SUM(CASE WHEN {$packExpr} IN ('VW0012','114003') THEN ({$palletExpr}) ELSE 0 END) AS vw_count,
            SUM(CASE WHEN {$packExpr} = 'VW0001' THEN ({$palletExpr}) ELSE 0 END) AS vw0001_count
        FROM `{$table}`
        WHERE TRIM(UPPER(lagergruppe)) = 'BM'
        GROUP BY {$sachExpr}
    ";

    $out = [];
    foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $r) {
        $label = trim((string)($r['sachnummer'] ?? ''));
        if ($label === '') {
            $label = 'OHNE SACHNUMMER';
        }

        $out[$label] = [
            'sachnummer'   => $label,
            'paletten'     => (int)($r['paletten'] ?? 0),
            'stueck'       => (int)($r['stueck'] ?? 0),
            'gt_count'     => (int)($r['gt_count'] ?? 0),
            'vw_count'     => (int)($r['vw_count'] ?? 0),
            'vw0001_count' => (int)($r['vw0001_count'] ?? 0),
        ];
    }

    return $out;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('DB nicht verfügbar');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $slotNormExpr = sqlNormExpr('ls.sachnummer');
    $mapNormExpr  = sqlNormExpr('sachnummer');

    /**
     * Mapping Sachnummer -> Lagergruppe als Derived Table,
     * damit es beim Join keine Duplikat-Explosion gibt.
     */
    $mapSql = "
        SELECT
            {$mapNormExpr} AS norm_sach,
            MAX(
                CASE
                    WHEN UPPER(TRIM(COALESCE(lagergruppe,''))) = 'BM' THEN 'BM'
                    WHEN TRIM(COALESCE(lagergruppe,'')) <> '' THEN TRIM(lagergruppe)
                    ELSE 'UNBEKANNT'
                END
            ) AS lagergruppe
        FROM sachnummern
        WHERE sachnummer IS NOT NULL
          AND sachnummer <> ''
        GROUP BY {$mapNormExpr}
    ";

    $lgExpr = "
        CASE
            WHEN UPPER(TRIM(COALESCE(sm.lagergruppe,''))) = 'BM' THEN 'BM'
            WHEN TRIM(COALESCE(sm.lagergruppe,'')) <> '' THEN TRIM(sm.lagergruppe)
            WHEN TRIM(COALESCE(ls.zone,'')) <> '' THEN TRIM(ls.zone)
            ELSE 'UNBEKANNT'
        END
    ";

    /**
     * =====================================================
     * 1) Lagerbestand + Details direkt aggregiert aus SQL
     * =====================================================
     */
    $detailsSql = "
        SELECT
            base.lg,
            base.detail_key,
            MIN(base.sach_label) AS sachnummer,
            COUNT(*) AS paletten,
            SUM(base.menge) AS stueck
        FROM (
            SELECT
                {$lgExpr} AS lg,
                CASE
                    WHEN {$slotNormExpr} <> '' THEN {$slotNormExpr}
                    ELSE '__OHNE_SACHNR__'
                END AS detail_key,
                CASE
                    WHEN TRIM(COALESCE(ls.sachnummer,'')) <> '' THEN TRIM(ls.sachnummer)
                    ELSE 'OHNE SACHNUMMER'
                END AS sach_label,
                COALESCE(ls.menge, 0) AS menge
            FROM lager_slots ls
            LEFT JOIN ({$mapSql}) sm
                ON sm.norm_sach = {$slotNormExpr}
            WHERE ls.deleted_at IS NULL
        ) base
        GROUP BY base.lg, base.detail_key
    ";

    $agg = [];
    $details = [];
    $globalSachSet = [];

    $stmtDetails = $pdo->query($detailsSql);
    while ($row = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
        $lg        = (string)($row['lg'] ?? 'UNBEKANNT');
        $detailKey = (string)($row['detail_key'] ?? '__OHNE_SACHNR__');
        $label     = trim((string)($row['sachnummer'] ?? ''));
        $paletten  = (int)($row['paletten'] ?? 0);
        $stueck    = (int)($row['stueck'] ?? 0);

        if ($label === '') {
            $label = 'OHNE SACHNUMMER';
        }

        if (!isset($agg[$lg])) {
            $agg[$lg] = [
                'lg'          => $lg,
                'lagergruppe' => $lg,
                'paletten'    => 0,
                'stueck'      => 0,
                'sach_set'    => [],
            ];
        }

        $agg[$lg]['paletten'] += $paletten;
        $agg[$lg]['stueck']   += $stueck;

        if ($detailKey !== '__OHNE_SACHNR__') {
            $agg[$lg]['sach_set'][$detailKey] = true;
            $globalSachSet[$detailKey] = true;
        }

        if (!isset($details[$lg])) {
            $details[$lg] = ['items' => []];
        }

        $details[$lg]['items'][$detailKey] = [
            'sachnummer' => $label,
            'paletten'   => $paletten,
            'stueck'     => $stueck,
        ];
    }

    $packNormExpr = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(ls.verpackung,'')), ' ', ''), '-', ''), '_', ''), '/', ''))";
    /**
     * =====================================================
     * 2) Transportdaten direkt aggregiert aus SQL
     * =====================================================
     */
    $transportSql = "
    SELECT
        {$lgExpr} AS lg,
        COUNT(*) AS paletten,
        SUM(CASE WHEN {$packNormExpr} IN ('GT14488','GT14491') THEN 1 ELSE 0 END) AS gt_count,
        SUM(CASE WHEN {$packNormExpr} IN ('VW0012','114003') THEN 1 ELSE 0 END) AS vw_count,
        SUM(CASE WHEN {$packNormExpr} = 'VW0001' THEN 1 ELSE 0 END) AS vw0001_count,
        SUM(CASE WHEN {$lgExpr} = 'Sarajevo' AND {$packNormExpr} IN ('KARTON','KARTONS') THEN 1 ELSE 0 END) AS karton_count
    FROM lager_slots ls
    LEFT JOIN ({$mapSql}) sm
        ON sm.norm_sach = {$slotNormExpr}
    WHERE ls.deleted_at IS NULL
    GROUP BY {$lgExpr}
";

    $transportAgg = [];
    $stmtTransport = $pdo->query($transportSql);

    while ($row = $stmtTransport->fetch(PDO::FETCH_ASSOC)) {
        $lg = (string)($row['lg'] ?? 'UNBEKANNT');

        $transportAgg[$lg] = [
    'lg'           => $lg,
    'paletten'     => (int)($row['paletten'] ?? 0),
    'gt_count'     => (int)($row['gt_count'] ?? 0),
    'vw_count'     => (int)($row['vw_count'] ?? 0),
    'vw0001_count' => (int)($row['vw0001_count'] ?? 0),
    'karton_count' => (int)($row['karton_count'] ?? 0),
    'lkw_relevant' => (int)($row['gt_count'] ?? 0)
                    + (int)($row['vw_count'] ?? 0)
                    + (int)($row['vw0001_count'] ?? 0)
                    + (int)($row['karton_count'] ?? 0),
    'offen'        => 0,
    'volle_lkw'    => 0,
];
    }

    /**
     * =====================================================
     * 3) BM zusätzlich aus WE/WA einhängen
     * =====================================================
     */
    $BM_WE_TABLES = ['wareneingang_old_20251215', 'wareneingang'];
    $BM_WA_TABLES = ['warenausgang_old_20251215', 'warenausgang'];

    $bmWeTable = pickExistingTable($pdo, $BM_WE_TABLES);
    $bmWaTable = pickExistingTable($pdo, $BM_WA_TABLES);

    $bmWeCols = $bmWeTable ? getTableColumns($pdo, $bmWeTable) : [];
    $bmWaCols = $bmWaTable ? getTableColumns($pdo, $bmWaTable) : [];

    $bmIn  = $bmWeTable ? bmFetchBySach($pdo, $bmWeTable, $bmWeCols) : [];
    $bmOut = $bmWaTable ? bmFetchBySach($pdo, $bmWaTable, $bmWaCols) : [];

    $keys = array_unique(array_merge(array_keys($bmIn), array_keys($bmOut)));

    $bmItems = [];
    $bmTotals = [
        'paletten'     => 0,
        'stueck'       => 0,
        'gt_count'     => 0,
        'vw_count'     => 0,
        'vw0001_count' => 0,
    ];
    $bmSachSet = [];

    foreach ($keys as $k) {
        $in  = $bmIn[$k]  ?? ['paletten'=>0,'stueck'=>0,'gt_count'=>0,'vw_count'=>0,'vw0001_count'=>0];
        $out = $bmOut[$k] ?? ['paletten'=>0,'stueck'=>0,'gt_count'=>0,'vw_count'=>0,'vw0001_count'=>0];

        $netPal = (int)$in['paletten']     - (int)$out['paletten'];
        $netStu = (int)$in['stueck']       - (int)$out['stueck'];
        $netGt  = (int)$in['gt_count']     - (int)$out['gt_count'];
        $netVw  = (int)$in['vw_count']     - (int)$out['vw_count'];
        $netVw1 = (int)$in['vw0001_count'] - (int)$out['vw0001_count'];

        if ($netPal <= 0 && $netStu <= 0 && $netGt <= 0 && $netVw <= 0 && $netVw1 <= 0) {
            continue;
        }

        $bmItems[] = [
            'sachnummer' => $k,
            'paletten'   => max(0, $netPal),
            'stueck'     => max(0, $netStu),
        ];

        $bmTotals['paletten']     += max(0, $netPal);
        $bmTotals['stueck']       += max(0, $netStu);
        $bmTotals['gt_count']     += max(0, $netGt);
        $bmTotals['vw_count']     += max(0, $netVw);
        $bmTotals['vw0001_count'] += max(0, $netVw1);

        if ($k !== 'OHNE SACHNUMMER') {
            $bmSachSet[normCode($k)] = true;
            $globalSachSet[normCode($k)] = true;
        }
    }

    usort($bmItems, static function(array $a, array $b): int {
        $cmp = ((int)$b['paletten'] <=> (int)$a['paletten']);
        if ($cmp !== 0) return $cmp;
        return strnatcasecmp((string)$a['sachnummer'], (string)$b['sachnummer']);
    });

    if (!isset($agg['BM'])) {
        $agg['BM'] = [
            'lg'          => 'BM',
            'lagergruppe' => 'BM',
            'paletten'    => 0,
            'stueck'      => 0,
            'sach_set'    => [],
        ];
    }

    $agg['BM']['paletten'] = (int)$bmTotals['paletten'];
    $agg['BM']['stueck']   = (int)$bmTotals['stueck'];
    $agg['BM']['sach_set'] = $bmSachSet;

    $details['BM'] = ['items' => $bmItems];

    if (!isset($transportAgg['BM'])) {
    $transportAgg['BM'] = [
        'lg'           => 'BM',
        'paletten'     => 0,
        'gt_count'     => 0,
        'vw_count'     => 0,
        'vw0001_count' => 0,
        'karton_count' => 0,
        'lkw_relevant' => 0,
        'offen'        => 0,
        'volle_lkw'    => 0,
    ];
}

    $transportAgg['BM']['karton_count'] = 0;
    $transportAgg['BM']['paletten']     = (int)$bmTotals['paletten'];
    $transportAgg['BM']['gt_count']     = (int)$bmTotals['gt_count'];
    $transportAgg['BM']['vw_count']     = (int)$bmTotals['vw_count'];
    $transportAgg['BM']['vw0001_count'] = (int)$bmTotals['vw0001_count'];
    $transportAgg['BM']['lkw_relevant'] = (int)($bmTotals['gt_count'] + $bmTotals['vw_count'] + $bmTotals['vw0001_count']);

    /**
     * =====================================================
     * 4) Reihenfolge + Sortierung
     * =====================================================
     */
    $wantedOrder = ['W1', 'X3', 'X3(B)', 'G9', 'B1', 'B1(T)', 'Sarajevo', 'Bauteile', 'BM', 'UNBEKANNT'];

    foreach ($details as $lg => $block) {
        $items = array_values($block['items']);
        usort($items, static function (array $a, array $b): int {
            $cmp = ((int)$b['paletten'] <=> (int)$a['paletten']);
            if ($cmp !== 0) return $cmp;
            return strnatcasecmp((string)$a['sachnummer'], (string)$b['sachnummer']);
        });
        $details[$lg]['items'] = $items;
    }

    foreach ($transportAgg as $lg => &$t) {
    $gtCap      = getGtCapacityForLg((string)$lg);
    $vwCap      = getVwCapacityForLg((string)$lg);
    $vw0001Cap  = getVw0001CapacityForLg((string)$lg);
    $kartonCap  = getKartonCapacityForLg((string)$lg);

    $t['gt_lkw']    = $gtCap > 0 ? intdiv((int)$t['gt_count'], $gtCap) : 0;
    $t['gt_rest']   = $gtCap > 0 ? ((int)$t['gt_count'] % $gtCap) : (int)$t['gt_count'];

    $t['vw_lkw']    = $vwCap > 0 ? intdiv((int)$t['vw_count'], $vwCap) : 0;
    $t['vw_rest']   = $vwCap > 0 ? ((int)$t['vw_count'] % $vwCap) : (int)$t['vw_count'];

    $t['vw0001_lkw']  = $vw0001Cap > 0 ? intdiv((int)($t['vw0001_count'] ?? 0), $vw0001Cap) : 0;
    $t['vw0001_rest'] = $vw0001Cap > 0 ? ((int)($t['vw0001_count'] ?? 0) % $vw0001Cap) : (int)($t['vw0001_count'] ?? 0);

    $t['karton_lkw']  = $kartonCap > 0 ? intdiv((int)($t['karton_count'] ?? 0), $kartonCap) : 0;
    $t['karton_rest'] = $kartonCap > 0 ? ((int)($t['karton_count'] ?? 0) % $kartonCap) : (int)($t['karton_count'] ?? 0);

    $t['volle_lkw'] = (int)$t['gt_lkw']
                    + (int)$t['vw_lkw']
                    + (int)$t['vw0001_lkw']
                    + (int)$t['karton_lkw'];

    $t['offen'] = max(0, (int)$t['paletten'] - (int)$t['lkw_relevant']);
}
unset($t);

    /**
     * =====================================================
     * 5) Rows bauen
     * =====================================================
     */
    $rows = [];
    $transportRows = [];

   foreach ($wantedOrder as $lg) {
    if (isset($agg[$lg])) {
        $rows[] = [
            'lg'          => $agg[$lg]['lg'],
            'lagergruppe' => $agg[$lg]['lagergruppe'],
            'paletten'    => (int)$agg[$lg]['paletten'],
            'stueck'      => (int)$agg[$lg]['stueck'],
            'sachnr'      => count($agg[$lg]['sach_set']),
        ];
        unset($agg[$lg]);
    }

    if (isset($transportAgg[$lg])) {
        $transportRows[] = [
            'lg'            => $transportAgg[$lg]['lg'],
            'gt_count'      => (int)$transportAgg[$lg]['gt_count'],
            'gt_lkw'        => (int)$transportAgg[$lg]['gt_lkw'],
            'gt_rest'       => (int)$transportAgg[$lg]['gt_rest'],

            'vw_count'      => (int)$transportAgg[$lg]['vw_count'],
            'vw_lkw'        => (int)$transportAgg[$lg]['vw_lkw'],
            'vw_rest'       => (int)$transportAgg[$lg]['vw_rest'],

            'vw0001_count'  => (int)$transportAgg[$lg]['vw0001_count'],
            'vw0001_lkw'    => (int)$transportAgg[$lg]['vw0001_lkw'],
            'vw0001_rest'   => (int)$transportAgg[$lg]['vw0001_rest'],

            'karton_count'  => (int)($transportAgg[$lg]['karton_count'] ?? 0),
            'karton_lkw'    => (int)($transportAgg[$lg]['karton_lkw'] ?? 0),
            'karton_rest'   => (int)($transportAgg[$lg]['karton_rest'] ?? 0),

            'lkw_relevant'  => (int)$transportAgg[$lg]['lkw_relevant'],
            'offen'         => (int)$transportAgg[$lg]['offen'],
            'volle_lkw'     => (int)$transportAgg[$lg]['volle_lkw'],
        ];
        unset($transportAgg[$lg]);
    }
}

    if (!empty($agg)) {
        ksort($agg, SORT_NATURAL);
        foreach ($agg as $item) {
            $rows[] = [
    'lg'          => $item['lg'],
    'lagergruppe' => $item['lagergruppe'],
    'paletten'    => (int)$item['paletten'],
    'stueck'      => (int)$item['stueck'],
    'sachnr'      => count($item['sach_set']),
];
        }
    }

if (!empty($transportAgg)) {
    ksort($transportAgg, SORT_NATURAL);
    foreach ($transportAgg as $item) {
        $transportRows[] = [
            'lg'            => $item['lg'],
            'gt_count'      => (int)$item['gt_count'],
            'gt_lkw'        => (int)$item['gt_lkw'],
            'gt_rest'       => (int)$item['gt_rest'],

            'vw_count'      => (int)$item['vw_count'],
            'vw_lkw'        => (int)$item['vw_lkw'],
            'vw_rest'       => (int)$item['vw_rest'],

            'vw0001_count'  => (int)$item['vw0001_count'],
            'vw0001_lkw'    => (int)$item['vw0001_lkw'],
            'vw0001_rest'   => (int)$item['vw0001_rest'],

            'karton_count'  => (int)($item['karton_count'] ?? 0),
            'karton_lkw'    => (int)($item['karton_lkw'] ?? 0),
            'karton_rest'   => (int)($item['karton_rest'] ?? 0),

            'lkw_relevant'  => (int)$item['lkw_relevant'],
            'offen'         => (int)$item['offen'],
            'volle_lkw'     => (int)$item['volle_lkw'],
        ];
    }
}

    /**
     * =====================================================
     * 6) Totals Lagerbestand
     * =====================================================
     */
    $totalPaletten = 0;
    $totalStueck   = 0;

    foreach ($rows as $r) {
        $totalPaletten += (int)$r['paletten'];
        $totalStueck   += (int)$r['stueck'];
    }

    $totalSachnr = count($globalSachSet);

    /**
     * =====================================================
     * 7) Totals Transport
     * =====================================================
     */
    $transportTotals = [
    'gt_count'        => 0,
    'gt_lkw'          => 0,
    'gt_rest'         => 0,
    'vw_count'        => 0,
    'vw_lkw'          => 0,
    'vw_rest'         => 0,
    'vw0001_count'    => 0,
    'vw0001_lkw'      => 0,
    'vw0001_rest'     => 0,
    'karton_count'    => 0,
    'karton_lkw'      => 0,
    'karton_rest'     => 0,
    'lkw_relevant'    => 0,
    'offen'           => 0,
    'erledigt_gesamt' => 0,
    'volle_lkw'       => 0,
];

    foreach ($transportRows as $tr) {
        $transportTotals['gt_count']        += (int)($tr['gt_count'] ?? 0);
        $transportTotals['gt_lkw']          += (int)($tr['gt_lkw'] ?? 0);
        $transportTotals['gt_rest']         += (int)($tr['gt_rest'] ?? 0);

        $transportTotals['vw_count']        += (int)($tr['vw_count'] ?? 0);
        $transportTotals['vw_lkw']          += (int)($tr['vw_lkw'] ?? 0);
        $transportTotals['vw_rest']         += (int)($tr['vw_rest'] ?? 0);

        $transportTotals['vw0001_count']    += (int)($tr['vw0001_count'] ?? 0);
        $transportTotals['vw0001_lkw']      += (int)($tr['vw0001_lkw'] ?? 0);
        $transportTotals['vw0001_rest']     += (int)($tr['vw0001_rest'] ?? 0);
        
        $transportTotals['karton_count'] += (int)($tr['karton_count'] ?? 0);
        $transportTotals['karton_lkw']   += (int)($tr['karton_lkw'] ?? 0);
        $transportTotals['karton_rest']  += (int)($tr['karton_rest'] ?? 0);

        $transportTotals['lkw_relevant']    += (int)($tr['lkw_relevant'] ?? 0);
        $transportTotals['offen']           += (int)($tr['offen'] ?? 0);
        $transportTotals['volle_lkw']       += (int)($tr['volle_lkw'] ?? 0);
    }

    $transportTotals['erledigt_gesamt'] = $transportTotals['lkw_relevant'];

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
        'details' => $details,
        'totals' => [
            'paletten' => $totalPaletten,
            'stueck'   => $totalStueck,
            'sachnr'   => $totalSachnr,
        ],
        'filtered' => [
            'paletten' => $totalPaletten,
            'stueck'   => $totalStueck,
            'sachnr'   => $totalSachnr,
        ],
        'transport_rows' => $transportRows,
        'transport' => $transportTotals,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}