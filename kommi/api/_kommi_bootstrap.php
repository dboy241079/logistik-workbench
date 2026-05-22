<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$ROOT = dirname(__DIR__, 2); // /LKW
require_once $ROOT . '/inc/session.php';
require_once $ROOT . '/api/_db.php';
require_once $ROOT . '/inc/rbac.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

final class KommiApiException extends RuntimeException {
  public string $apiCode;
  public int $httpStatus;

  public function __construct(string $message, string $apiCode = 'BAD_REQUEST', int $httpStatus = 400) {
    parent::__construct($message);
    $this->apiCode = $apiCode;
    $this->httpStatus = $httpStatus;
  }
}

function kommi_api_out(bool $ok, array $data = [], int $http = 200): void {
  http_response_code($http);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function kommi_api_need(bool $cond, string $message, string $code = 'BAD_REQUEST', int $http = 400): void {
  if (!$cond) {
    throw new KommiApiException($message, $code, $http);
  }
}

function kommi_api_require_method(string $method): void {
  $got = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $exp = strtoupper($method);
  if ($got !== $exp) {
    throw new KommiApiException('Methode nicht erlaubt.', 'METHOD_NOT_ALLOWED', 405);
  }
}

function kommi_api_read_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new KommiApiException('Ungültiges JSON.', 'BAD_JSON', 400);
  }
  return $data;
}

function kommi_api_require_login(array $rolesAllowed): array {
  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  kommi_api_need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  kommi_api_need(in_array($role, $rolesAllowed, true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  return [$username, $role];
}

function kommi_api_require_outbound_tab(PDO $pdo): void {
  if (function_exists('rbac_require_tab_json')) {
    // bestehende RBAC-Logik nutzen
    rbac_require_tab_json($pdo, 'outbound');
  }
}

function kommi_api_log_error(Throwable $e, string $context = ''): void {
  $prefix = $context !== '' ? $context . ' ' : '';
  error_log($prefix . $e->getMessage());
}
