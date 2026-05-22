<?php
declare(strict_types=1);

header('Cache-Control: no-store');

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$halle = trim((string)($data['halle'] ?? 'H3'));
$zone  = trim((string)($data['zone']  ?? 'W1'));
$user  = trim((string)($data['user']  ?? ''));
$stamp = trim((string)($data['stamp'] ?? date('d.m.Y H:i')));

$rows  = $data['rows'] ?? [];
if (!is_array($rows) || count($rows) === 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  exit("Keine Reihen übergeben.");
}

$day = date('Y-m-d');
$filename = "inventur_etiketten_{$halle}_{$zone}_{$day}.doc";

header('Content-Type: application/msword; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Layout: 2 Spalten Etiketten pro Zeile
// Größe kannst du an dein Etikettenpapier anpassen:
$labelW = '90mm';
$labelH = '35mm';

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
            xmlns:w="urn:schemas-microsoft-com:office:word"
            xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">
<style>
@page { margin: 10mm; }
body { font-family: Arial, sans-serif; font-size: 10pt; }
table.sheet { width:100%; border-collapse: separate; border-spacing: 6mm 4mm; }
td.label {
  width: ' . $labelW . ';
  height:' . $labelH . ';
  border: 1px dashed #999;
  vertical-align: top;
  padding: 4mm;
}
.big { font-size: 14pt; font-weight: 700; }
.small { font-size: 9pt; color:#333; }
.line { margin-top: 2mm; border-top: 1px solid #ddd; padding-top: 2mm; }
.box { display:inline-block; width: 5mm; height: 5mm; border:1px solid #111; vertical-align: middle; margin-right:2mm; }
</style></head><body>';

echo '<div style="margin-bottom:6mm;">
  <div style="font-size:12pt;font-weight:700;">Inventur Etiketten</div>
  <div class="small">Halle: <b>' . e($halle) . '</b> · Zone: <b>' . e($zone) . '</b> · Datum: <b>' . e($stamp) . '</b>' . ($user ? ' · User: <b>'.e($user).'</b>' : '') . '</div>
</div>';

echo '<table class="sheet"><tr>';

$col = 0;
foreach ($rows as $it) {
  $row  = trim((string)($it['row'] ?? ''));
  $label= trim((string)($it['label'] ?? 'Reihe ' . $row));
  if ($row === '') continue;

  echo '<td class="label">
    <div class="big">' . e($label) . '</div>
    <div class="small">H' . e($halle) . ' / ' . e($zone) . ' · Reihe ' . e($row) . '</div>

    <div class="line small">
      <span class="box"></span> gezählt / geprüft
      <span style="margin-left:6mm;">Unterschrift: __________________</span>
    </div>

    <div class="small" style="margin-top:2mm;">Bemerkung: __________________________________________</div>
  </td>';

  $col++;
  if ($col % 2 === 0) echo '</tr><tr>'; // 2 Spalten
}

echo '</tr></table>';
echo '</body></html>';
