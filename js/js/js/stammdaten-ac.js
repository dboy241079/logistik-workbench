// /LKW/js/stammdaten-ac.js
(function () {
  const API   = '/LKW/api/stammdaten_api.php';
  const TTLMS = 24 * 60 * 60 * 1000; // 24h Cache

  // ========= Cache Helpers ===================================================
  const K = (t) => `stmd_v2_${t}`;
  const read = (t) => {
    try {
      const x = JSON.parse(localStorage.getItem(K(t)) || 'null');
      if (!x || !Array.isArray(x.items) || (Date.now() - x.ts) > TTLMS) return null;
      return x.items;
    } catch { return null; }
  };
  const write = (t, items) => {
    try { localStorage.setItem(K(t), JSON.stringify({ ts: Date.now(), items })); } catch {}
  };

  async function fetchList(type) {
    const url = `${API}?type=${encodeURIComponent(type)}&action=list`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) return [];
    return j.items || [];
  }

  // ========= Stammdaten in Memory ============================================
  let SPEDS = [], BEHS = [], PARTS = [];
  // Flache Kennzeichen-Liste über alle Speditionen: [{ plate, norm, spedition, spedLower }]
  let PLATES = [];
  let _loadP = null;

  const normalize = (s) => String(s || '').toLowerCase().replace(/\s+/g, '');
  const lower     = (s) => String(s || '').toLowerCase();
  const normPlate = (s) => String(s || '').toUpperCase().replace(/[\s\-_.\/]/g, '');

  function parsePlates(v) {
    return String(v || '')
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);
  }

  function buildPlatesIndex() {
    const out = [];
    for (const s of SPEDS) {
      const spedLower = s.name.toLowerCase();
      for (const p of (s.plates || [])) {
        const plate = p.toUpperCase();
        out.push({ plate, norm: normPlate(plate), spedition: s.name, spedLower });
      }
    }
    return out;
  }

  async function ensureLoaded() {
    if (_loadP) return _loadP;
    _loadP = (async () => {
      // 1) Cache
      SPEDS = read('spedition') || [];
      BEHS  = read('behaelter') || [];
      PARTS = read('sachnummer') || [];

      // 2) Frisch laden (parallel); bei Fehler weiter mit Cache
      try {
        const [sp, bh, pa] = await Promise.all([
          fetchList('spedition'),
          fetchList('behaelter'),
          fetchList('sachnummer')
        ]);

        SPEDS = sp.map(it => ({
          name:   (it.name || '').trim(),
          plates: parsePlates(it.plates),     // CSV „AA-AB 123, XY-AB 456“
          norm:   lower(it.name || '')
        })).filter(s => s.name);

        BEHS = bh.map(it => ({
          nummer:      (it.nummer || '').trim(),
          lagergruppe: (it.lagergruppe || '').trim(),
          norm:        normalize(it.nummer)
        })).filter(b => b.nummer);

        PARTS = pa.map(it => ({
          sachnummer:  (it.sachnummer || '').trim(),
          lagergruppe: (it.lagergruppe || '').trim(),
          norm:        normalize(it.sachnummer)
        })).filter(p => p.sachnummer);

        write('spedition',  SPEDS);
        write('behaelter',  BEHS);
        write('sachnummer', PARTS);
      } catch (e) {
        console.warn('stammdaten-ac: Laden teilweise fehlgeschlagen, nutze ggf. Cache', e);
      }

      // 3) Indexe ableiten
      PLATES = buildPlatesIndex();
    })();
    return _loadP;
  }

  // ========= Datalist Utilities ==============================================
  function ensureDatalist(id) {
    let dl = document.getElementById(id);
    if (!dl) { dl = document.createElement('datalist'); dl.id = id; document.body.appendChild(dl); }
    return dl;
  }
  function fillDatalist(dl, values) {
    dl.innerHTML = values.map(v => `<option value="${escapeHtml(v)}">`).join('');
  }
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    }[m]));
  }

  // ========= Helpers: Kennzeichen-Suche/Status ===============================
  function uniqueByPlate(arr) {
    const seen = new Set();
    const out = [];
    for (const it of arr) {
      if (seen.has(it.plate)) continue;
      seen.add(it.plate);
      out.push(it);
    }
    return out;
  }

  function matchPlates({ q = '', spedLower = '' }) {
    const base = spedLower ? PLATES.filter(p => p.spedLower === spedLower) : PLATES;
    if (!q) return uniqueByPlate(base).slice(0, 100);
    const nq = normPlate(q);
    const qLower = q.toLowerCase();
    const hits = base.filter(p =>
      p.norm.startsWith(nq) || p.plate.toLowerCase().includes(qLower)
    );
    return uniqueByPlate(hits).slice(0, 100);
  }

  function findPlateStatus(value, spedLower = '') {
    const nq = normPlate(value);
    const base = spedLower ? PLATES.filter(p => p.spedLower === spedLower) : PLATES;
    const hits = base.filter(p => p.norm === nq);
    if (hits.length) {
      const speds = Array.from(new Set(hits.map(h => h.spedition))).sort((a,b)=>a.localeCompare(b,'de'));
      return { known: true, speds };
    }
    // Falls innerhalb Spedition nicht gefunden, aber global vorhanden → Info mit allen Speditionen
    const globalHits = PLATES.filter(p => p.norm === nq);
    if (globalHits.length) {
      const speds = Array.from(new Set(globalHits.map(h => h.spedition))).sort((a,b)=>a.localeCompare(b,'de'));
      return { known: true, speds };
    }
    return { known: false, speds: [] };
  }

  function applyKnownStyle(inputEl, status) {
    const val = (inputEl.value || '').trim();
    const showInvalid = val.length >= 2 && !status.known;
    // Bootstrap 5 Validierungs-Klassen
    inputEl.classList.toggle('is-valid',  status.known);
    inputEl.classList.toggle('is-invalid', showInvalid);
    if (!val) {
      inputEl.classList.remove('is-valid', 'is-invalid');
      inputEl.removeAttribute('title');
      return;
    }
    if (status.known) {
      inputEl.title = status.speds.length
        ? `Kennzeichen gespeichert (Spedition: ${status.speds.join(', ')})`
        : 'Kennzeichen gespeichert';
    } else {
      inputEl.title = 'Kennzeichen nicht in den Stammdaten gefunden';
    }
  }

  // ========= Attacher: Spedition =============================================
  function attachSpedition(inputEl, { onPick } = {}) {
    const DL_ID = 'dl-speditionen';
    const dl = ensureDatalist(DL_ID);
    inputEl.setAttribute('list', DL_ID);

    ensureLoaded().then(() => {
      fillDatalist(dl, SPEDS.map(s => s.name));
    });

    const fire = () => onPick?.(inputEl.value);
    inputEl.addEventListener('change', fire);
    inputEl.addEventListener('input',  fire);
  }

  // ========= Attacher: Kennzeichen (LIVE + Auto-Spedition) ===================
  /**
   * @param {HTMLInputElement} inputEl  Kennzeichen-Input
   * @param {Function} getCurrentSpedition  () => string (aktueller Speditionsname)
   * @param {Function} setSpeditionName     (name:string) => void (Sped.-Feld setzen)
   * Zeigt sofort Vorschläge und Validierung beim Tippen.
   * - Filtert nach aktueller Spedition (falls angegeben), sonst global.
   * - Setzt Spedition automatisch, wenn:
   *     a) Kennzeichen eindeutig nur einer Spedition zugeordnet ist, oder
   *     b) es zu der bereits ausgewählten Spedition gehört.
   */
  function attachKennzeichen(inputEl, getCurrentSpedition, setSpeditionName) {
    const DL_ID = 'dl-kennzeichen';
    const dl = ensureDatalist(DL_ID);
    inputEl.setAttribute('list', DL_ID);

    // Eingabe immer groß
    inputEl.addEventListener('input', () => { inputEl.value = inputEl.value.toUpperCase(); });

    function currentSpedLower() {
      return (getCurrentSpedition?.() || '').trim().toLowerCase();
    }

    async function refreshSuggestions() {
      await ensureLoaded();
      const spedLower = currentSpedLower();
      const q = inputEl.value || '';
      const matches = matchPlates({ q, spedLower });
      fillDatalist(dl, matches.map(m => m.plate));
    }

    function refreshKnownState() {
      const spedLower = currentSpedLower();
      const status = findPlateStatus(inputEl.value || '', spedLower);
      applyKnownStyle(inputEl, status);
    }

    // Versucht, anhand des aktuellen Kennzeichens die Spedition zu setzen.
    function maybeAutofillSpedition() {
      const val = (inputEl.value || '').trim();
      if (!val) return;

      const nq = normPlate(val);
      const curSpedLower = currentSpedLower();

      // Alle Speditionen, die dieses Kennzeichen führen
      const globalHits = PLATES.filter(p => p.norm === nq);
      if (!globalHits.length) return;

      // 1) Falls aktuelle Spedition gesetzt & passend → nichts ändern (ist korrekt)
      if (curSpedLower) {
        const matchCur = globalHits.find(h => h.spedLower === curSpedLower);
        if (matchCur) return; // passt bereits
      }

      // 2) Eindeutig? → diese Spedition setzen
      const uniqueSpeds = Array.from(new Set(globalHits.map(h => h.spedition)));
      if (uniqueSpeds.length === 1 && typeof setSpeditionName === 'function') {
        setSpeditionName(uniqueSpeds[0]);
        return;
      }

      // 3) Mehrdeutig und noch keine Spedition gewählt → pragmatisch erste setzen
      if (!curSpedLower && uniqueSpeds.length > 1 && typeof setSpeditionName === 'function') {
        setSpeditionName(uniqueSpeds[0]); // optional: UI-Hinweis wäre möglich
      }
    }

    async function refreshAll() {
      await refreshSuggestions();
      refreshKnownState();
    }

    // Live bei Fokus / Tippen / Verlassen prüfen & Datalist füllen
    inputEl.addEventListener('focus', () => { refreshAll(); });
    inputEl.addEventListener('input', () => { refreshAll(); });
    inputEl.addEventListener('change', () => { refreshAll(); maybeAutofillSpedition(); });
    inputEl.addEventListener('blur',  () => { refreshKnownState(); maybeAutofillSpedition(); });

    // public API: nur für Konsistenz mit alter Signatur
    return { refresh: refreshAll };
  }

  // ========= Attacher: Behälter ==============================================
  function attachBehaelter(inputEl, { onPick, onChange } = {}) {
    const DL_ID = 'dl-behaelter';
    const dl = ensureDatalist(DL_ID);
    inputEl.setAttribute('list', DL_ID);

    ensureLoaded().then(() => fillDatalist(dl, BEHS.map(b => b.nummer)));

    function handle() {
      const val = inputEl.value.trim();
      const hit = BEHS.find(b => b.norm === normalize(val)) || null;
      onPick?.(hit);
      onChange?.(val);
    }
    inputEl.addEventListener('change', handle);
    inputEl.addEventListener('input',  () => onChange?.(inputEl.value));
  }

  // ========= Attacher: Sachnummer ============================================
  function attachSachnummer(inputEl, { onPick, onChange } = {}) {
    const DL_ID = 'dl-sachnummern';
    const dl = ensureDatalist(DL_ID);
    inputEl.setAttribute('list', DL_ID);

    ensureLoaded().then(() => fillDatalist(dl, PARTS.map(p => p.sachnummer)));

    function handle() {
      const val = inputEl.value.trim();
      const hit = PARTS.find(p => p.norm === normalize(val)) || null;
      onPick?.(hit);
      onChange?.(val);
    }
    inputEl.addEventListener('change', handle);
    inputEl.addEventListener('input',  () => onChange?.(inputEl.value));
  }

  // ========= Helper: LG setzen (Edit- & View-Modus) ===========================
  function setLGCell(tr, colLG, val) {
    const td = tr?.children?.[colLG];
    if (!td) return;
    const span = td.querySelector('.form-control-plaintext');
    if (span) span.textContent = val || '';
    else {
      td.dataset.raw = val || '';
      td.textContent = val || '';
    }
  }

  // ========= Binder für eine Tabellenzeile ===================================
  /**
   * Standardspalten (WE/WA identisch):
   *   colSpedition=6, colKennzeichen=4, colBehaelter=12, colSachnummer=13, colLG=2
   */
  function bindRowAC(tr, opts = {}) {
    const colSped = opts.colSpedition   ?? 6;
    const colKZ   = opts.colKennzeichen ?? 4;
    const colBeh  = opts.colBehaelter   ?? 12;
    const colSN   = opts.colSachnummer  ?? 13;
    const colLG   = opts.colLG          ?? 2;

    const spedInp = tr.children[colSped]?.querySelector('input');
    const kzInp   = tr.children[colKZ]?.querySelector('input');
    const behInp  = tr.children[colBeh]?.querySelector('input');
    const snInp   = tr.children[colSN]?.querySelector('input');

    // Spedition -> Kennzeichen-Liste abhängig
    let kzBinder = null;
    if (spedInp) {
      attachSpedition(spedInp, {
        onPick: () => kzBinder?.refresh?.()
      });
    }
    if (kzInp) {
      kzBinder = attachKennzeichen(
        kzInp,
        () => spedInp?.value || '',               // Getter für aktuelle Spedition
        (name) => {                               // Setter zum Auto-Füllen
          if (!spedInp || !name) return;
          if (spedInp.value !== name) {
            spedInp.value = name;
            // Events feuern, damit Datalist/Badges etc. reagieren
            spedInp.dispatchEvent(new Event('input',  { bubbles:true }));
            spedInp.dispatchEvent(new Event('change', { bubbles:true }));
          }
        }
      );
    }

    // Behälter: Lagergruppe aus Stammdaten setzen
    if (behInp) {
      attachBehaelter(behInp, {
        onPick: (hit) => {
          if (hit) setLGCell(tr, colLG, hit.lagergruppe || '');
        }
      });
    }

    // Sachnummer: Lagergruppe setzen (Vorrang)
    if (snInp) {
      attachSachnummer(snInp, {
        onPick: (hit) => {
          if (hit) setLGCell(tr, colLG, hit.lagergruppe || '');
        }
      });
    }
  }

  // ========= Auto-Binder für Wareneingang & -ausgang =========================
  function autoBindForTable(tableId, opts = {}) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    function tryBindRow(tr) {
      if (!tr || tr.dataset._acBound === '1') return;
      if (tr.dataset.mode === 'edit') {
        bindRowAC(tr, opts);
        tr.dataset._acBound = '1';
      }
    }

    // Klick auf „Bearbeiten“
    tbody.addEventListener('click', (ev) => {
      const btn = ev.target.closest('button.btn-outline-secondary');
      if (!btn) return;
      const tr = btn.closest('tr');
      if (!tr) return;
      setTimeout(() => tryBindRow(tr), 0);
    });

    // MutationObserver: neue Zeilen / Moduswechsel
    const mo = new MutationObserver((muts) => {
      muts.forEach(m => {
        m.addedNodes.forEach(n => {
          if (n.nodeType === 1 && n.matches('tr')) {
            setTimeout(() => tryBindRow(n), 0);
          }
        });
        if (m.type === 'attributes' && m.attributeName === 'data-mode') {
          const tr = m.target;
          setTimeout(() => tryBindRow(tr), 0);
        }
      });
    });
    mo.observe(tbody, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-mode'] });

    // bestehende Edit-Zeilen initial
    [...tbody.querySelectorAll('tr')].forEach(tr => tryBindRow(tr));
  }

  function autoBindForTables() {
    autoBindForTable('eingangTable');
    autoBindForTable('ausgangTable');
  }

  // ========= Public API =======================================================
  window.StammdatenAC = {
    ensureLoaded,
    bindRowAC,
    autoBindForTables,
    _attach: { attachSpedition, attachKennzeichen, attachBehaelter, attachSachnummer }
  };

  // ========= Auto-Init nach DOM-Fertig =======================================
  document.addEventListener('DOMContentLoaded', () => {
    try { autoBindForTables(); } catch (e) { console.warn('stammdaten-ac autoBind warn', e); }
  });
})();
