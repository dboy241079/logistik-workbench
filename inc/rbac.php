<?php
declare(strict_types=1);

/**
 * RBAC – zentrale Rollen- und Tab-Checks
 *
 * Prinzip:
 * - User hat genau eine Rolle -> users.role
 * - Tab-Rechte kommen zentral aus -> app_tab_roles
 * - Seiten und APIs nutzen dieselbe Rechtequelle
 */

/* -----------------------------------------------------------
 * Basis-Helper
 * --------------------------------------------------------- */

function rbac_uid(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function rbac_username(): string
{
    return (string)($_SESSION['username'] ?? '');
}

function rbac_display_name(): string
{
    return (string)($_SESSION['display_name'] ?? '');
}

function rbac_role(): string
{
    return (string)($_SESSION['role'] ?? '');
}

function rbac_is_logged_in(): bool
{
    return rbac_uid() !== null && rbac_username() !== '';
}

/* -----------------------------------------------------------
 * Login erzwingen
 * --------------------------------------------------------- */

function rbac_require_login_json(): void
{
    if (!rbac_is_logged_in()) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Nicht eingeloggt.',
            'code' => 'UNAUTHORIZED',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function rbac_require_login_page(string $message = 'Nicht eingeloggt.'): void
{
    if (!rbac_is_logged_in()) {
        http_response_code(401);
        exit($message);
    }
}

/* -----------------------------------------------------------
 * Rollen
 * --------------------------------------------------------- */

function rbac_all_roles(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT role_key
        FROM app_roles
        WHERE active = 1
        ORDER BY sort_order, role_key
    ");
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);

    return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
}

/* -----------------------------------------------------------
 * Alte Permission-Logik (optional weiter nutzbar)
 * --------------------------------------------------------- */

function rbac_can(PDO $pdo, string $permKey): bool
{
    $role = rbac_role();
    if ($role === '') {
        return false;
    }

    $st = $pdo->prepare("
        SELECT 1
        FROM app_permission_roles
        WHERE perm_key = ?
          AND role_key = ?
        LIMIT 1
    ");
    $st->execute([$permKey, $role]);

    return (bool)$st->fetchColumn();
}

function rbac_require_json(PDO $pdo, string $permKey, string $error = 'forbidden', int $code = 403): void
{
    rbac_require_login_json();

    if (!rbac_can($pdo, $permKey)) {
        http_response_code($code);
        echo json_encode([
            'ok' => false,
            'error' => $error,
            'perm' => $permKey,
            'role' => rbac_role(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function rbac_require_page(PDO $pdo, string $permKey, string $message = 'Forbidden.'): void
{
    rbac_require_login_page();

    if (!rbac_can($pdo, $permKey)) {
        http_response_code(403);
        exit($message);
    }
}

/* -----------------------------------------------------------
 * Tab-RBAC
 * --------------------------------------------------------- */

/**
 * Alle erlaubten Rollen für einen Tab.
 * WICHTIG:
 * Kein Fallback = wenn nichts in app_tab_roles steht, darf niemand rein.
 */
function rbac_allowed_roles_for_tab(PDO $pdo, string $tabKey): array
{
    $st = $pdo->prepare("
        SELECT role
        FROM app_tab_roles
        WHERE tab_key = :tab
        ORDER BY role
    ");
    $st->execute([':tab' => $tabKey]);

    $roles = $st->fetchAll(PDO::FETCH_COLUMN);

    if (!is_array($roles)) {
        return [];
    }

    $roles = array_values(array_unique(array_filter($roles, static function ($r) {
        return is_string($r) && trim($r) !== '';
    })));

    return $roles;
}

/**
 * Alle Tabs, die eine Rolle sehen darf.
 */
function rbac_tabs_for_role(PDO $pdo, string $role): array
{
    if ($role === '') {
        return [];
    }

    $st = $pdo->prepare("
        SELECT tab_key
        FROM app_tab_roles
        WHERE role = :role
        ORDER BY tab_key
    ");
    $st->execute([':role' => $role]);

    $tabs = $st->fetchAll(PDO::FETCH_COLUMN);

    if (!is_array($tabs)) {
        return [];
    }

    return array_values(array_unique(array_filter($tabs, static function ($t) {
        return is_string($t) && trim($t) !== '';
    })));
}

/**
 * Darf die aktuelle Rolle den Tab sehen?
 */
function rbac_can_tab(PDO $pdo, string $tabKey): bool
{
    $role = rbac_role();
    if ($role === '') {
        return false;
    }

    $allowed = rbac_allowed_roles_for_tab($pdo, $tabKey);
    return in_array($role, $allowed, true);
}

/* -----------------------------------------------------------
 * Optionales Logging verweigerter Zugriffe
 * --------------------------------------------------------- */

function rbac_log_denied(PDO $pdo, string $reason, string $denyMode = 'message'): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO app_access_denied_log
                (user_id, username_snapshot, request_uri, remote_ip, deny_mode, reason, attempted_at)
            VALUES
                (:user_id, :username, :request_uri, :remote_ip, :deny_mode, :reason, NOW())
        ");
        $stmt->execute([
            ':user_id'     => rbac_uid(),
            ':username'    => rbac_username() !== '' ? rbac_username() : null,
            ':request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            ':remote_ip'   => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ':deny_mode'   => $denyMode,
            ':reason'      => $reason,
        ]);
    } catch (Throwable $e) {
        // Logging darf den eigentlichen Request nicht kaputt machen
        error_log('RBAC deny log failed: ' . $e->getMessage());
    }
}

/* -----------------------------------------------------------
 * Tab erzwingen – JSON / API
 * --------------------------------------------------------- */

function rbac_require_tab_json(PDO $pdo, string $tabKey): void
{
    rbac_require_login_json();

    if (!rbac_can_tab($pdo, $tabKey)) {
        rbac_log_denied(
            $pdo,
            'Tab "' . $tabKey . '" für Rolle "' . rbac_role() . '" nicht freigegeben',
            'json'
        );

        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden',
            'code' => 'TAB_FORBIDDEN',
            'tab' => $tabKey,
            'role' => rbac_role(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* -----------------------------------------------------------
 * Tab erzwingen – normale Seiten
 * --------------------------------------------------------- */

function rbac_require_tab_page(
    PDO $pdo,
    string $tabKey,
    string $denyMode = 'message',
    string $defaultTab = 'dashboard'
): void {
    rbac_require_login_page();

    if (!rbac_can_tab($pdo, $tabKey)) {
        rbac_log_denied(
            $pdo,
            'Tab "' . $tabKey . '" für Rolle "' . rbac_role() . '" nicht freigegeben',
            $denyMode
        );

        http_response_code(403);

        if ($denyMode === 'redirect') {
            header('Location: /index.php?tab=' . urlencode($defaultTab));
            exit;
        }

        exit('Forbidden: Zugriff auf "' . $tabKey . '" nicht erlaubt.');
    }
}