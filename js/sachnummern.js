

// /LKW/js/sachnummern.js
const API = '/api/stammdaten_api.php';
const API_CUST = '/kommi/api/cmr_recipients_api.php';

const LG_OPTIONS = ['W1','X3','X3(B)','G9','B1','B1(T)','Bauteile','BM','Sarajevo','Müll'];
const behExtraWrap = document.getElementById('behExtraWrap');
const fieldVwKennung = document.getElementById('fieldVwKennung');
const fieldKltsProBehaelter = document.getElementById('fieldKltsProBehaelter');
const fieldEinheit = document.getElementById('fieldEinheit');
const fieldBehStatus = document.getElementById('fieldBehStatus');

// --- DOM helpers ---
function $(root, sel) { return root.querySelector(sel); }
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, m => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[m]));
}

async function custFetchJson(url, opts = {}) {
  const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...opts });

  const ct  = res.headers.get('content-type') || '';
  const raw = await res.text();

  let j = null;
  try {
    j = JSON.parse(raw);
  } catch (e) {
    console.error('CMR-Recipients API: KEIN JSON!', {
      url: String(url), status: res.status, ct, raw: raw.slice(0, 1200)
    });
    throw new Error(`cust_non_json (${res.status})`);
  }

  if (!res.ok || !j?.ok) {
    console.warn('CMR-Recipients API error payload:', j);
    throw new Error(j?.error || `cust_failed (${res.status})`);
  }
  return j;
}



async function custList() {
  const url = new URL(API_CUST, location.origin);
  url.searchParams.set('action', 'list');
  const j = await custFetchJson(url);
  return Array.isArray(j.items) ? j.items : [];
}

async function custUpsert(payload) {
  const url = new URL(API_CUST, location.origin);
  url.searchParams.set('action', 'upsert');
  await custFetchJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
}

async function custDelete(code) {
  const url = new URL(API_CUST, location.origin);
  url.searchParams.set('action', 'delete');
  await custFetchJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code })
  });
}

function readCustForm() {
  const v = (id) => (document.querySelector(id)?.value || '').trim();

  const payload = {
    orig_code: v('#custOrigCode') || null,
    code:      v('#custCode'),
    name:      v('#custName'),
    address1:  v('#custAddr1'),
    address2:  v('#custAddr2'),
    postal:    v('#custPostal'),
    city:      v('#custCity'),
    country:   v('#custCountry'),
    note:      v('#custNote')
  };

  if (!payload.code)     throw new Error('Kürzel fehlt.');
  if (!payload.name)     throw new Error('Kunde/Name fehlt.');
  if (!payload.address1) throw new Error('Adresse 1 fehlt.');
  if (!payload.postal)   throw new Error('PLZ fehlt.');
  if (!payload.city)     throw new Error('Ort fehlt.');
  if (!payload.country)  throw new Error('Land fehlt.');

  return payload;
}

function fillCustForm(it = null) {
  const set = (id, val) => { const el = document.querySelector(id); if (el) el.value = val ?? ''; };

  set('#custOrigCode', it?.code || '');
  set('#custCode',     it?.code || '');
  set('#custName',     it?.name || '');
  set('#custAddr1',    it?.address1 || '');
  set('#custAddr2',    it?.address2 || '');
  set('#custPostal',   it?.postal || '');
  set('#custCity',     it?.city || '');
  set('#custCountry',  it?.country || '');
  set('#custNote',     it?.note || '');
}

function renderCustTable(root, items) {
  const table = $(root, '#tblCust');
  const tbody = table?.querySelector('tbody');
  if (!tbody) return;

  tbody.innerHTML = '';

  if (!items.length) {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="8" class="text-muted">Keine Kunden vorhanden.</td>`;
    tbody.appendChild(tr);
    return;
  }

  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.dataset.code = it.code || '';
    tr.dataset.item = JSON.stringify(it);

    tr.innerHTML = `
      <td class="fw-semibold">${esc(it.code || '')}</td>
      <td>${esc(it.name || '')}</td>
      <td>${esc(it.address1 || '')}${it.address2 ? '<br><span class="text-muted small">'+esc(it.address2)+'</span>' : ''}</td>
      <td>${esc(it.postal || '')}</td>
      <td>${esc(it.city || '')}</td>
      <td>${esc(it.country || '')}</td>
      <td><span class="text-muted small">${it.updated_at ? new Date(it.updated_at).toLocaleString('de-DE') : ''}</span></td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
        <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  tbody.onclick = async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const it = JSON.parse(tr.dataset.item || '{}');

    if (btn.dataset.act === 'edit') {
      document.querySelector('#custModalTitle') && (document.querySelector('#custModalTitle').textContent = `Kunde bearbeiten: ${it.code}`);
      fillCustForm(it);
      bootstrap.Modal.getOrCreateInstance(document.querySelector('#custModal')).show();
      setTimeout(() => document.querySelector('#custName')?.focus(), 50);
    }

    if (btn.dataset.act === 'del') {
      if (!confirm(`Kunde "${it.code}" wirklich löschen?`)) return;
      try {
        await custDelete(it.code);
        await loadKunden(root);
      } catch (err) {
        alert('Fehler: ' + (err?.message || err));
      }
    }
  };
}

let _KUNDEN_ITEMS = [];

function applyKundenSearch(root) {
  const q = ($(root, '#searchCust')?.value || '').trim().toLowerCase();
  if (!q) return renderCustTable(root, _KUNDEN_ITEMS);

  const terms = q.split(/\s+/).filter(Boolean);
  const hit = (it) => {
    const hay = [
      it.code, it.name, it.address1, it.address2, it.postal, it.city, it.country, it.note
    ].join(' ').toLowerCase();
    return terms.every(t => hay.includes(t));
  };

  renderCustTable(root, _KUNDEN_ITEMS.filter(hit));
}

