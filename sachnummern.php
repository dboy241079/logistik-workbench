<?php
require_once __DIR__ . '/inc/session.php';

$currentUser = (string)($_SESSION['username'] ?? '');

$APP_VERSION = $APP_VERSION ?? 'v3';

$jsPath   = __DIR__ . '/js/sachnummern.js';
$jsBuild  = is_file($jsPath) ? filemtime($jsPath) : time();

$cssPath  = __DIR__ . '/CSS/sachnummern.css';
$cssBuild = is_file($cssPath) ? filemtime($cssPath) : time();

$buildTs  = $jsBuild ?: filemtime(__FILE__);
$APP_STAND = $APP_STAND ?? date('d.m.Y H:i', $buildTs) . ' Uhr';
?>

<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-user" content="<?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?>">
  <title>Stammdaten – Sachnummern / Behälter / Spedition / Kunden</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="/CSS/sachnummern.css?v=<?= $cssBuild ?>">
<link rel="stylesheet" href="/CSS/sachnummern-mobile.css?v=2">

</head>

<body>
  <main data-tab-root>
    <div class="container-lg py-2">
      <header class="mb-3">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
          <h1 class="h4 mb-0">Stammdaten15</h1>

          <div class="d-flex gap-2" aria-label="Export- und Druckaktionen">
            <button id="btnPrintSach" type="button" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-printer" aria-hidden="true"></i>
              <span>Drucken (Sachnummern)</span>
            </button>

            <button id="btnExcelSach" type="button" class="btn btn-sm btn-success">
              <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>
              <span>Excel (aktive Gruppe)</span>
            </button>

            <button id="btnExcelAllSach" type="button" class="btn btn-sm btn-success-subtle border">
              <i class="bi bi-files" aria-hidden="true"></i>
              <span>Excel (alle Lager)</span>
            </button>
          </div>
        </div>
      </header>

      <nav aria-label="Stammdaten-Bereiche">
        <ul class="nav nav-tabs" id="stammTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button
              id="tab-btn-sach"
              class="nav-link active"
              type="button"
              role="tab"
              aria-selected="true"
              aria-controls="tabSach"
              data-bs-toggle="tab"
              data-bs-target="#tabSach">
              Sachnummern
            </button>
          </li>

          <li class="nav-item" role="presentation">
            <button
              id="tab-btn-beh"
              class="nav-link"
              type="button"
              role="tab"
              aria-selected="false"
              aria-controls="tabBeh"
              data-bs-toggle="tab"
              data-bs-target="#tabBeh">
              Behälter
            </button>
          </li>

          <li class="nav-item" role="presentation">
            <button
              id="tab-btn-sped"
              class="nav-link"
              type="button"
              role="tab"
              aria-selected="false"
              aria-controls="tabSped"
              data-bs-toggle="tab"
              data-bs-target="#tabSped">
              Speditionen
            </button>
          </li>

          <li class="nav-item" role="presentation">
            <button
              id="tab-btn-cust"
              class="nav-link"
              type="button"
              role="tab"
              aria-selected="false"
              aria-controls="tabCust"
              data-bs-toggle="tab"
              data-bs-target="#tabCust">
              Kunden
            </button>
          </li>
        </ul>
      </nav>

      <div class="tab-content border border-top-0 p-3" id="stammTabsContent">
        <!-- Sachnummern -->
        <section
          id="tabSach"
          class="tab-pane fade show active"
          role="tabpanel"
          aria-labelledby="tab-btn-sach">

          <h2 class="visually-hidden">Sachnummern</h2>

          <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
            <div>
              <label for="searchSach" class="visually-hidden">Sachnummern suchen</label>
              <input
                id="searchSach"
                name="searchSach"
                type="search"
                class="form-control form-control-sm w-auto"
                placeholder="Suchen (Sachnr / Lagergruppe)"
                autocomplete="off">
            </div>

            <button id="btnNewSach" type="button" class="btn btn-sm btn-primary" data-entity="sachnummer">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
              <span>Neu (Sachnummer)</span>
            </button>
          </div>

          <div class="acc-wrap" aria-live="polite">
            <div id="accSach" class="accordion"></div>
          </div>

        </section>

        <!-- Behälter -->
        <section
          id="tabBeh"
          class="tab-pane fade"
          role="tabpanel"
          aria-labelledby="tab-btn-beh">

          <h2 class="visually-hidden">Behälter</h2>

          <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
            <div>
              <label for="searchBeh" class="visually-hidden">Behälter suchen</label>
              <input
                id="searchBeh"
                name="searchBeh"
                type="search"
                class="form-control form-control-sm w-auto"
                placeholder="Suchen…"
                autocomplete="off">
            </div>

            <button type="button" class="btn btn-sm btn-primary" id="btnNewBeh" data-entity="behaelter">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
              <span>Neu (Behälter)</span>
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-striped" id="tblBeh">
              <caption class="visually-hidden">Liste aller Behälter</caption>
              <thead>
  <tr>
    <th scope="col">Behältertyp</th>
    <th scope="col">VW-Kennung</th>
    <th scope="col">KLTs / Behälter</th>
    <th scope="col">Einheit</th>
    <th scope="col">Status</th>
    <th scope="col">Geändert</th>
    <th scope="col">Aktionen</th>
  </tr>
