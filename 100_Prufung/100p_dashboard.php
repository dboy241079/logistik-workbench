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

// Letzte bekannte Prüfung (für Live-Benachrichtigung)
$lastCreatedAtStmt = $pdo->query("SELECT MAX(created_at) AS max_ts FROM qc_100_pruefungen");
$lastCreatedAt     = $lastCreatedAtStmt->fetchColumn() ?: null;


$von    = $_GET['von']    ?? date('Y-m-01');
$bis    = $_GET['bis']    ?? date('Y-m-d');
$hall   = $_GET['hall']   ?? '';
$reason = $_GET['reason'] ?? '';
$search = trim($_GET['search'] ?? '');
// Querystring für Abrechnung mit aktuellen Filtern
$qsAbrechnungFilters = http_build_query([
    'hall'   => $hall,
    'reason' => $reason,
    'search' => $search,
]);


// Schnellwahl-Zeiträume: aktueller und letzter Monat
$today = new DateTimeImmutable('today');

// aktueller Monat (von 1. bis heute)
$currentMonthStart = $today->modify('first day of this month')->format('Y-m-d');
$currentMonthEnd   = $today->format('Y-m-d');

// letzter Monat (kompletter Monat)
$lastMonthStart = $today->modify('first day of last month')->format('Y-m-d');
$lastMonthEnd   = $today->modify('last day of last month')->format('Y-m-d');

// Basis-URL für Links (dieses Script)
$selfUrl = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);

// Querystrings bauen und aktuelle Filter (Halle / Grund) mitnehmen
$qsCurrentMonth = [
    'von' => $currentMonthStart,
    'bis' => $currentMonthEnd,
];
$qsLastMonth = [
    'von' => $lastMonthStart,
    'bis' => $lastMonthEnd,
];

if ($hall !== '') {
    $qsCurrentMonth['hall'] = $hall;
    $qsLastMonth['hall']    = $hall;
}
if ($reason !== '') {
    $qsCurrentMonth['reason'] = $reason;
    $qsLastMonth['reason']    = $reason;
}

$qsCurrentMonthStr = http_build_query($qsCurrentMonth);
$qsLastMonthStr    = http_build_query($qsLastMonth);


$allowedHalls = ['W1','X3','Banking','G9'];
$allowedReasons = [
  '100% Prüfung',
  'Etikettierung KLT',
  'Umpacken auf Palette',
  'Umfüllung in KLT'
];
// === Haupt-Query (Zeitraum-Ansicht) =======================================
$sql = "
  SELECT 
    q.*,
    COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter,
    CASE 
      WHEN u.profile_image IS NOT NULL AND u.profile_image <> ''
      THEN CONCAT('/uploads/avatars/', u.profile_image)
      ELSE NULL
    END AS mitarbeiter_avatar
  FROM qc_100_pruefungen q
  LEFT JOIN users u ON q.employee_id = u.id
  WHERE DATE(q.created_at) BETWEEN :von AND :bis
";

$params = [
  ':von' => $von,
  ':bis' => $bis,
];

if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
  $sql .= " AND q.hall = :hall";
  $params[':hall'] = $hall;
}

if ($reason !== '' && in_array($reason, $allowedReasons, true)) {
  $sql .= " AND q.reason = :reason";
  $params[':reason'] = $reason;
}
// Freitext-Suche über Palette / Lieferschein / Sachnummer
if ($search !== '') {
  $sql .= " AND (
    q.pallet_code   LIKE :search
    OR q.delivery_note LIKE :search
    OR q.material_no   LIKE :search
  )";
  $params[':search'] = '%' . $search . '%';
}


$sql .= " ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Nach Tagen gruppieren (YYYY-MM-DD)
$byDay = [];
foreach ($rows as $r) {
    $dayKey = (new DateTimeImmutable($r['created_at']))->format('Y-m-d');
    $byDay[$dayKey][] = $r;
}

// Wochentagsnamen (0=Sonntag)
$weekdayNames = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];

// === Statistiken im gefilterten Zeitraum ================================
$total            = count($rows);
$countsByReason   = [];
$abweichungen     = 0;
$durationByReason = [];
$durationTotalAll = 0;
$perDay           = [];

foreach ($rows as $r) {
  $key = $r['reason'] ?: '(kein Grund)';
  $countsByReason[$key] = ($countsByReason[$key] ?? 0) + 1;

  $dur = isset($r['duration_min']) && $r['duration_min'] !== null ? (int)$r['duration_min'] : 0;
  if (!isset($durationByReason[$key])) {
    $durationByReason[$key] = ['sum' => 0, 'count' => 0];
  }
  if ($dur > 0) {
    $durationByReason[$key]['sum']   += $dur;
    $durationByReason[$key]['count'] += 1;
    $durationTotalAll                += $dur;
  }

  if ($r['result'] === 'ABWEICHUNG') {
    $abweichungen++;
  }

  // Tages-Stats im Zeitraum
  $dateKey = date('Y-m-d', strtotime($r['created_at']));
  if (!isset($perDay[$dateKey])) {
    $perDay[$dateKey] = [
      'date'         => $dateKey,
      'total'        => 0,
      'abweichungen' => 0,
      'duration_sum' => 0,
    ];
  }
  $perDay[$dateKey]['total']++;
  if ($r['result'] === 'ABWEICHUNG') {
    $perDay[$dateKey]['abweichungen']++;
  }
  if ($dur > 0) {
    $perDay[$dateKey]['duration_sum'] += $dur;
  }
}
ksort($perDay);

// === Heutige Stats pro Grund (nur Anzahl) ================================
$heute = date('Y-m-d');
$stmtToday = $pdo->prepare("
  SELECT reason, COUNT(*) AS c
  FROM qc_100_pruefungen
  WHERE DATE(created_at) = :heute
  GROUP BY reason
");
$stmtToday->execute([':heute' => $heute]);
$todayCounts = [];
while ($row = $stmtToday->fetch()) {
  $todayCounts[$row['reason'] ?: '(kein Grund)'] = (int)$row['c'];
}
$DAY_START_HOUR = 7;
$DAY_END_HOUR   = 20; // exklusiv: letzter Slot 19–20

$hourSlots = range($DAY_START_HOUR, $DAY_END_HOUR - 1);
// === Zeit-Matrix: heute, stundenweise nach Mitarbeiter ==================
$timeMatrix    = [];           // [employee_id][hour] => Minuten
$timeEmpOrder  = [];           // Reihenfolge der Mitarbeiter
$timeEmpNames  = [];           // employee_id => Name
$timeDetails   = [];           // [employee_id][hour] => ['HH:MM–HH:MM', ...]

foreach ($rows as $r) {
    // Nur heutige Datensätze berücksichtigen
    $rowDate = substr($r['created_at'], 0, 10);
    if ($rowDate !== $heute) {
        continue;
    }

    if (empty($r['time_start']) || empty($r['time_end']) || empty($r['employee_id'])) {
        continue;
    }

    $empId = (int)$r['employee_id'];
    $name  = $r['mitarbeiter'] ?? ('ID ' . $empId);

    $timeEmpNames[$empId] = $name;
    if (!in_array($empId, $timeEmpOrder, true)) {
        $timeEmpOrder[] = $empId;
    }

    // Start / Ende in Minuten seit 00:00 umrechnen
    [$sh, $sm] = array_map('intval', explode(':', substr($r['time_start'], 0, 5)));
    [$eh, $em] = array_map('intval', explode(':', substr($r['time_end'], 0, 5)));

    $startMin = $sh * 60 + $sm;
    $endMin   = $eh * 60 + $em;

    if ($endMin <= $startMin) {
        continue;
    }

        // Auf Stunden-Slots verteilen (z. B. 10:00–10:10 + 10:20–10:35 => 35 min im Slot 10–11)
    foreach ($hourSlots as $hour) {
        $slotStart = $hour * 60;       // z. B. 10 * 60 = 600 (10:00)
        $slotEnd   = ($hour + 1) * 60; // z. B. 11 * 60 = 660 (11:00)

        $overlapStart = max($startMin, $slotStart);
        $overlapEnd   = min($endMin,   $slotEnd);

        if ($overlapEnd > $overlapStart) {
            $mins = $overlapEnd - $overlapStart;

            // Minuten aufsummieren
            if (!isset($timeMatrix[$empId][$hour])) {
                $timeMatrix[$empId][$hour] = 0;
            }
            $timeMatrix[$empId][$hour] += $mins;

            // Tooltip-Details vorbereiten (z. B. "10:00–10:10")
            $ovStartH = intdiv($overlapStart, 60);
            $ovStartM = $overlapStart % 60;
            $ovEndH   = intdiv($overlapEnd,   60);
            $ovEndM   = $overlapEnd % 60;

            $label = sprintf('%02d:%02d–%02d:%02d', $ovStartH, $ovStartM, $ovEndH, $ovEndM);

            if (!isset($timeDetails[$empId][$hour])) {
                $timeDetails[$empId][$hour] = [];
            }
            $timeDetails[$empId][$hour][] = $label;
        }
    }

}
// === Summen je Mitarbeiter: heute (aus der Matrix) =======================
$timeTotalsToday = [];
foreach ($timeEmpOrder as $eid) {
    $sum = 0;
    foreach ($hourSlots as $h) {
        $sum += $timeMatrix[$eid][$h] ?? 0;
    }
    $timeTotalsToday[$eid] = $sum; // Minuten heute
}

// === Summen je Mitarbeiter: Woche (Mo–Fr) ================================
$timeWeekTotals = [];

if (!empty($timeEmpOrder)) {
    // Montag–Freitag dieser Woche
    $todayTs    = strtotime($heute);
    $weekStartW = date('Y-m-d', strtotime('monday this week',  $todayTs));
    $weekEndW   = date('Y-m-d', strtotime('friday this week',  $todayTs));

    // Nur die Mitarbeiter, die heute in der Matrix auftauchen
    $idsIn = implode(',', array_map('intval', $timeEmpOrder));

    if ($idsIn !== '') {
        $sqlWeek = "
          SELECT employee_id, SUM(COALESCE(duration_min,0)) AS sum_min
          FROM qc_100_pruefungen
          WHERE DATE(created_at) BETWEEN :ws AND :we
        ";

        $paramsWeek = [
          ':ws' => $weekStartW,
          ':we' => $weekEndW,
        ];

        // gleiche Filter wie oben
        if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
          $sqlWeek .= " AND hall = :hall";
          $paramsWeek[':hall'] = $hall;
        }

        if ($reason !== '' && in_array($reason, $allowedReasons, true)) {
          $sqlWeek .= " AND reason = :reason";
          $paramsWeek[':reason'] = $reason;
        }

        if ($search !== '') {
          $sqlWeek .= " AND (
            pallet_code   LIKE :search_week
            OR delivery_note LIKE :search_week
            OR material_no   LIKE :search_week
          )";
          $paramsWeek[':search_week'] = '%' . $search . '%';
        }

        // nur die relevanten Mitarbeiter
        $sqlWeek .= " AND employee_id IN ($idsIn)
                      GROUP BY employee_id";

        try {
            $stmtWeek = $pdo->prepare($sqlWeek);
            $stmtWeek->execute($paramsWeek);

            while ($row = $stmtWeek->fetch()) {
                $eid = (int)$row['employee_id'];
                $timeWeekTotals[$eid] = (int)$row['sum_min']; // Minuten in der Woche
            }
        } catch (Throwable $e) {
            $timeWeekTotals = [];
        }
    }
}


