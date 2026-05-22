// /LKW/js/sachnummern.js
const API = '/LKW/api/stammdaten_api.php';
const LG_OPTIONS = ['W1','X3','X3(B)','G9','B1','Bauteile','BM','Müll'];

// --- DOM helpers ---
function $(root, sel){ return root.querySelector(sel); }
function esc(s){
  return String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[m]));
}

// --- Lagergruppe helper ---
function setSelectValue(selectEl, val){
  if (!selectEl) return;
  if (!LG_OPTIONS.includes(val)) return;
  selectEl.value = val;
}

// --- Kennzeichen-Utils ---
function parsePlates(raw) {
  if (Array.isArray(raw)) return raw.filter(Boolean).map(s => String(s).trim());
  if (typeof raw === 'string') {
    const s = raw.trim();
    if (!s) return [];
    // JSON-Array?
    if (s.startsWith('[') && s.endsWith(']')) {
      try {
        const arr = JSON.parse(s);
        if (Array.isArray(arr)) return arr.filter(Boolean).map(x => String(x).trim());
      } catch (_) {}
    }
    // CSV-Fallback
    return s.split(',').map(x => x.trim()).filter(Boolean);
  }
  return [];
}
function formatPlatesShort(arr = []) {
  const a = parsePlates(arr);
  if (a.length <= 2) return a.join(', ');
  return `${a.slice(0, 2).join(', ')} +${a.length - 2}`;
}

// --- API helpers ---
async function apiList(type, q=''){
  const url = new URL(API, location.origin);
  url.searchParams.set('type', type);
  url.searchParams.set('action', 'list');
  if (q) url.searchParams.set('q', q);
  const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
  const j = await res.json().catch(() => ({}));
  if (!res.ok || !j?.ok) throw new Error(j?.error || `list_failed (${type})`);
  return j.items;
}
async function apiCreate(type, payload){
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'create');
  Object.entries(payload).forEach(([k,v])=> fd.set(k, v));
  const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
  const j = await res.json().catch(() => ({}));
  if (!res.ok || !j?.ok) throw new Error(j?.error || 'create_failed');
  return j.id;
}
async function apiUpdate(type, id, payload){
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'update');
  fd.set('id', id);
  Object.entries(payload).forEach(([k,v])=> fd.set(k, v));
  const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
  const j = await res.json().catch(() => ({}));
  if (!res.ok || !j?.ok) throw new Error(j?.error || 'update_failed');
  return true;
}
async function apiDelete(type, id){
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'delete');
  fd.set('id', id);
  const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
  const j = await res.json().catch(() => ({}));
  if (!res.ok || !j?.ok) throw new Error(j?.error || 'delete_failed');
  return true;
}

