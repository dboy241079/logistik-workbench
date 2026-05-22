<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/session.php';
require dirname(__DIR__) . '/inc/rbac.php';

// Optional, wenn du die Seite nur eingebettet in der Workbench öffnen willst:
// $AUTH_DEFAULT_TAB   = 'artikelakte';
// $AUTH_ALLOWED_ROLES = ['admin','disposition','staplerfahrer','verpacker','standortleiter','user'];
// $AUTH_REQUIRE_EMBED = true;
// if (file_exists(dirname(__DIR__) . '/inc/auth_embed.php')) {
//     require dirname(__DIR__) . '/inc/auth_embed.php';
// }

$currentUser = $_SESSION['username'] ?? 'Unbekannt';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Artikelakte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Falls Bootstrap in deiner Hauptseite schon geladen wird, kannst du diese Zeile entfernen -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/artikelakte.css?v=4">
</head>
<body>
<div class="wrap py-4">

    <div class="module-header mb-4">
        <div class="module-header-left">
            <span class="module-tag">Workbench Modul</span>
            <h1>Artikelakte</h1>
            <p>
                Suche nach Sachnummer, Referenznummer oder Lieferschein und verfolge
                den Verlauf eines Artikels über Wareneingang, Lager und Warenausgang.
            </p>
        </div>

        <div class="module-header-right">
            <div class="badge-user">
                Benutzer:
                <strong><?= htmlspecialchars((string)$currentUser) ?></strong>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <div class="section-head">
            <div>
                <h2>Suche & Filter</h2>
                <p class="section-subtext">
                    Gib eine Sachnummer, Referenznummer oder Lieferscheinnummer ein.
                </p>
            </div>
        </div>

        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-5">
                <div class="field position-relative">
    <label for="q">Suchbegriff</label>
    <input
        type="text"
        id="q"
        class="form-control ak-input"
        placeholder="z. B. 05E 117 021 C oder Referenznummer"
        autocomplete="off"
        spellcheck="false"
    >
    <div id="qSuggestions" class="ak-suggest-list d-none"></div>
    <div id="qHint" class="form-text text-muted mt-2">
        Sachnummern werden live aus der Datenbank vorgeschlagen.
    </div>
</div>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
                <div class="field">
                    <label for="source">Quelle</label>
                    <select id="source" class="form-select ak-select">
                        <option value="all">Alle Quellen</option>
                        <option value="wareneingang">Nur Wareneingang</option>
                        <option value="lager">Nur Lager</option>
                        <option value="warenausgang">Nur Warenausgang</option>
                        <option value="historie">Nur Historie</option>
                    </select>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-2">
                <div class="field">
                    <label for="limit">Max. Treffer</label>
                    <select id="limit" class="form-select ak-select">
                        <option value="100">100</option>
                        <option value="200" selected>200</option>
                        <option value="500">500</option>
                    </select>
                </div>
            </div>

            <div class="col-12 col-lg-2">
                <button class="btn btn-primary w-100 ak-btn" id="btnSearch" type="button">
                    Artikel suchen
                </button>
            </div>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-12 col-md-4">
                <div class="quick-info-card h-100">
                    <span class="quick-info-label">Suche möglich nach</span>
                    <span class="quick-info-value">Sachnummer / Referenznummer / Lieferschein</span>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="quick-info-card h-100">
                    <span class="quick-info-label">Ziel</span>
                    <span class="quick-info-value">Lieferung, Bearbeiter, Lagerort, Ausbuchung</span>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="quick-info-card h-100">
                    <span class="quick-info-label">Status</span>
                    <span class="quick-info-value" id="searchStateMini">Bereit</span>
                </div>
            </div>
        </div>

        <div class="status mt-3" id="status">Bereit.</div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="panel h-100">
                <div class="section-head">
    <div>
        <h2>Artikelübersicht</h2>
        <p class="section-subtext">
            Kompakter Überblick über den gefundenen Datensatz.
        </p>
    </div>
</div>

<div id="overviewNotice" class="ak-overview-note d-none"></div>