// === Tages-Verpackaufgaben (heute) aus qc_100_pack_needs ===================
$todayNeedsSummary = '';

try {
    $stmtNeeds = $pdo->prepare("
      SELECT hall,
             COUNT(*)        AS pos_count,
             SUM(klt_target) AS klt_sum
      FROM qc_100_pack_needs
      WHERE need_date = :d
      GROUP BY hall
    ");
    $stmtNeeds->execute([':d' => $heute]);
    $needsRows = $stmtNeeds->fetchAll();

    if ($needsRows) {
        $totalPos = 0;
        $totalKlt = 0;
        $halls    = [];

        foreach ($needsRows as $n) {
            $totalPos += (int)$n['pos_count'];
            $totalKlt += (int)$n['klt_sum'];
            if (!empty($n['hall'])) {
                $halls[] = $n['hall'];
            }
        }

        $halls = array_values(array_unique($halls));

        // Hallen-Label aufbauen
        if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
            $hallLabel = ' · Halle ' . $hall;
        } elseif (count($halls) === 1) {
            $hallLabel = ' · Halle ' . $halls[0];
        } elseif (count($halls) > 1) {
            $hallLabel = ' · mehrere Hallen';
        } else {
            $hallLabel = '';
        }

        $todayNeedsSummary = sprintf(
    'Heute geplant: %d Positionen · %d Paletten%s',
    $totalPos,
    $totalKlt,
    $hallLabel
);

    }
} catch (Throwable $e) {
    $todayNeedsSummary = '';
}
// === Offene Paletten (Umpacken erledigt, Etikettierung oder 100% fehlen) ===
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

    // keine :von / :bis mehr, offene Paletten immer über alle Daten
    $paramsOpen = [];

    // Halle-Filter (falls gesetzt) weiter anwenden
    if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
      $sqlOpen .= " AND hall = :hall";
      $paramsOpen[':hall'] = $hall;
    }

    // Suche (Palette / LS / Sachnummer) auch auf offene Paletten anwenden
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
  HAVING 
    (
      has_ump = 1
      AND (has_klt = 0 OR has_100 = 0)
    )
    OR (
      has_ump = 0
      AND has_klt = 1
      AND has_100 = 0
    )
  ORDER BY last_at DESC
  LIMIT 100
";


    $stmtOpen = $pdo->prepare($sqlOpen);
    $stmtOpen->execute($paramsOpen);
    $openPalletsDash = $stmtOpen->fetchAll();
} catch (Throwable $e) {
    $openPalletsDash = [];
}
// === Tages-Aufgaben Verpackung (heute) aus qc_100_pack_needs =============
$todayDate = $heute; // 'Y-m-d'

$packTasksToday = [];
try {
    $stmt = $pdo->prepare("
      SELECT 
        n.*,
        COUNT(q.id) AS klt_done          -- ⬅️ statt SUM(klt_count)
      FROM qc_100_pack_needs n
      LEFT JOIN qc_100_pruefungen q
        ON q.material_no = n.material_no
       AND q.reason      = n.reason
       AND DATE(q.created_at) = n.need_date
      WHERE n.need_date = :d
      GROUP BY 
        n.id,
        n.need_date,
        n.hall,
        n.material_no,
        n.reason,
        n.klt_target,
        n.comment,
        n.created_at,
        n.created_by
      ORDER BY n.hall, n.material_no
    ");
    $stmt->execute([':d' => $todayDate]);
    $packTasksToday = $stmt->fetchAll();
} catch (Throwable $e) {
    $packTasksToday = [];
}


// Nur Aufgaben anzeigen, bei denen noch etwas offen ist (Rest > 0)
if (!empty($packTasksToday)) {
    $packTasksToday = array_values(array_filter(
        $packTasksToday,
        function (array $t): bool {
            $target = (int)($t['klt_target'] ?? 0);
            $done   = (int)($t['klt_done']   ?? 0);

            return $target > 0 && $done < $target;
        }
    ));
}

// === Wochentage-Helfer ====================================================
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
$heuteLabel = weekday_de($heute) . ', ' . date('d.m.Y');

if (!function_exists('fmt_minutes_de')) {
  function fmt_minutes_de(int $min): string {
    if ($min <= 0) return '0 min';
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h > 0) {
      return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
    }
    return $m . ' min';
  }
}


