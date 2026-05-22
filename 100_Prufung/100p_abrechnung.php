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

// ==== KONFIG: Standard-Vergütung pro KLT (Default-Werte) ===================
$defaultRatePerKlt = [
    'Etikettierung KLT'    => 0.02,  // z.B. 2 Cent/KLT
    'Umpacken auf Palette' => 0.03,  // z.B. 3 Cent/KLT
    'Umfüllung in KLT'     => 0.03,  // z.B. 3 Cent/KLT
];

// Start: mit Defaults arbeiten
$ratePerKlt = $defaultRatePerKlt;

// Aus Datenbank überschreiben, falls vorhanden
try {
    $stmtRate = $pdo->query("
        SELECT reason, rate_per_klt
        FROM qc_klt_rates
    ");
    while ($row = $stmtRate->fetch(PDO::FETCH_ASSOC)) {
        $reasonKey = $row['reason'];
        $rate      = (float)$row['rate_per_klt'];
        if (isset($ratePerKlt[$reasonKey])) {
            $ratePerKlt[$reasonKey] = $rate;
        }
    }
} catch (Throwable $e) {
    // Wenn Tabelle noch nicht existiert, einfach mit Defaults weiterarbeiten
}

// Nur diese Gründe sind abrechnungsrelevant:
$abrechnungsGruende = array_keys($ratePerKlt);

// Bereiche / Hallen
$allowedHalls = ['W1','X3','Banking','G9'];

// Gründe, bei denen KLT gezählt werden
$kltReasons = [
    'Etikettierung KLT',
    'Umpacken auf Palette',
    'Umfüllung in KLT',
];

// ==== POST: Sätze pro KLT speichern =======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rates'])) {
    // Filterkontext aus dem Formular wieder herstellen
    $von    = $_POST['von']    ?? date('Y-m-01');
    $bis    = $_POST['bis']    ?? date('Y-m-d');
    $hall   = $_POST['hall']   ?? '';
    $reason = $_POST['reason'] ?? '';
    $search = trim($_POST['search'] ?? '');

    $postedRates = $_POST['rate'] ?? [];
    if (is_array($postedRates)) {
        try {
            // Tabelle qc_klt_rates:
            // reason (PK, VARCHAR), rate_per_klt (DECIMAL)
            $stmtUp = $pdo->prepare("
                INSERT INTO qc_klt_rates (reason, rate_per_klt)
                VALUES (:reason, :rate)
                ON DUPLICATE KEY UPDATE rate_per_klt = VALUES(rate_per_klt)
            ");

            foreach ($defaultRatePerKlt as $gr => $_dummy) {
                if (!array_key_exists($gr, $postedRates)) {
                    continue;
                }
                $raw  = (string)$postedRates[$gr];
                $raw  = str_replace(',', '.', $raw);
                $rate = (float)$raw;
                if ($rate < 0) {
                    $rate = 0;
                }

                $stmtUp->execute([
                    ':reason' => $gr,
                    ':rate'   => $rate,
                ]);
            }
        } catch (Throwable $e) {
            // Optional: Logging
        }
    }

    // Zurück auf die Abrechnungsseite mit gleichen Filtern
    $redirParams = [
        'von'         => $von,
        'bis'         => $bis,
        'hall'        => $hall,
        'reason'      => $reason,
        'search'      => $search,
        'mode'        => '', // manuelle Auswahl
        'rates_saved' => 1,  // kleiner Hinweis für Banner
    ];
    $qs = http_build_query($redirParams);
    header('Location: ' . $_SERVER['PHP_SELF'] . ($qs ? '?' . $qs : ''));
    exit;
}

// ==== Filter / Zeitraum (GET) =============================================
$mode   = $_GET['mode']   ?? ''; // 'last_week' / 'last_month' / ''
$hall   = $_GET['hall']   ?? '';
$reason = $_GET['reason'] ?? '';
$search = trim($_GET['search'] ?? '');

$today = new DateTimeImmutable('today');

// Zeitraum setzen: Modus hat Vorrang
if ($mode === 'last_week') {
    // Letzte Woche: Montag bis Sonntag der Vorwoche
    $weekStart = $today->modify('monday last week');
    $weekEnd   = $today->modify('sunday last week');
    $von = $weekStart->format('Y-m-d');
    $bis = $weekEnd->format('Y-m-d');
} elseif ($mode === 'last_month') {
    // Letzter Monat: 1. bis letzter Tag Vormonat
    $lastMonthStart = $today->modify('first day of last month');
    $lastMonthEnd   = $today->modify('last day of last month');
    $von = $lastMonthStart->format('Y-m-d');
    $bis = $lastMonthEnd->format('Y-m-d');
} else {
    // Manuelle Auswahl / Standard = aktueller Monat
    $von = $_GET['von'] ?? date('Y-m-01');
    $bis = $_GET['bis'] ?? date('Y-m-d');
}

// ==== KLT-Summen pro Grund im Zeitraum ====================================
$kltStats = [];
foreach ($kltReasons as $gr) {
    $kltStats[$gr] = 0;
}

try {
    $sqlKlt = "
      SELECT reason, SUM(COALESCE(klt_count,0)) AS klt_sum
      FROM qc_100_pruefungen
      WHERE DATE(created_at) BETWEEN :von AND :bis
        AND reason IN ('Etikettierung KLT','Umpacken auf Palette','Umfüllung in KLT')
    ";

    $paramsKlt = [
        ':von' => $von,
        ':bis' => $bis,
    ];

    // optional Halle
    if (!empty($hall)) {
        $sqlKlt .= " AND hall = :hall";
        $paramsKlt[':hall'] = $hall;
    }

    // optional Suche
    if (!empty($search)) {
        $sqlKlt .= " AND (
          pallet_code   LIKE :search
          OR delivery_note LIKE :search
          OR material_no   LIKE :search
        )";
        $paramsKlt[':search'] = '%' . $search . '%';
    }

    // optional Reason
    if (!empty($reason) && in_array($reason, $kltReasons, true)) {
        $sqlKlt .= " AND reason = :reason";
        $paramsKlt[':reason'] = $reason;
    }

    $sqlKlt .= " GROUP BY reason";

    $stmtKlt = $pdo->prepare($sqlKlt);
    $stmtKlt->execute($paramsKlt);

    while ($row = $stmtKlt->fetch(PDO::FETCH_ASSOC)) {
        $gr = $row['reason'];
        if (isset($kltStats[$gr])) {
            $kltStats[$gr] = (int)$row['klt_sum'];
        }
    }
} catch (Throwable $e) {
    // zur Not alles 0 lassen
}

