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


header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Nicht eingeloggt']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $pallet  = trim($_POST['pallet_code']   ?? '');
        $del     = trim($_POST['delivery_note'] ?? '');
        $mat     = trim($_POST['material_no']   ?? '');
        $hall    = trim($_POST['hall']          ?? '');
        $hasUmp  = (int)($_POST['has_ump']  ?? 0);
        $hasKlt  = (int)($_POST['has_klt']  ?? 0);
        $has100  = (int)($_POST['has_100'] ?? 0);

        if ($pallet === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Palette fehlt']);
            exit;
        }

        // Doppelaufgaben verhindern
        $stmt = $pdo->prepare("
          SELECT id 
          FROM qc_100_tasks 
          WHERE pallet_code = :pallet AND is_done = 0
          LIMIT 1
        ");
        $stmt->execute([':pallet' => $pallet]);
        if ($stmt->fetch()) {
            echo json_encode(['ok' => true, 'msg' => 'Aufgabe existiert bereits']);
            exit;
        }

        $stmtIns = $pdo->prepare("
          INSERT INTO qc_100_tasks
            (pallet_code, delivery_note, material_no, hall,
             has_ump, has_klt, has_100,
             is_done, created_by)
          VALUES
            (:pallet, :del, :mat, :hall,
             :has_ump, :has_klt, :has_100,
             0, :uid)
        ");
        $stmtIns->execute([
          ':pallet'  => $pallet,
          ':del'     => $del,
          ':mat'     => $mat,
          ':hall'    => $hall,
          ':has_ump' => $hasUmp,
          ':has_klt' => $hasKlt,
          ':has_100' => $has100,
          ':uid'     => $userId,
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Aufgabe angelegt']);
        exit;
    }

    if ($action === 'done') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'ID fehlt']);
            exit;
        }

        $stmt = $pdo->prepare("
          UPDATE qc_100_tasks
          SET is_done = 1,
              done_at = NOW(),
              done_by = :uid
          WHERE id = :id
        ");
        $stmt->execute([
          ':uid' => $userId,
          ':id'  => $id,
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Aufgabe erledigt']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Unbekannte Aktion']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB-Fehler']);
}
