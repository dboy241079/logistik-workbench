<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../inc/rbac.php';
require dirname(__DIR__) . '/api/_db.php';

// batch_id statt we_id
$batchId = (int)($_GET['batch_id'] ?? $_GET['batchId'] ?? $_GET['id'] ?? 0);

$batch = null;
if ($batchId > 0) {
  $stmt = $pdo->prepare("
    SELECT id, title, source, expected_count, created_at, created_by
    FROM lager_batches
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$batchId]);
  $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Lagerplan Halle 4</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- EIGENE HALLE4 STYLES -->
  <link rel="stylesheet" href="halle4.css">
  <link rel="stylesheet" href="halle4.layout.css">
  <link rel="stylesheet" href="halle4.mobile.css">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.19.3/dist/xlsx.full.min.js"></script>
</head>

<body class="min-h-screen bg-slate-200 flex flex-col items-center justify-start p-4">

<!-- Mehrfach-Buchung: pro Behälter eine Eingabezeile -->
<div id="multiAssignContainer" class="flex flex-col gap-1">
  <!-- JS erzeugt hier N Zeilen -->
</div>

<!-- BLOCK 1: Links Manuell / Mitte Filter / Rechts Excel+Warenausgang -->
<div class="container-fluid my-3">
  <div class="row g-3 align-items-stretch h4-equal-row">

    <!-- LINKS -->
    <div class="col-12 col-lg-4 d-flex flex-column gap-3">

      <!-- Card 1: Manuelle Einlagerung -->
      <div class="card shadow-sm w-100" data-inv-hide="1">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div>
            <div class="fw-semibold">Manuelle Einlagerung</div>
            <div class="text-muted small">LS + Sach bleiben stehen, nur Referenz wird geleert</div>
          </div>

          <button id="btnToggleManual" type="button" class="btn btn-outline-secondary btn-sm">
            Ein-/Ausblenden
          </button>
        </div>

        <div id="manualBox" class="card-body">
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <label class="form-label small mb-1">Sachnummer</label>
              <input id="manualSach" class="form-control" placeholder="z.B. 0Z1..." autocomplete="off">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label small mb-1">Lieferschein</label>
              <input id="manualLs" class="form-control" placeholder="z.B. 0612..." autocomplete="off">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label small mb-1">Stückzahl (optional)</label>
              <input id="manualQty" type="number" min="1" step="1" class="form-control" placeholder="z.B. 120">
              <div class="form-text">Leer = 1</div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label small mb-1">Verpackung</label>
              <select id="manualPack" class="form-select">
                <option value="">Bitte wählen</option>
                <option value="GT14488">GT14488</option>
                <option value="GT14491">GT14491</option>
                <option value="VW0012">VW0012</option>
                <option value="114003">114003</option>
                <option value="006280">006280</option>
                <option value="003147">003147</option>
                <option value="006147">006147</option>
              </select>
              <div class="form-text">optional</div>
            </div>

            <div class="d-none d-md-block col-md-4"></div>

            <div class="col-12">
              <label class="form-label small mb-1">Referenznummer</label>
              <input id="manualRef" class="form-control form-control-lg" placeholder="scannen / eingeben" autocomplete="off">
            </div>

            <div class="col-6">
              <label class="form-label small mb-1">Reihe</label>
              <select id="manualRow" class="form-select"></select>
            </div>

            <div class="col-6">
              <label class="form-label small mb-1">Platz</label>
              <input id="manualPlatz" type="number" min="1" max="200"
                     class="form-control" placeholder="Platznummer">
            </div>

            <div class="col-12 d-flex gap-2 mt-1">
              <button id="btnManualSave" type="button" class="btn btn-success flex-grow-1">
                Einlagern
              </button>
              <button id="btnCancelManual" type="button" class="btn btn-outline-secondary">
                Schließen
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Card 2: Inventur -->
      <div class="card shadow-sm w-100" data-inv-panel="1">
        <div class="card-header d-flex align-items-center justify-content-between">
          <button id="btnInvMode" type="button" class="btn btn-outline-dark btn-sm">
            Inventur-Modus
          </button>

          <div>
            <div class="fw-semibold">Inventur</div>
            <div class="text-muted small">Reihen markieren → Etiketten/Export</div>
          </div>
          <span class="badge text-bg-secondary" id="invProgressBadge">0/0 geprüft</span>
        </div>

        <div class="card-body">
          <div class="progress">
            <div class="progress-bar" id="invProgressBar" style="width:0%"></div>
          </div>

          <div class="d-flex gap-2 mt-3 flex-wrap">
            <button id="btnInvExportWord" type="button" class="btn btn-dark btn-sm">Etiketten (Word)</button>
            <button id="btnInvExportXlsx" type="button" class="btn btn-outline-secondary btn-sm">Liste (XLSX)</button>
            <button id="btnInvReset" type="button" class="btn btn-outline-danger btn-sm">Reset</button>
          </div>

          <div class="text-muted small mt-2">
            Tipp: Etiketten drucken → ausschneiden → an Reihe kleben.
          </div>
        </div>
      </div>

    </div>

    <!-- MITTE FILTER -->
    <div class="col-12 col-lg-4 d-flex">
      <div class="card h-100 shadow-sm w-100" id="lgFilterCard">

        <div class="card-header d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">Filter: Lagergruppe</div>
            <div class="text-muted small">für Export / Auswertung</div>
          </div>

          <span id="lgActiveBadge" class="badge text-bg-secondary align-self-start">
            Aktiv: alle
          </span>
        </div>

        <div class="card-body">
          <div id="lgFilterList" class="row g-1"></div>

          <div class="d-grid gap-2 mt-3">
            <button id="btnLgAll" class="btn btn-sm btn-outline-secondary" type="button">Alle</button>
            <button id="btnLgNone" class="btn btn-sm btn-outline-secondary" type="button">Keine</button>
            <button id="btnLgExport" class="btn btn-sm btn-primary" type="button">Excel</button>
          </div>

          <div class="text-muted small mt-2" id="lgFilterHint">
            Wenn nichts ausgewählt ist, gilt „alle“.
          </div>

          <hr class="my-3">
          <div class="fw-semibold mb-2" style="font-size:12px;">Übersicht (aktuell im Plan)</div>

          <div class="table-responsive" style="max-height:240px; overflow:auto;">
            <table class="table table-sm table-striped align-middle mb-0" id="lgSummaryTable">
              <thead class="table-light">
                <tr>
                  <th style="position:sticky; top:0; z-index:1;">LG</th>
                  <th class="text-end" style="position:sticky; top:0; z-index:1;">Paletten</th>
                  <th class="text-end" style="position:sticky; top:0; z-index:1;">Stück</th>
                  <th class="text-end" style="position:sticky; top:0; z-index:1;">Sachnr.</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <div class="small text-muted mt-2" id="lgSummaryTotal"></div>
        </div>

      </div>
    </div>

    <!-- RECHTS -->
    <div class="col-12 col-lg-4 d-flex flex-column gap-3 h4-rightcol">

      <!-- Excel -->
      <div class="h4-panel flex-fill d-flex">
        <div class="card border-0 shadow-sm w-100 h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
              <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-success">Export</span>
                <div class="fw-semibold">Excel Export</div>
              </div>
              <small class="text-muted">Read-only • erstellt nur Dateien</small>
            </div>

            <div class="row g-2 align-items-end">
              <div class="col-12 col-lg-auto">
                <div class="btn-group w-100" role="group" aria-label="Excel Export">
                  <button id="btnXlsxAll" type="button" class="btn btn-success btn-sm">Gesamt (1 Blatt)</button>
                  <button id="btnXlsxPerRow" type="button" class="btn btn-outline-success btn-sm">Je Reihe (Blätter)</button>
                  <button id="btnXlsxRowPallets" type="button" class="btn btn-outline-success btn-sm">Paletten je Reihe</button>
                  <button id="btnInventurExcel" type="button" class="btn btn-success">Inventur Excel</button>
                </div>
              </div>

              <div class="col-12 col-lg">
                <div class="input-group input-group-sm">
                  <span class="input-group-text">
                    <i class="bi bi-list-ol me-1"></i> Reihe
                  </span>
                  <select id="xlsxRowSel" class="form-select form-select-sm" style="min-width:110px;"></select>
                  <button id="btnXlsxRow" type="button" class="btn btn-outline-success btn-sm">Reihe exportieren</button>
                </div>

                <div class="form-text">
                  Tipp: „Je Reihe (Blätter)“ packt alle Reihen in eine Datei.
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- Warenausgang -->
      <div class="h4-panel flex-fill d-flex">
        <div class="card shadow-sm w-100 h-100">
          <div class="card-header">
            <div class="fw-semibold">Warenausgang buchen</div>
            <div class="text-muted small">Ref scannen → System zeigt Position/Sach → bestätigen</div>
          </div>

          <div class="card-body">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small mb-1">Versand-LS (optional)</label>
                <input id="outLs" class="form-control" placeholder="Versand-LS">
              </div>

              <div class="col-12">
                <label class="form-label small mb-1">Referenznummer</label>
                <div class="input-group">
                  <input id="outRef" class="form-control form-control-lg" placeholder="scannen / eingeben" autocomplete="off">
                  <button id="btnOutbook" class="btn btn-primary" type="button">Suchen</button>
                </div>
                <div id="out-status" class="small mt-1 text-muted"></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label small mb-1">Sachnummer</label>
                <input id="outSach" class="form-control" readonly>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label small mb-1">Position</label>
                <input id="outPos" class="form-control" readonly>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- BLOCK 2: Suche + Lagerreihe umbuchen -->
