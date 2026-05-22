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

// Zeitraum (Standard: aktueller Monat)
$heute = new DateTimeImmutable('today');
if (!empty($_GET['monat'])) {
    // Format: YYYY-MM
    [$jahr, $mon] = explode('-', $_GET['monat'] . '-');
    $von = (new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$jahr, (int)$mon)));
} else {
    $von = $heute->modify('first day of this month');
}
$bis = $von->modify('last day of this month');

$vonStr = $von->format('Y-m-d');
$bisStr = $bis->format('Y-m-d');

// Aggregation: pro Mitarbeiter + Tag
$stmt = $pdo->prepare("
    SELECT
  COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter,
  DATE(q.created_at) AS tag,
  COUNT(*) AS anz_pruefungen,
  SUM(q.result = 'ABWEICHUNG') AS anz_abweichungen
    FROM qc_100_pruefungen q
    LEFT JOIN users u ON q.employee_id = u.id
    WHERE DATE(q.created_at) BETWEEN :von AND :bis
    GROUP BY mitarbeiter, DATE(q.created_at)
    ORDER BY mitarbeiter, tag
");
$stmt->execute([':von' => $vonStr, ':bis' => $bisStr]);
$rows = $stmt->fetchAll();

// nach Mitarbeiter gruppieren
$byEmployee = [];
foreach ($rows as $r) {
    $byEmployee[$r['mitarbeiter']][] = $r;
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Arbeitsnachweis 100%-Prüfungen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    @media print {
      .no-print { display: none !important; }
    }
  </style>
</head>
<body class="bg-light">
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h1 class="h4 mb-0">Arbeitsnachweis – 100%-Prüfungen</h1>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">Drucken / PDF</button>
  </div>

  <form class="row g-2 mb-3 no-print">
    <div class="col-auto">
      <label class="form-label">Monat wählen</label>
      <input type="month" name="monat" class="form-control"
             value="<?=htmlspecialchars($von->format('Y-m'))?>">
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">Anzeigen</button>
    </div>
  </form>

  <p><strong>Zeitraum:</strong>
    <?=$von->format('d.m.Y')?> – <?=$bis->format('d.m.Y')?>
  </p>

  <?php if (empty($byEmployee)): ?>
    <div class="alert alert-info">
      In diesem Zeitraum wurden keine 100%-Prüfungen erfasst.
    </div>
  <?php endif; ?>

  <?php foreach ($byEmployee as $mitarbeiter => $liste): ?>
    <div class="card mb-3">
      <div class="card-header">
        <strong>Mitarbeiter: <?=htmlspecialchars($mitarbeiter)?></strong>
      </div>
      <div class="card-body p-2">
        <table class="table table-sm mb-0">
          <thead>
          <tr>
            <th>Tag</th>
            <th>Anzahl Prüfungen</th>
            <th>davon Abweichungen</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($liste as $r): ?>
            <tr>
              <td><?=htmlspecialchars(date('d.m.Y', strtotime($r['tag'])))?></td>
              <td><?= (int)$r['anz_pruefungen'] ?></td>
              <td><?= (int)$r['anz_abweichungen'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-end">
        Unterschrift Mitarbeiter: _______________________
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
