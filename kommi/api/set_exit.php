<?php
// /LKW/kommi/api/set_exit.php
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

  $payload  = read_json();
  $orderId  = (int)($payload['order_id'] ?? $_POST['order_id'] ?? $_GET['order_id'] ?? 0);
  $exitGate = (int)($payload['exit_gate'] ?? $_POST['exit_gate'] ?? $_GET['exit_gate'] ?? 0);

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  need(in_array($exitGate, [1,2], true), 'exit_gate muss 1 oder 2 sein.', 'INVALID_EXIT_GATE', 422);

  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','disposition','staplerfahrer'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  // Wichtig: Guard erst NACHDEM $orderId bekannt ist
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

  $status  = (string)$ord['status'];
  $oldGate = $ord['exit_gate'] !== null ? (int)$ord['exit_gate'] : 0;

  // Nach Verladung nicht mehr änderbar
  if (in_array($status, ['VERLADUNG','VERLADEN_OK'], true)) {
    $pdo->rollBack();
    out(false, ['error' => 'Ausgang kann in Status '.$status.' nicht mehr geändert werden.', 'code' => 'STATUS_LOCKED'], 409);
  }

  if (!in_array($status, ['OFFEN','KOMMISSIONIERUNG','BEREITGESTELLT'], true)) {
    $pdo->rollBack();
    out(false, ['error' => 'Ausgang setzen nicht erlaubt in Status: '.$status, 'code' => 'STATUS_NOT_ALLOWED'], 409);
  }

  // Prüfen: alle gepickt
  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id = ?");
  $st->execute([$orderId]);
  $total = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id = ? AND pick_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $done = (int)$st->fetchColumn();

  if ($total <= 0) {
    $pdo->rollBack();
    out(false, ['error' => 'Keine Reservierungen vorhanden.', 'code' => 'NO_RESERVATIONS'], 409);
  }

  if ($done < $total) {
    $pdo->rollBack();
    out(false, [
      'error' => "Ausgang erst möglich, wenn alle Paletten gepickt sind ({$done}/{$total}).",
      'code'  => 'PICK_INCOMPLETE',
      'done'  => $done,
      'total' => $total
    ], 409);
  }

  // Ohne provided_at (um 500 wegen fehlender Spalte sicher zu vermeiden)
  $st = $pdo->prepare("
    UPDATE kommi_orders
    SET exit_gate = ?, status = 'BEREITGESTELLT'
    WHERE id = ?
  ");
  $st->execute([$exitGate, $orderId]);

  // Event-Log (falls Tabelle da ist)
  // Falls dir das Probleme macht, kannst du diesen Block notfalls entfernen
  $st = $pdo->prepare("
    INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user)
    VALUES (?, 'EXIT', '', ?, ?)
  ");
  $st->execute([$orderId, 'SET_GATE_'.$exitGate, $username]);

  $pdo->commit();

  out(true, [
    'order_id'   => $orderId,
    'exit_gate'  => $exitGate,
    'changed'    => ($oldGate !== $exitGate),
    'status'     => 'BEREITGESTELLT',
    'pick_done'  => $done,
    'pick_total' => $total
  ], 200);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('set_exit.php: '.$e->getMessage());
  out(false, [
    'error' => 'set_exit fehlgeschlagen.',
    'code'  => 'INTERNAL',
    'debug' => $e->getMessage()
  ], 500);
}
