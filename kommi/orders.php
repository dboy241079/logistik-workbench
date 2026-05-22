<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';

$AUTH_DEFAULT_TAB   = 'outbound';
$AUTH_TAB_KEY       = 'outbound';
$AUTH_REQUIRE_EMBED = false;
$AUTH_DENY_MODE     = 'message';

require __DIR__ . '/../inc/auth_embed.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kommi – Aufträge</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    .order-card { cursor: pointer; transition: .15s transform ease; }
    .order-card:hover { transform: translateY(-2px); }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Kommi-Aufträge</h1>
      <button class="btn btn-outline-secondary btn-sm" id="btnReload">Neu laden</button>
    </div>

    <div id="msg" class="alert alert-secondary py-2">Lade Aufträge…</div>
    <div id="grid" class="row g-3"></div>
  </div>

  <script>
    (() => {
      const API = '/kommi/api/orders_list.php';
      const grid = document.getElementById('grid');
      const msg  = document.getElementById('msg');

      const statusLabel = (s) => ({
        OFFEN: 'Offen',
        KOMMISSIONIERUNG: 'Bereitstellung',
        BEREITGESTELLT: 'Bereitgestellt',
        VERLADUNG: 'Verladung',
        VERLADEN_OK: 'Verladen OK',
        PROBLEM: 'Problem'
      }[s] || s || '—');

      const statusBadgeClass = (s) => ({
        OFFEN: 'text-bg-secondary',
        KOMMISSIONIERUNG: 'text-bg-primary',
        BEREITGESTELLT: 'text-bg-warning',
        VERLADUNG: 'text-bg-info',
        VERLADEN_OK: 'text-bg-success',
        PROBLEM: 'text-bg-danger'
      }[s] || 'text-bg-secondary');

      function pct(done, total) {
        const d = Number(done || 0);
        const t = Math.max(1, Number(total || 0));
        return Math.round((d / t) * 100);
      }

      function openCheckin(orderId) {
        window.location.href = '/kommi/checkin.php?order_id=' + encodeURIComponent(orderId) + '&embed=1';
      }

      function render(items) {
        if (!Array.isArray(items) || items.length === 0) {
          msg.className = 'alert alert-info py-2';
          msg.textContent = 'Keine aktiven Aufträge gefunden.';
          grid.innerHTML = '';
          return;
        }

        msg.className = 'alert alert-success py-2';
        msg.textContent = items.length + ' Auftrag/Aufträge gefunden.';

        grid.innerHTML = items.map(it => {
          const total    = Number(it.total || 0);
          const pickDone = Number(it.pick_done || 0);
          const loadDone = Number(it.load_done || 0);
          const pickPct  = pct(pickDone, total);
          const loadPct  = pct(loadDone, total);

          return `
            <div class="col-12 col-md-6 col-xl-4">
              <div class="card order-card h-100 shadow-sm" data-order-id="${it.id}">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-center mb-2">
  <div class="fw-semibold mono">Ausgang ${it.source_ausgang_nr || '—'}</div>
  <span class="badge ${statusBadgeClass(it.status)}">${statusLabel(it.status)}</span>
</div>

<div class="small text-muted mb-2">Ausgang: ${it.exit_gate ? ('Ausgang ' + it.exit_gate) : '—'}</div>

                  <div class="small mb-1">Pick ${pickDone}/${total}</div>
                  <div class="progress mb-2" style="height:8px;">
                    <div class="progress-bar bg-primary" style="width:${pickPct}%"></div>
                  </div>

                  <div class="small mb-1">Load ${loadDone}/${total}</div>
                  <div class="progress mb-3" style="height:8px;">
                    <div class="progress-bar bg-success" style="width:${loadPct}%"></div>
                  </div>

                  <button class="btn btn-dark btn-sm w-100 js-open" type="button">
                    Check-in starten
                  </button>
                </div>
              </div>
            </div>
          `;
        }).join('');

        grid.querySelectorAll('.order-card').forEach(card => {
          card.addEventListener('click', (e) => {
            const id = Number(card.dataset.orderId || 0);
            if (id > 0) openCheckin(id);
          });
        });
      }

      async function loadOrders(silent = false) {
        try {
          const res = await fetch(API, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
          });

          const txt = await res.text();

          let j = null;
          try {
            j = JSON.parse(txt);
          } catch {
            throw new Error('Ungültige API-Antwort');
          }

          if (!res.ok || !j.ok) {
            if (j?.code === 'TAB_FORBIDDEN' || j?.error === 'forbidden') {
              throw new Error('Keine Berechtigung für Aufträge / Outbound.');
            }
            if (j?.code === 'UNAUTHORIZED') {
              throw new Error('Nicht eingeloggt.');
            }
            throw new Error(j?.error || 'Laden fehlgeschlagen');
          }

          render(j.items || []);
        } catch (e) {
          if (!silent) {
            msg.className = 'alert alert-danger py-2';
            msg.textContent = e.message || 'Fehler beim Laden.';
            grid.innerHTML = '';
          }
        }
      }

      document.getElementById('btnReload')?.addEventListener('click', () => loadOrders(false));

      setInterval(() => {
        if (document.visibilityState === 'visible') {
          loadOrders(true);
        }
      }, 5000);

      loadOrders(false);
    })();
  </script>
</body>
</html>