// ==== Daten laden: nur abrechnungsrelevante Vorgänge ======================
$sql = "
  SELECT 
    q.*,
    COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter
  FROM qc_100_pruefungen q
  LEFT JOIN users u ON q.employee_id = u.id
  WHERE DATE(q.created_at) BETWEEN :von AND :bis
    AND q.reason IN ('Etikettierung KLT','Umpacken auf Palette','Umfüllung in KLT')
";

$params = [
    ':von' => $von,
    ':bis' => $bis,
];

if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
    $sql .= " AND q.hall = :hall";
    $params[':hall'] = $hall;
}

if ($reason !== '' && in_array($reason, $abrechnungsGruende, true)) {
    $sql .= " AND q.reason = :reason";
    $params[':reason'] = $reason;
}

if ($search !== '') {
    $sql .= " AND (
      q.pallet_code   LIKE :search
      OR q.delivery_note LIKE :search
      OR q.material_no   LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY q.employee_id, q.created_at";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ==== Abrechnung nach Mitarbeiter ========================================
$empSummary      = [];
$rowsDetailed    = [];
$totalAmountAll  = 0.0;
$totalKltAll     = 0;

foreach ($rows as $r) {
    $emp = $r['mitarbeiter'] ?? 'Unbekannt';
    $gr  = $r['reason'] ?? '';
    $klt = isset($r['klt_count']) ? (int)$r['klt_count'] : 0;

    $rate   = $ratePerKlt[$gr] ?? 0.0;   // -> Sätze aus DB / Defaults
    $amount = $klt * $rate;

    if (!isset($empSummary[$emp])) {
        $empSummary[$emp] = [
            'klt_total' => 0,
            'amount'    => 0.0,
            'by_reason' => [],
        ];
    }

    $empSummary[$emp]['klt_total'] += $klt;
    $empSummary[$emp]['amount']    += $amount;

    if (!isset($empSummary[$emp]['by_reason'][$gr])) {
        $empSummary[$emp]['by_reason'][$gr] = [
            'klt'    => 0,
            'amount' => 0.0,
        ];
    }
    $empSummary[$emp]['by_reason'][$gr]['klt']    += $klt;
    $empSummary[$emp]['by_reason'][$gr]['amount'] += $amount;

    $totalAmountAll += $amount;
    $totalKltAll    += $klt;

    // Für Detailtabelle
    $r['_klt']    = $klt;
    $r['_rate']   = $rate;
    $r['_amount'] = $amount;
    $rowsDetailed[] = $r;
}

// Mitarbeiter alphabetisch
ksort($empSummary);

// ==== Helper: Wochentagsnamen ============================================
if (!function_exists('weekday_de')) {
    function weekday_de(string $dateOrDatetime): string {
        $ts = strtotime($dateOrDatetime);
        if (!$ts) return '';
        $n = (int)date('N', $ts); // 1=Mo ... 7=So
        $names = [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ];
        return $names[$n] ?? '';
    }
}

$heuteLabel = weekday_de(date('Y-m-d')) . ', ' . date('d.m.Y');

$selfUrl = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);
?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>100%-Prüfungen – Abrechnung</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              light: '#e0f2fe',
              DEFAULT: '#0ea5e9',
              dark: '#0369a1'
            }
          }
        }
      }
    };
  </script>
