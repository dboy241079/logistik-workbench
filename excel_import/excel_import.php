<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

$username = $_SESSION['username'] ?? 'unbekannt';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Excel-Paste-Import | Workbench</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{
      font-family: Arial, sans-serif;
      background:#0f172a;
      color:#e5e7eb;
      margin:0;
      padding:24px;
    }
    .wrap{
      max-width:1100px;
      margin:0 auto;
    }
    .card{
      background:#111827;
      border:1px solid #334155;
      border-radius:16px;
      padding:24px;
      box-shadow:0 10px 30px rgba(0,0,0,.25);
      margin-bottom:20px;
    }
    h1,h2,h3{
      margin-top:0;
    }
    label{
      display:block;
      margin:14px 0 6px;
      font-weight:bold;
    }
    select, textarea, button{
      width:100%;
      padding:12px;
      border-radius:10px;
      border:1px solid #475569;
      background:#1e293b;
      color:#fff;
      box-sizing:border-box;
      font:inherit;
    }
    textarea{
      min-height:360px;
      resize:vertical;
      line-height:1.4;
      white-space:pre;
      tab-size:4;
    }
    button{
      background:#2563eb;
      border:none;
      cursor:pointer;
      margin-top:20px;
      font-weight:bold;
    }
    button:hover{
      background:#1d4ed8;
    }
    .hint{
      margin-top:14px;
      color:#94a3b8;
      font-size:14px;
      line-height:1.5;
    }
    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:20px;
    }
    .mini{
      background:#1e293b;
      border:1px solid #334155;
      border-radius:12px;
      padding:16px;
    }
    .code{
      background:#020617;
      border:1px solid #334155;
      border-radius:10px;
      padding:12px;
      overflow:auto;
      font-family: Consolas, monospace;
      font-size:14px;
      white-space:pre-wrap;
    }
    .actions{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin-top:16px;
    }
    .secondary{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:12px 16px;
      border-radius:10px;
      border:1px solid #475569;
      background:#1e293b;
      color:#e5e7eb;
      text-decoration:none;
      font-weight:bold;
    }
    @media (max-width: 900px){
      .grid{
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Excel-Paste-Import</h1>
    <p>Angemeldet als: <strong><?= htmlspecialchars($username) ?></strong></p>

    <form action="excel_import_paste_save.php" method="post">
      <label for="module">Modul</label>
      <select name="module" id="module" required>
        <option value="wareneingang">Wareneingang</option>
        <option value="warenausgang">Warenausgang</option>
      </select>

      <label for="pasted_data">Excel-Tabelle hier einfügen</label>
      <textarea name="pasted_data" id="pasted_data" placeholder="Einfach den Bereich aus Excel markieren, kopieren und hier einfügen..." required></textarea>

      <div class="actions">
        <button type="submit">Einfügen prüfen und in Staging speichern</button>
        <a class="secondary" href="excel_import_preview.php">Zur letzten Vorschau</a>
      </div>
    </form>

    <div class="hint">
      Unterstützt normales Einfügen direkt aus Excel.  
      Die Daten werden zuerst nur in die Import-Tabellen geschrieben und noch nicht in euren echten Wareneingang / Warenausgang übernommen.
    </div>
  </div>

  <div class="grid">
    <div class="mini">
      <h3>So benutzt ihr es</h3>
      <ol style="margin:0; padding-left:20px; line-height:1.6;">
        <li>In Excel den gewünschten Tabellenbereich markieren</li>
        <li><strong>Strg + C</strong></li>
        <li>Hier ins Feld klicken</li>
        <li><strong>Strg + V</strong></li>
        <li>Auf <strong>„Einfügen prüfen und in Staging speichern“</strong> klicken</li>
      </ol>
    </div>

    <div class="mini">
      <h3>Am besten mit Header-Zeile</h3>
      <div class="code">Referenznummer    Sachnummer    Lieferschein    Menge    Lagergruppe
12345              98765         LS-1001          20       BM
12346              98766         LS-1002          10       KTL</div>
    </div>
  </div>
</div>
</body>
</html>