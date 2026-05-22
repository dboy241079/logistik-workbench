<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/api/_db.php"; // -> /LKW/api/_db.php
$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
if (!$pdo || !($pdo instanceof PDO)) { http_response_code(500); exit("DB nicht verfügbar."); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Macht aus DB-Werten eine Browser-taugliche URL.
 * - Falls DB aus Versehen Dateisystempfade speichert (/users/.../www/...), wird auf Webpfad gekürzt.
 * - Falls DB nur "uploads/xyz.jpg" speichert, wird "/LKW/Container/" davor gesetzt.
 */
function normalize_img_url(string $url): string {
  $url = trim($url);
  if ($url === '') return '';

  // Dateisystempfad -> Webpfad
  $pos = strpos($url, '/www/');
  if ($pos !== false) {
    $url = substr($url, $pos + 4); // "/www" abschneiden -> bleibt "/LKW/..."
  }

  // relative Pfade absichern
  if (!preg_match('~^https?://~i', $url) && $url[0] !== '/') {
    $url = '/Container/' . ltrim($url, '/');
  }

  return $url;
}

$code  = strtoupper(trim((string)($_GET['code'] ?? '')));
$auto  = (int)($_GET['auto'] ?? 0);   // 1 = automatisch drucken
$close = (int)($_GET['close'] ?? 0);  // 1 = nach Druck schließen

if (!preg_match('/^C\d{2}$/', $code)) { http_response_code(400); exit("Ungültiger Container-Code."); }

// Master
$st = $pdo->prepare("SELECT capacity FROM container_master WHERE code=?");
$st->execute([$code]);
$capacity = (int)$st->fetchColumn();
if ($capacity <= 0) { http_response_code(404); exit("Container nicht gefunden."); }

// Items
$st = $pdo->prepare("
  SELECT id, pos, referenznr, sachnummer, lieferschein, menge
  FROM container_pallets
  WHERE container_code=?
  ORDER BY pos ASC
");
$st->execute([$code]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$usedCount = count($items);
$totalQty  = 0;
foreach ($items as $it) $totalQty += (int)($it['menge'] ?? 1);

// Gruppen
$st = $pdo->prepare("
  SELECT sachnummer, COUNT(*) AS pos_count, SUM(menge) AS qty_sum
  FROM container_pallets
  WHERE container_code=?
  GROUP BY sachnummer
  ORDER BY sachnummer ASC
");
$st->execute([$code]);
$groups = $st->fetchAll(PDO::FETCH_ASSOC);

// Bilder (falls vorhanden)
$img1 = '';
$img2 = '';
try {
  $st = $pdo->prepare("SELECT slot, url FROM container_images WHERE container_code=? ORDER BY slot ASC");
  $st->execute([$code]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $slot = (int)($row['slot'] ?? 0);
    $url  = normalize_img_url((string)($row['url'] ?? ''));
    if ($slot === 1) $img1 = $url;
    if ($slot === 2) $img2 = $url;
  }
} catch (Throwable $e) {
  // Tabelle nicht da -> einfach ohne Bilder
}

$now = date('d.m.Y H:i');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Druck - <?=h($code)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    @page { size: A4; margin: 12mm; }
    @media print { .no-print { display:none !important; } }
    body { background:#fff; }
    .header-card{ border:1px solid #ddd; border-radius:12px; padding:12px; }
    .muted{ color:#6c757d; }
    .imgBox{ border:1px solid #ddd; border-radius:12px; overflow:hidden; height:160px; background:#f8f9fa; }
    .imgBox img{ width:100%; height:100%; object-fit:cover; display:block; }
    table{ font-size:12px; }
    .nowrap{ white-space:nowrap; }
  </style>
</head>

<body class="p-3">
  <div class="container-fluid">

    <div class="d-flex align-items-start justify-content-between gap-2 no-print mb-2">
      <div>
        <div class="fw-semibold">Druckansicht</div>
        <div class="muted small"><?=h($now)?></div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-primary" onclick="window.print()">Drucken</button>
      </div>
    </div>

    <div class="header-card mb-3">
      <div class="row g-2 align-items-center">
        <div class="col-md-6">
          <div class="h4 mb-1">Container <?=h($code)?></div>
          <div class="muted">
            Kapazität: <b><?= (int)$capacity ?></b> |
            Belegt: <b><?= (int)$usedCount ?></b> |
            Gesamtstückzahl: <b><?= (int)$totalQty ?></b>
          </div>
        </div>

        <div class="col-6">
  <div class="imgBox">
    <?php if ($img1): ?>
      <img src="<?= h($img1) ?>?t=<?= time() ?>" alt="Foto 1">
    <?php else: ?>
      <div class="p-2 muted small">Kein Foto 1</div>
    <?php endif; ?>
  </div>
</div>

<div class="col-6">
  <div class="imgBox">
    <?php if ($img2): ?>
      <img src="<?= h($img2) ?>?t=<?= time() ?>" alt="Foto 2">
    <?php else: ?>
      <div class="p-2 muted small">Kein Foto 2</div>
    <?php endif; ?>
  </div>
</div>


      </div>
    </div>

    <div class="mb-3">
      <div class="fw-semibold mb-2">Übersicht nach Sachnummer</div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr>
              <th>Sachnummer</th>
              <th class="nowrap">Positionen</th>
              <th class="nowrap">Stückzahl</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groups): ?>
              <tr><td colspan="3" class="muted">Keine Einträge</td></tr>
            <?php else: foreach ($groups as $g): ?>
              <tr>
                <td><?=h((string)($g['sachnummer'] ?? ''))?></td>
                <td class="nowrap"><?= (int)($g['pos_count'] ?? 0) ?></td>
                <td class="nowrap"><?= (int)($g['qty_sum'] ?? 0) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div>
      <div class="fw-semibold mb-2">Inhalt (Detail)</div>
      <div class="table-responsive">
        <table class="table table-sm table-striped table-bordered">
          <thead class="table-light">
            <tr>
              <th class="nowrap">Pos</th>
              <th>Referenz</th>
              <th>Sachnummer</th>
              <th>Lieferschein</th>
              <th class="nowrap">Menge</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="5" class="muted">Container ist leer.</td></tr>
            <?php else: foreach ($items as $it): ?>
              <tr>
                <td class="nowrap"><?= (int)($it['pos'] ?? 0) ?></td>
                <td class="nowrap"><?=h((string)($it['referenznr'] ?? ''))?></td>
                <td class="nowrap"><?=h((string)($it['sachnummer'] ?? ''))?></td>
                <td class="nowrap"><?=h((string)($it['lieferschein'] ?? ''))?></td>
                <td class="nowrap"><?= (int)($it['menge'] ?? 1) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

<script>
(() => {
  const AUTO  = <?= (int)$auto ?> === 1;
  const CLOSE = <?= (int)$close ?> === 1;

  async function waitForImages(timeoutMs){
    const imgs = [...document.images].filter(i => i.src);
    if (!imgs.length) return;

    const waiters = imgs.map(img => {
      if (img.complete && img.naturalWidth > 0) return Promise.resolve();
      return new Promise(res => {
        img.addEventListener("load", res, { once:true });
        img.addEventListener("error", res, { once:true });
      });
    });

    await Promise.race([
      Promise.all(waiters),
      new Promise(res => setTimeout(res, timeoutMs))
    ]);
  }

  window.addEventListener("afterprint", () => {
    if (AUTO && CLOSE) {
      try { window.close(); } catch(e) {}
    }
  });

  if (AUTO) {
    window.addEventListener("load", async () => {
      await new Promise(r => setTimeout(r, 200)); // Layout stabilisieren
      await waitForImages(6000);                  // Bilder abwarten
      window.print();
    });
  }
})();
</script>

</body>
</html>
