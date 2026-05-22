(() => {
  const API = '/kommi/api/cmr_recipients_api.php?action=list';

  const $  = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  let RECIP = new Map(); // code -> recipient
  let CODES = [];

  const norm = (s) => String(s ?? '').trim();

  async function loadRecipients() {
    try {
      const res = await fetch(API, { credentials:'same-origin' });
      const j = await res.json();
      if (!j.ok) return;

      RECIP = new Map();
      (j.items || []).forEach(it => {
        const code = norm(it.code);
        if (!code) return;
        RECIP.set(code, {
          name: norm(it.name),
          address1: norm(it.address1),
          address2: norm(it.address2),
          postal: norm(it.postal),
          city: norm(it.city),
          country: norm(it.country),
          note: norm(it.note),
        });
      });

      CODES = [...RECIP.keys()].sort((a,b)=>a.localeCompare(b,'de',{numeric:true}));
    } catch {}
  }

  function buildTooltip(rec){
    const lines = [];
    if (rec.name) lines.push(rec.name);
    if (rec.address1) lines.push(rec.address1);
    if (rec.address2) lines.push(rec.address2);
    const cityLine = [rec.postal, rec.city].filter(Boolean).join(' ');
    if (cityLine) lines.push(cityLine);
    if (rec.country) lines.push(rec.country);
    if (rec.note) lines.push(rec.note);
    return lines.join('\n');
  }

  function getLagergruppe(tr){
    const td = tr?.children?.[2];
    return norm(td?.dataset?.raw || td?.textContent);
  }

  function ensureCells(tr){
    // Wenn schon da -> ok
    if (tr.querySelector('td[data-col="cmr_code"]') && tr.querySelector('td[data-col="cmr_name"]')) return true;

    // Aktion-Zelle MUSS die letzte sein (deine App geht davon aus)
    const actionTd = tr.lastElementChild;
    if (!actionTd) return false;

    const tdCode = document.createElement('td');
    tdCode.dataset.col = 'cmr_code';
    tdCode.dataset.raw = '';
    tdCode.textContent = '';

    const tdName = document.createElement('td');
    tdName.dataset.col = 'cmr_name';
    tdName.dataset.raw = '';
    tdName.textContent = '';

    // vor Aktion einfügen (Reihenfolge: ... | cmr_code | cmr_name | Aktion)
    tr.insertBefore(tdName, actionTd);
    tr.insertBefore(tdCode, tdName);
    return true;
  }

  function setText(td, val){
    td.dataset.raw = val;
    // WICHTIG: wenn ein SELECT drin ist, NICHT textContent setzen -> sonst wird SELECT gelöscht
    if (td.querySelector('select')) return;
    td.textContent = val;
  }

  function ensureSelect(tdCode, current){
    let sel = tdCode.querySelector('select');
    if (!sel) {
      sel = document.createElement('select');
      sel.className = 'form-select form-select-sm';

      sel.innerHTML =
        `<option value="">– wählen –</option>` +
        CODES.map(c => `<option value="${c}">${c}</option>`).join('');

      // Autosave blocken, damit das Select nicht sofort "weg-committed" wird
      const holdOn  = () => { const tr = sel.closest('tr'); if (tr) tr.dataset.waHold = '1'; };
      const holdOff = () => { const tr = sel.closest('tr'); if (tr) delete tr.dataset.waHold; };

      // so früh wie möglich setzen
      sel.addEventListener('pointerdown', holdOn, { capture:true });
      sel.addEventListener('mousedown', holdOn, { capture:true });

      sel.addEventListener('blur', holdOff);
      sel.addEventListener('change', () => {
        holdOff();

        const v = norm(sel.value);
        tdCode.dataset.raw = v;

        const tr = tdCode.closest('tr');
        const tdName = tr?.querySelector('td[data-col="cmr_name"]');

        const rec = RECIP.get(v);
        if (tdName) {
          tdName.dataset.raw = rec?.name || '';
          tdName.textContent = rec?.name || '';
          tdName.title = rec ? buildTooltip(rec) : '';
        }
      });

      tdCode.textContent = '';
      tdCode.appendChild(sel);
    }

    // IMMER nur value setzen, nicht tdCode.textContent anfassen!
    sel.value = current || '';
    tdCode.dataset.raw = sel.value || '';
  }

  function fillRow(tr){
    if (!ensureCells(tr)) return;

    const tdCode = tr.querySelector('td[data-col="cmr_code"]');
    const tdName = tr.querySelector('td[data-col="cmr_name"]');
    if (!tdCode || !tdName) return;

    let code = norm(tdCode.dataset.raw || tdCode.textContent);

    // Fallback nur wenn Lagergruppe wirklich ein Code ist
    if (!code) {
      const lg = getLagergruppe(tr);
      if (RECIP.has(lg)) code = lg;
    }
    if (code && !RECIP.has(code)) code = '';

    const rec = RECIP.get(code);

    if (tr.dataset.mode === 'edit') {
      // Edit: Dropdown zeigen/halten (ohne es jemals per textContent zu killen)
      ensureSelect(tdCode, code);

      // Name darf als Text
      tdName.dataset.raw = rec?.name || '';
      tdName.textContent = rec?.name || '';
      tdName.title = rec ? buildTooltip(rec) : '';
      return;
    }

    // View: Select ggf. entfernen und Text anzeigen
    const sel = tdCode.querySelector('select');
    if (sel) sel.remove();

    setText(tdCode, code);
    setText(tdName, rec?.name || '');
    tdName.title = rec ? buildTooltip(rec) : '';
  }

  function refreshAll(){
    const tbody = $('#ausgangTable tbody');
    if (!tbody) return;
    $$('tr', tbody).forEach(fillRow);
  }

  let t = null;
  function schedule(){
    clearTimeout(t);
    t = setTimeout(refreshAll, 80);
  }

  (async () => {
    await loadRecipients();
    refreshAll();

    const tbody = $('#ausgangTable tbody');
    if (tbody) new MutationObserver(schedule).observe(tbody, { childList:true, subtree:true });

    // optional: Stammdaten nachladen, ohne UI kaputt zu machen
    setInterval(async () => {
      await loadRecipients();
      refreshAll();
    }, 60000);
  })();
})();
