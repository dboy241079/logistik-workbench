<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';
require __DIR__ . '/../inc/rbac.php';

// --- Zentraler Auth-/Embed-Guard für den Adminbereich --------------------
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_TAB_KEY       = 'admin';
$AUTH_DEFAULT_TAB   = 'admin';
$AUTH_DENY_MODE     = 'message';

require __DIR__ . '/../inc/auth_embed.php';

// Session-Auswertung ERST NACH dem Guard
$userId   = $_SESSION['user_id'] ?? null;
$role     = $_SESSION['role'] ?? null;
$username = $_SESSION['display_name'] ?? ($_SESSION['username'] ?? '');

// Wer auf dieser Seite ist, hat bereits den Tab "admin" erlaubt bekommen
$isAdmin = true;
$canAdminArea = true;

// Falls du später "echten" Superadmin von Standortleiter trennen willst:
$isSuperAdmin = in_array($role, ['admin','standortleiter'], true);
// alternativ strenger:
// $isSuperAdmin = ($role === 'admin');


// erlaubte Hallen
$allowedHalls = ['W1','X3','Banking','G9'];
$invZoneOptions = ['W1','X3','X3(B)','G9','B1','B1(T)','Sarajevo'];


$loginError     = '';
$uploadError    = '';
$uploadSuccess  = '';
$catError       = '';
$catSuccess     = '';
$docError       = '';
$docSuccess     = '';
$userError      = '';
$userSuccess    = '';
$tabsError      = '';
$tabsSuccess    = '';
$vehError = '';
$vehSuccess = '';
$invError   = '';
$invSuccess = '';
$invCfgPath = dirname(__DIR__) . '/data/inventory_cfg.json'; // /LKW/data/inventory_cfg.json


$roleOptions = rbac_all_roles($pdo);
$visibilityRoles = $roleOptions; // wenn du wirklich überall alles anzeigen willst

$vehCfgPath = dirname(__DIR__) . '/data/veh_cfg.json'; // /LKW/data/veh_cfg.json


/**
 * Bekannte Tabs der Workbench (für Erst-Befüllung der Tabelle app_tabs)
 */
$knownTabs = [
    ['tab_key' => 'dashboard',      'label' => 'Dashboard',     'description' => 'KPIs & Gesamtübersicht',                    'sort_order' => 10],
    ['tab_key' => 'drivers',        'label' => 'Fahrer',        'description' => 'Fahrer-Timeline & Disposition',             'sort_order' => 20],
    ['tab_key' => 'goods',          'label' => 'Warenstamm',    'description' => 'Stammdaten Sachnummern',                    'sort_order' => 30],
    ['tab_key' => 'inbound',        'label' => 'Wareneingang',  'description' => 'Eingänge, Buchungen, Belege',               'sort_order' => 40],
    ['tab_key' => 'outbound',       'label' => 'Warenausgang',  'description' => 'Ausgänge, Ladelisten, Verladung',           'sort_order' => 50],
    ['tab_key' => 'lagerplan',      'label' => 'Lagerplan',     'description' => 'Lagerplan & Hallenübersicht',               'sort_order' => 55],
    ['tab_key' => 'artikelakte',    'label' => 'Artikelakte',   'description' => 'Artikelhistorie, Lager- und Bewegungsdaten','sort_order' => 58],
    ['tab_key' => 'special',        'label' => '100%-Prüfung',  'description' => 'Sonderprüfungen & Qualität',                'sort_order' => 60],
    ['tab_key' => 'quiz',           'label' => 'Quiz',          'description' => 'Disponenten-Test / Schulungsquiz',          'sort_order' => 65],
    ['tab_key' => 'docs',           'label' => 'Dokumente',     'description' => 'Dokumenten-Center & Arbeitsanweis.',        'sort_order' => 90],
    ['tab_key' => 'admin',          'label' => 'Admin',         'description' => 'Adminbereich & Konfiguration',              'sort_order' => 100],
];

/**
 * Kleine Helper-Funktion: Rollen-JSON hübsch anzeigen.
 * NULL oder leeres Array = alle Rollen.
 */
function qcRenderRoles(?string $json): string
{
    if ($json === null || $json === '') {
        return 'alle Rollen';
    }
    $arr = json_decode($json, true);
    if (!is_array($arr) || !$arr) {
        return 'alle Rollen';
    }
    return implode(', ', $arr);
}

function qcLastActivityInfo(?string $dt, int $onlineSeconds = 300): array
{
    if (!$dt) {
        return [false, 'noch nie angemeldet'];
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return [false, 'Zeit unbekannt'];
    }

    $diff = time() - $ts;
    $isOnline = $diff <= $onlineSeconds;

    if ($diff < 60) {
        $text = 'vor wenigen Sekunden aktiv';
    } elseif ($diff < 3600) {
        $min = (int) floor($diff / 60);
        $text = 'vor ' . $min . ' Minute' . ($min === 1 ? '' : 'n') . ' aktiv';
    } elseif ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        $text = 'vor ' . $h . ' Stunde' . ($h === 1 ? '' : 'n') . ' aktiv';
    } else {
        $d = (int) floor($diff / 86400);
        $text = 'vor ' . $d . ' Tag' . ($d === 1 ? '' : 'en') . ' aktiv';
    }

    return [$isOnline, $text];
}
function normalizeDigits(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function requireFiveDigitNumber(?string $value, string $label): string
{
    $value = normalizeDigits($value);

    if (!preg_match('/^\d{5}$/', $value)) {
        throw new RuntimeException($label . ' muss genau 5-stellig sein.');
    }

    return $value;
}

function ensureUniquePersonalNo(PDO $pdo, string $personalNo, ?int $excludeUserId = null): void
{
    if ($excludeUserId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE personal_no = :p AND id <> :id LIMIT 1');
        $stmt->execute([
            ':p'  => $personalNo,
            ':id' => $excludeUserId,
        ]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE personal_no = :p LIMIT 1');
        $stmt->execute([':p' => $personalNo]);
    }

    if ($stmt->fetch()) {
        throw new RuntimeException('Die Personalnummer ist bereits vergeben.');
    }
}

function requireVerifyPinHash(?string $pin1, ?string $pin2): string
{
    $pin1 = normalizeDigits($pin1);
    $pin2 = normalizeDigits($pin2);

    if (!preg_match('/^\d{5}$/', $pin1)) {
        throw new RuntimeException('Die Verifizierungs-PIN muss genau 5-stellig sein.');
    }

    if ($pin1 !== $pin2) {
        throw new RuntimeException('Die Verifizierungs-PINs stimmen nicht überein.');
    }

    return password_hash($pin1, PASSWORD_DEFAULT);
}

function optionalVerifyPinHash(?string $pin1, ?string $pin2): ?string
{
    $pin1 = normalizeDigits($pin1);
    $pin2 = normalizeDigits($pin2);

    if ($pin1 === '' && $pin2 === '') {
        return null;
    }

    if (!preg_match('/^\d{5}$/', $pin1)) {
        throw new RuntimeException('Die Verifizierungs-PIN muss genau 5-stellig sein.');
    }

    if ($pin1 !== $pin2) {
        throw new RuntimeException('Die Verifizierungs-PINs stimmen nicht überein.');
    }

    return password_hash($pin1, PASSWORD_DEFAULT);
}


// --- Logout-Handler --------------------------------------------------------
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php?embed=1&section=users');
exit;

}

// --- Login-Handler ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'login')) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $loginError = 'Bitte Benutzername und Passwort eingeben.';
    } else {
        $stmt = $pdo->prepare('
  SELECT * 
  FROM users 
  WHERE username = :u 
    AND active = 1
    AND deleted_at IS NULL
  LIMIT 1
');

        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $loginError = 'Benutzername oder Passwort falsch.';
        } elseif (!in_array($user['role'], ['admin','standortleiter'], true)) {
  $loginError = 'Dieser Bereich ist nur für Admin/Standortleiter freigeschaltet.';
}
 else {
            $_SESSION['user_id']      = (int)$user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role']         = $user['role'];

            header('Location: admin.php?embed=1&section=users');
            exit;

        }
    }
}

// last_activity für Admin aktualisieren
if ($canAdminArea) {
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, last_activity)
        VALUES (:uid, NOW())
        ON DUPLICATE KEY UPDATE last_activity = VALUES(last_activity)
    ");
    $stmt->execute([':uid' => $userId]);
}

// --- Admin-Aktionen: Upload + Doku-Edit + Kategorien + User ----------------
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1) Dokument-Upload -----------------------------------------------------
    if ($action === 'upload_doc') {
        try {
            $title       = trim($_POST['doc_title'] ?? '');
            $docHall     = trim($_POST['doc_hall'] ?? '');
            $docCategory = trim($_POST['doc_category'] ?? '');

            // Rollen für Sichtbarkeit
            $docRolesRaw = $_POST['doc_roles'] ?? [];
            $docRolesRaw = is_array($docRolesRaw) ? $docRolesRaw : [];
            $docRolesRaw = array_unique($docRolesRaw);

            if (in_array('__all', $docRolesRaw, true)) {
                // __all = für alle Rollen sichtbar
                $docRolesJson = null;
            } else {
                $clean = array_values(array_intersect($docRolesRaw, $visibilityRoles));
                $docRolesJson = $clean ? json_encode($clean) : null;
            }

            if ($title === '') {
                throw new RuntimeException('Bitte einen Titel angeben.');
            }

            if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Bitte eine Datei auswählen.');
            }

            $file = $_FILES['doc_file'];

            // max. 10 MB
            if ($file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Datei ist größer als 10 MB (10 MB Limit).');
            }

            $origName   = $file['name'];
            $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExt = ['pdf','xls','xlsx','csv','doc','docx','txt','png','jpg','jpeg'];

            if (!in_array($ext, $allowedExt, true)) {
                throw new RuntimeException('Dieser Dateityp ist nicht erlaubt.');
            }

            // Upload-Ordner: /LKW/dokumente/docs
            $uploadDir = __DIR__ . '/../dokumente/docs';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden.');
            }

            $safeName   = bin2hex(random_bytes(8)) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            }

            $stmtIns = $pdo->prepare("
              INSERT INTO qc_100_docs
                (title, hall, category, filename, original_name, mime_type, file_size, visible_roles, active, uploaded_by, created_at)
              VALUES
                (:title, :hall, :category, :filename, :orig, :mime, :size, :roles, 1, :uid, NOW())
            ");
            $stmtIns->execute([
              ':title'    => $title,
              ':hall'     => ($docHall !== '' && in_array($docHall, $allowedHalls, true)) ? $docHall : null,
              ':category' => ($docCategory !== '') ? $docCategory : null,
              ':filename' => $safeName,
              ':orig'     => $origName,
              ':mime'     => $file['type'] ?: null,
              ':size'     => $file['size'],
              ':roles'    => $docRolesJson,
              ':uid'      => $userId,
            ]);

            $uploadSuccess = 'Dokument wurde erfolgreich hochgeladen.';
        } catch (Throwable $e) {
            $uploadError = $e->getMessage();
        }
    }

    // 2) Dokument bearbeiten -------------------------------------------------
    if ($action === 'update_doc') {
        try {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('Bitte ein Dokument auswählen.');
            }

            $newTitle = trim($_POST['doc_title_edit'] ?? '');
            $newHall  = trim($_POST['doc_hall_edit'] ?? '');
            $newCat   = trim($_POST['doc_category_edit'] ?? '');

            if ($newTitle === '') {
                throw new RuntimeException('Bitte einen Titel angeben.');
            }

            $stmtChk = $pdo->prepare("SELECT * FROM qc_100_docs WHERE id = :id");
            $stmtChk->execute([':id' => $docId]);
            $old = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new RuntimeException('Dokument wurde nicht gefunden.');
            }

            // Rollen fürs Edit
            $docRolesRaw = $_POST['doc_roles_edit'] ?? [];
            $docRolesRaw = is_array($docRolesRaw) ? $docRolesRaw : [];
            $docRolesRaw = array_unique($docRolesRaw);

            if (in_array('__all', $docRolesRaw, true)) {
                $docRolesJson = null;
            } else {
                $clean = array_values(array_intersect($docRolesRaw, $visibilityRoles));
                $docRolesJson = $clean ? json_encode($clean) : null;
            }

            $stmtUpd = $pdo->prepare("
              UPDATE qc_100_docs
              SET title         = :title,
                  hall          = :hall,
                  category      = :cat,
                  visible_roles = :roles
              WHERE id = :id
            ");
            $stmtUpd->execute([
              ':title' => $newTitle,
              ':hall'  => ($newHall !== '' && in_array($newHall, $allowedHalls, true)) ? $newHall : null,
              ':cat'   => ($newCat !== '') ? $newCat : null,
              ':roles' => $docRolesJson,
              ':id'    => $docId,
            ]);

            $docSuccess = 'Dokument wurde aktualisiert.';
        } catch (Throwable $e) {
            $docError = $e->getMessage();
        }
    }

    // 3) Dokument archivieren -----------------------------------------------
    if ($action === 'archive_doc') {
        try {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('Bitte ein Dokument auswählen.');
            }

            $stmtChk = $pdo->prepare("SELECT id, title FROM qc_100_docs WHERE id = :id");
            $stmtChk->execute([':id' => $docId]);
            $doc = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                throw new RuntimeException('Dokument wurde nicht gefunden.');
            }

            // Nur als "inactive" kennzeichnen, Datei bleibt erhalten
            $stmtArc = $pdo->prepare("
              UPDATE qc_100_docs
              SET active = 0
              WHERE id = :id
            ");
            $stmtArc->execute([':id' => $docId]);

            $docSuccess = 'Dokument wurde archiviert.';
        } catch (Throwable $e) {
            $docError = $e->getMessage();
        }
    }