</head>
<body class="min-h-full text-slate-900 text-base">
  <main class="w-full py-4 px-3 sm:px-6 lg:px-10">

    <!-- Header -->
    <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">
          100%-Prüfungen – Abrechnung (KLT-Arbeiten)
        </h1>
        <p class="mt-1 text-sm text-slate-700">
          Zeitraum:
          <span class="font-medium">
            <?=htmlspecialchars(date('d.m.Y', strtotime($von)))?>
            –
            <?=htmlspecialchars(date('d.m.Y', strtotime($bis)))?>
          </span>
        </p>
        <p class="mt-1 text-sm text-slate-700">
          Heute: <span class="font-medium"><?=$heuteLabel?></span>
        </p>

        <!-- Schnellwahl Abrechnungszeiträume -->
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-700">
          <span class="text-slate-500">Schnellwahl Abrechnung:</span>

          <a href="<?=$selfUrl?>?mode=last_week&hall=<?=urlencode($hall)?>&reason=<?=urlencode($reason)?>&search=<?=urlencode($search)?>"
             class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 shadow-sm hover:bg-slate-50 hover:border-slate-300">
            Letzte Woche (Mo–So)
          </a>

          <a href="<?=$selfUrl?>?mode=last_month&hall=<?=urlencode($hall)?>&reason=<?=urlencode($reason)?>&search=<?=urlencode($search)?>"
             class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 shadow-sm hover:bg-slate-50 hover:border-slate-300">
            Letzter Monat
          </a>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 justify-start sm:justify-end">
        <a href="/index.php?tab=admin"
   class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
  ← Zur Übersicht