<div class="container-fluid my-3">
  <div class="row g-3 align-items-stretch">

    <!-- Suche -->
    <div class="col-12 col-lg-6 d-flex">
      <div class="card h-100 shadow-sm w-100">
        <div class="card-header">
          <div class="fw-semibold">Referenz / Sachnummer / Lieferschein suchen</div>
          <div class="text-muted small">Autocomplete + Trefferliste</div>
        </div>

        <div class="card-body">
          <div class="position-relative" id="searchWrap">
            <div class="input-group">
              <input id="searchRefInput" class="form-control"
                     placeholder="Ref / Sach / LS scannen oder tippen…" autocomplete="off">
              <button id="btnSearchRef" class="btn btn-outline-primary" type="button">Suchen</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Reihe umbuchen -->
    <div class="col-12 col-lg-6 d-flex">
      <div class="card shadow-sm w-100 h-100" id="rowMoveCard">
        <div class="card-header">
          <div class="fw-semibold">Lagerreihe komplett umbuchen</div>
          <div class="text-muted small">z.B. Reihe 80 → 82 (mit Prüfung + Bestätigung)</div>
        </div>

        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label small mb-1">Von Reihe</label>
              <select id="rmFromRow" class="form-select"></select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label small mb-1">Nach Reihe</label>
              <select id="rmToRow" class="form-select"></select>
            </div>

            <div class="col-12 col-md-6 d-flex gap-2">
              <button id="rmCheckBtn" type="button" class="btn btn-outline-primary w-100">Prüfen</button>
              <button id="rmAskBtn" type="button" class="btn btn-primary w-100" disabled>Umbuchen</button>
            </div>
          </div>

          <div id="rmInfo" class="mt-3"></div>
          <div id="rmMsg" class="mt-3"></div>

          <div id="rmConfirm" class="mt-3 d-none">
            <div class="alert alert-warning mb-2">
              <div class="fw-semibold mb-1">Wirklich die ganze Reihe umbuchen?</div>
              <div id="rmConfirmText" class="small"></div>
            </div>

            <div class="d-flex gap-2">
              <button id="rmNoBtn" type="button" class="btn btn-outline-secondary w-100">Nein</button>
              <button id="rmYesBtn" type="button" class="btn btn-danger w-100">Ja, umbuchen</button>
            </div>
          </div>

        </div>
      </div>
    </div>

  </div>