// --- Render: Sachnummern ---
function renderTableSach(root, items){
  const tbody = $(root, '#tblSach tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.dataset.sachnummer  = it.sachnummer;
    tr.dataset.lagergruppe = it.lagergruppe ?? '';
    tr.innerHTML = `
      <td>${esc(it.sachnummer)}</td>
      <td>${esc(it.lagergruppe ?? '')}</td>
      <td><span class="text-muted small">${new Date(it.updated_at).toLocaleString('de-DE')}</span></td>
      <td>
        <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
        <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  tbody.onclick = (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = tr.dataset.id;
    if (btn.dataset.act === 'edit') {
      openModalSach(root, id, tr.dataset.sachnummer, tr.dataset.lagergruppe);
    } else if (btn.dataset.act === 'del') {
      if (confirm('Eintrag wirklich löschen?')) {
        apiDelete('sachnummer', id)
          .then(()=> loadSach(root, $(root,'#searchSach')?.value.trim() || ''))
          .catch(err => alert('Fehler: '+err.message));
      }
    }
  };
}

// --- Render: Behälter & Speditionen ---
function renderTableGeneric(root, type, items){
  const isSped = (type === 'spedition');
  const conf = {
    behaelter: { table:'#tblBeh', field:'nummer' },
    spedition: { table:'#tblSped', field:'name' }
  }[type];

  const tbody = $(root, conf.table + ' tbody');
  if (!tbody) return;
  tbody.innerHTML = '';

  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.dataset.id  = it.id;
    tr.dataset.val = it[conf.field];

    if (isSped) {
      const platesArr = parsePlates(it.plates);
      tr.dataset.plates = JSON.stringify(platesArr);
    }

    tr.innerHTML = isSped
      ? `
        <td>${esc(it.name)}</td>
        <td>${esc(formatPlatesShort(it.plates))}</td>
        <td><span class="text-muted small">${new Date(it.updated_at).toLocaleString('de-DE')}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
          <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
        </td>
      `
      : `
        <td>${esc(it[conf.field])}</td>
        <td><span class="text-muted small">${new Date(it.updated_at).toLocaleString('de-DE')}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
          <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
        </td>
      `;
    tbody.appendChild(tr);
  });

  tbody.onclick = (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = tr.dataset.id;

    if (btn.dataset.act === 'edit') {
      if (isSped) {
        const platesRaw = tr.dataset.plates ? JSON.parse(tr.dataset.plates) : [];
        openModalGeneric(root, 'spedition', id, tr.dataset.val, platesRaw);
      } else {
        openModalGeneric(root, type, id, tr.dataset.val);
      }
    } else if (btn.dataset.act === 'del') {
      if (confirm('Eintrag wirklich löschen?')) {
        apiDelete(type, id)
          .then(()=> loadGeneric(root, type, (type==='spedition' ? $(root,'#searchSped') : $(root,'#searchBeh'))?.value.trim() || ''))
          .catch(err => alert('Fehler: '+err.message));
      }
    }
  };
}

// --- Loader ---
async function loadSach(root, q=''){
  const items = await apiList('sachnummer', q);
  renderSachAccordion(root, items, { query: q });
}

async function loadGeneric(root, type, q=''){
  const items = await apiList(type, q);
  renderTableGeneric(root, type, items);
}

// --- Modal opener: Sachnummer ---
function openModalSach(root, id='', sachnummer='', lagergruppe=''){
  const modalEl = $(root, '#editModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const $id     = $(root, '#fieldId');
  const $entity = $(root, '#fieldEntity');
  const $value  = $(root, '#fieldValue');
  const $label  = $(root, '#fieldLabel');
  const $title  = $(root, '#modalTitle');
  const $lgWrap = $(root, '#lgWrap');
  const $lg     = $(root, '#fieldLagergruppe');
  const $plateWrap= $(root, '#plateWrap');
  const $plates   = $(root, '#fieldPlates');

  $id.value = id || '';
  $entity.value = 'sachnummer';
  $label.textContent = 'Sachnummer';
  $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Sachnummer';
  $value.value = sachnummer || '';
  $lgWrap.classList.remove('d-none');
  $lg.required = true;
  setSelectValue($lg, lagergruppe || '');

  if ($plateWrap) $plateWrap.classList.add('d-none');
  if ($plates) $plates.value = '';

  modal.show();
  setTimeout(()=> $value.focus(), 50);
}

// --- Modal opener: Behälter / Spedition ---
function openModalGeneric(root, type, id='', value='', plates=[]){
  const modalEl = $(root, '#editModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

  const $id       = $(root, '#fieldId');
  const $entity   = $(root, '#fieldEntity');
  const $value    = $(root, '#fieldValue');
  const $label    = $(root, '#fieldLabel');
  const $title    = $(root, '#modalTitle');
  const $lgWrap   = $(root, '#lgWrap');
  const $lg       = $(root, '#fieldLagergruppe');
  const $plateWrap= $(root, '#plateWrap');
  const $plates   = $(root, '#fieldPlates');

  $id.value = id || '';
  $entity.value = type;
  $value.value = value || '';
  $lgWrap.classList.add('d-none');
  if ($lg){ $lg.required = false; $lg.value = ''; }

  if (type === 'behaelter') {
    $label.textContent = 'Behälternummer';
    $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Behälter';
    if ($plateWrap) $plateWrap.classList.add('d-none');
    if ($plates) $plates.value = '';
  } else {
    $label.textContent = 'Spedition';
    $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Spedition';
    if ($plateWrap) $plateWrap.classList.remove('d-none');
    if ($plates) $plates.value = parsePlates(plates).join(', ');
  }

  modal.show();
  setTimeout(()=> $value.focus(), 50);
}

// --- Events / Submit ---
function wireEvents(root){
  // Neu-Buttons
  $(root, '#btnNewSach')?.addEventListener('click', ()=> openModalSach(root));
  $(root, '#btnNewBeh') ?.addEventListener('click', ()=> openModalGeneric(root, 'behaelter'));
  $(root, '#btnNewSped')?.addEventListener('click', ()=> openModalGeneric(root, 'spedition'));

  // Suche (debounced)
  const debounce = (fn,ms)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; };
  $(root, '#searchSach')?.addEventListener('input', debounce(()=> loadSach(root, $(root,'#searchSach').value.trim()), 200));
  $(root, '#searchBeh') ?.addEventListener('input', debounce(()=> loadGeneric(root,'behaelter', $(root,'#searchBeh').value.trim()), 200));
  $(root, '#searchSped')?.addEventListener('input', debounce(()=> loadGeneric(root,'spedition', $(root,'#searchSped').value.trim()), 200));

  // Modal submit
  $(root, '#editForm')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const type = $(root,'#fieldEntity').value;
    const id   = $(root,'#fieldId').value;
    const val  = $(root,'#fieldValue').value.trim();

    try{
      if (type === 'sachnummer'){
        const lgEl = $(root,'#fieldLagergruppe');
        const lg   = lgEl?.value?.trim() || '';
        if (!val) return alert('Bitte Sachnummer eingeben.');
        if (!lg)  return alert('Bitte Lagergruppe wählen.');
        if (!LG_OPTIONS.includes(lg)) return alert('Ungültige Lagergruppe.');

        if (id) await apiUpdate('sachnummer', id, { sachnummer: val, lagergruppe: lg });
        else    await apiCreate('sachnummer', { sachnummer: val, lagergruppe: lg });

        await loadSach(root, $(root,'#searchSach')?.value.trim() || '');

      } else if (type === 'behaelter') {
        if (!val) return alert('Bitte Behälternummer eingeben.');
        if (id) await apiUpdate('behaelter', id, { nummer: val });
        else    await apiCreate('behaelter', { nummer: val });
        await loadGeneric(root,'behaelter', $(root,'#searchBeh')?.value.trim() || '');

      } else if (type === 'spedition') {
        if (!val) return alert('Bitte Spedition eingeben.');
        const platesCsv = (($(root, '#fieldPlates')?.value) || '')
          .split(',')
          .map(s => s.trim())
          .filter(Boolean)
          .join(',');

        if (id) await apiUpdate('spedition', id, { name: val, plates: platesCsv });
        else    await apiCreate('spedition',    { name: val, plates: platesCsv });

        await loadGeneric(root,'spedition', $(root,'#searchSped')?.value.trim() || '');
      }

      bootstrap.Modal.getInstance($(root,'#editModal')).hide();
    }catch(err){
      alert(err?.message === 'duplicate' ? 'Eintrag existiert bereits.' : 'Fehler: '+(err?.message||err));
    }
  });
}

// --- Public init ---
export async function initSachnummern(root){
  wireEvents(root);
  await Promise.all([
    loadSach(root),
    loadGeneric(root, 'behaelter'),
    loadGeneric(root, 'spedition'),
  ]);
}

// --- Standalone-Autostart (wenn NICHT eingebettet in index.html) ---
if (!window.__EMBEDDED__) {
  window.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-tab-root]') || document.body;
    initSachnummern(root);
  });
}

function slugLG(lg) {
  return 'lg-' + String(lg || 'ohne').toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function renderSachAccordion(root, items, { query = '' } = {}) {
  const acc = $(root, '#accSach');
  if (!acc) return;

  // nach LG gruppieren
  const groups = new Map();
  for (const it of items) {
    const k = it.lagergruppe || 'Ohne Lagergruppe';
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(it);
  }

  // Reihenfolge: erst bekannte LG_OPTIONS, dann rest alphabetisch, "Ohne…" am Ende
  const KNOWN = LG_OPTIONS.slice();
  const extras = [...groups.keys()].filter(k => !KNOWN.includes(k) && k !== 'Ohne Lagergruppe').sort((a,b)=>a.localeCompare(b,'de'));
  const order = [...KNOWN.filter(k => groups.has(k)), ...extras, ...(groups.has('Ohne Lagergruppe') ? ['Ohne Lagergruppe'] : [])];

  // HTML bauen
  let i = 0;
  acc.innerHTML = order.map(lg => {
    const list = groups.get(lg) || [];
    const cid  = slugLG(lg) + '-' + (i++);
    const show = query ? 'show' : (i === 1 ? 'show' : ''); // bei Suche alle mit Treffern öffnen, sonst nur erste

    const body = list.length
      ? list.map(r => `
          <div class="list-group-item d-flex align-items-center justify-content-between"
               data-id="${r.id}" data-sachnummer="${esc(r.sachnummer)}" data-lagergruppe="${esc(r.lagergruppe||'')}">
            <div class="me-3">
              <div class="fw-semibold">${esc(r.sachnummer)}</div>
              <div class="text-muted small">Geändert: ${new Date(r.updated_at).toLocaleString('de-DE')}</div>
            </div>
            <div class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
              <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
            </div>
          </div>`).join('')
      : `<div class="p-3 text-muted">Keine Einträge.</div>`;

    return `
      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button ${show ? '' : 'collapsed'}" type="button"
                  data-bs-toggle="collapse" data-bs-target="#${cid}"
                  aria-expanded="${show ? 'true' : 'false'}" aria-controls="${cid}">
            ${esc(lg)} <span class="badge bg-secondary ms-2">${list.length}</span>
          </button>
        </h2>
        <div id="${cid}" class="accordion-collapse collapse ${show}" data-bs-parent="#accSach">
          <div class="accordion-body p-0">
            <div class="list-group list-group-flush">
              ${body}
            </div>
          </div>
        </div>
      </div>`;
  }).join('');

  // Event-Delegation für Edit/Löschen
  acc.onclick = (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const row = btn.closest('.list-group-item');
    if (!row) return;
    const id   = row.dataset.id;
    const sach = row.dataset.sachnummer;
    const lg   = row.dataset.lagergruppe;

    if (btn.dataset.act === 'edit') {
      openModalSach(root, id, sach, lg);
    } else if (btn.dataset.act === 'del') {
      if (confirm('Eintrag wirklich löschen?')) {
        apiDelete('sachnummer', id)
          .then(() => loadSach(root, $(root,'#searchSach')?.value.trim() || ''))
          .catch(err => alert('Fehler: ' + err.message));
      }
    }
  };
}

