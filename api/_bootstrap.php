<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_GET['debug']) && (($_SESSION['role'] ?? '') === 'admin')) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

function api_ok(array $data = []): void {
  echo json_encode(['ok'=>true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function api_err(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}
