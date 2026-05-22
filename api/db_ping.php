<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_db.php';

try {
    $stmt = $pdo->query("SELECT NOW() AS serverzeit, DATABASE() AS datenbank");
    $row = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Datenbankverbindung läuft',
        'data' => $row,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Datenbankfehler',
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}