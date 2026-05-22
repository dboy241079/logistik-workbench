<?php
declare(strict_types=1);
require __DIR__ . '/_kommi_bootstrap.php';

try {
  $debug = [
    'username' => $_SESSION['username'] ?? null,
    'role'     => $_SESSION['role'] ?? null,
  ];

  try {
    kommi_api_require_login(['admin','disposition','staplerfahrer','verpacker']);
  } catch (Throwable $e) {
    http_response_code(403);
    echo json_encode([
      'ok' => false,
      'step' => 'require_login',
      'error' => $e->getMessage(),
      'debug' => $debug,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  try {
    kommi_api_require_outbound_tab($pdo);
  } catch (Throwable $e) {
    http_response_code(403);
    echo json_encode([
      'ok' => false,
      'step' => 'require_outbound_tab',
      'error' => $e->getMessage(),
      'debug' => $debug,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $st = $pdo->query("
    SELECT
      o.id,
      o.order_no,
      o.source_ausgang_nr,
      o.status,
      o.exit_gate,
      COUNT(r.id) AS total,
      SUM(CASE WHEN r.pick_scanned_at IS NOT NULL THEN 1 ELSE 0 END) AS pick_done,
      SUM(CASE WHEN r.load_scanned_at IS NOT NULL THEN 1 ELSE 0 END) AS load_done
    FROM kommi_orders o
    LEFT JOIN kommi_reservations r ON r.order_id = o.id
    WHERE o.status IN ('OFFEN','KOMMISSIONIERUNG','BEREITGESTELLT','VERLADUNG')
    GROUP BY o.id, o.order_no, o.source_ausgang_nr, o.status, o.exit_gate
    ORDER BY o.id DESC
    LIMIT 300
  ");

  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  kommi_api_out(true, ['items' => $items]);

} catch (Throwable $e) {
  kommi_api_log_error($e, 'orders_list.php');
  kommi_api_out(false, ['error' => $e->getMessage(), 'code' => 'INTERNAL'], 500);
}