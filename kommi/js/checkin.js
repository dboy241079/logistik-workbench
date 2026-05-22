(() => {
  'use strict';

  const ORDER_ID = Number(
    window.__KOMMI_ORDER_ID__ ||
    new URLSearchParams(window.location.search).get('order_id') ||
    0
  );

  const API = {
    createChallenge: '/kommi/api/create_challenge.php',
    verifyChallenge: '/kommi/api/verify_challenge.php',
    verifyUserCode: '/kommi/api/verify_user_code.php',
    grantBoardAccess: '/kommi/api/grant_board_access.php',
    checkinState: '/kommi/api/checkin_state.php'
  };

  const state = {
    scanCode: '',
    challengeOk: false,
    preparerUser: '',
    loaderUser: '',
    locks: {
      create: false,
      verifyChallenge: false,
      verifyPreparer: false,
      verifyLoader: false,
      grant: false
    }
  };

  const el = (id) => document.getElementById(id);
  const elAny = (...ids) => {
    for (const id of ids) {
      const n = el(id);
      if (n) return n;
    }
    return null;
  };

  function setBadge(id, text, tone = 'secondary') {
    const n = el(id);
    if (!n) return;
    n.className = `badge text-bg-${tone}`;
    n.textContent = text;
  }

  function setMsg(msg, tone = 'secondary') {
    // bevorzugt #checkinMsg, fallback #msg
    const n = el('checkinMsg') || el('msg');
    if (!n) return;
    n.className = `alert alert-${tone} py-2 px-3 mb-2`;
    n.textContent = msg;
  }

  function sanitizePersonalNo(raw) {
    // Scanner-Reste entfernen: nur Ziffern behalten
    return String(raw ?? '').trim().replace(/\D+/g, '');
  }

  async function apiPost(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    });

    const ct  = res.headers.get('content-type') || '';
    const raw = await res.text();

    if (!ct.includes('application/json')) {
      console.error('[checkin] API kein JSON:', url, ct, raw);
      throw new Error('Ungültige API-Antwort (kein JSON).');
    }

    let j;
    try {
      j = JSON.parse(raw);
    } catch (e) {
      console.error('[checkin] JSON Parse Fehler:', url, raw);
      throw new Error('Ungültiges JSON von API.');
    }

    if (!res.ok || !j.ok) {
      const msg = [
        j?.error || `HTTP ${res.status}`,
        j?.code ? `(${j.code})` : '',
        j?.debug ? `- ${j.debug}` : ''
      ].filter(Boolean).join(' ');
      throw new Error(msg);
    }

    return j;
  }

  async function loadCheckinState() {
    const url = `${API.checkinState}?order_id=${encodeURIComponent(ORDER_ID)}`;
    const res = await fetch(url, { credentials: 'same-origin' });

    const ct  = res.headers.get('content-type') || '';
    const raw = await res.text();

    if (!res.ok) {
      throw new Error(`checkin_state HTTP ${res.status}`);
    }
    if (!ct.includes('application/json')) {
      throw new Error('checkin_state liefert kein JSON');
    }

    let j;
    try {
      j = JSON.parse(raw);
    } catch {
      throw new Error('checkin_state JSON ungültig');
    }

    if (!j.ok) {
      throw new Error(j.error || 'checkin_state fehlgeschlagen');
    }

    // Serverzustand in lokalen State übernehmen
    state.challengeOk  = !!(j.step1_ok || j.step2_ok || j.preparer_user);
    state.preparerUser = String(j.preparer_user || state.preparerUser || '');
    state.loaderUser   = String(j.loader_user || state.loaderUser || '');
  }

  function updateUi() {
    const inpChallenge = el('challengeScanInput');
    const btnVC = el('btnVerifyChallenge');

    const inpPrep = el('prepScanInput');
    const btnVP = el('btnVerifyPreparer');

    // Step3 IDs (beide Varianten erlaubt)
    const inpLoad = elAny('loadScanInput', 'loaderScanInput');
    const btnVL   = elAny('btnVerifyLoader');

    const canStep2  = !!state.challengeOk && !state.preparerUser;
    const canStep3  = !!state.preparerUser && !state.loaderUser;

    const busyStep2 = !!state.locks.verifyPreparer || !!state.locks.grant;
    const busyStep3 = !!state.locks.verifyLoader   || !!state.locks.grant;

    // Schritt 1
    if (inpChallenge) inpChallenge.disabled = state.challengeOk;
    if (btnVC) btnVC.disabled = !state.scanCode || state.challengeOk || state.locks.verifyChallenge;

    // Schritt 2
    if (inpPrep) inpPrep.disabled = !canStep2 || busyStep2;
    if (btnVP) btnVP.disabled = !canStep2 || busyStep2;

    // Schritt 3
    if (inpLoad) inpLoad.disabled = !canStep3 || busyStep3;
    if (btnVL) btnVL.disabled = !canStep3 || busyStep3;

    // Badges
    setBadge('step1Status', state.challengeOk ? 'ok' : 'offen', state.challengeOk ? 'success' : 'secondary');
    setBadge('step2Status', state.preparerUser ? 'ok' : 'offen', state.preparerUser ? 'success' : 'secondary');
    setBadge('step3Status', state.loaderUser ? 'ok' : 'offen', state.loaderUser ? 'success' : 'secondary');

    // Wer
    const prepWho = el('prepWho');
    if (prepWho) prepWho.textContent = state.preparerUser || '—';

    const loadWho = elAny('loadWho', 'loaderWho');
    if (loadWho) loadWho.textContent = state.loaderUser || '—';

    // Legacy-Karte "Board freischalten & öffnen" ausblenden
    const legacyBtn = el('btnGrantAndOpen');
    if (legacyBtn) {
      legacyBtn.disabled = true;
      const card = legacyBtn.closest('.card');
      if (card) card.classList.add('d-none');
    }
  }

  function renderScanCode() {
    // optional: falls du irgendwo den Code sichtbar machen willst
    const codeNode = el('challengeCode') || el('scanCodeText');
    if (codeNode) codeNode.textContent = state.scanCode || '—';
  }

  async function createChallenge() {
    if (state.locks.create) return;
    state.locks.create = true;

    try {
      if (!ORDER_ID) throw new Error('order_id fehlt in der URL.');

      const j = await apiPost(API.createChallenge, { order_id: ORDER_ID });

      state.scanCode = String(j.scan_code || '').trim();
      state.challengeOk = false;
      state.preparerUser = '';
      state.loaderUser = '';

      const challengeInput = el('challengeScanInput');
      if (challengeInput) challengeInput.value = state.scanCode;

      renderScanCode();
      setMsg('Challenge erzeugt. Auftragscode jetzt verifizieren.', 'info');
    } catch (e) {
      setMsg(e.message || 'create_challenge fehlgeschlagen.', 'danger');
    } finally {
      state.locks.create = false;
      updateUi();
      el('challengeScanInput')?.focus();
    }
  }

  async function verifyChallenge() {
    if (state.challengeOk) return;
    if (state.locks.verifyChallenge) return;
    state.locks.verifyChallenge = true;

    try {
      const scan = String(el('challengeScanInput')?.value || '').trim() || state.scanCode;
      if (!scan) throw new Error('Bitte Auftragscode scannen/eingeben.');

      await apiPost(API.verifyChallenge, { order_id: ORDER_ID, scan });

      state.challengeOk = true;
      setMsg('Auftrag erfolgreich verifiziert.', 'success');

      // Sofort Step2 freigeben
      updateUi();
      const prep = el('prepScanInput');
      if (prep && !state.preparerUser) {
        prep.disabled = false;
        prep.removeAttribute('disabled');
        prep.focus();
      }
    } catch (e) {
      setMsg(e.message || 'verify_challenge fehlgeschlagen.', 'danger');
    } finally {
      state.locks.verifyChallenge = false;
      updateUi();
    }
  }

  async function autoGrantAndOpen() {
    state.locks = state.locks || {};
    if (state.locks.grant) return;
    state.locks.grant = true;

    try {
      // Schutz: nur nach Step3
      if (!state.preparerUser) throw new Error('Schritt 2 nicht abgeschlossen.');
      if (!state.loaderUser)   throw new Error('Schritt 3 nicht abgeschlossen.');

      const j = await apiPost(API.grantBoardAccess, { order_id: ORDER_ID });

      const target =
        j?.board_url ||
        `/kommi/board.php?order_id=${encodeURIComponent(ORDER_ID)}&embed=1`;

      window.location.replace(target);
    } catch (e) {
      console.error('[checkin] autoGrantAndOpen error:', e);
      setMsg(e.message || 'Board-Freischaltung fehlgeschlagen.', 'danger');
    } finally {
      state.locks.grant = false;
      updateUi();
    }
  }

  async function verifyPreparer() {
    state.locks = state.locks || {};
    if (state.locks.verifyPreparer) return;
    state.locks.verifyPreparer = true;

    try {
      if (state.preparerUser) {
        setMsg('Bereitsteller bereits verifiziert.', 'info');
        return;
      }

      if (!state.challengeOk) {
        throw new Error('Bitte zuerst Schritt 1 abschließen.');
      }

      const inp = el('prepScanInput');
      const scan = sanitizePersonalNo(inp?.value || '');

      if (!/^\d{3,32}$/.test(scan)) {
        throw new Error('Bitte gültige Personalnummer eingeben.');
      }

      const j = await apiPost(API.verifyUserCode, {
        order_id: ORDER_ID,
        phase: 'PREPARER',
        scan
      });

      state.preparerUser = j.username || j.display_name || scan;

      const prepWho = el('prepWho');
      if (prepWho) prepWho.textContent = state.preparerUser;

      if (inp) inp.value = '';

      setBadge('step2Status', 'ok', 'success');
      setMsg('Bereitsteller verifiziert. Jetzt Schritt 3 (Verlader).', 'success');
      updateUi();

      // WICHTIG: kein autoGrant hier mehr (erst nach Loader)
      const inpLoad = elAny('loadScanInput', 'loaderScanInput');
      if (inpLoad && !state.loaderUser) inpLoad.focus();
    } catch (e) {
      console.error('[checkin] verifyPreparer error:', e);
      setMsg(e.message || 'Bereitsteller-Verifizierung fehlgeschlagen.', 'danger');
      return; // kein Grant bei Fehler
    } finally {
      state.locks.verifyPreparer = false;
      updateUi();
    }
  }

  async function verifyLoader() {
    state.locks = state.locks || {};
    if (state.locks.verifyLoader) return;
    state.locks.verifyLoader = true;

    try {
      if (!state.preparerUser) {
        throw new Error('Schritt 2 muss zuerst abgeschlossen sein.');
      }
      if (state.loaderUser) {
        setMsg('Verlader bereits verifiziert.', 'info');
        return;
      }

      const inp = elAny('loadScanInput', 'loaderScanInput');
      const scan = sanitizePersonalNo(inp?.value || '');

      if (!/^\d{3,32}$/.test(scan)) {
        throw new Error('Bitte gültige Personalnummer eingeben.');
      }

      const j = await apiPost(API.verifyUserCode, {
        order_id: ORDER_ID,
        phase: 'LOADER',
        scan
      });

      state.loaderUser = j.username || j.display_name || scan;

      const loadWho = elAny('loadWho', 'loaderWho');
      if (loadWho) loadWho.textContent = state.loaderUser;

      if (inp) inp.value = '';

      setBadge('step3Status', 'ok', 'success');
      setMsg('Verlader verifiziert. Öffne Auftrag…', 'success');
      updateUi();

      await autoGrantAndOpen();
    } catch (e) {
      console.error('[checkin] verifyLoader error:', e);
      setMsg(e.message || 'Verlader-Verifizierung fehlgeschlagen.', 'danger');
      return;
    } finally {
      state.locks.verifyLoader = false;
      updateUi();
    }
  }

  function bindEvents() {
    el('btnCreateChallenge')?.addEventListener('click', createChallenge);
    el('btnVerifyChallenge')?.addEventListener('click', verifyChallenge);
    el('btnVerifyPreparer')?.addEventListener('click', verifyPreparer);
    elAny('btnVerifyLoader')?.addEventListener('click', verifyLoader);

    el('challengeScanInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        verifyChallenge();
      }
    });

    el('prepScanInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        verifyPreparer();
      }
    });

    elAny('loadScanInput', 'loaderScanInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        verifyLoader();
      }
    });

    // Legacy button fallback (falls noch sichtbar)
    el('btnGrantAndOpen')?.addEventListener('click', (e) => {
      e.preventDefault();
      autoGrantAndOpen();
    });
  }

  function init() {
    if (!ORDER_ID) {
      setMsg('order_id fehlt in der URL.', 'danger');
      return;
    }

    bindEvents();
    updateUi();
    setMsg('Check-in bereit.', 'secondary');

    // Optionaler Server-Sync (falls checkin_state.php vorhanden)
    loadCheckinState()
      .then(() => {
        updateUi();

        if (state.loaderUser) {
          setMsg('Check-in bereits vollständig. Board kann geöffnet werden.', 'success');
        } else if (state.preparerUser) {
          setMsg('Schritt 1+2 bereits erledigt. Bitte Schritt 3 (Verlader) verifizieren.', 'info');
          elAny('loadScanInput', 'loaderScanInput')?.focus();
        } else if (state.challengeOk) {
          setMsg('Schritt 1 erledigt. Bitte Schritt 2 verifizieren.', 'info');
          el('prepScanInput')?.focus();
        }
      })
      .catch((e) => {
        console.warn('[checkin] checkin_state nicht verfügbar:', e.message);
        // Fallback: normal weiter
      });
  }
  // ===== MODE LOGIK (PREP / LOAD) – Ergänzung ohne Löschen =====