if ($action === 'add_user') {
    try {
        $uname      = trim($_POST['user_username'] ?? '');
        $dname      = trim($_POST['user_display_name'] ?? '');
        $pw1        = $_POST['user_password'] ?? '';
        $pw2        = $_POST['user_password2'] ?? '';
        $roleN      = $_POST['user_role'] ?? 'user';
        $activeN    = isset($_POST['user_active']) ? 1 : 0;

        $personalNo = requireFiveDigitNumber($_POST['user_personal_no'] ?? '', 'Die Personalnummer');

        if ($uname === '' || $pw1 === '' || $pw2 === '') {
            throw new RuntimeException('Benutzername und Passwort sind Pflichtfelder.');
        }

        if ($pw1 !== $pw2) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        if (!in_array($roleN, $roleOptions, true)) {
            throw new RuntimeException('Ungültige Rolle ausgewählt.');
        }

        // Benutzername eindeutig?
        $stmtChk = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmtChk->execute([':u' => $uname]);
        if ($stmtChk->fetch()) {
            throw new RuntimeException('Der Benutzername ist bereits vergeben.');
        }

        // Personalnummer eindeutig?
        ensureUniquePersonalNo($pdo, $personalNo);

        // Profilbild-Upload (optional)
        $avatarFilename = null;
        if (!empty($_FILES['user_avatar']) && $_FILES['user_avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['user_avatar'];

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Profilbild ist größer als 2 MB.');
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedImg = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowedImg, true)) {
                throw new RuntimeException('Nur Bilddateien (JPG, PNG, GIF, WebP) sind erlaubt.');
            }

            $uploadDir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Avatar-Upload-Ordner konnte nicht erstellt werden.');
            }

            $avatarFilename = bin2hex(random_bytes(8)) . '.' . $ext;
            $targetPath     = $uploadDir . '/' . $avatarFilename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Profilbild konnte nicht gespeichert werden.');
            }
        }

        $hash = password_hash($pw1, PASSWORD_DEFAULT);

        $stmtIns = $pdo->prepare("
          INSERT INTO users (
            username,
            password_hash,
            display_name,
            profile_image,
            role,
            active,
            personal_no
          )
          VALUES (
            :u,
            :pw,
            :dn,
            :avatar,
            :role,
            :active,
            :personal_no
          )
        ");
        $stmtIns->execute([
          ':u'           => $uname,
          ':pw'          => $hash,
          ':dn'          => ($dname !== '' ? $dname : $uname),
          ':avatar'      => $avatarFilename,
          ':role'        => $roleN,
          ':active'      => $activeN,
          ':personal_no' => $personalNo,
        ]);

        $userSuccess = 'Benutzer wurde angelegt.';
    } catch (Throwable $e) {
        $userError = $e->getMessage();
    }
}

    if ($action === 'update_user') {
    try {
        $id = (int)($_POST['edit_user_id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Bitte einen Benutzer auswählen.');
        }

        $dname         = trim($_POST['edit_display_name'] ?? '');
        $roleN         = $_POST['edit_role'] ?? '';
        $activeN       = isset($_POST['edit_active']) ? 1 : 0;
        $pw1           = $_POST['edit_password'] ?? '';
        $pw2           = $_POST['edit_password2'] ?? '';
        $personalNoRaw = trim($_POST['edit_personal_no'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new RuntimeException('Benutzer wurde nicht gefunden.');
        }

        if ($dname === '') {
            $dname = $userRow['display_name'];
        }

        if ($roleN === '') {
            $roleN = $userRow['role'];
        }

        if (!in_array($roleN, $roleOptions, true)) {
            throw new RuntimeException('Ungültige Rolle ausgewählt.');
        }

        // Personalnummer neu oder alte behalten
        if ($personalNoRaw !== '') {
            $personalNo = requireFiveDigitNumber($personalNoRaw, 'Die Personalnummer');
        } else {
            $personalNo = (string)($userRow['personal_no'] ?? '');
        }

        if ($personalNo !== '') {
            ensureUniquePersonalNo($pdo, $personalNo, $id);
        }

        // Profilbild: Standard = bisheriges Bild
        $avatarFilename = $userRow['profile_image'] ?? null;

        if (!empty($_FILES['edit_avatar']) && $_FILES['edit_avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['edit_avatar']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Fehler beim Profilbild-Upload.');
            }

            $file = $_FILES['edit_avatar'];

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Profilbild ist größer als 2 MB.');
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedImg = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowedImg, true)) {
                throw new RuntimeException('Nur Bilddateien (JPG, PNG, GIF, WebP) sind erlaubt.');
            }

            $uploadDir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Avatar-Upload-Ordner konnte nicht erstellt werden.');
            }

            if (!empty($avatarFilename)) {
                $oldPath = $uploadDir . '/' . $avatarFilename;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $avatarFilename = bin2hex(random_bytes(8)) . '.' . $ext;
            $targetPath     = $uploadDir . '/' . $avatarFilename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Profilbild konnte nicht gespeichert werden.');
            }
        }

        $sql = '
            UPDATE users
            SET display_name = :dn,
                profile_image = :avatar,
                role = :role,
                active = :active,
                personal_no = :personal_no
        ';

        $params = [
          ':dn'          => $dname,
          ':avatar'      => $avatarFilename,
          ':role'        => $roleN,
          ':active'      => $activeN,
          ':personal_no' => $personalNo,
          ':id'          => $id,
        ];

        if ($pw1 !== '' || $pw2 !== '') {
            if ($pw1 === '' || $pw2 === '' || $pw1 !== $pw2) {
                throw new RuntimeException('Neue Passwörter stimmen nicht überein.');
            }
            $sql .= ', password_hash = :pw';
            $params[':pw'] = password_hash($pw1, PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE id = :id';

        $stmtUpd = $pdo->prepare($sql);
        $stmtUpd->execute($params);

        $userSuccess = 'Benutzer wurde aktualisiert.';
    } catch (Throwable $e) {
        $userError = $e->getMessage();
    }
}

    // 9) User archivieren -------------------------------------------------
if ($action === 'archive_user') {
  try {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Ungültige User-ID.');
    if ($id === (int)$userId) throw new RuntimeException('Du kannst dich nicht selbst archivieren.');

    $stmt = $pdo->prepare("UPDATE users SET active=0, archived_at=NOW() WHERE id=:id AND deleted_at IS NULL");
    $stmt->execute([':id' => $id]);

    $userSuccess = 'Benutzer wurde archiviert.';
  } catch (Throwable $e) { $userError = $e->getMessage(); }
}

// 10) User aus Archiv zurückholen ------------------------------------
if ($action === 'restore_user') {
  try {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Ungültige User-ID.');

    $stmt = $pdo->prepare("UPDATE users SET archived_at=NULL, deleted_at=NULL, active=1 WHERE id=:id");
    $stmt->execute([':id' => $id]);

    $userSuccess = 'Benutzer wurde wiederhergestellt.';
  } catch (Throwable $e) { $userError = $e->getMessage(); }
}

// 11) User in Papierkorb (soft delete) --------------------------------
if ($action === 'trash_user') {
  try {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Ungültige User-ID.');
    if ($id === (int)$userId) throw new RuntimeException('Du kannst dich nicht selbst löschen.');

    // Optional: Letzten Admin schützen
    $stmtU = $pdo->prepare("SELECT role FROM users WHERE id=:id");
    $stmtU->execute([':id'=>$id]);
    // Papierkorb-Anzahl (für Badge/Warnung)
    $trashCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn();

    $r = $stmtU->fetchColumn();
    if ($r === 'admin') {
      $adminCnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND deleted_at IS NULL")->fetchColumn();
      if ($adminCnt <= 1) throw new RuntimeException('Letzter Admin kann nicht gelöscht werden.');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE users SET active=0, archived_at=NULL, deleted_at=NOW() WHERE id=:id");
    $stmt->execute([':id' => $id]);

    // Sessions aufräumen
    $stmtS = $pdo->prepare("DELETE FROM user_sessions WHERE user_id=:id");
    $stmtS->execute([':id' => $id]);

    $pdo->commit();
    $userSuccess = 'Benutzer wurde in den Papierkorb verschoben.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $userError = $e->getMessage();
  }
}

// 12) Endgültig löschen (hard delete) ----------------------------------
if ($action === 'purge_user') {
  try {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Ungültige User-ID.');
    if ($id === (int)$userId) throw new RuntimeException('Du kannst dich nicht selbst endgültig löschen.');

    $pdo->beginTransaction();

    $stmtGet = $pdo->prepare("SELECT profile_image, role FROM users WHERE id=:id");
    $stmtGet->execute([':id'=>$id]);
    $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Benutzer nicht gefunden.');

    if ($row['role'] === 'admin') {
      $adminCnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND deleted_at IS NULL")->fetchColumn();
      if ($adminCnt <= 1) throw new RuntimeException('Letzter Admin kann nicht endgültig gelöscht werden.');
    }

    $stmtS = $pdo->prepare("DELETE FROM user_sessions WHERE user_id=:id");
    $stmtS->execute([':id' => $id]);

    // nur endgültig löschen, wenn er im Papierkorb ist
    $stmtD = $pdo->prepare("DELETE FROM users WHERE id=:id AND deleted_at IS NOT NULL");
    $stmtD->execute([':id' => $id]);

    if ($stmtD->rowCount() === 0) {
      throw new RuntimeException('Endgültig löschen geht nur aus dem Papierkorb.');
    }

    $pdo->commit();

    // Avatar-Datei nach Commit löschen
    if (!empty($row['profile_image'])) {
      $path = __DIR__ . '/../uploads/avatars/' . $row['profile_image'];
      if (is_file($path)) @unlink($path);
    }

    $userSuccess = 'Benutzer wurde endgültig gelöscht.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $userError = $e->getMessage();
  }
}


    // X) Tab-Berechtigungen speichern ---------------------------------------
    if ($action === 'save_tab_roles') {
        try {
            $tabKey = trim($_POST['tab_key'] ?? '');
            $roles  = $_POST['roles'] ?? [];

            if ($tabKey === '') {
                throw new RuntimeException('Tab-Key fehlt.');
            }

            // Sicherstellen, dass Tab existiert
            $stmtTab = $pdo->prepare("SELECT id FROM app_tabs WHERE tab_key = :t LIMIT 1");
            $stmtTab->execute([':t' => $tabKey]);
            $tabRow = $stmtTab->fetch(PDO::FETCH_ASSOC);
            if (!$tabRow) {
                throw new RuntimeException('Unbekannter Tab: ' . $tabKey);
            }

            // Nur gültige Rollen übernehmen
            if (!is_array($roles)) {
                $roles = [];
            }
            $roles = array_values(array_intersect($roles, $roleOptions));

            $pdo->beginTransaction();

            // Alte Rollen für diesen Tab löschen
            $stmtDel = $pdo->prepare("DELETE FROM app_tab_roles WHERE tab_key = :t");
            $stmtDel->execute([':t' => $tabKey]);

            // Neue Rollen speichern
            if ($roles) {
                $stmtIns = $pdo->prepare("
                  INSERT INTO app_tab_roles (tab_key, role)
                  VALUES (:t, :r)
                ");
                foreach ($roles as $r) {
                    $stmtIns->execute([
                        ':t' => $tabKey,
                        ':r' => $r,
                    ]);
                }
            }

            $pdo->commit();
            $tabsSuccess = 'Tab-Berechtigungen wurden gespeichert.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $tabsError = $e->getMessage();
        }
    }

    // 4) Neue Kategorie anlegen ----------------------------------------------
    if ($action === 'add_category') {
        try {
            $name = trim($_POST['cat_name'] ?? '');
            $desc = trim($_POST['cat_desc'] ?? '');
            $sort = (int)($_POST['cat_sort'] ?? 0);

            // Rollen für Kategorie
            $catRolesRaw = $_POST['cat_roles'] ?? [];
            $catRolesRaw = is_array($catRolesRaw) ? $catRolesRaw : [];
            $catRolesRaw = array_unique($catRolesRaw);

            if (in_array('__all', $catRolesRaw, true)) {
                $catRolesJson = null;
            } else {
                $clean = array_values(array_intersect($catRolesRaw, $visibilityRoles));
                $catRolesJson = $clean ? json_encode($clean) : null;
            }

            if ($name === '') {
                throw new RuntimeException('Bitte einen Kategorienamen angeben.');
            }

            $stmtAdd = $pdo->prepare("
              INSERT INTO qc_doc_categories (name, description, sort_order, visible_roles)
              VALUES (:name, :desc, :sort, :roles)
            ");
            $stmtAdd->execute([
              ':name'  => $name,
              ':desc'  => $desc !== '' ? $desc : null,
              ':sort'  => $sort,
              ':roles' => $catRolesJson,
            ]);

            $catSuccess = 'Kategorie wurde angelegt.';
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $catError = 'Es existiert bereits eine Kategorie mit diesem Namen.';
            } else {
                $catError = $e->getMessage();
            }
        }
    }

    // 5) Kategorie bearbeiten (Name + Beschreibung + Sortierung + Rollen) ----
    if ($action === 'update_category') {
        try {
            $catId = (int)($_POST['cat_id'] ?? 0);
            if ($catId <= 0) {
                throw new RuntimeException('Bitte eine Kategorie auswählen.');
            }

            // Alte Werte holen
            $stmtOld = $pdo->prepare("SELECT * FROM qc_doc_categories WHERE id = :id");
            $stmtOld->execute([':id' => $catId]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new RuntimeException('Kategorie wurde nicht gefunden.');
            }

            // Neue Werte (alles optional)
            $newNameRaw = trim($_POST['cat_name_new'] ?? '');
            $newDescRaw = trim($_POST['cat_desc_new'] ?? '');
            $newSortRaw = $_POST['cat_sort_new'] ?? '';

            $newName = ($newNameRaw !== '') ? $newNameRaw : $old['name'];
            $newDesc = ($newDescRaw !== '') ? $newDescRaw : $old['description'];
            $newSort = ($newSortRaw !== '' ? (int)$newSortRaw : (int)$old['sort_order']);

            // Rollen fürs Edit
            $catRolesRaw = $_POST['cat_roles_edit'] ?? [];
            $catRolesRaw = is_array($catRolesRaw) ? $catRolesRaw : [];
            $catRolesRaw = array_unique($catRolesRaw);

            if (in_array('__all', $catRolesRaw, true)) {
                $catRolesJson = null;
            } else {
                $clean = array_values(array_intersect($catRolesRaw, $visibilityRoles));
                $catRolesJson = $clean ? json_encode($clean) : null;
            }

            $oldName = $old['name'];

            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare("
              UPDATE qc_doc_categories
              SET name          = :name,
                  description   = :desc,
                  sort_order    = :sort,
                  visible_roles = :roles
              WHERE id = :id
            ");
            $stmtUpd->execute([
              ':name'  => $newName,
              ':desc'  => $newDesc !== '' ? $newDesc : null,
              ':sort'  => $newSort,
              ':roles' => $catRolesJson,
              ':id'    => $catId,
            ]);

            // Name geändert? -> alle Dokumente umhängen
            if ($newName !== $oldName) {
              $stmtDocsUpd = $pdo->prepare("
                UPDATE qc_100_docs
                SET category = :newName
                WHERE category = :oldName
              ");
              $stmtDocsUpd->execute([
                ':newName' => $newName,
                ':oldName' => $oldName,
              ]);
            }

            $pdo->commit();
            $catSuccess = 'Kategorie wurde aktualisiert.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $catError = 'Es existiert bereits eine Kategorie mit diesem Namen.';
            } else {
                $catError = $e->getMessage();
            }
        }
    }

    // 6) Kategorie löschen ---------------------------------------------------
    if ($action === 'delete_category') {
        try {
            $catId = (int)($_POST['cat_id'] ?? 0);
            if ($catId <= 0) {
                throw new RuntimeException('Kategorie-ID fehlt.');
            }

            $stmtOld = $pdo->prepare("SELECT name FROM qc_doc_categories WHERE id = :id");
            $stmtOld->execute([':id' => $catId]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new RuntimeException('Kategorie wurde nicht gefunden.');
            }
            $name = $old['name'];

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM qc_100_docs WHERE category = :name");
            $stmtCount->execute([':name' => $name]);
            $docCount = (int)$stmtCount->fetchColumn();

            if ($docCount > 0) {
                throw new RuntimeException("Kategorie kann nicht gelöscht werden, es sind noch {$docCount} Dokument(e) zugeordnet.");
            }

            $stmtDel = $pdo->prepare("DELETE FROM qc_doc_categories WHERE id = :id");
            $stmtDel->execute([':id' => $catId]);

            $catSuccess = 'Kategorie wurde gelöscht.';
        } catch (Throwable $e) {
            $catError = $e->getMessage();
        }
    }
} // Ende Admin-Aktionsblock

// --- Kategorien & Dokumente für Anzeige laden -----------------------------
$categories   = [];
$docs         = [];
$allUsers     = [];
$tabsConfig   = [];
$tabRolesMap  = [];
$accessSummary = [];
$accessLogs    = [];
$accessTotal   = 0;
$lastDenied    = null;
$showTrash = (($_GET['show'] ?? '') === 'trash');


if ($canAdminArea) {
    // Users + letzte Aktivität
$where = $showTrash ? "u.deleted_at IS NOT NULL" : "u.deleted_at IS NULL";

$stmtUsers = $pdo->query("
  SELECT 
    u.id,
    u.username,
    u.display_name,
    u.personal_no,
    u.role,
    u.active,
    u.profile_image,
    u.verify_pin_hash,
    u.created_at,
    u.updated_at,
    u.archived_at,
    u.deleted_at,
    s.last_activity,
    COALESCE(qa.quiz_count, 0) AS quiz_count,
    qa.last_quiz_at
  FROM users u
  LEFT JOIN (
    SELECT user_id, MAX(last_activity) AS last_activity
    FROM user_sessions
    GROUP BY user_id
  ) s ON s.user_id = u.id
  LEFT JOIN (
    SELECT username, COUNT(*) AS quiz_count, MAX(created_at) AS last_quiz_at
    FROM quiz_attempts
    GROUP BY username
  ) qa ON qa.username = u.username
  WHERE {$where}
  ORDER BY u.username
");
$allUsers = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);


    // Kategorien inkl. Dokument-Anzahl
    $stmtCats = $pdo->query("
      SELECT c.*,
             COUNT(d.id) AS doc_count
      FROM qc_doc_categories c
      LEFT JOIN qc_100_docs d
        ON d.category = c.name
      GROUP BY c.id
      ORDER BY c.sort_order, c.name
    ");
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // letzte 50 Dokumente
    $stmtDocs = $pdo->query("
      SELECT d.*,
             COALESCE(u.display_name, u.username, CONCAT('ID ', d.uploaded_by)) AS uploader
      FROM qc_100_docs d
      LEFT JOIN users u ON d.uploaded_by = u.id
      ORDER BY d.created_at DESC
      LIMIT 50
    ");
    $docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

    // Tabs laden / bei Bedarf initial befüllen
    $stmtTabs = $pdo->query("SELECT * FROM app_tabs ORDER BY sort_order, label");
    $tabsConfig = $stmtTabs->fetchAll(PDO::FETCH_ASSOC);

    if (!$tabsConfig && !empty($knownTabs)) {
        $stmtInsTab = $pdo->prepare("
          INSERT INTO app_tabs (tab_key, label, description, sort_order, active)
          VALUES (:key, :label, :desc, :sort, 1)
        ");
        foreach ($knownTabs as $t) {
            $stmtInsTab->execute([
                ':key'   => $t['tab_key'],
                ':label' => $t['label'],
                ':desc'  => $t['description'],
                ':sort'  => $t['sort_order'],
            ]);
        }
        $stmtTabs = $pdo->query("SELECT * FROM app_tabs ORDER BY sort_order, label");
        $tabsConfig = $stmtTabs->fetchAll(PDO::FETCH_ASSOC);
    }

    // Rollen je Tab
    $stmtTR = $pdo->query("SELECT tab_key, role FROM app_tab_roles");
    while ($row = $stmtTR->fetch(PDO::FETCH_ASSOC)) {
        $tabKey = $row['tab_key'];
        $role   = $row['role'];
        if (!isset($tabRolesMap[$tabKey])) {
            $tabRolesMap[$tabKey] = [];
        }
        $tabRolesMap[$tabKey][] = $role;
    }
        // Zugriffs-Logs (verweigerte Zugriffe aus auth_embed.php)
    $stmtLogSummary = $pdo->query("
      SELECT request_uri, COUNT(*) AS cnt, MAX(attempted_at) AS last_attempt
      FROM app_access_denied_log
      GROUP BY request_uri
      ORDER BY cnt DESC
      LIMIT 10
    ");
    $accessSummary = $stmtLogSummary->fetchAll(PDO::FETCH_ASSOC);

    $stmtLogList = $pdo->query("
      SELECT attempted_at, user_id, username_snapshot,
             request_uri, remote_ip, deny_mode, reason
      FROM app_access_denied_log
      ORDER BY attempted_at DESC
      LIMIT 50
    ");
    $accessLogs = $stmtLogList->fetchAll(PDO::FETCH_ASSOC);

    foreach ($accessSummary as $row) {
        $accessTotal += (int)$row['cnt'];
    }
    $lastDenied = $accessLogs[0] ?? null;

}


function loadInventoryCfg(string $path): array {
  $cfg = [
    'active' => false,
    'zones' => [],
    'updated_at' => null,
    'updated_by' => null
  ];

  if (!is_file($path)) return $cfg;

  $raw = @file_get_contents($path);
  $js  = json_decode((string)$raw, true);
  if (is_array($js)) $cfg = array_merge($cfg, $js);

  $cfg['active'] = !empty($cfg['active']);
  $cfg['zones']  = (isset($cfg['zones']) && is_array($cfg['zones'])) ? array_values($cfg['zones']) : [];
  return $cfg;
}


function saveInventoryCfg(string $path, array $cfg): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Inventur-Konfig Ordner konnte nicht erstellt werden.');
  }

  // Backup
  if (is_file($path)) {
    @copy($path, $path . '.bak_' . date('Ymd_His'));
  }

  $tmp  = $path . '.tmp';
  $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON encode fehlgeschlagen.');

  if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
    throw new RuntimeException('Konnte tmp-Datei nicht schreiben (Rechte?).');
  }
  if (!@rename($tmp, $path)) {
    throw new RuntimeException('Konnte tmp nicht ersetzen (Rechte?).');
  }
}

function loadVehCfg(string $path): array {
  if (!is_file($path)) {
    return ['toursPerDay' => 4, 'vehicles' => []];
  }
  $raw = file_get_contents($path);
  $cfg = json_decode($raw, true);
  if (!is_array($cfg)) {
    return ['toursPerDay' => 4, 'vehicles' => []];
  }
  if (!isset($cfg['toursPerDay'])) $cfg['toursPerDay'] = 4;
  if (!isset($cfg['vehicles']) || !is_array($cfg['vehicles'])) $cfg['vehicles'] = [];
  return $cfg;
}

$invCfg = loadInventoryCfg($invCfgPath);

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'save_inventory_cfg')) {
  try {
    $active = isset($_POST['inv_active']) ? true : false;
    $zonesRaw = $_POST['inv_zones'] ?? [];
$zonesRaw = is_array($zonesRaw) ? $zonesRaw : [];
$zones    = array_values(array_unique(array_intersect($zonesRaw, $invZoneOptions)));

$invCfg = [
  'active' => $active,
  'zones' => $zones,   // ✅ NEU
  'updated_at' => date('c'),
  'updated_by' => ($_SESSION['username'] ?? ($_SESSION['display_name'] ?? '')),
];
    saveInventoryCfg($invCfgPath, $invCfg);

    $invSuccess = $active ? 'Inventur ist jetzt AKTIV ✅' : 'Inventur ist jetzt AUS ❌';
  } catch (Throwable $e) {
    $invError = $e->getMessage();
  }
}

$invCfg = loadInventoryCfg($invCfgPath);
$invActive = !empty($invCfg['active']);


// =====================================================
// Inventur Report (Admin Anzeige) – aus inventur_rows
// =====================================================
$invReportDay  = $_GET['inv_day']   ?? date('Y-m-d');
$invReportHall = $_GET['inv_halle'] ?? 'H3';

$invReportZones = $invCfg['zones'] ?? [];
if (!is_array($invReportZones)) $invReportZones = [];

$invSummaryRows = [];
$invDetailRows  = [];

if ($isAdmin && !empty($invReportZones)) {
  try {
    // IN (...) Platzhalter dynamisch bauen
    $ph = implode(',', array_fill(0, count($invReportZones), '?'));

    // 1) Summary pro Zone (nur Rows die an dem Tag COUNT oder CHECK hatten)
    $sqlSum = "
      SELECT
        zone,
        COUNT(*)                                         AS rows_total,
        SUM(count_time IS NOT NULL)                      AS rows_counted,
        SUM(check_time IS NOT NULL)                      AS rows_checked,
        SUM(status = 'ok')                               AS rows_ok,
        SUM(status IS NOT NULL AND status <> 'ok')       AS rows_issue
      FROM inventur_rows
      WHERE halle = ?
        AND zone IN ($ph)
        AND (
          DATE(count_time) = ?
          OR DATE(check_time) = ?
        )
      GROUP BY zone
      ORDER BY zone
    ";

    $paramsSum = array_merge([$invReportHall], $invReportZones, [$invReportDay, $invReportDay]);
    $st = $pdo->prepare($sqlSum);
    $st->execute($paramsSum);
    $invSummaryRows = $st->fetchAll(PDO::FETCH_ASSOC);

    // 2) Details (letzte Einträge des Tages)
    $sqlDet = "
      SELECT
        zone, reihe, soll_menge,
        count_menge, count_user, count_time,
        check_menge, check_user, check_time,
        status
      FROM inventur_rows
      WHERE halle = ?
        AND zone IN ($ph)
        AND (
          DATE(count_time) = ?
          OR DATE(check_time) = ?
        )
      ORDER BY COALESCE(check_time, count_time) DESC
      LIMIT 250
    ";

    $paramsDet = array_merge([$invReportHall], $invReportZones, [$invReportDay, $invReportDay]);
    $st2 = $pdo->prepare($sqlDet);
    $st2->execute($paramsDet);
    $invDetailRows = $st2->fetchAll(PDO::FETCH_ASSOC);

  } catch (Throwable $e) {
    // optional: du kannst das in $invError packen, wenn du es oben anzeigen willst
    // $invError = 'Inventur-Report Fehler: ' . $e->getMessage();
  }
}


$vehCfg = loadVehCfg($vehCfgPath);

// Speichern
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_vehicles_cfg') {
  try {
    $toursPerDay = (int)($_POST['toursPerDay'] ?? ($vehCfg['toursPerDay'] ?? 4));
    if ($toursPerDay < 1) $toursPerDay = 1;

    $vehiclesIn = $_POST['vehicles'] ?? null;
    if (!is_array($vehiclesIn)) {
      throw new RuntimeException('Keine Fahrzeugdaten übermittelt.');
    }

    $seen = [];
    $vehiclesOut = [];

    foreach ($vehiclesIn as $row) {
      $id = trim((string)($row['id'] ?? ''));
      $title = trim((string)($row['title'] ?? ''));
      $plate = trim((string)($row['plate'] ?? ''));
      $driver = trim((string)($row['driver'] ?? ''));

      if ($id === '') throw new RuntimeException('Fahrzeug ohne ID gefunden.');
      if (isset($seen[$id])) throw new RuntimeException("Doppelte Fahrzeug-ID: {$id}");
      $seen[$id] = true;

      // Wenn plate leer ist, nehmen wir title als Anzeige (und umgekehrt)
      if ($title === '' && $plate !== '') $title = $plate;
      if ($plate === '' && $title !== '') $plate = $title;

      $vehiclesOut[] = [
        'id' => $id,
        'title' => $title,
        'plate' => $plate,
        'driver' => $driver,
      ];
    }

    $newCfg = [
      'toursPerDay' => $toursPerDay,
      'vehicles' => $vehiclesOut,
    ];

    // Backup
    if (is_file($vehCfgPath)) {
      @copy($vehCfgPath, $vehCfgPath . '.bak_' . date('Ymd_His'));
    }

    // Atomar schreiben
    $tmp = $vehCfgPath . '.tmp';
    $json = json_encode($newCfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('JSON encode fehlgeschlagen.');

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
      throw new RuntimeException('Konnte tmp-Datei nicht schreiben (Rechte?).');
    }
    if (!@rename($tmp, $vehCfgPath)) {
      throw new RuntimeException('Konnte tmp nicht ersetzen (Rechte?).');
    }

    $vehSuccess = 'Fahrzeuge gespeichert ✅';
    $vehCfg = loadVehCfg($vehCfgPath); // neu laden

  } catch (Throwable $e) {
    $vehError = $e->getMessage();
  }
}
?>


<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Adminbereich</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <!-- Bootstrap Icons -->
  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"
  >
  <link rel="stylesheet" href="admin.css">

  <!-- Tailwind (für vorhandenes Layout/Formulare etc.) -->
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { DEFAULT: '#0ea5e9', dark: '#0369a1' }
          }
        }
      }
    };
  </script>