// === Helper: Top-Mitarbeiter für beliebigen Zeitraum =====================
function buildEmployeeStatsForRange(
  PDO $pdo,
  string $from,
  string $to,
  string $hall,
  string $reason,
  array $allowedHalls,
  array $allowedReasons,
  string $search = ''
): array {
  try {
    $sql = "
      SELECT 
        COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS name,
        CASE 
          WHEN u.profile_image IS NOT NULL AND u.profile_image <> ''
          THEN CONCAT('/uploads/avatars/', u.profile_image)
          ELSE NULL
        END AS avatar,
        SUM(COALESCE(q.duration_min,0))                                    AS sum_min,
        COUNT(*)                                                           AS cnt,
        SUM(CASE WHEN q.duration_min IS NOT NULL THEN 1 ELSE 0 END)       AS cnt_dur
      FROM qc_100_pruefungen q
      LEFT JOIN users u ON q.employee_id = u.id
      WHERE DATE(q.created_at) BETWEEN :von AND :bis
    ";


    $params = [
      ':von' => $from,
      ':bis' => $to,
    ];

    if ($hall !== '' && in_array($hall, $allowedHalls, true)) {
      $sql .= " AND q.hall = :hall";
      $params[':hall'] = $hall;
    }
        if ($search !== '') {
      $sql .= " AND (
        q.pallet_code   LIKE :search_emp
        OR q.delivery_note LIKE :search_emp
        OR q.material_no   LIKE :search_emp
      )";
      $params[':search_emp'] = '%' . $search . '%';
    }


    if ($reason !== '' && in_array($reason, $allowedReasons, true)) {
      $sql .= " AND q.reason = :reason";
      $params[':reason'] = $reason;
    }

    $sql .= "
      GROUP BY name, avatar
      HAVING sum_min > 0
      ORDER BY sum_min DESC
    ";


    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($row = $stmt->fetch()) {
      $sum    = (int)$row['sum_min'];
      $cnt    = (int)$row['cnt'];
      $cntDur = (int)$row['cnt_dur'];
      $avg    = ($cntDur > 0 && $sum > 0) ? (int)round($sum / $cntDur) : 0;
      $out[] = [
  'name'  => $row['name'],
  'sum'   => $sum,
  'count' => $cnt,
  'avg'   => $avg,
  'avatar'=> $row['avatar'] ?? null,
];


    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

// === Top-Mitarbeiter: heute / Woche / Monat ==============================
$todayFrom = $heute;
$todayTo   = $heute;

$todayEmpStats = buildEmployeeStatsForRange(
  $pdo, $todayFrom, $todayTo, $hall, $reason, $allowedHalls, $allowedReasons, $search
);

// aktuelle Woche (Mo–So)
$todayTs   = strtotime($heute);
$weekStart = date('Y-m-d', strtotime('monday this week', $todayTs));
$weekEnd   = date('Y-m-d', strtotime('sunday this week', $todayTs));
$weekLabel = date('d.m.', strtotime($weekStart)) . '–' . date('d.m.Y', strtotime($weekEnd));

$weekEmpStats = buildEmployeeStatsForRange(
  $pdo, $weekStart, $weekEnd, $hall, $reason, $allowedHalls, $allowedReasons, $search
);

// aktueller Monat
$monthStart = date('Y-m-01', $todayTs);
$monthEnd   = date('Y-m-t', $todayTs);
$monthLabel = date('m.Y', $todayTs);

$monthEmpStats = buildEmployeeStatsForRange(
  $pdo, $monthStart, $monthEnd, $hall, $reason, $allowedHalls, $allowedReasons, $search
);

$exportParams = [];
if ($hall !== '') {
  $exportParams['hall'] = $hall;
}
if ($reason !== '') {
  $exportParams['reason'] = $reason;
}
if ($search !== '') {
  $exportParams['search'] = $search;
}
$exportQuery = http_build_query($exportParams);

// === Top-Mitarbeiter: Zeitraum (von/bis aus Filter) ======================
$rangeEmpStats = buildEmployeeStatsForRange(
  $pdo,
  $von,
  $bis,
  $hall,
  $reason,
  $allowedHalls,
  $allowedReasons,
  $search
);
$rangeLabel = date('d.m.Y', strtotime($von)) . '–' . date('d.m.Y', strtotime($bis));


// Arbeitswoche (Mo–Fr) für Zeit-Summary je Mitarbeiter
$weekWorkStart = $weekStart; // Montag dieser Woche
$weekWorkEnd   = date('Y-m-d', strtotime('friday this week', $todayTs));

$weekWorkEmpStats = buildEmployeeStatsForRange(
  $pdo,
  $weekWorkStart,
  $weekWorkEnd,
  $hall,
  $reason,
  $allowedHalls,
  $allowedReasons,
  $search
);

// === Müll-Sondertätigkeiten HEUTE ===========================================
$wasteTodayRows  = [];
$wasteTotals     = [
    'klt'          => 0,
    'karton'       => 0,
    'gibo'         => 0,
    'holz'         => 0,
    'hours_ma'     => 0.0,
    'hours_stapler'=> 0.0,
];

try {
    $stmt = $pdo->prepare("
        SELECT
          w.*,
          e1.display_name AS emp1_name,
          e2.display_name AS emp2_name,
          o.display_name  AS ordered_by_name
        FROM qc_100_waste w
        LEFT JOIN users e1 ON e1.id = w.employee_id
        LEFT JOIN users e2 ON e2.id = w.employee2_id
        LEFT JOIN users o  ON o.id  = w.ordered_by_id
        WHERE DATE(w.created_at) = CURDATE()
        ORDER BY w.created_at, w.id
    ");
    $stmt->execute();
    $wasteTodayRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($wasteTodayRows as $row) {
        $qty   = (float)($row['quantity'] ?? 0);
        $type  = (string)($row['waste_type'] ?? '');
        $mins  = isset($row['duration_min']) ? (float)$row['duration_min'] : 0.0;
        $hours = $mins > 0 ? $mins / 60.0 : 0.0;
        $fork  = (int)($row['forklift_required'] ?? 0);

        // Mengen je Art summieren (wie die Excel-Spalten)
        switch ($type) {
            case 'Entleerung KLT':
                $wasteTotals['klt'] += $qty;
                break;
            case 'Entleerung Karton':
                $wasteTotals['karton'] += $qty;
                break;
            case 'Entleerung Gibo':
                $wasteTotals['gibo'] += $qty;
                break;
            case 'Entsorgung Holz':
                $wasteTotals['holz'] += $qty;
                break;
        }

        // Stunden summieren
        $wasteTotals['hours_ma'] += $hours;
        if ($fork === 1) {
            $wasteTotals['hours_stapler'] += $hours;
        }
    }
} catch (Throwable $e) {
    $wasteTodayRows = [];
}



?>
<!doctype html>
<html lang="de" class="h-full bg-slate-100">
<head>
  <meta charset="utf-8">
  <title>100%-Prüfungen – Übersicht</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Tailwind + Forms -->
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

    function pickRoot(htmlText){
  const doc = new DOMParser().parseFromString(htmlText, 'text/html');
  const root = doc.querySelector('[data-tab-root]') || doc.querySelector('main') || doc.body;
  return root ? root.innerHTML : htmlText;
}

  </script>
  <style>
    html { font-size: 1.0rem; } /* alles etwas größer */
    .qc-row.qc-dispo-done {
  opacity: 0.55;
}

  </style>
  <style>
  /* Autocomplete-Liste im Verpackungs-Modal:
     nicht intern scrollen, sondern einfach "auslaufen" lassen */
  #packNeedsModal .sn-suggest-box {
    max-height: none !important;
    overflow-y: visible !important;
    z-index: 9999 !important;
  }
</style>

</head>
<body class="min-h-full text-slate-900 text-base">
  <main data-tab-root data-last-created="<?=htmlspecialchars($lastCreatedAt ?? '')?>">


  <!-- Volle Breite -->
  <div class="w-full py-4 px-3 sm:px-6 lg:px-10">
    <!-- Header -->
   <!-- Header -->
<header class="mb-6">
  <div class="bg-white border border-slate-200 rounded-xl shadow-sm px-4 py-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    
    <!-- Linke Seite: Titel + Zeitraum + Schnellwahl -->
    <div class="space-y-2">
      <!-- Titel + Icon -->
      <div class="flex items-center gap-2">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand/10 text-brand">
          <!-- kleines Icon -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="h-5 w-5" fill="currentColor">
            <path d="M4 3a2 2 0 00-2 2v2.5A2.5 2.5 0 004.5 10h11A2.5 2.5 0 0018 7.5V5a2 2 0 00-2-2H4z" />
            <path d="M4.5 11A2.5 2.5 0 002 13.5V15a2 2 0 002 2h12a2 2 0 002-2v-1.5A2.5 2.5 0 0015.5 11h-11z" />
          </svg>
        </span>
        <div>
          <h1 class="text-xl md:text-2xl font-semibold text-slate-900">
            100%-Prüfungen – Übersicht
          </h1>
          <p class="mt-0.5 text-xs md:text-sm text-slate-600">
            Digitale Erfassung und Auswertung aller 100%-Prüfungen.
          </p>
        </div>
      </div>

      <!-- Zeitraum + Heute -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs md:text-sm text-slate-700">
        <span>
          <span class="font-medium">Zeitraum:</span>
          <span>
            <?=htmlspecialchars(date('d.m.Y', strtotime($von)))?>
            –
            <?=htmlspecialchars(date('d.m.Y', strtotime($bis)))?>
          </span>
        </span>
        <span class="hidden md:inline text-slate-300">|</span>
        <span>
          <span class="font-medium">Heute:</span>
          <span><?=$heuteLabel?></span>
        </span>
      </div>

      <button type="button"
          id="btnPlanPackNeeds"
          class="inline-flex items-center rounded-md bg-brand text-white px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-brand-dark">
    Verpack-Aufgaben planen
  </button>

      <!-- Schnellwahl Zeitraum -->
      <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-700">
        <span class="text-slate-500">Schnellwahl Zeitraum:</span>

        <a href="<?=$selfUrl . '?' . $qsCurrentMonthStr?>"
           class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 hover:border-slate-300">
          Aktueller Monat
        </a>

        <a href="<?=$selfUrl . '?' . $qsLastMonthStr?>"
           class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 hover:border-slate-300">
          Letzter Monat
        </a>
      </div>
    </div>
  </div>
</header>


<?php if ($packTasksToday): ?>
  <section class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm">
    <div class="flex items-center justify-between gap-2 mb-2">
      <div class="flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-500 text-white text-xs font-bold">
          📦
        </span>
        <span class="font-semibold text-indigo-900">
          Tages-Aufgaben Verpackung (vom Dashboard geplant)
        </span>
      </div>
      <span class="text-xs text-indigo-800">
        <?=count($packTasksToday)?> Positionen für <?=$todayDate?>
      </span>
    </div>

    <div class="overflow-x-auto -mx-2">
      <table class="min-w-full border-collapse text-[12px] text-indigo-900"
       id="packNeedsTodayTable">

        <thead>
  <tr class="border-b border-indigo-200 text-[11px] uppercase tracking-wide text-indigo-600">
    <th class="px-2 py-1 text-left">Halle</th>
    <th class="px-2 py-1 text-left">Sachnummer</th>
    <th class="px-2 py-1 text-left">Vorgang</th>
    <th class="px-2 py-1 text-right">Soll Palette</th>
    <th class="px-2 py-1 text-right">Erledigt Palette</th>
    <th class="px-2 py-1 text-right">Rest Palette</th>
    <th class="px-2 py-1 text-left">Kommentar / Alter</th>
    <th class="px-2 py-1 text-center">Aktion</th>
  </tr>
</thead>

       <tbody>
  <?php foreach ($packTasksToday as $t):
    $done   = (int)($t['klt_done'] ?? 0);
    $target = (int)$t['klt_target'];
    $rest   = max(0, $target - $done);

    // Alter in Tagen bestimmen (Basis: need_date, sonst created_at)
    $todayDt  = new DateTimeImmutable($todayDate);
    if (!empty($t['need_date'])) {
        $baseDt = new DateTimeImmutable($t['need_date']);
    } elseif (!empty($t['created_at'])) {
        $baseDt = new DateTimeImmutable(substr($t['created_at'], 0, 10));
    } else {
        $baseDt = $todayDt;
    }

    $diff    = $baseDt->diff($todayDt);
    $ageDays = $diff->invert ? 0 : (int)$diff->days; // future => 0 Tage

    $ageLabel = $ageDays . ' Tag' . ($ageDays === 1 ? '' : 'e');

    // Ampel-Farben
    $ageClass = 'bg-emerald-100 text-emerald-800'; // 0–1 Tage
    if ($ageDays >= 2 && $ageDays <= 3) {
        $ageClass = 'bg-amber-100 text-amber-800'; // 2–3 Tage
    } elseif ($ageDays >= 4) {
        $ageClass = 'bg-red-100 text-red-800';     // ab 4 Tage
    }
  ?>
    <tr class="border-b border-indigo-100/80">
      <td class="px-2 py-1 whitespace-nowrap">
        <?=htmlspecialchars($t['hall'] ?: '-')?>
      </td>
      <td class="px-2 py-1 whitespace-nowrap font-medium">
        <?=htmlspecialchars($t['material_no'])?>
      </td>
      <td class="px-2 py-1 whitespace-nowrap">
        <?=htmlspecialchars($t['reason'])?>
      </td>
      <td class="px-2 py-1 text-right">
        <?=$target?>
      </td>
      <td class="px-2 py-1 text-right text-emerald-700">
        <?=$done?>
      </td>
      <td class="px-2 py-1 text-right <?= $rest > 0 ? 'text-amber-700 font-semibold' : 'text-slate-500' ?>">
        <?=$rest?>
      </td>

      <!-- Kommentar + Alter-Badge -->
      <td class="px-2 py-1">
        <div class="flex items-center justify-between gap-2">
          <span class="text-[12px] text-indigo-900">
            <?=htmlspecialchars($t['comment'] ?? '')?>
          </span>
          <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold <?=$ageClass?>">
            <?=$ageLabel?>
          </span>
        </div>
      </td>

      <!-- Aktion: Löschen -->
      <td class="px-2 py-1 text-center">
        <button type="button"
                class="text-[11px] text-red-600 hover:text-red-800"
                data-pack-need-id="<?=$t['id']?>">
          löschen
        </button>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>

      </table>
    </div>
  </section>
<?php endif; ?>


<section id="openPalletsBox"
         class="mb-6"
         data-hall="<?=htmlspecialchars($hall ?? '')?>"
         data-search="<?=htmlspecialchars($search ?? '')?>">

  <h2 class="text-base font-semibold text-slate-900 mb-2">
    Offene Prozesse je Palette (Umpacken erledigt, Etikettierung oder 100% fehlt)
  </h2>

  <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500">
    Lade offene Prozesse …
  </div>
</section>



<!-- Filter + Kennzahlen -->
    <section class="mb-6 space-y-4">
      <!-- Filter-Form -->
      <form class="flex flex-wrap items-end gap-3 bg-white border border-slate-200 rounded-xl p-4 shadow-sm"
      method="get">

  <!-- Von -->
  <div class="flex flex-col gap-1.5 w-full sm:w-auto">
    <label class="text-sm font-medium text-slate-800">Von</label>
    <input type="date"
           name="von"
           class="rounded-md border-slate-300 text-base w-full sm:w-40"
           value="<?=htmlspecialchars($von)?>">
  </div>

  <!-- Bis -->
  <div class="flex flex-col gap-1.5 w-full sm:w-auto">
    <label class="text-sm font-medium text-slate-800">Bis</label>
    <input type="date"
           name="bis"
           class="rounded-md border-slate-300 text-base w-full sm:w-40"
           value="<?=htmlspecialchars($bis)?>">
  </div>

  <!-- Halle / Bereich -->
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

  <!-- Grund der Prüfung -->
  <div class="flex flex-col gap-1.5 w-full sm:w-auto">
    <label class="text-sm font-medium text-slate-800">Grund der Prüfung</label>
    <select name="reason"
            class="rounded-md border-slate-300 text-base w-full sm:w-56">
      <option value="">alle</option>
      <?php foreach ($allowedReasons as $g): ?>
        <option value="<?=$g?>" <?=$reason===$g?'selected':''?>><?=$g?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Suche nach Palette / Lieferschein / Sachnummer -->
  <div class="flex flex-col gap-1.5 flex-1 min-w-[220px]">
    <label class="text-sm font-medium text-slate-800">
      Suche (Palette / Lieferschein / Sachnummer)
    </label>
    <input type="text"
           name="search"
           id="searchFilter"
           value="<?=htmlspecialchars($search)?>"
           placeholder="Code eintippen oder scannen..."
           class="rounded-md border-slate-300 text-base px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand w-full">
    
  </div>

  <!-- Buttons rechts -->
  <div class="flex gap-2 w-full sm:w-auto sm:ml-auto justify-start sm:justify-end">
    <!-- Reset -->
    <button type="button"
            id="resetFilter"
            class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
      Filter zurücksetzen
    </button>

    <!-- Filtern -->
    <button type="submit"
            class="inline-flex items-center justify-center rounded-md bg-brand text-white text-base px-5 py-2 font-medium shadow-sm hover:bg-brand-dark transition">
      Filtern
    </button>
  </div>

</form>

      <div id="searchInfo"
     class="hidden mt-2 flex items-center gap-3 rounded-lg border border-sky-100 bg-sky-50 px-3 py-2 text-xs sm:text-sm text-sky-800">
  <!-- wird per JS befüllt -->
</div>

<style>
  html { font-size: 1.1rem; }

  /* Rahmen nur außen um eine Paletten-Gruppe */
  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row > td {
    /* border-left: 1px solid rgba(56, 189, 248, 0.7);   */
    /* border-right: 1px solid rgba(56, 189, 248, 0.7); */
    background-color: rgba(248, 250, 252, 0.8);      /* slate-50 leicht */
  }

  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-first > td {
    border-top: 1px solid rgba(56, 189, 248, 0.7);
  }

  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-last > td {
    border-bottom: 1px solid rgba(56, 189, 248, 0.7);
  }

  /* leichte Rundung an den Ecken */
  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-first > td:first-child {
    border-top-left-radius: 0.5rem;
  }
  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-first > td:last-child {
    border-top-right-radius: 0.5rem;
  }
  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-last > td:first-child {
    border-bottom-left-radius: 0.5rem;
  }
  [data-qc-table="1"] tbody.qc-palette-group tr.qc-row.qc-last > td:last-child {
    border-bottom-right-radius: 0.5rem;
  }
</style>


            <!-- Tabelle -->
<section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
 <?php if (!$byDay): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white/80 px-4 py-6 text-sm text-slate-500">
    Im gewählten Zeitraum wurden keine Prüfungen gefunden.
  </div>
 <?php else: ?>
  <div class="mt-4 space-y-3">
    <?php foreach ($byDay as $day => $items):
      $dt      = new DateTimeImmutable($day);
      $weekday = weekday_de($day);
      $isToday = ($day === $heute);
      $abweTag = 0;
      foreach ($items as $r) {
        if ($r['result'] === 'ABWEICHUNG') {
          $abweTag++;
        }
      }

      // Pro Tag sortieren: zuerst nach Palette, dann nach Startzeit
      $rows = $items;
      usort($rows, function (array $a, array $b): int {
        $pa = $a['pallet_code'] ?? '';
        $pb = $b['pallet_code'] ?? '';
        if ($pa === $pb) {
          $ta = $a['time_start'] ?? '';
          $tb = $b['time_start'] ?? '';
          return strcmp((string)$ta, (string)$tb);
        }
        return strcmp((string)$pa, (string)$pb);
      });
    ?>

      <details class="group rounded-xl border border-slate-200 bg-white/90 shadow-sm" <?= $isToday ? 'open' : '' ?>>
        <summary class="flex cursor-pointer select-none items-center justify-between gap-4 px-4 py-3">
          <div class="flex flex-wrap items-baseline gap-2">
            <span class="text-base font-semibold text-slate-800">
              <?= htmlspecialchars($weekday) ?>
            </span>
            <span class="text-sm text-slate-500">
              <?= $dt->format('d.m.Y') ?>
            </span>
            <?php if ($isToday): ?>
              <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                <span class="mr-1 h-2 w-2 rounded-full bg-emerald-500"></span>Heute
              </span>
            <?php endif; ?>
          </div>

          <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm">
            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600">
              <span class="h-2 w-2 rounded-full bg-slate-400"></span>
              <?= count($items) ?> Prüfungen
            </span>
            <span class="inline-flex items-center gap-1 rounded-full <?= $abweTag ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' ?> px-2.5 py-0.5">
              <span class="h-2 w-2 rounded-full <?= $abweTag ? 'bg-red-400' : 'bg-emerald-400' ?>"></span>
              <?= $abweTag ?> Abweichungen
            </span>

            <a href="100p_export_day_xlsx.php?date=<?= htmlspecialchars($day) ?>"
               class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1
                      text-xs font-medium text-emerald-700 hover:bg-emerald-100 hover:border-emerald-400">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                <path d="M4 3a2 2 0 00-2 2v2h2V5h12v10H4v-2H2v2a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4z" />
                <path d="M9 7a1 1 0 112 0v4.586l1.293-1.293a1 1 0 111.414 1.414l-3.004 3.004a1 1 0 01-1.414 0L6.293 11.707a1 1 0 111.414-1.414L9 11.586V7z" />
              </svg>
              <span>Excel (Tag)</span>
            </a>

            <span class="ml-1 text-slate-400 transition-transform group-open:rotate-180">
              <!-- kleiner Chevron -->
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 011.06.02L10 11.085l3.71-3.855a.75.75 0 111.08 1.04l-4.25 4.417a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                      clip-rule="evenodd" />
              </svg>
            </span>
          </div>
        </summary>

        <div class="border-t border-slate-100">
          <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-xs text-slate-800"
                   data-qc-table="1">
              <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                  <th class="px-3 py-2 text-left">Datum/Zeit</th>
                  <th class="px-3 py-2 text-left">Tag</th>
                  <th class="px-3 py-2 text-left">Start</th>
                  <th class="px-3 py-2 text-left">Ende</th>
                  <th class="px-3 py-2 text-left">Dauer (min)</th>
                  <th class="px-3 py-2 text-left">Palette</th>
                  <th class="px-3 py-2 text-left">Lieferschein</th>
                  <th class="px-3 py-2 text-left">Sachnr.</th>
                  <th class="px-3 py-2 text-left">Grund</th>
                  <th class="px-3 py-2 text-left">KLT / Kisten</th>
                  <th class="px-3 py-2 text-left">Ergebnis</th>
                  <th class="px-3 py-2 text-left">Mitarbeiter</th>
                  <th class="px-3 py-2 text-left">Foto</th>
                  <th class="px-3 py-2 text-left">Erledigt (Dispo)</th>
                </tr>
              </thead>

              <?php
                $rowCount       = count($rows);
                $openTbody      = false;

                if ($rowCount === 0):
              ?>
                  <tbody>
                    <tr>
                      <td colspan="14" class="px-3 py-2 text-xs text-slate-500">
                        Keine Einträge.
                      </td>
                    </tr>
                  </tbody>
              <?php
                else:
                  for ($i = 0; $i < $rowCount; $i++):
                    $row = $rows[$i];

                    $palette      = $row['pallet_code'] ?? '';
                    $prevPalette  = $i > 0 ? ($rows[$i-1]['pallet_code'] ?? '') : null;
                    $nextPalette  = $i < $rowCount - 1 ? ($rows[$i+1]['pallet_code'] ?? '') : null;

                    $isFirstGroupRow = ($i === 0) || $palette !== $prevPalette;
                    $isLastGroupRow  = ($i === $rowCount - 1) || $palette !== $nextPalette;

                    if ($isFirstGroupRow) {
                      if ($openTbody) {
                        echo '</tbody>';
                      }
                      echo '<tbody class="qc-palette-group">';
                      $openTbody = true;
                    }

                    $dtRow   = new DateTimeImmutable($row['created_at']);
                    $wdRow   = weekday_de($row['created_at']);
                    $start   = $row['time_start'] ? substr($row['time_start'], 0, 5) : '';
                    $end     = $row['time_end']   ? substr($row['time_end'],   0, 5) : '';
                    $durText = $row['duration_min'] !== null
                      ? (int)$row['duration_min'] . ' min'
                      : '–';
                    $isBad = ($row['result'] === 'ABWEICHUNG');

                    $searchString = mb_strtolower(
                      trim(
                        ($row['pallet_code']   ?? '') . ' ' .
                        ($row['delivery_note'] ?? '') . ' ' .
                        ($row['material_no']   ?? '')
                      )
                    );

                    $klt = isset($row['klt_count']) ? (int)$row['klt_count'] : 0;
                    $qty = isset($row['qty_per_klt']) ? (int)$row['qty_per_klt'] : 0;

                    $rowClasses = 'qc-row';
                    if ($isFirstGroupRow) $rowClasses .= ' qc-first';
                    if ($isLastGroupRow)  $rowClasses .= ' qc-last';
                    if ($isBad)           $rowClasses .= ' bg-red-50/40';
                    if (!empty($row['dispo_done'])) {
                       $rowClasses .= ' qc-dispo-done';
                      }

              ?>
                <tr class="<?= $rowClasses ?>"
                    data-search="<?= htmlspecialchars($searchString, ENT_QUOTES) ?>">

                  <!-- Datum/Zeit -->
                  <td class="whitespace-nowrap px-3 py-1.5 text-xs sm:text-[13px] text-slate-600">
                    <?= htmlspecialchars($dtRow->format('Y-m-d H:i:s')) ?>
                  </td>

                  <!-- Tag -->
                  <td class="whitespace-nowrap px-3 py-1.5 text-xs sm:text-[13px]">
                    <?= htmlspecialchars($wdRow) ?>
                  </td>

                  <!-- Start -->
                  <td class="whitespace-nowrap px-3 py-1.5">
                    <?= htmlspecialchars($start) ?>
                  </td>

                  <!-- Ende -->
                  <td class="whitespace-nowrap px-3 py-1.5">
                    <?= htmlspecialchars($end) ?>
                  </td>

                  <!-- Dauer -->
                  <td class="whitespace-nowrap px-3 py-1.5">
                    <?= htmlspecialchars($durText) ?>
                  </td>

                  <!-- Palette -->
                  <td class="px-3 py-1.5 whitespace-nowrap font-semibold">
                    <?= htmlspecialchars($row['pallet_code'] ?? '') ?>
                  </td>

                  <!-- Lieferschein -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?= htmlspecialchars($row['delivery_note'] ?? '') ?>
                  </td>

                  <!-- Sachnummer -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?= htmlspecialchars($row['material_no'] ?? '') ?>
                  </td>

                  <!-- Grund -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?= htmlspecialchars($row['reason'] ?? '') ?>
                  </td>

                  <!-- KLT / Kisten -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?php if ($klt > 0): ?>
                      <?php if ($qty > 0): ?>
                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                          <?= $klt ?> KLT (à <?= $qty ?>)
                        </span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                          <?= $klt ?> KLT
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-xs text-slate-400">–</span>
                    <?php endif; ?>
                  </td>

                  <!-- Ergebnis -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?php if ($isBad): ?>
                      <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                        ✖ Abweichung
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                        ✔ OK
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- Mitarbeiter -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <?php if (!empty($row['mitarbeiter_avatar'])): ?>
                        <div class="h-7 w-7 rounded-full bg-slate-200 overflow-hidden flex items-center justify-center">
                          <img src="<?= htmlspecialchars($row['mitarbeiter_avatar']) ?>"
                               alt="Profilbild"
                               class="h-full w-full object-cover">
                        </div>
                      <?php else: ?>
                        <?php
                          $name   = $row['mitarbeiter'] ?? '';
                          $parts  = preg_split('/\s+/', trim($name));
                          $ini    = '';
                          if (count($parts) >= 2) {
                            $ini = mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1);
                          } elseif ($name !== '') {
                            $ini = mb_substr($name, 0, 2);
                          }
                          $ini = mb_strtoupper($ini);
                        ?>
                        <div class="h-7 w-7 rounded-full bg-slate-200 flex items-center justify-center text-[11px] font-semibold text-slate-600">
                          <?= htmlspecialchars($ini ?: '?') ?>
                        </div>
                      <?php endif; ?>
                      <span class="text-xs sm:text-[13px] font-medium text-slate-900">
                        <?= htmlspecialchars($row['mitarbeiter']) ?>
                      </span>
                    </div>
                  </td>

                  <!-- Foto -->
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <?php if (!empty($row['photo_path'])): ?>
                      <button type="button"
                              class="text-xs font-medium text-sky-600 hover:text-sky-800 underline"
                              data-photo="<?= htmlspecialchars($row['photo_path']) ?>">
                        Anzeigen
                      </button>
                    <?php else: ?>
                      <span class="text-xs text-slate-400">–</span>
                    <?php endif; ?>
                  </td>

<!-- Dispo-Status -->
<td class="px-3 py-1.5 whitespace-nowrap">
  <label class="inline-flex items-center gap-1 text-[11px] text-slate-600">
    <input type="checkbox"
           class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
           data-dispo-toggle
           data-id="<?= (int)$row['id'] ?>"
           <?= !empty($row['dispo_done']) ? 'checked' : '' ?>>
    <span>erledigt</span>
  </label>
</td>


                </tr>
              <?php
                  endfor;
                  if ($openTbody) {
                    echo '</tbody>';
                  }
                endif;
              ?>
            </table>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
    </section>



      <!-- Tagesübersicht im Zeitraum -->
      <?php if ($perDay): ?>
        <div>
          <h2 class="text-base font-semibold text-slate-900 mb-2">
            Tagesübersicht im Zeitraum
          </h2>
          <div class="flex gap-3 overflow-x-auto pb-2">
            <?php foreach ($perDay as $dateKey => $info):
              $labelDate = date('d.m.Y', strtotime($dateKey));
              $weekday   = weekday_de($dateKey);
              $sumMin    = $info['duration_sum'];
              $avgDay    = ($info['total'] > 0 && $sumMin > 0) ? round($sumMin / $info['total']) : 0;
            ?>
              <div class="min-w-[210px] rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm flex flex-col">
                <div class="text-sm font-semibold text-slate-900">
                  <?=$weekday?>
                  <span class="text-slate-500 text-xs">(<?=$labelDate?>)</span>
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                  <div class="text-2xl font-bold text-slate-900"><?=$info['total']?></div>
                  <div class="text-xs text-slate-600">Prüfungen</div>
                </div>
                <div class="mt-1 text-xs text-red-600">
                  Abweichungen: <span class="font-semibold"><?=$info['abweichungen']?></span>
                </div>
                <div class="mt-1 text-xs text-slate-600">
                  Zeit: <?=$sumMin?> min<?php if ($avgDay): ?> (Ø <?=$avgDay?> min)<?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>



    </section>

    <?php if (!empty($timeMatrix) && !empty($timeEmpOrder)): ?>
  <section class="mb-6">
    <h2 class="text-base font-semibold text-slate-900 mb-2">
      Zeiteinsatz heute (stundenweise)
    </h2>

    <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm overflow-x-auto">
      <table class="min-w-full border-collapse text-[12px] text-slate-900">
        <thead>
          <tr class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
            <th class="px-2 py-1 text-left">Zeit</th>
            <?php foreach ($timeEmpOrder as $eid): ?>
              <th class="px-2 py-1 text-center">
                <?=htmlspecialchars($timeEmpNames[$eid])?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
                <tbody>
          <?php foreach ($hourSlots as $h): ?>
            <tr>
              <!-- Zeit-Spalte, z.B. 07:00–08:00 -->
              <td class="px-2 py-1 text-left whitespace-nowrap">
                <?=sprintf('%02d:00–%02d:00', $h, $h + 1)?>
              </td>

             <?php foreach ($timeEmpOrder as $eid):
  $min     = $timeMatrix[$eid][$h]      ?? 0;
  $details = $timeDetails[$eid][$h]     ?? [];
  $count   = count($details);

  // Tooltip-Text bauen, z.B. "3 Einsätze: 10:00–10:10, 10:20–10:35, 10:50–11:00"
  $title = '';
  if ($count > 0) {
      $title = $count . ' Einsatz' . ($count > 1 ? 'e' : '') . ': ' . implode(', ', $details);
  }
?>
  <td class="px-2 py-1 text-center">
    <?php if ($min > 0): ?>
      <?php
        $rest    = max(0, 60 - $min);                  // Rest bis 60
        $isFull  = ($min >= 60);
        $badgeBg = $isFull
          ? 'bg-emerald-50 text-emerald-700'
          : 'bg-red-50 text-red-700';
      ?>
      <span
        class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-semibold <?=$badgeBg?>"
        title="<?=htmlspecialchars($title)?>"
      >
        <?=$min?>&nbsp;min
        <?php if ($rest > 0): ?>
          &nbsp;(Rest <?=$rest?>)
        <?php endif; ?>
      </span>
    <?php else: ?>
      <span class="text-[11px] text-slate-300">–</span>
    <?php endif; ?>
  </td>
<?php endforeach; ?>

            </tr>
          <?php endforeach; ?>
        </tbody>

      </table>
    </div>
    

    <?php
      // === Zusammenfassung je Mitarbeiter: Heute + Woche (Mo–Fr) =========
      $todayMinutesByName = [];
      foreach ($todayEmpStats as $row) {
        $todayMinutesByName[$row['name']] = (int)$row['sum'];
      }

      $weekWorkMinutesByName = [];
      if (!empty($weekWorkEmpStats)) {
        foreach ($weekWorkEmpStats as $row) {
          $weekWorkMinutesByName[$row['name']] = (int)$row['sum'];
        }
      }

      // Alle Mitarbeiter, die entweder heute oder in der Woche vorkommen
      $allNames = array_unique(array_merge(
        array_keys($todayMinutesByName),
        array_keys($weekWorkMinutesByName)
      ));
      natcasesort($allNames);
    ?>

    <?php if (!empty($allNames)): ?>
      <div class="mt-4 border-t border-slate-100 pt-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">
          Zusammenfassung je Mitarbeiter (heute / Woche bis Freitag)
        </h3>

        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($allNames as $name):
            $minDay  = $todayMinutesByName[$name]    ?? 0;
            $minWeek = $weekWorkMinutesByName[$name] ?? 0;
          ?>
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[12px]">
              <div class="font-semibold text-slate-900 mb-1">
                <?=htmlspecialchars($name)?>
              </div>
              <div class="flex justify-between text-slate-700">
                <span>Heute:</span>
                <span><?=fmt_minutes_de($minDay)?></span>
              </div>
              <div class="flex justify-between text-slate-700">
                <span>Woche (Mo–Fr):</span>
                <span><?=fmt_minutes_de($minWeek)?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if (!empty($timeTotalsToday)): ?>
      <div class="mt-3 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm overflow-x-auto">
        <table class="min-w-full border-collapse text-[12px] text-slate-900">
          <thead>
            <tr class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
              <th class="px-2 py-1 text-left">Summe</th>
              <?php foreach ($timeEmpOrder as $eid): ?>
                <th class="px-2 py-1 text-center">
                  <?=htmlspecialchars($timeEmpNames[$eid])?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <!-- Zeile: Heute -->
            <tr>
              <td class="px-2 py-1 text-left font-semibold">Heute</td>
              <?php foreach ($timeEmpOrder as $eid):
                $min = $timeTotalsToday[$eid] ?? 0;
              ?>
                <td class="px-2 py-1 text-center">
                  <?=$min?>&nbsp;min
                </td>
              <?php endforeach; ?>
            </tr>

            <!-- Zeile: Woche (Mo–Fr) -->
            <tr>
              <td class="px-2 py-1 text-left font-semibold">Woche (Mo–Fr)</td>
              <?php foreach ($timeEmpOrder as $eid):
                $wmin = $timeWeekTotals[$eid] ?? 0;
                $wh   = intdiv($wmin, 60);
                $wm   = $wmin % 60;
              ?>
                <td class="px-2 py-1 text-center">
                  <?=$wh?>&nbsp;h<?php if ($wm): ?> <?=$wm?>&nbsp;min<?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </section>
<?php endif; ?>
  <!-- Modal: Verpack-Aufgaben planen -->
<div id="packNeedsModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] flex flex-col">
    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
      <h2 class="text-base font-semibold text-slate-900">
        Verpack-Aufgaben planen
      </h2>
      <button type="button"
              id="packNeedsClose"
              class="text-slate-500 hover:text-slate-800 text-xl leading-none">
        ×
      </button>
    </div>

    <div class="p-4 overflow-y-auto">
      <form id="packNeedsForm" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-slate-800">Datum</label>
            <input type="date"
                   name="need_date"
                   class="rounded-md border-slate-300 text-base"
                   value="<?=htmlspecialchars(date('Y-m-d'))?>">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-slate-800">Halle / Bereich</label>
            <input type="text"
                   name="hall"
                   class="rounded-md border-slate-300 text-base"
                   placeholder="optional (z.B. W1)"
                   value="<?=htmlspecialchars($hall ?? '')?>">
          </div>
          <div class="flex items-end justify-end">
            <button type="button"
                    id="addPackNeedRow"
                    class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
              + Position hinzufügen
            </button>
          </div>
        </div>

        <div class="overflow-x-auto border border-slate-200 rounded-lg">
          <table class="min-w-full border-collapse text-sm text-slate-800" id="packNeedsTable">
            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-2 py-1 text-left">Sachnummer</th>
                <th class="px-2 py-1 text-left">Vorgang</th>
                <th class="px-2 py-1 text-right">Anzahl KLT</th>
                <th class="px-2 py-1 text-left">Kommentar</th>
                <th class="px-2 py-1 text-center">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <!-- Reihen werden per JS eingefügt -->
            </tbody>
          </table>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button"
                  id="packNeedsCancel"
                  class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Abbrechen
          </button>
          <button type="submit"
                  class="inline-flex items-center rounded-md bg-brand text-white px-5 py-2 text-sm font-medium shadow-sm hover:bg-brand-dark">
            Aufgaben speichern
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
  <!-- Foto-Modal -->
 <div id="photoModal"
       class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60">
    <div class="relative max-w-5xl max-h-[90vh] mx-4">
      <img id="photoModalImg"
           src=""
           alt="Prüffoto"
           class="max-h-[90vh] rounded-lg shadow-2xl bg-white">
      <button type="button"
              id="photoModalClose"
              class="absolute -top-3 -right-3 h-8 w-8 rounded-full bg-white text-slate-700 shadow flex items-center justify-center text-lg font-bold hover:bg-slate-100">
        ×
      </button>
    </div>
  </div>


  <!-- Live-Stack für neue 100%-Prüfungen -->

  <div id="liveEditPopup"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
  <div id="liveEditStack" class="relative">
    <!-- Karten werden per JS eingefügt -->
  </div>
</div>

<!-- Sound für Live-Popup -->
<audio id="liveEditSound"
       src="/assets/sounds/ping.mp3"
       preload="auto"></audio>


<?php if (!empty($wasteTodayRows)): ?>
<section class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs sm:text-sm">
  <!-- Kopfbereich: Titel + kleine Zusammenfassung -->
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-2">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white text-xs font-bold">
        ♻
      </span>
      <div>
        <div class="font-semibold text-emerald-900">
          Abrechnung Sondertätigkeiten – Müll (heute)
        </div>
        <div class="text-[11px] text-emerald-800">
          <?=htmlspecialchars(date('d.m.Y'))?> ·
          <?=number_format($wasteTotals['hours_ma'], 2, ',', '.')?> Std MA ·
          <?=number_format($wasteTotals['hours_stapler'], 2, ',', '.')?> Std Stapler
        </div>
      </div>
    </div>

    <div class="text-[11px] text-emerald-900 text-right">
      <?=number_format($wasteTotals['klt'],    0, ',', '.')?> KLT ·
      <?=number_format($wasteTotals['karton'], 0, ',', '.')?> Karton ·
      <?=number_format($wasteTotals['gibo'],   0, ',', '.')?> Gibo 111 965 ·
      <?=number_format($wasteTotals['holz'],   0, ',', '.')?> Holz
    </div>
  </div>

  <!-- Tabelle wie in der Excel-Abrechnung -->
  <div class="overflow-x-auto">
    <table class="min-w-full text-[11px] sm:text-xs border border-emerald-200 bg-white rounded-lg overflow-hidden">
      <thead class="bg-emerald-100 text-emerald-900">
        <tr>
          <th class="px-2 py-1 text-left  font-semibold">Sondertätigkeit</th>
          <th class="px-2 py-1 text-right font-semibold">KLT</th>
          <th class="px-2 py-1 text-right font-semibold">Karton</th>
          <th class="px-2 py-1 text-right font-semibold">Gitterbox 111 965</th>
          <th class="px-2 py-1 text-right font-semibold">Holz</th>
          <th class="px-2 py-1 text-left  font-semibold">Beauftragt von</th>
          <th class="px-2 py-1 text-center font-semibold">Anzahl MA</th>
          <th class="px-2 py-1 text-left  font-semibold">Name MA</th>
          <th class="px-2 py-1 text-center font-semibold">Stapler</th>
          <th class="px-2 py-1 text-center font-semibold">Datum</th>
          <th class="px-2 py-1 text-center font-semibold">von</th>
          <th class="px-2 py-1 text-center font-semibold">bis</th>
          <th class="px-2 py-1 text-right font-semibold">Std MA</th>
          <th class="px-2 py-1 text-right font-semibold">Std Stapler</th>
          <th class="px-2 py-1 text-left  font-semibold">Bemerkung</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($wasteTodayRows as $row): ?>
          <?php
            $type = (string)($row['waste_type'] ?? '');
            $qty  = (float)($row['quantity'] ?? 0);

            $qtyKlt    = $type === 'Entleerung KLT'    ? $qty : 0;
            $qtyKarton = $type === 'Entleerung Karton' ? $qty : 0;
            $qtyGibo   = $type === 'Entleerung Gibo'   ? $qty : 0;
            $qtyHolz   = $type === 'Entsorgung Holz'   ? $qty : 0;

            $mins       = isset($row['duration_min']) ? (float)$row['duration_min'] : 0.0;
            $hoursMa    = $mins > 0 ? $mins / 60.0 : 0.0;
            $fork       = (int)($row['forklift_required'] ?? 0);
            $hoursFork  = $fork === 1 ? $hoursMa : 0.0;

            $emp1Name = trim((string)($row['emp1_name'] ?? ''));
            $emp2Name = trim((string)($row['emp2_name'] ?? ''));
            $names    = array_filter([$emp1Name, $emp2Name]);
            $nameStr  = implode(' + ', $names);

            $anzMa = count($names);
            if ($anzMa === 0 && !empty($row['employee_id'])) {
                $anzMa = 1;
            }

            $date = $row['created_at'] ?? null;
            $dateStr = $date ? date('d.m.Y', strtotime($date)) : '';

            $timeFrom = isset($row['time_start']) ? substr((string)$row['time_start'], 0, 5) : '';
            $timeTo   = isset($row['time_end'])   ? substr((string)$row['time_end'],   0, 5) : '';
          ?>
          <tr class="border-t border-emerald-100">
            <td class="px-2 py-1"><?=htmlspecialchars($type)?></td>
            <td class="px-2 py-1 text-right"><?= $qtyKlt    ? number_format($qtyKlt,    0, ',', '.') : '' ?></td>
            <td class="px-2 py-1 text-right"><?= $qtyKarton ? number_format($qtyKarton, 0, ',', '.') : '' ?></td>
            <td class="px-2 py-1 text-right"><?= $qtyGibo   ? number_format($qtyGibo,   0, ',', '.') : '' ?></td>
            <td class="px-2 py-1 text-right"><?= $qtyHolz   ? number_format($qtyHolz,   0, ',', '.') : '' ?></td>
            <td class="px-2 py-1 text-left">
              <?=htmlspecialchars((string)($row['ordered_by_name'] ?? ''))?>
            </td>
            <td class="px-2 py-1 text-center">
              <?=$anzMa > 0 ? $anzMa : ''?>
            </td>
            <td class="px-2 py-1 text-left">
              <?=htmlspecialchars($nameStr)?>
            </td>
            <td class="px-2 py-1 text-center">
              <?php if ($fork === 1): ?>
                <span class="inline-flex h-4 w-4 items-center justify-center rounded border border-emerald-400 bg-emerald-100 text-[10px]">✓</span>
              <?php endif; ?>
            </td>
            <td class="px-2 py-1 text-center"><?=htmlspecialchars($dateStr)?></td>
            <td class="px-2 py-1 text-center"><?=htmlspecialchars($timeFrom)?></td>
            <td class="px-2 py-1 text-center"><?=htmlspecialchars($timeTo)?></td>
            <td class="px-2 py-1 text-right">
              <?= $hoursMa > 0 ? number_format($hoursMa, 2, ',', '.') : '' ?>
            </td>
            <td class="px-2 py-1 text-right">
              <?= $hoursFork > 0 ? number_format($hoursFork, 2, ',', '.') : '' ?>
            </td>
            <td class="px-2 py-1 text-left">
              <?=htmlspecialchars((string)($row['comment'] ?? ''))?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="bg-emerald-50 font-semibold">
        <tr>
          <td class="px-2 py-1 text-right">Summe:</td>
          <td class="px-2 py-1 text-right"><?= $wasteTotals['klt']    ? number_format($wasteTotals['klt'],    0, ',', '.') : '' ?></td>
          <td class="px-2 py-1 text-right"><?= $wasteTotals['karton'] ? number_format($wasteTotals['karton'], 0, ',', '.') : '' ?></td>
          <td class="px-2 py-1 text-right"><?= $wasteTotals['gibo']   ? number_format($wasteTotals['gibo'],   0, ',', '.') : '' ?></td>
          <td class="px-2 py-1 text-right"><?= $wasteTotals['holz']   ? number_format($wasteTotals['holz'],   0, ',', '.') : '' ?></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1"></td>
          <td class="px-2 py-1 text-right">
            <?= $wasteTotals['hours_ma'] ? number_format($wasteTotals['hours_ma'], 2, ',', '.') : '' ?>
          </td>
          <td class="px-2 py-1 text-right">
            <?= $wasteTotals['hours_stapler'] ? number_format($wasteTotals['hours_stapler'], 2, ',', '.') : '' ?>
          </td>
          <td class="px-2 py-1"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</section>
<?php else: ?>
<section class="mb-4 rounded-xl border border-dashed border-emerald-200 bg-emerald-50/60 px-4 py-3 text-xs sm:text-sm text-emerald-800">
  Keine Müll-Sondertätigkeiten für heute erfasst.
</section>
<?php endif; ?>



<script>
  // === Foto-Modal + Filter-Reset ========================================
  (function () {
    const modal    = document.getElementById('photoModal');
    const modalImg = document.getElementById('photoModalImg');
    const btnClose = document.getElementById('photoModalClose');

    if (!modal || !modalImg || !btnClose) return;

    document.querySelectorAll('[data-photo]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const src = btn.getAttribute('data-photo');
        if (!src) return;
        modalImg.src = src;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      });
    });

    function closeModal() {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      modalImg.src = '';
    }

    btnClose.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });
  })();

  // === Filter-Reset: alles zurück auf Standard ===========================
  (function () {
    const btn = document.getElementById('resetFilter');
    if (!btn) return;

    btn.addEventListener('click', () => {
      // Zurück nur auf die Grund-URL = keine GET-Parameter
      window.location.href = window.location.pathname;
    });
  })();
