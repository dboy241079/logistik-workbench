<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../api/_db.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3 small">Kein Zugriff. Bitte zuerst einloggen.</div>';
    exit;
}

if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Debug nur für Admin & nur wenn ?debug=1
if (isset($_GET['debug']) && (($_SESSION['role'] ?? '') === 'admin')) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

$currentRole = (string)($_SESSION['role'] ?? '');

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '–';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '–';
    }

    return date('d.m.Y H:i', $ts);
}

function toSearchText(string $text): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }

    return strtolower($text);
}

/**
 * Prüft sichtbare Rollen.
 * Unterstützt:
 * - NULL / '' => für alle sichtbar
 * - JSON Array, z. B. ["admin","user"]
 * - Kommagetrennt, z. B. admin,user
 */
function isDocVisibleForRole(array $doc, string $currentRole): bool
{
    $raw = $doc['visible_roles'] ?? null;

    if ($raw === null || trim((string)$raw) === '') {
        return true;
    }

    $raw = trim((string)$raw);

    // JSON?
    if (
        (str_starts_with($raw, '[') && str_ends_with($raw, ']')) ||
        (str_starts_with($raw, '{') && str_ends_with($raw, '}'))
    ) {
        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $roles = array_map('strval', $decoded);
            return in_array($currentRole, $roles, true);
        }
    }

    // CSV-Fallback
    $roles = array_filter(array_map('trim', explode(',', $raw)));
    if (!$roles) {
        return true;
    }

    return in_array($currentRole, $roles, true);
}

try {
    // Kategorien laden
    $stmtCats = $pdo->query("
        SELECT id, name, description, sort_order
        FROM qc_doc_categories
        ORDER BY sort_order, name
    ");
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // Aktive Dokumente laden
    $stmtDocs = $pdo->query("
        SELECT
            d.id,
            d.title,
            d.filename,
            d.original_name,
            d.category,
            d.hall,
            d.created_at,
            d.uploaded_by,
            d.visible_roles,
            d.active,
            COALESCE(u.display_name, u.username, CONCAT('ID ', d.uploaded_by)) AS uploader
        FROM qc_100_docs d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.active = 1
        ORDER BY d.created_at DESC, d.title ASC
    ");
    $allDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

    // Sichtbarkeit nach Rolle filtern
    $docs = array_values(array_filter($allDocs, static function (array $doc) use ($currentRole): bool {
        return isDocVisibleForRole($doc, $currentRole);
    }));

    // Kategorien-Mapping
    $catMap = [];
    foreach ($categories as $c) {
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $catMap[$name] = [
            'meta' => $c,
            'docs' => [],
        ];
    }

    // Dokumente zuordnen
    $uncategorized = [];
    foreach ($docs as $d) {
        $catName = trim((string)($d['category'] ?? ''));

        if ($catName !== '' && isset($catMap[$catName])) {
            $catMap[$catName]['docs'][] = $d;
        } else {
            $uncategorized[] = $d;
        }
    }

    $filterOptions = array_keys($catMap);
    $hasUncategorized = count($uncategorized) > 0;

} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="alert alert-danger m-3 small">';
    echo '<b>500 Fehler in dokumente.php</b><br>';
    echo '<pre style="white-space:pre-wrap;margin:8px 0 0;">' . e($e->getMessage()) . '</pre>';
    echo '</div>';
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Dokumentencenter</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Tailwind nur in diesem Bereich -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            light: '#e0f2fe',
                            DEFAULT: '#0ea5e9',
                            dark: '#0369a1'
                        }
                    }
                }
            }
        };
    </script>
