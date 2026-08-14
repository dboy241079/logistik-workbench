const STATE = {
  cfg: null,
  ws: null,
  reconnectTimer: null,
  reconnectAttempt: 0,
  connecting: false,
  lastEventId: 0,
};

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function loadConfig() {
  if (STATE.cfg) return STATE.cfg;
  try {
    const res = await fetch('/data/realtime_cfg.json', { cache: 'no-store', credentials: 'same-origin' });
    if (!res.ok) throw new Error('config_http_' + res.status);
    STATE.cfg = await res.json();
  } catch (err) {
    console.warn('[Realtime] Konfiguration nicht verfügbar:', err);
    STATE.cfg = { enabled: false };
  }
  return STATE.cfg;
}

function setDriverRealtimeStatus(text, type = 'muted') {
  const el = document.getElementById('statusLbl');
  if (!el || !document.getElementById('weekAccordion')) return;
  el.textContent = text;
  el.className = `text-end mt-3 small text-${type}`;
}

async function getToken() {
  const res = await fetch('/api/realtime_token.php', {
    cache: 'no-store',
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok !== true || !data.token) {
    throw new Error(data.error || `token_http_${res.status}`);
  }
  return data;
}

function buildWsUrl(path, token) {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  const url = new URL(path || '/realtime/ws', `${proto}//${location.host}`);
  url.searchParams.set('token', token);
  return url.toString();
}

function statusHtml(rows) {
  if (!Array.isArray(rows) || !rows.length) return '<span class="badge bg-secondary">Keine Daten</span>';

  const hasStart = rows.some(r => r.workStart);
  const hasEnd = rows.some(r => r.workEnd);
  const r = [...rows].reverse().find(r =>
    r.arriveWU || r.departWU || r.arriveH || r.departH ||
    r.arriveH2 || r.departH2 || r.pauseStart || r.workStart
  ) || rows[0];

  let status = 'Offen';
  let cls = 'bg-secondary text-light';

  if (hasEnd) {
    const endRow = [...rows].reverse().find(x => x.workEnd);
    status = endRow?.workEnd ? `Feierabend (${endRow.workEnd})` : 'Feierabend';
    cls = 'status-feier';
  } else if (r.pauseStart && !r.pauseEnd) {
    status = 'Pause'; cls = 'status-pause';
  } else if (r.departH2) {
    status = 'Auf dem Weg nach Wunstorf'; cls = 'status-fahrt';
  } else if (r.arriveH2 && !r.departH2) {
    status = `In Halle ${r.hannoverHall2 || 'Hannover 2'}`; cls = 'status-hannover';
  } else if (r.departH) {
    if (r.hannoverHall2 && !r.arriveH2) {
      status = `Auf dem Weg nach Halle ${r.hannoverHall2}`;
    } else {
      status = 'Auf dem Weg nach Wunstorf';
    }
    cls = 'status-fahrt';
  } else if (r.arriveH && !r.departH) {
    status = `In Halle ${r.hannoverHall || 'Hannover'}`; cls = 'status-hannover';
  } else if (r.departWU && !r.arriveH) {
    status = 'Auf dem Weg nach Hannover'; cls = 'status-fahrt';
  } else if (r.arriveWU && !r.departWU) {
    status = 'In Halle Wunstorf'; cls = 'status-wunstorf';
  } else if (hasStart) {
    status = 'Arbeit begonnen'; cls = 'status-fahrt';
  } else {
    status = 'Noch nicht gestartet'; cls = 'status-feier';
  }

  return `<span class="badge ${cls}">${status}</span>`;
}

async function fetchDay(vehId, date) {
  const qs = new URLSearchParams({ veh_id: vehId, date });
  const res = await fetch(`/api/get_day.php?${qs}`, {
    cache: 'no-store',
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok !== true || !Array.isArray(data.rows)) {
    throw new Error(data.error || `get_day_http_${res.status}`);
  }
  return data.rows;
}

function patchCells(vehId, date, rows) {
  const byTour = new Map(rows.map(r => [String(r.tour), r]));
  const cells = document.querySelectorAll(
    `td[data-veh="${CSS.escape(vehId)}"][data-date="${CSS.escape(date)}"][data-tour]`
  );

  cells.forEach(td => {
    const row = byTour.get(String(td.dataset.tour || ''));
    const field = td.dataset.field;
    if (!row || !field) return;
    const next = row[field] ?? '';
    if (td.textContent !== String(next)) {
      td.textContent = String(next);
      td.classList.add('flash');
      setTimeout(() => td.classList.remove('flash'), 1200);
    }
  });
}

function patchStatus(vehId, date, rows) {
  const html = statusHtml(rows);
  document.querySelectorAll(
    `.driver-status[data-id="${CSS.escape(vehId)}"][data-date="${CSS.escape(date)}"]`
  ).forEach(el => { el.innerHTML = html; });

  document.querySelectorAll(`[id^="driver-status-"][id$="-${CSS.escape(date)}"]`).forEach(el => {
    const pane = el.closest('.tab-pane');
    if (!pane) return;
    const activeVeh = window.currentVehId || '';
    if (activeVeh === vehId) el.innerHTML = html;
  });
}

async function applyDriverEvent(event) {
  const p = event?.payload || {};
  const vehId = String(p.veh_id || '');
  const date = String(p.date || '');
  if (!vehId || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return;

  // Nur sichtbare Fahreransichten aktualisieren. Sonst übernimmt das normale Laden beim Öffnen.
  if (!document.getElementById('weekAccordion') && !document.getElementById('drvTabs')) return;

  try {
    const rows = await fetchDay(vehId, date);
    patchCells(vehId, date, rows);
    patchStatus(vehId, date, rows);
    setDriverRealtimeStatus(`Live aktualisiert · ${new Date().toLocaleTimeString('de-DE', { hour:'2-digit', minute:'2-digit', second:'2-digit' })}`, 'success');
  } catch (err) {
    console.warn('[Realtime] Fahrer-Update konnte nicht nachgeladen werden:', err);
  }
}

function scheduleReconnect() {
  if (STATE.reconnectTimer) return;
  const cfg = STATE.cfg || {};
  const base = Number(cfg.reconnect_base_ms || 1500);
  const max = Number(cfg.reconnect_max_ms || 30000);
  const delay = Math.min(max, base * Math.pow(2, Math.min(STATE.reconnectAttempt, 6)));
  STATE.reconnectAttempt += 1;
  STATE.reconnectTimer = setTimeout(() => {
    STATE.reconnectTimer = null;
    connect().catch(() => {});
  }, delay);
}

async function connect() {
  const cfg = await loadConfig();
  if (!cfg.enabled || STATE.connecting || STATE.ws?.readyState === WebSocket.OPEN) return;

  STATE.connecting = true;
  try {
    const auth = await getToken();
    const ws = new WebSocket(buildWsUrl(cfg.ws_path, auth.token));
    STATE.ws = ws;

    ws.addEventListener('open', () => {
      STATE.reconnectAttempt = 0;
      setDriverRealtimeStatus('Live-Verbindung aktiv · Polling bleibt als Sicherheitsnetz', 'success');
    });

    ws.addEventListener('message', async ev => {
      let msg = null;
      try { msg = JSON.parse(ev.data); } catch { return; }
      if (!msg || typeof msg !== 'object') return;
      if (msg.type === 'realtime:ready') return;
      if (msg.id && Number(msg.id) <= STATE.lastEventId) return;
      if (msg.id) STATE.lastEventId = Number(msg.id);
      if (msg.type === 'drivers:update') await applyDriverEvent(msg);
    });

    ws.addEventListener('close', () => {
      STATE.ws = null;
      setDriverRealtimeStatus('Live-Verbindung getrennt · 60-Sekunden-Polling übernimmt', 'warning');
      scheduleReconnect();
    });

    ws.addEventListener('error', () => {
      try { ws.close(); } catch {}
    });
  } catch (err) {
    console.warn('[Realtime] Verbindung nicht möglich:', err.message || err);
    setDriverRealtimeStatus('Realtime nicht erreichbar · Polling läuft weiter', 'warning');
    scheduleReconnect();
  } finally {
    STATE.connecting = false;
  }
}

function disconnect() {
  if (STATE.reconnectTimer) clearTimeout(STATE.reconnectTimer);
  STATE.reconnectTimer = null;
  if (STATE.ws) {
    try { STATE.ws.close(1000, 'client shutdown'); } catch {}
  }
  STATE.ws = null;
}

window.WorkbenchRealtime = {
  connect,
  disconnect,
  get state() { return STATE; },
};

connect().catch(() => {});
