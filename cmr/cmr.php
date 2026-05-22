<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Berlin');

$AUTH_DEFAULT_TAB   = 'outbound';
$AUTH_ALLOWED_ROLES = ['admin','disposition','staplerfahrer','verpacker'];
$AUTH_REQUIRE_EMBED = false;  // <- damit neuer Tab erlaubt ist
$AUTH_REQUIRE_LOGIN = true;   // falls du das nutzt

error_log("CMR open: ausgang_nr=" . ($_GET['ausgang_nr'] ?? '') . " / session=" . session_id());

require __DIR__ . '/../inc/auth_embed.php';
require __DIR__ . '/../api/_db.php';

function esc(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Baut aus Zeilen HTML mit <br>, jede Zeile wird escaped.
 */
function join_br(array $lines): string {
  $lines = array_values(array_filter(array_map('trim', $lines), static fn($x) => $x !== ''));
  return implode('<br>', array_map(static fn($x) => esc((string)$x), $lines));
}

/**
 * Wandelt DB-ISO (YYYY-MM-DD) in DE (DD.MM.YYYY), falls möglich.
 */
function iso_to_de(string $iso): string {
  $iso = trim($iso);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if ($dt) return $dt->format('d.m.Y');
  }
  return $iso; // fallback
}

$ausgangNr = trim((string)($_GET['ausgang_nr'] ?? ''));
if ($ausgangNr === '') {
  http_response_code(400);
  echo "ausgang_nr fehlt";
  exit;
}

// Tagesdatum (für Feld 3/4/20)
$today = date('d.m.Y');

// ---- Alle Zeilen dieser Ausg.-Nr. laden
$stmt = $pdo->prepare("SELECT * FROM warenausgang WHERE ausgang_nr = :nr ORDER BY id ASC");
$stmt->execute(['nr' => $ausgangNr]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  http_response_code(404);
  echo "Keine Daten für Ausg.-Nr. " . esc($ausgangNr);
  exit;
}

$first = $rows[0];

$lager       = trim((string)($first['lagergruppe'] ?? ''));
$spedition   = '';
$kennzeichen = '';
$datumDb     = '';
$empCode     = '';

$sumKg = 0.0;

$liefers = [];
$goods = [];
$goodsIndex = [];

foreach ($rows as $r) {
  $ls = trim((string)($r['lieferschein'] ?? ''));
  $sn = trim((string)($r['sachnummer'] ?? ''));

  // Stammdaten ziehen
  if ($spedition === '')   $spedition   = trim((string)($r['spedition'] ?? ''));
  if ($kennzeichen === '') $kennzeichen = trim((string)($r['kennzeichen'] ?? ''));
  if ($datumDb === '' && !empty($r['datum'])) $datumDb = (string)$r['datum'];

  if ($empCode === '' && array_key_exists('empfaenger_code', $r)) {
    $empCode = trim((string)($r['empfaenger_code'] ?? ''));
  }

  if ($ls !== '') $liefers[$ls] = true;

  if ($sn === '') continue;

  // Key = Sachnummer(normiert) + Lieferschein
  $k = preg_replace('/[^A-Z0-9]/', '', strtoupper($sn)) . '|' . $ls;

  if (!isset($goodsIndex[$k])) {
    $goodsIndex[$k] = count($goods);
    $goods[] = [
      'sn'      => $sn,
      'ls'      => ($ls !== '' ? $ls : '—'),
      'pallets' => [],   // Palettennummern (distinct)
      'kg'      => 0.0   // Gewicht pro Zeile
    ];
  }

  $idx = $goodsIndex[$k];

  // Palettennummer zählen
  $pno = trim((string)($r['behaelternr'] ?? ''));
  if ($pno !== '') {
    $goods[$idx]['pallets'][$pno] = true;
  } else {
    // Fallback: keine Palettennr -> jede DB-Zeile als 1 zählen
    $fallbackKey = 'row_' . (string)($r['id'] ?? $idx);
    $goods[$idx]['pallets'][$fallbackKey] = true;
  }

  // Gewicht addieren (auch Komma-Werte)
  $w = (float)str_replace(',', '.', trim((string)($r['brt_gew'] ?? '0')));
  $sumKg += $w;
  $goods[$idx]['kg'] += $w;
}

// Sortierung wie Ladeliste
usort($goods, static function($a, $b) {
  $ka = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$a['sn']));
  $kb = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$b['sn']));
  return $ka <=> $kb ?: strcmp((string)$a['ls'], (string)$b['ls']);
});

// qty (= Paletten) final berechnen und pallets rauswerfen
foreach ($goods as &$g) {
  $g['qty'] = max(1, count($g['pallets'] ?? []));
  unset($g['pallets']);
}
unset($g);

// Formatter fürs Gewicht (120 statt 120.0, ansonsten 1 Nachkommastelle mit Komma)
$fmtKg = static function(float $v): string {
  return (abs($v - round($v)) < 0.00001) ? (string)(int)round($v) : number_format($v, 1, ',', '');
};

// Spalten HTML synchron erzeugen
$cmrLsHtml   = join_br(array_map(static fn($g) => (string)$g['ls'],  $goods));
$cmrQtyHtml  = join_br(array_map(static fn($g) => (string)$g['qty'], $goods));
$cmrSachHtml = join_br(array_map(static fn($g) => (string)$g['sn'],  $goods));

$cmrGewHtml = join_br(array_map(static fn($g) => $fmtKg((float)($g['kg'] ?? 0)), $goods));
$cmrGewHtmlWithTotal =
  $cmrGewHtml
  . '<span style="display:block;border-top:1px solid #111;margin-top:4px;padding-top:3px;font-weight:700;">'
  . esc($fmtKg($sumKg))
  . '</span>';
