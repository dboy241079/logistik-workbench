<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php'; // <-- PDO muss vor dem Guard da sein

// Rollen dynamisch pro Tab aus DB holen
function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);

  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
  return $roles ?: ['admin']; // Fallback
}

// ================= Auth / Guard =================
$TAB_KEY = 'special';

$AUTH_DEFAULT_TAB   = $TAB_KEY;
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, $TAB_KEY);
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';


$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'check_updates') {
        $since = $_POST['since'] ?? $_GET['since'] ?? '';

        // Wenn kein "since" übergeben wird → keine alten Sachen melden
        if ($since === '') {
            echo json_encode(['ok' => true, 'items' => []]);
            exit;
        }

        $sql = "
          SELECT
            q.id,
            q.created_at,
            q.hall,
            q.pallet_code,
            q.delivery_note,
            q.material_no,
            q.reason,
            COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter
          FROM qc_100_pruefungen q
          LEFT JOIN users u ON q.employee_id = u.id
          WHERE q.created_at > :since
          ORDER BY q.created_at ASC      -- ⬅️ ÄLTESTER ZUERST, damit der Stack logisch ist
          LIMIT 50                       -- ⬅️ Mehr als 5 zulassen, falls viel los ist
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'ok'    => true,
            'items' => $rows,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Unbekannte Aktion']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Serverfehler']);
}
