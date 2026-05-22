<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

$AUTH_DEFAULT_TAB   = 'outbound';
$AUTH_ALLOWED_ROLES = ['admin','disposition','staplerfahrer','verpacker'];
$AUTH_REQUIRE_EMBED = true;
require __DIR__ . '/../inc/auth_embed.php';

require __DIR__ . '/inc/board_guard.php';

$orderId = (int)($_GET['order_id'] ?? 0);
kommi_require_board_access($pdo, $orderId);

$ctx          = $_SESSION['kommi_board_ctx'][(string)$orderId] ?? [];
$currentUser  = (string)($_SESSION['username'] ?? '');
$preparerUser = (string)($ctx['preparer_user'] ?? '');
$loaderUser   = (string)($ctx['loader_user'] ?? '');
$embedFlag    = ((string)($_GET['embed'] ?? '') === '1');


function js($value): string {
  return json_encode(
    $value,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
  ) ?: 'null';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kommi Board</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</head>

<body class="bg-light">
<div class="container py-3">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <h1 class="h4 mb-0">Kommissionierauftrag</h1>
    <span class="badge" id="badgeStatus">…</span>
  </div>

  <!-- Progress (Pick / Load) -->
  <div class="mb-3">
    <div class="small text-muted mb-1" id="progressText">Pick … | Load …</div>
    <div class="progress" style="height:10px;">
      <div id="barPick" class="progress-bar bg-primary" role="progressbar" style="width:0%"></div>
      <div id="barLoad" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
    </div>
  </div>

  <!-- Order-Infos -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-3">
          <div class="text-muted small">Order</div>
          <div class="fw-semibold" id="vOrderNo">–</div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Ausgangsnummer</div>
          <div class="fw-semibold" id="vSourceAusgang">–</div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Bereitgestellt</div>
          <div class="fw-semibold" id="vExitGate">–</div>
        </div>
        <div class="col-md-3">
          <div class="text-muted small">Reserviert</div>
          <div class="fw-semibold" id="vReserved">–</div>
        </div>
      </div>
    </div>
  </div>

  <!-- PHASE PICK -->
  <div id="phasePick">
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <input id="pickScanInput" class="form-control" style="max-width:360px"
                 placeholder="Referenz scannen (Pick)" autocomplete="off">
          <button id="pickScanBtn" class="btn btn-primary">Scannen</button>
          <span class="text-muted" id="pickScanInfo"></span>
        </div>
        <div class="small text-muted mt-2">Tipp: Scanner → Enter löst Scan aus.</div>
      </div>
    </div>
  </div>

  <!-- PHASE EXIT -->
  <div id="phaseExit" class="d-none">
    <div class="card mb-3" id="exitCard">
      <div class="card-body">
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <span class="fw-semibold">Ausgang:</span>
          <button id="btnExit1" class="btn btn-outline-secondary">Ausgang 1</button>
          <button id="btnExit2" class="btn btn-outline-secondary">Ausgang 2</button>
          <span class="text-muted" id="exitInfo"></span>
        </div>
        <div class="small text-muted mt-2">
          Nur möglich, wenn alle Paletten gepickt sind. Danach Ausgang wählen (1 oder 2).
        </div>
      </div>
    </div>
  </div>

  <!-- PHASE LOAD -->
  <div id="phaseLoad" class="d-none">
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <input id="loadScanInput" class="form-control" style="max-width:360px"
                 placeholder="Referenz scannen (Load/Doppelcheck)" autocomplete="off">
          <button id="loadScanBtn" class="btn btn-success">Doppelcheck</button>
          <span class="text-muted" id="loadScanInfo"></span>
        </div>
        <div class="small text-muted mt-2">Nur möglich, wenn der Auftrag bereitgestellt ist.</div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <button id="btnFinalize" class="btn btn-dark">✅ Verladen OK</button>
          <span class="text-muted" id="finalizeInfo"></span>
        </div>
        <div class="small text-muted mt-2">Nur möglich, wenn alle Paletten im Doppelcheck gescannt wurden.</div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header"><strong>Soll-Positionen</strong></div>
        <div class="card-body p-2">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Sachnummer</th><th class="text-end">Soll</th><th class="text-end">Res.</th></tr></thead>
              <tbody id="tbLines"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header"><strong>Reservierte Paletten</strong></div>
        <div class="card-body p-2">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Referenz</th>
                  <th>Zone</th><th>Reihe</th><th class="text-end">Platz</th><th class="text-end">Slot</th>
                  <th>Pick</th><th>Load</th>
                </tr>
              </thead>
              <tbody id="tbPallets"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<div id="sigModal" class="sig-modal" hidden>
  <div class="sig-backdrop"></div>
  <div class="sig-card">
    <div class="sig-head">
      <strong>Unterschrift Bereitsteller</strong>
      <button type="button" id="sigCloseBtn">✕</button>
    </div>

    <div class="sig-body">
      <p style="margin:0 0 8px; font-size:14px; color:#555;">
        Bitte mit dem Finger unterschreiben.
      </p>

      <canvas id="signatureCanvas" width="800" height="260"></canvas>
    </div>

    <div class="sig-actions">
      <button type="button" id="sigClearBtn">Leeren</button>
      <button type="button" id="sigSaveBtn">Speichern & bereitstellen</button>
    </div>
  </div>
</div>

<style>
.sig-modal[hidden] { display:none; }
.sig-modal {
  position: fixed; inset: 0; z-index: 9999;
}
.sig-backdrop {
  position: absolute; inset: 0; background: rgba(0,0,0,.45);
}
.sig-card {
  position: relative;
  z-index: 1;
  width: min(95vw, 720px);
  margin: 4vh auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 30px rgba(0,0,0,.25);
  overflow: hidden;
}
.sig-head {
  display:flex; justify-content:space-between; align-items:center;
  padding: 10px 12px; border-bottom:1px solid #ddd;
}
.sig-head button {
  border:0; background:transparent; font-size:18px; cursor:pointer;
}
.sig-body { padding: 12px; }
#signatureCanvas {
  width: 100%;
  height: 220px;
  border: 2px dashed #999;
  border-radius: 8px;
  background: #fff;
  touch-action: none; /* wichtig für Handy */
}
.sig-actions {
  display:flex; gap:8px; justify-content:flex-end;
  padding: 10px 12px; border-top:1px solid #ddd;
}
.sig-actions button {
  padding: 8px 12px; border-radius: 8px; border:1px solid #bbb; background:#f7f7f7;
}
#sigSaveBtn {
  background:#0d6efd; color:#fff; border-color:#0d6efd;
}
</style>
<script>
(() => {
  const modal = document.getElementById('sigModal');
  const canvas = document.getElementById('signatureCanvas');
  if (!modal || !canvas) return;

  const ctx = canvas.getContext('2d');

  const btnClose = document.getElementById('sigCloseBtn');
  const btnClear = document.getElementById('sigClearBtn');
  const btnSave  = document.getElementById('sigSaveBtn');

  let drawing = false;
  let hasStroke = false;
  let currentOrderId = 0;

  let currentSignatureType = 'prepared'; // 'prepared' | 'loaded'
  let afterSignatureSave = null;

  function openSignatureModal(orderId, sigType = 'prepared', onSuccess = null) {
    currentOrderId = Number(orderId || 0);
    currentSignatureType = (sigType === 'loaded') ? 'loaded' : 'prepared';
    afterSignatureSave = (typeof onSuccess === 'function') ? onSuccess : null;

    const titleEl = document.querySelector('#sigModal .sig-head strong');
    const saveBtn = document.getElementById('sigSaveBtn');

    if (titleEl) {
      titleEl.textContent = currentSignatureType === 'loaded'
        ? 'Unterschrift Verlader'
        : 'Unterschrift Bereitsteller';
    }

    if (saveBtn) {
      saveBtn.textContent = currentSignatureType === 'loaded'
        ? 'Speichern & Verladen OK'
        : 'Speichern & bereitstellen';
    }

    modal.hidden = false;
    setTimeout(resizeCanvasForDevicePixelRatio, 10);
    clearCanvas();
  }

  function closeSignatureModal() {
    modal.hidden = true;
    currentOrderId = 0;
    afterSignatureSave = null;
  }

  function resizeCanvasForDevicePixelRatio() {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const rect = canvas.getBoundingClientRect();

    canvas.width = Math.round(rect.width * ratio);
    canvas.height = Math.round(rect.height * ratio);

    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';

    // weiße Fläche (wichtig für PNG)
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    hasStroke = false;
  }

  function clearCanvas() {
    const rect = canvas.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    hasStroke = false;
  }

  function getPos(ev) {
    const rect = canvas.getBoundingClientRect();
    const p = ev.touches ? ev.touches[0] : ev;
    return { x: p.clientX - rect.left, y: p.clientY - rect.top };
  }

  function startDraw(ev) {
    ev.preventDefault();
    drawing = true;
    const p = getPos(ev);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    hasStroke = true;
  }

  function moveDraw(ev) {
    if (!drawing) return;
    ev.preventDefault();
    const p = getPos(ev);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }

  function endDraw(ev) {
    if (!drawing) return;
    ev.preventDefault();
    drawing = false;
    ctx.closePath();
  }

  // Mouse
  canvas.addEventListener('mousedown', startDraw);
  canvas.addEventListener('mousemove', moveDraw);
  window.addEventListener('mouseup', endDraw);

  // Touch
  canvas.addEventListener('touchstart', startDraw, { passive: false });
  canvas.addEventListener('touchmove', moveDraw, { passive: false });
  canvas.addEventListener('touchend', endDraw, { passive: false });
  canvas.addEventListener('touchcancel', endDraw, { passive: false });

  btnClose?.addEventListener('click', closeSignatureModal);
  btnClear?.addEventListener('click', clearCanvas);

  btnSave?.addEventListener('click', async () => {
    if (!currentOrderId) {
      alert('Auftrag fehlt.');
      return;
    }
    if (!hasStroke) {
      alert('Bitte zuerst unterschreiben.');
      return;
    }

    try {
      btnSave.disabled = true;
      btnSave.textContent = 'Speichert...';

      const dataUrl = canvas.toDataURL('image/png');

      const endpoint = currentSignatureType === 'loaded'
        ? '/api/kommi_mark_loaded_signed.php'
        : '/api/kommi_mark_prepared_signed.php';

      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          order_id: currentOrderId,
          signature: dataUrl
        })
      });

      const raw = await res.text();
      let json;
      try {
        json = JSON.parse(raw);
      } catch {
        throw new Error(raw.slice(0, 300));
      }

      if (!res.ok || !json.ok) {
        throw new Error(json.message || json.error || 'Speichern fehlgeschlagen');
      }

      // Werte sichern, BEVOR closeSignatureModal() sie zurücksetzt
const sigType = currentSignatureType;
const onSuccess = afterSignatureSave;

closeSignatureModal();

if (sigType === 'loaded') {
  if (typeof onSuccess === 'function') {
    await onSuccess(); // finalizeOrderCore() läuft jetzt wirklich
    return;
  }

  if (window.showExitToast) {
    window.showExitToast('✅ Verlader-Unterschrift gespeichert.', 1400);
  }
  return;
}

      // prepared (alter Ablauf)
      if (window.showExitToast) {
        window.showExitToast('✅ Unterschrift gespeichert – zurück zur Auftragsliste …', 1400);
      }

      if (window.goToOrders) {
        window.goToOrders(900);
      } else {
        setTimeout(() => {
          location.href = '/kommi/orders.php?embed=1';
        }, 900);
      }

    } catch (err) {
      alert(err.message || 'Fehler beim Speichern.');
    } finally {
      btnSave.disabled = false;
      btnSave.textContent = currentSignatureType === 'loaded'
        ? 'Speichern & Verladen OK'
        : 'Speichern & bereitstellen';
    }
  });

  // Global verfügbar machen
  window.openPreparedSignatureModal = (orderId) => openSignatureModal(orderId, 'prepared');
  window.openLoaderSignatureModal   = (orderId, onSuccess) => openSignatureModal(orderId, 'loaded', onSuccess);

  window.addEventListener('resize', () => {
    if (!modal.hidden) resizeCanvasForDevicePixelRatio();
  });
})();
</script>

<script>
  window.__KOMMI_ORDER_ID__ = <?= (int)$orderId ?>;
  window.__KOMMI_EMBED__ = <?= js($embedFlag) ?>;
  window.__KOMMI_CTX__ = {
    currentUser: <?= js($currentUser) ?>,
    preparerUser: <?= js($preparerUser) ?>,
    loaderUser: <?= js($loaderUser) ?>
  };
</script>

<!-- Cache-Buster hochdrehen -->
<script src="/kommi/js/kommi.board.js?v=10"></script>
</body>
</html>
