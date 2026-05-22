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

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['ok' => false, 'msg' => 'Keine Datei empfangen']);
        exit;
    }

    $f = $_FILES['image'];

    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Upload-Fehler Code: ' . (int)$f['error']]);
        exit;
    }

    // MIME prüfen
    $tmp = $f['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['ok' => false, 'msg' => 'Nur JPG/PNG/WEBP erlaubt']);
        exit;
    }

    // Zielordner
    $relDir = '/uploads/container_item_images';
    $absDir = $_SERVER['DOCUMENT_ROOT'] . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden');
    }

    // Optional: vorhandenes Bild löschen (immer nur 1 Bild pro item)
    $stOld = $pdo->prepare("SELECT id, image_path FROM container_item_images WHERE item_id = ? ORDER BY id DESC");
    $stOld->execute([$itemId]);
    $oldRows = $stOld->fetchAll(PDO::FETCH_ASSOC);

    foreach ($oldRows as $old) {
        $oldPath = (string)$old['image_path'];
        $oldAbs  = $_SERVER['DOCUMENT_ROOT'] . $oldPath;
        if ($oldPath !== '' && is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }
    $pdo->prepare("DELETE FROM container_item_images WHERE item_id = ?")->execute([$itemId]);

    $ext = $allowed[$mime];
    $filename = 'item_' . $itemId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $absDir . '/' . $filename;
    $relPath = $relDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $absPath)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden');
    }

    $createdBy = (string)($_SESSION['username'] ?? '');

    $ins = $pdo->prepare("
        INSERT INTO container_item_images (item_id, image_path, created_by)
        VALUES (?, ?, ?)
    ");
    $ins->execute([$itemId, $relPath, $createdBy]);

    echo json_encode([
        'ok'  => true,
        'url' => $relPath
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}