</head>
<body class="bg-slate-100 text-slate-900 text-sm">
  <div class="container py-md-5 admin-page">
    
    <?php if (!$isAdmin): ?>
      <!-- Login-Karte -->
      <div class="card shadow-sm" style="max-width: 420px;">
        <div class="card-body">
          <?php if ($loginError): ?>
            <div class="alert alert-danger small mb-3">
              <?=htmlspecialchars($loginError)?>
            </div>
          <?php endif; ?>

          <form method="post" class="small">
            <input type="hidden" name="action" value="login">

            <div class="mb-3">
              <label class="form-label small mb-1">Benutzername</label>
              <input type="text"
                     name="username"
                     class="form-control form-control-sm"
                     autocomplete="username"
                     required>
            </div>

            <div class="mb-3">
              <label class="form-label small mb-1">Passwort</label>
              <input type="password"
                     name="password"
                     class="form-control form-control-sm"
                     autocomplete="current-password"
                     required>
            </div>

            <div class="d-flex justify-content-between align-items-center">
              <button type="submit" class="btn btn-primary btn-sm">
                Anmelden
              </button>
            </div>
          </form>
        </div>
      </div>

    <?php else: ?>
      <?php
      // Kleine Helfer für Kachel-Infos
      $userCount = (isset($allUsers) && is_array($allUsers)) ? count($allUsers) : 0;
      $docCount  = (isset($docs) && is_array($docs)) ? count($docs) : 0;

      if ($docCount > 0) {
          $lastDocTitle = $docs[0]['title'] ?? ($docs[0]['original_name'] ?? 'Unbekanntes Dokument');
          $lastDocDate  = !empty($docs[0]['created_at'])
              ? date('d.m.Y H:i', strtotime($docs[0]['created_at']))
              : 'ohne Datum';

          $docTooltip = "Letzter Upload: {$lastDocDate} – {$lastDocTitle}";
      } else {
          $docTooltip = "Noch keine Dokumente hochgeladen.";
      }
      ?>

      
<!-- Admin Layout: links Tiles, rechts Inhalt -->
<div class="admin-shell mt-3">

  <!-- LINKS: Nav-Tiles -->
  <aside class="admin-sidebar">
    <p class="text-muted small mb-2">Wähle einen Bereich:</p>

    <div class="d-grid gap-2">
      <!-- USERS -->
      <div class="tile-card compact"
           data-admin-tile="users"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="<?=$userCount?> Benutzer im System">
        <div class="tile-icon"><i class="bi bi-people-fill"></i></div>

        <div class="flex-grow-1">
          <div class="tile-title">Benutzerverwaltung</div>
          <div class="tile-text">User, Rollen, aktiv/inaktiv.</div>

          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-shield-lock-fill"></i> Nutzer &amp; Rechte</span>
          </div>
        </div>

        <div class="tile-meta">
          <span class="badge rounded-pill text-bg-light border small"><?=$userCount?></span>
        </div>
      </div>

      <!-- VEHICLES -->
      <div class="tile-card compact"
           data-admin-tile="vehicles"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="veh_cfg.json bearbeiten">
        <div class="tile-icon"><i class="bi bi-truck"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Fahrzeuge</div>
          <div class="tile-text">Kennzeichen, Tab-Name, Fahrer.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-gear"></i> Konfiguration</span>
          </div>
        </div>
      </div>

      <!-- CATEGORIES -->
      <div class="tile-card compact"
           data-admin-tile="categories"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="<?=isset($categories) && is_array($categories) ? count($categories) : 0?> Kategorien im System">
        <div class="tile-icon"><i class="bi bi-tags-fill"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Kategorien</div>
          <div class="tile-text">Anlegen, sortieren, aufräumen.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-diagram-3-fill"></i> Struktur</span>
          </div>
        </div>
        <div class="tile-meta">
          <span class="badge rounded-pill text-bg-light border small">
            <?=isset($categories) && is_array($categories) ? count($categories) : 0?>
          </span>
        </div>
      </div>

      <!-- DOCS -->
      <div class="tile-card compact"
           data-admin-tile="docs"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="<?=htmlspecialchars($docTooltip, ENT_QUOTES)?>">
        <div class="tile-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Dokumente</div>
          <div class="tile-text">Upload, Edit, Archiv.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-folder2-open"></i> Dokumente</span>
          </div>
        </div>
        <div class="tile-meta">
          <span class="badge rounded-pill text-bg-light border small"><?=$docCount?></span>
        </div>
      </div>

      <!-- LAGER BESTAND -->
<div class="tile-card compact"
     data-admin-tile="lagerbestand"
     data-bs-toggle="tooltip"
     data-bs-placement="top"
     title="Bestände anzeigen / prüfen">
  <div class="tile-icon"><i class="bi bi-box-seam-fill"></i></div>
  <div class="flex-grow-1">
    <div class="tile-title">Lager Bestand</div>
    <div class="tile-text">Bestände, Status, Abweichungen.</div>
    <div class="d-flex align-items-center gap-2 mt-1">
      <span class="tile-badge"><i class="bi bi-clipboard-data"></i> Übersicht</span>
    </div>
  </div>
