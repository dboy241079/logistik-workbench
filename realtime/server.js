import http from 'node:http';
import crypto from 'node:crypto';
import mysql from 'mysql2/promise';
import { WebSocketServer, WebSocket } from 'ws';

const PORT = Number(process.env.REALTIME_PORT || 8081);
const POLL_MS = Math.max(250, Number(process.env.REALTIME_POLL_MS || 500));
const WS_PATH = process.env.REALTIME_WS_PATH || '/realtime/ws';

const db = mysql.createPool({
  host: process.env.DB_HOST,
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME,
  charset: 'utf8mb4',
  waitForConnections: true,
  connectionLimit: Number(process.env.DB_POOL_SIZE || 5),
  queueLimit: 0,
});

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, service: 'workbench-realtime' }));
    return;
  }
  res.writeHead(404);
  res.end();
});

const wss = new WebSocketServer({ noServer: true });
const clients = new Set();
let lastEventId = 0;

function sha256(value) {
  return crypto.createHash('sha256').update(String(value)).digest('hex');
}

async function authenticate(rawToken) {
  if (!/^[a-f0-9]{64}$/i.test(rawToken || '')) return null;
  const hash = sha256(rawToken);
  const [rows] = await db.execute(
    `SELECT id, user_id, location_code, scopes, expires_at
       FROM realtime_tokens
      WHERE token_hash = ?
        AND expires_at >= NOW()
      LIMIT 1`,
    [hash]
  );
  const row = rows?.[0];
  if (!row) return null;
  await db.execute('UPDATE realtime_tokens SET used_at = NOW() WHERE id = ?', [row.id]).catch(() => {});
  return row;
}

function mayReceive(client, event) {
  const scopes = String(client.auth?.scopes || '').split(',').map(v => v.trim()).filter(Boolean);
  if (!scopes.includes('drivers') && !scopes.includes('*')) return false;
  if (client.auth?.location_code && event.location_code && client.auth.location_code !== event.location_code) return false;
  return true;
}

function sendJson(ws, payload) {
  if (ws.readyState !== WebSocket.OPEN) return;
  try { ws.send(JSON.stringify(payload)); } catch {}
}

wss.on('connection', (ws, request, auth) => {
  ws.auth = auth;
  ws.isAlive = true;
  clients.add(ws);

  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('close', () => clients.delete(ws));
  ws.on('error', () => clients.delete(ws));

  sendJson(ws, {
    type: 'realtime:ready',
    location: auth.location_code,
    scopes: String(auth.scopes || '').split(',').filter(Boolean),
  });
});

server.on('upgrade', async (request, socket, head) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname !== WS_PATH) {
      socket.destroy();
      return;
    }

    const auth = await authenticate(url.searchParams.get('token') || '');
    if (!auth) {
      socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }

    wss.handleUpgrade(request, socket, head, ws => {
      wss.emit('connection', ws, request, auth);
    });
  } catch {
    socket.destroy();
  }
});

async function pollEvents() {
  try {
    const [rows] = await db.execute(
      `SELECT id, event_type, location_code, entity_type, entity_id, payload_json, created_at
         FROM realtime_events
        WHERE id > ?
        ORDER BY id ASC
        LIMIT 500`,
      [lastEventId]
    );

    for (const row of rows) {
      lastEventId = Math.max(lastEventId, Number(row.id));
      let payload = row.payload_json;
      if (typeof payload === 'string') {
        try { payload = JSON.parse(payload); } catch { payload = {}; }
      }

      const event = {
        id: Number(row.id),
        type: row.event_type,
        location_code: row.location_code,
        entity_type: row.entity_type,
        entity_id: row.entity_id,
        payload: payload || {},
        created_at: row.created_at,
      };

      for (const client of clients) {
        if (mayReceive(client, event)) sendJson(client, event);
      }

      await db.execute(
        'UPDATE realtime_events SET published_at = COALESCE(published_at, NOW()), publish_attempts = publish_attempts + 1 WHERE id = ?',
        [row.id]
      ).catch(() => {});
    }
  } catch (err) {
    console.error('[realtime] poll failed:', err.message);
  }
}

const pingTimer = setInterval(() => {
  for (const ws of clients) {
    if (!ws.isAlive) {
      clients.delete(ws);
      ws.terminate();
      continue;
    }
    ws.isAlive = false;
    try { ws.ping(); } catch {}
  }
}, 30000);

const pollTimer = setInterval(pollEvents, POLL_MS);

async function start() {
  if (!process.env.DB_HOST || !process.env.DB_USER || !process.env.DB_NAME) {
    throw new Error('DB_HOST, DB_USER und DB_NAME müssen als Umgebungsvariablen gesetzt sein.');
  }
  const [rows] = await db.query('SELECT COALESCE(MAX(id), 0) AS max_id FROM realtime_events');
  lastEventId = Number(rows?.[0]?.max_id || 0);
  server.listen(PORT, () => {
    console.log(`[realtime] listening on :${PORT}${WS_PATH}`);
  });
}

process.on('SIGTERM', async () => {
  clearInterval(pingTimer);
  clearInterval(pollTimer);
  for (const ws of clients) ws.close(1001, 'server shutdown');
  server.close();
  await db.end().catch(() => {});
  process.exit(0);
});

start().catch(err => {
  console.error('[realtime] startup failed:', err.message);
  process.exit(1);
});
