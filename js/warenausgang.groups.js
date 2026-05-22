(function () {
  const tbody = window.WA_tbody || document.querySelector('#ausgangTable tbody');
  const filterSelect = window.WA_filterSelect || document.getElementById('filterNumber');
  const searchInput = window.WA_searchInput || document.getElementById('searchInput');
  const searchField = window.WA_searchField || document.getElementById('searchField');
  const btnResetFilter = window.WA_btnResetFilter || document.getElementById('btnResetFilter');

  if (!tbody) return;

  const HEADER_COLS = [3, 4, 5, 6, 7, 8, 9];
  const SEARCH_FIELDS = {
    ausgang: 0,
    lieferschein: 1,
    spedition: 6,
    sachnummer: 10,
    all: -1
  };

  function getCellValue(tr, idx) {
    if (window.WA_getCellValue) return window.WA_getCellValue(tr, idx);

    const cell = tr.children[idx];
    if (!cell) return "";
    const sel = cell.querySelector("select");
    if (sel) return sel.options[sel.selectedIndex]?.text?.trim() || "";
    const inp = cell.querySelector("input");
    if (inp) return (inp.value || "").trim();
    if (typeof cell.dataset?.raw !== "undefined") return String(cell.dataset.raw).trim();
    return (cell.textContent || "").trim();
  }

  function setCellValue(td, value) {
    value = String(value ?? '').trim();

    const inp = td.querySelector('input');
    if (inp) {
      inp.value = value;
      td.dataset.raw = value;
      return;
    }

    const sel = td.querySelector('select');
    if (sel) {
      const found = [...sel.options].find(o => o.text.trim() === value || o.value.trim() === value);
      if (found) sel.value = found.value;
      td.dataset.raw = value;
      return;
    }

    if (window.WA_setCellRaw) {
      window.WA_setCellRaw(td, value);
      return;
    }

    td.dataset.raw = value;
    td.textContent = value;
  }

  function groupRows() {
    const rows = [...tbody.querySelectorAll('tr')];
    const groups = [];
    let i = 0;

    while (i < rows.length) {
      const nr = (getCellValue(rows[i], 0) || '').trim();
      let j = i + 1;

      while (j < rows.length && (getCellValue(rows[j], 0) || '').trim() === nr) {
        j++;
      }

      groups.push({
        nr,
        rows: rows.slice(i, j)
      });

      i = j;
    }

    return groups;
  }

  function getLeadRowFromGroup(group) {
    return group?.rows?.[0] || null;
  }

  function getEffectiveCellValue(tr, colIdx) {
    if (!HEADER_COLS.includes(Number(colIdx))) {
      return getCellValue(tr, colIdx);
    }

    const nr = (getCellValue(tr, 0) || '').trim();
    if (!nr) return getCellValue(tr, colIdx);

    const lead = [...tbody.querySelectorAll('tr')].find(r => (getCellValue(r, 0) || '').trim() === nr);
    return lead ? (getCellValue(lead, colIdx) || '').trim() : getCellValue(tr, colIdx);
  }

  function normalizeGroupHeaderRows() {
    const groups = groupRows();

    groups.forEach(group => {
      if (!group.nr || !group.rows.length) return;

      const first = getLeadRowFromGroup(group);

      HEADER_COLS.forEach(col => {
        let value = (getCellValue(first, col) || '').trim();

        if (!value) {
          for (const tr of group.rows.slice(1)) {
            value = (getCellValue(tr, col) || '').trim();
            if (value) break;
          }
        }

        const firstTd = first.children[col];
        if (firstTd) setCellValue(firstTd, value || '');
      });

      group.rows.slice(1).forEach(tr => {
        HEADER_COLS.forEach(col => {
          const td = tr.children[col];
          if (td) setCellValue(td, '');
        });
      });
    });
  }

  function lockSubrowHeaderEditors() {
    const groups = groupRows();

    groups.forEach(group => {
      group.rows.forEach((tr, rowIdx) => {
        HEADER_COLS.forEach(col => {
          const td = tr.children[col];
          if (!td) return;

          const inp = td.querySelector('input');
          const sel = td.querySelector('select');
          const editor = inp || sel;

          td.classList.remove('wa-head-locked');

          if (!editor) return;

          if (rowIdx === 0) {
            editor.disabled = false;
            editor.readOnly = false;
            editor.title = '';
          } else {
            editor.value = '';
            editor.disabled = true;
            editor.readOnly = true;
            editor.title = 'Wird aus der ersten Zeile der Ausgangsnummer übernommen.';
            td.classList.add('wa-head-locked');
          }
        });
      });
    });
  }

  function groupMatchesSearch(group, needle, fieldKey) {
    if (!needle) return true;

    const terms = needle.toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length) return true;

    const containsAll = (val) => {
      const v = String(val || '').toLowerCase();
      return terms.every(t => v.includes(t));
    };

    const lead = getLeadRowFromGroup(group);
    if (!lead) return false;

    if (fieldKey === 'ausgang') {
      return containsAll(group.nr);
    }

    if (fieldKey === 'lieferschein') {
      return group.rows.some(tr => containsAll(getCellValue(tr, 1)));
    }

    if (fieldKey === 'spedition') {
      return containsAll(getEffectiveCellValue(lead, 6));
    }

    if (fieldKey === 'sachnummer') {
      return group.rows.some(tr => containsAll(getCellValue(tr, 10)));
    }

    if (fieldKey === 'all') {
      const parts = [];

      parts.push(group.nr);

      HEADER_COLS.forEach(col => {
        parts.push(getEffectiveCellValue(lead, col));
      });

      group.rows.forEach(tr => {
        for (let col = 1; col <= 16; col++) {
          if (HEADER_COLS.includes(col)) continue;
          parts.push(getCellValue(tr, col));
        }
      });

      return containsAll(parts.join(' '));
    }

    return true;
  }

  function applyGroupedFilter() {
    const selected = filterSelect?.value || '';
    const query = (searchInput?.value || '').trim();
    const fieldKey = searchField?.value || 'ausgang';
    const activeMonth = window.WA_activeMonth || null;

    const groups = groupRows();

    groups.forEach(group => {
      const lead = getLeadRowFromGroup(group);
      const rowMonth = lead?.dataset?.waMonth || '';

      const matchDropdown = !selected || group.nr === selected;
      const matchMonth = !activeMonth || !rowMonth || rowMonth === activeMonth;
      const matchSearch = groupMatchesSearch(group, query, fieldKey);

      const visible = matchDropdown && matchMonth && matchSearch;

      group.rows.forEach(tr => {
        tr.style.display = visible ? '' : 'none';
      });
    });

    window.WA_regroupGroups?.();
    window.WA_computeStats?.();
    window.WA_refreshAttachmentBadges?.();
  }

  let filterTimer = null;
  function scheduleGroupedFilter() {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(applyGroupedFilter, 80);
  }

  let syncTimer = null;

function scheduleSync(rebuildMonth = false) {
  clearTimeout(syncTimer);
  syncTimer = setTimeout(() => {
    normalizeGroupHeaderRows();
    lockSubrowHeaderEditors();
    window.WA_syncAllRowMonthMeta?.();

    if (rebuildMonth) {
      window.WA_rebuildMonthAccordion?.();
    }

    applyGroupedFilter();
    window.WA_rebuildFilterOptions?.();
  }, 60);
}
  function handoverHeaderIfLeadRowChanged(tr) {
  const HEADER_COLS = [3, 4, 5, 6, 7, 8, 9];

  const oldNr = (tr.dataset.originalAusgangNr || '').trim();
  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();

  if (!oldNr || !newNr || oldNr === newNr) return null;

  const rows = [...tbody.querySelectorAll('tr')];

  // War die bearbeitete Zeile vorher die Führungszeile der alten Gruppe?
  const oldGroupRows = rows.filter(r => (window.WA_getCellValue?.(r, 0) || '').trim() === oldNr);
  const oldLead = oldGroupRows[0];

  if (!oldLead || oldLead !== tr) return null;

  // Welche Zeilen bleiben in der alten Gruppe?
  const remainingOldGroupRows = rows.filter(r => {
    if (r === tr) return false;
    return (window.WA_getCellValue?.(r, 0) || '').trim() === oldNr;
  });

  const newLeadOldGroup = remainingOldGroupRows[0] || null;
  if (!newLeadOldGroup) return null;

  // Kopfwerte aus der bisherigen Führungszeile auf neue Führungszeile übertragen
  HEADER_COLS.forEach(col => {
    const val = (window.WA_getCellValue?.(tr, col) || '').trim();
    const td = newLeadOldGroup.children[col];
    if (!td) return;

    if (window.WA_setCellRaw) {
      window.WA_setCellRaw(td, val);
    } else {
      td.dataset.raw = val;
      td.textContent = val;
    }
  });

  return newLeadOldGroup;
}

async function persistLeadRowFor(tr) {
  const newLeadOldGroup = handoverHeaderIfLeadRowChanged(tr);

  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();

  // neue Gruppe speichern
  if (newNr && window.WA_upsertRowToServer) {
    const newLead = [...tbody.querySelectorAll('tr')]
      .find(r => (window.WA_getCellValue?.(r, 0) || '').trim() === newNr);

    if (newLead) {
      try {
        await window.WA_upsertRowToServer(newLead);
        newLead.dataset.saved = '1';
      } catch (e) {
        console.warn('Neue Führungszeile Save fehlgeschlagen', e);
      }
    }
  }

  // alte Gruppe speichern, falls Führungsrolle übergeben wurde
  if (newLeadOldGroup && window.WA_upsertRowToServer) {
    try {
      await window.WA_upsertRowToServer(newLeadOldGroup);
      newLeadOldGroup.dataset.saved = '1';
    } catch (e) {
      console.warn('Alte Gruppe Handover Save fehlgeschlagen', e);
    }
  }

  // danach UI sauber neu synchronisieren
  normalizeGroupHeaderRows();
  lockSubrowHeaderEditors();
  window.WA_syncAllRowMonthMeta?.();
  window.WA_rebuildMonthAccordion?.();
  window.WA_rebuildFilterOptions?.();
  window.WA_applyFilters?.();
}

  function installStyle() {
  if (document.getElementById('wa-groups-style')) return;

  const style = document.createElement('style');
  style.id = 'wa-groups-style';
  style.textContent = `
    #ausgangTable td.wa-head-locked input,
    #ausgangTable td.wa-head-locked select {
      background: #f1f3f5 !important;
      color: #6c757d !important;
      cursor: not-allowed;
    }

    #ausgangTable tr.wa-row-moved {
      animation: waRowMovedFlash .55s ease;
    }

    @keyframes waRowMovedFlash {
      0%   { background-color: rgba(13, 110, 253, 0.14); }
      100% { background-color: transparent; }
    }
  `;
  document.head.appendChild(style);
}

  function installEventHooks() {
    filterSelect?.addEventListener('change', scheduleGroupedFilter);
    searchField?.addEventListener('change', scheduleGroupedFilter);
    searchInput?.addEventListener('input', scheduleGroupedFilter);
    btnResetFilter?.addEventListener('click', () => {
      setTimeout(scheduleGroupedFilter, 0);
    });

   const mo = new MutationObserver((mutations) => {
  const rowStructureChanged = mutations.some(m =>
    [...m.addedNodes, ...m.removedNodes].some(n =>
      n.nodeType === 1 && n.matches?.('tr')
    )
  );

  if (rowStructureChanged) {
    scheduleSync(true);
  }
});

mo.observe(tbody, {
  childList: true
});

    tbody.addEventListener('click', (e) => {
      const btn = e.target.closest('button.btn-outline-secondary');
      if (!btn) return;

      const tr = btn.closest('tr');
      if (!tr) return;

      const wasEditing = tr.dataset.mode === 'edit';
      if (!wasEditing) return;

      setTimeout(async () => {
        scheduleSync();
        await persistLeadRowFor(tr);
      }, 80);
    });

    document.addEventListener('keydown', (e) => {
      if (!((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's')) return;

      const tr = document.activeElement?.closest?.('tr');
      if (!tr || tr.dataset.mode !== 'edit') return;

      setTimeout(async () => {
        scheduleSync();
        await persistLeadRowFor(tr);
      }, 80);
    });
  }
  function handoverHeaderIfLeadRowChanged(tr) {
  const HEADER_COLS = [3, 4, 5, 6, 7, 8, 9];

  const oldNr = (tr.dataset.originalAusgangNr || '').trim();
  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();

  if (!oldNr || !newNr || oldNr === newNr) return null;

  const rows = [...tbody.querySelectorAll('tr')];

  // War die bearbeitete Zeile vorher die erste der alten Gruppe?
  const oldGroupRows = rows.filter(r => (window.WA_getCellValue?.(r, 0) || '').trim() === oldNr);
  const oldLead = oldGroupRows[0];

  if (!oldLead || oldLead !== tr) return null;

  // Welche Zeilen bleiben nach der Änderung in der alten Gruppe?
  const remainingOldGroupRows = rows.filter(r => {
    if (r === tr) return false;
    return (window.WA_getCellValue?.(r, 0) || '').trim() === oldNr;
  });

  const newLeadOldGroup = remainingOldGroupRows[0] || null;
  if (!newLeadOldGroup) return null;

  // Kopfwerte aus der bisherigen Führungszeile an die neue Führungszeile der alten Gruppe übergeben
  HEADER_COLS.forEach(col => {
    const val = (window.WA_getCellValue?.(tr, col) || '').trim();
    const td = newLeadOldGroup.children[col];
    if (td) {
      if (window.WA_setCellRaw) window.WA_setCellRaw(td, val);
      else {
        td.dataset.raw = val;
        td.textContent = val;
      }
    }
  });

  return newLeadOldGroup;
}
function compareAusgangNr(a, b) {
  return String(a || '').localeCompare(String(b || ''), 'de', {
    numeric: true,
    sensitivity: 'base'
  });
}

function findLeadRowByNr(nr) {
  nr = String(nr || '').trim();
  if (!nr) return null;

  return [...tbody.querySelectorAll('tr')]
    .find(r => (window.WA_getCellValue?.(r, 0) || '').trim() === nr) || null;
}

async function saveRowQuiet(tr) {
  if (!tr || !window.WA_upsertRowToServer) return;
  try {
    await window.WA_upsertRowToServer(tr);
    tr.dataset.saved = '1';
  } catch (e) {
    console.warn('Save fehlgeschlagen', e);
  }
}

function handoverHeaderIfLeadRowChanged(tr) {
  const oldNr = (tr.dataset.originalAusgangNr || '').trim();
  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();

  if (!oldNr || !newNr || oldNr === newNr) return null;

  const oldLead = findLeadRowByNr(oldNr);
  if (!oldLead || oldLead !== tr) return null;

  const remainingOldGroupRows = [...tbody.querySelectorAll('tr')].filter(r => {
    if (r === tr) return false;
    return (window.WA_getCellValue?.(r, 0) || '').trim() === oldNr;
  });

  const newLeadOldGroup = remainingOldGroupRows[0] || null;
  if (!newLeadOldGroup) return null;

  HEADER_COLS.forEach(col => {
    const val = (window.WA_getCellValue?.(tr, col) || '').trim();
    const td = newLeadOldGroup.children[col];
    if (!td) return;

    if (window.WA_setCellRaw) {
      window.WA_setCellRaw(td, val);
    } else {
      td.dataset.raw = val;
      td.textContent = val;
    }
  });

  return newLeadOldGroup;
}

function moveRowToSortedPositionWithFade(tr) {
  const oldNr = (tr.dataset.originalAusgangNr || '').trim();
  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();

  if (!oldNr || !newNr || oldNr === newNr) {
    return Promise.resolve(false);
  }

  return new Promise((resolve) => {
    tr.style.transition = 'opacity .18s ease';
    tr.style.opacity = '0.15';

    setTimeout(() => {
      const rows = [...tbody.querySelectorAll('tr')].filter(r => r !== tr);

      const sameNrRows = rows.filter(r => (window.WA_getCellValue?.(r, 0) || '').trim() === newNr);

      if (sameNrRows.length) {
        sameNrRows[sameNrRows.length - 1].after(tr);
      } else {
        const beforeRow = rows.find(r => {
          const otherNr = (window.WA_getCellValue?.(r, 0) || '').trim();
          return compareAusgangNr(otherNr, newNr) > 0;
        });

        if (beforeRow) beforeRow.before(tr);
        else tbody.appendChild(tr);
      }

      requestAnimationFrame(() => {
        tr.style.opacity = '1';
        tr.classList.add('wa-row-moved');

        setTimeout(() => {
          tr.classList.remove('wa-row-moved');
          tr.style.transition = '';
          tr.style.opacity = '';
          resolve(true);
        }, 260);
      });
    }, 180);
  });
}
async function afterCommitRow(tr) {
  const oldNr = (tr.dataset.originalAusgangNr || '').trim();
  const newNr = (window.WA_getCellValue?.(tr, 0) || '').trim();
  const changedGroup = oldNr && newNr && oldNr !== newNr;

  let handedOverLead = null;

  if (changedGroup) {
    handedOverLead = handoverHeaderIfLeadRowChanged(tr);
    await moveRowToSortedPositionWithFade(tr);
  }

  normalizeGroupHeaderRows();
  lockSubrowHeaderEditors();
  window.WA_syncAllRowMonthMeta?.();
  window.WA_rebuildMonthAccordion?.();
  window.WA_rebuildFilterOptions?.();
  window.WA_applyFilters?.();

  if (changedGroup) {
    await saveRowQuiet(tr);

    if (oldNr) {
      const oldLead = findLeadRowByNr(oldNr);
      if (oldLead) await saveRowQuiet(oldLead);
    }

    if (newNr) {
      const newLead = findLeadRowByNr(newNr);
      if (newLead) await saveRowQuiet(newLead);
    }

    if (handedOverLead) {
      await saveRowQuiet(handedOverLead);
    }
  }

  tr.dataset.originalAusgangNr = newNr;
}

window.WA_afterCommitRow = afterCommitRow;

  function init() {
    installStyle();
    normalizeGroupHeaderRows();
    lockSubrowHeaderEditors();
    window.WA_syncAllRowMonthMeta?.();
    window.WA_rebuildMonthAccordion?.();
    window.WA_rebuildFilterOptions?.();

    window.WA_applyFilters = applyGroupedFilter;

    installEventHooks();
    applyGroupedFilter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();