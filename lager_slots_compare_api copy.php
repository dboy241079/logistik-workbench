<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$ROOT = __DIR__; // /LKW
require $ROOT . '/inc/session.php';
require $ROOT . '/api/_db.php'; // liegt bei dir in /LKW/api/_db.php

function out(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('pdo_not_initialized');
    }

    $action = trim((string)($_GET['action'] ?? ''));

    if ($action === 'rows') {
        $sql = "
    SELECT DISTINCT halle, zone, reihe
    FROM lager_slots
    WHERE COALESCE(active_key, 1) = 1
      AND deleted_at IS NULL
      AND reihe IS NOT NULL
      AND TRIM(reihe) <> ''
    ORDER BY halle ASC, zone ASC, reihe ASC
";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $halle = trim((string)($r['halle'] ?? ''));
            $zone  = trim((string)($r['zone'] ?? ''));
            $reihe = trim((string)($r['reihe'] ?? ''));
            if ($halle === '' || $zone === '' || $reihe === '') continue;

            $result[] = [
                'halle' => $halle,
                'zone'  => $zone,
                'reihe' => $reihe,
                'label' => "{$halle} / {$zone} / {$reihe}",
            ];
        }

        out(['ok' => true, 'rows' => $result, 'count' => count($result)]);
    }

    if ($action === 'refs') {
        $halle = trim((string)($_GET['halle'] ?? ''));
        $zone  = trim((string)($_GET['zone'] ?? ''));
        $reihe = trim((string)($_GET['reihe'] ?? ''));

        if ($halle === '' || $zone === '' || $reihe === '') {
            out(['ok' => false, 'error' => 'missing_params'], 400);
        }

        $sql = "
    SELECT referenznr
    FROM lager_slots
    WHERE COALESCE(active_key, 1) = 1
      AND deleted_at IS NULL
      AND halle = :halle
      AND zone  = :zone
      AND reihe = :reihe
      AND referenznr IS NOT NULL
      AND TRIM(referenznr) <> ''
    ORDER BY platz ASC, slot_index ASC, id ASC
";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':halle' => $halle,
            ':zone'  => $zone,
            ':reihe' => $reihe,
        ]);

        $refs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ref = trim((string)($row['referenznr'] ?? ''));
            if ($ref !== '') $refs[] = $ref;
        }

        out([
            'ok' => true,
            'halle' => $halle,
            'zone' => $zone,
            'reihe' => $reihe,
            'refs' => $refs,
            'count' => count($refs)
        ]);
    }

    if ($action === 'health') {
        out(['ok' => true, 'status' => 'healthy']);
    }

    out(['ok' => false, 'error' => 'invalid_action'], 400);

} catch (Throwable $e) {
    error_log('[lager_slots_compare_api] ' . $e->getMessage());
    out(['ok' => false, 'error' => 'server_error'], 500);
}