</div>


      <!-- INVENTUR -->
      <div class="tile-card compact"
           data-admin-tile="inventur"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="Inventur global ein-/ausschalten">
        <div class="tile-icon"><i class="bi bi-clipboard-check"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Inventur</div>
          <div class="tile-text">Inventur-Modus schalten.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-toggle-on"></i> Schalter</span>
          </div>
        </div>
        <div class="tile-meta">
          <span class="badge rounded-pill small <?= $invActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
            <?= $invActive ? 'aktiv' : 'aus' ?>
          </span>
        </div>
      </div>

      <!-- TABS -->
      <div class="tile-card compact"
           data-admin-tile="tabs"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="Welche Rolle sieht welchen Tab">
        <div class="tile-icon"><i class="bi bi-layout-wtf"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Tabs &amp; Rollen</div>
          <div class="tile-text">Sichtbarkeit konfigurieren.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-eye"></i> Sichtbarkeit</span>
          </div>
        </div>
      </div>

      <!-- LOGS -->
      <div class="tile-card compact"
           data-admin-tile="logs"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           title="<?=$accessTotal ? ($accessTotal.' verweigerte Zugriffe') : 'Keine Logs'?>">
        <div class="tile-icon"><i class="bi bi-shield-exclamation"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Zugriffsprotokoll</div>
          <div class="tile-text">Verweigerte Aufrufe.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-lock-fill"></i> Security</span>
          </div>
        </div>
        <div class="tile-meta">
          <span class="badge rounded-pill text-bg-light border small"><?=$accessTotal?></span>
        </div>
      </div>
    </div>
  </aside>         
     

       <!-- RECHTS: Content -->
  <main class="admin-content"> 
      
<!-- LAGER BESTAND ----------------------------------------------->
<section id="admin-lagerbestand"
         data-admin-section="lagerbestand"
         class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

  <div class="d-flex justify-content-between align-items-center">
    <h2 class="text-sm font-semibold text-slate-900 mb-0">Lager Bestand</h2>
    <span class="text-muted small">Übersicht + Export nach Lagergruppe</span>
  </div>

  <!-- Unter-Kacheln -->
  <div class="row g-3 mt-1 mb-3" id="lagerbestandSubTiles">
    <div class="col-12 col-md-4">
      <div class="tile-card compact tile-active h-100"
           data-lagerbestand-tile="rows"
           style="cursor:pointer;">
        <div class="tile-icon"><i class="bi bi-layout-text-sidebar-reverse"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Lager Reihen anpassen</div>
          <div class="tile-text">Reihenbereich, Standardplätze, Slots, Sonderregeln.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-sliders"></i> Konfiguration</span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="tile-card compact h-100"
           data-lagerbestand-tile="summary"
           style="cursor:pointer;">
        <div class="tile-icon"><i class="bi bi-table"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Lagergruppen Übersicht</div>
          <div class="tile-text">Filter, Tabelle, Export, LKW-Übersicht.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-file-earmark-excel"></i> Auswertung</span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="tile-card compact h-100"
           data-lagerbestand-tile="third"
           style="cursor:pointer;">
        <div class="tile-icon"><i class="bi bi-plus-square-dotted"></i></div>
        <div class="flex-grow-1">
          <div class="tile-title">Weiterer Bereich</div>
          <div class="tile-text">Hier kann später noch etwas ergänzt werden.</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="tile-badge"><i class="bi bi-clock-history"></i> Platzhalter</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PANE 1: Reihen -->
  <div id="lagerbestandPaneRows" data-lagerbestand-pane="rows">
    <div class="card shadow-sm border-0 mt-3" id="lagerRowsAdminCard">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h5 class="mb-1 fw-bold text-primary">
              <i class="bi bi-layout-text-sidebar-reverse me-2"></i>Lagerreihen Bearbeitung
            </h5>
            <div class="text-muted small">
              Reihen zentral verwalten, ohne mehrere JS-Dateien anfassen zu müssen.
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-secondary" id="cfgRowRangeBadge">Reihen: –</span>
            <span class="badge text-bg-warning text-dark" id="cfgHighestUsedBadge">Höchste belegte Reihe: –</span>
          </div>
        </div>

        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-2">
            <label for="cfgHalle" class="form-label fw-semibold">Halle</label>
            <input type="text" class="form-control" id="cfgHalle" value="H3">
          </div>

          <div class="col-12 col-md-2">
            <label for="cfgZone" class="form-label fw-semibold">Zone</label>
            <input type="text" class="form-control" id="cfgZone" value="W1">
          </div>

          <div class="col-12 col-md-2">
            <label for="cfgRowFrom" class="form-label fw-semibold">Von Reihe</label>
            <input type="number" min="1" step="1" class="form-control" id="cfgRowFrom" value="1">
          </div>

          <div class="col-12 col-md-2">
            <label for="cfgRowTo" class="form-label fw-semibold">Bis Reihe</label>
            <input type="number" min="1" step="1" class="form-control" id="cfgRowTo" value="200">
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label fw-semibold">Anzahl</label>
            <div class="form-control bg-light" id="cfgRowCount">200</div>
          </div>

          <div class="col-12 col-md-2">
            <div class="d-grid">
              <button type="button" class="btn btn-primary" id="btnCfgSave">
                <i class="bi bi-save me-1"></i>Speichern
              </button>
            </div>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCfgReload">
            <i class="bi bi-arrow-clockwise me-1"></i>Neu laden
          </button>

          <button type="button" class="btn btn-outline-success btn-sm" id="btnCfgPlus10">
            +10 Reihen
          </button>

          <button type="button" class="btn btn-outline-danger btn-sm" id="btnCfgMinus10">
            -10 Reihen
          </button>

          <button type="button" class="btn btn-outline-dark btn-sm" id="btnCfgUseHighest">
            Auf höchste belegte Reihe setzen
          </button>
        </div>

        <div class="alert alert-light border mt-3 mb-0 small">
          <div class="fw-semibold mb-1">Sicherheitslogik</div>
          <div>
            Beim Kürzen der Reihen blockiert das System automatisch, wenn oberhalb der neuen Endreihe noch aktive Lagerplätze vorhanden sind.
            Dadurch werden keine bestehenden Lagerdaten aus <code>lager_slots</code> entfernt.
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 align-items-end mt-1">
      <div class="col-12 col-md-3">
        <label for="cfgDefaultPlaces" class="form-label fw-semibold">Standard Plätze / Reihe</label>
        <input type="number" min="1" step="1" class="form-control" id="cfgDefaultPlaces" value="40">
      </div>

      <div class="col-12 col-md-3">
        <label for="cfgDefaultSlots" class="form-label fw-semibold">Standard Slots / Platz</label>
        <input type="number" min="1" step="1" class="form-control" id="cfgDefaultSlots" value="4">
      </div>

      <div class="col-12 col-md-6">
        <div class="alert alert-light border mb-0 small">
          Standardwerte gelten für alle Reihen ohne Sonderregel.
        </div>
      </div>
    </div>

    <hr class="my-4">

    <div class="d-flex justify-content-between align-items-center mb-2">
      <div>
        <div class="fw-semibold">Sonderregeln pro Reihe</div>
        <div class="text-muted small">Beispiel: Reihe 210 = 134 Plätze / 4 Slots</div>
      </div>
    </div>

    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-2">
        <label for="cfgOvRow" class="form-label fw-semibold">Reihe</label>
        <input type="number" min="1" step="1" class="form-control" id="cfgOvRow">
      </div>

      <div class="col-12 col-md-3">
        <label for="cfgOvPlaces" class="form-label fw-semibold">Plätze</label>
        <input type="number" min="1" step="1" class="form-control" id="cfgOvPlaces">
      </div>

      <div class="col-12 col-md-3">
        <label for="cfgOvSlots" class="form-label fw-semibold">Slots / Platz</label>
        <input type="number" min="1" step="1" class="form-control" id="cfgOvSlots">
      </div>

      <div class="col-12 col-md-4">
        <div class="d-grid">
          <button type="button" class="btn btn-success" id="btnCfgOvSave">
            <i class="bi bi-plus-circle me-1"></i>Sonderregel speichern
          </button>
        </div>
      </div>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Reihe</th>
            <th class="text-end">Plätze</th>
            <th class="text-end">Slots / Platz</th>
            <th class="text-end">Aktion</th>
          </tr>
        </thead>
        <tbody id="cfgOverrideTableBody">
          <tr>
            <td colspan="4" class="text-muted">Lade Sonderregeln…</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- PANE 2: Übersicht -->
  <div id="lagerbestandPaneSummary" data-lagerbestand-pane="summary" class="d-none">
    <div class="row g-3">
      <div class="col-12">
        <div class="card h-100 shadow-sm w-100" id="lgFilterCard">
          <div class="card-header d-flex align-items-start justify-content-between gap-2">
            <div>
              <div class="fw-semibold">Filter: Lagergruppe</div>
              <div class="text-muted small">für Export / Auswertung</div>
            </div>

            <span id="lgActiveBadge" class="badge text-bg-secondary align-self-start">Aktiv: alle</span>
          </div>

          <div class="card-body">
            <div id="lgFilterList" class="row g-1">
              <?php
                $lgOptions = $invZoneOptions;
                foreach ($lgOptions as $z):
                  $safeId = 'lg_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $z);
              ?>
                <div class="col-6 col-xl-3">
                  <div class="form-check m-0">
                    <input class="form-check-input lg-check"
                           type="checkbox"
                           value="<?=htmlspecialchars($z)?>"
                           id="<?=htmlspecialchars($safeId)?>"
                           checked>
                    <label class="form-check-label small fw-semibold" for="<?=htmlspecialchars($safeId)?>">
                      <?=htmlspecialchars($z)?>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="d-grid gap-2 mt-3">
              <button id="btnLgAll" class="btn btn-sm btn-outline-secondary" type="button">Alle</button>
              <button id="btnLgNone" class="btn btn-sm btn-outline-secondary" type="button">Keine</button>
              <button id="btnLgExport" class="btn btn-sm btn-primary" type="button">Excel</button>
            </div>

            <div class="text-muted small mt-2" id="lgFilterHint">
              Wenn nichts ausgewählt ist, gilt „alle“.
            </div>

            <hr class="my-3">

            <div class="fw-semibold mb-2" style="font-size:12px;">Übersicht (aktuell im Plan)</div>

            <div class="table-responsive" style="max-height:240px; overflow:auto;">
              <table class="table table-sm table-striped align-middle mb-0" id="lgSummaryTable">
                <thead class="table-light">
                  <tr>
                    <th style="position:sticky; top:0; z-index:1;">LG</th>
                    <th class="text-end" style="position:sticky; top:0; z-index:1;">Paletten</th>
                    <th class="text-end" style="position:sticky; top:0; z-index:1;">Stück</th>
                    <th class="text-end" style="position:sticky; top:0; z-index:1;">Sachnr.</th>
                    <th class="text-end" style="position:sticky; top:0; z-index:1;">Verpackung</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td colspan="5" class="text-muted">Lade Daten…</td></tr>
                </tbody>
              </table>
            </div>

            <div class="small text-muted mt-2" id="lgSummaryTotal">
              <div><b>Gesamt:</b> —</div>
            </div>

            <div id="lgLkwInfo" class="mt-2 p-2 rounded border bg-light">
              <div id="lgLkwMain" class="small fw-semibold">LKW-Übersicht: lädt …</div>
              <div id="lgLkwSub" class="small text-muted"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PANE 3: Platzhalter -->
  <div id="lagerbestandPaneThird" data-lagerbestand-pane="third" class="d-none">
    <div class="card shadow-sm border-0 mt-3">
      <div class="card-body">
        <h5 class="mb-2 fw-bold text-primary">
          <i class="bi bi-plus-square-dotted me-2"></i>Platzhalter
        </h5>
        <div class="text-muted small">
          Dieser Bereich ist noch frei. Hier können wir später z. B. Detailtabellen,
          Suchfunktionen oder Bestandsprüfungen einbauen.
        </div>
      </div>
    </div>
  </div>
</section>

      <section id="admin-inventur"
         data-admin-section="inventur"
         class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

  <div class="flex items-center justify-between">
    <h2 class="text-sm font-semibold text-slate-900">Inventur</h2>

    <?php if (!empty($invError)): ?>
      <span class="text-[11px] text-red-600"><?=htmlspecialchars($invError)?></span>
    <?php elseif (!empty($invSuccess)): ?>
      <span class="text-[11px] text-emerald-600"><?=htmlspecialchars($invSuccess)?></span>
    <?php endif; ?>
  </div>

  <form method="post" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs space-y-3">
  <input type="hidden" name="action" value="save_inventory_cfg">

  <div class="form-check form-switch">
    <input class="form-check-input"
           type="checkbox"
           role="switch"
           id="invActiveSwitch"
           name="inv_active"
           <?= $invActive ? 'checked' : '' ?>>
    <label class="form-check-label" for="invActiveSwitch">
      <b>Inventur aktiv</b> (im Lagerplan werden Inventur-Checkboxen, Progress, Etiketten, QR usw. angezeigt)
    </label>
  </div>

  <div class="text-[11px] text-slate-500">
    Wenn <b>aus</b>: Im Lagerplan ist von Inventur <b>nichts</b> zu sehen.
  </div>

  <!-- ✅ HIER rein -->
  <div class="mt-2">
    <div class="fw-semibold mb-1">Bereiche, die gezählt werden</div>
    <div class="d-flex flex-wrap gap-2">
      <?php
        $selZones = $invCfg['zones'] ?? [];
        if (!is_array($selZones)) $selZones = [];
      ?>
      <?php foreach ($invZoneOptions as $z): ?>
        <label class="btn btn-sm btn-outline-primary">
          <input type="checkbox"
                 name="inv_zones[]"
                 value="<?=htmlspecialchars($z)?>"
                 <?= in_array($z, $selZones, true) ? 'checked' : '' ?>
                 style="margin-right:6px;">
          <?=htmlspecialchars($z)?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="text-[11px] text-slate-500 mt-1">
      Lagerplan zeigt Inventur nur in ausgewählten Bereichen.
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
    <span class="btn btn-sm <?= $invActive ? 'btn-success' : 'btn-outline-secondary' ?> disabled">
      Status: <?= $invActive ? 'AKTIV' : 'AUS' ?>
    </span>
  </div>

  <div class="text-[10px] text-slate-500">
    Zuletzt geändert: <?= !empty($invCfg['updated_at']) ? htmlspecialchars($invCfg['updated_at']) : '—' ?>
    · von: <?= !empty($invCfg['updated_by']) ? htmlspecialchars($invCfg['updated_by']) : '—' ?>
  </div>
</form>