</script>

<script>
  // === Live-Filter: Palette / Lieferschein / Sachnummer im Browser =======
  (function () {
    const searchInput = document.getElementById('searchFilter');
    if (!searchInput) return;

    const infoBox   = document.getElementById('searchInfo');
    const rows      = document.querySelectorAll('table[data-qc-table="1"] tbody tr');
    const dayBlocks = document.querySelectorAll('details.group');

    function escapeHtml(str) {
      return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function applyFilter() {
      const needle = searchInput.value.trim().toLowerCase();
      let visibleCount = 0;

      // Zeilen filtern
      rows.forEach(row => {
        const hay   = (row.getAttribute('data-search') || '').toLowerCase();
        const match = needle === '' || hay.includes(needle);

        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
      });

      // Tages-Details aus-/einblenden
      dayBlocks.forEach(block => {
        const visibleRow = block.querySelector('tbody tr:not([style*="display: none"])');
        if (needle && !visibleRow) {
          block.style.display = 'none';
        } else {
          block.style.display = '';
          if (needle && visibleRow) {
            block.open = true; // bei Filter Tage mit Treffern automatisch öffnen
          }
        }
      });

      // Info-Box aktualisieren
      if (!infoBox) return;

      if (needle === '') {
        infoBox.classList.add('hidden');
        infoBox.innerHTML = '';
      } else {
        infoBox.classList.remove('hidden');
        infoBox.innerHTML =
          '<span class="font-semibold">Gefiltert nach:</span> ' +
          '<span class="ml-1 font-mono">' + escapeHtml(needle) + '</span>' +
          '<span class="ml-3 text-xs sm:text-sm">(' + visibleCount + ' Treffer)</span>';
      }
    }

    // Beim Tippen / Scannen filtern
    searchInput.addEventListener('input', applyFilter);

    // Falls beim Laden schon ein Wert drin ist (z. B. per GET), direkt anwenden
    if (searchInput.value.trim() !== '') {
      applyFilter();
    }

    // Bonus: ESC leert das Suchfeld + Reset
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        searchInput.value = '';
        applyFilter();
      }
    });
  })();
