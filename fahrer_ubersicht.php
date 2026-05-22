<?php
declare(strict_types=1);

require __DIR__ . '/inc/session.php';


/**
 * Fahrerdashboard – nur aus der Workbench erreichbar.
 */
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = true;
$AUTH_ALLOWED_ROLES = ['admin', 'disposition'];
$AUTH_DEFAULT_TAB   = 'drivers';
$AUTH_DENY_MODE     = 'message';

require __DIR__ . '/inc/auth_embed.php';
?>

<!-- Full-Width Modul: kein <html>, <head>, <body> nötig -->
<div class="driver-dashboard container-fluid py-3">

  <!-- Intro-Kachel -->
  <div class="dashboard-intro text-center py-4 px-3 mb-4 rounded shadow-sm position-relative overflow-hidden">
    <h4 class="fw-bold text-primary mb-3">
      <i class="bi bi-speedometer2 me-2"></i>Fahrer-Dashboard
    </h4>
    <p class="text-muted mb-0">
      Willkommen im Fahrer-Dashboard! 🚛<br>
      Hier erhältst du einen Überblick über alle Touren, den aktuellen Fahrerstatus 
      und detaillierte Tagesberichte.
      Nutze die Tabs unten, um zwischen der Gesamtübersicht und den einzelnen Fahrern zu wechseln.
    </p>
    <!-- 🚛 Hintergrund-LKW -->
    <i class="bi bi-truck dashboard-bg-icon"></i>
  </div>

  <!-- Wochen-Navigation -->
<div class="d-flex justify-content-center align-items-center gap-2 mb-3">
  <button type="button" id="btnPrevWeek" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i>
  </button>

  <h6 id="weekInfo" class="text-muted mb-0 text-center">
    <!-- wird per JS gesetzt -->
  </h6>

  <button type="button" id="btnNextWeek" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-right"></i>
  </button>
</div>


  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="drvTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <a class="nav-link active" data-bs-toggle="tab" href="#tabOverview" aria-selected="true" role="tab">
        Übersicht
      </a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link" data-bs-toggle="tab" href="#tabF1" role="tab">
        <i class="bi bi-person-circle me-1"></i>BOH - DT 324
      </a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link" data-bs-toggle="tab" href="#tabF2" role="tab">
        <i class="bi bi-person-circle me-1"></i>BOH - DT 988
      </a>
    </li>
    <li class="nav-item" role="presentation">
      <a class="nav-link" data-bs-toggle="tab" href="#tabF3" role="tab">
        <i class="bi bi-person-circle me-1"></i>BOH - DT 964
      </a>
    </li>

    <!-- Info Icon -->
    <li class="nav-item ms-auto" role="presentation">
      <button class="btn btn-link text-secondary" id="tabInfoBtn" title="Tab-Erklärungen anzeigen">
        <i class="bi bi-info-circle fs-5"></i>
      </button>
    </li>
  </ul>

  <!-- Info Overlay (zuerst versteckt) -->
  <div id="tabInfoBox" class="tab-info-box">
    <div class="tab-info-content">
      <h5 class="fw-bold mb-3">
        <i class="bi bi-info-circle me-2 text-primary"></i>Erklärung der Tabs
      </h5>
      <ul class="list-unstyled text-muted small">
        <li><strong>Übersicht:</strong> gesamte Wochenübersicht aller Fahrer.</li>
        <li><strong>BOH - DT 324:</strong> individuelle Wochenansicht Fahrer 1.</li>
        <li><strong>BOH - DT 988:</strong> individuelle Wochenansicht Fahrer 2.</li>
        <li><strong>BOH - DT 964:</strong> individuelle Wochenansicht Fahrer 3.</li>
      </ul>
      <div class="text-end mt-3">
        <button class="btn btn-outline-primary btn-sm" id="closeTabInfo">
          <i class="bi bi-x-circle me-1"></i>Schließen
        </button>
      </div>
    </div>
  </div>

  <!-- Tab-Inhalte -->
  <div class="tab-content">
    <!-- Übersicht: alle Fahrer / alle Tage -->
    <div class="tab-pane fade show active" id="tabOverview">
      <div class="accordion" id="weekAccordion"></div>
    </div>

    <!-- Einzeldarstellung pro Fahrer -->
    <div class="tab-pane fade" id="tabF1"><div id="driver1"></div></div>
    <div class="tab-pane fade" id="tabF2"><div id="driver2"></div></div>
    <div class="tab-pane fade" id="tabF3"><div id="driver3"></div></div>
  </div>

  <div class="text-end mt-3 small text-muted" id="statusLbl"></div>

  <!-- Mail-Vorschau-Modal (Markup bleibt wie gehabt) -->
  <div class="modal fade" id="mailPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-envelope me-2"></i>Tagesbericht Vorschau</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <pre id="mailPreviewBody" class="small bg-light p-3 rounded" style="white-space: pre-wrap;"></pre>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Schließen
          </button>
          <button class="btn btn-outline-primary" id="btnSaveTxt">
            <i class="bi bi-file-earmark-text me-1"></i>Als .TXT speichern
          </button>
          <button class="btn btn-primary" id="btnSendOutlook">
            <i class="bi bi-envelope-fill me-1"></i>In Outlook öffnen
          </button>
        </div>
      </div>
    </div>
  </div>

</div>