</div>

<!-- AUSBUCHEN CONFIRM MODAL -->
<div id="outbookModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[999]">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
    <div class="flex items-center justify-between mb-2">
      <div class="font-semibold text-slate-800 text-sm">Ausbuchen bestätigen</div>
      <button id="outClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
    </div>

    <div class="text-xs text-slate-700 space-y-1 mb-3">
      <div>Ref: <strong id="outMRef">-</strong></div>
      <div>Sach: <strong id="outMSach">-</strong></div>
      <div>Pos: <strong id="outMPos">-</strong></div>
      <div>LS: <strong id="outMLs">-</strong></div>
    </div>

    <div class="flex gap-2">
      <button id="outYes" class="bg-red-600 text-white text-xs font-semibold px-3 py-1 rounded">
        Ja, ausbuchen
      </button>
      <button id="outNo" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">
        Nein
      </button>
    </div>
  </div>
</div>

<!-- PLAN VIEWPORT -->
<div id="planViewport"
     class="relative w-full h-[calc(100vh+250px)] overflow-hidden border border-slate-400 bg-slate-50 touch-none">

  <!-- Status FIX -->
  <div id="lager-status"
       class="absolute top-2 left-2 z-20 rounded bg-white/90 border border-slate-200 px-2 py-1 text-[11px] text-slate-700 shadow-sm">
  </div>

  <!-- WORLD -->
  <div id="planContent"
       style="transform: translate(0px,0px) scale(1);"
       class="absolute left-0 top-0 origin-top-left inline-flex w-max gap-2 p-2 select-none">

    <!-- ==========================================================
         HALLE4 STARTLAYOUT (EIGENSTÄNDIG)
         => Hier später dein echtes Halle4-Layout einsetzen
         => IDs/Klassen bewusst kompatibel zur Lagerplan-Logik
         ========================================================== -->
    <section id="h4-block-main" class="w1-block row-titel flex items-stretch gap-2 text-[10px] leading-tight">

      <!-- Beispiel Reihe 1 -->
      <div class="zone lager-reihe shrink-0 w-[260px] flex flex-col border border-slate-600 bg-emerald-100"
           data-zone="H4-R1" data-row="1">
        <div class="px-2 py-1 border-b border-slate-400 text-[11px] font-semibold text-slate-800">H4 Reihe 1</div>
        <div class="flex-1 flex flex-col border-b border-slate-400">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="1" data-range-start="26" data-range-end="60"></div>
        </div>
        <div class="quer-strasse h-4 bg-slate-300/80 text-[8px] flex items-center justify-center border-b border-slate-400">
          Stapler-Straße
        </div>
        <div class="flex-1 flex flex-col">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="1" data-range-start="1" data-range-end="25"></div>
        </div>
      </div>

      <!-- Straße -->
      <div class="stapler-strasse stapler-strasse-v shrink-0 w-[80px] self-stretch border border-slate-700 bg-slate-300/80 flex items-center justify-center text-[9px] text-center"
           data-zone="STR-H4-1-2">
        Stapler-<br>straße
      </div>

      <!-- Beispiel Reihe 2 -->
      <div class="zone lager-reihe shrink-0 w-[260px] flex flex-col border border-slate-600 bg-emerald-100"
           data-zone="H4-R2" data-row="2">
        <div class="px-2 py-1 border-b border-slate-400 text-[11px] font-semibold text-slate-800">H4 Reihe 2</div>
        <div class="flex-1 flex flex-col border-b border-slate-400">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="2" data-range-start="26" data-range-end="60"></div>
        </div>
        <div class="quer-strasse h-4 bg-slate-300/80 text-[8px] flex items-center justify-center border-b border-slate-400">
          Stapler-Straße
        </div>
        <div class="flex-1 flex flex-col">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="2" data-range-start="1" data-range-end="25"></div>
        </div>
      </div>

      <!-- Beispiel Reihe 3 -->
      <div class="zone lager-reihe shrink-0 w-[260px] flex flex-col border border-slate-600 bg-emerald-100"
           data-zone="H4-R3" data-row="3">
        <div class="px-2 py-1 border-b border-slate-400 text-[11px] font-semibold text-slate-800">H4 Reihe 3</div>
        <div class="flex-1 flex flex-col border-b border-slate-400">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="3" data-range-start="26" data-range-end="60"></div>
        </div>
        <div class="quer-strasse h-4 bg-slate-300/80 text-[8px] flex items-center justify-center border-b border-slate-400">
          Stapler-Straße
        </div>
        <div class="flex-1 flex flex-col">
          <div class="platz-container flex-1 flex flex-col gap-[10px] p-1"
               data-row="3" data-range-start="1" data-range-end="25"></div>
        </div>
      </div>

    </section>
  </div>

  <!-- Tooltip FIX -->
  <div id="lager-info"
       class="fixed hidden z-[999999] bg-white border border-slate-300 rounded shadow-lg p-2 text-[11px] text-slate-800"></div>

  <!-- Controls FIX -->
  <div class="plan-controls absolute top-2 right-2 z-20 flex gap-1">
    <button data-zoom="out" class="px-2 py-1 bg-white border rounded">-</button>
    <button data-zoom="in" class="px-2 py-1 bg-white border rounded">+</button>
    <button data-zoom="reset" class="px-2 py-1 bg-white border rounded">Reset</button>
  </div>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[999]">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
    <div class="flex items-center justify-between mb-3">
      <div class="font-semibold text-slate-800 text-sm">Palette einlagern</div>
      <button id="assignClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
    </div>

    <div class="grid gap-2 text-xs">
      <div class="grid grid-cols-3 gap-2">
        <div>
          <label class="block font-semibold">Reihe</label>
          <input id="amRow" class="border border-slate-300 rounded px-2 py-1 w-full bg-slate-100" readonly>
        </div>
        <div>
          <label class="block font-semibold">Platz</label>
          <input id="amPlatz" class="border border-slate-300 rounded px-2 py-1 w-full bg-slate-100" readonly>
        </div>
        <div>
          <label class="block font-semibold">Slot</label>
          <input id="amSlot" class="border border-slate-300 rounded px-2 py-1 w-full bg-slate-100" readonly>
        </div>
      </div>

      <label class="block text-[11px] font-semibold text-slate-700 mt-2">Stückzahl</label>
      <input id="amQty" type="number" min="1" step="1"
             class="border border-slate-300 rounded px-2 py-2 w-full text-sm" value="1">

      <div>
        <label class="block font-semibold">Referenznummer</label>
        <input id="amRef" class="border border-slate-300 rounded px-2 py-1 w-full" placeholder="0612...">
      </div>

      <div>
        <label class="block font-semibold">Sachnummer</label>
        <input id="amSach" class="border border-slate-300 rounded px-2 py-1 w-full" placeholder="0Z1 ...">
      </div>

      <div class="flex gap-2 mt-2">
        <button id="assignSave" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-1 rounded">Speichern</button>
        <button id="assignCancel" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">Abbrechen</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Delete Modal -->
