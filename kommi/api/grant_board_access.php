<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors','0');
error_reporting(E_ALL);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';

function out(bool $ok, array $data=[], int $http=200): void {
  http_response_code($http);
  echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function need(bool $c, string $m, string $code='BAD_REQUEST', int $http=400): void {
  if(!$c) out(false, ['error'=>$m, 'code'=>$code], $http);
}
function read_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw)==='') return [];
  $j = json_decode($raw,true);
  return is_array($j) ? $j : [];
}

try {
  need(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST', 'Methode nicht erlaubt.', 'METHOD_NOT_ALLOWED', 405);

  $loginUser = (string)($_SESSION['username'] ?? '');
  $role      = (string)($_SESSION['role'] ?? '');

  need($loginUser !== '', 'Nicht eingeloggt.', 'UNAUTHORIZED', 401);
  need(in_array($role, ['admin','disposition','staplerfahrer','verpacker'], true), 'Keine Berechtigung.', 'FORBIDDEN', 403);

  $p = read_json();
  $orderId = (int)($p['order_id'] ?? 0);
  $mode    = strtoupper(trim((string)($p['mode'] ?? 'AUTO')));

  need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  need(in_array($mode, ['AUTO','PREP','LOAD'], true), 'mode ungültig.', 'INVALID_MODE', 422);

  $sid = session_id();
  need($sid !== '', 'Session ungültig.', 'SESSION_INVALID', 500);

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

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

  $status   = strtoupper(trim((string)$ord['status']));
  $exitGate = $ord['exit_gate'] !== null ? (int)$ord['exit_gate'] : 0;
  $preparer = trim((string)($ord['assigned_picker'] ?? ''));
  $loader   = trim((string)($ord['assigned_loader'] ?? ''));

  // AUTO -> anhand Status entscheiden
  if ($mode === 'AUTO') {
    if (in_array($status, ['OFFEN','KOMMISSIONIERUNG'], true)) $mode = 'PREP';
    else $mode = 'LOAD';
  }

  // Alte Grants dieser Session für Auftrag schließen
  $st = $pdo->prepare("
    UPDATE kommi_board_access
    SET revoked_at = NOW()
    WHERE order_id = ?
      AND session_id = ?
      AND revoked_at IS NULL
  ");
  $st->execute([$orderId, $sid]);

  if ($mode === 'PREP') {
    need(in_array($status, ['OFFEN','KOMMISSIONIERUNG'], true),
      'PREP-Board nur in OFFEN/KOMMISSIONIERUNG möglich (Status: '.$status.').',
      'WRONG_STATUS',
      409
    );
    need($preparer !== '',
      'Bereitsteller fehlt (Schritt 2).',
      'PREPARER_REQUIRED',
      409
    );

    // loader_user darf bei dir nicht NULL sein -> ''
    $loaderUser = '';

    $st = $pdo->prepare("
      INSERT INTO kommi_board_access
        (order_id, session_id, granted_to, preparer_user, loader_user, granted_at, valid_until)
      VALUES
        (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 12 HOUR))
    ");
    $st->execute([$orderId, $sid, $loginUser, $preparer, $loaderUser]);

    $pdo->commit();

    // Session-Kontext (optional)
    $_SESSION['kommi_board_ctx'][(string)$orderId] = [
      'granted_to'    => $loginUser,
      'preparer_user' => $preparer,
      'loader_user'   => '',
      'granted_at'    => date('Y-m-d H:i:s'),
      'valid_until'   => date('Y-m-d H:i:s', time() + 12 * 3600),
    ];

    out(true, [
      'order_id'  => $orderId,
      'mode'      => 'PREP',
      'board_url' => '/kommi/board.php?order_id=' . rawurlencode((string)$orderId) . '&embed=1'
    ], 200);
  }

  // LOAD
  need(in_array($status, ['BEREITGESTELLT','VERLADUNG'], true),
    'LOAD-Board erst nach Bereitstellung möglich (Status: '.$status.').',
    'WRONG_STATUS',
    409
  );
  need(in_array($exitGate, [1,2], true),
    'Kein Ausgang gesetzt.',
    'EXIT_REQUIRED',
    409
  );
  need($preparer !== '',
    'Bereitsteller fehlt.',
    'PREPARER_REQUIRED',
    409
  );
  need($loader !== '',
    'Verlader fehlt (Schritt 3).',
    'LOADER_REQUIRED',
    409
  );

  // Optional: nur Loader oder Admin/Dispo darf öffnen
  if (!in_array($role, ['admin','disposition'], true)) {
    need($loginUser === $loader, 'Board darf nur vom verifizierten Verlader geöffnet werden.', 'LOADER_ONLY', 403);
  }

  $st = $pdo->prepare("
    INSERT INTO kommi_board_access
      (order_id, session_id, granted_to, preparer_user, loader_user, granted_at, valid_until)
    VALUES
      (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 12 HOUR))
  ");
  $st->execute([$orderId, $sid, $loader, $preparer, $loader]);

  $pdo->commit();

  $_SESSION['kommi_board_ctx'][(string)$orderId] = [
    'granted_to'    => $loader,
    'preparer_user' => $preparer,
    'loader_user'   => $loader,
    'granted_at'    => date('Y-m-d H:i:s'),
    'valid_until'   => date('Y-m-d H:i:s', time() + 12 * 3600),
  ];

  out(true, [
    'order_id'  => $orderId,
    'mode'      => 'LOAD',
    'board_url' => '/kommi/board.php?order_id=' . rawurlencode((string)$orderId) . '&embed=1'
  ], 200);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('grant_board_access.php: ' . $e->getMessage());
  out(false, ['error'=>'grant_board_access fehlgeschlagen.','code'=>'INTERNAL','debug'=>$e->getMessage()], 500);
}