<?php
declare(strict_types=1);

require __DIR__ . '/../../api/_db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $itemId = (int)($_GET['item_id'] ?? 0);
    if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'item_id fehlt']);
        exit;
    }

    $st = $pdo->prepare("
        SELECT id, image_path
        FROM container_item_images
        WHERE item_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => true, 'url' => null]);
        exit;
    }

    echo json_encode([
        'ok'  => true,
        'url' => $row['image_path']
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}