async function loadKunden(root) {
  _KUNDEN_ITEMS = await custList();
  _KUNDEN_ITEMS.sort((a,b) => String(a.code||'').localeCompare(String(b.code||''), 'de', { numeric:true }));
  applyKundenSearch(root);
}

function wireKundenEvents(root) {
  const btnNew = $(root, '#btnNewCust');
  const inp    = $(root, '#searchCust');
  const form   = document.querySelector('#custForm');
  const modalEl= document.querySelector('#custModal');

  if (!btnNew || !inp || !form || !modalEl) {
    console.warn('Kunden UI fehlt in DOM (TabCust HTML/Modal ids prüfen)');
    return;
  }

  btnNew.addEventListener('click', () => {
    document.querySelector('#custModalTitle') && (document.querySelector('#custModalTitle').textContent = 'Neuer Kunde');
    fillCustForm(null);
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    setTimeout(() => document.querySelector('#custCode')?.focus(), 50);
  });

  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  inp.addEventListener('input', debounce(() => applyKundenSearch(root), 200));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const payload = readCustForm();
      await custUpsert(payload);
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      await loadKunden(root);
    } catch (err) {
      alert('Fehler: ' + (err?.message || err));
    }
  });
}
// =================== /KUNDEN ===================


// --- Lagergruppe helper ---
function setSelectValue(selectEl, val) {
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
    if (s.startsWith('[') && s.endsWith(']')) {
      try {
        const arr = JSON.parse(s);
        if (Array.isArray(arr)) return arr.filter(Boolean).map(x => String(x).trim());
      } catch (_) {}
    }
    return s.split(',').map(x => x.trim()).filter(Boolean);
  }
  return [];
}
function formatPlatesShort(arr = []) {
  const a = parsePlates(arr);
  if (a.length <= 2) return a.join(', ');
  return `${a.slice(0, 2).join(', ')} +${a.length - 2}`;
}

function renderPlateBadges(raw) {
  const plates = parsePlates(raw);

  if (!plates.length) {
    return `<span class="plate-badge is-empty">Keine Kennzeichen</span>`;
  }

  return `
    <div class="plate-badges">
      ${plates.map(p => `<span class="plate-badge">${esc(p)}</span>`).join('')}
    </div>
  `;
}

// --- API helpers ---
// async function apiList(type, q = '') {
//   const url = new URL(API, location.origin);
//   url.searchParams.set('type', type);
//   url.searchParams.set('action', 'list');
//   if (q) url.searchParams.set('q', q);

//   const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
//   const j = await res.json().catch(() => ({}));
//   if (!res.ok || !j?.ok) throw new Error(j?.error || `list_failed (${type})`);
//   return j.items;
// }
// async function apiCreate(type, payload) {
//   const fd = new FormData();
//   fd.set('type', type);
//   fd.set('action', 'create');
//   Object.entries(payload).forEach(([k, v]) => fd.set(k, v));
//   const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
//   const j = await res.json().catch(() => ({}));
//   if (!res.ok || !j?.ok) throw new Error(j?.error || 'create_failed');
//   return j.id;
// }
// async function apiUpdate(type, id, payload) {
//   const fd = new FormData();
//   fd.set('type', type);
//   fd.set('action', 'update');
//   fd.set('id', id);
//   Object.entries(payload).forEach(([k, v]) => fd.set(k, v));
//   const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
//   const j = await res.json().catch(() => ({}));
//   if (!res.ok || !j?.ok) throw new Error(j?.error || 'update_failed');
//   return true;
// }
// async function apiDelete(type, id) {
//   const fd = new FormData();
//   fd.set('type', type);
//   fd.set('action', 'delete');
//   fd.set('id', id);
//   const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
//   const j = await res.json().catch(() => ({}));
//   if (!res.ok || !j?.ok) throw new Error(j?.error || 'delete_failed');
//   return true;
// }

async function apiFetchJson(url, opts = {}) {
  const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...opts });
  const ct  = res.headers.get('content-type') || '';
  const raw = await res.text();

  let j = null;
  try {
    j = JSON.parse(raw);
  } catch (e) {
    console.error('Stammdaten API: KEIN JSON!', {
      url: String(url),
      status: res.status,
      ct,
      raw: raw.slice(0, 2000),
    });
    throw new Error(`non_json (${res.status})`);
  }

  if (!res.ok || !j?.ok) {
    console.warn('Stammdaten API error payload:', j);
    throw new Error(j?.error || `failed (${res.status})`);
  }
  return j;
}

async function apiList(type, q = '') {
  const url = new URL(API, location.origin);
  url.searchParams.set('type', type);
  url.searchParams.set('action', 'list');
  if (q) url.searchParams.set('q', q);

  const j = await apiFetchJson(url);
  return Array.isArray(j.items) ? j.items : [];
}

async function apiCreate(type, payload) {
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'create');
  Object.entries(payload).forEach(([k, v]) => fd.set(k, v));

  const j = await apiFetchJson(API, { method: 'POST', body: fd });
  return j.id;
}

async function apiUpdate(type, id, payload) {
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'update');
  fd.set('id', id);
  Object.entries(payload).forEach(([k, v]) => fd.set(k, v));

  await apiFetchJson(API, { method: 'POST', body: fd });
  return true;
}

async function apiDelete(type, id) {
  const fd = new FormData();
  fd.set('type', type);
  fd.set('action', 'delete');
  fd.set('id', id);

  await apiFetchJson(API, { method: 'POST', body: fd });
  return true;
}

// --- Loader ---
async function loadSach(root, q = '') {
  const items = await apiList('sachnummer', q);
  renderSachAccordion(root, items, { query: q });
}
async function loadGeneric(root, type, q = '') {
  const items = await apiList(type, q);
  renderTableGeneric(root, type, items);
}

// --- Render: Sachnummern (Accordion) ---
function renderTableSach(root, items) {
  const tbody = $(root, '#tblSach tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.dataset.sachnummer = it.sachnummer;
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
          .then(() => loadSach(root, $(root, '#searchSach')?.value.trim() || ''))
          .catch(err => alert('Fehler: ' + err.message));
      }
    }
  };
}

