<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__, 2); // .../LKW
require_once $ROOT . '/inc/session.php';
require_once $ROOT . '/api/_db.php';

$rbacFile = $ROOT . '/inc/rbac.php';
if (is_file($rbacFile)) {
  require_once $rbacFile;
}

final class ApiException extends RuntimeException {
  public string $apiCode;
  public int $httpCode;

  public function __construct(string $message, string $apiCode = 'BAD_REQUEST', int $httpCode = 400) {
    parent::__construct($message);
    $this->apiCode = $apiCode;
    $this->httpCode = $httpCode;
  }
}

function out(bool $ok, array $data = [], int $httpCode = 200): void {
  http_response_code($httpCode);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function need(bool $cond, string $message, string $code = 'BAD_REQUEST', int $http = 400): void {
  if (!$cond) {
    throw new ApiException($message, $code, $http);
  }
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];

  $j = json_decode($raw, true);
  if (!is_array($j)) {
    throw new ApiException('Ungültiges JSON.', 'BAD_JSON', 400);
  }
  return $j;
}

/**
 * 16-stelliger numerischer Scan-Code (scannerfreundlich)
 */
function makeScanCode16(): string {
  $s = '';
  for ($i = 0; $i < 16; $i++) {
    $s .= (string)random_int(0, 9);
  }
  return $s;
}

/**
 * Tabelle + fehlende Altspalten robust sicherstellen
 */
function ensureChallengeSchema(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kommi_order_challenges (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      challenge_token VARCHAR(64) NOT NULL,
      qr_payload VARCHAR(255) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      used_by VARCHAR(100) NULL,
      created_by VARCHAR(100) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_challenge_token (challenge_token),
      KEY idx_order_expires (order_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $cols = [];
  $st = $pdo->query("SHOW COLUMNS FROM kommi_order_challenges");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[(string)$row['Field']] = true;
  }

  if (!isset($cols['qr_payload'])) {
    $pdo->exec("ALTER TABLE kommi_order_challenges ADD COLUMN qr_payload VARCHAR(255) NOT NULL AFTER challenge_token");
  }

  if (!isset($cols['used_by'])) {
    $pdo->exec("ALTER TABLE kommi_order_challenges ADD COLUMN used_by VARCHAR(100) NULL AFTER used_at");
  }

  if (!isset($cols['created_by'])) {
    $pdo->exec("ALTER TABLE kommi_order_challenges ADD COLUMN created_by VARCHAR(100) NOT NULL DEFAULT '' AFTER used_by");
  }

  // Index optional robust nachziehen
  $hasUnique = false;
  $ix = $pdo->query("SHOW INDEX FROM kommi_order_challenges");
  foreach ($ix->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ((string)$r['Key_name'] === 'uq_challenge_token') {
      $hasUnique = true;
      break;
    }
  }
  if (!$hasUnique) {
    $pdo->exec("ALTER TABLE kommi_order_challenges ADD UNIQUE KEY uq_challenge_token (challenge_token)");
  }
}

try {
  need(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST', 'Methode nicht erlaubt.', 'METHOD_NOT_ALLOWED', 405);

  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','disposition','staplerfahrer','verpacker'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  if (function_exists('rbac_require_tab_json')) {
    rbac_require_tab_json($pdo, 'outbound');
  }

  $payload = readJsonBody();
  $orderId = (int)($payload['order_id'] ?? 0);
  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // DDL vor Transaktion
  ensureChallengeSchema($pdo);

  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id, status FROM kommi_orders WHERE id = ? LIMIT 1 FOR UPDATE");
  $st->execute([$orderId]);
  $ord = $st->fetch(PDO::FETCH_ASSOC);

  need((bool)$ord, 'Auftrag nicht gefunden.', 'ORDER_NOT_FOUND', 404);

  $status = (string)$ord['status'];
  need(
    in_array($status, ['OFFEN','KOMMISSIONIERUNG','BEREITGESTELLT','VERLADUNG'], true),
    'Auftrag nicht aktiv.',
    'ORDER_NOT_ACTIVE',
    409
  );

  // alte aktive Challenges für diesen Auftrag entwerten
  $st = $pdo->prepare("
    UPDATE kommi_order_challenges
    SET used_at = NOW(), used_by = ?
    WHERE order_id = ?
      AND used_at IS NULL
      AND expires_at > NOW()
  ");
  $st->execute([$username, $orderId]);

  $scanCode = makeScanCode16(); // <= hier dein scannerfreundlicher Code
  $qrPayload = 'KOMMI|ORDER|' . $orderId . '|' . $scanCode; // Fallback-String

  $st = $pdo->prepare("
    INSERT INTO kommi_order_challenges
      (order_id, challenge_token, qr_payload, expires_at, created_by)
    VALUES
      (?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 SECOND), ?)
  ");
  $st->execute([$orderId, $scanCode, $qrPayload, $username]);

  $pdo->commit();

  out(true, [
    'order_id'   => $orderId,
    'status'     => $status,
    'scan_code'  => $scanCode,   // <-- WICHTIG für checkin.js / Barcode
    'qr_payload' => $qrPayload,  // optional
    'expires_in' => 90
  ]);

} catch (ApiException $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  out(false, ['error' => $e->getMessage(), 'code' => $e->apiCode], $e->httpCode);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('create_challenge.php: ' . $e->getMessage());

  out(false, [
    'error' => 'create_challenge fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}
