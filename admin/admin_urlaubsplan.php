<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

/** Rollen dynamisch aus DB */
function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
    $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
    $st->execute([':t' => $tabKey]);
    $roles = $st->fetchAll(PDO::FETCH_COLUMN);
    $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
    return $roles ?: ['admin'];
}

$AUTH_DEFAULT_TAB   = 'admin';
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, 'admin');
$AUTH_DENY_MODE     = 'redirect';
require __DIR__ . '/../inc/auth_embed.php';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function month_from(?string $p): int {
    $m = (int)$p;
    if ($m < 1 || $m > 12) {
        $m = (int)(new DateTimeImmutable('now'))->format('n');
    }
    return $m;
}

/**
 * Anzeige in Monatsmatrix:
 * - U => "1" (wie in deinem Screenshot)
 * - FO/K/GLZ/SONST => Kürzel
 */
function absence_cell_meta(string $type): array {
    switch ($type) {
        case 'U':
            return ['1', 'bg-lime-200 text-lime-900', 'Urlaub'];
        case 'FO':
            return ['FO', 'bg-sky-200 text-sky-900', 'Fortbildung'];
        case 'K':
            return ['K', 'bg-rose-200 text-rose-900', 'Krank'];
        case 'GLZ':
            return ['GLZ', 'bg-amber-200 text-amber-900', 'Gleitzeit'];
        default:
            return ['S', 'bg-slate-200 text-slate-900', 'Sonstiges'];
    }
}

function year_from(?string $p): int {
    $y = (int)$p;
    if ($y < 2020 || $y > 2100) {
        $y = (int)(new DateTimeImmutable('now'))->format('Y');
    }
    return $y;
}

$types = [
    'U'     => 'Urlaub',
    'FO'    => 'Fortbildung',
    'K'     => 'Krank',
    'GLZ'   => 'Gleitzeit',
    'SONST' => 'Sonstiges',
];

$year = year_from($_GET['year'] ?? $_POST['year'] ?? null);
$viewMonth = month_from($_GET['m'] ?? $_POST['m'] ?? null);

$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$backWeek = (string)($_GET['back_week'] ?? '');
if (!preg_match('/^\d{4}-W\d{2}$/', $backWeek)) {
    $now = new DateTimeImmutable('now');
    $backWeek = sprintf('%04d-W%02d', (int)$now->format('o'), (int)$now->format('W'));
}

