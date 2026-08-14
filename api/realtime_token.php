<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/realtime_outbox.php';

rbac_require_login_json();
rbac_require_tab_json($pdo, 'drivers');

try {
    realtimeEnsureSchema($pdo);

    $cfgPath = dirname(__DIR__) . '/data/realtime_cfg.json';
    $cfg = is_file($cfgPath) ? json_decode((string)file_get_contents($cfgPath), true) : [];
    $ttl = max(60, min(900, (int)($cfg['token_ttl_seconds'] ?? 300)));

    // Alte Tokens nebenbei aufräumen.
    $pdo->exec("DELETE FROM realtime_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $rawToken = bin2hex(random_bytes(32));
    $hash = hash('sha256', $rawToken);
    $location = realtimeLocationCode();
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s');

    $st = $pdo->prepare("INSERT INTO realtime_tokens
        (token_hash, user_id, location_code, scopes, expires_at, created_at)
        VALUES (:token_hash, :user_id, :location_code, :scopes, :expires_at, NOW())");
    $st->execute([
        ':token_hash' => $hash,
        ':user_id' => (int)$_SESSION['user_id'],
        ':location_code' => $location,
        ':scopes' => 'drivers',
        ':expires_at' => $expiresAt,
    ]);

    api_ok([
        'token' => $rawToken,
        'expires_at' => $expiresAt,
        'location' => $location,
        'scopes' => ['drivers'],
    ]);
} catch (Throwable $e) {
    api_err('Realtime-Token konnte nicht erstellt werden.', 500);
}