</head>
<body class="bg-slate-100 text-slate-900 text-sm">
<div class="w-full py-4 px-3 sm:px-6 lg:px-10 space-y-4">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Dokumentencenter</h1>
            <p class="text-xs text-slate-600">
                Hier findest du Arbeitsanweisungen, Checklisten und Vorlagen zur Logistik-Workbench.
            </p>
        </div>
        <p class="text-[11px] text-slate-500">
            Uploads nur über den Adminbereich.
        </p>
    </header>

    <?php if (!$docs && !$categories): ?>
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">
                Es sind noch keine Kategorien oder Dokumente hinterlegt.
            </p>
        </section>
    <?php else: ?>
        <section class="space-y-4">
            <!-- Filterleiste -->
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-medium text-slate-700">Kategorie:</span>
                    <select id="docCategoryFilter" class="rounded-md border-slate-300 text-xs">
                        <option value="">Alle Kategorien</option>
                        <?php foreach ($filterOptions as $catName): ?>
                            <option value="<?= e($catName) ?>"><?= e($catName) ?></option>
                        <?php endforeach; ?>
                        <?php if ($hasUncategorized): ?>
                            <option value="__uncategorized">Allgemein / ohne Kategorie</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <label for="docSearch" class="font-medium text-slate-700">Suche:</label>
                    <input
                        id="docSearch"
                        type="text"
                        class="rounded-md border-slate-300 text-xs px-2 py-1 w-full sm:w-56"
                        placeholder="Titel oder Dateiname …"
                    >
                </div>
            </div>

            <!-- Kategorien -->
            <?php foreach ($catMap as $catName => $bucket): ?>
                <?php
                $meta = $bucket['meta'];
                $catDocs = $bucket['docs'];
                $docCount = count($catDocs);

                $lastRaw = null;
                foreach ($catDocs as $d) {
                    if (!empty($d['created_at'])) {
                        if ($lastRaw === null || $d['created_at'] > $lastRaw) {
                            $lastRaw = $d['created_at'];
                        }
                    }
                }
                $lastLabel = $lastRaw ? formatDateTime($lastRaw) : null;
                ?>
                <div
                    class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                    data-category-block
                    data-category="<?= e($catName) ?>"
                    data-doc-count="<?= $docCount ?>"
                >
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">
                                <?= e($catName) ?>
                            </h2>

                            <?php if (!empty($meta['description'])): ?>
                                <p class="text-[11px] text-slate-500">
                                    <?= e((string)$meta['description']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($lastLabel): ?>
                                <p class="text-[11px] text-slate-500">
                                    Zuletzt geändert: <?= e($lastLabel) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <span class="text-[11px] text-slate-500 whitespace-nowrap">
                            <?= $docCount ?> Dokument(e)
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-[12px] text-slate-800">
                            <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Titel</th>
                                <th class="px-3 py-2 text-left">Halle</th>
                                <th class="px-3 py-2 text-left whitespace-nowrap">Hochgeladen am</th>
                                <th class="px-3 py-2 text-left">von</th>
                                <th class="px-3 py-2 text-left">Download</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if ($docCount === 0): ?>
                                <tr>
                                    <td colspan="5" class="px-3 py-2 text-[11px] text-slate-500 italic">
                                        In dieser Kategorie sind noch keine Dokumente hinterlegt.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($catDocs as $d): ?>
                                    <?php
                                    $searchText = toSearchText((string)(($d['title'] ?? '') . ' ' . ($d['original_name'] ?? '')));
                                    $createdLabel = formatDateTime($d['created_at'] ?? null);
                                    $downloadFile = trim((string)($d['filename'] ?? ''));
                                    ?>
                                    <tr
                                        data-doc-row
                                        data-category="<?= e($catName) ?>"
                                        data-search="<?= e($searchText) ?>"
                                    >
                                        <td class="px-3 py-1.5">
                                            <div class="text-[12px] font-medium text-slate-900">
                                                <?= e((string)($d['title'] ?? '')) ?>
                                            </div>
                                            <div class="text-[10px] text-slate-500">
                                                <?= e((string)($d['original_name'] ?? '')) ?>
                                            </div>
                                        </td>
                                        <td class="px-3 py-1.5 text-[11px] text-slate-700">
                                            <?= e((string)($d['hall'] ?? '')) ?>
                                        </td>
                                        <td class="px-3 py-1.5 text-[11px] text-slate-700 whitespace-nowrap">
                                            <?= e($createdLabel) ?>
                                        </td>
                                        <td class="px-3 py-1.5 text-[11px] text-slate-700 whitespace-nowrap">
                                            <?= e((string)($d['uploader'] ?? '–')) ?>
                                        </td>
                                        <td class="px-3 py-1.5 text-[11px]">
                                            <?php if ($downloadFile !== ''): ?>
                                                <a
                                                    href="docs/<?= rawurlencode($downloadFile) ?>"
                                                    download="<?= e((string)($d['original_name'] ?? $downloadFile)) ?>"
                                                    class="inline-flex items-center gap-1 text-sky-600 hover:text-sky-800"
                                                >
                                                    ⬇️ <span>Download</span>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-slate-400">–</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Allgemein / ohne Kategorie -->
            <?php if ($hasUncategorized): ?>
                <?php $docCount = count($uncategorized); ?>
                <div
                    class="rounded-xl border border-dashed border-slate-200 bg-white p-4 shadow-sm"
                    data-category-block
                    data-category="__uncategorized"
                    data-doc-count="<?= $docCount ?>"
                >
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900">
                            Allgemein / ohne Kategorie
                        </h2>
                        <span class="text-[11px] text-slate-500 whitespace-nowrap">
                            <?= $docCount ?> Dokument(e)
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-[12px] text-slate-800">
                            <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Titel</th>
                                <th class="px-3 py-2 text-left">Halle</th>
                                <th class="px-3 py-2 text-left whitespace-nowrap">Hochgeladen am</th>
                                <th class="px-3 py-2 text-left">von</th>
                                <th class="px-3 py-2 text-left">Download</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($uncategorized as $d): ?>
                                <?php
                                $searchText = toSearchText((string)(($d['title'] ?? '') . ' ' . ($d['original_name'] ?? '')));
                                $createdLabel = formatDateTime($d['created_at'] ?? null);
                                $downloadFile = trim((string)($d['filename'] ?? ''));
                                ?>
                                <tr
                                    data-doc-row
                                    data-category="__uncategorized"
                                    data-search="<?= e($searchText) ?>"
                                >
                                    <td class="px-3 py-1.5">
                                        <div class="text-[12px] font-medium text-slate-900">
                                            <?= e((string)($d['title'] ?? '')) ?>
                                        </div>
                                        <div class="text-[10px] text-slate-500">
                                            <?= e((string)($d['original_name'] ?? '')) ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-[11px] text-slate-700">
                                        <?= e((string)($d['hall'] ?? '')) ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-[11px] text-slate-700 whitespace-nowrap">
                                        <?= e($createdLabel) ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-[11px] text-slate-700 whitespace-nowrap">
                                        <?= e((string)($d['uploader'] ?? '–')) ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-[11px]">
                                        <?php if ($downloadFile !== ''): ?>
                                            <a
                                                href="docs/<?= rawurlencode($downloadFile) ?>"
                                                download="<?= e((string)($d['original_name'] ?? $downloadFile)) ?>"
                                                class="inline-flex items-center gap-1 text-sky-600 hover:text-sky-800"
                                            >
                                                ⬇️ <span>Download</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-400">–</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const catSelect = document.getElementById('docCategoryFilter');
    const searchInput = document.getElementById('docSearch');
    const blocks = Array.from(document.querySelectorAll('[data-category-block]'));
    const rows = Array.from(document.querySelectorAll('tr[data-doc-row]'));

    function applyFilter() {
        const selectedCat = catSelect ? catSelect.value : '';
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';

        rows.forEach(row => {
            const rowCat = row.dataset.category || '';
            const text = (row.dataset.search || '').toLowerCase();

            const matchesCat = !selectedCat || rowCat === selectedCat;
            const matchesSearch = !q || text.includes(q);
            const visible = matchesCat && matchesSearch;

            row.style.display = visible ? '' : 'none';
        });

        blocks.forEach(block => {
            const blockCat = block.dataset.category || '';
            const docCount = parseInt(block.dataset.docCount || '0', 10);
            const rowsInBlock = Array.from(block.querySelectorAll('tbody tr[data-doc-row]'));
            const hasVisibleRow = rowsInBlock.some(tr => tr.style.display !== 'none');

            let visible = true;

            if (selectedCat && blockCat !== selectedCat) {
                visible = false;
            } else if (selectedCat && blockCat === selectedCat) {
                visible = docCount > 0 ? (hasVisibleRow || q === '') : true;
            } else {
                if (docCount > 0) {
                    visible = hasVisibleRow;
                } else {
                    visible = (q === '');
                }
            }

            block.classList.toggle('hidden', !visible);
        });
    }

    if (catSelect) {
        catSelect.addEventListener('change', applyFilter);
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }

    applyFilter();
});
</script>
</body>
</html>