<!-- ===================================================== -->
<!-- Inventur Ergebnisse -->
<!-- ===================================================== -->
<div class="rounded-lg border border-slate-200 bg-white p-3 text-xs">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Ergebnisse (Inventur-Scans)</div>

    <form method="get" class="d-flex gap-2 align-items-center">
      <?php if (!empty($_GET['embed'])): ?>
        <input type="hidden" name="embed" value="1">
      <?php endif; ?>
      <input type="hidden" name="section" value="inventur">

      <input type="text" name="inv_halle" value="<?=htmlspecialchars($invReportHall)?>"
             class="form-control form-control-sm" style="max-width:90px" placeholder="Halle">

      <input type="date" name="inv_day" value="<?=htmlspecialchars($invReportDay)?>"
             class="form-control form-control-sm" style="max-width:160px">

      <button class="btn btn-sm btn-outline-primary" type="submit">Anzeigen</button>
    </form>
  </div>

  <?php if (empty($invCfg['zones'])): ?>
    <div class="text-muted small">Keine Bereiche ausgewählt – daher keine Auswertung.</div>

  <?php else: ?>
    <?php
      // Totals berechnen
      $t_rows = $t_count = $t_check = $t_ok = $t_issue = 0;
      foreach ($invSummaryRows as $r) {
        $t_rows  += (int)$r['rows_total'];
        $t_count += (int)$r['rows_counted'];
        $t_check += (int)$r['rows_checked'];
        $t_ok    += (int)$r['rows_ok'];
        $t_issue += (int)$r['rows_issue'];
      }
    ?>

    <div class="d-flex flex-wrap gap-2 mb-2">
      <span class="badge text-bg-secondary">Rows: <?=$t_rows?></span>
      <span class="badge text-bg-info">COUNT: <?=$t_count?></span>
      <span class="badge text-bg-primary">CHECK: <?=$t_check?></span>
      <span class="badge text-bg-success">OK: <?=$t_ok?></span>
      <span class="badge text-bg-danger">Abweichung: <?=$t_issue?></span>
    </div>

    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>LG</th>
            <th class="text-end">Rows</th>
            <th class="text-end">COUNT</th>
            <th class="text-end">CHECK</th>
            <th class="text-end">OK</th>
            <th class="text-end">Abw.</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invSummaryRows): ?>
            <tr><td colspan="6" class="text-muted">Keine Scans an diesem Tag.</td></tr>
          <?php else: ?>
            <?php foreach ($invSummaryRows as $r): ?>
              <tr>
                <td class="fw-semibold"><?=htmlspecialchars($r['zone'])?></td>
                <td class="text-end"><?= (int)$r['rows_total'] ?></td>
                <td class="text-end"><?= (int)$r['rows_counted'] ?></td>
                <td class="text-end"><?= (int)$r['rows_checked'] ?></td>
                <td class="text-end"><?= (int)$r['rows_ok'] ?></td>
                <td class="text-end"><?= (int)$r['rows_issue'] ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="fw-semibold mb-2">Details (letzte 250 Einträge)</div>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>LG</th>
            <th class="text-end">Reihe</th>
            <th class="text-end">Soll</th>
            <th class="text-end">Count</th>
            <th>Count User</th>
            <th>Count Zeit</th>
            <th class="text-end">Check</th>
            <th>Check User</th>
            <th>Check Zeit</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invDetailRows): ?>
            <tr><td colspan="10" class="text-muted">Keine Detaildaten.</td></tr>
          <?php else: ?>
            <?php foreach ($invDetailRows as $d): ?>
              <?php
                $st = (string)($d['status'] ?? '');
                $badge =
                  ($st === 'ok') ? 'text-bg-success' :
                  (($st === 'abweichung_system' || $st === 'abweichung') ? 'text-bg-danger' :
                  ($st ? 'text-bg-warning' : 'text-bg-secondary'));
              ?>
              <tr>
                <td class="fw-semibold"><?=htmlspecialchars($d['zone'])?></td>
                <td class="text-end"><?= (int)$d['reihe'] ?></td>
                <td class="text-end"><?= (int)$d['soll_menge'] ?></td>
                <td class="text-end"><?= ($d['count_menge'] !== null ? (int)$d['count_menge'] : '—') ?></td>
                <td><?=htmlspecialchars($d['count_user'] ?? '—')?></td>
                <td><?=htmlspecialchars($d['count_time'] ?? '—')?></td>
                <td class="text-end"><?= ($d['check_menge'] !== null ? (int)$d['check_menge'] : '—') ?></td>
                <td><?=htmlspecialchars($d['check_user'] ?? '—')?></td>
                <td><?=htmlspecialchars($d['check_time'] ?? '—')?></td>
                <td><span class="badge <?=$badge?>"><?=htmlspecialchars($st ?: 'offen')?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>
</div>
</section>
      <!-- TABS & ROLLEN ----------------------------------------------->
      <section id="admin-tabs"
               data-admin-section="tabs"
               class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-900">Tabs &amp; Rollen</h2>
          <?php if ($tabsError): ?>
            <span class="text-[11px] text-red-600"><?=htmlspecialchars($tabsError)?></span>
          <?php elseif ($tabsSuccess): ?>
            <span class="text-[11px] text-emerald-600"><?=htmlspecialchars($tabsSuccess)?></span>
          <?php endif; ?>
        </div>

        <p class="text-[11px] text-slate-600 mb-1">
          Lege fest, welche Rolle welche Registerkarte (Tab) in der Logistik-Workbench überhaupt sehen darf.
        </p>

        <?php if (!$tabsConfig): ?>
          <p class="text-[11px] text-slate-500">
            Noch keine Tabs in <code>app_tabs</code> hinterlegt.
          </p>
        <?php else: ?>
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="text-muted small">
                  <tr>
                    <th style="width: 18%;">Tab</th>
                    <th style="width: 32%;">Beschreibung</th>
                    <th>Rollen</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tabsConfig as $t):
                    $tabKey   = $t['tab_key'];
                    $tabLabel = $t['label'];
                    $tabDesc  = $t['description'] ?? '';
                    $tabRoles = $tabRolesMap[$tabKey] ?? [];
                  ?>
                    <tr>
                      <td>
                        <div class="fw-semibold text-slate-900">
                          <?=htmlspecialchars($tabLabel)?>
                        </div>
                        <div class="text-[10px] text-slate-500">
                          Key: <code><?=htmlspecialchars($tabKey)?></code>
                        </div>
                      </td>
                      <td class="text-[11px] text-slate-700">
                        <?=htmlspecialchars($tabDesc)?>
                      </td>
                      <td>
                        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                          <input type="hidden" name="action" value="save_tab_roles">
                          <input type="hidden" name="tab_key" value="<?=htmlspecialchars($tabKey)?>">

                          <?php foreach ($roleOptions as $r): ?>
                            <div class="form-check form-check-inline mb-1">
                              <input class="form-check-input"
                                     type="checkbox"
                                     name="roles[]"
                                     id="tab-<?=htmlspecialchars($tabKey)?>-<?=htmlspecialchars($r)?>"
                                     value="<?=$r?>"
                                     <?=in_array($r, $tabRoles, true) ? 'checked' : ''?>>
                              <label class="form-check-label text-[11px]"
                                     for="tab-<?=htmlspecialchars($tabKey)?>-<?=htmlspecialchars($r)?>">
                                <?=$r?>
                              </label>
                            </div>
                          <?php endforeach; ?>

                          <button type="submit"
                                  class="btn btn-sm btn-primary ms-2 text-[11px]">
                            Speichern
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <p class="mt-2 text-[10px] text-slate-500">
          Hinweis: In der <code>index.php</code> muss die Sichtbarkeit der Tabs aus
          <code>app_tab_roles</code> ausgelesen werden (statt einer festen PHP-Konfiguration).
        </p>
      </section>

      <!-- ZUGRIFFS-LOGS ---------------------------------------------->
      <section id="admin-logs"
               data-admin-section="logs"
               class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

        <div class="flex items-center justify-between mb-1">
          <h2 class="text-sm font-semibold text-slate-900">Zugriffsprotokoll</h2>
          <p class="text-[11px] text-slate-500 mb-0">
            Verweigerte Zugriffe auf gesperrte Seiten (Guard <code>auth_embed.php</code>).
          </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
            <h3 class="font-semibold text-slate-900 mb-2 text-xs">Top 10 gesperrte Seiten</h3>

            <?php if (!$accessSummary): ?>
              <p class="text-[11px] text-slate-500 mb-0">
                Bisher keine Einträge in <code>app_access_denied_log</code>.
              </p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="text-muted small">
                    <tr>
                      <th>Pfad</th>
                      <th class="text-end">Versuche</th>
                      <th>Letzter Versuch</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($accessSummary as $row): ?>
                      <tr>
                        <td class="text-break">
                          <code><?=htmlspecialchars($row['request_uri'])?></code>
                        </td>
                        <td class="text-end"><?= (int)$row['cnt'] ?></td>
                        <td><?=htmlspecialchars(date('d.m.Y H:i', strtotime($row['last_attempt'])))?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
            <h3 class="font-semibold text-slate-900 mb-2 text-xs">Letzte 20 Versuche</h3>

            <?php if (!$accessLogs): ?>
              <p class="text-[11px] text-slate-500 mb-0">
                Noch keine Logeinträge vorhanden.
              </p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="text-muted small">
                    <tr>
                      <th>Zeitpunkt</th>
                      <th>Benutzer</th>
                      <th>Pfad</th>
                      <th>IP</th>
                      <th>Grund</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach (array_slice($accessLogs, 0, 20) as $row): ?>
                      <tr>
                        <td><?=htmlspecialchars(date('d.m.Y H:i:s', strtotime($row['attempted_at'])))?></td>
                        <td>
                          <?php if (!empty($row['user_id'])): ?>
                            ID <?=$row['user_id']?> <?=htmlspecialchars($row['username_snapshot'] ?? '')?>
                          <?php else: ?>
                            (nicht angemeldet)
                          <?php endif; ?>
                        </td>
                        <td class="text-break"><code><?=htmlspecialchars($row['request_uri'])?></code></td>
                        <td><?=htmlspecialchars($row['remote_ip'])?></td>
                        <td><?=htmlspecialchars($row['reason'])?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
        
      </section>

      <!-- DOKUMENTE verwalten ---------------------------------------->
      <section id="admin-docs"
               data-admin-section="docs"
               class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4">

        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-900">Dokumente verwalten</h2>
          <?php if ($uploadError || $docError): ?>
            <span class="text-[11px] text-red-600">
              <?=htmlspecialchars($uploadError ?: $docError)?>
            </span>
          <?php elseif ($uploadSuccess || $docSuccess): ?>
            <span class="text-[11px] text-emerald-600">
              <?=htmlspecialchars($uploadSuccess ?: $docSuccess)?>
            </span>
          <?php endif; ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <!-- Upload -->
          <form method="post" enctype="multipart/form-data"
                class="rounded-lg border border-slate-200 bg-slate-50 p-3 shadow-sm space-y-3 text-xs">
            <h3 class="font-semibold text-slate-900 mb-1 text-xs">Dokument hochladen</h3>

            <input type="hidden" name="action" value="upload_doc">

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Titel</label>
              <input type="text" name="doc_title" required
                     class="rounded-md border-slate-300 text-xs"
                     placeholder="z.B. Arbeitsanweisung 100%-Prüfung">
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Halle (optional)</label>
              <select name="doc_hall" class="rounded-md border-slate-300 text-xs">
                <option value="">alle / nicht zugeordnet</option>
                <?php foreach ($allowedHalls as $h): ?>
                  <option value="<?=$h?>"><?=$h?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Kategorie</label>
              <select name="doc_category" class="rounded-md border-slate-300 text-xs">
                <option value="">ohne Kategorie</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?=$cat['name']?>"><?=$cat['name']?></option>
                <?php endforeach; ?>
              </select>
              <p class="mt-0.5 text-[10px] text-slate-500">
                Kategorien werden unten im Bereich „Kategorien verwalten“ gepflegt.
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Sichtbar für Rollen</label>
              <div class="role-checkbox-group d-flex flex-wrap gap-2" id="docRolesNewGroup">
                <div class="form-check form-check-inline">
                  <input class="form-check-input"
                         type="checkbox"
                         id="doc_roles_all"
                         name="doc_roles[]"
                         value="__all"
                         data-role-all>
                  <label class="form-check-label text-[11px]" for="doc_roles_all">
                    Alle
                  </label>
                </div>
                <?php foreach ($visibilityRoles as $r): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input"
                           type="checkbox"
                           id="doc_roles_<?=$r?>"
                           name="doc_roles[]"
                           value="<?=$r?>">
                    <label class="form-check-label text-[11px]" for="doc_roles_<?=$r?>">
                      <?=htmlspecialchars($r)?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="mt-0.5 text-[10px] text-slate-500">
                Keine Auswahl oder „Alle“ = Dokument ist für alle Rollen sichtbar (Admin sieht immer alles).
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Datei</label>
              <input type="file" name="doc_file" required class="block w-full text-xs text-slate-700">
              <p class="mt-0.5 text-[10px] text-slate-500">
                Erlaubt: PDF, Office, Bilder · max. 10 MB
              </p>
            </div>

            <div class="pt-1">
              <button type="submit"
                      class="inline-flex items-center justify-center rounded-md bg-brand text-white px-4 py-1.5 text-[11px] font-medium hover:bg-brand-dark">
                Hochladen
              </button>
            </div>
          </form>

          <!-- Dokumente bearbeiten -->
          <form method="post"
                class="rounded-lg border border-slate-200 bg-slate-50 p-3 shadow-sm space-y-3 text-xs">
            <h3 class="font-semibold text-slate-900 mb-1 text-xs">Dokument bearbeiten / archivieren</h3>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Dokument auswählen</label>
              <div class="flex gap-2">
                <select name="doc_id"
                        id="docEditSelect"
                        class="rounded-md border-slate-300 text-xs flex-1"
                        required>
                  <option value="">Bitte wählen…</option>
                  <?php foreach ($docs as $d): ?>
                    <option value="<?=$d['id']?>"
                            data-title="<?=htmlspecialchars($d['title'] ?? '', ENT_QUOTES)?>"
                            data-hall="<?=htmlspecialchars($d['hall'] ?? '', ENT_QUOTES)?>"
                            data-cat="<?=htmlspecialchars($d['category'] ?? '', ENT_QUOTES)?>"
                            data-roles="<?=htmlspecialchars($d['visible_roles'] ?? '', ENT_QUOTES)?>">
                      <?=htmlspecialchars($d['title'])?>
                      (<?=htmlspecialchars($d['original_name'])?>)
                      <?=$d['active'] ? '' : ' [ARCHIVIERT]'?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button"
                        id="btnDocFill"
                        class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-700 hover:bg-slate-50">
                  Werte übernehmen
                </button>
              </div>
              <p class="mt-0.5 text-[10px] text-slate-500">
                Es werden die letzten 50 Dokumente angezeigt (inkl. archivierter).
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Titel</label>
              <input type="text" name="doc_title_edit"
                     class="rounded-md border-slate-300 text-xs"
                     placeholder="Titel des Dokuments">
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Halle</label>
              <select name="doc_hall_edit" class="rounded-md border-slate-300 text-xs">
                <option value="">alle / nicht zugeordnet</option>
                <?php foreach ($allowedHalls as $h): ?>
                  <option value="<?=$h?>"><?=$h?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Kategorie</label>
              <select name="doc_category_edit" class="rounded-md border-slate-300 text-xs">
                <option value="">ohne Kategorie</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?=$cat['name']?>"><?=$cat['name']?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Sichtbar für Rollen</label>
              <div class="role-checkbox-group d-flex flex-wrap gap-2" id="docRolesEditGroup">
                <div class="form-check form-check-inline">
                  <input class="form-check-input"
                         type="checkbox"
                         id="doc_roles_edit_all"
                         name="doc_roles_edit[]"
                         value="__all"
                         data-role-all>
                  <label class="form-check-label text-[11px]" for="doc_roles_edit_all">
                    Alle
                  </label>
                </div>
                <?php foreach ($visibilityRoles as $r): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input"
                           type="checkbox"
                           id="doc_roles_edit_<?=$r?>"
                           name="doc_roles_edit[]"
                           value="<?=$r?>">
                    <label class="form-check-label text-[11px]" for="doc_roles_edit_<?=$r?>">
                      <?=htmlspecialchars($r)?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="pt-1 flex flex-wrap gap-2">
              <button type="submit"
                      name="action"
                      value="update_doc"
                      class="inline-flex items-center justify-center rounded-md bg-brand text-white px-4 py-1.5 text-[11px] font-medium hover:bg-brand-dark">
                Änderungen speichern
              </button>

              <button type="submit"
                      name="action"
                      value="archive_doc"
                      onclick="return confirm('Dokument wirklich archivieren? Es erscheint dann nicht mehr im Dokumentencenter.')"
                      class="inline-flex items-center justify-center rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-[11px] font-medium text-amber-700 hover:bg-amber-100">
                Dokument archivieren
              </button>
            </div>
          </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-3 text-xs">
          <h3 class="font-semibold text-slate-900 mb-2 text-xs">Letzte Dokumente</h3>
          <?php if (!$docs): ?>
            <p class="text-[11px] text-slate-500">Noch keine Dokumente vorhanden.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="min-w-full border-collapse text-[11px] text-slate-800">
                <thead class="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left">Titel</th>
                    <th class="px-3 py-2 text-left">Kategorie</th>
                    <th class="px-3 py-2 text-left">Halle</th>
                    <th class="px-3 py-2 text-left">Rollen</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left whitespace-nowrap">Hochgeladen am</th>
                    <th class="px-3 py-2 text-left">von</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php foreach ($docs as $d): ?>
                    <tr>
                      <td class="px-3 py-1.5">
                        <div class="text-[11px] font-medium text-slate-900">
                          <?=htmlspecialchars($d['title'])?>
                        </div>
                        <div class="text-[10px] text-slate-500">
                          <?=htmlspecialchars($d['original_name'])?>
                        </div>
                      </td>
                      <td class="px-3 py-1.5"><?=htmlspecialchars($d['category'] ?? '')?></td>
                      <td class="px-3 py-1.5"><?=htmlspecialchars($d['hall'] ?? '')?></td>
                      <td class="px-3 py-1.5">
                        <span class="badge rounded-pill text-bg-light border">
                          <?=htmlspecialchars(qcRenderRoles($d['visible_roles'] ?? null))?>
                        </span>
                      </td>
                      <td class="px-3 py-1.5">
                        <?php if ($d['active']): ?>
                          <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">
                            Aktiv
                          </span>
                        <?php else: ?>
                          <span class="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-medium text-slate-700">
                            Archiviert
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="px-3 py-1.5 whitespace-nowrap">
                        <?=htmlspecialchars(date('d.m.Y H:i', strtotime($d['created_at'])))?>
                      </td>
                      <td class="px-3 py-1.5 whitespace-nowrap">
                        <?=htmlspecialchars($d['uploader'] ?? '–')?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- KATEGORIEN verwalten --------------------------------------->
      <section id="admin-categories"
               data-admin-section="categories"
               class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-900">Kategorien verwalten</h2>
          <?php if ($catError): ?>
            <span class="text-[11px] text-red-600"><?=htmlspecialchars($catError)?></span>
          <?php elseif ($catSuccess): ?>
            <span class="text-[11px] text-emerald-600"><?=htmlspecialchars($catSuccess)?></span>
          <?php endif; ?>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
          <!-- Neue Kategorie -->
          <form method="post"
                class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2 text-xs">
            <input type="hidden" name="action" value="add_category">
            <h3 class="font-semibold text-slate-900 text-xs mb-1">Neue Kategorie anlegen</h3>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Name</label>
              <input type="text" name="cat_name" required
                     class="rounded-md border-slate-300 text-xs"
                     placeholder="z.B. Mitarbeiter">
            </div>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Beschreibung (optional)</label>
              <input type="text" name="cat_desc"
                     class="rounded-md border-slate-300 text-xs"
                     placeholder="z.B. Urlaubsanträge, Schichtpläne, ...">
            </div>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Sortierung</label>
              <input type="number" name="cat_sort" value="0"
                     class="rounded-md border-slate-300 text-xs">
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="font-medium text-slate-800">Sichtbar für Rollen</label>
              <div class="role-checkbox-group d-flex flex-wrap gap-2" id="catRolesNewGroup">
                <div class="form-check form-check-inline">
                  <input class="form-check-input"
                         type="checkbox"
                         id="cat_roles_all"
                         name="cat_roles[]"
                         value="__all"
                         data-role-all>
                  <label class="form-check-label text-[11px]" for="cat_roles_all">
                    Alle
                  </label>
                </div>
                <?php foreach ($visibilityRoles as $r): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input"
                           type="checkbox"
                           id="cat_roles_<?=$r?>"
                           name="cat_roles[]"
                           value="<?=$r?>">
                    <label class="form-check-label text-[11px]" for="cat_roles_<?=$r?>">
                      <?=htmlspecialchars($r)?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="mt-0.5 text-[10px] text-slate-500">
                Keine Auswahl oder „Alle“ = Kategorie ist für alle Rollen sichtbar.
              </p>
            </div>

            <div class="pt-1">
              <button type="submit"
                      class="inline-flex items-center justify-center rounded-md bg-brand text-white px-3 py-1 text-[11px] font-medium hover:bg-brand-dark">
                Kategorie speichern
              </button>
            </div>
          </form>

          <!-- Bestehende Kategorien -->
          <div class="md:col-span-2 rounded-lg border border-slate-200 bg-white p-3 text-xs">
            <h3 class="font-semibold text-slate-900 mb-2 text-xs">Bestehende Kategorien</h3>

            <?php if (!$categories): ?>
              <p class="text-[11px] text-slate-500">
                Noch keine Kategorien angelegt.
              </p>
            <?php else: ?>
              <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-[11px] text-slate-800">
                  <thead class="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                      <th class="px-3 py-2 text-left">Name</th>
                      <th class="px-3 py-2 text-left">Beschreibung</th>
                      <th class="px-3 py-2 text-left">Sort.</th>
                      <th class="px-3 py-2 text-left">Rollen</th>
                      <th class="px-3 py-2 text-left">Dokumente</th>
                      <th class="px-3 py-2 text-left">Aktion</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <?php foreach ($categories as $cat): ?>
                      <tr>
                        <td class="px-3 py-1.5"><?=htmlspecialchars($cat['name'])?></td>
                        <td class="px-3 py-1.5"><?=htmlspecialchars($cat['description'] ?? '')?></td>
                        <td class="px-3 py-1.5"><?= (int)$cat['sort_order'] ?></td>
                        <td class="px-3 py-1.5">
                          <span class="badge rounded-pill text-bg-light border">
                            <?=htmlspecialchars(qcRenderRoles($cat['visible_roles'] ?? null))?>
                          </span>
                        </td>
                        <td class="px-3 py-1.5"><?= (int)$cat['doc_count'] ?></td>
                        <td class="px-3 py-1.5">
                          <form method="post" class="inline-block"
                                onsubmit="return confirm('Kategorie wirklich löschen? Nur möglich, wenn keine Dokumente zugeordnet sind.');">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="cat_id" value="<?=$cat['id']?>">
                            <button type="submit"
                                    class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[10px] text-red-700 hover:bg-red-100">
                              Löschen
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- Kategorie bearbeiten -->
          <form method="post"
                class="md:col-span-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs space-y-2">
            <input type="hidden" name="action" value="update_category">
            <h3 class="font-semibold text-slate-900 mb-1 text-xs">Kategorie bearbeiten</h3>

            <div class="grid gap-2 md:grid-cols-4">
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Kategorie auswählen</label>
                <div class="flex gap-2">
                  <select name="cat_id"
                          id="catEditSelect"
                          class="rounded-md border-slate-300 text-xs flex-1"
                          required>
                    <option value="">Bitte wählen…</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?=$cat['id']?>"
                              data-name="<?=htmlspecialchars($cat['name'], ENT_QUOTES)?>"
                              data-desc="<?=htmlspecialchars($cat['description'] ?? '', ENT_QUOTES)?>"
                              data-sort="<?= (int)$cat['sort_order'] ?>"
                              data-roles="<?=htmlspecialchars($cat['visible_roles'] ?? '', ENT_QUOTES)?>">
                        <?=htmlspecialchars($cat['name'])?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button"
                          id="btnCatFill"
                          class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-700 hover:bg-slate-50">
                    Werte übernehmen
                  </button>
                </div>
              </div>
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Neuer Name</label>
                <input type="text" name="cat_name_new"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="z.B. Mitarbeiter">
              </div>
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Neue Beschreibung</label>
                <input type="text" name="cat_desc_new"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="Beschreibung der Kategorie">
              </div>
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Neue Sortierung</label>
                <input type="number" name="cat_sort_new"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="0">
              </div>
            </div>

            <div class="flex flex-col gap-1.5 mt-2">
              <label class="font-medium text-slate-800">Sichtbar für Rollen</label>
              <div class="role-checkbox-group d-flex flex-wrap gap-2" id="catRolesEditGroup">
                <div class="form-check form-check-inline">
                  <input class="form-check-input"
                         type="checkbox"
                         id="cat_roles_edit_all"
                         name="cat_roles_edit[]"
                         value="__all"
                         data-role-all>
                  <label class="form-check-label text-[11px]" for="cat_roles_edit_all">
                    Alle
                  </label>
                </div>
                <?php foreach ($visibilityRoles as $r): ?>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input"
                           type="checkbox"
                           id="cat_roles_edit_<?=$r?>"
                           name="cat_roles_edit[]"
                           value="<?=$r?>">
                    <label class="form-check-label text-[11px]" for="cat_roles_edit_<?=$r?>">
                      <?=htmlspecialchars($r)?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="mt-0.5 text-[10px] text-slate-500">
                „Alle“ oder keine Rollen = Kategorie ist global sichtbar.
              </p>
            </div>

            <div class="pt-1">
              <button type="submit"
                      class="inline-flex items-center justify-center rounded-md bg-brand text-white px-4 py-1.5 text-[11px] font-medium hover:bg-brand-dark">
                Kategorie aktualisieren
              </button>
            </div>

            <p class="mt-1 text-[10px] text-slate-500">
              Beim Umbenennen einer Kategorie werden alle zugeordneten Dokumente automatisch auf den neuen Namen umgestellt.
            </p>
          </form>
        </div>
      </section>

      <!-- FAHRZEUGE verwalten --------------------------------------->
