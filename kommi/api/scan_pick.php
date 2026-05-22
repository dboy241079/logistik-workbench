<?php
// /LKW/kommi/api/scan_pick.php
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
function need(bool $c, string $m, string $code = 'BAD_REQUEST', int $http = 400): void {
  if (!$c) out(false, ['error' => $m, 'code' => $code], $http);
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
  $refNo   = (string)($payload['ref_no'] ?? $_POST['ref_no'] ?? $_GET['ref_no'] ?? '');
  $refNo   = preg_replace('/\s+/', '', trim($refNo ?? ''));

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  need($refNo !== '', 'ref_no fehlt.', 'MISSING_REF_NO', 422);

  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');

  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','staplerfahrer','disposition'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  // WICHTIG: erst jetzt, nachdem $orderId gesetzt ist
  kommi_require_board_access_json($pdo, $orderId);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT status FROM kommi_orders WHERE id=? LIMIT 1 FOR UPDATE");
  $st->execute([$orderId]);
  $status = (string)($st->fetchColumn() ?: '');
  if ($status === '') {
    $pdo->rollBack();
    out(false, ['error' => 'Auftrag nicht gefunden.', 'code' => 'ORDER_NOT_FOUND'], 404);
  }
  if (!in_array($status, ['OFFEN','KOMMISSIONIERUNG'], true)) {
    $pdo->rollBack();
    out(false, ['error' => 'PICK nicht erlaubt in Status: '.$status, 'code' => 'STATUS_NOT_ALLOWED'], 409);
  }

  $st = $pdo->prepare("
    SELECT id, pick_scanned_at
    FROM kommi_reservations
    WHERE order_id=? AND ref_no=?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$orderId, $refNo]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $pdo->prepare("INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user) VALUES (?,?,?,?,?)")
        ->execute([$orderId, 'PICK', $refNo, 'NOT_IN_ORDER', $username]);
    $pdo->commit();
    out(false, ['error' => 'Diese Palette gehört nicht zu diesem Auftrag.', 'code' => 'NOT_IN_ORDER'], 422);
  }

  if (!empty($row['pick_scanned_at'])) {
    $pdo->prepare("INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user) VALUES (?,?,?,?,?)")
        ->execute([$orderId, 'PICK', $refNo, 'DUP', $username]);

    $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=?");
    $st->execute([$orderId]);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=? AND pick_scanned_at IS NOT NULL");
    $st->execute([$orderId]);
    $done = (int)$st->fetchColumn();

    $pdo->commit();
    out(true, ['dup' => true, 'done' => $done, 'total' => $total, 'msg' => 'Schon gepickt.']);
  }

  $pdo->prepare("UPDATE kommi_reservations SET pick_scanned_at=NOW(), pick_scanned_by=? WHERE id=?")
      ->execute([$username, (int)$row['id']]);

  $pdo->prepare("INSERT INTO kommi_scan_events (order_id, phase, ref_no, result, user) VALUES (?,?,?,?,?)")
      ->execute([$orderId, 'PICK', $refNo, 'OK', $username]);

  if ($status === 'OFFEN') {
    // WICHTIG:
    // assigned_picker NICHT hier setzen/überschreiben!
    // assigned_picker wird ausschließlich in verify_user_code.php (Phase PREPARER) gesetzt.
    $pdo->prepare("
      UPDATE kommi_orders
      SET status='KOMMISSIONIERUNG',
          picked_at=COALESCE(picked_at, NOW())
      WHERE id=?
    ")->execute([$orderId]);
  }

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=?");
  $st->execute([$orderId]);
  $total = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=? AND pick_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $done = (int)$st->fetchColumn();

  $pdo->commit();
  out(true, ['ok_scan' => true, 'done' => $done, 'total' => $total]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('scan_pick.php: ' . $e->getMessage());
  out(false, ['error' => 'scan_pick fehlgeschlagen.', 'code' => 'INTERNAL', 'debug' => $e->getMessage()], 500);
}