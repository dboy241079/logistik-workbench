<?php
declare(strict_types=1);

require __DIR__ . '/_kommi_bootstrap.php';

try {
    rbac_require_tab_json($pdo, 'outbound');

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('Ungültige order_id.');
    }

    $stmt = $pdo->prepare("
        UPDATE kommi_orders
        SET
            assigned_loader = NULL,
            loaded_signed_at = NULL,
            loaded_signature_path = NULL,
            loaded_signature_name = NULL
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);

    echo json_encode([
        'ok' => true
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}