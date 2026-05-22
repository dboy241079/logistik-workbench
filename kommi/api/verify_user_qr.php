<?php
declare(strict_types=1);
require __DIR__ . '/_kommi_bootstrap.php';

const KOMMI_ALLOW_SAME_PREPARER_LOADER = true;

try {
  kommi_api_require_method('POST');
  [$username] = kommi_api_require_login(['admin','disposition','staplerfahrer','verpacker']);
  kommi_api_require_outbound_tab($pdo);

  $payload   = kommi_api_read_json();
  $orderId   = (int)($payload['order_id'] ?? 0);
  $phase     = strtoupper(trim((string)($payload['phase'] ?? '')));
  $scanInput = trim((string)($payload['scan'] ?? ''));

  kommi_api_need($orderId > 0, 'order_id fehlt.', 'MISSING_ORDER_ID', 400);
  kommi_api_need(in_array($phase, ['PREPARER','LOADER'], true), 'Ungültige Phase.', 'INVALID_PHASE', 400);
  kommi_api_need($scanInput !== '', 'Scan fehlt.', 'MISSING_SCAN', 400);

  $state = $_SESSION['kommi_checkin'][(string)$orderId] ?? null;
  kommi_api_need(is_array($state) && !empty($state['challenge_ok']), 'Auftrags-QR zuerst verifizieren.', 'CHALLENGE_REQUIRED', 403);

  if ($phase === 'LOADER') {
    kommi_api_need(!empty($state['preparer_user']), 'Zuerst Bereitsteller verifizieren.', 'PREPARER_REQUIRED', 409);
  }

  // Unterstützt:
  // 1) nur Badge-Code
  // 2) KOMMI|USER|<badge_code>
  $badgeCode = $scanInput;
  if (preg_match('/^KOMMI\|USER\|(.+)$/', $scanInput, $m)) {
    $badgeCode = trim($m[1]);
  }

  $st = $pdo->prepare("
    SELECT username, role, is_active
    FROM kommi_user_badges
    WHERE badge_code = ?
    LIMIT 1
  ");
  $st->execute([$badgeCode]);
  $badge = $st->fetch(PDO::FETCH_ASSOC);

  kommi_api_need((bool)$badge, 'Badge nicht gefunden.', 'BADGE_NOT_FOUND', 404);
  kommi_api_need((int)$badge['is_active'] === 1, 'Badge ist inaktiv.', 'BADGE_INACTIVE', 403);

  $verifiedUser = (string)$badge['username'];
  $verifiedRole = (string)$badge['role'];

  $allowedForPhase = [
    'PREPARER' => ['admin','disposition','staplerfahrer'],
    'LOADER'   => ['admin','disposition','verpacker'],
  ];

  kommi_api_need(in_array($verifiedRole, $allowedForPhase[$phase], true), 'Rolle nicht für diese Phase erlaubt.', 'ROLE_NOT_ALLOWED', 403);

  if (!KOMMI_ALLOW_SAME_PREPARER_LOADER && $phase === 'LOADER') {
    kommi_api_need($verifiedUser !== (string)$state['preparer_user'], 'Bereitsteller und Verlader müssen unterschiedlich sein.', 'SAME_USER_NOT_ALLOWED', 409);
  }

  $pdo->beginTransaction();

  $pdo->prepare("
    INSERT INTO kommi_order_verifications
      (order_id, phase, verified_user, method, challenge_token, verified_by)
    VALUES
      (?, ?, ?, 'QR', ?, ?)
  ")->execute([
    $orderId,
    $phase,
    $verifiedUser,
    (string)($state['challenge_token'] ?? ''),
    $username
  ]);

  if ($phase === 'PREPARER') {
    $state['preparer_user'] = $verifiedUser;
    $state['preparer_role'] = $verifiedRole;
    $state['preparer_at']   = date('Y-m-d H:i:s');
  } else {
    $state['loader_user'] = $verifiedUser;
    $state['loader_role'] = $verifiedRole;
    $state['loader_at']   = date('Y-m-d H:i:s');
  }

  $_SESSION['kommi_checkin'][(string)$orderId] = $state;

  $pdo->commit();

  kommi_api_out(true, [
    'phase'    => $phase,
    'username' => $verifiedUser,
    'role'     => $verifiedRole
  ]);

} catch (KommiApiException $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  kommi_api_out(false, ['error' => $e->getMessage(), 'code' => $e->apiCode], $e->httpStatus);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  kommi_api_log_error($e, 'verify_user_qr.php');
  kommi_api_out(false, ['error' => 'Interner Serverfehler.', 'code' => 'INTERNAL'], 500);
}
