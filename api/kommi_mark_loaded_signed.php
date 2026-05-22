<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $role = (string)($_SESSION['role'] ?? '');
  if (!in_array($role, ['admin','disposition','staplerfahrer','verpacker'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $raw = file_get_contents('php://input');
  $in  = json_decode($raw ?: '', true);

  if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $orderId   = (int)($in['order_id'] ?? 0);
  $signature = (string)($in['signature'] ?? '');
  $username  = trim((string)($_SESSION['username'] ?? ''));

  if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order_id_invalid'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($signature === '' || !str_starts_with($signature, 'data:image/png;base64,')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'signature_missing_or_invalid'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $base64 = substr($signature, strlen('data:image/png;base64,'));
  $bin = base64_decode($base64, true);

  if ($bin === false || strlen($bin) < 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'signature_decode_failed'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $dirAbs = dirname(__DIR__) . '/uploads/signatures/loaded';
  if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
    throw new RuntimeException('Konnte Signaturordner nicht erstellen.');
  }

  $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username ?: 'unknown');
  $fileName = sprintf('loaded_order_%d_%s_%s.png', $orderId, $safeUser, date('Ymd_His'));

  $fileAbs = $dirAbs . '/' . $fileName;
  $fileRel = '/uploads/signatures/loaded/' . $fileName;

  if (file_put_contents($fileAbs, $bin) === false) {
    throw new RuntimeException('Signaturdatei konnte nicht gespeichert werden.');
  }

  $st = $pdo->prepare("SELECT id FROM kommi_orders WHERE id = ? LIMIT 1");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);

  if (!$order) {
    @unlink($fileAbs);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'order_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // assigned_loader setzen, falls leer
  $st = $pdo->prepare("
    UPDATE kommi_orders
    SET
      assigned_loader = COALESCE(NULLIF(assigned_loader, ''), ?),
      loaded_signed_at = NOW(),
      loaded_signature_path = ?,
      loaded_signature_name = ?
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([
    $username,
    $fileRel,
    $username,
    $orderId
  ]);

  echo json_encode([
    'ok' => true,
    'order_id' => $orderId,
    'signature_path' => $fileRel
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'server_error',
    'message' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}