<div class="row g-3" id="overviewCards">
                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Sachnummer</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Referenznummer</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Status</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Letzter Bearbeiter</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Erster Eingang</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Letzte Bewegung</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Letzter Ort</div>
                            <div class="value">–</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card ak-card h-100">
                            <div class="label">Treffer gesamt</div>
                            <div class="value">0</div>
                        </div>
                    </div>
                </div>

                <div class="matches mt-4" id="matchesBox">
                    <div class="section-head section-head-small">
                        <div>
                            <h3>Direkte Treffer</h3>
                            <p class="section-subtext">Alle gefundenen Datensätze aus den Quellen.</p>
                        </div>
                    </div>

                    <div class="list-empty">Noch keine Suche ausgeführt.</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="panel h-100">
                <div class="section-head">
                    <div>
                        <h2>Historie / Timeline</h2>
                        <p class="section-subtext">
                            Zeitlicher Verlauf aller gefundenen Ereignisse.
                        </p>
                    </div>
                </div>

                <div class="timeline" id="timeline">
                    <div class="list-empty">Noch keine Daten geladen.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const qEl = document.getElementById('q');
    const qSuggestionsEl = document.getElementById('qSuggestions');
    const qHintEl = document.getElementById('qHint');

    const sourceEl = document.getElementById('source');
    const limitEl = document.getElementById('limit');
    const btnEl = document.getElementById('btnSearch');
    const statusEl = document.getElementById('status');
    const overviewEl = document.getElementById('overviewCards');
    const timelineEl = document.getElementById('timeline');
    const matchesBoxEl = document.getElementById('matchesBox');
    const searchStateMiniEl = document.getElementById('searchStateMini');

    let suggestTimer = null;
    let suggestionItems = [];
    let activeSuggestionIndex = -1;
    let selectedSachnummer = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeSachnummer(value) {
        return String(value || '')
            .toUpperCase()
            .replace(/[^A-Z0-9]/g, '');
    }

    function valueOrDash(v) {
        return v && String(v).trim() !== '' ? escapeHtml(v) : '–';
    }

    function getStatusBadgeClass(statusText) {
        const s = String(statusText || '').toLowerCase();

        if (s.includes('ausgebucht') || s.includes('warenausgang') || s.includes('verladen')) {
            return 'danger';
        }
        if (s.includes('lager') || s.includes('bestand')) {
            return 'primary';
        }
        if (s.includes('eingang') || s.includes('geliefert') || s.includes('eingebucht')) {
            return 'success';
        }
        if (s.includes('offen') || s.includes('prüfung') || s.includes('bearbeitung') || s.includes('kommission')) {
            return 'warning';
        }
        return 'secondary';
    }

    function getEventClass(typeText) {
        const t = String(typeText || '').toLowerCase();

        if (t.includes('lieferung') || t.includes('wareneingang')) {
            return 'event-inbound';
        }

        if (t.includes('einlager') || t.includes('lager')) {
            return 'event-storage';
        }

        if (
            t.includes('ausbuch') ||
            t.includes('kommission') ||
            t.includes('bereitstellung') ||
            t.includes('verladung') ||
            t.includes('kommi')
        ) {
            return 'event-outbound';
        }

        if (t.includes('historie') || t.includes('signiert')) {
            return 'event-history';
        }

        return 'event-default';
    }

    function setStatus(text, isError = false) {
        statusEl.textContent = text;
        statusEl.style.color = isError ? '#dc3545' : '#6c757d';

        if (searchStateMiniEl) {
            searchStateMiniEl.textContent = text;
            searchStateMiniEl.style.color = isError ? '#dc3545' : '#212529';
        }
    }

    function closeSuggestions() {
        suggestionItems = [];
        activeSuggestionIndex = -1;
        qSuggestionsEl.innerHTML = '';
        qSuggestionsEl.classList.add('d-none');
    }

    function renderSuggestions(items) {
        suggestionItems = Array.isArray(items) ? items : [];
        activeSuggestionIndex = -1;

        if (!suggestionItems.length) {
            closeSuggestions();
            return;
        }

        qSuggestionsEl.innerHTML = suggestionItems.map((item, index) => `
            <button type="button" class="ak-suggest-item" data-index="${index}">
                <span class="ak-suggest-main">${escapeHtml(item.sachnummer)}</span>
                <span class="ak-suggest-meta">
                    ${escapeHtml(item.lagergruppe || 'ohne Lagergruppe')}
                </span>
            </button>
        `).join('');

        qSuggestionsEl.classList.remove('d-none');

        qSuggestionsEl.querySelectorAll('.ak-suggest-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = Number(btn.dataset.index);
                selectSuggestionByIndex(idx);
            });
        });
    }

    function highlightActiveSuggestion() {
        const nodes = qSuggestionsEl.querySelectorAll('.ak-suggest-item');
        nodes.forEach((node, index) => {
            node.classList.toggle('active', index === activeSuggestionIndex);
        });
    }

    function selectSuggestionByIndex(index) {
        const item = suggestionItems[index];
        if (!item) return;

        qEl.value = item.sachnummer;
        selectedSachnummer = item.sachnummer;
        qHintEl.textContent = `Sachnummer erkannt: ${item.sachnummer}${item.lagergruppe ? ' · Lagergruppe: ' + item.lagergruppe : ''}`;
        closeSuggestions();
    }

    async function fetchSachnummerSuggestions(query) {
        const url = `api/sachnummer_suggest.php?q=${encodeURIComponent(query)}&limit=8`;
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();

        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Sachnummern-Vorschläge konnten nicht geladen werden.');
        }

        return data;
    }

    async function maybeLoadSuggestions() {
        const raw = qEl.value.trim();

        if (raw.length < 2) {
            selectedSachnummer = null;
            qHintEl.textContent = 'Sachnummern werden live aus der Datenbank vorgeschlagen.';
            closeSuggestions();
            return;
        }

        try {
            const data = await fetchSachnummerSuggestions(raw);

            if (data.exact_match && data.exact_item) {
                qHintEl.textContent = `Exakte Sachnummer gefunden: ${data.exact_item.sachnummer}${data.exact_item.lagergruppe ? ' · Lagergruppe: ' + data.exact_item.lagergruppe : ''}`;
            } else if (Array.isArray(data.items) && data.items.length > 0) {
                qHintEl.textContent = 'Passende Sachnummern gefunden. Bitte aus der Liste auswählen, um Tippfehler zu vermeiden.';
            } else {
                qHintEl.textContent = 'Keine passende Sachnummer gefunden. Suche als Referenznummer, Lieferschein oder anderer Wert bleibt möglich.';
            }

            renderSuggestions(data.items || []);
        } catch (err) {
            console.error(err);
            qHintEl.textContent = 'Sachnummern-Vorschläge konnten gerade nicht geladen werden.';
            closeSuggestions();
        }
    }

    async function validateSachnummerInput(rawValue) {
        const raw = String(rawValue || '').trim();
        if (!raw) return false;

        if (selectedSachnummer && normalizeSachnummer(selectedSachnummer) === normalizeSachnummer(raw)) {
            return true;
        }

        try {
            const data = await fetchSachnummerSuggestions(raw);

            if (data.exact_match && data.exact_item) {
                qEl.value = data.exact_item.sachnummer;
                selectedSachnummer = data.exact_item.sachnummer;
                qHintEl.textContent = `Exakte Sachnummer gefunden: ${data.exact_item.sachnummer}${data.exact_item.lagergruppe ? ' · Lagergruppe: ' + data.exact_item.lagergruppe : ''}`;
                closeSuggestions();
                return true;
            }

            if (Array.isArray(data.items) && data.items.length > 0) {
                qHintEl.textContent = 'Bitte eine gültige Sachnummer aus der Vorschlagsliste auswählen.';
                renderSuggestions(data.items);
                setStatus('Bitte eine gültige Sachnummer aus der Vorschlagsliste auswählen.', true);
                return false;
            }

            return true;
        } catch (err) {
            console.error(err);
            return true;
        }
    }

   function renderOverview(data) {
    const o = data?.overview || {};
    const statusText = o.status || '–';
    const statusClass = getStatusBadgeClass(statusText);
    const isSammelansicht = data?.search_mode === 'exact_sachnummer';

    const overviewNoticeEl = document.getElementById('overviewNotice');

    if (isSammelansicht) {
        if (overviewNoticeEl) {
            overviewNoticeEl.classList.remove('d-none');
            overviewNoticeEl.innerHTML = `
                <div class="ak-overview-note-title">Sammelansicht für Sachnummer</div>
                <div class="ak-overview-note-text">
                    Diese Ansicht zeigt alle gefundenen Bestände und Bewegungen zu dieser Sachnummer.
                    Einzelwerte wie Referenznummer oder einzelner Lagerplatz werden hier bewusst nicht als ein globaler Wert dargestellt.
                </div>
            `;
        }

        overviewEl.innerHTML = `
            <div class="col-12">
                <div class="card ak-card ak-summary-card h-100">
                    <div class="label">Sachnummer</div>
                    <div class="value mb-3">${valueOrDash(o.sachnummer)}</div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="badge text-bg-${statusClass} ak-badge">${escapeHtml(statusText)}</span>
                    </div>

                    <div class="ak-summary-box">
                        <div class="ak-summary-title">Gesamtbestand dieser Sachnummer</div>

                        <div class="ak-summary-grid">
                            <div class="ak-summary-item">
                                <div class="ak-summary-label">Aktive Lagerplätze</div>
                                <div class="ak-summary-value">${valueOrDash(o.bestand_aktive_lagerplaetze ?? 0)}</div>
                            </div>

                            <div class="ak-summary-item">
                                <div class="ak-summary-label">Gesamtmenge</div>
                                <div class="ak-summary-value">${valueOrDash(o.bestand_gesamtmenge ?? 0)}</div>
                            </div>

                            <div class="ak-summary-item">
                                <div class="ak-summary-label">Treffer gesamt</div>
                                <div class="ak-summary-value">${valueOrDash(o.treffer_gesamt ?? 0)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        return;
    }

    if (overviewNoticeEl) {
        overviewNoticeEl.classList.add('d-none');
        overviewNoticeEl.innerHTML = '';
    }

    overviewEl.innerHTML = `
        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Sachnummer</div>
                <div class="value">${valueOrDash(o.sachnummer)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Referenznummer</div>
                <div class="value">${valueOrDash(o.referenznummer)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Status</div>
                <div class="value">
                    <span class="badge text-bg-${statusClass} ak-badge">${escapeHtml(statusText)}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Bearbeitet von</div>
                <div class="value">${valueOrDash(o.bearbeitet_von)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Geliefert am</div>
                <div class="value">${valueOrDash(o.geliefert_am)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Eingelagert am</div>
                <div class="value">${valueOrDash(o.eingelagert_am)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Ausgebucht am</div>
                <div class="value">${valueOrDash(o.ausgebucht_am)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Verladen am</div>
                <div class="value">${valueOrDash(o.verladen_am)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Letzte Bewegung</div>
                <div class="value">${valueOrDash(o.letzte_bewegung)}</div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card ak-card h-100">
                <div class="label">Aktueller / letzter Lagerplatz</div>
                <div class="value">${valueOrDash(o.aktueller_lagerplatz)}</div>
            </div>
        </div>

        <div class="col-12">
            <div class="card ak-card h-100">
                <div class="label">Treffer gesamt</div>
                <div class="value">${valueOrDash(o.treffer_gesamt ?? 0)}</div>
            </div>
        </div>
    `;
}

    function renderTimeline(data) {
        const items = Array.isArray(data?.timeline) ? data.timeline : [];

        if (!items.length) {
            timelineEl.innerHTML = `<div class="list-empty">Keine Historie gefunden.</div>`;
            return;
        }

        timelineEl.innerHTML = items.map(item => {
            const eventClass = getEventClass(item.typ || '');
            const badgeClass = getStatusBadgeClass(item.status || item.typ || '');

            return `
                <div class="event ${eventClass}">
                    <div class="event-header">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="event-type">${escapeHtml(item.typ || 'Ereignis')}</div>
                            <span class="badge text-bg-${badgeClass} ak-badge">
                                ${escapeHtml(item.status || 'ohne Status')}
                            </span>
                        </div>
                        <div class="event-time">${escapeHtml(item.zeitpunkt || 'ohne Zeit')}</div>
                    </div>

                    <div class="event-body">
                        ${escapeHtml(item.beschreibung || 'Kein Beschreibungstext vorhanden.')}
                    </div>

                    <div class="event-meta">
                        <span><strong>Benutzer:</strong> ${escapeHtml(item.benutzer || '–')}</span>
                        <span><strong>Quelle:</strong> ${escapeHtml(item.quelle || '–')}</span>
                        <span><strong>Lagerort:</strong> ${escapeHtml(item.lagerort || '–')}</span>
                        <span><strong>Menge:</strong> ${escapeHtml(item.menge || '–')}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderMatches(data) {
        const matches = Array.isArray(data?.matches) ? data.matches : [];

        if (!matches.length) {
            matchesBoxEl.innerHTML = `
                <div class="section-head section-head-small">
                    <div>
                        <h3>Direkte Treffer</h3>
                        <p class="section-subtext">Alle gefundenen Datensätze aus den Quellen.</p>
                    </div>
                </div>
                <div class="list-empty">Keine direkten Treffer gefunden.</div>
            `;
            return;
        }

        matchesBoxEl.innerHTML = `
            <div class="section-head section-head-small">
                <div>
                    <h3>Direkte Treffer</h3>
                    <p class="section-subtext">Alle gefundenen Datensätze aus den Quellen.</p>
                </div>
            </div>

            <div class="d-flex flex-column gap-2">
                ${matches.map(row => {
                    const badgeClass = getStatusBadgeClass(row.status || row.typ || '');
                    return `
                        <div class="match-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <strong>${escapeHtml(row.quelle_label || row.quelle || 'Quelle')}</strong>
                                <span class="badge text-bg-${badgeClass} ak-badge">${escapeHtml(row.status || 'ohne Status')}</span>
                            </div>

                            <div class="muted">Sachnummer: ${escapeHtml(row.sachnummer || '–')}</div>
<div class="muted">Referenznummer: ${escapeHtml(row.referenznummer || '–')}</div>
<div class="muted">Eingangsnummer: ${escapeHtml(row.eingang_nr || '–')}</div>
<div class="muted">Ausgangsnummer: ${escapeHtml(row.ausgang_nr || '–')}</div>
<div class="muted">Order-No: ${escapeHtml(row.order_no || '–')}</div>
<div class="muted">Lieferschein: ${escapeHtml(row.lieferschein || '–')}</div>
                            <div class="muted">Geliefert am: ${escapeHtml(row.geliefert_am || '–')}</div>
                            <div class="muted">Eingelagert am: ${escapeHtml(row.eingelagert_am || '–')}</div>
                            <div class="muted">Ausgebucht am: ${escapeHtml(row.ausgebucht_am || '–')}</div>
                            <div class="muted">Verladen am: ${escapeHtml(row.verladen_am || '–')}</div>
                            <div class="muted">Zeitpunkt: ${escapeHtml(row.zeitpunkt || '–')}</div>
                            <div class="muted">Benutzer: ${escapeHtml(row.benutzer || '–')}</div>
                            <div class="muted">Lagerort: ${escapeHtml(row.lagerort || '–')}</div>
                            <div class="muted">Menge: ${escapeHtml(row.menge || '–')}</div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    async function runSearch() {
        const rawQ = qEl.value.trim();
        const source = sourceEl.value;
        const limit = limitEl.value;

        if (!rawQ) {
            setStatus('Bitte zuerst eine Sachnummer, Referenznummer oder einen Lieferschein eingeben.', true);
            return;
        }

        const isValid = await validateSachnummerInput(rawQ);
        if (!isValid) {
            return;
        }

        const q = qEl.value.trim();

        setStatus('Suche läuft...');
        btnEl.disabled = true;

        try {
            const searchKind =
    selectedSachnummer &&
    normalizeSachnummer(selectedSachnummer) === normalizeSachnummer(q)
        ? 'sachnummer_exact'
        : '';

const url = `api/artikelakte_lookup.php?q=${encodeURIComponent(q)}&source=${encodeURIComponent(source)}&limit=${encodeURIComponent(limit)}&search_kind=${encodeURIComponent(searchKind)}`;
            const res = await fetch(url, { credentials: 'same-origin' });

            let data;
            try {
                data = await res.json();
            } catch (jsonError) {
                throw new Error('API liefert kein gültiges JSON zurück.');
            }

            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Unbekannter Fehler');
            }

            renderOverview(data);
            renderTimeline(data);
            renderMatches(data);

            const found = Array.isArray(data.found_sources) && data.found_sources.length
                ? data.found_sources.join(', ')
                : 'keine';

            setStatus(`Suche erfolgreich. Gefundene Quellen: ${found}`);
        } catch (err) {
            console.error(err);
            setStatus(`Fehler: ${err.message}`, true);
        } finally {
            btnEl.disabled = false;
        }
    }

    qEl.addEventListener('input', () => {
        const current = qEl.value.trim();

        if (selectedSachnummer && normalizeSachnummer(selectedSachnummer) !== normalizeSachnummer(current)) {
            selectedSachnummer = null;
        }

        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(() => {
            maybeLoadSuggestions();
        }, 180);
    });

    qEl.addEventListener('keydown', (e) => {
        const itemsCount = suggestionItems.length;

        if (e.key === 'ArrowDown' && itemsCount > 0) {
            e.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex + 1) % itemsCount;
            highlightActiveSuggestion();
            return;
        }

        if (e.key === 'ArrowUp' && itemsCount > 0) {
            e.preventDefault();
            activeSuggestionIndex = activeSuggestionIndex <= 0 ? itemsCount - 1 : activeSuggestionIndex - 1;
            highlightActiveSuggestion();
            return;
        }

        if ((e.key === 'Enter' || e.key === 'Tab') && itemsCount > 0 && activeSuggestionIndex >= 0) {
            e.preventDefault();
            selectSuggestionByIndex(activeSuggestionIndex);
            return;
        }

        if (e.key === 'Escape') {
            closeSuggestions();
        }

        if (e.key === 'Enter' && (itemsCount === 0 || activeSuggestionIndex < 0)) {
            runSearch();
        }
    });

    qEl.addEventListener('focus', () => {
        if (qEl.value.trim().length >= 2) {
            maybeLoadSuggestions();
        }
    });

    document.addEventListener('click', (e) => {
        if (!qSuggestionsEl.contains(e.target) && e.target !== qEl) {
            closeSuggestions();
        }
    });

    btnEl.addEventListener('click', runSearch);
})();
</script>
</body>
</html>