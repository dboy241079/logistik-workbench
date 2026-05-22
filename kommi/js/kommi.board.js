(() => {
  const orderId = Number(window.__KOMMI_ORDER_ID__ || 0);

  const API_DETAIL  = '/kommi/api/kommi_api.php?action=detail&order_id=' + encodeURIComponent(orderId);
  const API_PICK    = '/kommi/api/scan_pick.php';
  const API_EXIT    = '/kommi/api/set_exit.php';
  const API_LOAD    = '/kommi/api/scan_load.php';
  const API_FINAL   = '/kommi/api/finalize.php';
  const API_VERIFY_USER_CODE = '/kommi/api/verify_user_code.php';


  const AUTO_REFRESH_MS = 5000;
  const INFO_TTL_MS = 2600;

  const el = (id) => document.getElementById(id);

  let _didScrollToExit = false;
  let _didScrollToLoad = false;
  let _loadSeq = 0;
  let _pollTimer = null;
  let _audioCtx = null;
  let _exitInfoTimer = null;
  let _loaderVerified = false;

  const _infoTimers = new WeakMap();

  const normalizeRef = (v) => String(v ?? '').trim().replace(/\s+/g, '');
  const isValidRef = (ref) => ref.length === 14; // exakt 14


  async function ensureLoaderVerifiedBeforeLoad() {
  if (_loaderVerified) return true;

  const raw = window.prompt('Verlader-Personalnummer scannen/eingeben:');
  if (raw === null) return false; // Abbruch

  const code = String(raw || '').replace(/\s+/g, '');
  if (!/^\d{3,32}$/.test(code)) {
    showCenterMessage('Ungültige Personalnummer', 'Bitte eine gültige Personalnummer eingeben.', 'warning');
    return false;
  }

  try {
    const j = await fetchJson(API_VERIFY_USER_CODE, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, phase: 'LOADER', scan: code })
    });

    if (!j.ok) throw new Error(j.error || 'Verlader-Verifizierung fehlgeschlagen.');

    _loaderVerified = true;
    const info = el('loadScanInfo');
    if (info) info.textContent = `Verlader: ${j.username}`;
    return true;
  } catch (e) {
    showCenterMessage('Verlader-Verifizierung fehlgeschlagen', e.message, 'danger');
    return false;
  }
}

