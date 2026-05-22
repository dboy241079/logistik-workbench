<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2); // .../LKW
require_once $ROOT . '/inc/session.php';
require_once $ROOT . '/api/_db.php';
require_once $ROOT . '/inc/rbac.php';
require_once $ROOT . '/kommi/inc/board_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
error_reporting(E_ALL);

function out(bool $ok, array $data = [], int $http = 200): void {
  http_response_code($http);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function need(bool $c, string $m, string $code = 'BAD_REQUEST', int $http = 400): void {
  if (!$c) out(false, ['error' => $m, 'code' => $code], $http);
}

try {
  // RBAC Tab-Prüfung
  rbac_require_tab_json($pdo, 'outbound');

  $username = (string)($_SESSION['username'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  need($username !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','disposition','staplerfahrer','verpacker'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  $action = (string)($_GET['action'] ?? '');
  need($action === 'detail', 'unknown action', 'UNKNOWN_ACTION', 400);

  // WICHTIG: order_id zuerst sauber ermitteln, DANN guard aufrufen
  $orderId = (int)($_GET['order_id'] ?? 0);
  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);

  // Board-Zugriff prüfen (session + freigeschaltet)
  kommi_require_board_access_json($pdo, $orderId);

  // Order
  $st = $pdo->prepare("SELECT * FROM kommi_orders WHERE id=? LIMIT 1");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  need((bool)$order, 'Auftrag nicht gefunden.', 'ORDER_NOT_FOUND', 404);

  // Lines
  $st = $pdo->prepare("
    SELECT *
    FROM kommi_order_lines
    WHERE order_id=?
    ORDER BY sachnummer
  ");
  $st->execute([$orderId]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);

  // Paletten/Reservierungen
  $st = $pdo->prepare("
    SELECT *
    FROM kommi_reservations
    WHERE order_id=?
    ORDER BY
      zone,
      reihe,
      CAST(platz AS UNSIGNED),
      CAST(slot AS UNSIGNED)
  ");
  $st->execute([$orderId]);
  $pallets = $st->fetchAll(PDO::FETCH_ASSOC);

  // Progress
  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=?");
  $st->execute([$orderId]);
  $pickTotal = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=? AND pick_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $pickDone = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM kommi_reservations WHERE order_id=? AND load_scanned_at IS NOT NULL");
  $st->execute([$orderId]);
  $loadDone = (int)$st->fetchColumn();

  $progress = [
    'pick_done'  => $pickDone,
    'pick_total' => $pickTotal,
    'load_done'  => $loadDone,
    'load_total' => $pickTotal
  ];

  out(true, [
    'order'    => $order,
    'lines'    => $lines,
    'pallets'  => $pallets,
    'progress' => $progress
  ], 200);

} catch (Throwable $e) {
  error_log('kommi_api.php fatal: ' . $e->getMessage());
  out(false, ['error' => 'Interner Serverfehler.', 'code' => 'INTERNAL'], 500);
}