<section id="admin-vehicles"
         data-admin-section="vehicles"
         class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

  <div class="flex items-center justify-between">
    <h2 class="text-sm font-semibold text-slate-900">Fahrzeuge / Kennzeichen</h2>

    <?php if (!empty($vehError)): ?>
      <span class="text-[11px] text-red-600"><?= htmlspecialchars($vehError) ?></span>
    <?php elseif (!empty($vehSuccess)): ?>
      <span class="text-[11px] text-emerald-600"><?= htmlspecialchars($vehSuccess) ?></span>
    <?php endif; ?>
  </div>

  <form method="post" class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-3 text-xs">
    <input type="hidden" name="action" value="save_vehicles_cfg">

    <div class="grid gap-2 md:grid-cols-4 items-end">
      <div class="flex flex-col gap-1">
        <label class="font-medium text-slate-800">Touren pro Tag</label>
        <input type="number" name="toursPerDay" min="1"
               value="<?= (int)($vehCfg['toursPerDay'] ?? 4) ?>"
               class="rounded-md border-slate-300 text-xs">
      </div>

      <div class="md:col-span-3 text-[11px] text-slate-500">
        Quelle: <code>/data/veh_cfg.json</code> — Änderungen wirken sich auf Tabs & Import-Zuordnung aus.
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full border-collapse text-[11px] text-slate-800">
        <thead class="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-3 py-2 text-left">ID</th>
            <th class="px-3 py-2 text-left">Name (Tab)</th>
            <th class="px-3 py-2 text-left">Kennzeichen (für Import)</th>
            <th class="px-3 py-2 text-left">Fahrer (optional)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
          <?php foreach (($vehCfg['vehicles'] ?? []) as $i => $v): ?>
            <tr>
              <td class="px-3 py-1.5">
                <input type="text"
                       name="vehicles[<?= $i ?>][id]"
                       value="<?= htmlspecialchars($v['id'] ?? '') ?>"
                       class="rounded-md border-slate-300 text-xs bg-slate-100"
                       readonly>
              </td>
              <td class="px-3 py-1.5">
                <input type="text"
                       name="vehicles[<?= $i ?>][title]"
                       value="<?= htmlspecialchars($v['title'] ?? '') ?>"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="z.B. BOH - DT 328">
              </td>
              <td class="px-3 py-1.5">
                <input type="text"
                       name="vehicles[<?= $i ?>][plate]"
                       value="<?= htmlspecialchars($v['plate'] ?? '') ?>"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="z.B. BOH - DT 328">
              </td>
              <td class="px-3 py-1.5">
                <input type="text"
                       name="vehicles[<?= $i ?>][driver]"
                       value="<?= htmlspecialchars($v['driver'] ?? '') ?>"
                       class="rounded-md border-slate-300 text-xs"
                       placeholder="optional">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pt-1 flex items-center gap-2">
      <button type="submit"
              class="inline-flex items-center justify-center rounded-md bg-brand text-white px-4 py-1.5 text-[11px] font-medium hover:bg-brand-dark">
        Speichern
      </button>

      <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'admin.php') ?>"
         class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50">
        Neu laden
      </a>

      <span class="text-[10px] text-slate-500">
        Tipp: Für saubere Import-Zuordnung plate & title gleich halten.
      </span>
    </div>
  </form>
</section>
      <!-- USER VERWALTEN ---------------------------------------------->
      <section id="admin-users"
               data-admin-section="users"
               class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-4 hidden">

        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-900">Benutzer verwalten</h2>
          <?php $shiftHref = 'admin_shiftplan.php' . (!empty($_GET['embed']) ? '?embed=1' : ''); ?>

<div class="mb-3">
  <a href="<?=$shiftHref?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-calendar-week me-1"></i>
    Mitarbeiter-Einsatzplan
  </a>
  <span class="text-muted small ms-2">Schichtplan Früh-/Tag-/Spät pflegen</span>
</div>

          <?php if ($userError): ?>
            <span class="text-[11px] text-red-600"><?=htmlspecialchars($userError)?></span>
          <?php elseif ($userSuccess): ?>
            <span class="text-[11px] text-emerald-600"><?=htmlspecialchars($userSuccess)?></span>
          <?php endif; ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <!-- User anlegen -->
          <form method="post"
                enctype="multipart/form-data"
                class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2 text-xs">

            <input type="hidden" name="action" value="add_user">
            <h3 class="font-semibold text-slate-900 text-xs mb-1">Benutzer anlegen</h3>

            
<div class="flex flex-col gap-1">
  <label class="font-medium text-slate-800">Personalnummer (5-stellig)</label>
  <input type="text" name="user_personal_no"
         class="rounded-md border-slate-300 text-xs"
         inputmode="numeric"
         maxlength="5"
         pattern="\d{5}"
         placeholder="z.B. 12345"
         required>
</div>

<div class="grid gap-2 md:grid-cols-2">
  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Verifizierungs-PIN (5-stellig)</label>
    <input type="password" name="user_verify_pin"
           class="rounded-md border-slate-300 text-xs"
           inputmode="numeric"
           maxlength="5"
           pattern="\d{5}"
           required>
  </div>
  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">PIN wiederholen</label>
    <input type="password" name="user_verify_pin2"
           class="rounded-md border-slate-300 text-xs"
           inputmode="numeric"
           maxlength="5"
           pattern="\d{5}"
           required>
  </div>
</div>
            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Benutzername</label>
              <input type="text" name="user_username"
                     class="rounded-md border-slate-300 text-xs"
                     required>
            </div>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Anzeigename</label>
              <input type="text" name="user_display_name"
                     class="rounded-md border-slate-300 text-xs"
                     placeholder="z.B. Daniel Strübig">
            </div>

            <div class="grid gap-2 md:grid-cols-2">
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Passwort</label>
                <input type="password" name="user_password"
                       class="rounded-md border-slate-300 text-xs" required>
              </div>
              <div class="flex flex-col gap-1">
                <label class="font-medium text-slate-800">Passwort (Wdh.)</label>
                <input type="password" name="user_password2"
                       class="rounded-md border-slate-300 text-xs" required>
              </div>
            </div>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Rolle</label>
              <select name="user_role" class="rounded-md border-slate-300 text-xs">
                <?php foreach ($roleOptions as $r): ?>
                  <option value="<?=$r?>"><?=$r?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex items-center gap-2">
              <input type="checkbox" name="user_active" id="userActive"
                     class="h-3 w-3 rounded border-slate-300" checked>
              <label for="userActive" class="text-[11px] text-slate-700">Benutzer ist aktiv</label>
            </div>

            <div class="flex flex-col gap-1">
              <label class="font-medium text-slate-800">Profilbild (optional)</label>
              <input type="file" name="user_avatar" accept="image/*"
                     class="rounded-md border-slate-300 text-xs">
              <p class="mt-0.5 text-[10px] text-slate-500">
                Erlaubt: JPG/PNG/GIF/WebP · max. 2&nbsp;MB
              </p>
            </div>

            <div class="pt-1">
              <button type="submit"
                      class="inline-flex items-center justify-center rounded-md bg-brand text-white px-3 py-1 text-[11px] font-medium hover:bg-brand-dark">
                Benutzer speichern
              </button>
            </div>
          </form>

          <!-- User bearbeiten -->
