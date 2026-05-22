let data = [];
let isSaving = false;

async function load() {
    const tbody = document.getElementById('list');

    try {
        setLoadingState();
        showTableMessage('loading');

        const res = await fetch('api/leergut_zaehlung_get.php', {
            cache: 'no-store'
        });

        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }

        const json = await parseJsonResponse(res);

        if (!Array.isArray(json)) {
            throw new Error('Ungültige Datenstruktur vom Server.');
        }

        data = json.map(normalizeItem);

        render();
        updateSaveInfo();
        updateSaveButtonState();
        postEmbedHeight();
    } catch (err) {
        console.error(err);
        data = [];
        showTableMessage('error');
        updateKpis([], true);
        setSaveInfoText('Fehler beim Laden der Behälterdaten.');
        updateSaveButtonState();
        postEmbedHeight();
    }
}

function normalizeItem(item) {
    const savedMenge = toInt(item.menge);
    const savedBemerkung = String(item.bemerkung || '');

    return {
        ...item,
        id: toInt(item.id),
        nummer: String(item.nummer || ''),
        vw_kennung: String(item.vw_kennung || ''),
        lagergruppe: String(item.lagergruppe || ''),
        status: normalizeStatus(item.status),
        klts_pro_behaelter: toInt(item.klts_pro_behaelter),
        savedMenge,
        currentMenge: savedMenge,
        savedBemerkung,
        currentBemerkung: savedBemerkung
    };
}

function render() {
    const tbody = document.getElementById('list');
    if (!tbody) return;

    const filtered = getFilteredItems();

    tbody.innerHTML = '';

    if (!filtered.length) {
        showTableMessage('empty');
        updateKpis([], true);
        updateSaveInfo();
        updateSaveButtonState();
        postEmbedHeight();
        return;
    }

    filtered.forEach(item => {
        const id = Number(item.id || 0);
        const gbMenge = toInt(item.savedMenge);
        const zaehlung = toInt(item.currentMenge);
        const faktor = toInt(item.klts_pro_behaelter);
        const kltGesamt = zaehlung * faktor;

        const behaelterTyp = item.nummer || '-';
        const vwKennung = item.vw_kennung || '-';
        const status = normalizeStatus(item.status);
        const bemerkung = String(item.currentBemerkung || '');

        const tr = document.createElement('tr');
        tr.dataset.id = String(id);
        tr.dataset.dirty = isItemDirty(item) ? '1' : '0';

        tr.innerHTML = `
            <td>
                <div class="leergut-row-type">${escapeHtml(behaelterTyp)}</div>
                <div class="leergut-row-sub">ID: ${escapeHtml(String(id))}</div>
            </td>

            <td class="col-vwkennung">
    <div class="leergut-row-code">${escapeHtml(vwKennung)}</div>
    <div class="leergut-row-sub">${escapeHtml(item.lagergruppe || '')}</div>
</td>

<td class="text-end col-kltgesamt">
    <span class="leergut-factor fw-bold" id="klt_${id}">
        ${formatNumber(kltGesamt)}
    </span>
</td>

            <td class="text-end">
                <span class="leergut-gb-menge" id="gb_${id}">
                    ${formatNumber(gbMenge)}
                </span>
            </td>

            <td class="text-end">
                <div class="leergut-qty-wrap justify-content-end">
                    <button type="button" class="btn btn-outline-danger leergut-mini-btn" data-id="${id}" data-step="-1">−</button>
                    <input
                        type="number"
                        class="form-control form-control-sm leergut-qty-input"
                        id="m_${id}"
                        min="0"
                        value="${zaehlung}"
                        inputmode="numeric"
                    >
                    <button type="button" class="btn btn-outline-success leergut-mini-btn" data-id="${id}" data-step="1">+</button>
                </div>
            </td>

            <td>
                <div class="leergut-status-badge ${escapeHtml(status)}">${escapeHtml(capitalize(status))}</div>
                <input
                    type="text"
                    class="form-control form-control-sm leergut-note-input"
                    id="b_${id}"
                    value="${escapeAttr(bemerkung)}"
                    placeholder="Bemerkung"
                >
            </td>
        `;

        tbody.appendChild(tr);
    });

    updateKpis(filtered, false);
    updateSaveInfo();
    updateSaveButtonState();
    postEmbedHeight();
}

function getFilteredItems() {
    const searchEl = document.getElementById('search');
    const statusFilterEl = document.getElementById('statusFilter');

    const term = ((searchEl && searchEl.value) || '').toLowerCase().trim();
    const statusFilter = ((statusFilterEl && statusFilterEl.value) || '').toLowerCase().trim();

    return data.filter(item => {
        const searchHaystack = [
            item.nummer,
            item.vw_kennung,
            item.lagergruppe,
            item.currentBemerkung,
            item.status
        ].join(' ').toLowerCase();

        const status = String(item.status || 'aktiv').toLowerCase();
        const matchesSearch = !term || searchHaystack.includes(term);
        const matchesStatus = !statusFilter || status === statusFilter;

        return matchesSearch && matchesStatus;
    });
}

