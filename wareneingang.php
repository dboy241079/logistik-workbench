<?php
declare(strict_types=1);

require __DIR__ . '/inc/session.php';
require __DIR__ . '/api/_db.php'; // PDO für DB-Rollen

function allowed_roles_for_tab(PDO $pdo, string $tabKey): array {
  $st = $pdo->prepare("SELECT role FROM app_tab_roles WHERE tab_key = :t");
  $st->execute([':t' => $tabKey]);
  $roles = $st->fetchAll(PDO::FETCH_COLUMN);
  $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));

  // Fallback, falls Tabelle leer ist:
  return $roles ?: ['admin'];
}

// Konfiguration für diesen Screen
$TAB_KEY = 'inbound';

$AUTH_DEFAULT_TAB   = $TAB_KEY;
$AUTH_ALLOWED_ROLES = allowed_roles_for_tab($pdo, $TAB_KEY);
$AUTH_REQUIRE_EMBED = true;
$AUTH_REQUIRE_LOGIN = true;
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/inc/auth_embed.php';
?>



<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Wareneingang – Gruppen + Filter + Suche</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Eigenes CSS -->
  <link href="/CSS/wareneingang.css" rel="stylesheet" />
</head>
<body>
  <div class="page-wrap">
    <div class="px-3 py-3 d-flex align-items-center justify-content-between">
      <h1 class="h4 mb-0">Wareneingang</h1>
    </div>

    <div class="container-fluid">
      <div class="row g-3 align-items-stretch">

        <!-- LINKS: 50% – zwei Karten untereinander -->
        <div class="col-12 col-lg-6 d-flex flex-column gap-3">

          <!-- Offene Eingangsnummern -->
          <div class="card">
            <div class="card-header py-2">
              <strong>Offene Eingangsnummern</strong>
            </div>
            <div class="card-body p-2">
              <div class="d-flex align-items-baseline gap-2 mb-2">
                <div class="display-6 mb-0" id="statsOffenCount">0</div>
                <div class="text-muted">offen</div>
              </div>
              <div id="statsOffenList" class="small">
                <span class="text-muted">Alles gebucht – keine offenen Eingangsnummern.</span>
              </div>
            </div>
          </div>

          <!-- Wareneingänge pro Tag -->
          <div class="card">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
              <strong>Wareneingänge pro Tag</strong>
              <small class="text-muted">Zeitraum wie oben</small>
            </div>
            <div class="card-body p-2">
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th>Datum</th>
                      <th class="text-end">Eingänge</th>
                      <th class="text-end">Δ Vortag</th>
                    </tr>
                  </thead>
                  <!-- EINZIGES tbody mit dieser ID -->
                  <tbody id="statsPerDay"></tbody>
                </table>
              </div>
              <small class="text-muted" id="statsPerDayInfo"></small>
            </div>
          </div>

        </div>

        <!-- RECHTS: 50% – Hinweis, Export, Filter -->
        <div class="col-12 col-lg-6 d-flex">
          <div class="card flex-fill h-100">
            <div class="card-header py-2">
              <strong>Aktionen &amp; Filter</strong>
            </div>
            <div class="card-body p-2">

              <div class="alert alert-secondary py-2 mb-2">
                Tipp: Spaltenkopf zum Sortieren. „Bearbeiten“ macht die Zeile editierbar, „Speichern“ markiert sie.
                Icons: <i class="bi bi-pencil"></i> Bearbeiten · <i class="bi bi-check2"></i> Speichern ·
                <i class="bi bi-copy"></i> Duplizieren · <i class="bi bi-trash"></i> Löschen ·
                <i class="bi bi-printer"></i> WA drucken (Excel).
              </div>

              <button id="btnExportCsv" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> CSV exportieren
              </button>
              <button id="btnWeeklyExport" class="btn btn-outline-success btn-sm"
                      title="Export für 2025-11-24 bis 2025-11-30">
                Vorige Woche exportieren
              </button>

              <!-- Attachments Modal -->
              <div class="modal fade" id="attModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">
                        Anhänge zu Eingangsnummer <span id="attModalNr"></span>
                      </h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>

                    <div class="modal-body">
                      <div class="row g-3">
                        <div class="col-12 col-lg-5">
                          <div class="d-flex gap-2 align-items-center mb-2">
                            <input id="attFileInput" type="file"
                                   class="form-control form-control-sm"
                                   accept="image/*,application/pdf" multiple>
                            <button id="attPrintBtn" type="button"
                                    class="btn btn-sm btn-outline-primary" disabled>
                              Drucken
                            </button>
                          </div>
                          <div id="attList" class="list-group"></div>
                        </div>

                        <div class="col-12 col-lg-7">
                          <div id="attPreview" class="border rounded p-2" style="min-height: 200px;">
                            <div class="text-muted mt-3">Keine Vorschau ausgewählt.</div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Schließen
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Filterleiste -->
              <div id="filterBar"
                   class="filter-bar d-flex align-items-center gap-2 flex-wrap flex-md-nowrap pb-2 mt-3">
                <div class="d-flex align-items-center gap-2 flex-nowrap">
                  <label for="filterNumber" class="form-label mb-0">Nur Eingangsnummer:</label>
                  <select id="filterNumber" class="form-select form-select-sm w-auto">
                    <option value="">Alle</option>
                    <!-- Optionen aus JS generiert -->
                  </select>
                </div>

                <div class="vr d-none d-md-block"></div>

                <div class="d-flex align-items-center gap-2 flex-nowrap">
                  <label for="searchInput" class="form-label mb-0">Suche:</label>
                  <input id="searchInput" type="text"
                         class="form-control form-control-sm w-auto"
                         placeholder="z. B. WE-2025-00123">
                  <select id="searchField" class="form-select form-select-sm w-auto">
                    <option value="eingang" selected>Eingangsnummer</option>
                    <option value="lieferschein">Lieferschein</option>
                    <option value="spedition">Spedition</option>
                    <option value="sachnummer">Sachnummer</option>
                    <option value="all">Alle Spalten</option>
                  </select>

                  <div class="form-check ms-1 mb-0 d-none">
                    <input class="form-check-input" type="checkbox" id="searchAllCols">
                    <label class="form-check-label small" for="searchAllCols">alle Spalten</label>
                  </div>
                </div>

                <div class="ms-md-auto">
                  <button id="btnResetFilter" class="btn btn-outline-secondary btn-sm">Reset</button>
                </div>
              </div>

            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- MONATS-ACCORDION für Wareneingang (Monatsfilter für Tabelle) -->
    <div class="px-3 pt-2" id="weMonthAccordionWrap">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h2 class="h6 mb-0">Monatsauswahl Wareneingang</h2>
        <small class="text-muted">Standard: aktueller Monat</small>
      </div>
      <div class="accordion" id="weMonthAccordion">
        <!-- Wird von JS (buildWeMonthAccordion) gefüllt -->
      </div>
    </div>

    <!-- TABELLE WARNEEINGANG -->
    <div class="table-container px-3 pb-4">
      <table id="eingangTable"
             class="table table-sm table-striped table-hover align-middle w-100 table-compact">

        <thead class="table-dark">
          <tr>
            <th data-type="number" title="Eingangsnummer">
              <span class="th-wrap">
                <span class="th-lines"><span>Eing.-Nr.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Frachtbrief / Lieferschein">
              <span class="th-wrap">
                <span class="th-lines"><span>Fracht</span><span>/Lief.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Lagergruppe">
              <span class="th-wrap">
                <span class="th-lines"><span>Lagergrp.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="date" title="Datum">
              <span class="th-wrap">
                <span class="th-lines"><span>Datum</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Kennzeichen">
              <span class="th-wrap">
                <span class="th-lines"><span>Kennz.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Land">
              <span class="th-wrap">
                <span class="th-lines"><span>Land</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Spedition">
              <span class="th-wrap">
                <span class="th-lines"><span>Spedit.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="time" title="Ankunft (Uhrzeit)">
              <span class="th-wrap">
                <span class="th-lines"><span>Ank.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="time" title="Beginn Entladung (Uhrzeit)">
              <span class="th-wrap">
                <span class="th-lines"><span>Beg. Entl.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="time" title="Ende Entladung (Uhrzeit)">
              <span class="th-wrap">
                <span class="th-lines"><span>Ende Entl.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="number" title="Anzahl Behälter">
              <span class="th-wrap">
                <span class="th-lines"><span>Behälter</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="number" title="Anzahl Zusatz-Behälter">
              <span class="th-wrap">
                <span class="th-lines"><span>Zus.-Beh.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Behälternummer">
              <span class="th-wrap">
                <span class="th-lines"><span>Beh.-Nr.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="text" title="Sachnummer">
              <span class="th-wrap">
                <span class="th-lines"><span>Sachnr.</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="number" title="Menge">
              <span class="th-wrap">
                <span class="th-lines"><span>Menge</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="select" title="Gebucht (Ja/Nein)">
              <span class="th-wrap">
                <span class="th-lines"><span>Gebucht</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="select" title="Gebucht von (FS = Frühschicht / SS = Spätschicht)">
              <span class="th-wrap">
                <span class="th-lines"><span>Geb. von</span></span>
                <span class="sort-ind">↕</span>
              </span>
            </th>
            <th data-type="none">Aktion</th>
          </tr>
        </thead>

        <tbody>
          <!-- Zeilen werden aus PHP/JS befüllt -->
        </tbody>
      </table>

      <button id="btnAddRow" class="btn btn-primary btn-sm">+ Zeile hinzufügen</button>
    </div>

    <!-- STATISTIK -->
    <div class="px-3 pb-5" id="statsWrap">
      <div class="row g-3 align-items-stretch">
        <!-- 50%: Top-Sachnummern -->
        <div class="col-12 col-lg-6 d-flex">
          <div class="card flex-fill h-100">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
              <strong>Top-Sachnummern</strong>
              <div class="d-flex align-items-center gap-2">
                <label for="statsRange" class="small mb-0">Zeitraum:</label>
                <select id="statsRange" class="form-select form-select-sm">
                  <option value="current">Aktueller Monat</option>
                  <option value="last">Letzter Monat</option>
                  <option value="all">Alle Monate</option>
                </select>
              </div>
            </div>
            <div class="card-body p-2">
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th style="width:60%">Sachnummer</th>
                      <th class="text-end">Summe Behälter</th>
                    </tr>
                  </thead>
                  <tbody id="statsSach">
                    <!-- ... dynamische Zeilen ... -->
                  </tbody>
                </table>
              </div>
              <small class="text-muted" id="statsSachInfo">
                Zeitraum: September 2025 — Datengrundlage: gespeicherte/aktuelle Zeilen.
              </small>
            </div>
          </div>
        </div>

        <!-- 50%: Paletten nach Lagergruppe -->
        <div class="col-12 col-lg-6 d-flex">
          <div class="card flex-fill h-100">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
              <strong>Paletten nach Lagergruppe</strong>
            </div>
            <div class="card-body p-2">
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th>Lagergruppe</th>
                      <th class="text-end">Paletten</th>
                    </tr>
                  </thead>
                  <tbody id="statsGruppen">
                    <!-- ... dynamische Zeilen ... -->
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <th>Gesamt</th>
                      <th class="text-end" id="statsGruppenGesamt">0</th>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <small class="text-muted">
                Zeitraum basiert auf der Auswahl „Zeitraum“ oben.
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
      <!-- STATISTIK -->
  <div class="px-3 pb-5" id="statsWrap">
    <!-- ... dein bestehender Statistik-Content ... -->
  </div>

  </div> <!-- /.page-wrap -->

  <!-- JS: Stammdaten + Wareneingang-Logik -->
  <script src="/js/stammdaten-ac.js"></script>
  <script src="/js/wareneingang.js"></script>
  <script src="/js/iframe_parent_bridge.js"></script>
</body>
</html>