</a>

        <span class="inline-flex flex-col items-end rounded-xl bg-white px-3 py-2 shadow-sm border border-slate-200 text-sm">
          <span class="text-slate-500 text-xs">Gesamt KLT (Zeitraum)</span>
          <span class="font-semibold text-slate-900"><?=$totalKltAll?> KLT</span>
        </span>
        <span class="inline-flex flex-col items-end rounded-xl bg-emerald-50 px-3 py-2 shadow-sm border border-emerald-200 text-sm">
          <span class="text-emerald-700 text-xs">Gesamtbetrag</span>
          <span class="font-semibold text-emerald-700">
            <?=number_format($totalAmountAll, 2, ',', '.')?> €
          </span>
        </span>
      </div>
    </header>

    <!-- Filter -->
    <section class="mb-6">
      <form method="get"
            class="flex flex-wrap items-end gap-3 bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <input type="hidden" name="mode" value=""> <!-- Modus leeren bei manueller Filterung -->

        <div class="flex flex-col gap-1.5 w-full sm:w-auto">
          <label class="text-sm font-medium text-slate-800">Von</label>
          <input type="date"
                 name="von"
                 class="rounded-md border-slate-300 text-base w-full sm:w-40"
                 value="<?=htmlspecialchars($von)?>">
        </div>
        <div class="flex flex-col gap-1.5 w-full sm:w-auto">
          <label class="text-sm font-medium text-slate-800">Bis</label>
          <input type="date"
                 name="bis"
                 class="rounded-md border-slate-300 text-base w-full sm:w-40"
                 value="<?=htmlspecialchars($bis)?>">
        </div>
        <div class="flex flex-col gap-1.5 w-full sm:w-auto">
          <label class="text-sm font-medium text-slate-800">Halle / Bereich</label>
          <select name="hall"
                  class="rounded-md border-slate-300 text-base w-full sm:w-40">
            <option value="">alle</option>
            <?php foreach ($allowedHalls as $h): ?>
              <option value="<?=$h?>" <?=$hall===$h?'selected':''?>><?=$h?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-col gap-1.5 w-full sm:w-auto">
          <label class="text-sm font-medium text-slate-800">Grund</label>
          <select name="reason"
                  class="rounded-md border-slate-300 text-base w-full sm:w-56">
            <option value="">alle</option>
            <?php foreach ($abrechnungsGruende as $g): ?>
              <option value="<?=$g?>" <?=$reason===$g?'selected':''?>><?=$g?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-col gap-1.5 flex-1 min-w-[220px]">
          <label class="text-sm font-medium text-slate-800">Suche (Palette / Lieferschein / Sachnummer)</label>
          <input type="text"
                 name="search"
                 value="<?=htmlspecialchars($search)?>"
                 placeholder="Code eintippen oder scannen..."
                 class="rounded-md border-slate-300 text-base px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand w-full">
        </div>
        <div class="flex gap-2 w-full sm:w-auto sm:ml-auto justify-start sm:justify-end">
          <button type="button"
                  id="resetFilter"
                  class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Filter zurücksetzen
          </button>
          <button type="submit"
                  class="inline-flex items-center justify-center rounded-md bg-brand text-white text-base px-5 py-2 font-medium shadow-sm hover:bg-brand-dark transition">
            Filtern
          </button>
        </div>
      </form>
    </section>

    <!-- Sätze pro KLT -->
    <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <h2 class="text-base font-semibold text-slate-900 mb-3">
        Sätze pro KLT – Abrechnung
      </h2>

      <?php if (!empty($_GET['rates_saved'])): ?>
        <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
          Sätze wurden gespeichert. Alle Beträge wurden mit den neuen Sätzen neu berechnet.
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-3">
        <!-- Filterkontext für POST mitgeben -->
        <input type="hidden" name="von"    value="<?=htmlspecialchars($von)?>">
        <input type="hidden" name="bis"    value="<?=htmlspecialchars($bis)?>">
        <input type="hidden" name="hall"   value="<?=htmlspecialchars($hall)?>">
        <input type="hidden" name="reason" value="<?=htmlspecialchars($reason)?>">
        <input type="hidden" name="search" value="<?=htmlspecialchars($search)?>">

        <div class="overflow-x-auto">
          <table id="abrechnungTable" class="min-w-full border-collapse text-sm text-slate-800">
            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-3 py-2 text-left">Grund</th>
                <th class="px-3 py-2 text-right">Satz pro KLT&nbsp;(&euro;)</th>
                <th class="px-3 py-2 text-center">Anzahl KLT</th>
                <th class="px-3 py-2 text-right">Summe (&euro;)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($kltReasons as $gr):
                if (!empty($reason) && $reason !== $gr) continue;
                $kltAnz  = $kltStats[$gr] ?? 0;
                $rateNow = $ratePerKlt[$gr] ?? 0.0;
                // 4 Nachkommastellen, Komma als Trenner
                $rateVal = $rateNow > 0 ? number_format($rateNow, 2, '.', '') : '';
              ?>
                <tr data-klt="<?=$kltAnz?>">
                  <td class="px-3 py-2">
                    <?=htmlspecialchars($gr)?>
                  </td>
                  <td class="px-3 py-2">
  <div class="relative max-w-[140px] ml-auto">
    <input type="number"
           step="0.01"
           min="0"
           class="rate-input block w-full rounded-md border border-slate-300 px-2 py-1 text-right text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand"
           placeholder="0.00"
           name="rate[<?=htmlspecialchars($gr)?>]"
           value="<?=htmlspecialchars($rateVal)?>">
    <span class="rate-suffix pointer-events-none absolute inset-y-0 right-2 flex items-center text-[11px] text-slate-400 transition-opacity">
      €/KLT
    </span>
  </div>
