<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__); // /LKW
require $ROOT . '/inc/session.php';
require $ROOT . '/api/_db.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','standortleiter'], true)) {
  http_response_code(403);
  echo "forbidden";
  exit;
}

$allowed = ['W1','X3','X3(B)','G9','B1','B1(T)','Sarajevo','UNBEKANNT'];

$type      = (string)($_GET['type'] ?? 'summary'); // summary | flags
$lgRaw     = trim((string)($_GET['lg'] ?? ''));
$halle     = trim((string)($_GET['halle'] ?? ''));
$onlyFlags = (($_GET['only_flags'] ?? '') === '1'); // wirkt nur bei summary

$sel = [];
if ($lgRaw !== '') {
  $parts = array_filter(array_map('trim', explode(',', $lgRaw)));
  $sel = array_values(array_intersect($parts, $allowed));
}

// Join auf Stammdaten (neueste lagergruppe je sachnummer)
$SN_JOIN = "
  LEFT JOIN (
    SELECT
      sachnummer,
      SUBSTRING_INDEX(
        GROUP_CONCAT(lagergruppe ORDER BY updated_at DESC SEPARATOR ','),
        ',', 1
      ) AS lagergruppe
    FROM sachnummern
    GROUP BY sachnummer
  ) sn ON sn.sachnummer = ls.sachnummer
";

$LG_EXPR = "COALESCE(NULLIF(sn.lagergruppe,''),'UNBEKANNT')";

$where  = "ls.deleted_at IS NULL";
$params = [];

if ($halle !== '') {
  $where .= " AND ls.halle = ?";
  $params[] = $halle;
}

$COND_QTY = "(ls.karton_soll IS NOT NULL AND ls.menge <> ls.karton_soll)";
$COND_MIS = "(sn.lagergruppe IS NOT NULL AND sn.lagergruppe <> '' AND sn.lagergruppe <> ls.zone)";
$COND_ANY = "(($COND_QTY) OR ($COND_MIS))";

// LG-Filter (auf Soll-LG)
$lgFilter = '';
if (!empty($sel)) {
  $ph = implode(',', array_fill(0, count($sel), '?'));
  $lgFilter = " AND $LG_EXPR IN ($ph)";
  $params = array_merge($params, $sel);
}

$sep = ';';
$filename = 'lagerbestand_' . $type . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');

// =====================
// EXPORT: FLAGS (Abweichungen)
// =====================
if ($type === 'flags') {
  $sql = "
    SELECT
      ls.halle,
      $LG_EXPR AS soll_lg,
      ls.zone  AS ist_zone,
      ls.reihe,
      ls.platz,
      ls.referenznr,
      ls.sachnummer,
      ls.menge,
      ls.karton_soll,
      ($COND_QTY) AS flag_qty,
      ($COND_MIS) AS flag_misplaced,
      ls.eingelagert_am,
      ls.user_name
    FROM lager_slots ls
    $SN_JOIN
    WHERE $where
      AND $COND_ANY
      $lgFilter
    ORDER BY soll_lg, ls.zone, ls.reihe, ls.platz
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  fputs($out,
    "Halle{$sep}Soll_LG{$sep}Ist_Zone{$sep}Reihe{$sep}Platz{$sep}Referenznr{$sep}Sachnummer{$sep}Menge{$sep}Karton_Soll{$sep}Flag_Qty{$sep}Flag_Misplaced{$sep}Eingelagert_am{$sep}User\n"
  );

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputs($out,
      ($r['halle'] ?? '') . $sep .
      ($r['soll_lg'] ?? '') . $sep .
      ($r['ist_zone'] ?? '') . $sep .
      ($r['reihe'] ?? '') . $sep .
      (string)($r['platz'] ?? '') . $sep .
      (string)($r['referenznr'] ?? '') . $sep .
      (string)($r['sachnummer'] ?? '') . $sep .
      (string)($r['menge'] ?? '') . $sep .
      (string)($r['karton_soll'] ?? '') . $sep .
      (string)($r['flag_qty'] ?? 0) . $sep .
      (string)($r['flag_misplaced'] ?? 0) . $sep .
      (string)($r['eingelagert_am'] ?? '') . $sep .
      (string)($r['user_name'] ?? '') . "\n"
    );
  }

  fclose($out);
  exit;
}

// =====================
// EXPORT: SUMMARY
// =====================
if ($onlyFlags) {
  $where .= " AND $COND_ANY";
}

$sql = "
  SELECT
    $LG_EXPR AS lg,
    COUNT(*) AS paletten,
    COALESCE(SUM(ls.menge),0) AS stueck,
    COUNT(DISTINCT ls.sachnummer) AS sachnr,
    SUM($COND_QTY) AS qty_mismatch,
    SUM($COND_MIS) AS misplaced,
    SUM($COND_ANY) AS flags_any
  FROM lager_slots ls
  $SN_JOIN
  WHERE $where
  $lgFilter
  GROUP BY lg
  ORDER BY lg
";

$st = $pdo->prepare($sql);
$st->execute($params);

fputs($out, "LG{$sep}Paletten{$sep}Stueck{$sep}Sachnr{$sep}QtyMismatch{$sep}Misplaced{$sep}FlagsAny\n");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  fputs($out,
    ($r['lg'] ?? '') . $sep .
    (string)($r['paletten'] ?? 0) . $sep .
    (string)($r['stueck'] ?? 0) . $sep .
    (string)($r['sachnr'] ?? 0) . $sep .
    (string)($r['qty_mismatch'] ?? 0) . $sep .
    (string)($r['misplaced'] ?? 0) . $sep .
    (string)($r['flags_any'] ?? 0) . "\n"
  );
}

fclose($out);
