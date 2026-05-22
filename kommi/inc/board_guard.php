<?php
declare(strict_types=1);

function kommi_find_board_access(PDO $pdo, int $orderId, string $sessionId): ?array {
  $st = $pdo->prepare("
    SELECT id, order_id, granted_to, preparer_user, loader_user, granted_at, valid_until
    FROM kommi_board_access
    WHERE order_id = ?
      AND session_id = ?
      AND revoked_at IS NULL
      AND valid_until > NOW()
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$orderId, $sessionId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function kommi_has_board_access(PDO $pdo, int $orderId): bool {
  if ($orderId <= 0) return false;
  $sid = session_id();
  if ($sid === '') return false;
  return kommi_find_board_access($pdo, $orderId, $sid) !== null;
}

function kommi_require_board_access(PDO $pdo, int $orderId): void {
  if ($orderId <= 0) {
    header('Location: /kommi/orders.php?embed=1');
    exit;
  }

  $sid = session_id();
  $row = kommi_find_board_access($pdo, $orderId, $sid);

  if (!$row) {
    header('Location: /kommi/checkin.php?order_id=' . urlencode((string)$orderId) . '&embed=1');
    exit;
  }

  if (!isset($_SESSION['kommi_board_ctx']) || !is_array($_SESSION['kommi_board_ctx'])) {
    $_SESSION['kommi_board_ctx'] = [];
  }

  $_SESSION['kommi_board_ctx'][(string)$orderId] = [
    'granted_to'    => (string)$row['granted_to'],
    'preparer_user' => (string)$row['preparer_user'],
    'loader_user'   => (string)$row['loader_user'],
    'granted_at'    => (string)$row['granted_at'],
    'valid_until'   => (string)$row['valid_until'],
  ];
}

function kommi_require_board_access_json(PDO $pdo, int $orderId): void {
  header('Content-Type: application/json; charset=utf-8');

  if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'order_id fehlt.',
      'code' => 'MISSING_ORDER_ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!kommi_has_board_access($pdo, $orderId)) {
    http_response_code(403);
    echo json_encode([
      'ok' => false,
      'error' => 'Board-Zugriff nicht freigeschaltet.',
      'code' => 'BOARD_ACCESS_REQUIRED'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
