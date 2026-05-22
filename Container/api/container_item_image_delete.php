<?php
declare(strict_types=1);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $itemId = (int)($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'item_id fehlt']);
        exit;
    }

    $st = $pdo->prepare("SELECT id, image_path FROM container_item_images WHERE item_id = ?");
    $st->execute([$itemId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $rel = (string)$row['image_path'];
        $abs = $_SERVER['DOCUMENT_ROOT'] . $rel;
        if ($rel !== '' && is_file($abs)) {
            @unlink($abs);
        }
    }

    $pdo->prepare("DELETE FROM container_item_images WHERE item_id = ?")->execute([$itemId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}