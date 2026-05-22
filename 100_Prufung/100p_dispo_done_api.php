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

// ==== Immer JSON antworten ===============================================
header('Content-Type: application/json; charset=utf-8');

// Nur POST zulassen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'  => false,
        'msg' => 'Nur POST erlaubt.'
    ]);
    exit;
}

// Parameter holen
$id   = isset($_POST['id'])   ? (int)$_POST['id']   : 0;
$done = isset($_POST['done']) && $_POST['done'] === '1' ? 1 : 0;

if ($id <= 0) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'Ungültige ID.'
    ]);
    exit;
}

require __DIR__ . '/../api/_db.php';

try {
    $stmt = $pdo->prepare('
        UPDATE qc_100_pruefungen
        SET dispo_done = :done
        WHERE id = :id
    ');
    $stmt->execute([
        ':done' => $done,
        ':id'   => $id,
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'ok'  => false,
            'msg' => 'Datensatz nicht gefunden.'
        ]);
        exit;
    }

    echo json_encode([
        'ok'     => true,
        'status' => $done
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => 'DB-Fehler: ' . $e->getMessage()
    ]);
    exit;
}