/* ========================= AJAX: Zell-Schnellaktionen (Doppelklick) ========================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string)($_POST['action'] ?? ''), ['quick_save_day', 'quick_delete_day'], true)
) {
    // Wenn Puffer aktiv ist, leeren (hilft gegen kaputtes JSON durch Restausgaben)
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    $action = (string)($_POST['action'] ?? '');
    $uid    = (int)($_POST['user_id'] ?? 0);
    $date   = trim((string)($_POST['date'] ?? ''));

    $validDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    if ($uid <= 0 || !$validDate) {
        echo json_encode(['ok' => false, 'message' => 'Ungültige Daten.']);
        exit;
    }

    try {
        $d = new DateTimeImmutable($date);

        // Sonntag sperren (wie bei deinem Save-Formular)
        if ((int)$d->format('N') === 7) {
            echo json_encode(['ok' => false, 'message' => 'Sonntag wird nicht beplant.']);
            exit;
        }

        if ($action === 'quick_save_day') {
            // Doppelklick auf freie Zelle => 1 Tag Urlaub (U)
            $st = $pdo->prepare("
                INSERT INTO qc_absence_day
                  (user_id, absence_date, absence_type, note, created_by, updated_by)
                VALUES
                  (:uid, :adate, 'U', NULL, :cby, :uby)
                ON DUPLICATE KEY UPDATE
                  absence_type = VALUES(absence_type),
                  note         = VALUES(note),
                  updated_by   = VALUES(updated_by),
                  updated_at   = NOW()
            ");
            $st->execute([
                ':uid'   => $uid,
                ':adate' => $date,
                ':cby'   => $sessionUserId ?: null,
                ':uby'   => $sessionUserId ?: null,
            ]);

            echo json_encode(['ok' => true, 'mode' => 'saved']);
            exit;
        }

        if ($action === 'quick_delete_day') {
            // Doppelklick auf belegte Zelle => Eintrag löschen
            $st = $pdo->prepare("
                DELETE FROM qc_absence_day
                WHERE user_id = :uid
                  AND absence_date = :adate
            ");
            $st->execute([
                ':uid'   => $uid,
                ':adate' => $date,
            ]);

            echo json_encode(['ok' => true, 'mode' => 'deleted']);
            exit;
        }

        echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Speichern/Löschen fehlgeschlagen.']);
        exit;
    }
}



/* ========================= POST: speichern/löschen ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $uid    = (int)($_POST['user_id'] ?? 0);
    $from   = trim((string)($_POST['date_from'] ?? ''));
    $to     = trim((string)($_POST['date_to'] ?? ''));
    $type   = (string)($_POST['absence_type'] ?? 'U');
    $note   = trim((string)($_POST['note'] ?? ''));

    $validDate = static fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

    if ($uid > 0 && $validDate($from) && $validDate($to)) {
        $start = new DateTimeImmutable($from);
        $end   = new DateTimeImmutable($to);
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        if ($action === 'save') {
            if (!isset($types[$type])) $type = 'U';

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO qc_absence_day
                  (user_id, absence_date, absence_type, note, created_by, updated_by)
                VALUES
                  (:uid, :adate, :atype, :note, :cby, :uby)
                ON DUPLICATE KEY UPDATE
                  absence_type = VALUES(absence_type),
                  note         = VALUES(note),
                  updated_by   = VALUES(updated_by),
                  updated_at   = NOW()
            ");

            // inkl. Enddatum
            $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
            foreach ($period as $d) {
                // Sonntag überspringen (1=Mo ... 7=So)
                if ((int)$d->format('N') === 7) continue;

                $ins->execute([
                    ':uid'   => $uid,
                    ':adate' => $d->format('Y-m-d'),
                    ':atype' => $type,
                    ':note'  => $note !== '' ? $note : null,
                    ':cby'   => $sessionUserId ?: null,
                    ':uby'   => $sessionUserId ?: null,
                ]);
            }

            $pdo->commit();
            header('Location: admin_urlaubsplan.php?year=' . $year . '&msg=saved');
            exit;
        }

        if ($action === 'delete') {
            $delType = (string)($_POST['delete_type'] ?? 'ALL');

            if ($delType === 'ALL') {
                $st = $pdo->prepare("
                    DELETE FROM qc_absence_day
                    WHERE user_id = :uid
                      AND absence_date BETWEEN :f AND :t
                ");
                $st->execute([
                    ':uid' => $uid,
                    ':f'   => $start->format('Y-m-d'),
                    ':t'   => $end->format('Y-m-d'),
                ]);
            } else {
                if (!isset($types[$delType])) $delType = 'U';
                $st = $pdo->prepare("
                    DELETE FROM qc_absence_day
                    WHERE user_id = :uid
                      AND absence_date BETWEEN :f AND :t
                      AND absence_type = :atype
                ");
                $st->execute([
                    ':uid'   => $uid,
                    ':f'     => $start->format('Y-m-d'),
                    ':t'     => $end->format('Y-m-d'),
                    ':atype' => $delType,
                ]);
            }

            header('Location: admin_urlaubsplan.php?year=' . $year . '&msg=deleted');
            exit;
        }
    } else {
        header('Location: admin_urlaubsplan.php?year=' . $year . '&msg=invalid');
        exit;
    }
}

/* ========================= Daten laden ========================= */
$employees = $pdo->query("
    SELECT id, COALESCE(display_name, username, CONCAT('ID ', id)) AS name
    FROM users
    WHERE active = 1
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Monatsverbrauch Urlaub (nur Typ U), Mo-Sa => WEEKDAY <= 5 (Mo=0 ... So=6)
$stM = $pdo->prepare("
    SELECT user_id, MONTH(absence_date) AS m, COUNT(*) AS c
    FROM qc_absence_day
    WHERE YEAR(absence_date) = :y
      AND absence_type = 'U'
      AND WEEKDAY(absence_date) <= 5
    GROUP BY user_id, MONTH(absence_date)
");

$monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $viewMonth));
$monthEnd   = $monthStart->modify('last day of this month');