<div id="confirmDeleteModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[1000]">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-sm">
    <div class="font-semibold text-slate-800 text-sm mb-2">Slot löschen?</div>

    <div class="text-xs text-slate-600 mb-3">
      Referenz: <strong id="cdmRef">-</strong><br>
      Sachnummer: <strong id="cdmSach">-</strong><br>
      Position: <strong id="cdmPos">-</strong>
    </div>

    <div class="flex gap-2 justify-end">
      <button id="cdmNo" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">Nein</button>
      <button id="cdmYes" class="bg-red-600 text-white text-xs font-semibold px-3 py-1 rounded">Ja, löschen</button>
    </div>
  </div>
</div>

<!-- Move Modal -->
<div id="moveModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[1000]">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
    <div class="flex items-center justify-between mb-2">
      <div class="font-semibold text-slate-800 text-sm">Platz umbuchen</div>
      <button id="mmClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
    </div>

    <div class="text-xs text-slate-600 mb-3">
      Ref: <strong id="mmRef">-</strong> · Sach: <strong id="mmSach">-</strong><br>
      Von: <strong id="mmFrom">-</strong>
    </div>

    <div class="grid gap-2 text-xs">
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="block font-semibold">Ziel-Reihe</label>
          <select id="mmRow" class="border border-slate-300 rounded px-2 py-1 w-full"></select>
        </div>
        <div>
          <label class="block font-semibold">Ziel-Platz</label>
          <input id="mmPlatz" type="number" min="1" max="200"
                 class="border border-slate-300 rounded px-2 py-1 w-full" placeholder="z.B. 28">
        </div>
      </div>

      <div class="text-[11px] text-slate-500" id="mmHint">
        Slot wird automatisch auf den ersten freien Slot gelegt.
      </div>

      <div class="flex gap-2 mt-2 justify-end">
        <button id="mmCancel" class="bg-slate-200 text-slate-800 text-xs font-semibold px-3 py-1 rounded">Abbrechen</button>
        <button id="mmSave" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-1 rounded">Umbuchen</button>
      </div>
    </div>
  </div>