</td>

                  <td class="px-3 py-2 text-center font-medium" data-klt-display>
                    <?=$kltAnz?>
                  </td>
                  <td class="px-3 py-2 text-right font-semibold sum-cell" data-sum>
                    –
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-slate-50">
              <tr>
                <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold text-slate-600">
                  Gesamt
                </td>
                <td class="px-3 py-2 text-right text-sm font-bold text-slate-900" id="abrechnungTotal">
                  –
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div class="flex justify-end">
          <button type="submit"
                  name="save_rates"
                  class="inline-flex items-center rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-dark">
            Sätze speichern
          </button>
        </div>
      </form>
    </section>

    <!-- Abrechnung nach Mitarbeiter -->
    <section class="mb-6 mt-6">
      <h2 class="text-base font-semibold text-slate-900 mb-2">
        Abrechnung nach Mitarbeiter
      </h2>
      <?php if (empty($empSummary)): ?>
        <p class="text-sm text-slate-500">
          Im gewählten Zeitraum sind keine abrechnungsrelevanten Vorgänge vorhanden.
        </p>
      <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
          <table class="min-w-full border-collapse text-[13px]">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left">Mitarbeiter</th>
                <th class="px-3 py-2 text-right">KLT gesamt</th>
                <th class="px-3 py-2 text-right">Betrag gesamt</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($empSummary as $name => $s): ?>
                <tr>
                  <td class="px-3 py-1.5 align-top">
                    <div class="font-medium text-slate-900"><?=htmlspecialchars($name)?></div>
                    <?php if (!empty($s['by_reason'])): ?>
                      <div class="mt-1 text-[11px] text-slate-500 space-y-0.5">
                        <?php foreach ($s['by_reason'] as $gr => $rs): ?>
                          <div>
                            <?=htmlspecialchars($gr)?>:
                            <?=$rs['klt']?> KLT
                            (<?=number_format($rs['amount'], 2, ',', '.')?> €)
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-1.5 text-right font-medium">
                    <?=$s['klt_total']?> KLT
                  </td>
                  <td class="px-3 py-1.5 text-right font-semibold text-emerald-700">
                    <?=number_format($s['amount'], 2, ',', '.')?> €
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-slate-50">
              <tr>
                <td class="px-3 py-2 font-semibold text-slate-900">Summe</td>
                <td class="px-3 py-2 text-right font-semibold"><?=$totalKltAll?> KLT</td>
                <td class="px-3 py-2 text-right font-semibold text-emerald-700">
                  <?=number_format($totalAmountAll, 2, ',', '.')?> €
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Detailtabelle je Vorgang -->
    <section class="mb-10">
      <h2 class="text-base font-semibold text-slate-900 mb-2">
        Einzelvorgänge (Kontrolle)
      </h2>
      <?php if (empty($rowsDetailed)): ?>
        <p class="text-sm text-slate-500">Keine Vorgänge im Zeitraum.</p>
      <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
          <table class="min-w-full border-collapse text-[12px]">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left">Datum/Zeit</th>
                <th class="px-3 py-2 text-left">Mitarbeiter</th>
                <th class="px-3 py-2 text-left">Grund</th>
                <th class="px-3 py-2 text-left">Palette</th>
                <th class="px-3 py-2 text-left">Lieferschein</th>
                <th class="px-3 py-2 text-left">Sachnr.</th>
                <th class="px-3 py-2 text-right">KLT</th>
                <th class="px-3 py-2 text-right">€/KLT</th>
                <th class="px-3 py-2 text-right">Betrag</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($rowsDetailed as $r):
                $dt = new DateTimeImmutable($r['created_at']);
              ?>
                <tr>
                  <td class="px-3 py-1.5 whitespace-nowrap"><?=$dt->format('d.m.Y H:i')?></td>
                  <td class="px-3 py-1.5"><?=htmlspecialchars($r['mitarbeiter'])?></td>
                  <td class="px-3 py-1.5"><?=htmlspecialchars($r['reason'] ?? '')?></td>
                  <td class="px-3 py-1.5"><?=htmlspecialchars($r['pallet_code'] ?? '')?></td>
                  <td class="px-3 py-1.5"><?=htmlspecialchars($r['delivery_note'] ?? '')?></td>
                  <td class="px-3 py-1.5"><?=htmlspecialchars($r['material_no'] ?? '')?></td>
                  <td class="px-3 py-1.5 text-right"><?=$r['_klt']?></td>
                  <td class="px-3 py-1.5 text-right">
                    <?=number_format($r['_rate'], 2, ',', '.')?> €
                  </td>
                  <td class="px-3 py-1.5 text-right font-semibold">
                    <?=number_format($r['_amount'], 2, ',', '.')?> €
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  </main>

  <script>
    // Filter zurücksetzen
    (function () {
      const btn = document.getElementById('resetFilter');
      if (!btn) return;
      btn.addEventListener('click', () => {
        window.location.href = window.location.pathname;
      });
    })();
  </script>
