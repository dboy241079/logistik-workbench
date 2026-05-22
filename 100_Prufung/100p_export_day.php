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

// Datum aus Parameter
$date = $_GET['date'] ?? date('Y-m-d');

// Datensätze für genau diesen Tag holen
$sql = "
  SELECT 
    q.*,
    COALESCE(u.display_name, u.username, CONCAT('ID ', q.employee_id)) AS mitarbeiter
  FROM qc_100_pruefungen q
  LEFT JOIN users u ON q.employee_id = u.id
  WHERE DATE(q.created_at) = :d
  ORDER BY q.created_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':d' => $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = '100p_Tag_' . $date . '.csv';

// CSV-Header
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM für Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// 🎨 „schönere“ Kopfzone
$dt = new DateTimeImmutable($date);
$niceDate = $dt->format('d.m.Y');

// Titelzeile
fputcsv($out, ["100%-Prüfungen – Tagesexport", "", "", "", ""], ';');
// Datumszeile
fputcsv($out, ["Datum", $niceDate, "", "", ""], ';');
// Leerzeile
fputcsv($out, ["", "", "", "", ""], ';');

// Spaltenüberschriften (A–E)
fputcsv(
    $out,
    ['Sachnummer', 'Referenznummer', 'Datum', 'Prüfer', 'Bemerkung'],
    ';'
);

foreach ($rows as $r) {
    $created = new DateTimeImmutable($r['created_at']);

    // A: Sachnummer
    $sachnummer = (string)($r['material_no'] ?? '');

    // B: Referenznummer → nur Ziffern (damit Excel sicher Zahl erkennt)
    // Wenn du führende Nullen unbedingt behalten willst, diese Zeile NICHT benutzen,
    // sondern lieber nur Komma/Punkt abschneiden.
    $refRaw   = (string)($r['pallet_code'] ?? $r['delivery_note'] ?? '');
    $refClean = preg_replace('/\D+/', '', $refRaw); // alle Nicht-Ziffern raus

    // C: Datum (TT.MM.JJJJ)
    $datum = $created->format('d.m.Y');

    // D: Prüfer
    $pruefer = (string)($r['mitarbeiter'] ?? '');

    // E: Bemerkung
    $bemerkung = (string)($r['comment'] ?? '');

    fputcsv(
        $out,
        [$sachnummer, $refClean, $datum, $pruefer, $bemerkung],
        ';'
    );
}

fclose($out);
exit;
