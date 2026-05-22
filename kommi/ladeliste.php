<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

$AUTH_DEFAULT_TAB   = 'outbound';
$AUTH_ALLOWED_ROLES = ['admin','disposition','staplerfahrer','verpacker'];
$AUTH_REQUIRE_EMBED = true;
require __DIR__ . '/../inc/auth_embed.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sachKey(string $s): string {
  // "05C 145 785 D" -> "05C145785D"
  return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($s))) ?? '';
}

$ausgangNr = trim((string)($_GET['ausgang_nr'] ?? ''));
if ($ausgangNr === '') {
  http_response_code(400);
  echo 'ausgang_nr fehlt';
  exit;
}

// Order über Ausgangsnummer finden
$st = $pdo->prepare("
  SELECT *
  FROM kommi_orders
  WHERE source_ausgang_nr = ?
  ORDER BY id DESC
  LIMIT 1
");
$st->execute([$ausgangNr]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  http_response_code(404);
  echo 'Kein Auftrag zur Ausgangsnummer gefunden.';
  exit;
}

$orderId  = (int)($order['id'] ?? 0);
$orderNo  = (string)($order['order_no'] ?? '');
$exitGate = (int)($order['exit_gate'] ?? 0);

$preparerUser = trim((string)($order['assigned_picker'] ?? ''));
$loaderUser   = trim((string)($order['assigned_loader'] ?? ''));

// Standard: Username anzeigen (einfach und robust)
$preparerName = $preparerUser;
$loaderName   = $loaderUser;
$preparedSignaturePath = trim((string)($order['prepared_signature_path'] ?? ''));
$preparedSignedAt      = trim((string)($order['prepared_signed_at'] ?? ''));
$preparedSignatureName = trim((string)($order['prepared_signature_name'] ?? ''));

$loadedSignaturePath   = trim((string)($order['loaded_signature_path'] ?? ''));
$loadedSignedAt        = trim((string)($order['loaded_signed_at'] ?? ''));
$loadedSignatureName   = trim((string)($order['loaded_signature_name'] ?? ''));

// Lines laden (Sachnummer + Menge)
$st = $pdo->prepare("
  SELECT sachnummer, qty_required, qty_reserved
  FROM kommi_order_lines
  WHERE order_id = ?
  ORDER BY sachnummer
");
$st->execute([$orderId]);
$lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// Mehrere Lieferscheine pro Sachnummer aus warenausgang holen
// Ausgabe später untereinander
// ---------------------------------------------------------
$lieferscheineBySach = [];

try {
  // Wenn "id" in warenausgang existiert, behalten wir so die Eingabereihenfolge
  // Falls nicht, "ORDER BY id ASC" entfernen oder anpassen.
  $st = $pdo->prepare("
    SELECT sachnummer, lieferschein
    FROM warenausgang
    WHERE ausgang_nr = ?
    ORDER BY id ASC
  ");
  $st->execute([$ausgangNr]);
  $waRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($waRows as $r) {
    $waSach = trim((string)($r['sachnummer'] ?? ''));
    $waLs   = trim((string)($r['lieferschein'] ?? ''));

    if ($waSach === '') continue;

    $k = sachKey($waSach);
    if ($k === '') continue;

    if (!isset($lieferscheineBySach[$k])) {
      $lieferscheineBySach[$k] = [];
    }

    // Nur nicht-leere Lieferscheine aufnehmen, doppelte vermeiden
    if ($waLs !== '' && !in_array($waLs, $lieferscheineBySach[$k], true)) {
      $lieferscheineBySach[$k][] = $waLs;
    }
  }
} catch (Throwable $e) {
  // Fallback: Seite soll weiter laufen
  error_log('ladeliste.php lieferschein-map warning: ' . $e->getMessage());
}

// Menge-GB: qty_reserved, fallback qty_required
$totalGb = 0;
foreach ($lines as $ln) {
  $q = (int)($ln['qty_reserved'] ?? 0);
  if ($q <= 0) $q = (int)($ln['qty_required'] ?? 0);
  $totalGb += $q;
}

// Werte aus Warenausgang (per URL übergeben)
$shipper = trim((string)($_GET['shipper'] ?? $_GET['spediteur'] ?? ''));
$licence = trim((string)($_GET['licence'] ?? $_GET['kennzeichen'] ?? ''));
$lieferscheinFallback = trim((string)($_GET['lieferschein'] ?? $_GET['ls'] ?? ''));

// ---------------------------------------------------------
// Gedruckte Tabellenzeilen zählen (wichtig bei Mehrfach-LS)
// ---------------------------------------------------------
$printedRows = 0;
foreach ($lines as $lnTmp) {
  $sachTmp = (string)($lnTmp['sachnummer'] ?? '');
  $tmpList = $lieferscheineBySach[sachKey($sachTmp)] ?? [];

  if (!$tmpList && $lieferscheinFallback !== '') {
    $tmpList = [$lieferscheinFallback];
  }
  if (!$tmpList) {
    $tmpList = [''];
  }

  $printedRows += count($tmpList);
}

$targetRows = 20; // gewünschte Gesamtzeilen im Formular
$emptyRows  = max(0, $targetRows - $printedRows);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Ladeliste – <?= h($orderNo ?: ('Ausgang ' . $ausgangNr)) ?></title>

<style>
  @page { size: A4; margin: 10mm; }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    padding: 0;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
  }

  .no-print {
    margin: 10px;
  }

  @media print {
    .no-print { display: none !important; }
  }

  .sheet {
    margin: 10px;
    padding: 10px;
    border: 2px solid #111;
    box-sizing: border-box;
  }

  /* Logo bleibt allein oben (100%) */
  .top {
    display: block;
  }

  .logo {
    border: 1px solid #111;
    border-radius: 8px;
    padding: 10px;
    min-height: 140px;
    text-align: center;
    font-weight: 800;
    letter-spacing: 1px;
    font-size: 22px;
    line-height: 1.1;
  }

  .logo small {
    display: block;
    margin-top: 2px;
    font-weight: 600;
    font-size: 11px;
    letter-spacing: .5px;
  }

  .logo-addr {
    margin-top: 8px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
    letter-spacing: 0;
  }

  .logo-contact {
    margin-top: 6px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.35;
    letter-spacing: 0;
  }

  /* 3 Info-Boxen nebeneinander */
  .info-row {
    margin-top: 10px;
    display: flex;
    gap: 12px;
    align-items: stretch;
    page-break-inside: avoid;
    break-inside: avoid;
  }

  .info-col {
    flex: 1 1 0;
    max-width: calc((100% - 24px) / 3);
    display: flex;
    align-items: stretch;
  }

  .info-card {
    width: 100%;
    height: 100%;
    min-height: 120px;
    border: 1px solid #111;
    border-radius: 8px;
    padding: 8px;
    font-size: 12px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
  }

  .info-card .line + .line {
    margin-top: 8px;
  }

  .info-card .lbl {
    display: block;
    margin-bottom: 4px;
    color: #222;
    font-size: 11px;
  }

  .info-card .val {
    font-weight: 600;
    word-break: break-word;
  }

  .sender-box .line,
  .meta-mini .line {
    line-height: 1.35;
  }

  /* Druck-Stabilität */
  tr, td, th {
    page-break-inside: avoid;
    break-inside: avoid;
  }

  thead { display: table-header-group; }
  tfoot { display: table-footer-group; }

  .footer,
  .signature-box {
    page-break-inside: avoid;
    break-inside: avoid;
  }

  /* Mobile nur auf Screen */
  @media screen and (max-width: 900px) {
    .info-row {
      flex-direction: column;
    }

    .info-col {
      max-width: 100%;
    }
  }

  /* Druck: zwingend nebeneinander + gleiche Höhe */
  @media print {
    .info-row {
      display: flex !important;
      flex-direction: row !important;
      gap: 8px !important;
      align-items: stretch !important;
    }

    .info-col {
      display: flex !important;
      align-items: stretch !important;
      flex: 1 1 0 !important;
      max-width: calc((100% - 16px) / 3) !important;
    }

    .info-card {
      height: 100% !important;
      min-height: 100px !important;
      border-radius: 8px !important;
    }
  }

  table {
    width: 100%;
    margin-top: 8px;
    border-collapse: collapse;
    font-size: 12px;
  }

  th, td {
    border: 1px solid #111;
    padding: 6px;
    vertical-align: top;
  }

  th {
    background: #f3f3f3;
    text-align: left;
  }

  .col-sach   { width: 32%; }
  .col-menge  { width: 12%; text-align: right; }
  .col-ls     { width: 18%; }
  .col-prep   { width: 19%; }
  .col-loader { width: 19%; }

  .footer {
    margin-top: 8px;
    border: 1px solid #111;
    padding: 8px;
    font-size: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
  }

  .mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  }

 
</style>
</head>
<body>

<div class="no-print">
  <button type="button" onclick="window.print()">🖨️ Drucken</button>
  <span style="margin-left:8px; color:#666;">A4 – Ladeliste</span>
</div>

<div class="sheet">

  <div class="top">
    <!-- LOGO bleibt oben 100% -->
    <div class="logo">
      <img src="/Bilder/logo_standard_tpo.svg" alt="TEAMPROJEKT OUTSOURCING" style="max-width:170px; height:auto;">
      <small>TEAM OUTSOURCING</small>

      <div class="logo-addr">
        Am Prime Parc 17<br>
        65479 Raunheim
      </div>

      <div class="logo-contact">
        <div><strong>Email:</strong> kontakt@teamprojekt-outsourcing.de</div>
        <div><strong>Tel:</strong> +49 6142 / 83 78 60</div>
      </div>
    </div>
  </div>

  <!-- 3x 33% nebeneinander -->
  <div class="info-row">

    <!-- Spediteur / Kennzeichen -->
    <div class="info-col">
      <div class="info-card top-right">
        <div class="line">
          <span class="lbl">Spediteur / Shipper</span>
          <div class="val"><?= h($shipper ?: '—') ?></div>
        </div>

        <div class="line">
          <span class="lbl">Kennzeichen / Licence number</span>
          <div class="val"><?= h($licence ?: '—') ?></div>
        </div>
      </div>
    </div>

    <!-- Auftrag / Ausgang -->
    <div class="info-col">
      <div class="info-card meta-mini">
        <div class="line">
          <span class="lbl">Auftrag</span>
          <div class="val mono"><?= h($orderNo !== '' ? $orderNo : ('#' . $orderId)) ?></div>
        </div>

        <div class="line">
          <span class="lbl">Ausgangsnummer</span>
          <div class="val mono"><?= h($ausgangNr) ?></div>
        </div>

        <div class="line">
          <span class="lbl">Ausgang</span>
          <div class="val"><?= $exitGate > 0 ? 'Ausgang ' . $exitGate : '—' ?></div>
        </div>
      </div>
    </div>

    <!-- Versender -->
    <div class="info-col">
      <div class="info-card sender-box">
        <div class="line">
          <span class="lbl">Versender</span>
          <div class="val">Teamprojekt Outsourcing</div>
          <div>Lise-Meitner-Straße 21</div>
          <div>31515 Wunstorf</div>
        </div>
      </div>
    </div>
  </div><!-- /.info-row -->

  <table>
    <thead>
      <tr>
        <th class="col-sach">Sachnummer</th>
        <th class="col-menge">Menge - GB</th>
        <th class="col-ls">Lieferschein</th>
        <th class="col-prep">Bereitsteller</th>
        <th class="col-loader">Verlader</th>
      </tr>
    </thead>
   <tbody>
  <?php if (!$lines): ?>
    <tr>
      <td colspan="5" style="color:#666;">Keine Positionen</td>
    </tr>
  <?php else: ?>
    <?php foreach ($lines as $ln): ?>
      <?php
        $sach = (string)($ln['sachnummer'] ?? '');
        $qty  = (int)($ln['qty_reserved'] ?? 0);
        if ($qty <= 0) $qty = (int)($ln['qty_required'] ?? 0);

        $lsList = $lieferscheineBySach[sachKey($sach)] ?? [];

        // Fallback, wenn zu Sachnummer keine LS gefunden wurden
        if (!$lsList && $lieferscheinFallback !== '') {
          $lsList = [$lieferscheinFallback];
        }
        if (!$lsList) {
          $lsList = [''];
        }

        $lsCount = count($lsList);

        // Menge auf Lieferscheine verteilen
        // Beispiel: qty=5, lsCount=2 => 3 + 2
        $baseQty = $lsCount > 0 ? intdiv($qty, $lsCount) : $qty;
        $restQty = $lsCount > 0 ? ($qty % $lsCount) : 0;
      ?>

      <?php foreach ($lsList as $idx => $lsLine): ?>
        <?php
          $rowQty = $baseQty + ($idx < $restQty ? 1 : 0);
        ?>
        <tr>
          <td class="mono"><?= h($sach) ?></td>
          <td class="col-menge"><?= $rowQty ?></td>
          <td><?= h($lsLine !== '' ? $lsLine : '—') ?></td>
          <td><?= h($preparerName ?: '—') ?></td>
          <td><?= h($loaderName ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- leere Zeilen wie Papierformular -->
  <?php for ($i = 0; $i < $emptyRows; $i++): ?>
    <tr>
      <td>&nbsp;</td>
      <td></td>
      <td></td>
      <td></td>
      <td></td>
    </tr>
  <?php endfor; ?>
</tbody>
  </table>

  <div class="footer">
    <div><strong>Behältermenge auf dem LKW insgesamt:</strong></div>
    <div class="mono" style="font-size:14px;"><strong><?= $totalGb ?></strong></div>
  </div>

  <div class="signature-box" style="margin-top:8px; border:1px solid #111; padding:8px; font-size:12px;">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div><strong>Erstellt am:</strong> <?= date('d.m.Y H:i') ?> Uhr</div>
    <div><strong>Ausgangsnummer:</strong> <span class="mono"><?= h($ausgangNr) ?></span></div>
  </div>

  <div style="margin-top:14px; display:flex; gap:16px; align-items:flex-start;">
    <!-- Bereitsteller -->
    <div style="flex:1;">
      <?php if ($preparedSignaturePath !== ''): ?>
        <div style="height:46px; display:flex; align-items:flex-end; border-bottom:1px solid #111; padding-bottom:2px;">
          <img src="<?= h($preparedSignaturePath) ?>"
               alt="Unterschrift Bereitsteller"
               style="max-height:40px; max-width:100%; object-fit:contain;">
        </div>
        <div style="margin-top:4px;">
          Unterschrift Bereitsteller
          <?php if ($preparedSignatureName !== ''): ?> – <?= h($preparedSignatureName) ?><?php endif; ?>
          <?php if ($preparedSignedAt !== ''): ?> (<?= h($preparedSignedAt) ?>)<?php endif; ?>
        </div>
      <?php else: ?>
        <div style="border-bottom:1px solid #111; height:26px;"></div>
        <div style="margin-top:4px;">
          Unterschrift Bereitsteller
          <?php if ($preparerName !== ''): ?> – <?= h($preparerName) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Verlader -->
    <div style="flex:1;">
      <?php if ($loadedSignaturePath !== ''): ?>
        <div style="height:46px; display:flex; align-items:flex-end; border-bottom:1px solid #111; padding-bottom:2px;">
          <img src="<?= h($loadedSignaturePath) ?>"
               alt="Unterschrift Verlader"
               style="max-height:40px; max-width:100%; object-fit:contain;">
        </div>
        <div style="margin-top:4px;">
          Unterschrift Verlader
          <?php if ($loadedSignatureName !== ''): ?> – <?= h($loadedSignatureName) ?><?php endif; ?>
          <?php if ($loadedSignedAt !== ''): ?> (<?= h($loadedSignedAt) ?>)<?php endif; ?>
        </div>
      <?php else: ?>
        <div style="border-bottom:1px solid #111; height:26px;"></div>
        <div style="margin-top:4px;">
          Unterschrift Verlader
          <?php if ($loaderName !== ''): ?> – <?= h($loaderName) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>

</body>
</html>