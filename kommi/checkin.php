<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';
require __DIR__ . '/../inc/rbac.php';

$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = true;
$AUTH_TAB_KEY       = 'outbound';
$AUTH_DEFAULT_TAB   = 'outbound';
$AUTH_DENY_MODE     = 'message';

require __DIR__ . '/../inc/auth_embed.php';

/**
 * Auftrag-ID IMMER direkt am Anfang lesen,
 * bevor sie irgendwo benutzt wird.
 */
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    exit('order_id fehlt.');
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kommi Check-in</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

  <style>
    #qrBox {
      min-height: 220px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #fff;
      border: 1px dashed #ddd;
      border-radius: .5rem;
    }

    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .verify-actions {
      display: none;
    }

    .verify-actions.show {
      display: flex;
    }
  </style>
</head>
<body class="bg-light">
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Check-in Auftrag <span class="mono">#<?= (int)$orderId ?></span></h1>
    <a href="/kommi/orders.php?embed=1" class="btn btn-outline-secondary btn-sm">Zurück</a>
  </div>

  <?php if ($orderId <= 0): ?>
    <div class="alert alert-danger">order_id fehlt.</div>
  <?php else: ?>

    <div id="msg" class="alert alert-secondary py-2">Bitte Schritt 1 starten.</div>

    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card" id="step1Card">
          <div class="card-header"><strong>Schritt 1 – Auftrags-QR erzeugen & scannen</strong></div>
          <div class="card-body">
            <button id="btnCreateChallenge" class="btn btn-primary btn-sm mb-2" type="button">Auftrags-QR erzeugen</button>

            <div id="qrBox" class="mb-2">
              <span class="text-muted small">Noch kein QR erzeugt</span>
            </div>

            <div class="small text-muted mb-2">Gültig: <span id="challengeCountdown">—</span></div>

            <label class="form-label small">Scan Auftrags-QR</label>
            <div class="d-flex gap-2">
              <input id="challengeScanInput" class="form-control" placeholder="QR scannen (ORDER-Challenge)">
              <button id="btnVerifyChallenge" class="btn btn-success" type="button" disabled>Prüfen</button>
            </div>

            <div class="small mt-2">Status: <span id="step1Status" class="badge text-bg-secondary">offen</span></div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card" id="step2Card">
          <div class="card-header"><strong>Schritt 2 – Bereitsteller verifizieren</strong></div>
          <div class="card-body">
            <label class="form-label small">Personalnummer scannen/eingeben</label>
            <div class="d-flex gap-2">
              <input id="prepScanInput" class="form-control" placeholder="Personalnummer scannen" disabled>
              <button id="btnVerifyPreparer" class="btn btn-success" type="button" disabled>Prüfen</button>
            </div>

            <div class="small mt-2">Verifiziert: <span id="prepWho" class="mono">—</span></div>
            <div class="small mt-2">Status: <span id="step2Status" class="badge text-bg-secondary">offen</span></div>

            <div id="step2Actions" class="verify-actions gap-2 mt-3">
              <button id="btnContinueAfterPreparer" class="btn btn-success btn-sm" type="button">
                Weiter
              </button>
              <button id="btnResetPreparerVerify" class="btn btn-outline-warning btn-sm" type="button">
                Bereitsteller neu verifizieren
              </button>
            </div>
          </div>
        </div>

        <div class="card mt-3" id="step3Card">
          <div class="card-header"><strong>Schritt 3 – Verlader verifizieren</strong></div>
          <div class="card-body">
            <label class="form-label small">Personalnummer scannen/eingeben</label>
            <div class="d-flex gap-2">
              <input id="loadScanInput" class="form-control" placeholder="Personalnummer scannen" disabled>
              <button id="btnVerifyLoader" class="btn btn-success" type="button" disabled>Prüfen</button>
            </div>

            <div class="small mt-2">Verifiziert: <span id="loadWho" class="mono">—</span></div>
            <div class="small mt-2">Status: <span id="step3Status" class="badge text-bg-secondary">offen</span></div>

            <div id="step3Actions" class="verify-actions gap-2 mt-3">
              <button id="btnContinueAfterLoader" class="btn btn-success btn-sm" type="button">
                Weiter
              </button>
              <button id="btnResetLoaderVerify" class="btn btn-outline-warning btn-sm" type="button">
                Verlader neu verifizieren
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3" id="step4Card">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <button id="btnGrantAndOpen" class="btn btn-dark" type="button" disabled>Board freischalten & öffnen</button>
        <span class="text-muted small">Schritt 1–3 müssen erfolgreich sein.</span>
      </div>
    </div>

  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js" defer></script>