// ---- Feld 16: Spedition + Name (dein Wunsch)
$feld16 = 'Spedition: ' . ($spedition !== '' ? $spedition : '—');

// ---- Empfänger aus cmr_recipients (per Code)
$emp = [
  'name'     => '',
  'address1' => '',
  'address2' => '',
  'postal'   => '',
  'city'     => '',
  'country'  => ''
];

// Optional: wenn empfaenger_code leer ist, aber lagergruppe evtl. ein Code wäre
if ($empCode === '' && $lager !== '') {
  $empCode = $lager;
}

if ($empCode !== '') {
  $st = $pdo->prepare("SELECT * FROM cmr_recipients WHERE code = :c LIMIT 1");
  $st->execute(['c' => $empCode]);
  $dbEmp = $st->fetch(PDO::FETCH_ASSOC);

  if ($dbEmp) {
    $emp['name']     = (string)($dbEmp['name'] ?? '');
    $emp['address1'] = (string)($dbEmp['address1'] ?? '');
    $emp['address2'] = (string)($dbEmp['address2'] ?? '');
    $emp['postal']   = (string)($dbEmp['postal'] ?? '');
    $emp['city']     = (string)($dbEmp['city'] ?? '');
    $emp['country']  = (string)($dbEmp['country'] ?? '');
  }
}

// ---- Absender fix
$abs_name = "TEAMProjekt Outsourcing";
$abs_addr = join_br(["Lager Wunstorf", "Lise-Meitner-Straße 21"]);
$abs_plz  = "31515";
$abs_ort  = "Wunstorf";

// ---- Empfänger zusammensetzen (mit <br>)
$emp_name = $emp['name'];
$emp_addr = join_br([$emp['address1'], $emp['address2']]);
$emp_plz  = $emp['postal'];
$emp_ort  = $emp['city'];

// ---- Orte/Land für Feld 3/4 (dein Wunsch)
$origin_place   = "Wunstorf / Lise-Meitner-Straße 21";
$origin_country = "Deutschland";

// Damit auch dein bisheriges Template ({{auslieferungsort}} / {{uebernahmeort}}) passt:
$auslieferungsort = join_br([$origin_place, $origin_country]);
$uebernahmeort    = join_br([$origin_place, $origin_country]);

// ---- Feld 13 (dein Wunsch)
$lagerPlus = trim($lager . ' ' . $empCode);
$lagerPlus = trim($lagerPlus);
$anweisung = "WA - {$ausgangNr} - {$lagerPlus} - Wunstorf";

// ---- Template laden (dein cmr.html bleibt 1:1!)
$templateFile = __DIR__ . '/cmr.html';
if (!is_file($templateFile)) {
  http_response_code(500);
  echo "Template cmr.html nicht gefunden: " . esc($templateFile);
  exit;
}
$tpl = (string)file_get_contents($templateFile);

// ---- Platzhalter-Map
$vars = [
  // 1 Absender
  'absender_name'    => esc($abs_name),
  'absender_adresse' => $abs_addr,     // enthält <br>
  'absender_plz'     => esc($abs_plz),
  'absender_ort'     => esc($abs_ort),

  // 2 Empfänger
  'empfaenger_name'    => esc($emp_name),
  'empfaenger_adresse' => $emp_addr,   // enthält <br>
  'empfaenger_plz'     => esc($emp_plz),
  'empfaenger_ort'     => esc($emp_ort),

  // 3/4 (kompatibel zu deinem bisherigen Template)
  'auslieferungsort' => $auslieferungsort,
  'uebernahmeort'    => $uebernahmeort,

  // Datum:
  // - {{datum}}: nutzbar überall (auch Feld 20 "am ...")
  // - optional: {{datum_20}} falls du es im Template trennen willst
  'datum'    => esc($today),
  'datum_20' => esc($today),

  // Optional falls du DB-Datum irgendwo brauchst
  'datum_db' => esc(iso_to_de($datumDb)),

  // Falls du dein Template schon auf Ort/Land/Datum pro Feld umgebaut hast:
  'ort_lieu_3'  => esc($origin_place),
  'land_pays_3' => esc($origin_country),
  'datum_3'     => esc($today),

  'ort_lieu_4'  => esc($origin_place),
  'land_pays_4' => esc($origin_country),
  'datum_4'     => esc($today),

  // 5 Beilagen
  'beilagen' => esc("Ladungssicherungsprotokoll"),

  // 6–12 Goods-Zeile
    'verpackung'         => '',                          // Feld 8 leer
    'kennzeichen_nr'     => $cmrLsHtml,
'anzahl_packstuecke' => $cmrQtyHtml,
'bezeichnung_gut'    => $cmrSachHtml,
'bruttogewicht'      => $cmrGewHtmlWithTotal,


  // 13 Anweisung
  'anweisung' => esc($anweisung),

  // 16 (dein Wunsch)
  'spedition' => esc($feld16),

  // 21/25
  'ausgestellt_ort' => esc($origin_place),

  // Kennzeichen (untere Tabelle)
  'kennzeichen' => esc($kennzeichen),
  'nutzlast'    => '',

  // Optional
  'nachfolgende_frachtfuehrer' => '',

    // 16: Referenz/Zusatzinfos
  'spedition_label' => esc('Spedition:'),
  'spedition_name'  => esc($spedition),

];

// ---- Token-Replacer: {{token}} -> Wert
$out = preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function($m) use ($vars) {
  $k = $m[1];
  return $vars[$k] ?? '';
}, $tpl);

echo $out;