</script>

<script>
  // === Modal "Verpack-Aufgaben planen" + Toast + Löschen ==================
  (function () {
    window.addEventListener('DOMContentLoaded', () => {
      const modal     = document.getElementById('packNeedsModal');
      const btnOpen   = document.getElementById('btnPlanPackNeeds');
      const btnClose  = document.getElementById('packNeedsClose');
      const btnCancel = document.getElementById('packNeedsCancel');
      const btnAdd    = document.getElementById('addPackNeedRow');
      const form      = document.getElementById('packNeedsForm');
      const tbody     = document.querySelector('#packNeedsTable tbody');
      const toast     = document.getElementById('toastPackNeeds');

      // Minimum-Voraussetzung: Modal, Button, Form
      if (!modal || !btnOpen || !form || !tbody) return;

      const hallInputGlobal = form.elements['hall'] || null;
      const SN_API_URL      = '/api/stammdaten_api.php';

      // Toast-Elemente (optional dynamischer Text, wenn im HTML vorhanden)
      const toastPanel = toast ? toast.querySelector('[data-toast-panel]') : null;
      const toastTitle = toast ? toast.querySelector('[data-toast-title]') : null;
      const toastMsg   = toast ? toast.querySelector('[data-toast-msg]') : null;

      let toastTimer = null;

      // === API: Sachnummern aus Stammdaten holen ==========================
      async function apiListSachnummern(q) {
        const url = new URL(SN_API_URL, window.location.origin);
        url.searchParams.set('type', 'sachnummer');
        url.searchParams.set('action', 'list');
        if (q) url.searchParams.set('q', q);

        const res = await fetch(url.toString(), {
          credentials: 'same-origin',
          cache: 'no-store'
        });

        const j = await res.json().catch(() => ({}));
        if (!res.ok || !j || !j.ok || !Array.isArray(j.items)) {
          console.warn('Sachnummer-API Fehler im Modal', j);
          return [];
        }
        return j.items;
      }

      // === Autocomplete für Sachnummern ===================================
      function attachSachnummerAutocomplete(input) {
        const td = input.closest('td');
        if (!td) return;

        td.classList.add('relative');

        let box = td.querySelector('.sn-suggest-box');
        if (!box) {
          box = document.createElement('div');
          box.className =
            'sn-suggest-box absolute left-0 right-0 top-full mt-1 ' +
            'bg-white border border-slate-200 rounded-md shadow-lg ' +
            'text-sm z-50 hidden';
          td.appendChild(box);
        }

        let timer = null;

        function clearSuggestions() {
          box.innerHTML = '';
          box.classList.add('hidden');
        }

        function showSuggestions(items) {
          box.innerHTML = '';

          if (!items.length) {
            clearSuggestions();
            return;
          }

          items.slice(0, 20).forEach(it => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className =
              'w-full text-left px-3 py-1.5 text-sm hover:bg-slate-100 focus:bg-slate-100';

            let label = it.sachnummer || '';
            if (it.lagergruppe)  label += ' (' + it.lagergruppe;
            if (it.behaelter_nr) label += (label.includes('(') ? ', ' : ' (') + it.behaelter_nr;
            if (label.includes('(') && !label.endsWith(')')) label += ')';

            btn.textContent = label;

            btn.addEventListener('click', ev => {
              ev.preventDefault();
              input.value = it.sachnummer || '';
              clearSuggestions();
              input.focus();

              const hallFromApi = it.hall || it.halle || it.standard_hall || '';
              if (hallInputGlobal && hallFromApi && !hallInputGlobal.value.trim()) {
                hallInputGlobal.value = hallFromApi;
              }
            });

            box.appendChild(btn);
          });

          box.classList.remove('hidden');
        }

        input.addEventListener('input', () => {
          const q = input.value.trim();

          if (timer) clearTimeout(timer);

          if (q.length < 3) {
            clearSuggestions();
            return;
          }

          timer = setTimeout(async () => {
            const items = await apiListSachnummern(q);
            showSuggestions(items);
          }, 200);
        });

        document.addEventListener('click', ev => {
          if (!td.contains(ev.target)) {
            clearSuggestions();
          }
        });
      }

      // === Toast-Logik ====================================================
      function showPackToast(title, message, variant = 'success') {
        if (!toast) return;

        if (toastTitle && title) {
          toastTitle.textContent = title;
        }
        if (toastMsg && message) {
          toastMsg.textContent = message;
        }

        if (toastPanel) {
          toastPanel.classList.remove('bg-emerald-500', 'bg-red-500');
          if (variant === 'error') {
            toastPanel.classList.add('bg-red-500');
          } else {
            toastPanel.classList.add('bg-emerald-500');
          }
        }

        toast.classList.remove('opacity-0', 'translate-y-2', 'pointer-events-none');
        toast.classList.add('opacity-100', 'translate-y-0');

        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(hidePackToast, 3000);
      }

      function hidePackToast() {
        if (!toast) return;
        toast.classList.add('opacity-0', 'translate-y-2', 'pointer-events-none');
        toast.classList.remove('opacity-100', 'translate-y-0');
      }

      if (toast) {
        const btnCloseToast = toast.querySelector('[data-toast-close]');
        if (btnCloseToast) {
          btnCloseToast.addEventListener('click', hidePackToast);
        }
      }

      // === Modal öffnen / schließen =======================================
      function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        if (!tbody.querySelector('tr')) {
          addRow();
        }
      }

      function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }

      // === Zeile hinzufügen ===============================================
      function addRow() {
        if (!tbody) return;

        const tr = document.createElement('tr');
        tr.className = 'border-t border-slate-100';

        tr.innerHTML = `
          <td class="px-2 py-1 relative">
            <input type="text"
                   name="material_no[]"
                   class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm"
                   placeholder="Sachnummer (z.B. 0Z1 915 404 BF)">
          </td>
          <td class="px-2 py-1">
            <select name="reason[]"
                    class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm bg-white">
              <option value="Etikettierung KLT">Etikettierung KLT</option>
              <option value="Umpacken auf Palette">Umpacken auf Palette</option>
              <option value="Umfüllung in KLT">Umfüllung in KLT</option>
              <option value="100% Prüfung">100% Prüfung</option>
            </select>
          </td>
          <td class="px-2 py-1 text-right">
  <input type="number"
         name="klt_target[]"
         min="1"
         step="1"
         class="w-24 rounded-md border border-slate-300 px-2 py-1 text-sm text-right"
         placeholder="z.B. 3">
 </td>

          <td class="px-2 py-1">
            <input type="text"
                   name="comment[]"
                   class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm"
                   placeholder="optional">
          </td>
          <td class="px-2 py-1 text-center">
            <button type="button"
                    class="text-xs text-red-600 hover:text-red-800"
                    data-remove-row>
              Entfernen
            </button>
          </td>
        `;
        tbody.appendChild(tr);

        const snInput = tr.querySelector('input[name="material_no[]"]');
        if (snInput) {
          attachSachnummerAutocomplete(snInput);
        }
      }

      // === Event-Listener Modal / Buttons / Zeilen ========================
      btnOpen.addEventListener('click', openModal);
      if (btnClose)  btnClose.addEventListener('click', closeModal);
      if (btnCancel) btnCancel.addEventListener('click', closeModal);

      if (btnAdd) {
        btnAdd.addEventListener('click', (e) => {
          e.preventDefault();
          addRow();
        });
      }

      tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-row]');
        if (!btn) return;
        const tr = btn.closest('tr');
        if (tr) tr.remove();
      });

      // === Formular-Submit: Aufgaben speichern ============================
      form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const dateInput = form.elements['need_date'];
        const hallInput = form.elements['hall'];

        const mats = form.querySelectorAll('input[name="material_no[]"]');
        const reas = form.querySelectorAll('select[name="reason[]"]');
        const klt  = form.querySelectorAll('input[name="klt_target[]"]');
        const comm = form.querySelectorAll('input[name="comment[]"]');

        const items = [];
        for (let i = 0; i < mats.length; i++) {
          const mat = mats[i].value.trim();
          const r   = reas[i].value;
          const k   = parseInt(klt[i].value, 10) || 0;
          const c   = comm[i].value.trim();

          if (!mat || k <= 0) continue;

          items.push({
            material_no: mat,
            reason:      r,
            klt_target:  k,
            comment:     c
          });
        }

        if (!items.length) {
          showPackToast(
            'Fehlende Daten',
            'Bitte mindestens eine Position mit Sachnummer und Anzahl KLT ausfüllen.',
            'error'
          );
          return;
        }

        const payload = new URLSearchParams();
        payload.set('action', 'save_needs');
        payload.set('need_date', dateInput.value);
        payload.set('hall', hallInput.value.trim());
        payload.set('items', JSON.stringify(items));

        try {
          const res = await fetch('100p_pack_needs_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
          });

          const j = await res.json().catch(() => ({}));
          if (!res.ok || !j.ok) {
            showPackToast(
              'Fehler',
              j.msg || 'Aufgaben konnten nicht gespeichert werden.',
              'error'
            );
            return;
          }

          showPackToast(
            'Aufgaben gespeichert',
            'Die Verpack-Aufgaben wurden übernommen.',
            'success'
          );
          closeModal();
        } catch (err) {
          console.error(err);
          showPackToast(
            'Fehler',
            'Netzwerkfehler beim Speichern der Aufgaben.',
            'error'
          );
        }
      });

      // === Eintrag aus "Tages-Aufgaben Verpackung" löschen ================
      const todayTable = document.getElementById('packNeedsTodayTable');
      if (todayTable) {
        todayTable.addEventListener('click', async (e) => {
          const btn = e.target.closest('[data-pack-need-id]');
          if (!btn) return;

          const id  = btn.getAttribute('data-pack-need-id');
          if (!id) return;

          const row = btn.closest('tr');

          const payload = new URLSearchParams();
          payload.set('action', 'delete_need');
          payload.set('id', id);

          try {
            const res = await fetch('100p_pack_needs_api.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
              },
              body: payload.toString()
            });

            const j = await res.json().catch(() => ({}));

            if (!res.ok || !j.ok) {
              showPackToast(
                'Fehler',
                j.msg || 'Eintrag konnte nicht gelöscht werden.',
                'error'
              );
              return;
            }

            if (row) {
              row.remove();
            }

            showPackToast(
              'Eintrag gelöscht',
              'Die Verpack-Aufgabe wurde entfernt.',
              'success'
            );
          } catch (err) {
            console.error(err);
            showPackToast(
              'Fehler',
              'Netzwerkfehler beim Löschen der Aufgabe.',
              'error'
            );
          }
        });
      }
    });
  })();