function renderTableGeneric(root, type, items) {
  const isSped = (type === 'spedition');
  const isBeh  = (type === 'behaelter');

  const conf = {
    behaelter: { table: '#tblBeh', field: 'nummer' },
    spedition: { table: '#tblSped', field: 'name' }
  }[type];

  const tbody = $(root, conf.table + ' tbody');
  if (!tbody) return;
  tbody.innerHTML = '';

  if (!items.length) {
    const colspan = isBeh ? 7 : 4;
    tbody.innerHTML = `
      <tr>
        <td colspan="${colspan}" class="text-muted">Keine Einträge gefunden.</td>
      </tr>
    `;
    return;
  }

  items.forEach(it => {
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.dataset.val = it[conf.field] || '';

    if (isSped) {
      const platesArr = parsePlates(it.plates);
      tr.dataset.plates = JSON.stringify(platesArr);
    }

    if (isBeh) {
      tr.dataset.vwKennung = it.vw_kennung || '';
      tr.dataset.klts = String(it.klts_pro_behaelter ?? 0);
      tr.dataset.einheit = it.einheit || 'GB';
      tr.dataset.status = it.status || 'aktiv';

      tr.innerHTML = `
        <td>${esc(it.nummer || '')}</td>
        <td>${esc(it.vw_kennung || '')}</td>
        <td class="text-end">${esc(it.klts_pro_behaelter ?? 0)}</td>
        <td>${esc(it.einheit || 'GB')}</td>
        <td>${esc(it.status || 'aktiv')}</td>
        <td><span class="text-muted small">${it.updated_at ? new Date(it.updated_at).toLocaleString('de-DE') : ''}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
          <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
        </td>
      `;
    } else if (isSped) {
  tr.innerHTML = `
    <td>${esc(it.name)}</td>
    <td>${renderPlateBadges(it.plates)}</td>
    <td><span class="text-muted small">${it.updated_at ? new Date(it.updated_at).toLocaleString('de-DE') : ''}</span></td>
    <td>
      <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
      <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
    </td>
  `;
}

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
      } else if (isBeh) {
        openModalGeneric(root, 'behaelter', id, {
          nummer: tr.dataset.val || '',
          vw_kennung: tr.dataset.vwKennung || '',
          klts_pro_behaelter: tr.dataset.klts || '0',
          einheit: tr.dataset.einheit || 'GB',
          status: tr.dataset.status || 'aktiv'
        });
      }
    } else if (btn.dataset.act === 'del') {
      if (!confirm('Eintrag wirklich löschen?')) return;

      apiDelete(type, id)
        .then(() => loadGeneric(
          root,
          type,
          (type === 'spedition'
            ? $(root, '#searchSped')
            : $(root, '#searchBeh')
          )?.value.trim() || ''
        ))
        .catch(err => alert('Fehler: ' + err.message));
    }
  };
}

function openModalSach(root, id = '', sachnummer = '', lagergruppe = '', brt = '', beh = '', zus = '') {
  const modalEl = document.querySelector('#editModal');
  const modal   = bootstrap.Modal.getOrCreateInstance(modalEl);

  const $id        = modalEl.querySelector('#fieldId');
  const $entity    = modalEl.querySelector('#fieldEntity');
  const $value     = modalEl.querySelector('#fieldValue');
  const $label     = modalEl.querySelector('#fieldLabel');
  const $title     = modalEl.querySelector('#modalTitle');
  const $lgWrap    = modalEl.querySelector('#lgWrap');
  const $lg        = modalEl.querySelector('#fieldLagergruppe');
  const $plateWrap = modalEl.querySelector('#plateWrap');
  const $plates    = modalEl.querySelector('#fieldPlates');
  const $snExtra   = modalEl.querySelector('#snExtraWrap');

  const $brtGew    = modalEl.querySelector('#fieldBruttogewicht');
  const $behNr     = modalEl.querySelector('#fieldBehaelterNr');
  const $zusBeh    = modalEl.querySelector('#fieldZusBehaelter');

  $id.value = id || '';
  $entity.value = 'sachnummer';
  $label.textContent = 'Sachnummer';
  $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Sachnummer';
  $value.value = sachnummer || '';

  $lgWrap.classList.remove('d-none');
  $snExtra.classList.remove('d-none');

  if ($brtGew) $brtGew.value = brt ?? '';
  if ($behNr)  $behNr.value  = beh ?? '';
  if ($zusBeh) $zusBeh.value = zus ?? '';

  $lg.required = true;
  setSelectValue($lg, lagergruppe || '');

  $plateWrap.classList.add('d-none');
  if ($plates) $plates.value = '';

  modal.show();
  setTimeout(() => $value.focus(), 50);
}

