<?php
// /LKW/kommi/api/finalize.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';
require __DIR__ . '/../inc/board_guard.php';

function out(bool $ok, array $data = [], int $http = 200): void {
  http_response_code($http);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}

function need(bool $cond, string $msg, string $code = 'BAD_REQUEST', int $http = 400): void {
  if (!$cond) out(false, ['error' => $msg, 'code' => $code], $http);
}

function read_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

try {
  need(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST', 'Methode nicht erlaubt.', 'METHOD_NOT_ALLOWED', 405);

  $payload = read_json();
  $orderId = (int)($payload['order_id'] ?? $_POST['order_id'] ?? $_GET['order_id'] ?? 0);

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);

  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin', 'verpacker', 'disposition'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  // WICHTIG: erst nachdem $orderId gesetzt ist
  kommi_require_board_access_json($pdo, $orderId);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // Auftrag locken
  $st = $pdo->prepare("
    SELECT id, status, exit_gate
    FROM kommi_orders
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$orderId]);
  $ord = $st->fetch(PDO::FETCH_ASSOC);

  if (!$ord) {
    $pdo->rollBack();
    out(false, ['error' => 'Auftrag nicht gefunden.', 'code' => 'ORDER_NOT_FOUND'], 404);
  }

  $status   = (string)$ord['status'];
  $exitGate = $ord['exit_gate'] !== null ? (int)$ord['exit_gate'] : 0;

  // Count total + load done
  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id = ?");
  $st->execute([$orderId]);
  $total = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id = ? AND load_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $done = (int)$st->fetchColumn();

  if ($status === 'VERLADEN_OK') {
    // idempotent Erfolg
    $pdo->commit();
    out(true, [
      'order_id' => $orderId,
      'status'   => 'VERLADEN_OK',
      'already'  => true,
      'done'     => $done,
      'total'    => $total
    ], 200);
  }

  need(in_array($status, ['BEREITGESTELLT', 'VERLADUNG'], true), 'Finalize nicht erlaubt in Status: ' . $status, 'STATUS_NOT_ALLOWED', 409);
  need(in_array($exitGate, [1, 2], true), 'Kein Ausgang gesetzt.', 'EXIT_GATE_REQUIRED', 409);
  need($total > 0, 'Keine Reservierungen vorhanden.', 'NO_RESERVATIONS', 409);
  need($done >= $total, "Finalize erst möglich, wenn alle Paletten im Doppelcheck sind ({$done}/{$total}).", 'LOAD_INCOMPLETE', 409);

  // Finalisieren
  $st = $pdo->prepare("
    UPDATE kommi_orders
    SET status = 'VERLADEN_OK'
    WHERE id = ?
  ");
  $st->execute([$orderId]);

  // optionales Event-Log
  $st = $pdo->prepare("
    INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user)
    VALUES (?, 'FINAL', '', 'OK', ?)
  ");
  $st->execute([$orderId, $username]);

  $pdo->commit();

  out(true, [
    'order_id' => $orderId,
    'status'   => 'VERLADEN_OK',
    'done'     => $done,
    'total'    => $total
  ], 200);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('finalize.php: ' . $e->getMessage());
  out(false, [
    'error' => 'finalize fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}