</div>

<!-- Karton Scan Modal -->
<div id="cartonModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-[10000]">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
    <div class="flex items-center justify-between mb-2">
      <div class="font-semibold text-slate-800 text-sm">Kartons scannen</div>
      <button id="cartonClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
    </div>

    <div class="text-xs text-slate-700 mb-2">
      Position: <strong id="cartonPos">-</strong><br>
      Fortschritt: <strong id="cartonProg">0</strong>
    </div>

    <div class="flex gap-2">
      <input id="cartonInput" class="border border-slate-300 rounded px-2 py-2 w-full text-sm"
             placeholder="Karton-Ref scannen…" autocomplete="off">
      <button id="cartonAdd" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-2 rounded">OK</button>
    </div>

    <div class="mt-3 max-h-[35vh] overflow-auto text-xs" id="cartonList"></div>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 z-[999999] items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-xl p-4 w-[95vw] max-w-md">
    <div class="flex items-center justify-between mb-2">
      <div class="text-sm font-semibold text-slate-800">Bearbeiten</div>
      <button id="editClose" class="text-slate-600 hover:text-slate-900 text-xl leading-none">×</button>
    </div>

    <div class="text-xs text-slate-700 mb-2">
      Ref: <b id="emRefLbl">-</b><br>
      Pos: <b id="emPosLbl">-</b>
    </div>

    <div class="grid gap-2">
      <div>
        <label class="block text-[11px] font-semibold text-slate-700 mb-1">Referenz</label>
        <input id="emRef" class="border border-slate-300 rounded px-2 py-2 w-full text-sm">
      </div>

      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="block text-[11px] font-semibold text-slate-700 mb-1">Sachnummer</label>
          <input id="emSach" class="border border-slate-300 rounded px-2 py-2 w-full text-sm">
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-slate-700 mb-1">Menge</label>
          <input id="emQty" type="number" min="1" step="1"
                 class="border border-slate-300 rounded px-2 py-2 w-full text-sm" value="1">
        </div>
      </div>

      <div>
        <label class="block text-[11px] font-semibold text-slate-700 mb-1">Notiz</label>
        <textarea id="emNote" class="border border-slate-300 rounded px-2 py-2 w-full text-sm" rows="2"></textarea>
      </div>
    </div>

    <div class="mt-3 flex justify-end gap-2">
      <button id="emSave" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-2 rounded">Speichern</button>
      <button id="editClose2" class="bg-slate-100 text-slate-800 text-xs font-semibold px-3 py-2 rounded border">Abbrechen</button>
    </div>
  </div>