function openModalGeneric(root, type, id = '', value = '', plates = []) {
  const modalEl = document.querySelector('#editModal');
  const modal   = bootstrap.Modal.getOrCreateInstance(modalEl);

  const $id        = modalEl.querySelector('#fieldId');
  const $entity    = modalEl.querySelector('#fieldEntity');
  const $value     = modalEl.querySelector('#fieldValue');
  const $label     = modalEl.querySelector('#fieldLabel');
  const $title     = modalEl.querySelector('#modalTitle');
  const $lgWrap    = modalEl.querySelector('#lgWrap');
  const $lg        = modalEl.querySelector('#fieldLagergruppe');
  const $plateWrap = modalEl.querySelector('#plateWrap');
  const $plates    = modalEl.querySelector('#fieldPlates');
  const $snExtra   = modalEl.querySelector('#snExtraWrap');
  const $behExtra  = modalEl.querySelector('#behExtraWrap');

  $id.value = id || '';
  $entity.value = type;

  $lgWrap.classList.add('d-none');
  $snExtra.classList.add('d-none');
  $plateWrap.classList.add('d-none');
  $behExtra?.classList.add('d-none');

  if ($lg) {
    $lg.required = false;
    $lg.value = '';
  }

  if ($plates) $plates.value = '';

  if (type === 'behaelter') {
    const row = (value && typeof value === 'object') ? value : {};

    $label.textContent = 'Behältertyp';
    $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Behälter';
    $value.value = row.nummer || '';
    $value.placeholder = 'z. B. 6280 oder 114 003';

    $behExtra?.classList.remove('d-none');

    if (fieldVwKennung) fieldVwKennung.value = row.vw_kennung || '';
    if (fieldKltsProBehaelter) fieldKltsProBehaelter.value = row.klts_pro_behaelter ?? 0;
    if (fieldEinheit) fieldEinheit.value = row.einheit || 'GB';
    if (fieldBehStatus) fieldBehStatus.value = row.status || 'aktiv';
  } else {
    $value.value = value || '';
    $behExtra?.classList.add('d-none');

    if (fieldVwKennung) fieldVwKennung.value = '';
    if (fieldKltsProBehaelter) fieldKltsProBehaelter.value = '';
    if (fieldEinheit) fieldEinheit.value = 'GB';
    if (fieldBehStatus) fieldBehStatus.value = 'aktiv';

    if (type === 'spedition') {
      $label.textContent = 'Spedition';
      $title.textContent = (id ? 'Bearbeiten ' : 'Neu ') + 'Spedition';
      $plateWrap.classList.remove('d-none');
      if ($plates) $plates.value = parsePlates(plates).join(', ');
    }
  }

  modal.show();
  setTimeout(() => $value.focus(), 50);
}

// --- Events ---
function wireEvents(root) {
  const getRoot = () => root || document.querySelector('[data-tab-root]') || document.body;

  const bindOnce = (el, key, eventName, handler) => {
    if (!el) return;
    const flag = `bound${key}`;
    if (el.dataset[flag] === '1') return;
    el.dataset[flag] = '1';
    el.addEventListener(eventName, handler);
  };

  const debounce = (fn, ms) => {
    let t;
    return (...a) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...a), ms);
    };
  };

  bindOnce($(root, '#btnNewSach'), 'NewSach', 'click', () => openModalSach(getRoot()));
  bindOnce($(root, '#btnNewBeh'),  'NewBeh',  'click', () => openModalGeneric(getRoot(), 'behaelter'));
  bindOnce($(root, '#btnNewSped'), 'NewSped', 'click', () => openModalGeneric(getRoot(), 'spedition'));

  bindOnce(
    $(root, '#searchSach'),
    'SearchSach',
    'input',
    debounce(() => loadSach(getRoot(), $(getRoot(), '#searchSach')?.value.trim() || ''), 200)
  );

  bindOnce(
    $(root, '#searchBeh'),
    'SearchBeh',
    'input',
    debounce(() => loadGeneric(getRoot(), 'behaelter', $(getRoot(), '#searchBeh')?.value.trim() || ''), 200)
  );

  bindOnce(
    $(root, '#searchSped'),
    'SearchSped',
    'input',
    debounce(() => loadGeneric(getRoot(), 'spedition', $(getRoot(), '#searchSped')?.value.trim() || ''), 200)
  );

  const form = document.querySelector('#editModal #editForm');
  bindOnce(form, 'Submit', 'submit', async (e) => {
    e.preventDefault();

    if (form.dataset.submitting === '1') {
      return;
    }

    form.dataset.submitting = '1';

    const modalEl = document.querySelector('#editModal');
    const effectiveRoot = getRoot();
    const submitBtn = form.querySelector('button[type="submit"]');

    if (submitBtn) {
      submitBtn.disabled = true;
    }

    try {
      const type = modalEl.querySelector('#fieldEntity').value;
      const id   = modalEl.querySelector('#fieldId').value;
      const val  = modalEl.querySelector('#fieldValue').value.trim();

      if (type === 'sachnummer') {
        const lg  = (modalEl.querySelector('#fieldLagergruppe')?.value ?? '').trim();
        const brt = (modalEl.querySelector('#fieldBruttogewicht')?.value ?? '').trim();
        const beh = (modalEl.querySelector('#fieldBehaelterNr')?.value ?? '').trim();
        const zus = (modalEl.querySelector('#fieldZusBehaelter')?.value ?? '').trim();

        if (!val) return alert('Bitte Sachnummer eingeben.');
        if (!lg)  return alert('Bitte Lagergruppe wählen.');

        const payload = {
          sachnummer: val,
          lagergruppe: lg,
          brt_gew: brt,
          behaelter_nr: beh,
          zus_behaelter: zus
        };

        if (id) await apiUpdate('sachnummer', id, payload);
        else    await apiCreate('sachnummer', payload);

        await loadSach(effectiveRoot, $(effectiveRoot, '#searchSach')?.value.trim() || '');
      }

      else if (type === 'behaelter') {
        if (!val) return alert('Bitte Behältertyp eingeben.');

        const vwKennung = (fieldVwKennung?.value || '').trim();
        const klts = parseInt(fieldKltsProBehaelter?.value || '0', 10);
        const einheit = (fieldEinheit?.value || 'GB').trim();
        const status = (fieldBehStatus?.value || 'aktiv').trim();

        const payload = {
          nummer: val,
          vw_kennung: vwKennung,
          klts_pro_behaelter: Number.isNaN(klts) ? 0 : klts,
          einheit: einheit,
          status: status
        };

        if (id) await apiUpdate('behaelter', id, payload);
        else    await apiCreate('behaelter', payload);

        await loadGeneric(
          effectiveRoot,
          'behaelter',
          $(effectiveRoot, '#searchBeh')?.value.trim() || ''
        );
      }

      else if (type === 'spedition') {
        if (!val) return alert('Bitte Spedition eingeben.');

        const platesCsv = (modalEl.querySelector('#fieldPlates')?.value || '')
          .split(',')
          .map(s => s.trim())
          .filter(Boolean)
          .join(',');

        if (id) {
          await apiUpdate('spedition', id, { name: val, plates: platesCsv });
        } else {
          await apiCreate('spedition', { name: val, plates: platesCsv });
        }

        await loadGeneric(
          effectiveRoot,
          'spedition',
          $(effectiveRoot, '#searchSped')?.value.trim() || ''
        );
      }

      bootstrap.Modal.getInstance(document.querySelector('#editModal'))?.hide();
      form.reset?.();
    } catch (err) {
      const msg = err?.message || '';

      if (msg === 'duplicate' || msg === 'duplicate_spedition_name_plates') {
        alert('Diese Spedition mit genau diesem Kennzeichen existiert bereits.');
      } else if (msg === 'missing_plates') {
        alert('Bitte mindestens ein Kennzeichen eingeben.');
      } else if (msg === 'missing_name') {
        alert('Bitte einen Speditionsnamen eingeben.');
      } else {
        alert('Fehler: ' + msg);
      }
    } finally {
      form.dataset.submitting = '0';
      if (submitBtn) {
        submitBtn.disabled = false;
      }
    }
  });
}

