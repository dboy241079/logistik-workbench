<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo 'Kein Zugriff. Bitte zuerst einloggen.';
    exit;
}

function qcPreviewSafeSegment(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '..') || preg_match('/[^A-Za-z0-9_.-]/', $value)) {
        throw new RuntimeException('Ungültiger Pfadparameter.');
    }
    return $value;
}

function qcPreviewFindFirstFile(string $root, string $extension, ?string $preferredBasename = null): ?string
{
    if ($preferredBasename !== null) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strcasecmp($fileInfo->getBasename(), $preferredBasename) === 0) {
                return $fileInfo->getPathname();
            }
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === strtolower($extension)) {
            return $fileInfo->getPathname();
        }
    }

    return null;
}

function qcPreviewGermanDate(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('d.m.Y', $ts);
}

try {
    $doc = qcPreviewSafeSegment((string)($_GET['doc'] ?? ''));
    $rev = qcPreviewSafeSegment((string)($_GET['rev'] ?? ''));

    $base = __DIR__ . '/controlled_archive/' . $doc . '/Rev_' . $rev;
    $filesDir = $base . '/files';
    if (!is_dir($filesDir)) {
        throw new RuntimeException('Archivierte Revision wurde nicht gefunden.');
    }

    $htmlPath = qcPreviewFindFirstFile($filesDir, 'html', 'druck_wa.html');
    if ($htmlPath === null || !is_file($htmlPath)) {
        throw new RuntimeException('Für diese Revision wurde keine druckbare HTML-Datei gefunden.');
    }

    $cssPath = qcPreviewFindFirstFile($filesDir, 'css', 'drucken.css');
    $jsPath = qcPreviewFindFirstFile($filesDir, 'js', 'drucken.js');

    $html = (string)file_get_contents($htmlPath);
    if ($html === '') {
        throw new RuntimeException('Die archivierte Druckvorlage ist leer.');
    }

    $manifest = [];
    $manifestPath = $base . '/manifest.json';
    if (is_file($manifestPath)) {
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($decoded)) {
            $manifest = $decoded;
        }
    }

    $title = trim((string)($manifest['title'] ?? 'Laufzettel Wareneingang'));
    if ($title === '') {
        $title = 'Dokument';
    }
    $approvedDate = qcPreviewGermanDate((string)($manifest['approved_at'] ?? ''));

    // Live-Assets entfernen: Die Vorschau verwendet ausschließlich die Dateien
    // aus dem Archiv der ausgewählten Revision.
    $html = preg_replace(
        '~<link\b[^>]*href=["\'][^"\']*drucken\.css[^"\']*["\'][^>]*>~i',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '~<script\b[^>]*src=["\'][^"\']*drucken\.js[^"\']*["\'][^>]*>\s*</script>~i',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '~<script\b[^>]*src=["\'][^"\']*html2pdf[^"\']*["\'][^>]*>\s*</script>~i',
        '',
        $html
    ) ?? $html;

    $archivedCss = $cssPath !== null ? (string)file_get_contents($cssPath) : '';
    $archivedCss = str_ireplace('</style', '<\/style', $archivedCss);

    $previewCss = <<<'CSS'
<style id="qc-controlled-preview-style">
/* Alte Formular-Toolbar nicht anzeigen; die Dokumentenlenkung bekommt eine eigene Vorschau-Leiste. */
body > .toolbar.no-print,
body > .toolbar {
    display: none !important;
}

body {
    background: #e2e8f0 !important;
    padding-bottom: 24px !important;
}

.qc-preview-toolbar {
    position: sticky;
    top: 0;
    z-index: 100000;
    width: 210mm;
    max-width: calc(100vw - 24px);
    margin: 0 auto 12px;
    padding: 10px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: rgba(15, 23, 42, .96);
    color: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .18);
    font-family: Arial, sans-serif;
}

.qc-preview-meta {
    min-width: 0;
}
.qc-preview-meta strong {
    display: block;
    font-size: 13px;
    line-height: 1.35;
}
.qc-preview-meta span {
    display: block;
    margin-top: 2px;
    font-size: 11px;
    color: #cbd5e1;
}
.qc-preview-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}
.qc-preview-btn {
    border: 1px solid rgba(255,255,255,.3);
    border-radius: 7px;
    padding: 8px 12px;
    background: #fff;
    color: #0f172a;
    font: 600 12px/1.2 Arial, sans-serif;
    cursor: pointer;
}
.qc-preview-btn:hover {
    background: #f1f5f9;
}
.qc-preview-btn.primary {
    border-color: #16a34a;
    background: #16a34a;
    color: #fff;
}
.qc-preview-btn.primary:hover {
    background: #15803d;
}