</thead>
              <tbody></tbody>
            </table>
          </div>
        </section>

        <!-- Speditionen -->
        <section
          id="tabSped"
          class="tab-pane fade"
          role="tabpanel"
          aria-labelledby="tab-btn-sped">

          <h2 class="visually-hidden">Speditionen</h2>

          <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
            <div>
              <label for="searchSped" class="visually-hidden">Speditionen suchen</label>
              <input
                id="searchSped"
                name="searchSped"
                type="search"
                class="form-control form-control-sm w-auto"
                placeholder="Suchen…"
                autocomplete="off">
            </div>

            <button type="button" class="btn btn-sm btn-primary" id="btnNewSped" data-entity="spedition">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
              <span>Neu (Spedition)</span>
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-striped" id="tblSped">
              <caption class="visually-hidden">Liste aller Speditionen</caption>
              <thead>
                <tr>
                  <th scope="col">Spedition</th>
                  <th scope="col">Kennzeichen</th>
                  <th scope="col">Geändert</th>
                  <th scope="col">Aktionen</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </section>

        <!-- Kunden -->
        <section
          id="tabCust"
          class="tab-pane fade"
          role="tabpanel"
          aria-labelledby="tab-btn-cust">

          <h2 class="visually-hidden">Kunden</h2>

          <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
            <div>
              <label for="searchCust" class="visually-hidden">Kunden suchen</label>
              <input
                id="searchCust"
                name="searchCust"
                type="search"
                class="form-control form-control-sm w-auto"
                placeholder="Suchen (Kürzel / Kunde / Ort)"
                autocomplete="off">
            </div>

            <button type="button" class="btn btn-sm btn-primary" id="btnNewCust">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
              <span>Neu (Kunde)</span>
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-striped" id="tblCust">
              <caption class="visually-hidden">Liste aller Kunden</caption>
              <thead>
                <tr>
                  <th scope="col">Kürzel</th>
                  <th scope="col">Kunde</th>
                  <th scope="col">Adresse</th>
                  <th scope="col">PLZ</th>
                  <th scope="col">Ort</th>
                  <th scope="col">Land</th>
                  <th scope="col">Geändert</th>
                  <th scope="col">Aktionen</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <!-- Kunden Modal -->
    <div
      class="modal fade"
      id="custModal"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      aria-labelledby="custModalTitle"
      aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <form class="modal-content" id="custForm">
          <div class="modal-header">
            <h2 class="modal-title h5 mb-0" id="custModalTitle">Kunde</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="custOrigCode" value="">

            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label" for="custCode">Kürzel</label>
                <input id="custCode" name="custCode" class="form-control" required maxlength="16" placeholder="z.B. 6128">
              </div>
              <div class="col-md-9">
                <label class="form-label" for="custName">Kunde / Name</label>
                <input id="custName" name="custName" class="form-control" required placeholder="z.B. Volkswagen AG Hannover">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="custAddr1">Adresse 1</label>
                <input id="custAddr1" name="custAddr1" class="form-control" required placeholder="z.B. Hansastr. 51">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="custAddr2">Adresse 2</label>
                <input id="custAddr2" name="custAddr2" class="form-control" placeholder="z.B. LKW Einfahrt Halle 28C">
              </div>

              <div class="col-md-3">
                <label class="form-label" for="custPostal">PLZ</label>
                <input id="custPostal" name="custPostal" class="form-control" required maxlength="16" placeholder="30419">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="custCity">Ort</label>
                <input id="custCity" name="custCity" class="form-control" required placeholder="Hannover">
              </div>
              <div class="col-md-3">
                <label class="form-label" for="custCountry">Land</label>
                <input id="custCountry" name="custCountry" class="form-control" required placeholder="Deutschland">
              </div>

              <div class="col-12">
                <label class="form-label" for="custNote">Notiz (optional)</label>
                <input id="custNote" name="custNote" class="form-control" placeholder="z.B. Rampe, Ansprechpartner, etc.">
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
            <button type="submit" class="btn btn-primary">Speichern</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Sachnr / Behälter / Sped Modal -->
    <div
      class="modal fade"
      id="editModal"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modalTitle"
      aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" id="editForm">
          <div class="modal-header">
            <h2 class="modal-title h5 mb-0" id="modalTitle">Eintrag</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="fieldId" name="fieldId">
            <input type="hidden" id="fieldEntity" name="fieldEntity">

            <div class="mb-3">
              <label class="form-label" id="fieldLabel" for="fieldValue">Wert</label>
              <input type="text" class="form-control" id="fieldValue" name="fieldValue" required>
            </div>
            <div id="behExtraWrap" class="row g-3 d-none">
  <div class="col-md-6">
    <label class="form-label" for="fieldVwKennung">Behälter Kennung VW</label>
    <input
      type="text"
      class="form-control"
      id="fieldVwKennung"
      name="fieldVwKennung"
      placeholder="z. B. GT62803"
    >
  </div>

  <div class="col-md-6">
    <label class="form-label" for="fieldKltsProBehaelter">KLTs / Behälter</label>
    <input
      type="number"
      min="0"
      step="1"
      class="form-control"
      id="fieldKltsProBehaelter"
      name="fieldKltsProBehaelter"
      placeholder="z. B. 15"
    >
  </div>

  <div class="col-md-6">
    <label class="form-label" for="fieldEinheit">Einheit</label>
    <select class="form-select" id="fieldEinheit" name="fieldEinheit">
      <option value="GB">GB</option>
      <option value="PAL">PAL</option>
      <option value="STK">STK</option>
      <option value="KLT">KLT</option>
    </select>
  </div>

  <div class="col-md-6">
    <label class="form-label" for="fieldBehStatus">Status</label>
    <select class="form-select" id="fieldBehStatus" name="fieldBehStatus">
      <option value="aktiv">aktiv</option>
      <option value="defekt">defekt</option>
      <option value="gesperrt">gesperrt</option>
    </select>
  </div>