state.orderStatus = state.orderStatus || '';
state.mode = state.mode || 'PREP';
state.exitGate = state.exitGate || 0;

async function refreshFromServerState() {
  try {
    const url = `${API.checkinState}?order_id=${encodeURIComponent(ORDER_ID)}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const ct = res.headers.get('content-type') || '';
    const raw = await res.text();
    if (!ct.includes('application/json')) throw new Error('checkin_state kein JSON');
    const j = JSON.parse(raw);
    if (!j.ok) throw new Error(j.error || 'checkin_state failed');

    state.orderStatus = String(j.status || '');
    state.mode = String(j.mode || 'PREP');
    state.exitGate = Number(j.exit_gate || 0);

    // “fertige” Zuweisungen aus DB spiegeln
    state.preparerUser = String(j.preparer_user || state.preparerUser || '');
    state.loaderUser   = String(j.loader_user || state.loaderUser || '');

    // Schritt 1 gilt als ok sobald Step2 existiert
    state.challengeOk = !!(state.challengeOk || state.preparerUser);

    // Badges setzen
    setBadge('step1Status', (j.step1_ok ? 'ok' : 'offen'), (j.step1_ok ? 'success' : 'secondary'));
    setBadge('step2Status', (j.step2_ok ? 'ok' : 'offen'), (j.step2_ok ? 'success' : 'secondary'));
    setBadge('step3Status', (j.step3_ok ? 'ok' : 'offen'), (j.step3_ok ? 'success' : 'secondary'));

    el('prepWho') && (el('prepWho').textContent = state.preparerUser || '—');
    (el('loadWho') || el('loaderWho')) && ((el('loadWho') || el('loaderWho')).textContent = state.loaderUser || '—');

  } catch (e) {
    console.warn('[checkin] checkin_state nicht verfügbar:', e.message);
  }
}

function hideCardByInputId(inputId, hide) {
  const n = el(inputId);
  if (!n) return;
  const card = n.closest('.card');
  if (!card) return;
  card.classList.toggle('d-none', !!hide);
}

async function openBoard(mode) {
  try {
    const j = await apiPost(API.grantBoardAccess, { order_id: ORDER_ID, mode });

    const target =
      j.board_url ||
      `/kommi/board.php?order_id=${encodeURIComponent(ORDER_ID)}&embed=1`;

    // Wichtig: replace (keine Back-History)
    window.location.replace(target);
  } catch (e) {
    console.error('[checkin] openBoard failed:', e);
    setMsg(`Board öffnen fehlgeschlagen: ${e.message}`, 'danger');
  }
}

// PREP: nach Step2 → Board (Bereitstellung)
const _verifyPreparerOrig = verifyPreparer;
verifyPreparer = async function () {
  await refreshFromServerState();

  if (String(state.mode).toUpperCase() !== 'PREP') {
    setMsg('Schritt 2 ist nur vor Bereitstellung möglich.', 'warning');
    updateUi();
    return;
  }

  await _verifyPreparerOrig();

  // State neu ziehen (assigned_picker kommt aus DB)
  await refreshFromServerState();

  if (state.preparerUser) {
    setMsg('Bereitsteller OK. Öffne Board für Bereitstellung…', 'success');
    await openBoard('PREP');
  }
};

// LOAD: eigener Step3-Verify + Board (Verladung)
async function verifyLoader() {
  await refreshFromServerState();

  if (String(state.mode).toUpperCase() !== 'LOAD') {
    setMsg('Schritt 3 erst nach Bereitstellung (Status BEREITGESTELLT).', 'warning');
    updateUi();
    return;
  }
  if (!state.preparerUser) {
    setMsg('Schritt 2 fehlt (Bereitsteller nicht gesetzt).', 'danger');
    updateUi();
    return;
  }

  const inp = el('loadScanInput') || el('loaderScanInput');
  const scan = sanitizePersonalNo(inp?.value || '');
  if (!/^\d{3,32}$/.test(scan)) {
    setMsg('Bitte gültige Personalnummer eingeben.', 'warning');
    inp?.focus();
    return;
  }

  const j = await apiPost(API.verifyUserCode, {
    order_id: ORDER_ID,
    phase: 'LOADER',
    scan
  });

  state.loaderUser = j.username || j.display_name || scan;
  (el('loadWho') || el('loaderWho')) && ((el('loadWho') || el('loaderWho')).textContent = state.loaderUser);

  setBadge('step3Status', 'ok', 'success');
  setMsg('Verlader OK. Öffne Board für Verladung…', 'success');

  await openBoard('LOAD');
}

// UI: je nach Mode Steps zeigen/ausblenden
const _updateUiOrig = updateUi;
updateUi = function () {
  try { _updateUiOrig(); } catch {}

  const mode = String(state.mode || 'PREP').toUpperCase();

  if (mode === 'PREP') {
    // Step3 verstecken
    hideCardByInputId('loadScanInput', true);
    hideCardByInputId('loaderScanInput', true);

    // Step1/2 sichtbar
    hideCardByInputId('challengeScanInput', false);
    hideCardByInputId('prepScanInput', false);

    // Step2 nur wenn nicht gesetzt
    const inpPrep = el('prepScanInput');
    const btnPrep = el('btnVerifyPreparer');
    if (inpPrep) inpPrep.disabled = !state.challengeOk || !!state.preparerUser;
    if (btnPrep) btnPrep.disabled = !state.challengeOk || !!state.preparerUser;
  }

  if (mode === 'LOAD') {
    // Step1/2 verstecken
    hideCardByInputId('challengeScanInput', true);
    hideCardByInputId('prepScanInput', true);

    // Step3 sichtbar
    hideCardByInputId('loadScanInput', false);
    hideCardByInputId('loaderScanInput', false);

    const inpLoad = el('loadScanInput') || el('loaderScanInput');
    const btnLoad = el('btnVerifyLoader');
    if (inpLoad) inpLoad.disabled = !!state.loaderUser;
    if (btnLoad) btnLoad.disabled = !!state.loaderUser;
  }

  if (mode === 'DONE') {
    setMsg('Auftrag ist bereits VERLADEN OK.', 'success');
  }
};

// Events für Step3 hinzufügen
const _bindEventsOrig = bindEvents;
bindEvents = function () {
  try { _bindEventsOrig(); } catch {}
  el('btnVerifyLoader')?.addEventListener('click', (e) => { e.preventDefault(); verifyLoader(); });
  (el('loadScanInput') || el('loaderScanInput'))?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); verifyLoader(); }
  });
};

// Init: zuerst DB-State holen, dann UI richtig setzen
const _initOrig = init;
init = function () {
  _initOrig();

  refreshFromServerState().finally(() => {
    updateUi();

    const mode = String(state.mode || 'PREP').toUpperCase();
    if (mode === 'PREP') {
      if (!state.challengeOk) el('challengeScanInput')?.focus();
      else if (!state.preparerUser) el('prepScanInput')?.focus();
    } else if (mode === 'LOAD') {
      (el('loadScanInput') || el('loaderScanInput'))?.focus();
      setMsg('Bereitstellung erledigt. Bitte Schritt 3 (Verlader) verifizieren.', 'info');
    }
  });
};

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