</div>

<script>
  // Alias für Export
  window.h4ExportData = window.lagerSlots || [];
</script>

<script>
  window.currentUserName = <?= json_encode($_SESSION['username'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
  window.LAGERPLAN_CFG = {
    hall: "H4",
    zone: "H4",
    batch: <?= json_encode($batch ?: null, JSON_UNESCAPED_UNICODE) ?>,
    user: <?= json_encode($_SESSION['username'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
    stammdatenApi: "/api/stammdaten_api.php"
  };
</script>

<script>
  window.STAMMDATEN_API_URL = "/api/stammdaten_api.php";
</script>

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>

<!-- Stammdaten / gemeinsame Module -->
<script defer src="/js/stammdaten-ac.js"></script>
<script defer src="/js/stammdaten-ac-live-sach.js"></script>

<script defer src="/Lagerplan/js/lagerplan.viewport.js?v=1"></script>
<script defer src="/Lagerplan/js/lagerplan.live.js?v=2"></script>
<script defer src="/Lagerplan/js/lagerplan.js?v=2"></script>
<script defer src="/Lagerplan/js/lager_row_move_ui.js?v=1"></script>
<script defer src="/Lagerplan/js/lg_filters.js?v=1"></script>
<script defer src="/Lagerplan/js/slot_flags.js?v=1"></script>
<script defer src="/Lagerplan/js/inventur.js?v=20260206_2"></script>

<!-- EIGENE HALLE4 DATEIEN -->
<script defer src="/Lagerplan/halle4.rows.layout.js?v=20260225_1"></script>
<script defer src="/Lagerplan/halle4.js?v=20260225_1"></script>
<script defer src="/Lagerplan/js/halle4.mobile.js?v=20260225_1"></script>

</body>
</html>