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
  return $roles ?: ['admin', 'disposition']; // Fallback
}

// ================= Auth / Guard =================
$TAB_KEY = 'special';

$AUTH_DEFAULT_TAB   = $TAB_KEY;
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, $TAB_KEY);
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';

http_response_code(403);
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
      border: 1px solid rgba(148, 163, 184, 0.7);
      box-shadow: 0 18px 40px rgba(15,23,42,0.6);
      max-width: 360px;
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
  </style>
</head>
<body>
  <div class="box">
    <h1>Zugriff verweigert</h1>
    <p>Vielen Dank für Ihr Interesse,<br>aber diese Daten sind nichts für Sie!</p>
    <small>Bitte wenden Sie sich an die Disposition / TeamProjekt Outsourcing.</small>
  </div>
</body>
</html>