</script>

<script>
 // === Live-Popups im Stack + Ping + Reload offene Paletten ===================
 (function () {
  const root   = document.querySelector('[data-last-created]');
  const overlay = document.getElementById('liveEditPopup');
  const stack   = document.getElementById('liveEditStack');
  const sound   = document.getElementById('liveEditSound');

  if (!root || !overlay || !stack) return;

  let lastSeen = root.getAttribute('data-last-created') || '';

  function playSound() {
    if (!sound) return;
    try {
      sound.currentTime = 0;
      sound.play().catch(() => {});
    } catch (e) {
      console.warn('Sound konnte nicht abgespielt werden', e);
    }
  }

  // Hilfsfunktion zum Escapen (für Sicherheit)
  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

 function addPopupCard(item) {
  const idx = stack.childElementCount; // 0,1,2,... => für Offset

  const card = document.createElement('div');
  card.className =
    'absolute max-w-xs w-[280px] sm:w-[320px] rounded-xl bg-white shadow-xl ' +
    'border border-slate-200 px-4 py-3 text-xs text-slate-800';

  // leichte Überlappung (diagonal versetzt)
  const SHIFT = 14;
  card.style.top  = (idx * SHIFT) + 'px';
  card.style.left = (idx * SHIFT) + 'px';

  // Rohdaten
  const created = item.created_at    || '-';
  const emp     = item.mitarbeiter   || '-';
  const hall    = item.hall          || '-';
  const mat     = item.material_no   || '-';
  const pallet  = item.pallet_code   || '-';
  const deliv   = item.delivery_note || '-';
  const reason  = item.reason        || '-';

  // Dynamischer Text je Grund
  let reasonPhrase = 'einen Vorgang erfasst';
  switch (reason) {
    case '100% Prüfung':
      reasonPhrase = 'eine 100%-Prüfung erfasst';
      break;
    case 'Etikettierung KLT':
      reasonPhrase = 'eine Etikettierung KLT erfasst';
      break;
    case 'Umpacken auf Palette':
      reasonPhrase = 'ein Umpacken auf Palette erfasst';
      break;
    case 'Umfüllung in KLT':
      reasonPhrase = 'eine Umfüllung in KLT erfasst';
      break;
    default:
      if (reason && reason !== '-') {
        reasonPhrase = `den Vorgang „${reason}“ erfasst`;
      }
  }

  card.innerHTML = `
    <div class="flex items-center justify-between mb-1 text-[11px] text-slate-400">
      <span>${esc(created)}</span>
      <button type="button"
              class="ml-2 text-slate-400 hover:text-slate-700 text-xs"
              data-live-close>
        ✕
      </button>
    </div>

    <div class="mb-2 text-[11px] font-semibold text-slate-700">
      ${esc(emp)} hat ${esc(reasonPhrase)}
    </div>

    <div class="space-y-0.5 text-[11px] leading-snug">
      <div><span class="font-semibold">Halle:</span> ${esc(hall)}</div>
      <div><span class="font-semibold">Palette:</span> ${esc(pallet)}</div>
      <div><span class="font-semibold">Lieferschein:</span> ${esc(deliv)}</div>
      <div><span class="font-semibold">Sachnummer:</span> ${esc(mat)}</div>
      <div><span class="font-semibold">Grund:</span> ${esc(reason)}</div>
    </div>
  `;

  // Close-Button pro Karte
  const btnClose = card.querySelector('[data-live-close]');
  if (btnClose) {
    btnClose.addEventListener('click', () => {
      card.remove();
      if (!stack.childElementCount) {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
      }
    });
  }

  stack.appendChild(card);

  // Overlay zeigen + zentrieren
  overlay.classList.remove('hidden');
  overlay.classList.add('flex');

  playSound();

  // Offene Paletten neu laden, wenn vorhanden
  if (window.reloadOpenPallets) {
    window.reloadOpenPallets();
  }
}



  overlay.addEventListener('click', (e) => {
  if (e.target === overlay) {
    while (stack.firstChild) {
      stack.removeChild(stack.firstChild);
    }
    overlay.classList.add('hidden');
    overlay.classList.remove('flex'); // ⬅️ NEU
  }
});


  async function checkUpdates() {
    // ⏰ Nur zwischen 07:00 und 20:00 Uhr prüfen (kannst du anpassen)
    const now  = new Date();
    const hour = now.getHours(); // 0–23
    if (hour < 7 || hour >= 20) {
      return;
    }

    if (!lastSeen) {
      return;
    }

    const payload = new URLSearchParams();
    payload.set('action', 'check_updates');
    payload.set('since', lastSeen);

    try {
      const res = await fetch('100p_live_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: payload.toString()
      });

      const j = await res.json().catch(() => null);
      if (!j || !j.ok || !Array.isArray(j.items) || !j.items.length) {
        return;
      }

      // Alle neuen Einträge verarbeiten
      // Falls API sortiert (z.B. älteste → neueste), übernehmen wir die Reihenfolge
      j.items.forEach(item => {
        addPopupCard(item);
      });

      // lastSeen auf das höchste created_at der gelieferten Items setzen
      let maxTs = lastSeen;
      j.items.forEach(item => {
        if (item && item.created_at && item.created_at > maxTs) {
          maxTs = item.created_at;
        }
      });
      lastSeen = maxTs;

    } catch (err) {
      console.error('Live-Check Fehler', err);
    }
  }

  // Alle 30 Sekunden nach neuen Einträgen schauen
  setInterval(checkUpdates, 30000);
 })();