function shouldAutoReturnAfterFinalize(apiResp) {
  const st = String(apiResp?.status || '').toUpperCase();
  return st === 'VERLADEN_OK';
}

  function showCenterMessage(title, msg, type = 'danger') {
    let wrap = document.getElementById('kommiMsgWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'kommiMsgWrap';
      wrap.style.position = 'fixed';
      wrap.style.inset = '0';
      wrap.style.zIndex = '3000';
      wrap.style.display = 'grid';
      wrap.style.placeItems = 'center';
      wrap.style.background = 'rgba(0,0,0,.35)';
      wrap.innerHTML = `
        <div class="card shadow" style="max-width:560px;width:92vw;">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong id="kommiMsgTitle"></strong>
            <button type="button" class="btn-close" aria-label="Schließen"></button>
          </div>
          <div class="card-body">
            <div id="kommiMsgBody" class="alert mb-0"></div>
          </div>
        </div>
      `;
      document.body.appendChild(wrap);
      wrap.querySelector('.btn-close').addEventListener('click', () => wrap.remove());
      wrap.addEventListener('click', (e) => { if (e.target === wrap) wrap.remove(); });
    }
    wrap.querySelector('#kommiMsgTitle').textContent = title;
    const body = wrap.querySelector('#kommiMsgBody');
    body.className = `alert alert-${type} mb-0`;
    body.textContent = msg;
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/`/g, '&#096;');
  }

  function setTransientInfo(node, text, ttl = INFO_TTL_MS) {
    if (!node) return;
    node.textContent = text || '';

    const old = _infoTimers.get(node);
    if (old) clearTimeout(old);

    if (!text) return;
    const tid = setTimeout(() => {
      if (node.textContent === text) node.textContent = '';
      _infoTimers.delete(node);
    }, ttl);

    _infoTimers.set(node, tid);
  }

  function blinkInvalidInput(inputEl) {
    if (!inputEl) return;
    inputEl.classList.add('is-invalid', 'scan-invalid');
    if (inputEl.__invalidTimer) clearTimeout(inputEl.__invalidTimer);
    inputEl.__invalidTimer = setTimeout(() => {
      inputEl.classList.remove('is-invalid', 'scan-invalid');
    }, 700);
  }

  function injectStyles() {
    if (document.getElementById('kommi-board-dyn-style')) return;
    const style = document.createElement('style');
    style.id = 'kommi-board-dyn-style';
    style.textContent = `
      @keyframes rowFlashPick {
        0%   { background-color: rgba(25, 135, 84, .38); }
        100% { background-color: transparent; }
      }
      @keyframes rowFlashLoad {
        0%   { background-color: rgba(13, 110, 253, .34); }
        100% { background-color: transparent; }
      }
      tr.flash-pick td { animation: rowFlashPick .9s ease; }
      tr.flash-load td { animation: rowFlashLoad .9s ease; }

      @keyframes scanInvalidShake {
        0%,100% { transform: translateX(0); }
        25%     { transform: translateX(-3px); }
        75%     { transform: translateX(3px); }
      }
      .scan-invalid {
        animation: scanInvalidShake .18s linear 2;
      }
    `;
    document.head.appendChild(style);
  }

  function ensureLastScanBadge(inputId, badgeId) {
    const existing = document.getElementById(badgeId);
    if (existing) return existing;

    const input = el(inputId);
    if (!input) return null;

    const host = input.parentElement;
    if (!host) return null;

    const wrap = document.createElement('div');
    wrap.id = badgeId;
    wrap.className = 'mb-2 d-none';
    wrap.innerHTML = `
      <span class="badge rounded-pill border text-bg-light">
        <span class="js-state fw-semibold me-2">OK</span>
        <span class="js-ref">—</span>
        <span class="js-time ms-2"></span>
      </span>
    `;
    host.insertBefore(wrap, input);
    return wrap;
  }

  function updateLastScanBadge(which, ref, state = 'ok') {
    const isPick = which === 'pick';
    const badgeId = isPick ? 'lastPickBadge' : 'lastLoadBadge';
    const inputId = isPick ? 'pickScanInput' : 'loadScanInput';

    const wrap = document.getElementById(badgeId) || ensureLastScanBadge(inputId, badgeId);
    if (!wrap) return;

    const pill = wrap.querySelector('.badge');
    const st = wrap.querySelector('.js-state');
    const rf = wrap.querySelector('.js-ref');
    const tm = wrap.querySelector('.js-time');

    pill.className = 'badge rounded-pill border';
    if (state === 'ok') {
      pill.classList.add('text-bg-success');
      if (st) st.textContent = 'OK';
    } else if (state === 'dup') {
      pill.classList.add('text-bg-warning');
      if (st) st.textContent = 'DUP';
    } else {
      pill.classList.add('text-bg-danger');
      if (st) st.textContent = 'ERR';
    }

    if (rf) rf.textContent = ref || '—';
    if (tm) tm.textContent = new Date().toLocaleTimeString('de-DE', {
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });

    wrap.classList.remove('d-none');
  }

  function highlightRowByRef(ref, mode = 'pick') {
    const tbody = el('tbPallets');
    if (!tbody) return;

    const nRef = normalizeRef(ref);
    let row = Array.from(tbody.querySelectorAll('tr[data-ref]'))
      .find((r) => normalizeRef(r.dataset.ref) === nRef);

    if (!row) {
      row = Array.from(tbody.querySelectorAll('tr'))
        .find((r) => normalizeRef(r.children?.[0]?.textContent || '') === nRef);
    }
    if (!row) return;

    row.classList.remove('flash-pick', 'flash-load');
    void row.offsetWidth; // restart animation
    row.classList.add(mode === 'load' ? 'flash-load' : 'flash-pick');

    setTimeout(() => row.classList.remove('flash-pick', 'flash-load'), 950);
  }

  function getAudioCtx() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    if (!_audioCtx) _audioCtx = new Ctx();
    return _audioCtx;
  }

  async function unlockAudio() {
    const ctx = getAudioCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      try { await ctx.resume(); } catch {}
    }
  }

  function tone(freq, dur = 0.08, type = 'sine', gainVal = 0.035, startOffset = 0) {
    const ctx = getAudioCtx();
    if (!ctx) return;

    const t0 = ctx.currentTime + startOffset;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = type;
    osc.frequency.setValueAtTime(freq, t0);

    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(gainVal, t0 + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start(t0);
    osc.stop(t0 + dur + 0.01);
  }

  function playSuccess() {
    tone(880, 0.06, 'sine', 0.03, 0.00);
    tone(1240, 0.07, 'sine', 0.03, 0.08);
  }

  function playDuplicate() {
    tone(640, 0.05, 'triangle', 0.028, 0.00);
    tone(640, 0.05, 'triangle', 0.028, 0.08);
  }

  function playError() {
    tone(430, 0.09, 'sawtooth', 0.035, 0.00);
    tone(260, 0.11, 'sawtooth', 0.035, 0.10);
  }

async function fetchJson(url, opts) {
  const res = await fetch(url, opts);
  const ct = res.headers.get('content-type') || '';
  const raw = await res.text();

  if (!ct.includes('application/json')) {
    console.error('API kein JSON:', url, ct, raw);
    throw new Error('API lieferte kein JSON (siehe Console).');
  }

  try {
    return JSON.parse(raw);
  } catch (e) {
    console.error('JSON Parse Fehler:', url, raw);
    throw new Error('Ungültiges JSON von API (siehe Console).');
  }
}

  function applyStatusBadge(status) {
  const b = el('badgeStatus');
  if (!b) return;

  const STATUS_LABELS = {
    OFFEN: 'Offen',
    KOMMISSIONIERUNG: 'Bereitstellung', // <- neuer UI-Name
    BEREITGESTELLT: 'Bereitgestellt',
    VERLADUNG: 'Verladung',
    VERLADEN_OK: 'Verladen OK',
    PROBLEM: 'Problem'
  };

  b.textContent = STATUS_LABELS[status] || status || '—';
  b.className = 'badge';

  if (status === 'OFFEN') b.classList.add('text-bg-secondary');
  else if (status === 'KOMMISSIONIERUNG') b.classList.add('text-bg-primary');
  else if (status === 'BEREITGESTELLT') b.classList.add('text-bg-warning');
  else if (status === 'VERLADUNG') b.classList.add('text-bg-info');
  else if (status === 'VERLADEN_OK') b.classList.add('text-bg-success');
  else if (status === 'PROBLEM') b.classList.add('text-bg-danger');
  else b.classList.add('text-bg-secondary');
}



function applyPhases(status, prog) {
  const phasePick = el('phasePick');
  const phaseExit = el('phaseExit');
  const phaseLoad = el('phaseLoad');

  const pickComplete = (prog.pickTotal > 0 && prog.pickDone === prog.pickTotal);

  // PICK nur OFFEN/KOMMISSIONIERUNG
  const showPick = (status === 'OFFEN' || status === 'KOMMISSIONIERUNG');
  if (phasePick) phasePick.classList.toggle('d-none', !showPick);

  // EXIT sichtbar sobald Pick komplett oder bereitgestellt+
  const showExit = pickComplete || ['BEREITGESTELLT', 'VERLADUNG', 'VERLADEN_OK'].includes(status);
  if (phaseExit) phaseExit.classList.toggle('d-none', !showExit);

  // LOAD ab bereitgestellt
  const showLoad = ['BEREITGESTELLT', 'VERLADUNG', 'VERLADEN_OK'].includes(status);
  if (phaseLoad) phaseLoad.classList.toggle('d-none', !showLoad);

  // Auto-scroll zu EXIT wenn Pick komplett (und noch nicht bereitgestellt)
  if (pickComplete && !_didScrollToExit && status !== 'BEREITGESTELLT') {
    const card = el('exitCard');
    if (card) {
      _didScrollToExit = true;
      setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
    }
  }

  // Auto-scroll zu LOAD wenn bereitgestellt (oder verladen startet)
  if (showLoad && !_didScrollToLoad) {
    const loadInput = el('loadScanInput');
    if (loadInput) {
      _didScrollToLoad = true;
      setTimeout(() => {
        loadInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        loadInput.focus();
      }, 200);
    }
  }
}
async function load({ silent = false } = {}) {
  if (!orderId) {
    if (!silent) showCenterMessage('Fehler', 'order_id fehlt in der URL.', 'warning');
    return;
  }

  const seq = ++_loadSeq;

  let j;
  try {
    j = await fetchJson(API_DETAIL, { credentials: 'same-origin' });
  } catch (e) {
    if (seq !== _loadSeq) return;
    if (!silent) showCenterMessage('Fehler', e.message, 'danger');
    else console.warn('Auto-Refresh fehlgeschlagen:', e.message);
    return;
  }

  if (seq !== _loadSeq) return; // veraltete Antwort ignorieren

  if (!j.ok) {
    if (j.code === 'BOARD_ACCESS_REQUIRED') {
      window.location.href = '/kommi/checkin.php?order_id=' + encodeURIComponent(orderId) + '&embed=1';
      return;
    }

    if (!silent) showCenterMessage('Fehler', j.error || 'Laden fehlgeschlagen', 'danger');
    else console.warn('Auto-Refresh API-Fehler:', j.error || 'Laden fehlgeschlagen');
    return;
  }

  // WICHTIG: Loader-Status aus Serverdaten synchronisieren
  _loaderVerified = !!(j.order?.assigned_loader);

  const status = j.order?.status || '';
  applyStatusBadge(status);

  const prog = applyProgress(j.progress || {});
  applyPhases(status, prog);
  applyExitState(status, prog, j.order?.exit_gate);

  const vOrderNo = el('vOrderNo');
  const vSourceAusgang = el('vSourceAusgang');
  const vExitGate = el('vExitGate');
  const vReserved = el('vReserved');

  if (vOrderNo) vOrderNo.textContent = j.order?.order_no || '-';
  if (vSourceAusgang) vSourceAusgang.textContent = j.order?.source_ausgang_nr || '-';
  if (vExitGate) vExitGate.textContent = j.order?.exit_gate ? `Ausgang ${j.order.exit_gate}` : '—';
  if (vReserved) vReserved.textContent = (j.pallets?.length || 0) + ' Paletten';

  const tbLines = el('tbLines');
  if (tbLines) {
    tbLines.innerHTML = (j.lines || []).map((x) => `
      <tr>
        <td>${escapeHtml(x.sachnummer)}</td>
        <td class="text-end">${x.qty_required}</td>
        <td class="text-end">${x.qty_reserved}</td>
      </tr>
    `).join('') || `<tr><td colspan="3" class="text-muted">Keine Lines</td></tr>`;
  }

  const tbPallets = el('tbPallets');
  if (tbPallets) {
    tbPallets.innerHTML = (j.pallets || []).map((p) => `
      <tr data-ref="${escapeHtml(p.ref_no)}">
        <td class="fw-semibold">${escapeHtml(p.ref_no)}</td>
        <td>${escapeHtml(p.zone)}</td>
        <td>${escapeHtml(p.reihe)}</td>
        <td class="text-end">${p.platz ?? ''}</td>
        <td class="text-end">${p.slot ?? ''}</td>
        <td>${p.pick_scanned_at ? '✅' : '—'}</td>
        <td>${p.load_scanned_at ? '✅' : '—'}</td>
      </tr>
    `).join('') || `<tr><td colspan="7" class="text-muted">Keine Reservierungen</td></tr>`;
  }

  // Optional: kurzen Hinweis anzeigen, wenn Loader schon gesetzt ist
  if (_loaderVerified && j.order?.assigned_loader) {
    const info = el('loadScanInfo');
    if (info && !info.textContent) {
      setTransientInfo(info, `Verlader: ${j.order.assigned_loader}`, 1800);
    }
  }
  return j;
}


function ensureExitHeaderBadge() {
  const statusBadge = el('badgeStatus');
  if (!statusBadge) return null;

  let gateBadge = document.getElementById('badgeExitGate');
  if (gateBadge) return gateBadge;

  gateBadge = document.createElement('span');
  gateBadge.id = 'badgeExitGate';
  gateBadge.className = 'badge text-bg-success ms-2 d-none';
  gateBadge.dataset.gate = '0';
  gateBadge.textContent = 'Ausgang —';

  statusBadge.insertAdjacentElement('afterend', gateBadge);
  return gateBadge;
}

function renderExitHeaderBadge(exitGateRaw) {
  const gateBadge = ensureExitHeaderBadge();
  if (!gateBadge) return;

  const gate = Number(exitGateRaw || 0);
  gateBadge.dataset.gate = String(gate || 0);

  if (gate === 1 || gate === 2) {
    gateBadge.classList.remove('d-none', 'text-bg-secondary');
    gateBadge.classList.add('text-bg-success');
    gateBadge.textContent = `Ausgang ${gate}`;
  } else {
    gateBadge.classList.add('d-none');
    gateBadge.textContent = 'Ausgang —';
  }
}

function getCurrentExitGateUi() {
  const gateBadge = document.getElementById('badgeExitGate');
  const gateFromBadge = Number(gateBadge?.dataset?.gate || 0);
  if (gateFromBadge === 1 || gateFromBadge === 2) return gateFromBadge;

  const b1 = el('btnExit1');
  const b2 = el('btnExit2');
  if (b1?.classList.contains('btn-success')) return 1;
  if (b2?.classList.contains('btn-success')) return 2;
  return 0;
}

function setExitInfo(msg, ttl = 2600) {
  const info = el('exitInfo');
  if (!info) return;

  info.textContent = msg || '';

  if (setExitInfo._timer) clearTimeout(setExitInfo._timer);
  if (!msg) return;

  setExitInfo._timer = setTimeout(() => {
    if (info.textContent === msg) info.textContent = '';
  }, ttl);
}

function showExitToast(message, ttl = 1800) {
  let host = document.getElementById('kommiToastHost');
  if (!host) {
    host = document.createElement('div');
    host.id = 'kommiToastHost';
    host.className = 'toast-container position-fixed top-0 end-0 p-3';
    host.style.zIndex = '4000';
    document.body.appendChild(host);
  }

  const item = document.createElement('div');
  item.className = 'toast align-items-center text-bg-dark border-0';
  item.setAttribute('role', 'alert');
  item.setAttribute('aria-live', 'assertive');
  item.setAttribute('aria-atomic', 'true');
  item.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${message}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Schließen"></button>
    </div>
  `;
  host.appendChild(item);

  if (window.bootstrap?.Toast) {
    const t = new bootstrap.Toast(item, { delay: ttl });
    item.addEventListener('hidden.bs.toast', () => item.remove(), { once: true });
    t.show();
  } else {
    item.classList.add('show');
    setTimeout(() => item.remove(), ttl + 150);
  }
}