function change(id, step) {
    const item = getItemById(id);
    const input = document.getElementById('m_' + id);

    if (!item || !input) return;

    let current = toInt(item.currentMenge);
    current += step;

    if (current < 0) {
        current = 0;
    }

    item.currentMenge = current;
    input.value = String(current);

    updateRowDerivedValues(id);
    updateRowDirtyState(id);
    updateSaveInfo();
    updateSaveButtonState();
    updateKpisFromState();
    postEmbedHeight();
}

function normalizeCountInput(input) {
    let val = toInt(input.value);

    if (val < 0) {
        val = 0;
    }

    input.value = String(val);
    return val;
}

function buildPayload() {
    return data
        .filter(item => isItemDirty(item))
        .map(item => ({
            id: item.id,
            menge: item.currentMenge,
            bemerkung: item.currentBemerkung
        }));
}

async function saveData() {
    if (isSaving) return;

    const dirtyCount = getDirtyItemsCount();
    if (dirtyCount === 0) {
        showToast('Keine Änderungen zum Speichern vorhanden.', false);
        return;
    }

    try {
        isSaving = true;
        updateSaveButtonState();
        setSaveInfoText('Speichere Änderungen ...');

        const payload = buildPayload();

        const res = await fetch('api/leergut_zaehlung_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await parseJsonResponse(res);

        if (!res.ok || !json || json.status !== 'ok') {
            throw new Error((json && json.message) || 'Speichern fehlgeschlagen');
        }

        showToast('Zählung erfolgreich gespeichert.', false);
        await load();
    } catch (err) {
        console.error(err);
        showToast('Fehler beim Speichern.', true);
        updateSaveInfo();
        updateSaveButtonState();
    } finally {
        isSaving = false;
        updateSaveButtonState();
        updateSaveInfo();
    }
}

function updateSaveInfo() {
    if (isSaving) {
        setSaveInfoText('Speichere Änderungen ...');
        return;
    }

    const visibleRows = getFilteredItems().length;
    const dirtyCount = getDirtyItemsCount();

    if (!data.length) {
        setSaveInfoText('Keine Behälterdaten geladen');
        return;
    }

    if (dirtyCount === 0) {
        setSaveInfoText(`${visibleRows} Zeilen sichtbar · Keine Änderungen vorhanden`);
        return;
    }

    setSaveInfoText(`${visibleRows} Zeilen sichtbar · ${dirtyCount} Änderung${dirtyCount === 1 ? '' : 'en'} nicht gespeichert`);
}

function setSaveInfoText(text) {
    const info = document.getElementById('saveInfo');
    if (!info) return;
    info.textContent = text;
}

function updateSaveButtonState() {
    const saveBtn = document.getElementById('saveBtn');
    if (!saveBtn) return;

    const dirtyCount = getDirtyItemsCount();
    const hasData = data.length > 0;

    saveBtn.disabled = isSaving || !hasData || dirtyCount === 0;

    if (isSaving) {
        saveBtn.textContent = 'Speichere ...';
        return;
    }

    if (dirtyCount > 0) {
        saveBtn.textContent = `💾 Zählung speichern (${dirtyCount})`;
        return;
    }

    saveBtn.textContent = '💾 Zählung speichern';
}

function updateKpis(filteredItems = null, isEmpty = false) {
    const visibleEl = document.getElementById('kpiVisible');
    const positiveEl = document.getElementById('kpiPositive');
    const kltTotalEl = document.getElementById('kpiKltTotal');

    if (isEmpty) {
        if (visibleEl) visibleEl.textContent = '0';
        if (positiveEl) positiveEl.textContent = '0';
        if (kltTotalEl) kltTotalEl.textContent = '0';
        return;
    }

    const list = Array.isArray(filteredItems) ? filteredItems : getFilteredItems();

    if (visibleEl) {
        visibleEl.textContent = String(list.length);
    }

    let positiveCount = 0;
    let kltTotal = 0;

    list.forEach(item => {
        const menge = toInt(item.currentMenge);
        const faktor = toInt(item.klts_pro_behaelter);

        if (menge > 0) {
            positiveCount++;
        }

        kltTotal += menge * faktor;
    });

    if (positiveEl) positiveEl.textContent = formatNumber(positiveCount);
    if (kltTotalEl) kltTotalEl.textContent = formatNumber(kltTotal);
}

function updateKpisFromState() {
    updateKpis(getFilteredItems(), false);
}

function updateRowDerivedValues(id) {
    const item = getItemById(id);
    if (!item) return;

    const kltEl = document.getElementById('klt_' + id);
    const kltGesamt = toInt(item.currentMenge) * toInt(item.klts_pro_behaelter);

    if (kltEl) {
        kltEl.textContent = formatNumber(kltGesamt);
    }
}

function updateRowDirtyState(id) {
    const item = getItemById(id);
    const row = document.querySelector('#list tr[data-id="' + id + '"]');

    if (!item || !row) return;

    row.dataset.dirty = isItemDirty(item) ? '1' : '0';
}

function isItemDirty(item) {
    if (!item) return false;

    return (
        toInt(item.currentMenge) !== toInt(item.savedMenge) ||
        String(item.currentBemerkung || '') !== String(item.savedBemerkung || '')
    );
}

function getDirtyItemsCount() {
    return data.reduce((count, item) => {
        return count + (isItemDirty(item) ? 1 : 0);
    }, 0);
}

function getItemById(id) {
    const numericId = Number(id || 0);
    return data.find(item => Number(item.id) === numericId) || null;
}

function normalizeStatus(status) {
    const value = String(status || 'aktiv').trim().toLowerCase();

    if (['aktiv', 'defekt', 'gesperrt'].includes(value)) {
        return value;
    }

    return 'aktiv';
}

function formatNumber(value) {
    const n = toInt(value);
    return n.toLocaleString('de-DE');
}

function toInt(value) {
    const n = parseInt(value != null ? value : 0, 10);
    return Number.isNaN(n) ? 0 : n;
}

function capitalize(str) {
    const s = String(str || '');
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

function showToast(message, isError = false) {
    const toast = document.getElementById('toastMsg');
    if (!toast) return;

    toast.className = 'leergut-toast alert shadow-sm ' + (isError ? 'alert-danger' : 'alert-success');
    toast.textContent = message;
    toast.classList.add('show');

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.classList.remove('show');
    }, 2500);

    postEmbedHeight();
}

