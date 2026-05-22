<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);
  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
  return $roles ?: ['admin'];
}

function api_err(string $msg, int $code=400): void {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$userId = $_SESSION['user_id'] ?? null;
$role   = $_SESSION['role'] ?? '';

if (!$userId) api_err('login_required', 401);

$allowed = allowed_roles_for_tab($pdo, 'special'); // Tab-Key passend zu deinem Dashboard
if (!in_array($role, $allowed, true)) api_err('forbidden', 403);

// Filter aus Query übernehmen (müssen zum Dashboard passen)
$hall   = $_GET['hall']   ?? '';
$search = $_GET['search'] ?? '';

$allowedHalls = ['W1', 'H28', 'CK10', 'CK20', 'CK30']; // ggf. deine Liste hier

$openPalletsDash = [];

try {
    $sqlOpen = "
      SELECT
        pallet_code,
        MIN(created_at) AS started_at,
        MAX(created_at) AS last_at,
        MAX(CASE WHEN reason IN ('Umpacken auf Palette', 'Umfüllung in KLT')
                 THEN 1 ELSE 0 END) AS has_ump,
        MAX(CASE WHEN reason = 'Etikettierung KLT'
                 THEN 1 ELSE 0 END) AS has_klt,
        MAX(CASE WHEN reason = '100% Prüfung'
                 THEN 1 ELSE 0 END) AS has_100,
        SUM(CASE WHEN reason = 'Etikettierung KLT'
                 THEN COALESCE(klt_count,0) ELSE 0 END) AS klt_sum,
        MAX(delivery_note) AS delivery_note,
        MAX(material_no)   AS material_no
      FROM qc_100_pruefungen
      WHERE 1=1
    ";

    $paramsOpen = [];

    if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
        $sqlOpen .= " AND hall = :hall";
        $paramsOpen[':hall'] = $hall;
    }

    if ($search !== '') {
        $sqlOpen .= " AND (
          pallet_code   LIKE :search_open
          OR delivery_note LIKE :search_open
          OR material_no   LIKE :search_open
        )";
        $paramsOpen[':search_open'] = '%' . $search . '%';
    }

    $sqlOpen .= "
      GROUP BY pallet_code
      HAVING has_ump = 1
        AND (has_klt = 0 OR has_100 = 0)
      ORDER BY last_at DESC
      LIMIT 100
    ";

    $stmtOpen = $pdo->prepare($sqlOpen);
    $stmtOpen->execute($paramsOpen);
    $openPalletsDash = $stmtOpen->fetchAll() ?: [];
} catch (Throwable $e) {
    $openPalletsDash = [];
}

// HTML der Tabelle bauen (EXAKT wie im Dashboard!)
ob_start();
?>

<?php if ($openPalletsDash): ?>
  <h2 class="text-base font-semibold text-slate-900 mb-2">
    Offene Prozesse je Palette (Umpacken erledigt, Etikettierung oder 100% fehlt)
  </h2>
  <div class="overflow-x-auto rounded-xl border border-amber-200 bg-amber-50/60">
    <table class="min-w-full border-collapse text-[13px] text-slate-900"
           data-open-pallets-table="1">
      <thead>
        <tr class="bg-amber-100 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
          <th class="px-3 py-2 text-left">Palette / Prüflabel</th>
          <th class="px-3 py-2 text-left">Lieferschein</th>
          <th class="px-3 py-2 text-left">Sachnummer</th>
          <th class="px-3 py-2 text-left">Umpacken</th>
          <th class="px-3 py-2 text-left">Etikettierung</th>
          <th class="px-3 py-2 text-left">100% Prüfung</th>
          <th class="px-3 py-2 text-left">KLT gesamt</th>
          <th class="px-3 py-2 text-left">gestartet am</th>
          <th class="px-3 py-2 text-left">letzte Aktivität</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($openPalletsDash as $op):
          $started = new DateTimeImmutable($op['started_at']);
          $last    = new DateTimeImmutable($op['last_at']);
        ?>
          <tr class="border-b border-amber-100/80">
            <td class="px-3 py-1.5 font-medium whitespace-nowrap">
              <?=htmlspecialchars($op['pallet_code'])?>
            </td>
            <td class="px-3 py-1.5 whitespace-nowrap">
              <?=htmlspecialchars($op['delivery_note'] ?? '')?>
            </td>
            <td class="px-3 py-1.5 whitespace-nowrap">
              <?=htmlspecialchars($op['material_no'] ?? '')?>
            </td>

            <!-- Umpacken -->
            <td class="px-3 py-1.5">
              <?php if ($op['has_ump']): ?>
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                  ✔ erledigt
                </span>
              <?php else: ?>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                  – fehlt –
                </span>
              <?php endif; ?>
            </td>

            <!-- Etikettierung -->
            <td class="px-3 py-1.5">
              <?php if ($op['has_klt']): ?>
                <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700">
                  ✔ Etikettiert
                </span>
              <?php else: ?>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                  – offen –
                </span>
              <?php endif; ?>
            </td>

            <!-- 100% Prüfung -->
            <td class="px-3 py-1.5">
              <?php if ($op['has_100']): ?>
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                  ✔ geprüft
                </span>
              <?php else: ?>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                  – offen –
                </span>
              <?php endif; ?>
            </td>

            <!-- KLT gesamt -->
            <td class="px-3 py-1.5">
              <?php if ((int)$op['klt_sum'] > 0): ?>
                <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700">
                  <?=$op['klt_sum']?> KLT
                </span>
              <?php else: ?>
                <span class="text-[11px] text-slate-400">–</span>
              <?php endif; ?>
            </td>

            <!-- gestartet am -->
            <td class="px-3 py-1.5 text-[11px] whitespace-nowrap text-slate-600">
              <?=$started->format('d.m.Y H:i')?>
            </td>

            <!-- letzte Aktivität -->
            <td class="px-3 py-1.5 text-[11px] whitespace-nowrap text-slate-600">
              <?=$last->format('d.m.Y H:i')?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500">
    Aktuell keine offenen Prozesse je Palette. 🎉
  </div>
<?php endif; ?>

<?php
$html = ob_get_clean();

echo json_encode([
  'ok'   => true,
  'html' => $html,
]);
