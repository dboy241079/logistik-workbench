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

function need(bool $cond, string $msg, string $code='BAD_REQUEST', int $http=400): void {
  if (!$cond) throw new ApiException($msg, $code, $http);
}

function readJsonBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $j = json_decode($raw, true);
  if (!is_array($j)) throw new ApiException('Ungültiges JSON.', 'BAD_JSON', 400);
  return $j;
}

function sessionChallengeOk(int $orderId): bool {
  $k = (string)$orderId;
  return isset($_SESSION['kommi_checkin'][$k]['challenge_ok']) && (int)$_SESSION['kommi_checkin'][$k]['challenge_ok'] === 1;
}

function setSessionCheckin(int $orderId, string $key, $value): void {
  $k = (string)$orderId;
  if (!isset($_SESSION['kommi_checkin'][$k]) || !is_array($_SESSION['kommi_checkin'][$k])) {
    $_SESSION['kommi_checkin'][$k] = [];
  }
  $_SESSION['kommi_checkin'][$k][$key] = $value;
}

function safeLogEvent(PDO $pdo, int $orderId, string $result, string $refNo, string $sessionUser): void {
  try {
    $st = $pdo->prepare("
      INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user)
      VALUES (?, 'AUTH', ?, ?, ?)
    ");
    $st->execute([$orderId, $refNo, $result, $sessionUser]);
  } catch (Throwable $e) {
    error_log('verify_user_code.php log warning: ' . $e->getMessage());
  }
}