function setExitButtonsVisual(exitGateRaw) {
  const gate = Number(exitGateRaw || 0);
  const b1 = el('btnExit1');
  const b2 = el('btnExit2');

  const resetBtn = (btn, fallbackLabel) => {
    if (!btn) return;
    if (!btn.dataset.baseLabel) {
      btn.dataset.baseLabel = (btn.textContent || fallbackLabel || '').replace(/\s*✓\s*$/, '').trim();
    }
    btn.classList.remove('btn-success', 'active');
    btn.classList.add('btn-outline-secondary');
    btn.setAttribute('aria-pressed', 'false');
    btn.innerHTML = btn.dataset.baseLabel || fallbackLabel;
  };

  resetBtn(b1, 'Ausgang 1');
  resetBtn(b2, 'Ausgang 2');

  const activateBtn = (btn) => {
    if (!btn) return;
    const label = btn.dataset.baseLabel || btn.textContent.trim();
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success', 'active');
    btn.setAttribute('aria-pressed', 'true');
    btn.innerHTML = `${label} <span class="ms-1" aria-hidden="true">✓</span>`;
  };

  if (gate === 1) activateBtn(b1);
  if (gate === 2) activateBtn(b2);
}

function applyExitState(status, prog, exitGateRaw) {
  const b1 = el('btnExit1');
  const b2 = el('btnExit2');

  const pickComplete = (prog.pickTotal > 0 && prog.pickDone === prog.pickTotal);

  // In VERLADUNG / VERLADEN_OK: fixiert (disabled), aber aktive Markierung bleibt grün
  const canChangeExit = pickComplete && !['VERLADUNG', 'VERLADEN_OK'].includes(status);

  if (b1) b1.disabled = !canChangeExit;
  if (b2) b2.disabled = !canChangeExit;

  setExitButtonsVisual(exitGateRaw);
  renderExitHeaderBadge(exitGateRaw);

  const gate = Number(exitGateRaw || 0);
  if (gate === 1 || gate === 2) {
    setExitInfo(`Aktuell: Ausgang ${gate}`, 2600);
  } else if (pickComplete) {
    setExitInfo('Bitte Ausgang 1 oder 2 auswählen.', 2600);
  } else {
    setExitInfo('Wird aktiv, sobald alle Paletten gepickt sind.', 2600);
  }
}