<form method="post"
      enctype="multipart/form-data"
      autocomplete="off"
      class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2 text-xs">

  <input type="hidden" name="action" value="update_user">
  <h3 class="font-semibold text-slate-900 text-xs mb-1">Benutzer bearbeiten</h3>

  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Benutzer auswählen</label>
    <div class="flex gap-2">
      <select name="edit_user_id"
              id="editUserSelect"
              class="rounded-md border-slate-300 text-xs flex-1">
        <option value="">Bitte wählen…</option>
        <?php foreach ($allUsers as $u): ?>
          <option value="<?=$u['id']?>"
                  data-username="<?=htmlspecialchars($u['username'], ENT_QUOTES)?>"
                  data-display="<?=htmlspecialchars($u['display_name'], ENT_QUOTES)?>"
                  data-personalno="<?=htmlspecialchars((string)($u['personal_no'] ?? ''), ENT_QUOTES)?>"
                  data-role="<?=htmlspecialchars($u['role'], ENT_QUOTES)?>"
                  data-active="<?=$u['active'] ? '1' : '0'?>">
            <?=htmlspecialchars($u['display_name'] ?: $u['username'])?>
            (<?=htmlspecialchars($u['username'])?>, <?=$u['role']?>)
          </option>
        <?php endforeach; ?>
      </select>

      <button type="button"
              id="btnUserFill"
              class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-700 hover:bg-slate-50">
        Werte übernehmen
      </button>
    </div>
  </div>

  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Anzeigename</label>
    <input type="text"
           name="edit_display_name"
           class="rounded-md border-slate-300 text-xs"
           placeholder="leer = unverändert"
           autocomplete="off">
  </div>

  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Rolle</label>
    <select name="edit_role" class="rounded-md border-slate-300 text-xs">
      <option value="">unverändert lassen</option>
      <?php foreach ($roleOptions as $r): ?>
        <option value="<?=$r?>"><?=$r?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="flex items-center gap-2">
    <input type="checkbox"
           name="edit_active"
           id="editActive"
           class="h-3 w-3 rounded border-slate-300">
    <label for="editActive" class="text-[11px] text-slate-700">Benutzer ist aktiv</label>
  </div>

  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Personalnummer (5-stellig)</label>
    <input type="text"
           name="edit_personal_no"
           class="rounded-md border-slate-300 text-xs"
           inputmode="numeric"
           maxlength="5"
           pattern="\d{5}"
           placeholder="z.B. 12345"
           autocomplete="off"
           spellcheck="false">
  </div>

  <div class="grid gap-2 md:grid-cols-2">
    <div class="flex flex-col gap-1">
      <label class="font-medium text-slate-800">Neue Verifizierungs-PIN (5-stellig)</label>
      <input type="password"
             name="edit_verify_pin"
             class="rounded-md border-slate-300 text-xs"
             inputmode="numeric"
             maxlength="5"
             pattern="\d{5}"
             placeholder="leer = unverändert"
             autocomplete="new-password">
    </div>

    <div class="flex flex-col gap-1">
      <label class="font-medium text-slate-800">Neue PIN wiederholen</label>
      <input type="password"
             name="edit_verify_pin2"
             class="rounded-md border-slate-300 text-xs"
             inputmode="numeric"
             maxlength="5"
             pattern="\d{5}"
             autocomplete="new-password">
    </div>
  </div>

  <div class="grid gap-2 md:grid-cols-2">
    <div class="flex flex-col gap-1">
      <label class="font-medium text-slate-800">Neues Passwort</label>
      <input type="password"
             name="edit_password"
             class="rounded-md border-slate-300 text-xs"
             placeholder="leer = unverändert"
             autocomplete="new-password">
    </div>

    <div class="flex flex-col gap-1">
      <label class="font-medium text-slate-800">Neues Passwort (Wdh.)</label>
      <input type="password"
             name="edit_password2"
             class="rounded-md border-slate-300 text-xs"
             autocomplete="new-password">
    </div>
  </div>

  <div class="flex flex-col gap-1">
    <label class="font-medium text-slate-800">Neues Profilbild (optional)</label>
    <input type="file"
           name="edit_avatar"
           accept="image/*"
           class="rounded-md border-slate-300 text-xs">
    <p class="mt-0.5 text-[10px] text-slate-500">
      Leer lassen, um das aktuelle Profilbild zu behalten.
    </p>
  </div>

  <div class="pt-1">
    <button type="submit"
            class="inline-flex items-center justify-center rounded-md bg-brand text-white px-4 py-1.5 text-[11px] font-medium hover:bg-brand-dark">
      Benutzer aktualisieren
    </button>
  </div>
</form>

          <!-- User-Liste -->
          <div class="md:col-span-2 rounded-lg border border-slate-200 bg-white p-3 text-xs">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h3 class="font-semibold text-slate-900 mb-0 text-xs">Benutzerliste</h3>

              <?php
                $embed   = isset($_GET['embed']) ? 'embed=1&' : '';
                $section = 'section=users';
              ?>
              <div class="d-flex gap-2">
                <a class="btn btn-sm <?= $showTrash ? 'btn-outline-secondary' : 'btn-secondary' ?>"
                   href="?<?=$embed?><?=$section?>">
                  Benutzer
                </a>
                <a class="btn btn-sm <?= $showTrash ? 'btn-danger' : 'btn-outline-danger' ?>"
   href="?<?=$embed?><?=$section?>&show=trash">
  Papierkorb
  <?php if (!empty($trashCount)): ?>
    <span class="badge text-bg-danger ms-1"><?=$trashCount?></span>
  <?php endif; ?>
</a>

              </div>
            </div>

            <?php if (!$allUsers): ?>
              <p class="text-[11px] text-slate-500 mb-0">
                <?= $showTrash ? 'Papierkorb ist leer.' : 'Noch keine Benutzer angelegt.' ?>
              </p>
            <?php else: ?>
              <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-[11px] text-slate-800">
                  <thead class="bg-slate-50 font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
  <th class="px-3 py-2 text-left">Avatar</th>
  <th class="px-3 py-2 text-left">Benutzername</th>
  <th class="px-3 py-2 text-left">Anzeigename</th>
  <th class="px-3 py-2 text-left">Rolle</th>
  <th class="px-3 py-2 text-left">Status</th>
  <th class="px-3 py-2 text-left">Test gemacht?</th>
  <th class="px-3 py-2 text-left">Aktionen</th>