async function fillBehaelterDatalist() {
  const list = await apiList('behaelter', '');
  const dl = document.querySelector('#behList');
  if (!dl) return;
  dl.innerHTML = list.map(b => `<option value="${esc(b.nummer)}"></option>`).join('');
}

// --- Init-Export ---
export async function initSachnummern(root) {
  wireEvents(root);
  wireKundenEvents(root);

  await Promise.all([
    loadSach(root),
    loadGeneric(root, 'behaelter'),
    loadGeneric(root, 'spedition'),
    loadKunden(root),
  ]);

  await fillBehaelterDatalist();
}


// --- Standalone-Autostart ---
if (!window.__EMBEDDED__) {
  window.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-tab-root]') || document.body;
    initSachnummern(root);
  });
}

// --- Accordion-Renderer ---
function slugLG(lg) {
  return 'lg-' + String(lg || 'ohne').toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function renderSachAccordion(root, items, { query = '' } = {}) {
  const acc = $(root, '#accSach');
  if (!acc) return;

  const fmtWeight = (v) => {
    if (v === null || v === undefined) return '';
    const s = String(v).trim().replace(',', '.');
    if (!s) return '';
    const n = Number(s);
    if (!Number.isFinite(n)) return s;
    return (Math.round(n * 100) / 100)
      .toString()
      .replace(/\.0+$/, '')
      .replace(/(\.\d*[1-9])0+$/, '$1');
  };

  const groups = new Map();
  for (const it of items) {
    const k = it.lagergruppe || 'Ohne Lagergruppe';
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(it);
  }

  const KNOWN = LG_OPTIONS.slice();
  const extras = [...groups.keys()]
    .filter(k => !KNOWN.includes(k) && k !== 'Ohne Lagergruppe')
    .sort((a, b) => a.localeCompare(b, 'de'));

  const order = [
    ...KNOWN.filter(k => groups.has(k)),
    ...extras,
    ...(groups.has('Ohne Lagergruppe') ? ['Ohne Lagergruppe'] : [])
  ];

  let i = 0;
  acc.innerHTML = order.map((lg) => {
    const list = groups.get(lg) || [];
    const cid  = slugLG(lg) + '-' + (i++);
    const show = query ? 'show' : (i === 1 ? 'show' : '');

    const body = list.length
      ? list.map((r) => {
          const parts = [];

          const beh = String(r.behaelter_nr ?? '').trim();
          const brt = fmtWeight(r.brt_gew);
          const zus = String(r.zus_behaelter ?? '').trim();
          const by  = String(r.updated_by ?? '').trim();

          if (beh) parts.push(`<span class="me-2"><i class="bi bi-inbox me-1"></i><span class="text-secondary">Beh.-Nr.:</span> ${esc(beh)}</span>`);
          if (brt) parts.push(`<span class="me-2"><i class="bi bi-speedometer2 me-1"></i><span class="text-secondary">BRT-GEW:</span> ${esc(brt)} kg</span>`);
          if (zus !== '') parts.push(`<span class="me-2"><i class="bi bi-box-seam me-1"></i><span class="text-secondary">Zus.-Beh.:</span> ${esc(zus)}</span>`);

          const infoLine = parts.length
            ? `<div class="small text-secondary">${parts.join(' · ')}</div>`
            : '';

          const changedLine =
            `Geändert: ${new Date(r.updated_at).toLocaleString('de-DE')}${by ? ` · von ${esc(by)}` : ''}`;

          return `
            <div class="list-group-item d-flex align-items-center justify-content-between"
                 data-id="${esc(r.id)}"
                 data-sachnummer="${esc(r.sachnummer)}"
                 data-sachnummer_key="${esc(r.sachnummer_key || '')}"
                 data-lagergruppe="${esc(r.lagergruppe || '')}"
                 data-brt_gew="${esc(r.brt_gew ?? '')}"
                 data-behaelter_nr="${esc(r.behaelter_nr ?? '')}"
                 data-zus_behaelter="${esc(r.zus_behaelter ?? '')}"
                 data-updated_by="${esc(r.updated_by ?? '')}">
              <div class="me-3">
                <div class="fw-semibold">${esc(r.sachnummer)}</div>
                ${infoLine}
                <div class="text-muted small">${changedLine}</div>
              </div>
              <div class="text-nowrap">
                <button class="btn btn-sm btn-outline-primary me-1" data-act="edit">Bearbeiten</button>
                <button class="btn btn-sm btn-outline-danger" data-act="del">Löschen</button>
              </div>
            </div>`;
        }).join('')
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

  acc.onclick = (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const row = btn.closest('.list-group-item');
    if (!row) return;

    const id   = row.dataset.id;
    const sach = row.dataset.sachnummer;
    const lg   = row.dataset.lagergruppe;

    const brt  = row.dataset.brt_gew || '';
    const beh  = row.dataset.behaelter_nr || '';
    const zus  = row.dataset.zus_behaelter || '';

    if (btn.dataset.act === 'edit') {
      openModalSach(root, id, sach, lg, brt, beh, zus);
    } else if (btn.dataset.act === 'del') {
      if (confirm('Eintrag wirklich löschen?')) {
        apiDelete('sachnummer', id)
          .then(() => loadSach(root, $(root, '#searchSach')?.value.trim() || ''))
          .catch(err => alert('Fehler: ' + err.message));
      }
    }
  };
}

(() => {
  "use strict";

  const escHtml = (s) => String(s ?? "").replace(/[&<>"']/g, (m) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
  }[m]));

  const deDateTime = (d = new Date()) =>
    new Intl.DateTimeFormat("de-DE", {
      year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit"
    }).format(d);

  function getCurrentUserName() {
  const candidates = [
    window.CURRENT_USER,
    document.body?.dataset?.username,
    document.documentElement?.dataset?.username,
    document.querySelector('meta[name="app-user"]')?.getAttribute("content"),
    document.getElementById("loggedUserName")?.textContent,
    document.querySelector("[data-username]")?.getAttribute("data-username")
  ];

  for (const c of candidates) {
    const v = String(c ?? "").trim();
    if (v && !/^unbekannt$/i.test(v)) return v;
  }

  // Fallback: aus sichtbarem UI-Text "Angemeldet als ..."
  const probeSelectors = [
    "#userInfo", "#loginInfo", ".user-info", ".topbar-user", ".navbar-text", "header", "nav"
  ];

  for (const sel of probeSelectors) {
    const el = document.querySelector(sel);
    if (!el) continue;
    const t = (el.textContent || "").replace(/\s+/g, " ").trim();
    if (!/angemeldet als/i.test(t)) continue;

    // Beispieltext: "Angemeldet als Daniel Strübig admin 1 online"
    const m = t.match(/angemeldet als\s+(.+?)(?:\s+(?:admin|user|online|offline)\b.*)?$/i);
    if (m?.[1]) {
      const name = m[1].trim();
      if (name) return name;
    }
  }

  return "Unbekannt";
}


  function getActiveGroupNameFromDOM() {
    const open = document.querySelector("#accSach .accordion-collapse.show");
    const item = open?.closest(".accordion-item") || document.querySelector("#accSach .accordion-item");
    if (!item) return null;

    const btn = item.querySelector(".accordion-button");
    if (!btn) return null;

    const clone = btn.cloneNode(true);
    clone.querySelector(".badge")?.remove();
    const name = (clone.textContent || "").trim();
    return name || null;
  }

  function orderGroupNames(grouped) {
    const keys = Object.keys(grouped || {});
    const known = Array.isArray(LG_OPTIONS) ? LG_OPTIONS : [];
    const extras = keys
      .filter(k => !known.includes(k) && k !== "Ohne Lagergruppe")
      .sort((a, b) => a.localeCompare(b, "de"));

    return [
      ...known.filter(k => keys.includes(k)),
      ...extras,
      ...(keys.includes("Ohne Lagergruppe") ? ["Ohne Lagergruppe"] : [])
    ];
  }

  function groupRows(rows) {
    const grouped = {};
    for (const r of rows || []) {
      const sach = String(r?.sachnummer ?? "").trim();
      if (!sach) continue;

      const lg = String(r?.lagergruppe ?? "Ohne Lagergruppe").trim() || "Ohne Lagergruppe";
      (grouped[lg] ||= []).push({
        Sachnummer: sach,
        Lagergruppe: lg,
        BRT_GEW: r?.brt_gew ?? "",
        Behaelter_Nr: r?.behaelter_nr ?? "",
        Zus_Behaelter: r?.zus_behaelter ?? "",
        Geaendert: r?.updated_at ?? ""
      });
    }

    for (const g of Object.keys(grouped)) {
      grouped[g].sort((a, b) => a.Sachnummer.localeCompare(b.Sachnummer, "de", { numeric: true }));
    }
    return grouped;
  }

  function collectFromDOMFallback() {
    const grouped = {};
    document.querySelectorAll("#accSach .accordion-item").forEach((item) => {
      const btn = item.querySelector(".accordion-button");
      const clone = btn ? btn.cloneNode(true) : null;
      clone?.querySelector(".badge")?.remove();
      const lg = (clone?.textContent || "Ohne Lagergruppe").trim() || "Ohne Lagergruppe";

      item.querySelectorAll(".list-group-item").forEach((row) => {
        const sach = (row.querySelector(".fw-semibold")?.textContent || "").trim();
        if (!sach) return;

        const updRaw = (row.querySelector(".text-muted.small")?.textContent || "").trim();
        const upd = updRaw.replace(/^Geändert:\s*/i, "");

        (grouped[lg] ||= []).push({
          Sachnummer: sach,
          Lagergruppe: lg,
          BRT_GEW: "",
          Behaelter_Nr: "",
          Zus_Behaelter: "",
          Geaendert: upd
        });
      });
    });
    return grouped;
  }

  async function getGroupedDataCurrentFilter() {
    // nutzt dein vorhandenes apiList('sachnummer', q)
    const q = (document.querySelector("#searchSach")?.value || "").trim();

    try {
      const rows = await apiList("sachnummer", q);
      const grouped = groupRows(rows);
      if (Object.keys(grouped).length) return grouped;
    } catch (e) {
      console.warn("API fehlgeschlagen, DOM-Fallback aktiv:", e?.message || e);
    }

    return collectFromDOMFallback();
  }

  function uniqueSheetName(base, used) {
    let name = String(base || "Gruppe").replace(/[:\\/?*\[\]]/g, " ").trim();
    if (!name) name = "Gruppe";
    name = name.slice(0, 31);

    let out = name;
    let i = 1;
    while (used.has(out)) {
      const suffix = "_" + i++;
      out = name.slice(0, 31 - suffix.length) + suffix;
    }
    used.add(out);
    return out;
  }

  async function getGroupedDataForExport() {
  // 1) Wenn vorhanden: deine bestehende Funktion nutzen
  if (typeof getGroupedDataCurrentFilter === "function") {
    return await getGroupedDataCurrentFilter();
  }
  if (typeof getGroupedData === "function") {
    return await getGroupedData();
  }

  // 2) Fallback: direkt über API laden und gruppieren
  const q = (document.querySelector("#searchSach")?.value || "").trim();
  const rows = await apiList("sachnummer", q); // nutzt deine vorhandene apiList()

  const grouped = {};
  for (const r of (rows || [])) {
    const sach = String(r?.sachnummer ?? "").trim();
    if (!sach) continue;

    const lg = String(r?.lagergruppe ?? "Ohne Lagergruppe").trim() || "Ohne Lagergruppe";

    (grouped[lg] ||= []).push({
      Sachnummer: sach,
      Lagergruppe: lg,
      BRT_GEW: r?.brt_gew ?? "",
      Behaelter_Nr: r?.behaelter_nr ?? "",
      Zus_Behaelter: r?.zus_behaelter ?? "",
      Geaendert: r?.updated_at ?? ""
    });
  }

  // sortieren
  Object.keys(grouped).forEach(g => {
    grouped[g].sort((a, b) =>
      a.Sachnummer.localeCompare(b.Sachnummer, "de", { numeric: true })
    );
  });

  return grouped;
}


  async function exportExcelActiveGroup() {
    if (typeof XLSX === "undefined") {
      alert("XLSX-Library nicht geladen.");
      return;
    }

    const grouped = await getGroupedDataForExport();
    const activeGroup = getActiveGroupNameFromDOM();

    let groupName = activeGroup && grouped[activeGroup] ? activeGroup : null;
    if (!groupName) {
      const ordered = orderGroupNames(grouped);
      groupName = ordered[0] || null;
    }

    if (!groupName || !grouped[groupName]?.length) {
      alert("Keine Daten in der aktiven Lagergruppe gefunden.");
      return;
    }

    const rows = grouped[groupName].map((r, i) => ({
      Pos: i + 1,
      Sachnummer: r.Sachnummer ?? "",
      Lagergruppe: r.Lagergruppe ?? groupName,
      BRT_GEW: r.BRT_GEW ?? "",
      Behaelter_Nr: r.Behaelter_Nr ?? "",
      Zus_Behaelter: r.Zus_Behaelter ?? "",
      Geaendert: r.Geaendert ?? ""
    }));

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(rows);
    ws["!cols"] = [
      { wch: 6 }, { wch: 24 }, { wch: 14 }, { wch: 12 },
      { wch: 16 }, { wch: 14 }, { wch: 20 }
    ];

    if (rows.length && ws["!ref"]) {
      const range = XLSX.utils.decode_range(ws["!ref"]);
      ws["!autofilter"] = { ref: XLSX.utils.encode_range(range) };
    }

    XLSX.utils.book_append_sheet(wb, ws, uniqueSheetName(groupName, new Set()));

    const d = new Date();
    const stamp = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}_${String(d.getHours()).padStart(2,"0")}${String(d.getMinutes()).padStart(2,"0")}`;
    XLSX.writeFile(wb, `Sachnummern_${groupName}_${stamp}.xlsx`);
  }

    async function exportExcelAllGroups() {
    if (typeof XLSX === "undefined") {
      alert("XLSX-Library nicht geladen.");
      return;
    }

    const grouped = await getGroupedDataForExport();
    const groups = orderGroupNames(grouped).filter(g => (grouped[g] || []).length > 0);

    if (!groups.length) {
      alert("Keine Daten für Excel gefunden.");
      return;
    }

    const wb = XLSX.utils.book_new();
    const usedSheetNames = new Set();

    for (const g of groups) {
      const rows = grouped[g].map((r, i) => ({
        Pos: i + 1,
        Sachnummer: r.Sachnummer ?? "",
        Lagergruppe: r.Lagergruppe ?? g,
        BRT_GEW: r.BRT_GEW ?? "",
        Behaelter_Nr: r.Behaelter_Nr ?? "",
        Zus_Behaelter: r.Zus_Behaelter ?? "",
        Geaendert: r.Geaendert ?? ""
      }));

      const ws = XLSX.utils.json_to_sheet(rows);
      ws["!cols"] = [
        { wch: 6 }, { wch: 24 }, { wch: 14 }, { wch: 12 },
        { wch: 16 }, { wch: 14 }, { wch: 20 }
      ];

      if (rows.length && ws["!ref"]) {
        const range = XLSX.utils.decode_range(ws["!ref"]);
        ws["!autofilter"] = { ref: XLSX.utils.encode_range(range) };
      }

      XLSX.utils.book_append_sheet(wb, ws, uniqueSheetName(g, usedSheetNames));
    }

    const d = new Date();
    const stamp =
      `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}_` +
      `${String(d.getHours()).padStart(2, "0")}${String(d.getMinutes()).padStart(2, "0")}`;

    XLSX.writeFile(wb, `Sachnummern_alle_Lager_${stamp}.xlsx`);
  }


  function buildPrintHtml(grouped, meta) {
    const groups = orderGroupNames(grouped);
    const total = groups.reduce((sum, g) => sum + (grouped[g]?.length || 0), 0);

    const summaryRows = groups.map(g => `
      <tr>
        <td>${escHtml(g)}</td>
        <td class="num">${grouped[g].length}</td>
      </tr>
    `).join("");

    const sectionHtml = groups.map((g) => {
      const rows = grouped[g];
      const body = rows.map((r, i) => `
        <tr>
          <td class="num">${i + 1}</td>
          <td>${escHtml(r.Sachnummer)}</td>
          <td>${escHtml(r.BRT_GEW)}</td>
          <td>${escHtml(r.Behaelter_Nr)}</td>
          <td class="num">${escHtml(r.Zus_Behaelter)}</td>
          <td>${escHtml(r.Geaendert)}</td>
        </tr>
      `).join("");

      return `
        <section class="page">
          <div class="print-header">
            <div><strong>Stammdaten Sachnummern</strong></div>
            <div>Benutzer: ${escHtml(meta.user)}</div>
            <div>Datum: ${escHtml(meta.now)}</div>
            <div>Datensätze gesamt: ${total}</div>
            <div>Lagergruppe: <strong>${escHtml(g)}</strong> (${rows.length})</div>
          </div>

          <table>
            <thead>
              <tr>
                <th style="width:55px;">Pos</th>
                <th>Sachnummer</th>
                <th style="width:110px;">BRT-GEW</th>
                <th style="width:140px;">Beh.-Nr.</th>
                <th style="width:110px;">Zus.-Beh.</th>
                <th style="width:165px;">Geändert</th>
              </tr>
            </thead>
            <tbody>${body || `<tr><td colspan="6">Keine Daten</td></tr>`}</tbody>
          </table>
        </section>
      `;
    }).join("");

    return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Druck – Sachnummern</title>
<style>
  @page { size: A4 portrait; margin: 10mm; }
  body { font-family: Arial, sans-serif; color:#111; font-size:12px; margin:0; }
  .page { page-break-after: always; }
  .cover { min-height: 260mm; }
  h1,h2,h3 { margin: 0 0 8px; }
  .muted { color:#555; }
  .meta { margin: 8px 0 16px; }
  table { width:100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border:1px solid #ccc; padding:6px 8px; vertical-align: top; }
  th { background:#f2f2f2; text-align:left; }
  .num { text-align:right; }
  .print-header { margin-bottom: 10px; line-height: 1.45; }
</style>
</head>
<body>

<section class="page cover">
  <h1>Stammdaten – Sachnummern</h1>
  <div class="meta">
    <div><strong>Benutzer:</strong> ${escHtml(meta.user)}</div>
    <div><strong>Datum:</strong> ${escHtml(meta.now)}</div>
    <div><strong>Datensätze gesamt:</strong> ${total}</div>
  </div>

  <h3>Gesamtanzahl je Lagergruppe</h3>
  <table>
    <thead>
      <tr><th>Lagergruppe</th><th style="width:120px;" class="num">Anzahl</th></tr>
    </thead>
    <tbody>${summaryRows || `<tr><td colspan="2">Keine Daten</td></tr>`}</tbody>
  </table>
</section>

${sectionHtml}

</body>
</html>`;
  }

  async function printWithCoverAndHeader() {
    // Popup direkt öffnen (sonst evtl. Blocker)
    const w = window.open("", "_blank");
    if (!w) {
      alert("Popup blockiert. Bitte Popups für diese Seite erlauben.");
      return;
    }

    try {
      const grouped = await getGroupedDataCurrentFilter();
      const hasData = Object.keys(grouped).some(g => grouped[g]?.length);
      if (!hasData) {
        w.document.write("<p>Keine Druckdaten vorhanden.</p>");
        w.document.close();
        return;
      }

      const meta = {
        user: getCurrentUserName(),
        now: deDateTime(new Date())
      };

      const html = buildPrintHtml(grouped, meta);
      w.document.open();
      w.document.write(html);
      w.document.close();

      // kurz warten bis gerendert
      setTimeout(() => {
        w.focus();
        w.print();
      }, 250);

    } catch (err) {
      console.error(err);
      w.document.open();
      w.document.write(`<p>Druckfehler: ${escHtml(err?.message || err)}</p>`);
      w.document.close();
      alert("Druckfehler: " + (err?.message || err));
    }
  }

  function bindButtons() {
  const btnPrint = document.getElementById("btnPrintSach");
  const btnExcel = document.getElementById("btnExcelSach");       // aktive Gruppe
  const btnExcelAll = document.getElementById("btnExcelAllSach"); // NEU

  if (btnPrint && !btnPrint.dataset.bound) {
    btnPrint.dataset.bound = "1";
    btnPrint.addEventListener("click", async (e) => {
      e.preventDefault();
      await printWithCoverAndHeader();
    });
  }

  if (btnExcel && !btnExcel.dataset.bound) {
    btnExcel.dataset.bound = "1";
    btnExcel.addEventListener("click", async (e) => {
      e.preventDefault();
      await exportExcelActiveGroup();
    });
  }

  if (btnExcelAll && !btnExcelAll.dataset.bound) {
    btnExcelAll.dataset.bound = "1";
    btnExcelAll.addEventListener("click", async (e) => {
      e.preventDefault();
      await exportExcelAllGroups();
    });
  }

  console.log("✅ Print/Excel erweitert gebunden");
}


  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindButtons, { once: true });
  } else {
    bindButtons();
  }
})();

function bindSmoothSachAccordion() {
  const acc = document.getElementById('accSach');
  if (!acc || acc.dataset.smoothBound === '1') return;
  acc.dataset.smoothBound = '1';

  acc.addEventListener('show.bs.collapse', (e) => {
    const col = e.target;
    col.style.willChange = 'height';
    col.closest('.accordion-item')?.classList.add('is-animating');
  });

  acc.addEventListener('shown.bs.collapse', (e) => {
    const col = e.target;
    col.style.willChange = 'auto';
    col.closest('.accordion-item')?.classList.remove('is-animating');
  });

  acc.addEventListener('hide.bs.collapse', (e) => {
    const col = e.target;
    col.style.willChange = 'height';
    col.closest('.accordion-item')?.classList.add('is-animating');
  });

  acc.addEventListener('hidden.bs.collapse', (e) => {
    const col = e.target;
    col.style.willChange = 'auto';
    col.closest('.accordion-item')?.classList.remove('is-animating');
  });
}