try {
  need(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST', 'Methode nicht erlaubt.', 'METHOD_NOT_ALLOWED', 405);

  $sessionUser = (string)($_SESSION['username'] ?? '');
  $sessionRole = (string)($_SESSION['role'] ?? '');

  need($sessionUser !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($sessionRole, ['admin','disposition','staplerfahrer','verpacker'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  if (function_exists('rbac_require_tab_json')) {
    rbac_require_tab_json($pdo, 'outbound');
  }

  $payload = readJsonBody();

  $orderId = (int)($payload['order_id'] ?? 0);
  $phase   = strtoupper(trim((string)($payload['phase'] ?? '')));
  $scanRaw = trim((string)($payload['scan'] ?? ''));

  // robust: Scanner-Müll raus, nur Ziffern
  $personalNo = preg_replace('/\D+/', '', $scanRaw);

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  need(in_array($phase, ['PREPARER','LOADER'], true), 'phase ungültig.', 'INVALID_PHASE', 422);
  need($personalNo !== '' && preg_match('/^\d{3,32}$/', $personalNo) === 1, 'Personalnummer ungültig.', 'INVALID_PERSONAL_NO', 422);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // Auftrag locken (Status + ExitGate brauchen wir für Mode)
  $st = $pdo->prepare("
    SELECT id, status, exit_gate, assigned_picker, assigned_loader
    FROM kommi_orders
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$orderId]);
  $ord = $st->fetch(PDO::FETCH_ASSOC);
  need((bool)$ord, 'Auftrag nicht gefunden.', 'ORDER_NOT_FOUND', 404);

  $status = strtoupper(trim((string)($ord['status'] ?? '')));
  $exitGate = $ord['exit_gate'] !== null ? (int)$ord['exit_gate'] : 0;

  $assignedPicker = trim((string)($ord['assigned_picker'] ?? ''));
  $assignedLoader = trim((string)($ord['assigned_loader'] ?? ''));

  // =========================
  // MODE-REGELN (dein Flow)
  // PREP: OFFEN/KOMMISSIONIERUNG -> Step1+2
  // LOAD: BEREITGESTELLT/VERLADUNG -> Step3
  // =========================

  // PREPARER: wenn bereits gesetzt -> immer "already_done" (auch wenn Status später schon weiter)
  if ($phase === 'PREPARER' && $assignedPicker !== '') {
    setSessionCheckin($orderId, 'preparer_ok', 1);
    setSessionCheckin($orderId, 'preparer_user', $assignedPicker);

    $st = $pdo->prepare("SELECT username, display_name, role FROM users WHERE username = ? LIMIT 1");
    $st->execute([$assignedPicker]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: ['username' => $assignedPicker, 'display_name' => $assignedPicker, 'role' => ''];

    $pdo->commit();
    out(true, [
      'order_id'      => $orderId,
      'phase'         => 'PREPARER',
      'already_done'  => true,
      'username'      => (string)$u['username'],
      'display_name'  => (string)$u['display_name'],
      'role'          => (string)$u['role'],
      'status'        => $status,
      'exit_gate'     => $exitGate
    ], 200);
  }

  // LOADER: Schritt 3 nur nach Bereitstellung!
  if ($phase === 'LOADER') {
    // Step2 muss gesetzt sein
    need($assignedPicker !== '', 'Schritt 2 (Bereitsteller) ist noch nicht abgeschlossen.', 'PREPARER_REQUIRED', 409);

    // Nur LOAD-Phase (nach Ausgang/ Bereitstellung)
    need(in_array($status, ['BEREITGESTELLT','VERLADUNG'], true),
      'Schritt 3 erst nach Bereitstellung möglich (Status: '.$status.').',
      'WRONG_STATUS',
      409
    );

    need(in_array($exitGate, [1,2], true),
      'Kein Ausgang gesetzt (Bereitstellung fehlt).',
      'EXIT_REQUIRED',
      409
    );

    // Wenn loader schon gesetzt -> already_done
    if ($assignedLoader !== '') {
      setSessionCheckin($orderId, 'loader_ok', 1);
      setSessionCheckin($orderId, 'loader_user', $assignedLoader);

      $st = $pdo->prepare("SELECT username, display_name, role FROM users WHERE username = ? LIMIT 1");
      $st->execute([$assignedLoader]);
      $u = $st->fetch(PDO::FETCH_ASSOC) ?: ['username' => $assignedLoader, 'display_name' => $assignedLoader, 'role' => ''];

      $pdo->commit();
      out(true, [
        'order_id'      => $orderId,
        'phase'         => 'LOADER',
        'already_done'  => true,
        'username'      => (string)$u['username'],
        'display_name'  => (string)$u['display_name'],
        'role'          => (string)$u['role'],
        'status'        => $status,
        'exit_gate'     => $exitGate,
        'preparer_user' => $assignedPicker
      ], 200);
    }
  }

  // PREPARER: darf nur in PREP-Phase und braucht Challenge
  if ($phase === 'PREPARER') {
    need(in_array($status, ['OFFEN','KOMMISSIONIERUNG'], true),
      'Schritt 2 nur vor Bereitstellung möglich (Status: '.$status.').',
      'WRONG_STATUS',
      409
    );

    need(sessionChallengeOk($orderId),
      'Step 1 nicht abgeschlossen (Challenge fehlt).',
      'CHALLENGE_REQUIRED',
      403
    );
  }

  // LOADER: hier sind wir nur, wenn assignedLoader leer war -> Status-Regeln wurden oben bereits geprüft

  // User über personal_no finden
  $st = $pdo->prepare("
    SELECT id, username, display_name, role, active, archived_at, deleted_at
    FROM users
    WHERE personal_no = ?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$personalNo]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  need((bool)$u, 'Personalnummer nicht gefunden.', 'PERSON_NOT_FOUND', 404);

  need((int)$u['active'] === 1, 'Benutzer ist inaktiv.', 'USER_INACTIVE', 403);
  need($u['archived_at'] === null, 'Benutzer ist archiviert.', 'USER_ARCHIVED', 403);
  need($u['deleted_at'] === null, 'Benutzer ist gelöscht.', 'USER_DELETED', 403);

  $foundUser = (string)$u['username'];
  $foundName = (string)$u['display_name'];
  $foundRole = (string)$u['role'];

  if ($phase === 'PREPARER') {
    need(in_array($foundRole, ['admin','disposition','staplerfahrer'], true), 'Rolle nicht als Bereitsteller erlaubt.', 'ROLE_NOT_ALLOWED', 403);

    $st = $pdo->prepare("UPDATE kommi_orders SET assigned_picker = ? WHERE id = ?");
    $st->execute([$foundUser, $orderId]);

    setSessionCheckin($orderId, 'preparer_ok', 1);
    setSessionCheckin($orderId, 'preparer_user', $foundUser);
    setSessionCheckin($orderId, 'preparer_name', $foundName);
    setSessionCheckin($orderId, 'preparer_at', time());

    safeLogEvent($pdo, $orderId, 'PREPARER_OK', $personalNo, $sessionUser);
  }

  if ($phase === 'LOADER') {
    need(in_array($foundRole, ['admin','disposition','verpacker','staplerfahrer'], true), 'Rolle nicht als Verlader erlaubt.', 'ROLE_NOT_ALLOWED', 403);

    $st = $pdo->prepare("UPDATE kommi_orders SET assigned_loader = ? WHERE id = ?");
    $st->execute([$foundUser, $orderId]);

    setSessionCheckin($orderId, 'loader_ok', 1);
    setSessionCheckin($orderId, 'loader_user', $foundUser);
    setSessionCheckin($orderId, 'loader_name', $foundName);
    setSessionCheckin($orderId, 'loader_at', time());

    safeLogEvent($pdo, $orderId, 'LOADER_OK', $personalNo, $sessionUser);
  }

  $pdo->commit();

  out(true, [
    'order_id'     => $orderId,
    'phase'        => $phase,
    'already_done' => false,
    'username'     => $foundUser,
    'display_name' => $foundName,
    'role'         => $foundRole,
    'status'       => $status,
    'exit_gate'    => $exitGate,
    'preparer_user'=> ($phase === 'LOADER') ? $assignedPicker : ($phase === 'PREPARER' ? $foundUser : $assignedPicker)
  ], 200);

} catch (ApiException $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  out(false, ['error' => $e->getMessage(), 'code' => $e->apiCode], $e->httpCode);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('verify_user_code.php: ' . $e->getMessage());
  out(false, [
    'error' => 'verify_user_code fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}