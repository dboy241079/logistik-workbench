// =================== Lager Bestand (LG-Filter) ===================
(() => {
  const API_SUMMARY = '/api/lagerbestand_summary.php';
  const API_EXPORT  = '/api/lagerbestand_export.php';

  const boxChecks = () => Array.from(document.querySelectorAll('.lg-check'));

  const badge    = document.getElementById('lgActiveBadge');
  const table    = document.getElementById('lgSummaryTable');
  const tbody    = document.querySelector('#lgSummaryTable tbody');
  const totalBox = document.getElementById('lgSummaryTotal');

  const btnAll    = document.getElementById('btnLgAll');
  const btnNone   = document.getElementById('btnLgNone');
  const btnExport = document.getElementById('btnLgExport');
  const lkwMain = document.getElementById('lgLkwMain');
  const lkwSub  = document.getElementById('lgLkwSub');


  // Pflicht-Elemente prüfen
  if (!table || !tbody || !badge || !totalBox || !btnAll || !btnNone || !btnExport) return;

  // Dynamisch aus Tabelle lesen, damit colspan immer korrekt ist
  const getColCount = () => {
    const n = table.querySelectorAll('thead th').length;
    return n > 0 ? n : 5;
  };

  function renderRowMessage(text, cls = 'text-muted') {
    tbody.innerHTML = `<tr><td colspan="${getColCount()}" class="${cls}">${text}</td></tr>`;
  }

  function selectedLGs() {
    // leer = "alle"
    return boxChecks().filter(c => c.checked).map(c => c.value);
  }
  function calcLkw(done, capacity) {
  const d = Math.max(0, Number(done || 0));
  return {
    done: d,
    full: Math.floor(d / capacity),
    rest: d % capacity
  };
}

function renderLkwInfo(js) {
  if (!lkwMain || !lkwSub) return;

  // NEU: primär neues Backend-Schema nutzen
  const src = js?.lkw_totals ?? js?.lkw ?? {};

  const gtDone = Number(src.gt_count ?? src.gt_done ?? 0);
  const vwDone = Number(src.vw_count ?? src.vw_done ?? 0);

  const gtFull = Number(src.gt_full ?? Math.floor(gtDone / 52));
  const gtRest = Number(src.gt_rest ?? (gtDone % 52));

  const vwFull = Number(src.vw_full ?? Math.floor(vwDone / 78));
  const vwRest = Number(src.vw_rest ?? (vwDone % 78));

  // Wichtig: "Erledigt gesamt" aus done_total (alle Verpackungen), nicht nur GT/VW
  const doneTotalAll = Number(src.done_total ?? (gtDone + vwDone));
  const openTotal = Number(src.open_total ?? 0);

  const fullTotal = Number(src.full_total ?? (gtFull + vwFull));
  const lkwRelevantDone = gtDone + vwDone;

  lkwMain.textContent =
    `Erledigt gesamt: ${fmtInt(doneTotalAll)} Paletten → ${fmtInt(fullTotal)} volle LKW`;

  lkwSub.textContent =
    `GT14488/GT14491: ${fmtInt(gtDone)} → ${fmtInt(gtFull)} LKW` +
    `${gtRest ? ` + ${fmtInt(gtRest)} einzelne` : ''}` +
    ` · VW0012/114003: ${fmtInt(vwDone)} → ${fmtInt(vwFull)} LKW` +
    `${vwRest ? ` + ${fmtInt(vwRest)} einzelne` : ''}` +
    ` · LKW-relevant gesamt: ${fmtInt(lkwRelevantDone)}` +
    ` · Offen: ${fmtInt(openTotal)}`;
}



  function updateBadge() {
    const sel = selectedLGs();
    if (sel.length === 0) {
      badge.textContent = 'Aktiv: alle';
      badge.className = 'badge text-bg-secondary align-self-start';
    } else {
      badge.textContent = 'Aktiv: ' + sel.join(', ');
      badge.className = 'badge text-bg-primary align-self-start';
    }
  }

  function fmtInt(n) {
    const x = Number(n || 0);
    return x.toLocaleString('de-DE');
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function loadSummary() {
    updateBadge();

    const sel = selectedLGs();
    const qs = new URLSearchParams();
    if (sel.length) qs.set('lg', sel.join(','));

    renderRowMessage('Lade…');

    const res = await fetch(API_SUMMARY + '?' + qs.toString(), { credentials: 'same-origin' });
    if (!res.ok) throw new Error('summary_http_' + res.status);

    const js = await res.json();
    renderLkwInfo(js);


    // Rows rendern
    const rows = Array.isArray(js.rows) ? js.rows : [];
    if (!rows.length) {
      renderRowMessage('Keine Daten.');
    } else {
      // Prüfen, ob LKW-Spalte im Header vorhanden ist (empfohlen: <th data-col="lkw">...)
      const hasLkwCol =
        !!table.querySelector('thead th[data-col="lkw"]') ||
        getColCount() >= 6;

      tbody.innerHTML = rows.map(r => `
        <tr>
          <td class="fw-semibold">${escapeHtml(r.lg)}</td>
          <td class="text-end">${fmtInt(r.pallets)}</td>
          <td class="text-end">${fmtInt(r.pieces)}</td>
          <td class="text-end">${fmtInt(r.sachnr)}</td>
          <td class="text-end">${escapeHtml(r.verpackung ?? '—')}</td>
          ${hasLkwCol ? `<td class="text-end">${escapeHtml(r.lkw_text ?? '—')}</td>` : ''}
        </tr>
      `).join('');
    }

    // Totals
    const t  = js.totals || {};
    const tf = js.filtered || {};

    // Wenn Filter aktiv ist, beide anzeigen
    totalBox.innerHTML = `
      <div><b>Gesamt:</b> Paletten ${fmtInt(t.pallets)} · Stück ${fmtInt(t.pieces)} · Sachnr ${fmtInt(t.sachnr)}</div>
      <div class="text-muted">Gefiltert: Paletten ${fmtInt(tf.pallets)} · Stück ${fmtInt(tf.pieces)} · Sachnr ${fmtInt(tf.sachnr)}</div>
    `;
  }

  function exportCsv() {
    const sel = selectedLGs();
    const qs = new URLSearchParams();
    qs.set('type', 'summary');
    if (sel.length) qs.set('lg', sel.join(','));
    window.location.href = API_EXPORT + '?' + qs.toString();
  }

  function safeReload() {
    loadSummary().catch(() => {
      renderRowMessage('Fehler beim Laden.', 'text-danger');
      
    });
  }

  // Events
  boxChecks().forEach(c => c.addEventListener('change', safeReload));

  btnAll.addEventListener('click', () => {
    boxChecks().forEach(c => (c.checked = true));
    safeReload();
  });

  btnNone.addEventListener('click', () => {
    boxChecks().forEach(c => (c.checked = false));
    safeReload();
  });

  btnExport.addEventListener('click', exportCsv);

  // Initial
  safeReload();
})();
