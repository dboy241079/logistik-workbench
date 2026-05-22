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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: 100p_form.php');
    exit;
}

$palletCode   = trim($_POST['pallet_code'] ?? '');
$deliveryNote = trim($_POST['delivery_note'] ?? '');
$materialNo   = trim($_POST['material_no'] ?? '');
$reason       = trim($_POST['reason'] ?? '');
$result       = $_POST['result'] ?? 'OK';
$comment      = trim($_POST['comment'] ?? '');
$employeeId   = !empty($_POST['employee_id'])
    ? (int)$_POST['employee_id']
    : ($_SESSION['user_id'] ?? null);

// NEU: Anzahl der KLTs (nur relevant bei KLT-Vorgängen)
$kltRaw   = trim($_POST['klt_count'] ?? '');
$kltCount = $kltRaw !== '' ? max(0, (int)$kltRaw) : null;

// NEU: Anzahl pro KLT (nur bei Umfüllung in KLT relevant)
$qtyRaw     = trim($_POST['qty_per_klt'] ?? '');
$qtyPerKlt  = $qtyRaw !== '' ? max(0, (int)$qtyRaw) : null;

if ($palletCode === '') {
    die('Paletten-Code fehlt.');
}

// Zeitfelder
$timeStart = trim($_POST['time_start'] ?? '');
$timeEnd   = trim($_POST['time_end'] ?? '');

if ($timeStart === '') $timeStart = null;
if ($timeEnd === '')   $timeEnd   = null;

// Zeitfenster 07:00–20:00 prüfen (optional, für Sicherheit)
if ($timeStart !== null && $timeEnd !== null) {
    if ($timeStart < '06:00' || $timeEnd > '21:00') {
        die('Zeiten müssen im Bereich 06:00 bis 21:00 Uhr liegen.');
    }
}

/**
 * 🧠 NEU: Overlap-Check pro Mitarbeiter/Tag, bevor gespeichert wird
 * Bedingung: Mitarbeiter + Start + Ende vorhanden
 */
if ($employeeId !== null && $timeStart !== null && $timeEnd !== null) {

    $dtStart = DateTime::createFromFormat('H:i', $timeStart);
    $dtEnd   = DateTime::createFromFormat('H:i', $timeEnd);

    if ($dtStart && $dtEnd) {
        $startMin = (int)$dtStart->format('H') * 60 + (int)$dtStart->format('i');
        $endMin   = (int)$dtEnd->format('H')   * 60 + (int)$dtEnd->format('i');

        if ($endMin > $startMin) {
            // Alle bisherigen Zeiten dieses Mitarbeiters HEUTE holen
            $stmt = $pdo->prepare("
                SELECT time_start, time_end
                FROM qc_100_pruefungen
                WHERE employee_id = :eid
                  AND DATE(created_at) = CURDATE()
                  AND time_start IS NOT NULL
                  AND time_end   IS NOT NULL
            ");
            $stmt->execute([':eid' => $employeeId]);

            $conflicts = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Kann als 'H:i:s' oder 'H:i' aus DB kommen
                $es = DateTime::createFromFormat('H:i:s', $row['time_start'])
                   ?: DateTime::createFromFormat('H:i',    $row['time_start']);
                $ee = DateTime::createFromFormat('H:i:s', $row['time_end'])
                   ?: DateTime::createFromFormat('H:i',    $row['time_end']);

                if (!$es || !$ee) continue;

                $esMin = (int)$es->format('H') * 60 + (int)$es->format('i');
                $eeMin = (int)$ee->format('H') * 60 + (int)$ee->format('i');
                if ($eeMin <= $esMin) continue;

                // Overlap-Bedingung: [start, end) überschneidet [es, ee)
                if ($startMin < $eeMin && $endMin > $esMin) {
                    $conflicts[] = [
                        'start' => $es->format('H:i'),
                        'end'   => $ee->format('H:i'),
                    ];
                }
            }

            if (!empty($conflicts)) {
    // Hinweis für die Form-Seite bauen
    $_SESSION['time_overlap'] = [
        'message'     => 'Für diesen Mitarbeiter überschneidet sich die eingegebene Zeit mit bereits erfassten Zeiten. Bitte Zeiten prüfen.',
        'new_slot'    => [
            'start' => $timeStart,
            'end'   => $timeEnd,
        ],
        'conflicts'   => $conflicts,
        'pallet_code' => $palletCode,
        'material_no' => $materialNo,
    ];

    // Formdaten für Prefill beim nächsten Aufruf merken
    $_SESSION['time_overlap_form'] = [
        'pallet_code'   => $palletCode,
        'delivery_note' => $deliveryNote,
        'material_no'   => $materialNo,
        'reason'        => $reason,
        'result'        => $result,
        'comment'       => $comment,
        'employee_id'   => $employeeId,
        'klt_count'     => $kltCount,
        'qty_per_klt'   => $qtyPerKlt,
        // Zeiten werden bewusst NICHT wieder eingesetzt → neue Zeit eingeben
        'time_start'    => null,
        'time_end'      => null,
    ];

    // ⛔️ NICHT speichern, zurück zur Eingabemaske
    header('Location: 100p_form.php');
    exit;
}

        }
    }
}

