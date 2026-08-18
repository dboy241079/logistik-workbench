<?php
declare(strict_types=1);

/**
 * Unveränderliche Altstände für Revisionen, die vor Einführung des automatischen
 * controlled_archive-Workflows freigegeben wurden.
 *
 * F_059_0001 Rev. 1.0 stammt aus dem Repository-Stand vor der ersten Rev.-2.0-Änderung:
 * Commit ff4519dbf66c74aaaac3e65bcae3bfcd140d15f5
 * Git-Blobs:
 * - druck_wa.html 8c5f621f2d6f183a1c754019bd716980ca99b81f
 * - CSS/drucken.css 574af073c058ec6f74e72118025a2bdd648d4ddc
 * - js/drucken.js bccb4a22ddf1316cf058795ba7a931af48fab99e
 */

function qcLegacySnapshotAvailable(string $documentNo, string $revision): bool
{
    return $documentNo === 'F_059_0001' && $revision === '1.0';
}

function qcLegacySnapshotGet(string $documentNo, string $revision): ?array
{
    if (!qcLegacySnapshotAvailable($documentNo, $revision)) {
        return null;
    }

    $html = <<<'HTML'
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Wareneingang – Formular (2 Seiten)</title>
  <link rel="stylesheet" href="/CSS/drucken.css" />
</head>
<body>
    
  <div class="toolbar no-print">
  <button id="btnBack" class="btn">← Zurück</button>
  <div class="spacer"></div>
</div>


  <!-- ======= Seite 1 ======= -->
  <section class="page a4">
    <header class="head">
      <div class="brand">
        <!-- Logo-Platzhalter – hier könntest du euer PNG/SVG einbinden -->
        <img class="brand-logo" src="/Bilder/logo_standard_tpo.svg" alt="TeamProjekt Outsourcing Logo">
        <div class="addr">
          TeamProjekt-Outsourcing<br>
          Lise-Meitner-Straße 21<br>
          31515 Wunstorf
        </div>
      </div>
      <div class="title">WARE</div>
      <div class="checkgrid">
        <div class="checkgrid">
  <label><input type="checkbox" value="B1"> Ladesäule (B1)</label>
  <label><input type="checkbox" value="BM"> Behältermanagment (BM)</label>
  <label><input type="checkbox" value="Muell"> Müllentsorgung (Müll)</label>
  <label><input type="checkbox" value="W1"> Batteriefertigung (W1)</label>
  <label><input type="checkbox" value="Banking"> Banking (Banking)</label>
  <label><input type="checkbox" value="Sarajevo"> Sarajevo (Sarajevo)</label>
  <label><input type="checkbox" value="BM"> Leergut (BM)</label>
  <label><input type="checkbox" value="X3"> Wärmetauscher (X3)</label>
  <label><input type="checkbox" value="G9"> Gießerei (G9)</label>
</div>

      </div>
    </header>

   <div class="block office">
  <div class="block-head">Auszufüllen durch Büro:</div>
  <div class="grid grid-3">
    <label class="lbl">WF-NR:
      <input class="inp" type="text" id="wfNr" placeholder="1978">
    </label>
    <label class="lbl">Ankunftszeit:
      <input class="inp" type="time" id="ankunft" value="14:45">
    </label>
    <label class="lbl">Datum:
      <input class="inp" type="date" id="datum">
    </label>
  </div>
</div>

    <div class="block">
      <div class="block-head">Wer Liefert?</div>
      

      <div class="grid grid-2-50">
  <label class="lbl">SPEDITION:
    <input class="inp" type="text" id="spedition" placeholder="Tricor">
  </label>
  <label class="lbl">KENNZEICHEN:
    <input class="inp" type="text" id="kennz" placeholder="BRA L 8507">
  </label>
</div>

      <div class="grid grid-2">
        <label class="lbl">BEGINN ENTLADUNG:
          <input class="inp" type="text"> </label>
        <label class="lbl">ENDE ENTLADUNG:
          <input class="inp" type="text">
        </label>
      </div>
    </div>

    <div class="block">
      <div class="block-head">INHALT:</div>
      <div class="grid grid-2-50">
  <label class="lbl">Anzahl gezählt?
    <input class="inp right" type="text">
  </label>
  <label class="lbl">Anzahl laut Lieferschein:
    <input class="inp right" type="number" id="anzahlLs" min="0" value="0">
  </label>
</div>

      <div class="grid grid-2-33-66">
  <div class="lbl">
    Beschädigung der Ware:
    <label class="check-inline"><input type="radio" name="beschaedigt" value="ja"> Ja</label>
    <label class="check-inline"><input type="radio" name="beschaedigt" value="nein" checked> Nein</label>
  </div>
  <label class="lbl">Wenn „JA“, Bemerkung:
    <input class="inp" type="text" id="bemerkung">
  </label>
</div>

      <div class="grid grid-1">
  <label class="lbl">Eingangskontrolle durch Unterschrift:
    <input class="inp" type="text" id="sigMitarb">
  </label>
</div>
    </div>

    <div class="block">
      <div class="block-head">Auszufüllen durch Büro:</div>
     <div class="grid grid-2-50">
  <div class="lbl">
    Ware systemseitig gebucht:
    <label class="check-inline"><input type="checkbox" id="gebuchtJa"> Ja</label>
    <label class="check-inline"><input type="checkbox" id="gebuchtNein"> Nein</label>
  </div>
  <div class="lbl">
    Waren in Tabelle eingetragen?
    <label class="check-inline"><input type="checkbox" id="wepJa"> Ja</label>
    <label class="check-inline"><input type="checkbox" id="wepNein"> Nein</label>
  </div>
</div>

      <div class="grid grid-2">
        <label class="lbl">Wenn NEIN, Bemerkung:
          <input class="inp" type="text" id="bemerkungB">
        </label>
        <label class="lbl">Gebucht am:
          <input class="inp" type="text">
        </label>
      </div>

      <div class="grid grid-2">
        <label class="lbl">Gebucht durch:
          <input class="inp" type="text" id="gebuchtDurch">
        </label>
        <label class="lbl">Unterschrift:
          <input class="inp" type="text" id="sigBuero">
        </label>
      </div>
    </div>

    <aside class="side-note">
      <div>Palettenauflistung<br>bitte auf der<br>Rückseite<br>vermerken</div>
      <div class="arrow">➤</div>
    </aside>
  </section>

  <!-- ======= Seite 2 ======= -->
  <section class="page a4">
    <header class="head small">
      <div class="title">Paletten / KLT – Auflistung (Rückseite)</div>
    </header>

    <div class="table-wrap">
      <table id="tbl" class="tbl">
        <thead>
          <tr>
            <th style="width:14%">LS-Nr.</th>
            <th style="width:10%">Verk</th>
            <th>Sachnummer</th>
            <th style="width:14%">Paletten / KLT</th>
            <th style="width:14%">NO Label</th>
            <th style="width:8%"></th>
          </tr>
        </thead>
        <tbody>
          <!-- Beispielzeile -->
          <tr>
            <td><input type="text" class="cell"></td>
            <td><input type="text" class="cell"></td>
            <td><input type="text" class="cell" placeholder="z. B. GTI4488"></td>
            <td><input type="number" class="cell num pal" min="0" value="0"></td>
            <td class="center"><input type="checkbox"></td>
            <td class="center"><button class="btn-del" title="Zeile löschen">×</button></td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="right">Summe Paletten / KLT:</td>
            <td class="right"><span id="sum">0</span></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="row-controls">
      <button id="addRow" class="btn">+ Zeile</button>
      <button id="clearAll" class="btn ghost">Alle löschen</button>
      <button id="syncToFront" class="btn ghost">Summe → Seite 1</button>
    </div>

    <p class="tiny-hint">Hinweis: Die Summe kann auf Seite 1 in „Gesamt­paletten / KLT (gezählt)“ übertragen werden.</p>
  </section>

  <!-- html2pdf (für PDF-Export) -->
  <script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
  
  <script src="/js/drucken.js"></script>
</body>
</html>
HTML;

    $css = <<<'CSS'
/* ——— Basis ——— */
:root{
  --ink:#111;
  --grid:#444;
  --muted:#6d6d6d;
  --line:#cfcfcf;
  --accent:#0a0a0a;
}
*{ box-sizing:border-box; }
html,body{ margin:0; padding:0; color:var(--ink); font:12px/1.3 "Inter", system-ui, Arial, sans-serif; }
.page.a4{ width:210mm; min-height:297mm; padding:12mm 12mm 16mm; margin:0 auto 8mm; position:relative; background:#fff; outline:1px solid #eee; }
@media print{ body{ background:#fff; } .page.a4{ margin:0; outline:none; break-after:page; } .row-controls, .tiny-hint{ display:none !important; } }
.head{ display:grid; grid-template-columns:1fr auto; gap:8mm; align-items:start; margin-bottom:6mm; }
.head.small{ grid-template-columns:1fr; }
.brand{ display:flex; gap:10mm; align-items:flex-end; }
.logo{ font-weight:800; letter-spacing:.5px; line-height:1.05; }
.logo span{ font-weight:600; font-size:.9em; color:var(--muted); }
.addr{ font-size:10px; color:var(--muted); }
.title{ font-weight:800; letter-spacing:.8px; align-self:flex-start; padding:2mm 4mm; border:1px solid var(--line); }
.block{ border:1px solid var(--line); margin-bottom:6mm; }
.block-head{ background:#f6f6f6; padding:2.5mm 3mm; border-bottom:1px solid var(--line); font-weight:700; letter-spacing:.2px; }
.grid{ display:grid; gap:3mm; padding:3mm; }
.grid-1{ grid-template-columns:1fr; }
.grid-2{ grid-template-columns:1fr 1fr; }
.grid-3{ grid-template-columns:1fr 1fr 1fr; }
.grid-3-2{ grid-template-columns:2fr 1fr; }
.lbl{ display:flex; align-items:center; gap:2mm; }
.lbl > .inp{ flex:1; }
.inp{ width:100%; padding:2.5mm; border:1px solid var(--line); background:#fff; }
.inp.right{ text-align:right; }
.check-inline{ margin-left:6mm; margin-right:6mm; }
.checkgrid{ display:grid; grid-template-columns:repeat(3, max-content); gap:2mm 6mm; align-content:start; padding-top:1mm; }
.checkgrid label{ white-space:nowrap; }
.side-note{ position:absolute; right:0; top:40%; transform:translate(50%,-50%); border:1px solid var(--line); width:36mm; text-align:center; padding:4mm 2mm; background:#fff; font-weight:700; line-height:1.25; }
.side-note .arrow{ font-size:20px; margin-top:2mm; }
.table-wrap{ margin-top:4mm; }
.tbl{ width:100%; border-collapse:collapse; }
.tbl th, .tbl td{ border:1px solid var(--line); padding:2mm; vertical-align:middle; text-align:center; }
.tbl thead th{ background:#f6f6f6; font-weight:700; }
.tbl tfoot td{ background:#fafafa; }
.tbl input.cell{ width:100%; border:none; background:transparent; padding:0; height:20px; text-align:center; }
.tbl input.cell:focus{ outline:none; }
.tbl .num{ text-align:center !important; }
.center{ text-align:center; }
.right{ text-align:right; }
.btn{ border:1px solid var(--grid); background:#fff; padding:6px 10px; cursor:pointer; margin-right:8px; font-weight:600; }
.btn:hover{ background:#f2f2f2; }
.btn.ghost{ border-style:dashed; }
.btn-del{ border:1px solid var(--line); background:#fff; width:26px; height:26px; cursor:pointer; line-height:1; font-size:16px; }
.row-controls{ margin-top:8mm; }
.tiny-hint{ color:var(--muted); font-size:11px; margin-top:4mm; }
.brand-logo{ height:28mm; width:350px; display:block; }
@media print{ .brand-logo{ filter:none; } }
.no-print{ display:block; }
@media print{ .no-print{ display:none !important; } }
input[readonly], textarea[readonly]{ background:#f9f9f9; color:#333; }
input[type="checkbox"][disabled], input[type="radio"][disabled]{ filter:grayscale(1) opacity(.7); cursor:not-allowed; }
@page{ size:A4; margin:8mm 10mm; }
@media print{ .page.a4{ padding:0; margin:0; outline:none; } .head{ break-inside:avoid; } }
@media screen{ .page.a4{ position:relative; } .head{ position:sticky; top:0; z-index:10; background:#fff; box-shadow:0 1px 0 var(--line); } }
.toolbar{ width:210mm; margin:8mm auto 4mm; display:flex; gap:8px; align-items:center; }
.toolbar .spacer{ flex:1; }
.page-break{ break-before:page; }
.flash{ animation:flash .9s ease; }
@keyframes flash{ 0%{ box-shadow:0 0 0 3px rgba(255,200,0,.9); } 100%{ box-shadow:none; } }
.block.office{ background:#f6f6f6; }
.block.office .inp{ background:#fff; }
.grid-2-50{ grid-template-columns:1fr 1fr; }
.grid-2-33-66{ grid-template-columns:1fr 2fr; }
.full-span{ grid-column:1 / -1; }
@media (max-width:700px){ .grid-2-50, .grid-2-33-66{ grid-template-columns:1fr; } }
.tbl td.center, .tbl th.center{ text-align:center !important; }
CSS;

    // Für die Vorschau reicht ein schlanker historischer Interaktionsstand:
    // Felder sperren, Summe initialisieren und Datum setzen. Das eigentliche Layout
    // stammt vollständig aus historischem HTML/CSS.
    $js = <<<'JS'
(function () {
  const byId = (id) => document.getElementById(id);
  const fields = Array.from(document.querySelectorAll('input, select, textarea'));
  fields.forEach(el => {
    const type = (el.type || '').toLowerCase();
    if (type === 'checkbox' || type === 'radio') el.disabled = true;
    else el.readOnly = true;
  });
  const sum = byId('sum');
  if (sum && !sum.textContent.trim()) sum.textContent = '0';
  const dt = byId('datum');
  if (dt && !dt.value) dt.value = '2025-11-05';
})();
JS;

    return [
        'document_no' => 'F_059_0001',
        'title' => 'Laufzettel Wareneingang',
        'revision' => '1.0',
        'approved_at' => '2025-11-05T00:00:00+01:00',
        'source_commit' => 'ff4519dbf66c74aaaac3e65bcae3bfcd140d15f5',
        'html' => $html,
        'css' => $css,
        'js' => $js,
    ];
}
