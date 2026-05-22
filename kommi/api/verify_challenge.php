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
  if (!$cond) throw new ApiException($message, $code, $http);
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $j = json_decode($raw, true);
  if (!is_array($j)) throw new ApiException('Ungültiges JSON.', 'BAD_JSON', 400);
  return $j;
}

function startsWith(string $haystack, string $needle): bool {
  return substr($haystack, 0, strlen($needle)) === $needle;
}

/**
 * Erlaubte Scanformate:
 * 1) 16-stellig numerisch (neuer Scanner-Code)
 * 2) 32-hex (alt)
 * 3) KOMMI|ORDER|{order_id}|{token} (alt/Fallback)
 */
function extractTokenFromScan(string $scan, int $orderId): string {
  $s = trim($scan);
  if ($s === '') return '';

  if (preg_match('/^\d{16}$/', $s)) return $s;                  // neu
  if (preg_match('/^[a-f0-9]{32}$/i', $s)) return strtolower($s); // alt hex

  if (startsWith($s, 'KOMMI|ORDER|')) {
    $parts = explode('|', $s);
    if (count($parts) >= 4) {
      $oid = (int)($parts[2] ?? 0);
      $tok = trim((string)($parts[3] ?? ''));
      if ($oid === $orderId && $tok !== '') return $tok;
    }
  }

  return '';
}

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
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[(string)$c['Field']] = true;
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
  $scanRaw = trim((string)($payload['scan'] ?? ''));

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  need($scanRaw !== '', 'Scan fehlt.', 'MISSING_SCAN', 400);

  // Bereits verifiziert? -> idempotent OK zurück (verhindert Doppelklick-Fehler)
  $sessKey = (string)$orderId;
  if (
    isset($_SESSION['kommi_checkin'][$sessKey]['challenge_ok']) &&
    (int)$_SESSION['kommi_checkin'][$sessKey]['challenge_ok'] === 1
  ) {
    out(true, [
      'order_id' => $orderId,
      'verified' => true,
      'by'       => (string)($_SESSION['kommi_checkin'][$sessKey]['challenge_by'] ?? $username),
      'already'  => true
    ], 200);
  }

  $token = extractTokenFromScan($scanRaw, $orderId);
  need($token !== '', 'Scanformat ungültig.', 'INVALID_SCAN_FORMAT', 422);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  ensureChallengeSchema($pdo);

  $pdo->beginTransaction();

  // Auftrag prüfen
  $st = $pdo->prepare("SELECT id FROM kommi_orders WHERE id=? LIMIT 1 FOR UPDATE");
  $st->execute([$orderId]);
  $ord = $st->fetch(PDO::FETCH_ASSOC);
  need((bool)$ord, 'Auftrag nicht gefunden.', 'ORDER_NOT_FOUND', 404);

  // Challenge per Token laden (nicht nur "aktive"), damit wir idempotent reagieren können
  $st = $pdo->prepare("
    SELECT id, challenge_token, expires_at, used_at, used_by
    FROM kommi_order_challenges
    WHERE order_id = ? AND challenge_token = ?
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$orderId, $token]);
  $ch = $st->fetch(PDO::FETCH_ASSOC);

  need((bool)$ch, 'Challenge ungültig.', 'CHALLENGE_MISMATCH', 422);

  $usedAt = (string)($ch['used_at'] ?? '');
  $usedBy = (string)($ch['used_by'] ?? '');

  // Schon benutzt?
  if ($usedAt !== '') {
    // Wenn vom selben User vor kurzem benutzt -> idempotent als OK
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS age_sec");
    $st->execute([$usedAt]);
    $age = (int)($st->fetchColumn() ?? 99999);

    if ($usedBy === $username && $age <= 120) {
      $_SESSION['kommi_checkin'][$sessKey] = array_merge(
        (array)($_SESSION['kommi_checkin'][$sessKey] ?? []),
        [
          'challenge_ok' => 1,
          'challenge_at' => time(),
          'challenge_by' => $username
        ]
      );
      $pdo->commit();
      out(true, [
        'order_id' => $orderId,
        'verified' => true,
        'by'       => $username,
        'already'  => true
      ], 200);
    }

    need(false, 'Challenge wurde bereits verwendet.', 'CHALLENGE_ALREADY_USED', 422);
  }

  // Ablauf prüfen
  $st = $pdo->prepare("SELECT CASE WHEN NOW() <= ? THEN 1 ELSE 0 END");
  $st->execute([(string)$ch['expires_at']]);
  $notExpired = (int)$st->fetchColumn() === 1;
  need($notExpired, 'Challenge abgelaufen.', 'CHALLENGE_EXPIRED', 422);

  // als verwendet markieren
  $st = $pdo->prepare("
    UPDATE kommi_order_challenges
    SET used_at = NOW(), used_by = ?
    WHERE id = ? AND used_at IS NULL
  ");
  $st->execute([$username, (int)$ch['id']]);

  $_SESSION['kommi_checkin'][$sessKey] = array_merge(
    (array)($_SESSION['kommi_checkin'][$sessKey] ?? []),
    [
      'challenge_ok' => 1,
      'challenge_at' => time(),
      'challenge_by' => $username
    ]
  );

  $pdo->commit();

  out(true, [
    'order_id' => $orderId,
    'verified' => true,
    'by'       => $username
  ], 200);

} catch (ApiException $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  out(false, ['error' => $e->getMessage(), 'code' => $e->apiCode], $e->httpCode);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('verify_challenge.php: ' . $e->getMessage());
  out(false, [
    'error' => 'verify_challenge fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}