async function setExitGate(gate) {
  const newGate = Number(gate);
  if (![1, 2].includes(newGate)) return;

  const b1 = el('btnExit1');
  const b2 = el('btnExit2');
  if (!b1 || !b2) return;
  if (b1.disabled && b2.disabled) return;

  const prevGate = getCurrentExitGateUi();
  let didAutoReturn = false;

  b1.disabled = true;
  b2.disabled = true;

  try {
    // 1) Ausgang setzen
    const j = await fetchJson(API_EXIT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, exit_gate: newGate })
    });

    if (!j.ok) throw new Error(j.error || 'Ausgang setzen fehlgeschlagen');

    const appliedGate = Number(j.exit_gate || newGate);

    // Sofort visuelles Feedback
    setExitButtonsVisual(appliedGate);
    renderExitHeaderBadge(appliedGate);
    setExitInfo(`Aktuell: Ausgang ${appliedGate}`, 2600);

    if (prevGate && prevGate !== appliedGate) {
      showExitToast(`Ausgang geändert auf ${appliedGate}`);
    }

    // 2) FRISCHE DETAILDATEN holen (wichtig für sicheren Redirect)
    const fresh = await fetchJson(API_DETAIL, { credentials: 'same-origin' });

    if (fresh?.ok) {
      const freshStatus = String(fresh.order?.status || '').toUpperCase();
      const pickDone  = Number(fresh.progress?.pick_done || 0);
      const pickTotal = Number(fresh.progress?.pick_total || 0);
      const pickComplete = pickTotal > 0 && pickDone === pickTotal;

      console.log('[AUTO-RETURN EXIT]', {
        freshStatus,
        pickDone,
        pickTotal,
        pickComplete,
        exit_gate: fresh.order?.exit_gate
      });

      // ✅ In eurem Prozess: nach Ausgang setzen + BEREITGESTELLT => zurück zur Orders
      if (pickComplete && freshStatus === 'BEREITGESTELLT') {
  didAutoReturn = true;

  // Statt sofort zurück: Unterschrift öffnen
  if (typeof window.openPreparedSignatureModal === 'function') {
    window.openPreparedSignatureModal(orderId);
  } else {
    // Fallback, falls Modal-Script noch nicht geladen ist
    showExitToast(`Ausgang ${appliedGate} gesetzt – Auftrag erledigt.`, 1700);
    setExitInfo('Bereitstellung abgeschlossen.', 1800);
    goToOrders(1200);
  }
  return;
}
    }

  } catch (e) {
    showCenterMessage('Ausgang setzen fehlgeschlagen', e.message, 'danger');
  } finally {
    // Nur neu laden, wenn wir NICHT weg navigieren
    if (!didAutoReturn) {
      load({ silent: true }).catch(() => {
        b1.disabled = false;
        b2.disabled = false;
      });
    }
  }
}



  function applyProgress(prog) {
    const pickDone = Number(prog?.pick_done || 0);
    const pickTotal = Number(prog?.pick_total || 0);
    const loadDone = Number(prog?.load_done || 0);
    const loadTotal = Number(prog?.load_total || 0);

    const pickDen = Math.max(1, pickTotal);
    const loadDen = Math.max(1, loadTotal);

    const pickPct = Math.round((pickDone / pickDen) * 100);
    const loadPct = Math.round((loadDone / loadDen) * 100);

    const t = el('progressText');
    if (t) t.textContent = `Pick ${pickDone}/${pickTotal} | Load ${loadDone}/${loadTotal}`;

    const barPick = el('barPick');
    const barLoad = el('barLoad');

    if (barPick) {
      barPick.style.width = pickPct + '%';
      barPick.className = 'progress-bar bg-primary';
      barPick.title = `Pick: ${pickDone}/${pickTotal}`;
    }
    if (barLoad) {
      barLoad.style.width = loadPct + '%';
      barLoad.className = 'progress-bar bg-success';
      barLoad.title = `Load: ${loadDone}/${loadTotal}`;
    }

    return { pickDone, pickTotal, loadDone, loadTotal };
  }


  async function doPickScan() {
    const inp = el('pickScanInput');
    const info = el('pickScanInfo');
    const btn = el('pickScanBtn');
    if (!inp || !btn) return;
    if (btn.disabled) return;

    await unlockAudio();

    const ref = normalizeRef(inp.value);
    if (!ref) return;

    if (!isValidRef(ref)) {
      blinkInvalidInput(inp);
      setTransientInfo(info, 'Referenznummer muss exakt 14-stellig sein.');
      playError();
      inp.focus();
      return;
    }

    btn.disabled = true;
    try {
      const j = await fetchJson(API_PICK, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_id: orderId, ref_no: ref })
      });

      if (!j.ok) throw new Error(j.error || 'Scan fehlgeschlagen');

      setTransientInfo(info, j.dup ? `Schon gepickt (${j.done}/${j.total})` : `OK (${j.done}/${j.total})`);
      updateLastScanBadge('pick', ref, j.dup ? 'dup' : 'ok');

      if (j.dup) playDuplicate(); else playSuccess();

      inp.value = '';
      inp.focus();

      await load({ silent: true });
      highlightRowByRef(ref, 'pick');
    } catch (e) {
      updateLastScanBadge('pick', ref, 'err');
      playError();
      showCenterMessage('Pick-Scan fehlgeschlagen', e.message, 'danger');
    } finally {
      btn.disabled = false;
    }
  }

  async function doLoadScan() {
  const inp = el('loadScanInput');
  const info = el('loadScanInfo');
  const btn = el('loadScanBtn');
  if (!inp || !btn) return;
  if (btn.disabled) return;

  await unlockAudio();

  const ref = normalizeRef(inp.value);
  if (!ref) return;

  if (!isValidRef(ref)) {
    blinkInvalidInput(inp);
    setTransientInfo(info, 'Referenznummer muss exakt 14-stellig sein.');
    playError();
    inp.focus();
    return;
  }

  // WICHTIG: Step 3 vor erstem Load erzwingen
  const loaderOk = await ensureLoaderVerifiedBeforeLoad();
  if (!loaderOk) {
    setTransientInfo(info, 'Verlader-Verifizierung erforderlich.');
    inp.focus();
    return;
  }

  btn.disabled = true;
  try {
    const j = await fetchJson(API_LOAD, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, ref_no: ref })
    });

    if (!j.ok) throw new Error(j.error || 'Scan fehlgeschlagen');

    setTransientInfo(
      info,
      j.dup ? `Schon im Doppelcheck (${j.done}/${j.total})` : `OK (${j.done}/${j.total})`
    );
    updateLastScanBadge('load', ref, j.dup ? 'dup' : 'ok');

    if (j.dup) playDuplicate(); else playSuccess();

    inp.value = '';
    inp.focus();

    await load({ silent: true });
    highlightRowByRef(ref, 'load');
  } catch (e) {
    updateLastScanBadge('load', ref, 'err');
    playError();
    showCenterMessage('Load-Scan fehlgeschlagen', e.message, 'danger');
  } finally {
    btn.disabled = false;
  }
}
async function finalizeOrderCore() {
  const info = el('finalizeInfo');
  const btn = el('btnFinalize');
  if (!btn) return;
  if (btn.disabled) return;

  btn.disabled = true;
  try {
    const j = await fetchJson(API_FINAL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId })
    });

    if (!j.ok) throw new Error(j.error || 'Finalize fehlgeschlagen');

    setTransientInfo(info, `✅ Verladen OK (${j.total} Paletten)`, 2500);
    playSuccess();
    await load({ silent: true });

    if (shouldAutoReturnAfterFinalize(j)) {
      window.showExitToast?.('✅ Auftrag abgeschlossen – zurück zur Auftragsliste …', 1600);
      goToOrders(1100);
      return;
    }
  } catch (e) {
    playError?.();
    showCenterMessage('Finalize fehlgeschlagen', e.message, 'danger');
    throw e;
  } finally {
    btn.disabled = false;
  }
}

