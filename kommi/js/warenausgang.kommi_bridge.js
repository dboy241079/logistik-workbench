(() => {
  const API = '/kommi/api/create_from_ausgang.php';

  function showCenterMessage(title, msg, type='danger') {
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

  function getAusgangNr(tr){
    const td = tr?.children?.[0];
    return (td?.dataset?.raw || td?.textContent || '').trim();
  }

  async function callCreate(ausgangNr){
    const url = API + '?ausgang_nr=' + encodeURIComponent(ausgangNr);
    const res = await fetch(url, { credentials:'same-origin' });
    const ct  = res.headers.get('content-type') || '';
    const raw = await res.text();

    if (!ct.includes('application/json')) {
      console.error('Kommi API kein JSON:', ct, raw);
      throw new Error('API lieferte kein JSON. Siehe Console.');
    }

    let j;
    try { j = JSON.parse(raw); }
    catch {
      console.error('Kommi API JSON-Parse Error:', raw);
      throw new Error('Antwort ist kein gültiges JSON. Siehe Console.');
    }

    if (!j.ok) throw new Error(j.error || 'create failed');
    return j;
  }

  function openCheckin(orderId){
    window.open('/kommi/checkin.php?order_id=' + encodeURIComponent(orderId) + '&embed=1', '_blank');
  }

  function ensureBtn(tr){
    const actionTd = tr?.lastElementChild;
    if (!actionTd) return;
    if (actionTd.querySelector('.btn-kommi')) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-success btn-sm action-btn me-1 btn-kommi';
    btn.dataset.role = 'group';
    btn.textContent = '📋 Auftrag';
    btn.title = 'Kommi-Auftrag erstellen & Check-in starten';

    btn.addEventListener('click', async () => {
      const row = btn.closest('tr');
      const ausgangNr = getAusgangNr(row);

      if (!ausgangNr) {
        showCenterMessage('Fehler', 'Keine Ausgangsnummer in der Zeile gefunden.', 'warning');
        return;
      }

      btn.disabled = true;
      try {
        const j = await callCreate(ausgangNr);
        openCheckin(j.order_id); // WICHTIG: nicht direkt board.php öffnen
      } catch (err) {
        showCenterMessage('Auftrag konnte nicht erstellt werden', err.message, 'danger');
      } finally {
        btn.disabled = false;
      }
    });

    actionTd.prepend(btn);
  }

  function scan(){
    document.querySelectorAll('#ausgangTable tbody tr').forEach(ensureBtn);
  }

  scan();
  const tb = document.querySelector('#ausgangTable tbody');
  if (tb) new MutationObserver(scan).observe(tb, { childList:true, subtree:true });
})();