$dowShort = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

$monthDays = []; // pro Kalendertag des Monats
for ($d = 1; $d <= (int)$monthEnd->format('j'); $d++) {
    $obj = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $viewMonth, $d));
    $n   = (int)$obj->format('N'); // 1..7
    $monthDays[] = [
        'date'       => $obj->format('Y-m-d'),
        'day'        => (int)$obj->format('j'),
        'dow'        => $n,
        'dow_short'  => $dowShort[$n],
        'kw'         => (int)$obj->format('W'),
        'is_weekend' => ($n >= 6),
    ];
}

// KW-Spans für Kopfzeile (KW06 / KW07 ...)
$kwSpans = [];
$currentKw = null;
$span = 0;
foreach ($monthDays as $md) {
    if ($currentKw === null) {
        $currentKw = $md['kw'];
        $span = 1;
    } elseif ($md['kw'] === $currentKw) {
        $span++;
    } else {
        $kwSpans[] = ['kw' => $currentKw, 'span' => $span];
        $currentKw = $md['kw'];
        $span = 1;
    }
}
if ($span > 0) {
    $kwSpans[] = ['kw' => $currentKw, 'span' => $span];
}

// Abwesenheiten des Monats laden
$stGrid = $pdo->prepare("
    SELECT user_id, absence_date, absence_type
    FROM qc_absence_day
    WHERE absence_date BETWEEN :d1 AND :d2
");
$stGrid->execute([
    ':d1' => $monthStart->format('Y-m-d'),
    ':d2' => $monthEnd->format('Y-m-d'),
]);

$monthGrid = []; // [uid][Y-m-d] = type
while ($r = $stGrid->fetch(PDO::FETCH_ASSOC)) {
    $uid  = (int)$r['user_id'];
    $date = substr((string)$r['absence_date'], 0, 10);
    $type = (string)$r['absence_type'];
    $monthGrid[$uid][$date] = $type;
}
// Summen pro Mitarbeiter + pro Tag
$plannedByUser   = []; // "geplant" (U-Tage im Monat)
$approvedByUser  = []; // aktuell gleich geplant (ohne Status-Spalte)
$dailyAbsentAll  = []; // alle Typen pro Tag
$dailyAbsentU    = []; // nur Urlaub pro Tag
$dailyTipAll     = []; // Tooltip: Namen + Typ
$dailyTipU       = []; // Tooltip: Namen (nur U)

foreach ($monthDays as $md) {
    $date = $md['date'];
    $cAll = 0;
    $cU   = 0;

    $namesAll = [];
    $namesU   = [];

    foreach ($employees as $e) {
        $uid  = (int)$e['id'];
        $name = (string)$e['name'];
        $t    = $monthGrid[$uid][$date] ?? null;

        if ($t) {
            $cAll++;
            $typeLabel = $types[$t] ?? $t;
            $namesAll[] = $name . ' (' . $typeLabel . ')';

            if ($t === 'U') {
                $cU++;
                $namesU[] = $name;
            }
        }
    }

    $dailyAbsentAll[$date] = $cAll;
    $dailyAbsentU[$date]   = $cU;

    $dailyTipAll[$date] = $cAll > 0
        ? implode(' | ', $namesAll)
        : 'Keine Abwesenheit';

    $dailyTipU[$date] = $cU > 0
        ? implode(' | ', $namesU)
        : 'Kein Urlaub';
}


$stM->execute([':y' => $year]);

$monthCount = []; // [uid][1..12] = count
while ($r = $stM->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$r['user_id'];
    $m   = (int)$r['m'];
    $c   = (int)$r['c'];
    $monthCount[$uid][$m] = $c;
}

// Jahresverbrauch Urlaub
$stY = $pdo->prepare("
    SELECT user_id, COUNT(*) AS c
    FROM qc_absence_day
    WHERE YEAR(absence_date) = :y
      AND absence_type = 'U'
      AND WEEKDAY(absence_date) <= 5
    GROUP BY user_id
");
$stY->execute([':y' => $year]);
$usedYear = []; // [uid] => count
while ($r = $stY->fetch(PDO::FETCH_ASSOC)) {
    $usedYear[(int)$r['user_id']] = (float)$r['c'];
}

// Quoten
$stQ = $pdo->prepare("
    SELECT user_id, entitlement_days, carryover_days
    FROM qc_vacation_quota
    WHERE year = :y
");
$stQ->execute([':y' => $year]);
$quota = []; // [uid] => ['entitlement'=>..., 'carry'=>...]
while ($r = $stQ->fetch(PDO::FETCH_ASSOC)) {
    $quota[(int)$r['user_id']] = [
        'entitlement' => (float)$r['entitlement_days'],
        'carry'       => (float)$r['carryover_days'],
    ];
}

$months = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
    7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
];
$monthAnchors = [
    1 => 'januar',
    2 => 'februar',
    3 => 'maerz',
    4 => 'april',
    5 => 'mai',
    6 => 'juni',
    7 => 'juli',
    8 => 'august',
    9 => 'september',
    10 => 'oktober',
    11 => 'november',
    12 => 'dezember',
];

?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>Admin – Ganzjähriger Urlaubsplan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <style>
  th:target {
    outline: 2px solid #0ea5e9;
    background: #e0f2fe;
  }
</style>

</head>
<body class="min-h-full bg-slate-100 text-slate-900">
<div class="w-full px-3 sm:px-6 lg:px-10 py-6">

  <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-semibold">Ganzjähriger Urlaubsplan (<?=h((string)$year)?>)</h1>
      <p class="text-sm text-slate-600">Urlaubstage je Monat + Resturlaub pro Mitarbeiter.</p>
    </div>

    <form method="get" class="flex items-center gap-2">
      <label class="text-sm">Jahr:</label>
      <input type="number" min="2020" max="2100" name="year" value="<?=h((string)$year)?>"
             class="w-28 rounded-md border-slate-300 text-sm">
      <button class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-900">
        Anzeigen
      </button>
      <a href="admin_shiftplan.php?week=<?=rawurlencode($backWeek)?>"
   class="rounded-md bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">
  Zurück zum Shiftplan
</a>

    </form>
  </header>

  <?php if ($msg === 'saved'): ?>
    <div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Einträge gespeichert.</div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Einträge gelöscht.</div>
  <?php elseif ($msg === 'invalid'): ?>
    <div class="mb-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Bitte gültige Eingaben prüfen (Mitarbeiter + Datum).</div>
  <?php endif; ?>


  <section id="month-overlap-section" class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-sm font-semibold text-slate-800">Monatsansicht zur Überschneidungsprüfung</h2>
      <p class="text-xs text-slate-500">
        Grün/Code pro Tag = Abwesenheit. „1“ steht für Urlaub.
      </p>
    </div>

    <form method="get" action="" class="flex items-center gap-2 js-month-switch-form">
      <input type="hidden" name="year" value="2026">
      <input type="hidden" name="back_week" value="2026-W07">

      <label class="text-xs text-slate-600">Monat:</label>
      <select name="m" class="rounded-md border-slate-300 text-sm">
        <option value="1">Jan</option>
        <option value="2" selected>Feb</option>
        <option value="3">Mär</option>
        <option value="4">Apr</option>
        <option value="5">Mai</option>
        <option value="6">Jun</option>
        <option value="7">Jul</option>
        <option value="8">Aug</option>
        <option value="9">Sep</option>
        <option value="10">Okt</option>
        <option value="11">Nov</option>
        <option value="12">Dez</option>
      </select>

      <button type="submit" class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-900">
        Anzeigen
      </button>
    </form>
  </div>

   <div class="overflow-x-auto">
    <table class="min-w-max text-xs border border-slate-300">
      <thead>
        <tr class="bg-slate-200">
          <th rowspan="3" class="px-2 py-1 border border-slate-300 text-left min-w-[180px]">
            <?=h($months[$viewMonth])?>
          </th>
          <th rowspan="3" class="px-2 py-1 border border-slate-300 text-center">geplant</th>
          <th rowspan="3" class="px-2 py-1 border border-slate-300 text-center">genehmigt</th>

          <?php foreach ($kwSpans as $sp): ?>
            <th colspan="<?=$sp['span']?>" class="px-2 py-1 border border-slate-300 text-center font-semibold">
              KW<?=str_pad((string)$sp['kw'], 2, '0', STR_PAD_LEFT)?>
            </th>
          <?php endforeach; ?>
        </tr>

        <tr class="bg-slate-100">
          <?php foreach ($monthDays as $md):
              $mondayBorder = $md['dow'] === 1 ? 'border-l-2 border-l-slate-500' : '';
              $weekend = $md['is_weekend'] ? 'bg-amber-100' : '';
          ?>
            <th class="px-2 py-1 border border-slate-300 text-center <?=$mondayBorder?> <?=$weekend?>">
              <?=$md['day']?>
            </th>
          <?php endforeach; ?>
        </tr>

        <tr class="bg-slate-50">
          <?php foreach ($monthDays as $md):
              $mondayBorder = $md['dow'] === 1 ? 'border-l-2 border-l-slate-500' : '';
              $weekend = $md['is_weekend'] ? 'bg-amber-50' : '';
          ?>
            <th class="px-2 py-1 border border-slate-300 text-center <?=$mondayBorder?> <?=$weekend?>">
              <?=h($md['dow_short'])?>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>

      <tbody>
       <!-- Summenzeilen für schnelle Überschneidungs-Sicht -->
<tr class="bg-slate-50">
  <td class="px-2 py-1 border border-slate-300 font-semibold">Abwesend gesamt</td>
  <td class="px-2 py-1 border border-slate-300 text-center">–</td>
  <td class="px-2 py-1 border border-slate-300 text-center">–</td>

  <?php foreach ($monthDays as $md):
      $date = $md['date'];
      $c = (int)($dailyAbsentAll[$date] ?? 0);
      $mondayBorder = $md['dow'] === 1 ? 'border-l-2 border-l-slate-500' : '';
      $weekend      = $md['is_weekend'] ? 'bg-amber-50' : '';
      $warn         = $c >= 3 ? 'bg-rose-100 text-rose-800 font-semibold' : ($c >= 2 ? 'bg-amber-100 text-amber-900' : '');
      $tip          = 'Abwesend gesamt: ' . $c . ' · ' . ($dailyTipAll[$date] ?? 'Keine Abwesenheit');
  ?>
    <td class="px-2 py-1 border border-slate-300 text-center <?=$mondayBorder?> <?=$weekend?> <?=$warn?>"
        title="<?=h($tip)?>">
      <?=$c > 0 ? $c : ''?>
    </td>
  <?php endforeach; ?>
</tr>

<tr class="bg-slate-50">
  <td class="px-2 py-1 border border-slate-300 font-semibold">Urlaub (U) gesamt</td>
  <td class="px-2 py-1 border border-slate-300 text-center">–</td>
  <td class="px-2 py-1 border border-slate-300 text-center">–</td>

  <?php foreach ($monthDays as $md):
      $date = $md['date'];
      $c = (int)($dailyAbsentU[$date] ?? 0);
      $mondayBorder = $md['dow'] === 1 ? 'border-l-2 border-l-slate-500' : '';
      $weekend      = $md['is_weekend'] ? 'bg-amber-50' : '';
      $warn         = $c >= 3 ? 'bg-rose-100 text-rose-800 font-semibold' : ($c >= 2 ? 'bg-amber-100 text-amber-900' : '');
      $tip          = 'Urlaub gesamt: ' . $c . ' · ' . ($dailyTipU[$date] ?? 'Kein Urlaub');
  ?>
    <td class="px-2 py-1 border border-slate-300 text-center <?=$mondayBorder?> <?=$weekend?> <?=$warn?>"
        title="<?=h($tip)?>">
      <?=$c > 0 ? $c : ''?>
    </td>
  <?php endforeach; ?>
</tr>


        <?php foreach ($employees as $e):
            $uid = (int)$e['id'];
            $planned  = (int)($plannedByUser[$uid] ?? 0);
            $approved = (int)($approvedByUser[$uid] ?? 0);
        ?>
          <tr class="hover:bg-slate-50/70">
            <td class="px-2 py-1 border border-slate-300 whitespace-nowrap font-medium">
              <?=h((string)$e['name'])?>
            </td>
            <td class="px-2 py-1 border border-slate-300 text-center"><?=$planned?></td>
            <td class="px-2 py-1 border border-slate-300 text-center"><?=$approved?></td>

            <?php foreach ($monthDays as $md):
    $date = $md['date'];
    $type = $monthGrid[$uid][$date] ?? null;

    $mondayBorder = $md['dow'] === 1 ? 'border-l-2 border-l-slate-500' : '';
    $baseWeekend  = (!$type && $md['is_weekend']) ? 'bg-amber-50' : '';

    $cellText  = '';
    $cellClass = '';
    $title     = '';

    if ($type) {
        [$cellText, $cellClass, $title] = absence_cell_meta($type);
    }

    $isWeekend = (bool)$md['is_weekend'];
    $interactiveClass = $isWeekend
        ? 'pointer-events-none cursor-not-allowed opacity-60'
        : 'js-absence-cell cursor-pointer select-none';

    $titleText = $isWeekend
        ? 'Wochenende (gesperrt)'
        : (($title ?: 'Frei') . ' · Klick=Formular · Doppelklick=speichern/löschen');
?>
  <td
    class="px-2 py-1 border border-slate-300 text-center <?=$mondayBorder?> <?=$baseWeekend?> <?=$cellClass?> <?=$interactiveClass?>"
    data-user-id="<?=h((string)$uid)?>"
    data-date="<?=h($date)?>"
    data-type="<?=h((string)($type ?? ''))?>"
    data-weekend="<?=$isWeekend ? '1' : '0'?>"
    title="<?=h($titleText)?>"
  >
    <?=$cellText !== '' ? h($cellText) : ''?>
  </td>
<?php endforeach; ?>

          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

   <p class="mt-2 text-[11px] text-slate-500">
    Hinweis: „genehmigt“ ist aktuell technisch identisch zu „geplant“, solange kein separates Freigabe-Feld vorhanden ist.
  </p>
</section>

  <!-- Eintragen -->
  <section id="absenceFormCard" class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <h2 class="mb-3 text-sm font-semibold text-slate-800">Abwesenheit eintragen (Zeitraum)</h2>

  <form id="absenceForm" method="post" class="grid grid-cols-1 gap-3 md:grid-cols-6">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="year" value="<?=h((string)$year)?>">

    <div class="md:col-span-2">
      <label class="block text-xs text-slate-600 mb-1">Mitarbeiter</label>
      <select id="absenceUserId" name="user_id" required class="w-full rounded-md border-slate-300 text-sm">
        <option value="">– bitte wählen –</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?=h((string)$e['id'])?>"><?=h((string)$e['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs text-slate-600 mb-1">Typ</label>
      <select id="absenceType" name="absence_type" class="w-full rounded-md border-slate-300 text-sm">
        <?php foreach ($types as $k => $lbl): ?>
          <option value="<?=h($k)?>"><?=h($lbl)?> (<?=h($k)?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs text-slate-600 mb-1">Von</label>
      <input id="absenceFrom" type="date" name="date_from" required class="w-full rounded-md border-slate-300 text-sm">
    </div>

    <div>
      <label class="block text-xs text-slate-600 mb-1">Bis</label>
      <input id="absenceTo" type="date" name="date_to" required class="w-full rounded-md border-slate-300 text-sm">
    </div>

    <div>
      <label class="block text-xs text-slate-600 mb-1">Notiz (optional)</label>
      <input id="absenceNote" type="text" name="note" maxlength="255" class="w-full rounded-md border-slate-300 text-sm" placeholder="z. B. Sommerurlaub">
    </div>

    <div class="md:col-span-6">
      <button class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-700">
        Speichern
      </button>
    </div>
  </form>
</section>


  <!-- Löschen -->
  <section class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    <h2 class="mb-3 text-sm font-semibold text-slate-800">Einträge löschen (Zeitraum)</h2>
    <form method="post" class="grid grid-cols-1 gap-3 md:grid-cols-6">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="year" value="<?=h((string)$year)?>">

      <div class="md:col-span-2">
        <label class="block text-xs text-slate-600 mb-1">Mitarbeiter</label>
        <select name="user_id" required class="w-full rounded-md border-slate-300 text-sm">
          <option value="">– bitte wählen –</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?=h((string)$e['id'])?>"><?=h((string)$e['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-xs text-slate-600 mb-1">Typ</label>
        <select name="delete_type" class="w-full rounded-md border-slate-300 text-sm">
          <option value="ALL">Alle Typen</option>
          <?php foreach ($types as $k => $lbl): ?>
            <option value="<?=h($k)?>"><?=h($lbl)?> (<?=h($k)?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-xs text-slate-600 mb-1">Von</label>
        <input type="date" name="date_from" required class="w-full rounded-md border-slate-300 text-sm">
      </div>

      <div>
        <label class="block text-xs text-slate-600 mb-1">Bis</label>
        <input type="date" name="date_to" required class="w-full rounded-md border-slate-300 text-sm">
      </div>

      <div class="flex items-end">
        <button class="rounded-lg bg-rose-600 px-5 py-2 text-sm font-semibold text-white hover:bg-rose-700">
          Löschen
        </button>
      </div>
    </form>
  </section>

  <!-- Jahresübersicht -->
  <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-x-auto">
    <table class="w-full text-xs sm:text-sm">
      <thead class="bg-slate-50">
        <tr class="border-b border-slate-200">
          <th class="px-3 py-2 text-left">Mitarbeiter</th>
          <?php foreach ($months as $m => $lbl): ?>
  <th id="<?=h($monthAnchors[$m])?>" class="px-2 py-2 text-center scroll-mt-24">
    <?=h($lbl)?>
  </th>
<?php endforeach; ?>

          <th class="px-2 py-2 text-center">Genutzt</th>
          <th class="px-2 py-2 text-center">Anspruch</th>
          <th class="px-2 py-2 text-center">Übertrag</th>
          <th class="px-2 py-2 text-center">Rest</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($employees as $e):
          $uid = (int)$e['id'];
          $entitlement = $quota[$uid]['entitlement'] ?? 30.0;
          $carry       = $quota[$uid]['carry'] ?? 0.0;
          $used        = $usedYear[$uid] ?? 0.0;
          $rest        = ($entitlement + $carry) - $used;
      ?>
        <tr class="border-b border-slate-100 hover:bg-slate-50/60">
          <td class="px-3 py-2 whitespace-nowrap font-medium"><?=h((string)$e['name'])?></td>

          <?php for ($m = 1; $m <= 12; $m++):
              $c = $monthCount[$uid][$m] ?? 0;
          ?>
            <td class="px-2 py-2 text-center"><?= $c > 0 ? h((string)$c) : '–' ?></td>
          <?php endfor; ?>

          <td class="px-2 py-2 text-center font-semibold"><?=h(number_format($used, 2, ',', '.'))?></td>
          <td class="px-2 py-2 text-center"><?=h(number_format($entitlement, 2, ',', '.'))?></td>
          <td class="px-2 py-2 text-center"><?=h(number_format($carry, 2, ',', '.'))?></td>
          <td class="px-2 py-2 text-center font-semibold <?= $rest < 0 ? 'text-rose-700' : 'text-emerald-700' ?>">
            <?=h(number_format($rest, 2, ',', '.'))?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash ? window.location.hash.slice(1) : '';
  if (!hash) return;
  const el = document.getElementById(hash);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const formCard = document.getElementById('absenceFormCard');
  const userSel  = document.getElementById('absenceUserId');
  const fromInp  = document.getElementById('absenceFrom');
  const toInp    = document.getElementById('absenceTo');
  const typeSel  = document.getElementById('absenceType');
  const noteInp  = document.getElementById('absenceNote');
  const toastEl  = document.getElementById('toastMsg');

  let clickTimer = null;
  let reloadTimer = null;

  function showToast(message, kind = 'ok') {
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.classList.remove('hidden', 'bg-emerald-600', 'text-white', 'bg-rose-600');
    if (kind === 'error') {
      toastEl.classList.add('bg-rose-600', 'text-white');
    } else {
      toastEl.classList.add('bg-emerald-600', 'text-white');
    }
    setTimeout(() => {
      toastEl.classList.add('hidden');
    }, 1300);
  }

  function prefillFromCell(cell) {
    const uid  = cell.dataset.userId || '';
    const date = cell.dataset.date || '';
    const type = cell.dataset.type || 'U';

    if (!uid || !date) return;
    if (cell.dataset.weekend === '1') return;

    if (userSel) userSel.value = uid;
    if (fromInp) fromInp.value = date;
    if (toInp)   toInp.value = date;
    if (typeSel) typeSel.value = type || 'U';
    if (noteInp && !noteInp.value) noteInp.value = '';

    document.querySelectorAll('.js-absence-cell.ring-2').forEach(el => {
      el.classList.remove('ring-2', 'ring-sky-400');
    });
    cell.classList.add('ring-2', 'ring-sky-400');

    if (formCard) {
      formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function setCellVisual(cell, type) {
    cell.classList.remove(
      'bg-lime-200','text-lime-900',
      'bg-sky-200','text-sky-900',
      'bg-rose-200','text-rose-900',
      'bg-amber-200','text-amber-900',
      'bg-slate-200','text-slate-900'
    );

    if (!type) {
      cell.textContent = '';
      cell.dataset.type = '';
      cell.title = 'Frei · Klick=Formular · Doppelklick=speichern/löschen';
      return;
    }

    if (type === 'U') {
      cell.textContent = '1';
      cell.classList.add('bg-lime-200', 'text-lime-900');
      cell.dataset.type = 'U';
      cell.title = 'Urlaub · Klick=Formular · Doppelklick=speichern/löschen';
      return;
    }

    // Fallback falls andere Typen gesetzt sind
    cell.textContent = type;
    cell.dataset.type = type;
    cell.title = type + ' · Klick=Formular · Doppelklick=speichern/löschen';
  }

  async function quickAction(cell, action) {
  const uid  = cell.dataset.userId || '';
  const date = cell.dataset.date || '';

  if (!uid || !date) {
    return { ok: false, message: 'Ungültige Zelle.' };
  }

  if (cell.dataset.weekend === '1') {
    return { ok: false, message: 'Wochenenden sind gesperrt.' };
  }

  if (cell.dataset.saving === '1') {
    return { ok: false, message: 'Bereits in Bearbeitung.' };
  }
  cell.dataset.saving = '1';

  try {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('user_id', uid);
    body.set('date', date);

    const res = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: body.toString()
    });

    const raw = await res.text();

    let data = null;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      console.error('AJAX Antwort ist kein JSON:', raw);
      return {
        ok: false,
        message: 'Server liefert kein JSON (siehe Konsole).'
      };
    }

    if (!res.ok) {
      return { ok: false, message: data.message || ('HTTP ' + res.status) };
    }

    return data;
  } catch (e) {
    console.error('Fetch Fehler:', e);
    return { ok: false, message: 'Netzwerkfehler.' };
  } finally {
    cell.dataset.saving = '0';
  }
}


  document.querySelectorAll('.js-absence-cell').forEach(cell => {
    cell.addEventListener('click', () => {
      if (clickTimer) clearTimeout(clickTimer);
      clickTimer = setTimeout(() => prefillFromCell(cell), 220);
    });

    cell.addEventListener('dblclick', async (ev) => {
      ev.preventDefault();
      if (clickTimer) clearTimeout(clickTimer);

      const hasType = (cell.dataset.type || '').trim() !== '';
      const action = hasType ? 'quick_delete_day' : 'quick_save_day';

      const result = await quickAction(cell, action);
      if (!result.ok) {
        showToast(result.message || 'Aktion fehlgeschlagen.', 'error');
        return;
      }

      if (action === 'quick_save_day') {
        setCellVisual(cell, 'U');
        showToast('Urlaub gespeichert.');
      } else {
        setCellVisual(cell, '');
        showToast('Eintrag gelöscht.');
      }

      // Damit Summenzeilen + Tooltips sauber nachziehen:
      if (reloadTimer) clearTimeout(reloadTimer);
      reloadTimer = setTimeout(() => window.location.reload(), 650);
    });
  });
});
</script>


<script defer src="/admin/month-overlap-ajax.js"></script>

<div id="toastMsg"
     class="fixed right-4 top-4 z-50 hidden rounded-lg px-4 py-2 text-sm font-semibold shadow-lg">
</div>

</body>
</html>