</script>

<div id="toastOpenPallets"
     class="fixed bottom-4 right-4 z-40 pointer-events-none opacity-0 translate-y-2 transition-all duration-300 ease-out">
  <div class="rounded-full bg-emerald-600 text-white text-xs px-3 py-1.5 shadow-lg flex items-center gap-2">
    <span class="inline-block h-2 w-2 rounded-full bg-emerald-300 animate-pulse"></span>
    <span>Offene Prozesse aktualisiert</span>
  </div>
</div>

<script>
  (function () {
    const toast = document.getElementById('toastOpenPallets');
    if (!toast) return;

    let timer = null;

    window.showOpenPalletsToast = function () {
      // sichtbar machen
      toast.classList.remove('opacity-0', 'translate-y-2', 'pointer-events-none');
      toast.classList.add('opacity-100', 'translate-y-0');

      if (timer) clearTimeout(timer);
      timer = setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-y-2', 'pointer-events-none');
        toast.classList.remove('opacity-100', 'translate-y-0');
      }, 2000); // 2 Sekunden sichtbar
    };
  })();
</script>

<script>
  // === Offene Prozesse neu laden (wird auch vom Live-Popup genutzt) ========
  window.reloadOpenPallets = async function () {
    const box = document.getElementById('openPalletsBox');
    if (!box) return;

    // Filter-Elemente (falls vorhanden)
    const hallSel     = document.querySelector('[data-filter="hall"]');
    const searchInput = document.querySelector('[data-filter="search"]');

    const hall   = hallSel ? hallSel.value : (box.dataset.hall   || '');
    const search = searchInput
      ? searchInput.value.trim()
      : (box.dataset.search || '');

    const params = new URLSearchParams();
    if (hall)   params.set('hall', hall);
    if (search) params.set('search', search);

    try {
      const res = await fetch('100p_open_pallets_partial.php?' + params.toString(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      if (!res.ok) {
        console.error('reloadOpenPallets HTTP-Fehler', res.status);
        return;
      }

      const data = await res.json().catch(() => null);
      if (!data || !data.ok || typeof data.html !== 'string') {
        console.error('reloadOpenPallets: unerwartete Antwort', data);
        return;
      }

      box.innerHTML = data.html;

      if (window.showOpenPalletsToast) {
  window.showOpenPalletsToast();
}

    } catch (err) {
      console.error('reloadOpenPallets Fehler', err);
    }
  };

  // Beim Laden der Seite direkt einmal ziehen
  document.addEventListener('DOMContentLoaded', () => {
    if (window.reloadOpenPallets) {
      window.reloadOpenPallets();
    }
  });
</script>

<script>
  window.addEventListener('DOMContentLoaded', () => {
    const sound = document.getElementById('liveEditSound');
    if (!sound) return;

    // Einmalig freischalten, sobald der User irgendwo klickt
    function unlockAudio() {
      sound.play().catch(() => {
        // Fehler ignorieren, wir wollten nur entsperren
      });
      document.removeEventListener('click', unlockAudio);
    }

    document.addEventListener('click', unlockAudio);
  });
</script>

<script>
  (function () {
  const table = document.querySelector('table[data-qc-table="1"]');
  if (!table) return;

  table.addEventListener('change', async (e) => {
    const box = e.target.closest('input[data-dispo-toggle]');
    if (!box) return;

    const row  = box.closest('tr');
    const id   = box.getAttribute('data-id');
    const done = box.checked ? '1' : '0';

    if (!id) return;

    const payload = new URLSearchParams();
    payload.set('id',   id);
    payload.set('done', done);

    try {
      const res  = await fetch('100p_dispo_done_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        credentials: 'same-origin', // <-- wichtig, Session-Cookies mitnehmen
        body: payload.toString()
      });

      const text = await res.text();
      let j = null;
      try {
        j = JSON.parse(text);
      } catch (err) {
        console.error('JSON-Parse-Fehler bei dispo_done:', text);
      }

      if (!res.ok || !j || !j.ok) {
        // zurückrollen
        box.checked = !box.checked;
        if (row) {
          row.classList.toggle('qc-dispo-done', box.checked);
        }
        alert(j && j.msg ? j.msg : 'Status konnte nicht gespeichert werden.');
        return;
      }

      // Erfolgreich → Klasse setzen
      if (row) {
        row.classList.toggle('qc-dispo-done', done === '1');
      }

    } catch (err) {
      console.error('fetch-Fehler dispo_done:', err);
      box.checked = !box.checked;
      if (row) {
        row.classList.toggle('qc-dispo-done', box.checked);
      }
      alert('Netzwerkfehler beim Speichern des Status.');
    }
  });
})();

</script>



</main>
</body>
</html>