</div>

            <div class="mb-3 d-none" id="lgWrap">
              <label class="form-label" for="fieldLagergruppe">Lagergruppe</label>
             <select class="form-select" id="fieldLagergruppe" name="fieldLagergruppe" required="">
  <option value="">– bitte wählen –</option>
  <option>W1</option>
  <option>X3</option>
  <option>X3(B)</option>
  <option>G9</option>
  <option>B1</option>
  <option>B1(T)</option>
  <option>Bauteile</option>
  <option>BM</option>
  <option>Sarajevo</option>
  <option>Müll</option>
</select>
              <div class="form-text">Pflichtfeld für Sachnummern.</div>
            </div>

            <div class="row g-3" id="snExtraWrap">
              <div class="col-md-4">
                <label class="form-label" for="fieldBruttogewicht">BRT-GEW</label>
                <input type="number" step="0.01" class="form-control" id="fieldBruttogewicht" name="fieldBruttogewicht" placeholder="z. B. 25.4">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="fieldBehaelterNr">Beh.-Nr.</label>
                <input type="text" class="form-control" id="fieldBehaelterNr" name="fieldBehaelterNr" list="behList" placeholder="z. B. 532 052">
                <datalist id="behList"></datalist>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="fieldZusBehaelter">Zus.-Beh.</label>
                <input type="number" class="form-control" id="fieldZusBehaelter" name="fieldZusBehaelter" placeholder="z. B. 500">
              </div>
            </div>

            <div class="mb-3 d-none" id="plateWrap">
              <label class="form-label" for="fieldPlates">Kennzeichen (optional)</label>
              <input type="text" class="form-control" id="fieldPlates" name="fieldPlates" placeholder="Mehrere mit Komma trennen">
              <div class="form-text">Mehrere Kennzeichen: mit Komma trennen.</div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
            <button type="submit" class="btn btn-primary">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  
    <footer class="app-footer" role="contentinfo">
    <div class="container-lg d-flex flex-wrap justify-content-between gap-2">
      <small>Stammdaten <?= htmlspecialchars($APP_VERSION, ENT_QUOTES, 'UTF-8') ?></small>
      <small>Stand: <?= htmlspecialchars($APP_STAND, ENT_QUOTES, 'UTF-8') ?></small>
    </div>
  </footer>

 </main>

  <script>
    window.CURRENT_USER = <?= json_encode(
      $currentUser,
      JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT
    ) ?>;
  </script>
  <script defer src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <script type="module" src="/js/sachnummern.js?v=<?= $jsBuild ?>"></script>
  <script src="/kommi/js/kunden.js?v=2" defer></script>

</body>
</html>