/* Bildschirmvorschau möglichst nah an der vorhandenen A4-Druckansicht. */
@media screen {
    .page.a4 {
        width: 210mm !important;
        min-height: 297mm !important;
        height: 297mm !important;
        padding: 10mm 10mm 30mm !important;
        margin: 0 auto 8mm !important;
        overflow: hidden !important;
        background: #fff !important;
        outline: 1px solid #cbd5e1 !important;
        box-shadow: 0 6px 24px rgba(15, 23, 42, .12);
    }
    .page.a4 .head {
        position: static !important;
        top: auto !important;
        box-shadow: none !important;
    }
    .page.a4 .doc-footer {
        left: 10mm !important;
        right: 10mm !important;
        bottom: 8mm !important;
    }
}

@media print {
    .qc-preview-toolbar,
    body > .toolbar.no-print,
    body > .toolbar {
        display: none !important;
    }
    html,
    body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
}

@media (max-width: 860px) {
    .qc-preview-toolbar {
        position: relative;
        flex-direction: column;
        align-items: stretch;
    }
    .qc-preview-actions {
        justify-content: stretch;
    }
    .qc-preview-btn {
        flex: 1;
    }
}
</style>
CSS;

    $styleBlock = '<style id="qc-archived-document-css">' . $archivedCss . '</style>' . $previewCss;
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $styleBlock . '</head>', $html);
    } else {
        $html = $styleBlock . $html;
    }

    $safeDoc = htmlspecialchars($doc, ENT_QUOTES, 'UTF-8');
    $safeRev = htmlspecialchars($rev, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeApprovedDate = htmlspecialchars($approvedDate, ENT_QUOTES, 'UTF-8');

    $metaLine = 'Freigegebene Revision ' . $safeRev;
    if ($safeApprovedDate !== '') {
        $metaLine .= ' · freigegeben am ' . $safeApprovedDate;
    }

    $toolbar = '<div class="qc-preview-toolbar no-print">'
        . '<div class="qc-preview-meta">'
        . '<strong>Vorschau · ' . $safeDoc . ' – ' . $safeTitle . '</strong>'
        . '<span>' . $metaLine . ' · archivierter Dokumentstand</span>'
        . '</div>'
        . '<div class="qc-preview-actions">'
        . '<button type="button" class="qc-preview-btn" id="qcPreviewBack">← Zurück</button>'
        . '<button type="button" class="qc-preview-btn primary" id="qcPreviewPrint">🖨 Drucken / als PDF speichern</button>'
        . '</div>'
        . '</div>';

    $html = preg_replace('~<body([^>]*)>~i', '<body$1>' . $toolbar, $html, 1) ?? $html;

    $archivedJs = $jsPath !== null ? (string)file_get_contents($jsPath) : '';
    $archivedJs = str_ireplace('</script', '<\/script', $archivedJs);

    $revisionJs = json_encode($rev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $approvedDateJs = json_encode($approvedDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $docTitleJs = json_encode($doc . ' – ' . $title . ' – Rev. ' . $rev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $previewJs = <<<HTML
<script>
// Eine Dokumentencenter-Vorschau ist immer die leere freigegebene Vorlage.
// Eventuell vorhandene Wareneingangs-Druckdaten aus einer früheren Sitzung dürfen
// den archivierten Dokumentstand nicht unbeabsichtigt befüllen.
try {
    sessionStorage.removeItem('waPrintPayload');
} catch (_) {}
try {
    if (window.name) {
        const parsed = JSON.parse(window.name);
        if (parsed && typeof parsed === 'object' && parsed.waPrintPayload) {
            delete parsed.waPrintPayload;
            window.name = JSON.stringify(parsed);
        }
    }
} catch (_) {}
</script>
<script>
{$archivedJs}
</script>
<script>
(() => {
    const revision = {$revisionJs};
    const approvedDate = {$approvedDateJs};
    const previewTitle = {$docTitleJs};

    document.title = previewTitle;

    function stampRevision() {
        document.querySelectorAll('.doc-footer-left div').forEach(el => {
            const text = (el.textContent || '').trim();
            if (/^Rev-Nr\.?/i.test(text)) {
                el.textContent = 'Rev-Nr. ' + revision;
            }
            if (approvedDate && /^Aktualisiert:/i.test(text)) {
                el.textContent = 'Aktualisiert: ' + approvedDate;
            }
        });
    }

    function goBack() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '/?tab=docs';
        }
    }

    const backButton = document.getElementById('qcPreviewBack');
    const printButton = document.getElementById('qcPreviewPrint');

    if (backButton) {
        backButton.addEventListener('click', goBack);
    }
    if (printButton) {
        printButton.addEventListener('click', () => {
            stampRevision();
            window.print();
        });
    }

    stampRevision();
    window.addEventListener('load', stampRevision, { once: true });
    window.addEventListener('beforeprint', stampRevision);
})();
</script>
HTML;

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $previewJs . '</body>', $html);
    } else {
        $html .= $previewJs;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $html;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Dokumentvorschau</title></head>';
    echo '<body style="font-family:Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a">';
    echo '<h2>Dokumentvorschau konnte nicht geöffnet werden</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/?tab=docs">Zurück zum Dokumentencenter</a></p>';
    echo '</body></html>';
}