</tr>
                  </thead>

           <tbody class="divide-y divide-slate-100">
  <?php foreach ($allUsers as $u): ?>
    <tr>
      <td class="px-3 py-1.5">
        <?php
          $avatar = $u['profile_image']
            ? '/uploads/avatars/' . htmlspecialchars($u['profile_image'])
            : '/Bilder/avatar_placeholder.png';
        ?>
        <img src="<?=$avatar?>" alt="Avatar"
             style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
      </td>

      <td class="px-3 py-1.5"><?=htmlspecialchars($u['username'])?></td>
      <td class="px-3 py-1.5"><?=htmlspecialchars($u['display_name'])?></td>
      <td class="px-3 py-1.5"><?=htmlspecialchars($u['role'])?></td>

      <td class="px-3 py-1.5">
        <?php [$isOnlineUser, $lastText] = qcLastActivityInfo($u['last_activity'] ?? null); ?>

        <div class="mb-1">
          <?php if ($u['active']): ?>
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">
              aktiv
            </span>
          <?php else: ?>
            <span class="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-medium text-slate-700">
              inaktiv
            </span>
          <?php endif; ?>

          <?php if (!empty($u['archived_at'])): ?>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700 ms-1">
              archiviert
            </span>
          <?php endif; ?>
        </div>

        <div class="text-[10px] text-slate-500 mt-0.5">
          <?php if ($isOnlineUser): ?>
            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 font-medium text-green-700">
              <span style="width:8px; height:8px; border-radius:999px; background:#22c55e; display:inline-block; margin-right:4px;"></span>
              online · <?=$lastText?>
            </span>
          <?php else: ?>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
              <span style="width:8px; height:8px; border-radius:999px; background:#94a3b8; display:inline-block; margin-right:4px;"></span>
              <?=$lastText?>
            </span>
          <?php endif; ?>
        </div>
      </td>

      <td class="px-3 py-1.5">
        <?php $hasQuiz = ((int)($u['quiz_count'] ?? 0) > 0); ?>

        <?php if ($hasQuiz): ?>
          <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-medium text-emerald-700">
            <i class="bi bi-check-circle-fill me-1"></i> Ja
          </span>
          <?php if (!empty($u['last_quiz_at'])): ?>
            <div class="text-[10px] text-slate-500 mt-1">
              <?=htmlspecialchars(date('d.m.Y H:i', strtotime($u['last_quiz_at'])))?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-[10px] font-medium text-red-700">
            <i class="bi bi-x-circle-fill me-1"></i> Nein
          </span>
        <?php endif; ?>
      </td>

      <td class="px-3 py-1.5 whitespace-nowrap">
        <?php if (empty($u['deleted_at'])): ?>

          <?php if (empty($u['archived_at'])): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="archive_user">
              <input type="hidden" name="user_id" value="<?=$u['id']?>">
              <button class="btn btn-sm btn-outline-warning"
                      onclick="return confirm('Benutzer archivieren? Login wird gesperrt.')">
                Archivieren
              </button>
            </form>
          <?php else: ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="restore_user">
              <input type="hidden" name="user_id" value="<?=$u['id']?>">
              <button class="btn btn-sm btn-outline-success">
                Reaktivieren
              </button>
            </form>
          <?php endif; ?>

          <form method="post" class="d-inline ms-1">
            <input type="hidden" name="action" value="trash_user">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <button class="btn btn-sm btn-outline-danger"
                    onclick="return confirm('In Papierkorb verschieben?')">
              Löschen
            </button>
          </form>

        <?php else: ?>

          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="restore_user">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <button class="btn btn-sm btn-outline-success">
              Wiederherstellen
            </button>
          </form>

          <form method="post" class="d-inline ms-1">
            <input type="hidden" name="action" value="purge_user">
            <input type="hidden" name="user_id" value="<?=$u['id']?>">
            <button class="btn btn-sm btn-danger"
                    onclick="return confirm('ENDGÜLTIG löschen? Kann nicht rückgängig gemacht werden!')">
              Endgültig
            </button>
          </form>

        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    </main>
  </div>

  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {

          // =========================================================
      // Lagerbestand: Unter-Kacheln (Rows / Summary / Third)
      // =========================================================
      const lbTiles = Array.from(document.querySelectorAll('[data-lagerbestand-tile]'));
      const lbPanes = Array.from(document.querySelectorAll('[data-lagerbestand-pane]'));

      function setLagerbestandPane(key) {
        lbPanes.forEach(pane => {
          pane.classList.toggle('d-none', pane.dataset.lagerbestandPane !== key);
        });

        lbTiles.forEach(tile => {
          tile.classList.toggle('tile-active', tile.dataset.lagerbestandTile === key);
        });
      }

      lbTiles.forEach(tile => {
        tile.addEventListener('click', () => {
          const key = tile.dataset.lagerbestandTile;
          if (!key) return;
          setLagerbestandPane(key);
        });
      });

      // Standard: zuerst Reihenbereich anzeigen
      if (lbTiles.length && lbPanes.length) {
        setLagerbestandPane('rows');
      }
      const isDesktop = window.matchMedia('(min-width: 768px)').matches;

      // Bootstrap Tooltips nur auf Desktop
      if (window.bootstrap && isDesktop) {
        const tooltipTriggerList = [].slice.call(
          document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        tooltipTriggerList.forEach(el => {
          new bootstrap.Tooltip(el);
        });
      }

      // --- Helper: Rollen-Checkbox-Gruppen ----------------------------------
      function initRoleCheckboxGroup(groupEl) {
        if (!groupEl) return;
        const allBox = groupEl.querySelector('[data-role-all]');
        const boxes  = Array.from(groupEl.querySelectorAll('input[type="checkbox"]'))
                            .filter(b => b !== allBox);

        if (!allBox) return;

        allBox.addEventListener('change', () => {
          if (allBox.checked) {
            boxes.forEach(b => { b.checked = false; });
          }
        });

        boxes.forEach(box => {
          box.addEventListener('change', () => {
            if (box.checked) allBox.checked = false;
          });
        });
      }

      function applyRolesToGroup(groupEl, rolesArray) {
        if (!groupEl) return;
        const allBox = groupEl.querySelector('[data-role-all]');
        const boxes  = Array.from(groupEl.querySelectorAll('input[type="checkbox"]'))
                            .filter(b => b !== allBox);

        if (!rolesArray || !Array.isArray(rolesArray) || rolesArray.length === 0) {
          if (allBox) allBox.checked = true;
          boxes.forEach(b => { b.checked = false; });
          return;
        }

        if (allBox) allBox.checked = false;
        boxes.forEach(b => { b.checked = rolesArray.includes(b.value); });
      }

      initRoleCheckboxGroup(document.getElementById('docRolesNewGroup'));
      initRoleCheckboxGroup(document.getElementById('docRolesEditGroup'));
      initRoleCheckboxGroup(document.getElementById('catRolesNewGroup'));
      initRoleCheckboxGroup(document.getElementById('catRolesEditGroup'));

      // --- Admin-Kacheln: Bereiche umschalten + Zustand merken -------------
      const tiles    = Array.from(document.querySelectorAll('[data-admin-tile]'));
      const sections = Array.from(document.querySelectorAll('[data-admin-section]'));

      if (tiles.length && sections.length) {
        function setActiveSection(key, opts = { scroll: false }) {
          sections.forEach(sec => {
            sec.classList.toggle('hidden', sec.dataset.adminSection !== key);
          });

          tiles.forEach(tile => {
            tile.classList.toggle('tile-active', tile.dataset.adminTile === key);
          });

          try {
            const url = new URL(window.location.href);
            url.searchParams.set('section', key);
            window.history.replaceState({}, '', url);
          } catch (err) {}

          if (opts.scroll) {
            const target = sections.find(sec => sec.dataset.adminSection === key);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }

        tiles.forEach(tile => {
  tile.addEventListener('click', () => {
    const key = tile.dataset.adminTile;
    if (!key) return;
    setActiveSection(key, { scroll: !isDesktop }); // Desktop: kein Scroll, Mobile: ok
  });
});


        let initialKey = 'lagerbestand';
        try {
          const params = new URLSearchParams(window.location.search);
          const fromUrl = params.get('section');
          const validSections = sections.map(sec => sec.dataset.adminSection);
          if (fromUrl && validSections.includes(fromUrl)) initialKey = fromUrl;
        } catch (err) {}

        setActiveSection(initialKey, { scroll: false });
      }

      // === Benutzer: Werte übernehmen ======================================
const userSelect          = document.getElementById('editUserSelect');
const btnUserFill         = document.getElementById('btnUserFill');
const inpDispName         = document.querySelector('input[name="edit_display_name"]');
const inpPersonalNoEdit   = document.querySelector('input[name="edit_personal_no"]');
const selRoleEdit         = document.querySelector('select[name="edit_role"]');
const chkActiveEdit       = document.getElementById('editActive');

if (userSelect && btnUserFill) {
  const fillUserFromOption = (opt, overwrite = true) => {
    if (!opt || !opt.value) return;

    const disp       = opt.dataset.display || '';
    const personalNo = opt.dataset.personalno || '';
    const role       = opt.dataset.role || '';
    const active     = opt.dataset.active || '0';

    if (inpDispName && (overwrite || !inpDispName.value)) {
      inpDispName.value = disp;
    }

    if (inpPersonalNoEdit && (overwrite || !inpPersonalNoEdit.value)) {
      inpPersonalNoEdit.value = personalNo;
    }

    if (selRoleEdit && (overwrite || !selRoleEdit.value) && role) {
      selRoleEdit.value = role;
    }

    if (chkActiveEdit) {
      chkActiveEdit.checked = (active === '1');
    }
  };

  btnUserFill.addEventListener('click', (e) => {
    e.preventDefault();
    const opt = userSelect.options[userSelect.selectedIndex];
    if (!opt || !opt.value) return alert('Bitte zuerst einen Benutzer auswählen.');
    fillUserFromOption(opt, true);
  });

  userSelect.addEventListener('change', () => {
    const opt = userSelect.options[userSelect.selectedIndex];
    fillUserFromOption(opt, false);
  });
}

      // === Kategorien: Werte übernehmen =====================================
      const catSelect      = document.getElementById('catEditSelect');
      const btnCatFill     = document.getElementById('btnCatFill');
      const catNameInput   = document.querySelector('input[name="cat_name_new"]');
      const catDescInput   = document.querySelector('input[name="cat_desc_new"]');
      const catSortInput   = document.querySelector('input[name="cat_sort_new"]');
      const catRolesEditEl = document.getElementById('catRolesEditGroup');

      function fillCategoryFromOption(opt, overwriteFields = true, overwriteRoles = true) {
        if (!opt || !opt.value) return;

        const name  = opt.dataset.name  || '';
        const desc  = opt.dataset.desc  || '';
        const sort  = opt.dataset.sort  || '';
        const roles = opt.dataset.roles || '';

        if (overwriteFields) {
          if (catNameInput) catNameInput.value = name;
          if (catDescInput) catDescInput.value = desc;
          if (catSortInput) catSortInput.value = sort;
        } else {
          if (catNameInput && !catNameInput.value) catNameInput.value = name;
          if (catDescInput && !catDescInput.value) catDescInput.value = desc;
          if (catSortInput && !catSortInput.value) catSortInput.value = sort;
        }

        if (overwriteRoles && catRolesEditEl) {
          let arr = [];
          if (roles) { try { arr = JSON.parse(roles); } catch (e) { arr = []; } }
          applyRolesToGroup(catRolesEditEl, arr);
        }
      }

      if (catSelect && btnCatFill) {
        btnCatFill.addEventListener('click', (e) => {
          e.preventDefault();
          const opt = catSelect.options[catSelect.selectedIndex];
          if (!opt || !opt.value) return alert('Bitte zuerst eine Kategorie auswählen.');
          fillCategoryFromOption(opt, true, true);
        });

        catSelect.addEventListener('change', () => {
          const opt = catSelect.options[catSelect.selectedIndex];
          fillCategoryFromOption(opt, false, true);
        });
      }

      // === Dokumente: Werte übernehmen ======================================
      const docSelect       = document.getElementById('docEditSelect');
      const btnDocFill      = document.getElementById('btnDocFill');
      const docTitleInput   = document.querySelector('input[name="doc_title_edit"]');
      const docHallSelect   = document.querySelector('select[name="doc_hall_edit"]');
      const docCatSelect    = document.querySelector('select[name="doc_category_edit"]');
      const docRolesEditEl  = document.getElementById('docRolesEditGroup');

      function fillDocFromOption(opt, overwrite = true) {
        if (!opt || !opt.value) return;
        const title = opt.dataset.title || '';
        const hall  = opt.dataset.hall  || '';
        const cat   = opt.dataset.cat   || '';
        const roles = opt.dataset.roles || '';

        if (docTitleInput && (overwrite || !docTitleInput.value)) docTitleInput.value = title;
        if (docHallSelect && (overwrite || !docHallSelect.value)) docHallSelect.value = hall || '';

        if (docCatSelect && (overwrite || !docCatSelect.value)) {
          if (cat) {
            let optCat = Array.from(docCatSelect.options).find(o => o.value === cat);
            if (!optCat) {
              optCat = new Option(cat, cat);
              docCatSelect.add(optCat);
            }
            docCatSelect.value = cat;
          } else {
            docCatSelect.value = '';
          }
        }

        if (docRolesEditEl) {
          let arr = [];
          if (roles) { try { arr = JSON.parse(roles); } catch (e) { arr = []; } }
          applyRolesToGroup(docRolesEditEl, arr);
        }
      }

      if (docSelect && btnDocFill) {
        btnDocFill.addEventListener('click', (e) => {
          e.preventDefault();
          const opt = docSelect.options[docSelect.selectedIndex];
          if (!opt || !opt.value) return alert('Bitte zuerst ein Dokument auswählen.');
          fillDocFromOption(opt, true);
        });

        docSelect.addEventListener('change', () => {
          const opt = docSelect.options[docSelect.selectedIndex];
          fillDocFromOption(opt, false);
        });
      }
    });
  </script>
 <script>
(() => {
  const API_GET           = '/Lagerplan/api/lager_config_get.php';
  const API_SAVE          = '/Lagerplan/api/lager_config_save.php';
  const API_OVERRIDE_SAVE = '/Lagerplan/api/lager_row_override_save.php';
  const API_OVERRIDE_DEL  = '/Lagerplan/api/lager_row_override_delete.php';

  const el = (id) => document.getElementById(id);

  const cfgHalle            = el('cfgHalle');
  const cfgZone             = el('cfgZone');
  const cfgRowFrom          = el('cfgRowFrom');
  const cfgRowTo            = el('cfgRowTo');
  const cfgDefaultPlaces    = el('cfgDefaultPlaces');
  const cfgDefaultSlots     = el('cfgDefaultSlots');
  const cfgRowCount         = el('cfgRowCount');
  const cfgRowRangeBadge    = el('cfgRowRangeBadge');
  const cfgHighestUsedBadge = el('cfgHighestUsedBadge');
  const cfgMsg              = el('cfgMsg');

  const cfgOvRow            = el('cfgOvRow');
  const cfgOvPlaces         = el('cfgOvPlaces');
  const cfgOvSlots          = el('cfgOvSlots');
  const cfgOverrideTableBody= el('cfgOverrideTableBody');

  const btnCfgReload        = el('btnCfgReload');
  const btnCfgSave          = el('btnCfgSave');
  const btnCfgPlus10        = el('btnCfgPlus10');
  const btnCfgMinus10       = el('btnCfgMinus10');
  const btnCfgUseHighest    = el('btnCfgUseHighest');
  const btnCfgOvSave        = el('btnCfgOvSave');

  if (!cfgHalle || !cfgZone || !cfgRowFrom || !cfgRowTo) return;

  let lastHighestUsedRow = 0;
  let currentCfg = null;

 function escapeHtml(str) {
  return String(str ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function formatCfgPopupMessage(msg) {
  let safe = escapeHtml(msg);

  safe = safe.replace(/(Reihe\s+\d+)/i, '<b>$1</b>');
  safe = safe.replace(/(Platz\s+\d+,\s*Slot\s+\d+)/i, '<b>$1</b>');

  return safe;
}

function showCfgPopup(text, type = 'error') {
  const modalEl = document.getElementById('cfgAlertModal');
  const titleEl = document.getElementById('cfgAlertModalLabel');
  const textEl  = document.getElementById('cfgAlertModalText');

  if (!modalEl || !titleEl || !textEl || !window.bootstrap) {
    alert(text);
    return;
  }

  const header = modalEl.querySelector('.modal-header');
  if (!header) {
    alert(text);
    return;
  }

  header.classList.remove('bg-danger', 'bg-warning', 'bg-success', 'bg-info', 'text-white', 'text-dark');

  if (type === 'success') {
    header.classList.add('bg-success', 'text-white');
    titleEl.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>Erfolg';
  } else if (type === 'warn') {
    header.classList.add('bg-warning', 'text-dark');
    titleEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Warnung';
  } else if (type === 'info') {
    header.classList.add('bg-info', 'text-dark');
    titleEl.innerHTML = '<i class="bi bi-info-circle-fill me-2"></i>Hinweis';
  } else {
    header.classList.add('bg-danger', 'text-white');
    titleEl.innerHTML = '<i class="bi bi-x-octagon-fill me-2"></i>Fehler';
  }

  textEl.innerHTML = formatCfgPopupMessage(text || '');
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function showMsg(text, type = 'info') {
  // Nur Popup für Warnung/Fehler
  if (type === 'error' || type === 'warn') {
    showCfgPopup(text, type);
  }
}

 function clearMsg() {
  // absichtlich leer, weil wir kein Inline-Div mehr nutzen
}

  function normalizeInt(v, fallback = 0) {
    const n = parseInt(String(v || '').trim(), 10);
    return Number.isFinite(n) ? n : fallback;
  }

  function refreshCountUI() {
    const from = Math.max(1, normalizeInt(cfgRowFrom.value, 1));
    let to = Math.max(from, normalizeInt(cfgRowTo.value, from));

    cfgRowFrom.value = String(from);
    cfgRowTo.value   = String(to);

    const count = Math.max(0, to - from + 1);
    if (cfgRowCount) cfgRowCount.textContent = String(count);
    if (cfgRowRangeBadge) cfgRowRangeBadge.textContent = `Reihen: ${from} bis ${to}`;
  }

  function renderOverrides(overrides) {
    if (!cfgOverrideTableBody) return;

    const rows = Object.entries(overrides || {})
      .sort((a, b) => parseInt(a[0], 10) - parseInt(b[0], 10));

    if (!rows.length) {
      cfgOverrideTableBody.innerHTML = `
        <tr>
          <td colspan="4" class="text-muted">Keine Sonderregeln vorhanden.</td>
        </tr>
      `;
      return;
    }

    cfgOverrideTableBody.innerHTML = rows.map(([row, cfg]) => `
      <tr>
        <td><b>${row}</b></td>
        <td class="text-end">${cfg.places}</td>
        <td class="text-end">${cfg.slots_per_place}</td>
        <td class="text-end">
          <button type="button"
                  class="btn btn-sm btn-outline-primary me-1"
                  data-edit-row="${row}"
                  data-edit-places="${cfg.places}"
                  data-edit-slots="${cfg.slots_per_place}">
            Bearbeiten
          </button>
          <button type="button"
                  class="btn btn-sm btn-outline-danger"
                  data-del-row="${row}">
            Löschen
          </button>
        </td>
      </tr>
    `).join('');
  }

  async function fetchJson(url, opts = {}) {
    const res = await fetch(url, opts);
    const text = await res.text();

    let js = {};
    try {
      js = JSON.parse(text);
    } catch (e) {
      throw new Error('Server liefert kein gültiges JSON.');
    }

    if (!res.ok || js.ok !== true) {
      throw new Error(js?.msg || 'Anfrage fehlgeschlagen.');
    }

    return js;
  }

  async function loadConfig() {
    clearMsg();

    const halle = (cfgHalle.value || 'H3').trim();
    const zone  = (cfgZone.value || 'W1').trim();

    try {
      const url = `${API_GET}?halle=${encodeURIComponent(halle)}&zone=${encodeURIComponent(zone)}`;
      const js = await fetchJson(url, {
        credentials: 'same-origin',
        cache: 'no-store'
      });

      currentCfg = js;

      cfgRowFrom.value       = String(js.row_from ?? 1);
      cfgRowTo.value         = String(js.row_to ?? 200);
      cfgDefaultPlaces.value = String(js.default_places_per_row ?? 40);
      cfgDefaultSlots.value  = String(js.default_slots_per_place ?? 4);

      lastHighestUsedRow = parseInt(js.highest_used_row ?? 0, 10) || 0;

      if (cfgHighestUsedBadge) {
        cfgHighestUsedBadge.textContent = `Höchste belegte Reihe: ${lastHighestUsedRow || '–'}`;
      }

      refreshCountUI();
      renderOverrides(js.row_overrides || {});
      showMsg(`Konfiguration geladen: ${js.halle}/${js.zone}`, 'success');
    } catch (err) {
      console.error(err);
      showMsg(err?.message || 'Fehler beim Laden.', 'error');
    }
  }

  async function saveBaseConfig() {
    clearMsg();

    const halle   = (cfgHalle.value || 'H3').trim();
    const zone    = (cfgZone.value || 'W1').trim();
    const rowFrom = Math.max(1, normalizeInt(cfgRowFrom.value, 1));
    const rowTo   = Math.max(rowFrom, normalizeInt(cfgRowTo.value, rowFrom));
    const defaultPlaces = Math.max(1, normalizeInt(cfgDefaultPlaces.value, 40));
    const defaultSlots  = Math.max(1, normalizeInt(cfgDefaultSlots.value, 4));

    try {
      const fd = new FormData();
      fd.append('halle', halle);
      fd.append('zone', zone);
      fd.append('row_from', String(rowFrom));
      fd.append('row_to', String(rowTo));
      fd.append('default_places_per_row', String(defaultPlaces));
      fd.append('default_slots_per_place', String(defaultSlots));

      const js = await fetchJson(API_SAVE, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      currentCfg = js;
      lastHighestUsedRow = parseInt(js.highest_used_row ?? 0, 10) || 0;

      if (cfgHighestUsedBadge) {
        cfgHighestUsedBadge.textContent = `Höchste belegte Reihe: ${lastHighestUsedRow || '–'}`;
      }

      refreshCountUI();
      renderOverrides(js.row_overrides || {});
      showMsg('Basis-Konfiguration gespeichert.', 'success');
    } catch (err) {
      console.error(err);
      showMsg(err?.message || 'Speichern fehlgeschlagen.', 'error');
    }
  }


async function saveOverride() {
  clearMsg();

  const halle  = (cfgHalle.value || 'H3').trim();
  const zone   = (cfgZone.value || 'W1').trim();
  const row    = Math.max(1, normalizeInt(cfgOvRow.value, 0));
  const places = Math.max(1, normalizeInt(cfgOvPlaces.value, 0));
  const slots  = Math.max(1, normalizeInt(cfgOvSlots.value, 0));

  if (!row || !places || !slots) {
    showMsg('Bitte Reihe, Plätze und Slots ausfüllen.', 'warn');
    return;
  }

  try {
    const fd = new FormData();
    fd.append('halle', halle);
    fd.append('zone', zone);
    fd.append('row', String(row));
    fd.append('places', String(places));
    fd.append('slots_per_place', String(slots));

    const js = await fetchJson(API_OVERRIDE_SAVE, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    currentCfg = currentCfg || {};
    currentCfg.row_overrides = js.row_overrides || {};

    renderOverrides(currentCfg.row_overrides);

    cfgOvRow.value = '';
    cfgOvPlaces.value = '';
    cfgOvSlots.value = '';

    showMsg(js.msg || 'Sonderregel gespeichert.', 'success');
  } catch (err) {
    console.error(err);
    showMsg(err?.message || 'Sonderregel konnte nicht gespeichert werden.', 'error');
  }
}
async function deleteOverride(row) {
  clearMsg();

  const halle = (cfgHalle.value || 'H3').trim();
  const zone  = (cfgZone.value || 'W1').trim();

  try {
    const fd = new FormData();
    fd.append('halle', halle);
    fd.append('zone', zone);
    fd.append('row', String(row));

    const js = await fetchJson(API_OVERRIDE_DEL, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    currentCfg = currentCfg || {};
    currentCfg.row_overrides = js.row_overrides || {};

    renderOverrides(currentCfg.row_overrides);
    showMsg(js.msg || 'Sonderregel gelöscht.', 'success');
  } catch (err) {
    console.error(err);
    showMsg(err?.message || 'Löschen fehlgeschlagen.', 'error');
  }
}

  btnCfgReload?.addEventListener('click', loadConfig);
  btnCfgSave?.addEventListener('click', saveBaseConfig);
  btnCfgOvSave?.addEventListener('click', saveOverride);

  btnCfgPlus10?.addEventListener('click', () => {
    cfgRowTo.value = String(Math.max(1, normalizeInt(cfgRowTo.value, 200)) + 10);
    refreshCountUI();
  });

  btnCfgMinus10?.addEventListener('click', () => {
    const from = Math.max(1, normalizeInt(cfgRowFrom.value, 1));
    const currentTo = Math.max(from, normalizeInt(cfgRowTo.value, from));
    const nextTo = Math.max(from, currentTo - 10);

    cfgRowTo.value = String(nextTo);
    refreshCountUI();

    if (lastHighestUsedRow > 0 && nextTo < lastHighestUsedRow) {
      showMsg(`Achtung: Höchste belegte Reihe ist aktuell ${lastHighestUsedRow}.`, 'warn');
    }
  });

  btnCfgUseHighest?.addEventListener('click', () => {
    if (lastHighestUsedRow > 0) {
      cfgRowTo.value = String(lastHighestUsedRow);
      refreshCountUI();
      showMsg(`Bis Reihe auf ${lastHighestUsedRow} gesetzt.`, 'info');
    }
  });

  cfgOverrideTableBody?.addEventListener('click', (e) => {
    const editBtn = e.target.closest('[data-edit-row]');
    if (editBtn) {
      cfgOvRow.value    = editBtn.dataset.editRow || '';
      cfgOvPlaces.value = editBtn.dataset.editPlaces || '';
      cfgOvSlots.value  = editBtn.dataset.editSlots || '';
      return;
    }

    const delBtn = e.target.closest('[data-del-row]');
    if (delBtn) {
      const row = delBtn.dataset.delRow || '';
      if (!row) return;

      if (confirm(`Sonderregel für Reihe ${row} wirklich löschen?`)) {
        deleteOverride(row);
      }
    }
  });

  cfgRowFrom?.addEventListener('input', refreshCountUI);
  cfgRowTo?.addEventListener('input', refreshCountUI);

  document.addEventListener('DOMContentLoaded', loadConfig);
})();


</script>
<div class="modal fade" id="cfgAlertModal" tabindex="-1" aria-labelledby="cfgAlertModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="cfgAlertModalLabel">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Hinweis
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
      </div>
      <div class="modal-body">
        <div id="cfgAlertModalText" class="small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>
  <script defer src="/admin/admin.js"></script>
</body>
</html>


