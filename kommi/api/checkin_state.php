<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';

function out(bool $ok, array $data = [], int $http = 200): void {
  http_response_code($http);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function need(bool $cond, string $msg, string $code='BAD_REQUEST', int $http=400): void {
  if (!$cond) out(false, ['error' => $msg, 'code' => $code], $http);
}

try {
  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','disposition','staplerfahrer','verpacker'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  $orderId = (int)($_GET['order_id'] ?? 0);
  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);

  $st = $pdo->prepare("
    SELECT id, status, exit_gate, assigned_picker, assigned_loader
    FROM kommi_orders
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$orderId]);
  $ord = $st->fetch(PDO::FETCH_ASSOC);
  need((bool)$ord, 'Auftrag nicht gefunden.', 'ORDER_NOT_FOUND', 404);

  $status = (string)$ord['status'];
  $exitGate = $ord['exit_gate'] !== null ? (int)$ord['exit_gate'] : 0;

  $assignedPicker = trim((string)($ord['assigned_picker'] ?? ''));
  $assignedLoader = trim((string)($ord['assigned_loader'] ?? ''));

  // Pick Fortschritt (für Info/Checks)
  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=?");
  $st->execute([$orderId]);
  $pickTotal = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=? AND pick_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $pickDone = (int)$st->fetchColumn();

  // Session-Challenge (nur relevant in PREP)
  $k = (string)$orderId;
  $sessionChallengeOk = isset($_SESSION['kommi_checkin'][$k]['challenge_ok']) && (int)$_SESSION['kommi_checkin'][$k]['challenge_ok'] === 1;

  // Mode
  $u = strtoupper($status);
  if (in_array($u, ['OFFEN','KOMMISSIONIERUNG'], true)) $mode = 'PREP';
  elseif (in_array($u, ['BEREITGESTELLT','VERLADUNG'], true)) $mode = 'LOAD';
  elseif ($u === 'VERLADEN_OK') $mode = 'DONE';
  else $mode = 'PREP';

  // Steps
  $step2Ok = ($assignedPicker !== '');
  $step3Ok = ($assignedLoader !== '');
  $step1Ok = $sessionChallengeOk || $step2Ok; // wenn Step2 existiert, war Step1 praktisch schon ok

  out(true, [
    'order_id'      => $orderId,
    'status'        => $status,
    'mode'          => $mode,
    'exit_gate'     => $exitGate,
    'pick_done'     => $pickDone,
    'pick_total'    => $pickTotal,

    'step1_ok'      => $step1Ok,
    'step2_ok'      => $step2Ok,
    'step3_ok'      => $step3Ok,

    'preparer_user' => $assignedPicker,
    'loader_user'   => $assignedLoader,
  ], 200);

} catch (Throwable $e) {
  error_log('checkin_state.php: ' . $e->getMessage());
  out(false, [
    'error' => 'checkin_state fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}