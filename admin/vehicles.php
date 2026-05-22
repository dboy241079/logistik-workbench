<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['username'])) { http_response_code(401); exit('Login erforderlich'); }
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Fahrzeuge bearbeiten</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-truck me-2"></i>Fahrzeuge / Kennzeichen</h4>
    <div class="d-flex gap-2">
      <button id="btnReload" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i>Neu laden
      </button>
      <button id="btnSave" class="btn btn-primary btn-sm">
        <i class="bi bi-save me-1"></i>Speichern
      </button>
    </div>
  </div>

  <div id="msg"></div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:120px;">ID</th>
              <th>Titel (Tab-Name)</th>
              <th style="width:180px;">Kennzeichen</th>
              <th style="width:220px;">Fahrer</th>
            </tr>
          </thead>
          <tbody id="vehBody"></tbody>
        </table>
      </div>
      <div class="text-muted small">
        Tipp: Wenn du <b>Kennzeichen</b> pflegst, wird die Import-Zuordnung viel zuverlässiger.
      </div>
    </div>
  </div>
</div>

<script>
const $ = (id) => document.getElementById(id);
let CFG = null;

function alertBox(type, text){
  $('msg').innerHTML = `<div class="alert alert-${type} py-2">${text}</div>`;
  setTimeout(()=>{ $('msg').innerHTML = ''; }, 4000);
}

function render(){
  const tb = $('vehBody');
  tb.innerHTML = '';
  const vehicles = CFG?.vehicles || [];

  vehicles.forEach((v, i) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input class="form-control form-control-sm" value="${v.id || ''}" data-k="id" data-i="${i}" readonly></td>
      <td><input class="form-control form-control-sm" value="${v.title || ''}" data-k="title" data-i="${i}"></td>
      <td><input class="form-control form-control-sm" value="${v.plate || ''}" data-k="plate" data-i="${i}" placeholder="z.B. BOH - DT 328"></td>
      <td><input class="form-control form-control-sm" value="${v.driver || ''}" data-k="driver" data-i="${i}"></td>
    `;
    tb.appendChild(tr);
  });

  tb.querySelectorAll('input[data-k]').forEach(inp => {
    inp.addEventListener('input', () => {
      const i = Number(inp.dataset.i);
      const k = inp.dataset.k;
      CFG.vehicles[i][k] = inp.value;
    });
  });
}

async function loadCfg(){
  const res = await fetch('/api/veh_cfg_get.php', { cache:'no-store', credentials:'include' });
  const j = await res.json().catch(()=>({}));
  if (!j.ok) throw new Error(j.msg || j.error || 'load_failed');
  CFG = j.cfg;
  render();
}

async function saveCfg(){
  const res = await fetch('/api/veh_cfg_save.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ cfg: CFG }),
    credentials:'include'
  });
  const j = await res.json().catch(()=>({}));
  if (!j.ok) throw new Error(j.msg || j.error || 'save_failed');

  // optional: damit Frontend sofort neue Namen hat
  localStorage.setItem('drv_cfg_v1', JSON.stringify(CFG));

  alertBox('success', 'Gespeichert ✅');
}

$('btnReload').addEventListener('click', async () => {
  try { await loadCfg(); alertBox('secondary', 'Neu geladen'); } catch(e){ alertBox('danger', e.message); }
});

$('btnSave').addEventListener('click', async () => {
  try { await saveCfg(); } catch(e){ alertBox('danger', e.message); }
});

loadCfg().catch(e => alertBox('danger', e.message));
</script>
</body>
</html>
