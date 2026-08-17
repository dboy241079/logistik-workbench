<?php
declare(strict_types=1);
require __DIR__ . '/inc/session.php';

require __DIR__ . '/api/_db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('DB nicht verfügbar.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$loginUsername = '';
$loginError = '';

$onlineUsersCount = 0;
$isOnline = false;

// Rollen (aktuell nicht genutzt, kannst du später validieren)
$roleOptions = ['admin','user','disposition','staplerfahrer','verpacker','standortleiter'];

/**
 * Tab-Berechtigungen aus app_tab_roles laden.
 * Jede Zeile = (tab_key, role). Wenn leer -> Fallback.
 */
function loadTabPermissions(PDO $pdo): array
{
  $permissions = [];

  $stmt = $pdo->query("SELECT tab_key, role FROM app_tab_roles");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $tab  = (string)$row['tab_key'];
    $role = (string)$row['role'];

    $permissions[$tab] ??= [];
    if (!in_array($role, $permissions[$tab], true)) {
      $permissions[$tab][] = $role;
    }
  }

  if (empty($permissions)) {
    $permissions = [
      'dashboard' => ['admin','user','disposition','staplerfahrer','verpacker','standortleiter'],
      'drivers'   => ['admin','disposition'],
      'special'   => ['admin','disposition'],
      'cmr'       => ['admin','disposition'],
      'exports'   => ['admin','disposition','standortleiter'],
      'goods'     => ['admin','disposition','standortleiter'],
      'inbound'   => ['admin','disposition','staplerfahrer'],
      'outbound'  => ['admin','disposition','staplerfahrer'],
      'lagerplan' => ['admin','disposition','staplerfahrer','verpacker','standortleiter','user'],
      'docs'      => ['admin','user','disposition','staplerfahrer','verpacker','standortleiter'],
      'admin'     => ['admin'],
      'referenzvergleich' => ['admin','disposition','standortleiter'],
      'arbeitsanweisung' => ['admin','disposition','standortleiter'],
    ];
  }

  return $permissions;
}


function canSeeTab(string $key, ?string $role, array $tabPermissions): bool
{
  if ($role === null) return false;
  if (!isset($tabPermissions[$key])) return false;
  return in_array($role, $tabPermissions[$key], true);
}

// --- Logout ---
if (isset($_GET['logout'])) {
  try {
    $sid = session_id();
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE session_id = :sid");
    $stmt->execute([':sid' => $sid]);
  } catch (Throwable $e) {
    // ignore
  }

  $_SESSION = [];
  session_destroy();
  header('Location: index.php');
  exit;
}

// --- Login ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'login')) {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $loginUsername = $username;

  if ($username === '' || $password === '') {
    $loginError = 'Bitte Benutzername und Passwort eingeben.';
  } else {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u AND active = 1 LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $loginError = 'Benutzername oder Passwort falsch.';
    } else {
      session_regenerate_id(true);

      $_SESSION['user_id']      = (int)$user['id'];
      $_SESSION['username']     = (string)$user['username'];
      $_SESSION['display_name'] = (string)($user['display_name'] ?? '');
      $_SESSION['role']         = (string)($user['role'] ?? 'user');

      header('Location: index.php');
      exit;
    }
  }
}

// --- Session/User laden ---
$currentUserId       = $_SESSION['user_id'] ?? null;
$isLoggedIn          = false;
$currentUsername     = null;
$currentName         = '';
$currentRole         = null;
$currentProfileImage = null;

if ($currentUserId) {
  $stmt = $pdo->prepare("
    SELECT username, display_name, role, profile_image
    FROM users
    WHERE id = :id AND active = 1
    LIMIT 1
  ");
  $stmt->execute([':id' => (int)$currentUserId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $isLoggedIn          = true;
    $currentUsername     = (string)$row['username'];
    $currentName         = (string)($row['display_name'] ?: $row['username']);
    $currentRole         = (string)$row['role'];
    $currentProfileImage = $row['profile_image'] ?? null;
  } else {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
  }
}

// --- Avatar ---
$avatarUrl = '/Bilder/avatar_placeholder.png';
if ($isLoggedIn && !empty($currentProfileImage)) {
  $avatarPathFs = __DIR__ . '/uploads/avatars/' . $currentProfileImage;
  if (is_file($avatarPathFs)) {
    $avatarUrl = '/uploads/avatars/' . rawurlencode((string)$currentProfileImage);
  }
}

// --- Tabs ---
$tabPermissions = loadTabPermissions($pdo);

$tabOrder = [
  'dashboard',
  'drivers',
  'goods',
  'inbound',
  'outbound',
  'special',
  'lagerplan',
  'referenzvergleich',
  'docs',
  'arbeitsanweisung',
  'admin',
];

$defaultTabKey = null;
if ($isLoggedIn) {
  foreach ($tabOrder as $tKey) {
    if (canSeeTab($tKey, $currentRole, $tabPermissions)) {
      $defaultTabKey = $tKey;
      break;
    }
  }
  if ($defaultTabKey === null) $defaultTabKey = 'dashboard';
}

// --- Settings (location/project) ---
$locationLabel = 'Wunstorf / Hannover';
$projectLabel  = 'TeamProjekt Outsourcing';

$stmt = $pdo->prepare("
  SELECT `key`, `value`
  FROM app_settings
  WHERE `key` IN ('location_label','project_label')
");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  if ($row['key'] === 'location_label') $locationLabel = (string)$row['value'];
  if ($row['key'] === 'project_label')  $projectLabel  = (string)$row['value'];
}

$typewriterTextMain = 'Willkommen in deiner Logistik-Workbench';
$typewriterTextAlt  = "Willkommen hier in {$locationLabel}";
$heroSubtitle       = "Pilotumgebung {$projectLabel} – Standort {$locationLabel}";

// --- Online-Tracking (session_id) ---
if ($isLoggedIn) {
  $sid = session_id();

$stmt = $pdo->prepare("
  INSERT INTO user_sessions (session_id, user_id, last_activity)
  VALUES (:sid, :uid, NOW())
  ON DUPLICATE KEY UPDATE
    user_id = :uid2,
    last_activity = NOW()
");
$stmt->execute([
  ':sid'  => $sid,
  ':uid'  => (int)$currentUserId,
  ':uid2' => (int)$currentUserId,
]);


  // Is online? (last 5 minutes)
  $stmt = $pdo->prepare("
    SELECT (last_activity >= (NOW() - INTERVAL 5 MINUTE))
    FROM user_sessions
    WHERE session_id = :sid
  ");
  $stmt->execute([':sid' => $sid]);
  $isOnline = (bool)$stmt->fetchColumn();

  // Count online users (distinct user_id)
  $stmt = $pdo->query("
    SELECT COUNT(DISTINCT user_id)
    FROM user_sessions
    WHERE last_activity >= (NOW() - INTERVAL 5 MINUTE)
  ");
  $onlineUsersCount = (int)$stmt->fetchColumn();
}

// --- View rendern ---
require __DIR__ . '/index.view.php';

