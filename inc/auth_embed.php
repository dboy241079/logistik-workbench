<?php
declare(strict_types=1);

/**
 * Zentraler Auth-/Embed-Guard für alle Module.
 *
 * Vor require() optional setzen:
 *
 *   $AUTH_REQUIRE_LOGIN = true|false
 *   $AUTH_REQUIRE_EMBED = true|false
 *   $AUTH_ALLOWED_ROLES = ['admin', ...]   // Legacy-Fallback
 *   $AUTH_TAB_KEY       = 'outbound'       // NEU: zentrale RBAC über app_tab_roles
 *   $AUTH_DEFAULT_TAB   = 'dashboard'
 *   $AUTH_DENY_MODE     = 'redirect'|'message'
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/rbac.php';

// Defaults
$AUTH_REQUIRE_LOGIN = $AUTH_REQUIRE_LOGIN ?? true;
$AUTH_REQUIRE_EMBED = $AUTH_REQUIRE_EMBED ?? true;
$AUTH_ALLOWED_ROLES = (isset($AUTH_ALLOWED_ROLES) && is_array($AUTH_ALLOWED_ROLES)) ? $AUTH_ALLOWED_ROLES : [];
$AUTH_TAB_KEY       = (isset($AUTH_TAB_KEY) && is_string($AUTH_TAB_KEY) && $AUTH_TAB_KEY !== '')
    ? $AUTH_TAB_KEY
    : null;
$AUTH_DEFAULT_TAB   = (isset($AUTH_DEFAULT_TAB) && is_string($AUTH_DEFAULT_TAB) && $AUTH_DEFAULT_TAB !== '')
    ? $AUTH_DEFAULT_TAB
    : 'dashboard';
$AUTH_DENY_MODE     = $AUTH_DENY_MODE ?? 'redirect';

// --- Helpers ---------------------------------------------------------------

if (!function_exists('auth_redirect_to_index')) {
    function auth_redirect_to_index(string $tab = 'dashboard'): void
    {
        $tab = preg_replace('/[^a-z0-9_-]/i', '', $tab) ?: 'dashboard';

        $url = '/index.php';
        if ($tab !== '') {
            $url .= '?tab=' . urlencode($tab);
        }

        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('auth_log_denied')) {
    function auth_log_denied(string $reason, string $mode): void
    {
        global $pdo;

        try {
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                require_once __DIR__ . '/../api/_db.php';
            }

            $userId   = $_SESSION['user_id'] ?? null;
            $username = $_SESSION['username'] ?? null;
            $uri      = $_SERVER['REQUEST_URI'] ?? '';
            $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt = $pdo->prepare("
                INSERT INTO app_access_denied_log
                  (attempted_at, user_id, username_snapshot, request_uri, remote_ip, user_agent, deny_mode, reason)
                VALUES
                  (NOW(), :uid, :uname, :uri, :ip, :ua, :mode, :reason)
            ");
            $stmt->execute([
                ':uid'    => $userId,
                ':uname'  => $username,
                ':uri'    => $uri,
                ':ip'     => $ip,
                ':ua'     => $ua,
                ':mode'   => $mode,
                ':reason' => $reason,
            ]);
        } catch (Throwable $e) {
            // absichtlich still
        }
    }
}

if (!function_exists('auth_handle_deny')) {
    function auth_handle_deny(string $defaultTab, string $mode = 'redirect', string $reason = 'denied'): void
    {
        auth_log_denied($reason, $mode);

        if ($mode === 'message') {
            http_response_code(403);
            $ts = date('d.m.Y H:i:s');
            ?>
            <!doctype html>
            <html lang="de">
            <head>
              <meta charset="utf-8">
              <title>Zugriff verweigert</title>
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <style>
                body {
                  margin: 0;
                  min-height: 100vh;
                  display: flex;
                  align-items: center;
                  justify-content: center;
                  background: #020617;
                  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                  color: #e5e7eb;
                }
                .box {
                  padding: 1.75rem 2rem;
                  border-radius: 0.75rem;
                  background: rgba(15,23,42,0.95);
                  border: 1px solid rgba(148,163,184,0.7);
                  box-shadow: 0 18px 40px rgba(15,23,42,0.6);
                  max-width: 380px;
                  text-align: center;
                }
                .box h1 {
                  font-size: 1.15rem;
                  margin: 0 0 .5rem;
                }
                .box p {
                  font-size: .85rem;
                  margin: 0 0 .75rem;
                }
                .box small {
                  font-size: .7rem;
                  color: #9ca3af;
                }
                .btn-back {
                  display: inline-block;
                  margin-top: 0.9rem;
                  padding: 0.45rem 1.1rem;
                  border-radius: 999px;
                  border: 1px solid rgba(59,130,246,0.6);
                  background: linear-gradient(135deg, #1d4ed8, #3b82f6);
                  color: #e5e7eb;
                  text-decoration: none;
                  font-size: .8rem;
                  font-weight: 500;
                  box-shadow: 0 8px 20px rgba(37,99,235,0.5);
                }
                .btn-back:hover {
                  background: linear-gradient(135deg, #1e40af, #2563eb);
                }
              </style>
            </head>
            <body>
              <div class="box">
                <h1>Zugriff verweigert</h1>
                <p>Vielen Dank für Ihr Interesse,<br>aber diese Daten sind nichts für Sie!</p>
                <small>Versuchter Zugriff: <?=htmlspecialchars($ts)?> Uhr</small><br>
                <small>Bitte wenden Sie sich an die Disposition / TeamProjekt Outsourcing.</small><br>
                <a href="/index.php" class="btn-back">Zur Workbench</a>
              </div>
            </body>
            </html>
            <?php
            exit;
        }

        auth_redirect_to_index($defaultTab);
    }
}

// --- Checks ---------------------------------------------------------------

// 1) Login prüfen
if ($AUTH_REQUIRE_LOGIN && empty($_SESSION['user_id'])) {
    auth_handle_deny($AUTH_DEFAULT_TAB, $AUTH_DENY_MODE, 'not_logged_in');
}

// 2) Rolle aus DB synchronisieren (Quelle der Wahrheit)
if ($AUTH_REQUIRE_LOGIN && !empty($_SESSION['user_id'])) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("
            SELECT id, username, display_name, role, active, deleted_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$u ||
            (int)($u['active'] ?? 0) !== 1 ||
            !empty($u['deleted_at'])
        ) {
            auth_handle_deny($AUTH_DEFAULT_TAB, $AUTH_DENY_MODE, 'user_inactive_or_missing');
        }

        // Session sauber synchron halten
        $_SESSION['username']     = (string)($u['username'] ?? ($_SESSION['username'] ?? ''));
        $_SESSION['display_name'] = (string)($u['display_name'] ?? ($_SESSION['display_name'] ?? ''));
        $_SESSION['role']         = (string)($u['role'] ?? '');
    } catch (Throwable $e) {
        auth_handle_deny($AUTH_DEFAULT_TAB, $AUTH_DENY_MODE, 'db_role_sync_failed');
    }
}

// 3) Embed prüfen
if ($AUTH_REQUIRE_EMBED && (($_GET['embed'] ?? '0') !== '1')) {
    auth_handle_deny($AUTH_DEFAULT_TAB, $AUTH_DENY_MODE, 'not_embedded');
}

// 4) Rechte prüfen
// NEU: Wenn AUTH_TAB_KEY gesetzt ist, immer zentrale Tab-RBAC verwenden.
// Legacy AUTH_ALLOWED_ROLES nur verwenden, wenn kein AUTH_TAB_KEY gesetzt ist.
if ($AUTH_REQUIRE_LOGIN) {
    if ($AUTH_TAB_KEY !== null) {
        if (!rbac_can_tab($pdo, $AUTH_TAB_KEY)) {
            auth_handle_deny(
                $AUTH_DEFAULT_TAB,
                $AUTH_DENY_MODE,
                'tab_forbidden:' . $AUTH_TAB_KEY . ':role=' . ($_SESSION['role'] ?? '')
            );
        }
    } elseif (!empty($AUTH_ALLOWED_ROLES)) {
        $role = $_SESSION['role'] ?? null;
        if ($role === null || !in_array($role, $AUTH_ALLOWED_ROLES, true)) {
            auth_handle_deny($AUTH_DEFAULT_TAB, $AUTH_DENY_MODE, 'role_forbidden');
        }
    }
}