<script src="/kommi/js/checkin.js?v=3" defer></script>

<?php if ($orderId > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const orderId = <?= (int)$orderId ?>;

  const step2StatusEl = document.getElementById('step2Status');
  const step3StatusEl = document.getElementById('step3Status');
  const prepWhoEl     = document.getElementById('prepWho');
  const loadWhoEl     = document.getElementById('loadWho');

  const step2Actions  = document.getElementById('step2Actions');
  const step3Actions  = document.getElementById('step3Actions');

  const btnContinueAfterPreparer = document.getElementById('btnContinueAfterPreparer');
  const btnContinueAfterLoader   = document.getElementById('btnContinueAfterLoader');

  const btnResetPreparerVerify   = document.getElementById('btnResetPreparerVerify');
  const btnResetLoaderVerify     = document.getElementById('btnResetLoaderVerify');

  const step3Card = document.getElementById('step3Card');
  const step4Card = document.getElementById('step4Card');

  function badgeIsOk(el) {
    if (!el) return false;
    const txt = (el.textContent || '').trim().toLowerCase();
    return txt === 'ok';
  }

  function whoHasValue(el) {
    if (!el) return false;
    const txt = (el.textContent || '').trim();
    return txt !== '' && txt !== '—';
  }

  function updateVerifyActionVisibility() {
    const prepOk = badgeIsOk(step2StatusEl) && whoHasValue(prepWhoEl);
    const loadOk = badgeIsOk(step3StatusEl) && whoHasValue(loadWhoEl);

    if (step2Actions) {
      step2Actions.classList.toggle('show', prepOk);
    }

    if (step3Actions) {
      step3Actions.classList.toggle('show', loadOk);
    }
  }

  async function postReset(url) {
    const fd = new FormData();
    fd.append('order_id', String(orderId));

    const res = await fetch(url, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });

    const j = await res.json().catch(() => ({}));

    if (!j.ok) {
      throw new Error(j.error || 'Zurücksetzen fehlgeschlagen.');
    }

    return j;
  }

  btnContinueAfterPreparer?.addEventListener('click', () => {
    if (step3Card) {
      step3Card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  btnContinueAfterLoader?.addEventListener('click', () => {
    if (step4Card) {
      step4Card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  btnResetPreparerVerify?.addEventListener('click', async () => {
    if (!confirm('Bereitsteller-Verifizierung wirklich zurücksetzen?')) return;

    try {
      await postReset('/kommi/api/reset_preparer_verification.php');
      location.reload();
    } catch (err) {
      alert(err.message || 'Bereitsteller konnte nicht zurückgesetzt werden.');
    }
  });

  btnResetLoaderVerify?.addEventListener('click', async () => {
    if (!confirm('Verlader-Verifizierung wirklich zurücksetzen?')) return;

    try {
      await postReset('/kommi/api/reset_loader_verification.php');
      location.reload();
    } catch (err) {
      alert(err.message || 'Verlader konnte nicht zurückgesetzt werden.');
    }
  });

  const observer = new MutationObserver(() => {
    updateVerifyActionVisibility();
  });

  [step2StatusEl, step3StatusEl, prepWhoEl, loadWhoEl].forEach(el => {
    if (el) {
      observer.observe(el, {
        childList: true,
        subtree: true,
        characterData: true
      });
    }
  });

  updateVerifyActionVisibility();
});
</script>
<?php endif; ?>

</body>
</html>