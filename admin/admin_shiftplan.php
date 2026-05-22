<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

// Session-Auswertung VOR Guard
$userId = $_SESSION['user_id'] ?? null;
$role   = $_SESSION['role'] ?? '';

// Rollen dynamisch aus Tabs & Rollen laden (Tab-Key = "admin")
function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);

  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
  // Fallback, falls Tabelle leer ist:
  if (!$roles) $roles = ['admin'];

  return $roles;
}

// ================= Auth =================
$AUTH_DEFAULT_TAB   = 'admin';
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;      // nicht im iFrame
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, 'admin');   // <- DB steuert!
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';

// ================= Hilfsfunktionen =================
function get_iso_week_from_param(?string $param): array {
    if ($param && preg_match('/^(\d{4})-W(\d{2})$/', $param, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    $dt = new DateTimeImmutable();
    return [(int)$dt->format('o'), (int)$dt->format('W')];
}

/**
 * Schichtstunden aus von/bis (HH:MM), abzüglich 30 Min Pause
 */
function calc_shift_hours(?string $from, ?string $to): float {
    if (!$from || !$to) {
        return 0.0;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) {
        return 0.0;
    }

    [$fh, $fm] = array_map('intval', explode(':', $from));
    [$th, $tm] = array_map('intval', explode(':', $to));

    $minFrom = $fh * 60 + $fm;
    $minTo   = $th * 60 + $tm;
    $diff    = $minTo - $minFrom;

    // Wenn <= 30 Minuten, bleibt nach Pause nichts übrig
    if ($diff <= 30) {
        return 0.0;
    }

    return max(0.0, ($diff - 30) / 60.0);
}
function absence_meta(string $type): array {
    switch ($type) {
        case 'U':
            return ['U', 'Urlaub', 'bg-emerald-100 text-emerald-800 border-emerald-200'];
        case 'FO':
            return ['FO', 'Fortbildung', 'bg-sky-100 text-sky-800 border-sky-200'];
        case 'K':
            return ['K', 'Krank', 'bg-rose-100 text-rose-800 border-rose-200'];
        case 'GLZ':
            return ['GLZ', 'Gleitzeit', 'bg-amber-100 text-amber-800 border-amber-200'];
        default:
            return ['S', 'Sonstiges', 'bg-slate-100 text-slate-700 border-slate-200'];
    }
}
function month_anchor_from_week_start(DateTimeImmutable $weekMonday): string {
    $map = [
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
    $m = (int)$weekMonday->format('n');
    return $map[$m] ?? 'januar';
}

function week_param(int $year, int $week): string {
    return sprintf('%04d-W%02d', $year, $week);
}


/**
 * Für das Tätigkeiten-Dropdown (selected)
 */
function sel(string $current, string $value): string {
    return $current === $value ? 'selected' : '';
}

// ================= POST: Plan speichern =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $weekYear = (int)($_POST['week_year'] ?? 0);
    $weekNo   = (int)($_POST['week_no']   ?? 0);
    if ($weekYear <= 0 || $weekNo <= 0) {
        [$weekYear, $weekNo] = get_iso_week_from_param($_POST['week'] ?? null);
    }

    $rows   = $_POST['rows'] ?? [];
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($weekYear > 0 && $weekNo > 0 && is_array($rows)) {
        $pdo->beginTransaction();

        // Alten Plan der Woche komplett löschen
        $del = $pdo->prepare("
          DELETE FROM qc_shift_plan
          WHERE week_year = :wy AND week_no = :wn
        ");
        $del->execute([':wy' => $weekYear, ':wn' => $weekNo]);

        $ins = $pdo->prepare("
          INSERT INTO qc_shift_plan
            (week_year, week_no, user_id,
             activity, shift_group, time_from, time_to,
             mon, tue, wed, thu, fri, sat,
             created_by)
          VALUES
            (:wy, :wn, :uid,
             :activity, :grp, :from, :to,
             :mon, :tue, :wed, :thu, :fri, :sat,
             :created_by)
        ");

        foreach ($rows as $uid => $row) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }

            $activity   = trim($row['activity'] ?? '');
            $group      = $row['shift_group'] ?? 'FR';
            if (!in_array($group, ['FR','TS','SP','SO'], true)) {
                $group = 'FR';
            }

            $timeFrom   = trim($row['time_from'] ?? '');
            $timeTo     = trim($row['time_to']   ?? '');
            $timeFrom   = $timeFrom !== '' ? $timeFrom : null;
            $timeTo     = $timeTo   !== '' ? $timeTo   : null;

            $days       = $row['days'] ?? [];
            $mon        = !empty($days['mon']) ? 1 : 0;
            $tue        = !empty($days['tue']) ? 1 : 0;
            $wed        = !empty($days['wed']) ? 1 : 0;
            $thu        = !empty($days['thu']) ? 1 : 0;
            $fri        = !empty($days['fri']) ? 1 : 0;
            $sat        = !empty($days['sat']) ? 1 : 0;

            // komplett leere Zeilen überspringen
            if (
                $activity === '' &&
                !$timeFrom && !$timeTo &&
                !$mon && !$tue && !$wed && !$thu && !$fri && !$sat
            ) {
                continue;
            }

            $ins->execute([
                ':wy'         => $weekYear,
                ':wn'         => $weekNo,
                ':uid'        => $uid,
                ':activity'   => $activity !== '' ? $activity : null,
                ':grp'        => $group,
                ':from'       => $timeFrom,
                ':to'         => $timeTo,
                ':mon'        => $mon,
                ':tue'        => $tue,
                ':wed'        => $wed,
                ':thu'        => $thu,
                ':fri'        => $fri,
                ':sat'        => $sat,
                ':created_by' => $userId ?: null,
            ]);
        }

        $pdo->commit();

        $weekParam = sprintf('%04d-W%02d', $weekYear, $weekNo);
        header('Location: admin_shiftplan.php?week=' . rawurlencode($weekParam) . '&saved=1');
        exit;
    }
}

// ================= GET: Woche bestimmen =================
[$weekYear, $weekNo] = get_iso_week_from_param($_GET['week'] ?? null);

// Montag der ISO-Woche
$dto = new DateTimeImmutable();
$dto = $dto->setISODate($weekYear, $weekNo); // Montag
$days = [];
for ($i = 0; $i < 6; $i++) { // Mo–Sa
    $d = $dto->modify("+{$i} day");
    $days[] = [
        'obj'   => $d,
        'dow'   => $d->format('D'),
        'date'  => $d->format('d.m.Y'),
        'key'   => ['mon','tue','wed','thu','fri','sat'][$i],
        'short' => ['Mo','Di','Mi','Do','Fr','Sa'][$i],
    ];
}
$monthAnchorMap = [
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

// Monat der angezeigten KW (Montag der Woche)
$monthAnchor = $monthAnchorMap[(int)$days[0]['obj']->format('n')] ?? 'januar';
$weekParamForBack = sprintf('%04d-W%02d', $weekYear, $weekNo);

// ================= Abwesenheiten (Mo–Sa der angezeigten Woche) =================
$absencesByUserDay = []; // [user_id][mon|tue|...|sat] = 'U'|'FO'|'K'|'GLZ'|'SONST'

$dayKeyByDate = [];
foreach ($days as $d) {
    $dayKeyByDate[$d['obj']->format('Y-m-d')] = $d['key'];
}

$weekStartDate = $days[0]['obj']->format('Y-m-d');
$weekEndDate   = $days[count($days)-1]['obj']->format('Y-m-d');

try {
    $stAbs = $pdo->prepare("
      SELECT user_id, absence_date, absence_type
      FROM qc_absence_day
      WHERE absence_date BETWEEN :d1 AND :d2
    ");
    $stAbs->execute([
        ':d1' => $weekStartDate,
        ':d2' => $weekEndDate
    ]);

    while ($a = $stAbs->fetch(PDO::FETCH_ASSOC)) {
        $uid  = (int)$a['user_id'];
        $date = substr((string)$a['absence_date'], 0, 10);
        $key  = $dayKeyByDate[$date] ?? null;
        if (!$key) continue;

        $absencesByUserDay[$uid][$key] = (string)$a['absence_type'];
    }
} catch (Throwable $e) {
    // Falls Tabelle noch nicht vorhanden oder andere DB-Probleme:
    // Plan bleibt nutzbar, nur ohne Abwesenheits-Badges.
    $absencesByUserDay = [];
}


// ================= Mitarbeiter laden (nur aktive) =================
$employees = [];
$stmt = $pdo->query("
  SELECT id, COALESCE(display_name, username, CONCAT('ID ', id)) AS name
  FROM users
  WHERE active = 1
  ORDER BY name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================= Bestehende Einträge der Woche =================
$planRows = [];
$stmt2 = $pdo->prepare("
  SELECT *
  FROM qc_shift_plan
  WHERE week_year = :wy AND week_no = :wn
");
$stmt2->execute([':wy' => $weekYear, ':wn' => $weekNo]);
while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $planRows[(int)$r['user_id']] = $r;
}

// ================= Gruppen vorbereiten =================
$groups = [
    'FR' => ['label' => 'Frühschicht',  'rows' => []],
    'TS' => ['label' => 'Tagschicht',   'rows' => []],
    'SP' => ['label' => 'Spätschicht',  'rows' => []],
    'SO' => ['label' => 'Sonstiges',    'rows' => []],
];

foreach ($employees as $emp) {
    $uid = (int)$emp['id'];
    $row = $planRows[$uid] ?? null;
    $grp = $row['shift_group'] ?? 'FR';
    if (!isset($groups[$grp])) {
        $grp = 'FR';
    }
    $groups[$grp]['rows'][] = ['emp' => $emp, 'row' => $row];
}

// ======================================================================
// Wochen-Gesamtsummen (KW) pro Gruppe + Gesamt
// ======================================================================
$groupWeekHours = [
    'FR' => 0.0,
    'TS' => 0.0,
    'SP' => 0.0,
    'SO' => 0.0,
];
$totalWeekHours = 0.0;

foreach ($groups as $gKey => $gData) {
    foreach ($gData['rows'] as $item) {
        $row = $item['row'] ?? null;
        if (!$row) {
            continue;
        }

        // Zeitfelder aus DB auf HH:MM kürzen (DB liefert meistens HH:MM:SS)
        $tf = !empty($row['time_from']) ? substr($row['time_from'], 0, 5) : null;
        $tt = !empty($row['time_to'])   ? substr($row['time_to'],   0, 5) : null;

        $baseHours = calc_shift_hours($tf, $tt);
        if ($baseHours <= 0) {
            continue;
        }

        $uid = (int)($item['emp']['id'] ?? 0);
$abs = $absencesByUserDay[$uid] ?? [];

$daysCount = 0;
if (!empty($row['mon']) && empty($abs['mon'])) $daysCount++;
if (!empty($row['tue']) && empty($abs['tue'])) $daysCount++;
if (!empty($row['wed']) && empty($abs['wed'])) $daysCount++;
if (!empty($row['thu']) && empty($abs['thu'])) $daysCount++;
if (!empty($row['fri']) && empty($abs['fri'])) $daysCount++;
if (!empty($row['sat']) && empty($abs['sat'])) $daysCount++;


        if ($daysCount <= 0) {
            continue;
        }

        $weekHours = $baseHours * $daysCount;

        if (!isset($groupWeekHours[$gKey])) {
            $groupWeekHours[$gKey] = 0.0;
        }

        $groupWeekHours[$gKey] += $weekHours;
        $totalWeekHours        += $weekHours;
    }
}


// ======================================================================
// Stunden "heute verplant" berechnen (nur wenn angezeigte KW = aktuelle KW)
// ======================================================================
$totalHoursToday = 0.0;
$todayKey        = null;
$todayLabel      = null;
$todayDateStr    = null;

$now = new DateTimeImmutable('now');

if ((int)$now->format('o') === $weekYear && (int)$now->format('W') === $weekNo) {
    // 1 = Mo ... 7 = So
    $dowIndex = (int)$now->format('N') - 1; // 0 = Mo

    // Wir haben nur Mo–Sa im Plan
    if ($dowIndex >= 0 && $dowIndex < 6) {
        $dayKeys   = ['mon','tue','wed','thu','fri','sat'];
        $dayLabels = ['Mo','Di','Mi','Do','Fr','Sa'];

        $todayKey   = $dayKeys[$dowIndex];
        $todayLabel = $dayLabels[$dowIndex];

        foreach ($days as $d) {
            if ($d['key'] === $todayKey) {
                $todayDateStr = $d['date'];
                break;
            }
        }

        foreach ($groups as $grpData) {
  foreach ($grpData['rows'] as $item) {
    $row = $item['row'] ?? null;
    if (!$row) continue;

    $uid = (int)($item['emp']['id'] ?? 0);
if (!empty($absencesByUserDay[$uid][$todayKey])) continue; // heute abwesend => keine Planstunden

if (empty($row[$todayKey])) continue;

$tf = !empty($row['time_from']) ? substr($row['time_from'], 0, 5) : null;
$tt = !empty($row['time_to'])   ? substr($row['time_to'],   0, 5) : null;

$totalHoursToday += calc_shift_hours($tf, $tt);

  }
}
    }
}

$weekValue = sprintf('%04d-W%02d', $weekYear, $weekNo);
$saved = !empty($_GET['saved']);
?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>Admin – Mitarbeiter-Einsatzplan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Tailwind -->
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

  <style>
    /* Druck: Seitengröße & Ränder */
    @page {
      size: A4 landscape;
      margin: 10mm 8mm;
    }

    @media print {
      * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      body {
        background: #fff;
        font-size: 10px;
      }

      /* Dinge, die nur am Bildschirm sichtbar sind */
      .no-print {
        display: none !important;
      }

      .page-container {
        max-width: 100%;
        margin: 0;
        padding: 0 4mm;
      }

      .shiftplan-header {
        margin-bottom: 3mm;
        padding-top: 0;
        padding-bottom: 2mm;
      }

      .shiftplan-title {
        font-size: 14px;
        line-height: 1.2;
      }

      .shiftplan-sub {
        font-size: 9px;
      }

      table {
        font-size: 9px;
      }

      th,
      td {
        padding: 2px 3px !important;
      }

      /* Schicht- und Stunden-Spalte im Druck ausblenden */
      .col-shift,
      .col-hours {
        display: none !important;
      }

      /* Abstände zwischen Früh / Tag / Spät im Druck sehr klein */
      form.space-y-8 > * + * {
        margin-top: 2mm !important;
      }

      .shift-section {
        margin-bottom: 2mm !important;
        box-shadow: none !important;
      }

      .shift-section-header {
        padding-top: 1mm;
        padding-bottom: 1mm;
      }
    }
  </style>
</head>
<body class="min-h-full bg-slate-100 text-slate-900">
<div class="w-full px-3 sm:px-6 lg:px-10 py-6 page-container">

  <!-- Header -->
  <header class="shiftplan-header mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
      <img src="/Bilder/logo_standard_tpo.svg" alt="TPO" class="h-10">
      <div>
        <h1 class="shiftplan-title text-2xl font-semibold text-slate-900">
          Mitarbeiter-Einsatzplan (KW <?=htmlspecialchars((string)$weekNo)?> / <?=$weekYear?>)
        </h1>
        <p class="shiftplan-sub text-sm text-slate-600">
          Übersicht Früh-, Tag- und Spätschicht pro Woche.
        </p>
      </div>
    </div>

    <div class="flex items-center gap-2 no-print">
  <form method="get" class="flex items-center gap-2">
    <label class="text-sm text-slate-700">Woche:</label>
    <input type="week"
           name="week"
           value="<?=htmlspecialchars($weekValue)?>"
           class="rounded-md border-slate-300 text-sm">
    <button type="submit"
            class="inline-flex items-center rounded-md bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-900">
      Anzeigen
    </button>
  </form>

  <a href="admin_urlaubsplan.php?year=<?=$weekYear?>&back_week=<?=rawurlencode($weekParamForBack)?>#<?=htmlspecialchars($monthAnchor)?>"
   class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700">
  Zum Urlaubsplan
</a>


  <button type="button"
          onclick="window.print()"
          class="inline-flex items-center rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-dark">
    Drucken
  </button>
</div>

  </header>

  <!-- Wochen-Gesamtsumme (immer, wenn >0) -->
  <?php if ($totalWeekHours > 0): ?>
    <div class="mb-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 flex flex-wrap items-center justify-between gap-2">
      <div class="font-semibold">
        Gesamtstunden in dieser Woche (KW <?=htmlspecialchars((string)$weekNo)?> / <?=$weekYear?>):
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span class="text-sm font-semibold">
          <?=htmlspecialchars(number_format($totalWeekHours, 2, ',', '.'))?> Std
        </span>
        <span class="text-[11px] text-slate-600">
          <?php
            $parts = [];
            foreach (['FR'=>'Frühschicht','TS'=>'Tagschicht','SP'=>'Spätschicht','SO'=>'Sonstiges'] as $k=>$lbl) {
                $val = $groupWeekHours[$k] ?? 0.0;
                if ($val > 0) {
                    $parts[] = $lbl . ': ' . number_format($val, 2, ',', '.') . ' Std';
                }
            }
            echo htmlspecialchars(implode(' · ', $parts));
          ?>
        </span>
      </div>
    </div>
  <?php endif; ?>

  <!-- Stunden heute verplant (nur wenn aktuelle KW) -->
  <?php if ($todayKey !== null): ?>
    <div class="no-print mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 flex items-center justify-between">
      <div>
        <span class="font-semibold">Stunden heute verplant:</span>
        <?php if ($todayLabel && $todayDateStr): ?>
          <span class="text-xs text-amber-800">
            (<?=$todayLabel?>, <?=$todayDateStr?>)
          </span>
        <?php endif; ?>
      </div>
      <div class="text-base font-bold">
        <?=htmlspecialchars(number_format($totalHoursToday, 2, ',', '.'))?> Std
      </div>
    </div>
  <?php endif; ?>

  <?php if ($saved): ?>
    <div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 no-print">
      Schichtplan gespeichert.
    </div>
  <?php endif; ?>

  <!-- Formular für Plan -->
  <form method="post" class="space-y-8">
    <input type="hidden" name="week_year" value="<?=htmlspecialchars((string)$weekYear)?>">
    <input type="hidden" name="week_no"   value="<?=htmlspecialchars((string)$weekNo)?>">

    <?php foreach ($groups as $grpKey => $grpData): ?>

      <?php
      // Sonstiges komplett ausblenden, wenn leer
      if ($grpKey === 'SO' && empty($grpData['rows'])) {
          continue;
      }
      $grpHours = $groupWeekHours[$grpKey] ?? 0.0;
      ?>

     <section class="shift-section rounded-xl border border-slate-200 bg-white shadow-sm">
  <div class="shift-section-header border-b border-slate-200 px-4 py-2.5 flex items-center justify-between">
    <h2 class="text-sm font-semibold text-slate-800">
      <?=$grpData['label']?>
    </h2>
    <span class="text-xs text-slate-500">
      Gruppe: <?=$grpKey?>
      <?php if ($grpHours > 0): ?>
        · Summe KW: <?=htmlspecialchars(number_format($grpHours, 2, ',', '.'))?> Std
      <?php endif; ?>
    </span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full border-t border-slate-200 text-xs sm:text-sm">
      <thead class="bg-slate-50">
        <tr class="border-b border-slate-200">
          <th class="px-3 py-2 text-left font-medium text-slate-700 whitespace-nowrap">Name</th>
          <th class="px-3 py-2 text-left font-medium text-slate-700 whitespace-nowrap">Tätigkeit</th>
          <th class="px-2 py-2 text-center font-medium text-slate-700 col-shift whitespace-nowrap">Schicht</th>
          <th class="px-2 py-2 text-center font-medium text-slate-700 whitespace-nowrap">von</th>
          <th class="px-2 py-2 text-center font-medium text-slate-700 whitespace-nowrap">bis</th>
          <th class="px-2 py-2 text-center font-medium text-slate-700 col-hours whitespace-nowrap">
            Std (–0,5 Pause)
          </th>

          <?php foreach ($days as $d): ?>
            <th class="px-2 py-2 text-center font-medium text-slate-700">
              <div><?=$d['short']?></div>
              <div class="text-[10px] text-slate-500"><?=$d['date']?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>

      <tbody>
      <?php if (empty($grpData['rows'])): ?>
        <tr>
          <td colspan="<?=6 + count($days)?>" class="px-3 py-3 text-center text-slate-400 text-xs">
            (Noch keine Mitarbeiter in dieser Gruppe)
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($grpData['rows'] as $item):
            $emp = $item['emp'];
            $uid = (int)$emp['id'];
            $row = $item['row'] ?? [];

            $activity  = $row['activity']    ?? '';
            $shiftG    = $row['shift_group'] ?? $grpKey;
            $timeFrom  = !empty($row['time_from']) ? substr($row['time_from'], 0, 5) : '';
            $timeTo    = !empty($row['time_to'])   ? substr($row['time_to'], 0, 5)   : '';

            $dayFlags = [
              'mon' => !empty($row['mon']),
              'tue' => !empty($row['tue']),
              'wed' => !empty($row['wed']),
              'thu' => !empty($row['thu']),
              'fri' => !empty($row['fri']),
              'sat' => !empty($row['sat']),
            ];
           
        ?>
          <tr class="border-b border-slate-100 hover:bg-slate-50/60" data-shift-row="<?=$uid?>">
            <td class="px-3 py-1.5 whitespace-nowrap">
              <?=htmlspecialchars($emp['name'])?>
            </td>

            <td class="px-3 py-1.5">
              <select name="rows[<?=$uid?>][activity]"
                      class="w-full rounded-md border-slate-300 bg-white px-2 py-1 text-xs sm:text-sm">
                <option value="">– bitte wählen –</option>
                <option value="Lagerleitung/Dispo" <?=sel($activity, 'Lagerleitung/Dispo')?>>Lagerleitung/Dispo</option>
                <option value="Dispo" <?=sel($activity, 'Dispo')?>>Dispo</option>
                <option value="Stapler - Bereitstellung + Einlagerung" <?=sel($activity, 'Stapler - Bereitstellung + Einlagerung')?>>Stapler - Bereitstellung + Einlagerung</option>
                <option value="Stapler - Be-/Entladung + Bereitstellung" <?=sel($activity, 'Stapler - Be-/Entladung + Bereitstellung')?>>Stapler - Be-/Entladung + Bereitstellung</option>
                <option value="Verpackung/Entsorgung" <?=sel($activity, 'Verpackung/Entsorgung')?>>Verpackung/Entsorgung</option>
                <option value="Bestandskontrolle - Inventur - Koordination Verpackung" <?=sel($activity, 'Bestandskontrolle - Inventur - Koordination Verpackung')?>>Bestandskontrolle - Inventur - Koordination Verpackung</option>
              </select>
            </td>

            <td class="px-2 py-1.5 text-center col-shift">
              <select name="rows[<?=$uid?>][shift_group]"
                      class="rounded-md border-slate-300 bg-white py-1 text-xs">
                <option value="FR" <?=$shiftG==='FR'?'selected':''?>>FR</option>
                <option value="TS" <?=$shiftG==='TS'?'selected':''?>>TS</option>
                <option value="SP" <?=$shiftG==='SP'?'selected':''?>>SP</option>
                <option value="SO" <?=$shiftG==='SO'?'selected':''?>>SO</option>
              </select>
            </td>

            <td class="px-2 py-1.5 text-center">
              <input type="time"
                     name="rows[<?=$uid?>][time_from]"
                     value="<?=htmlspecialchars($timeFrom)?>"
                     class="w-20 rounded-md border-slate-300 bg-white px-1 py-1 text-xs time-from">
            </td>

            <td class="px-2 py-1.5 text-center">
              <input type="time"
                     name="rows[<?=$uid?>][time_to]"
                     value="<?=htmlspecialchars($timeTo)?>"
                     class="w-20 rounded-md border-slate-300 bg-white px-1 py-1 text-xs time-to">
            </td>

            <td class="px-2 py-1.5 text-center col-hours">
              <input type="text"
                     name="rows[<?=$uid?>][hours_display]"
                     class="w-16 rounded-md border-slate-200 bg-slate-50 px-1 py-1 text-xs text-center hours-field"
                     readonly>
            </td>

            <?php foreach ($days as $d):
                $key       = $d['key'];
                $absType   = $absencesByUserDay[$uid][$key] ?? null;
                $checked   = (!$absType && !empty($dayFlags[$key]));
                $badgeCode = '';
                $badgeTitle = '';
                $badgeClass = '';

                if ($absType) {
                  [$badgeCode, $badgeTitle, $badgeClass] = absence_meta($absType);
                }
            ?>
              <td class="px-2 py-1.5 text-center align-middle">
                <?php if ($absType): ?>
                  <div class="mb-1">
                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold <?=$badgeClass?>"
                          title="<?=htmlspecialchars($badgeTitle)?>">
                      <?=htmlspecialchars($badgeCode)?>
                    </span>
                  </div>
                <?php endif; ?>

                <input type="checkbox"
                       name="rows[<?=$uid?>][days][<?=$key?>]"
                       value="1"
                       <?=$checked ? 'checked' : ''?>
                       <?=$absType ? 'disabled' : ''?>
                       class="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand <?=$absType ? 'opacity-40 cursor-not-allowed' : ''?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

    <?php endforeach; ?>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between no-print">
      <div class="text-[11px] sm:text-xs text-slate-500 space-y-0.5">
  <div><span class="font-semibold">Legende:</span> FR = Frühschicht · TS = Tagschicht · SP = Spätschicht · SO = Sonstiges</div>
  <div>
    Abwesenheit:
    <span class="inline-flex rounded border border-emerald-200 bg-emerald-100 px-1 text-[10px] font-semibold text-emerald-800">U</span> Urlaub
    <span class="inline-flex rounded border border-sky-200 bg-sky-100 px-1 text-[10px] font-semibold text-sky-800">FO</span> Fortbildung
    <span class="inline-flex rounded border border-rose-200 bg-rose-100 px-1 text-[10px] font-semibold text-rose-800">K</span> Krank
    <span class="inline-flex rounded border border-amber-200 bg-amber-100 px-1 text-[10px] font-semibold text-amber-800">GLZ</span> Gleitzeit
  </div>
</div>

      <button type="submit"
              class="inline-flex items-center rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-dark">
        Plan speichern
      </button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('tr[data-shift-row]').forEach(tr => {
    const fromInput  = tr.querySelector('input.time-from');
    const toInput    = tr.querySelector('input.time-to');
    const hoursField = tr.querySelector('input.hours-field');

    if (!fromInput || !toInput || !hoursField) return;

    const updateHours = () => {
      const start = fromInput.value;
      const end   = toInput.value;

      if (!start || !end) {
        hoursField.value = '';
        return;
      }

      const [sh, sm] = start.split(':').map(Number);
      const [eh, em] = end.split(':').map(Number);

      const startMin = sh * 60 + sm;
      const endMin   = eh * 60 + em;
      let diff       = endMin - startMin;

      if (diff <= 0) {
        hoursField.value = '';
        return;
      }

      // 30 Minuten Pause abziehen
      diff -= 30;
      if (diff < 0) diff = 0;

      const hours = diff / 60;
      hoursField.value = hours.toFixed(2).replace('.', ',');
    };

    fromInput.addEventListener('change', updateHours);
    toInput.addEventListener('change', updateHours);
    fromInput.addEventListener('input', updateHours);
    toInput.addEventListener('input', updateHours);

    // Initial berechnen (für bereits gespeicherte Zeiten)
    updateHours();
  });
});
</script>

</body>
</html>
