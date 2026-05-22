<?php
require __DIR__ . '/../inc/session.php';

date_default_timezone_set('Europe/Berlin');

$username = $_SESSION['username'] ?? 'Unbekannt';
$today = date('d.m.Y');
$isEmbed = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$bodyClass = $isEmbed ? 'is-embed' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Workbench – Leergut Zählung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/leergut_zaehlung.css" rel="stylesheet">
</head>
<body
    class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>"
    data-embed="<?= $isEmbed ? '1' : '0' ?>"
>
<div class="container-fluid py-3">
    <div class="container-xxl">

        <?php if (!$isEmbed): ?>
            <div class="tabs-bar bg-white shadow-sm p-3 mb-3">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
                    <div>
                        <div class="leergut-kicker">Workbench / Leergut</div>
                        <h1 class="leergut-page-title mb-1">Behälterzählung</h1>
                        <div class="text-muted">
                            Behälter, VW-Kennung, KLT-Faktor und gezählte Bestände in Tabellenform
                        </div>
                    </div>

                    <div class="text-lg-end">
                        <div class="small text-muted">Benutzer</div>
                        <div class="fw-semibold"><?= htmlspecialchars((string)$username, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="leergut-embed-head d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <div class="leergut-kicker mb-1">Leergut</div>
                    <div class="fw-semibold">Behälterzählung</div>
                </div>

                <div class="d-flex align-items-center gap-2 small text-muted">
                    <span><?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></span>
                    <span>•</span>
                    <span><?= htmlspecialchars((string)$username, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="kpi-grid mb-3 leergut-kpi-grid">
            <div class="card stat-card shadow-sm border-0">
                <div class="card-body">
                    <div class="sub">Datum</div>
                    <div class="value"><?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="card stat-card shadow-sm border-0">
                <div class="card-body">
                    <div class="sub">Sichtbare Zeilen</div>
                    <div class="value" id="kpiVisible">0</div>
                </div>
            </div>

            <div class="card stat-card shadow-sm border-0">
                <div class="card-body">
                    <div class="sub">Mit Bestand &gt; 0</div>
                    <div class="value" id="kpiPositive">0</div>
                </div>
            </div>

            <div class="card stat-card shadow-sm border-0">
                <div class="card-body">
                    <div class="sub">Gezählte KLT-Menge</div>
                    <div class="value" id="kpiKltTotal">0</div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-lg">
                        <label for="search" class="visually-hidden">
                            Suche nach Behältertyp, VW-Kennung, Nummer oder Lagergruppe
                        </label>
                        <div class="position-relative">
                            <span class="leergut-search-icon" aria-hidden="true">🔍</span>
                            <input
                                type="text"
                                id="search"
                                class="form-control leergut-search-input"
                                placeholder="Typ, VW-Kennung, Nummer oder Lagergruppe suchen ..."
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="col-6 col-lg-auto d-grid">
                        <button type="button" class="btn btn-outline-secondary" id="resetSearchBtn">
                            Filter zurücksetzen
                        </button>
                    </div>

                    <div class="col-6 col-lg-auto d-grid">
                        <label for="statusFilter" class="visually-hidden">Statusfilter</label>
                        <select id="statusFilter" class="form-select">
                            <option value="">Alle Status</option>
                            <option value="aktiv">Aktiv</option>
                            <option value="defekt">Defekt</option>
                            <option value="gesperrt">Gesperrt</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h6 mb-0 text-uppercase text-muted fw-bold leergut-section-title">
                Leergutbestand
            </h2>

            <div class="small text-muted" id="saveInfo" aria-live="polite">
                Keine Änderungen vorhanden
            </div>
        </div>

        <div class="card shadow-sm border-0 leergut-table-card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 leergut-table">
                    <thead>
                        <tr>
                            <th scope="col">Behältertyp</th>
                            <th scope="col" class="col-vwkennung">VW-Kennung</th>
                            <th scope="col" class="text-end col-kltgesamt">KLT Gesamt</th>
                            <th scope="col" class="text-end">GB-Bestand</th>
                            <th scope="col" class="text-end">Zählung</th>
                            <th scope="col">Info</th>
                        </tr>
                    </thead>
                    <tbody
                        id="list"
                        data-loading-text="Lade Behälterdaten ..."
                        data-empty-text="Keine Behälter gefunden"
                        data-error-text="Fehler beim Laden der Behälterdaten"
                    >
                        <tr>
                            <td colspan="6" class="text-muted p-3">Lade Behälterdaten ...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div class="leergut-savebar">
    <div class="container-xxl">
        <div class="card shadow border-0">
            <div class="card-body d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-between gap-2">
                <div class="text-muted small">
                    Änderungen werden für den heutigen Tag gespeichert.
                </div>
                <button type="button" class="btn btn-primary leergut-save-btn" id="saveBtn">
                    💾 Zählung speichern
                </button>
            </div>
        </div>
    </div>
</div>

<div
    class="leergut-toast alert alert-success shadow-sm"
    id="toastMsg"
    role="alert"
    aria-live="polite"
    aria-atomic="true"
></div>

<script>
(function () {
    const isEmbedded = document.body.dataset.embed === '1';
    const CLOSE_MENU_SCROLL_THRESHOLD = 8;

    if (!isEmbedded) {
        return;
    }

    function notifyParentCloseMenu() {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(
                { type: 'workbench:iframe-close-menu' },
                window.location.origin
            );
        }
    }

    document.addEventListener('click', function () {
        notifyParentCloseMenu();
    }, true);

    let lastY = window.scrollY || 0;
    let ticking = false;

    function onScroll() {
        if (ticking) {
            return;
        }

        ticking = true;

        requestAnimationFrame(function () {
            const y = window.scrollY || 0;
            const diff = Math.abs(y - lastY);

            if (diff > CLOSE_MENU_SCROLL_THRESHOLD) {
                notifyParentCloseMenu();
                lastY = y;
            }

            ticking = false;
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>

<script>
window.LEERGUT_EMBED = <?= $isEmbed ? 'true' : 'false' ?>;
</script>

<script src="js/leergut_zaehlung.js"></script>
</body>
</html>