async function finalizeOrder() {
  // Schutz: Nur wenn Load-Phase sichtbar ist
  const phaseLoad = el('phaseLoad');
  if (!phaseLoad || phaseLoad.classList.contains('d-none')) {
    return;
  }

  if (typeof window.openLoaderSignatureModal === 'function') {
    window.openLoaderSignatureModal(orderId, async () => {
      await finalizeOrderCore();
    });
    return;
  }

  await finalizeOrderCore();
}



  function startAutoRefresh() {
    if (_pollTimer) clearInterval(_pollTimer);
    _pollTimer = setInterval(() => {
      if (document.visibilityState === 'visible') {
        load({ silent: true });
      }
    }, AUTO_REFRESH_MS);

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        load({ silent: true });
      }
    });

    window.addEventListener('focus', () => {
      load({ silent: true });
    });
  }

  function init() {
    injectStyles();
    ensureLastScanBadge('pickScanInput', 'lastPickBadge');
    ensureLastScanBadge('loadScanInput', 'lastLoadBadge');

    // Audio freischalten
    document.addEventListener('pointerdown', unlockAudio, { once: true });
    document.addEventListener('keydown', unlockAudio, { once: true });

    // Events
    el('pickScanBtn')?.addEventListener('click', doPickScan);
    el('pickScanInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        doPickScan();
      }
    });
    

    el('btnExit1')?.addEventListener('click', () => setExitGate(1));
    el('btnExit2')?.addEventListener('click', () => setExitGate(2));

    el('loadScanBtn')?.addEventListener('click', doLoadScan);
    el('loadScanInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        doLoadScan();
      }
    });

    el('btnFinalize')?.addEventListener('click', finalizeOrder);

    // Initial + Polling
    load();
    startAutoRefresh();
  }

  init();
})();
function buildOrdersUrl() {
  const url = new URL('/kommi/orders.php', window.location.origin);

  const qsEmbed = new URLSearchParams(window.location.search).get('embed') === '1';
  const jsEmbed = window.__KOMMI_EMBED__ === true;

  if (qsEmbed || jsEmbed) url.searchParams.set('embed', '1');
  return url.toString();
}

function goToOrders(delayMs = 1100) {
  const target = buildOrdersUrl();
  setTimeout(() => {
    window.location.href = target;
  }, delayMs);
}

function shouldAutoReturnAfterExit() {
   return false;
}