function postEmbedHeight() {
    if (!window.LEERGUT_EMBED) return;
    if (window.parent === window) return;

    const height = Math.max(
        document.body.scrollHeight,
        document.documentElement.scrollHeight
    );

    window.parent.postMessage({
        type: 'workbench:iframe-height',
        source: 'leergut',
        height: height
    }, window.location.origin);
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, s => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[s]));
}

function escapeAttr(str) {
    return escapeHtml(str);
}

function showTableMessage(type) {
    const tbody = document.getElementById('list');
    if (!tbody) return;

    const loadingText = tbody.dataset.loadingText || 'Lade Behälterdaten ...';
    const emptyText = tbody.dataset.emptyText || 'Keine Behälter gefunden';
    const errorText = tbody.dataset.errorText || 'Fehler beim Laden der Behälterdaten';

    let text = loadingText;
    let textClass = '';

    if (type === 'empty') {
        text = emptyText;
    } else if (type === 'error') {
        text = errorText;
        textClass = ' text-danger';
    }

    tbody.innerHTML = `
        <tr class="leergut-empty-row">
            <td colspan="6" class="p-3${textClass}">${escapeHtml(text)}</td>
        </tr>
    `;
}

function setLoadingState() {
    const visibleEl = document.getElementById('kpiVisible');
    const positiveEl = document.getElementById('kpiPositive');
    const kltTotalEl = document.getElementById('kpiKltTotal');

    if (visibleEl) visibleEl.textContent = '–';
    if (positiveEl) positiveEl.textContent = '–';
    if (kltTotalEl) kltTotalEl.textContent = '–';

    setSaveInfoText('Lade Behälterdaten ...');
    updateSaveButtonState();
}

async function parseJsonResponse(res) {
    const text = await res.text();

    try {
        return text ? JSON.parse(text) : null;
    } catch (err) {
        console.error('Ungültige JSON-Antwort:', text);
        throw new Error('Ungültige Serverantwort.');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('search');
    const saveBtn = document.getElementById('saveBtn');
    const resetSearchBtn = document.getElementById('resetSearchBtn');
    const statusFilter = document.getElementById('statusFilter');
    const tbody = document.getElementById('list');

    if (search) {
        search.addEventListener('input', () => {
            render();
        });
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', () => {
            render();
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', saveData);
    }

    if (resetSearchBtn) {
        resetSearchBtn.addEventListener('click', () => {
            if (search) search.value = '';
            if (statusFilter) statusFilter.value = '';
            render();
            if (search) search.focus();
        });
    }

    if (tbody) {
        tbody.addEventListener('click', event => {
            const btn = event.target.closest('.leergut-mini-btn[data-id][data-step]');
            if (!btn) return;

            const id = Number(btn.dataset.id || 0);
            const step = Number(btn.dataset.step || 0);

            change(id, step);
        });

        tbody.addEventListener('input', event => {
            const target = event.target;

            if (target.classList.contains('leergut-qty-input')) {
                const match = String(target.id || '').match(/^m_(\d+)$/);
                if (!match) return;

                const id = Number(match[1]);
                const item = getItemById(id);
                if (!item) return;

                item.currentMenge = normalizeCountInput(target);
                updateRowDerivedValues(id);
                updateRowDirtyState(id);
                updateSaveInfo();
                updateSaveButtonState();
                updateKpisFromState();
                postEmbedHeight();
                return;
            }

            if (target.classList.contains('leergut-note-input')) {
                const match = String(target.id || '').match(/^b_(\d+)$/);
                if (!match) return;

                const id = Number(match[1]);
                const item = getItemById(id);
                if (!item) return;

                item.currentBemerkung = String(target.value || '');
                updateRowDirtyState(id);
                updateSaveInfo();
                updateSaveButtonState();
                postEmbedHeight();
            }
        });
    }

    load();
    window.addEventListener('resize', postEmbedHeight);
    window.setTimeout(postEmbedHeight, 150);
});