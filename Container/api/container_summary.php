<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../api/_db.php';

function out(array $a): void {
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // kleine Helferfunktion
    $tableExists = function(PDO $pdo, string $table): bool {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    $hasMasterTable          = $tableExists($pdo, 'container_master');
    $hasPalletsTable         = $tableExists($pdo, 'container_pallets');
    $hasContainerImagesTable = $tableExists($pdo, 'container_images');
    $hasItemImagesTable      = $tableExists($pdo, 'container_item_images');

    if (!$hasMasterTable) {
        throw new RuntimeException("Tabelle 'container_master' nicht gefunden");
    }

    // 1) Alle Container aus Master laden (wie container_load.php)
    $stMaster = $pdo->query("
        SELECT code, capacity
        FROM container_master
        ORDER BY code ASC
    ");
    $masterRows = $stMaster->fetchAll(PDO::FETCH_ASSOC);

    // 2) Belegung aus container_pallets zählen
    $usedMap = [];
    if ($hasPalletsTable) {
        $stUsed = $pdo->query("
            SELECT container_code AS code, COUNT(*) AS used
            FROM container_pallets
            GROUP BY container_code
        ");
        foreach ($stUsed->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $usedMap[(string)$r['code']] = (int)$r['used'];
        }
    }

    // 3) 2 Container-Gesamtbilder aus container_images (bei dir: file_path)
    $containerImgMap = [];
    if ($hasContainerImagesTable) {
        $stCImg = $pdo->query("
            SELECT
                container_code,
                MAX(CASE WHEN slot = 1 THEN file_path ELSE NULL END) AS img1,
                MAX(CASE WHEN slot = 2 THEN file_path ELSE NULL END) AS img2,
                CASE
                    WHEN SUM(CASE WHEN file_path IS NOT NULL AND file_path <> '' THEN 1 ELSE 0 END) > 0 THEN 1
                    ELSE 0
                END AS has_container_images
            FROM container_images
            GROUP BY container_code
        ");

        foreach ($stCImg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $containerImgMap[(string)$r['container_code']] = [
                'img1' => $r['img1'] ?? null,
                'img2' => $r['img2'] ?? null,
                'has_container_images' => (int)($r['has_container_images'] ?? 0),
            ];
        }
    }

    // 4) Slot-/Item-Bilder je Container erkennen
    // Join: container_item_images.item_id -> container_pallets.id
    $itemImgMap = [];
    if ($hasItemImagesTable && $hasPalletsTable) {
        $stIImg = $pdo->query("
            SELECT
                cp.container_code,
                1 AS has_item_images
            FROM container_item_images cii
            INNER JOIN container_pallets cp ON cp.id = cii.item_id
            GROUP BY cp.container_code
        ");

        foreach ($stIImg->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $itemImgMap[(string)$r['container_code']] = 1;
        }
    }

    // 5) Ausgabe fürs Frontend bauen
    $items = [];
    foreach ($masterRows as $row) {
        $code = strtoupper(trim((string)$row['code']));
        if ($code === '') continue;

        $capacity = (int)($row['capacity'] ?? 48);
        if ($capacity <= 0) $capacity = 48;

        $used = (int)($usedMap[$code] ?? 0);

        $cimg = $containerImgMap[$code] ?? [
            'img1' => null,
            'img2' => null,
            'has_container_images' => 0,
        ];

        $hasItemImages = (int)($itemImgMap[$code] ?? 0);
        $hasImages = ((int)$cimg['has_container_images'] === 1 || $hasItemImages === 1) ? 1 : 0;

        $items[] = [
            'code' => $code,
            'used' => $used,
            'capacity' => $capacity,

            // wichtig für dein bestehendes JS hasImages(summaryRow)
            'img1' => $cimg['img1'],
            'img2' => $cimg['img2'],
            'has_images' => $hasImages,

            // optional hilfreich
            'has_container_images' => (int)$cimg['has_container_images'],
            'has_item_images' => $hasItemImages,
        ];
    }

    out([
        'ok' => true,
        'items' => $items,
        'debug' => [
            'master' => $hasMasterTable,
            'pallets' => $hasPalletsTable,
            'container_images' => $hasContainerImagesTable,
            'container_item_images' => $hasItemImagesTable
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    out([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}