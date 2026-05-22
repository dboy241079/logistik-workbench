<?php
declare(strict_types=1);

$AUTH_DEFAULT_TAB   = 'special';
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;                     // API darf direkt aufgerufen werden
$AUTH_ALLOWED_ROLES = ['admin','disposition','verpackung'];
$AUTH_DENY_MODE     = 'message';

require __DIR__ . '/../inc/auth_embed.php';
require __DIR__ . '/../api/_db.php';

header('Content-Type: application/json; charset=utf-8');

// Benutzer prüfen
$userId = $_SESSION['user_id'] ?? null;
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Nicht eingeloggt.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Nur POST erlaubt.']);
    exit;
}

// --------------------------------------------------
// Daten aus POST – Namen GENAU wie im Formular
// --------------------------------------------------
$wasteType = trim($_POST['waste_type'] ?? '');
$unit      = trim($_POST['unit']       ?? '');
$qtyRaw    = trim($_POST['quantity']   ?? '');

// Formular: name="emp1_id"
$emp1      = (int)($_POST['emp1_id']       ?? 0);
// Formular: name="emp2_id"
$emp2      = (int)($_POST['emp2_id']       ?? 0);
// Formular: name="ordered_by_id"
$orderedBy = (int)($_POST['ordered_by_id'] ?? 0);

// Formular: name="needs_forklift" (Checkbox)
$forkliftRequired = !empty($_POST['needs_forklift']) ? 1 : 0;

// Formular: name="time_start" / name="time_end"
$timeFrom = trim($_POST['time_start'] ?? '');
$timeTo   = trim($_POST['time_end']   ?? '');

$comment  = trim($_POST['comment']   ?? '');

$errors = [];

// --------------------------------------------------
// Validierung
// --------------------------------------------------
if ($wasteType === '') {
    $errors[] = 'Bitte eine Form des Mülls auswählen.';
}

if ($qtyRaw === '') {
    $errors[] = 'Bitte eine Anzahl / Menge eingeben.';
} else {
    $qtyRaw = str_replace(',', '.', $qtyRaw);
    if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
        $errors[] = 'Die Menge muss größer als 0 sein.';
    }
}
$quantity = (float)$qtyRaw;

if ($emp1 <= 0) {
    $errors[] = 'Bitte den ausführenden Mitarbeiter auswählen.';
}

if ($orderedBy <= 0) {
    $errors[] = 'Bitte angeben, welcher Mitarbeiter es angeordnet hat.';
}

if ($timeFrom === '' || $timeTo === '') {
    $errors[] = 'Bitte Start- und Endzeit angeben.';
}

// Zeiten prüfen + Dauer in Minuten berechnen
$durationMin = null;
if ($timeFrom !== '' && $timeTo !== '') {
    if (!preg_match('/^\d{2}:\d{2}$/', $timeFrom) || !preg_match('/^\d{2}:\d{2}$/', $timeTo)) {
        $errors[] = 'Zeitformat ist ungültig (HH:MM erwartet).';
    } else {
        [$sh, $sm] = array_map('intval', explode(':', $timeFrom));
        [$eh, $em] = array_map('intval', explode(':', $timeTo));

        $minFrom = $sh * 60 + $sm;
        $minTo   = $eh * 60 + $em;
        $diff    = $minTo - $minFrom;

        if ($diff <= 0) {
            $errors[] = 'Endzeit muss nach der Startzeit liegen.';
        } else {
            $durationMin = $diff;      // Minuten
        }
    }
}

if (!empty($errors)) {
    echo json_encode([
        'ok'  => false,
        'msg' => implode(' ', $errors),
    ]);
    exit;
}

// Fallback-Einheit, falls leer
if ($unit === '') {
    $unit = 'Stk';
}

// --------------------------------------------------
// Speichern in qc_100_waste
//   erwartete Spalten:
//   waste_type, unit, quantity,
//   employee_id, employee2_id, ordered_by_id,
//   forklift_required, time_start, time_end,
//   duration_min, comment, created_at, created_by
// --------------------------------------------------
try {
    $sql = "
      INSERT INTO qc_100_waste
        (waste_type,
         unit,
         quantity,
         employee_id,
         employee2_id,
         ordered_by_id,
         forklift_required,
         time_start,
         time_end,
         duration_min,
         comment,
         created_at,
         created_by)
      VALUES
        (:waste_type,
         :unit,
         :quantity,
         :emp1,
         :emp2,
         :ordered_by,
         :forklift_required,
         :time_start,
         :time_end,
         :duration_min,
         :comment,
         NOW(),
         :created_by)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':waste_type'        => $wasteType,
        ':unit'              => $unit,
        ':quantity'          => $quantity,
        ':emp1'              => $emp1,
        ':emp2'              => $emp2 > 0 ? $emp2 : null,
        ':ordered_by'        => $orderedBy > 0 ? $orderedBy : null,
        ':forklift_required' => $forkliftRequired,
        ':time_start'        => $timeFrom,
        ':time_end'          => $timeTo,
        ':duration_min'      => $durationMin,
        ':comment'           => $comment !== '' ? $comment : null,
        ':created_by'        => (int)$userId,
    ]);

    echo json_encode([
        'ok'   => true,
        'msg'  => 'Müll-Erfassung gespeichert.',
        'data' => ['id' => (int)$pdo->lastInsertId()],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => 'Fehler beim Speichern: ' . $e->getMessage(),
    ]);
}