// Dauer in Minuten berechnen
$durationMin = null;
if ($timeStart && $timeEnd) {
    $dtStart = DateTime::createFromFormat('H:i', $timeStart);
    $dtEnd   = DateTime::createFromFormat('H:i', $timeEnd);
    if ($dtStart && $dtEnd) {
        $diff = $dtEnd->getTimestamp() - $dtStart->getTimestamp();
        if ($diff >= 0) {
            $durationMin = (int)round($diff / 60);
        }
    }
}

// Foto-Upload
$photoPath = null;
if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $ext = $ext ? ('.' . strtolower($ext)) : '';
    $filename   = 'qc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
        // Web-Pfad relativ zur 100_Prufung
        $photoPath = 'uploads/' . $filename;
    }
}

$sql = "
INSERT INTO qc_100_pruefungen
  (pallet_code, delivery_note, material_no, reason, result,
   time_start, time_end, duration_min, comment, photo_path, employee_id,
   klt_count, qty_per_klt)
VALUES
  (:pallet_code, :delivery_note, :material_no, :reason, :result,
   :time_start, :time_end, :duration_min, :comment, :photo_path, :employee_id,
   :klt_count, :qty_per_klt)
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':pallet_code'   => $palletCode,
    ':delivery_note' => $deliveryNote,
    ':material_no'   => $materialNo,
    ':reason'        => $reason,
    ':result'        => $result,
    ':time_start'    => $timeStart,
    ':time_end'      => $timeEnd,
    ':duration_min'  => $durationMin,
    ':comment'       => $comment,
    ':photo_path'    => $photoPath,
    ':employee_id'   => $employeeId,
    ':klt_count'     => $kltCount,
    ':qty_per_klt'   => $qtyPerKlt,
]);

// NEU: letzte erfasste Palette für die Form-Anzeige merken
$_SESSION['last_100p'] = [
    'pallet_code' => $palletCode,
    'reason'      => $reason,
    'klt_count'   => $kltCount,
];

// nach erfolgreichem INSERT
$isKltStep = in_array($reason, [
    'Etikettierung KLT',
    'Umpacken auf Palette',
    'Umfüllung in KLT'
], true);

if ($isKltStep) {
    $_SESSION['prefill_100p'] = [
        'pallet_code'   => $palletCode,
        'delivery_note' => $deliveryNote,
        'material_no'   => $materialNo,
        'employee_id'   => $employeeId,
        'time_start'    => $timeStart,
        'time_end'      => $timeEnd,
        'comment'       => $comment,
        'reason'        => $reason,
    ];

    $_SESSION['last_100p'] = [
        'pallet_code' => $palletCode,
        'reason'      => $reason,
        'klt_count'   => $kltCount ?? null,
    ];

    header('Location: 100p_form.php?ask100=1');
    exit;
}

// bei allen anderen Gründen: normal zurück
header('Location: 100p_form.php?ok=1');
exit;