<script>
  (function () {
  const table = document.getElementById('abrechnungTable');
  if (!table) return;

  function parseNumber(val) {
    if (!val) return 0;
    val = String(val).replace(',', '.');
    const n = parseFloat(val);
    return isNaN(n) ? 0 : n;
  }

  function formatEuro(value) {
    return value.toFixed(2).replace('.', ',') + ' €';
  }

  function recalcAbrechnung() {
    let total = 0;

    table.querySelectorAll('tbody tr').forEach(tr => {
      const klt = parseNumber(tr.getAttribute('data-klt'));
      const input  = tr.querySelector('.rate-input');
      const sumCell = tr.querySelector('[data-sum]');
      if (!input || !sumCell) return;

      const rate = parseNumber(input.value);
      const sum  = rate * klt;

      if (sum > 0) {
        sumCell.textContent = formatEuro(sum);
      } else {
        sumCell.textContent = '–';
      }

      total += sum;
    });

    const totalCell = document.getElementById('abrechnungTotal');
    if (totalCell) {
      totalCell.textContent = total > 0 ? formatEuro(total) : '–';
    }
  }

  function updateSuffixVisibility(input) {
    const wrapper = input.closest('.relative');
    if (!wrapper) return;
    const suffix = wrapper.querySelector('.rate-suffix');
    if (!suffix) return;

    if (input.value && input.value.trim() !== '') {
      suffix.classList.add('opacity-0');
    } else {
      suffix.classList.remove('opacity-0');
    }
  }

  table.querySelectorAll('.rate-input').forEach(input => {
    // Bei Eingabe: Suffix verstecken/zeigen + Summe neu rechnen
    input.addEventListener('input', () => {
      updateSuffixVisibility(input);
      recalcAbrechnung();
    });

    // Initialzustand (für schon gespeicherte Sätze)
    updateSuffixVisibility(input);
  });

  // initial Summen berechnen
  recalcAbrechnung();
})();
</script>

</body>
</html>
