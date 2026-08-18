<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo 'Kein Zugriff. Bitte zuerst einloggen.';
    exit;
}

function qcPdfSafeSegment(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '..') || preg_match('/[^A-Za-z0-9_.-]/', $value)) {
        throw new RuntimeException('Ungültiger Pfadparameter.');
    }
    return $value;
}

function qcPdfFindFirstFile(string $root, string $extension, ?string $preferredBasename = null): ?string
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

function qcPdfGermanDate(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? '' : date('d.m.Y', $ts);
}

function qcPdfDownloadName(string $documentNo, string $title, string $revision): string
{
    $title = trim($title);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if (is_string($converted) && $converted !== '') {
            $title = $converted;
        }
    }
    $title = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $title) ?: 'Dokument';
    $title = trim($title, '-_.');
    return $documentNo . '_' . $title . '_Rev_' . $revision . '.pdf';
}

try {
    $doc = qcPdfSafeSegment((string)($_GET['doc'] ?? ''));
    $rev = qcPdfSafeSegment((string)($_GET['rev'] ?? ''));

    $base = __DIR__ . '/controlled_archive/' . $doc . '/Rev_' . $rev;
    $filesDir = $base . '/files';
    if (!is_dir($filesDir)) {
        throw new RuntimeException('Archivierte Revision wurde nicht gefunden.');
    }

    $htmlPath = qcPdfFindFirstFile($filesDir, 'html', 'druck_wa.html');
    if ($htmlPath === null || !is_file($htmlPath)) {
        throw new RuntimeException('Für diese Revision wurde keine druckbare HTML-Datei gefunden.');
    }

    $cssPath = qcPdfFindFirstFile($filesDir, 'css', 'drucken.css');
    $jsPath = qcPdfFindFirstFile($filesDir, 'js', 'drucken.js');

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
    $approvedDate = qcPdfGermanDate((string)($manifest['approved_at'] ?? ''));
    $downloadName = qcPdfDownloadName($doc, $title, $rev);

    // Die archivierten Assets werden inline eingebettet. Damit verwendet jede Revision
    // exakt ihren eigenen CSS-/JS-Stand und nicht versehentlich die aktuelle Live-Datei.
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

    $pdfCss = <<<'CSS'
<style id="qc-controlled-pdf-style">
/* PDF-Referenz: nur die eigentlichen A4-Seiten, keine Bedienoberfläche. */
.qc-pdf-wrapper .no-print,
.qc-pdf-wrapper .toolbar,
.qc-pdf-wrapper .row-controls,
.qc-pdf-wrapper .tiny-hint,
.qc-pdf-wrapper button {
    display: none !important;
}
.qc-pdf-wrapper .page.a4 {
    margin: 0 !important;
    outline: none !important;
    break-after: page !important;
    page-break-after: always !important;
}
.qc-pdf-wrapper .page.a4:last-child {
    break-after: auto !important;
    page-break-after: auto !important;
}
.qc-pdf-wrapper .head {
    position: static !important;
    top: auto !important;
    box-shadow: none !important;
}
#qc-pdf-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8fafc;
    color: #0f172a;
    font-family: Arial, sans-serif;
}
#qc-pdf-overlay > div {
    max-width: 520px;
    margin: 20px;
    padding: 24px;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 12px 35px rgba(15, 23, 42, .12);
    text-align: center;
}
#qc-pdf-overlay strong { display:block; font-size:18px; margin-bottom:8px; }
#qc-pdf-overlay span { display:block; font-size:13px; color:#475569; line-height:1.5; }
</style>
CSS;

    $styleBlock = '<style id="qc-archived-document-css">' . $archivedCss . '</style>' . $pdfCss;
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $styleBlock . '</head>', $html);
    } else {
        $html = $styleBlock . $html;
    }

    $overlay = '<div id="qc-pdf-overlay"><div><strong>PDF wird erstellt …</strong><span id="qc-pdf-status">Rev. '
        . htmlspecialchars($rev, ENT_QUOTES, 'UTF-8')
        . ' wird aus dem freigegebenen Archivstand erzeugt.</span></div></div>';
    $html = preg_replace('~<body([^>]*)>~i', '<body$1>' . $overlay, $html, 1) ?? $html;

    $archivedJs = $jsPath !== null ? (string)file_get_contents($jsPath) : '';
    $archivedJs = str_ireplace('</script', '<\/script', $archivedJs);

    $downloadNameJs = json_encode($downloadName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $revisionJs = json_encode($rev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $approvedDateJs = json_encode($approvedDate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $generatorJs = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
{$archivedJs}
</script>
<script>
(() => {
    const downloadName = {$downloadNameJs};
    const revision = {$revisionJs};
    const approvedDate = {$approvedDateJs};
    const statusEl = document.getElementById('qc-pdf-status');

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function waitForImages() {
        const images = Array.from(document.images || []);
        return Promise.all(images.map(img => {
            if (img.complete) return Promise.resolve();
            return new Promise(resolve => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        }));
    }

    function stampRevision(root) {
        root.querySelectorAll('.doc-footer-left div').forEach(el => {
            const text = (el.textContent || '').trim();
            if (/^Rev-Nr\.?/i.test(text)) {
                el.textContent = 'Rev-Nr. ' + revision;
            }
            if (approvedDate && /^Aktualisiert:/i.test(text)) {
                el.textContent = 'Aktualisiert: ' + approvedDate;
            }
        });
    }

    async function createPdf() {
        try {
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }
            await waitForImages();
            await new Promise(resolve => setTimeout(resolve, 250));

            if (typeof window.html2pdf !== 'function') {
                throw new Error('PDF-Bibliothek konnte nicht geladen werden.');
            }

            const sourcePages = Array.from(document.querySelectorAll('.page.a4'));
            if (!sourcePages.length) {
                throw new Error('In der archivierten Revision wurden keine A4-Seiten gefunden.');
            }

            const wrapper = document.createElement('div');
            wrapper.className = 'qc-pdf-wrapper';
            wrapper.style.position = 'absolute';
            wrapper.style.left = '-10000px';
            wrapper.style.top = '0';
            wrapper.style.background = '#fff';

            sourcePages.forEach(page => {
                const clone = page.cloneNode(true);
                clone.querySelectorAll('.no-print, .toolbar, .row-controls, .tiny-hint, button').forEach(el => el.remove());
                wrapper.appendChild(clone);
            });

            stampRevision(wrapper);
            document.body.appendChild(wrapper);

            setStatus('PDF wird gerendert …');
            await window.html2pdf()
                .set({
                    margin: 0,
                    filename: downloadName,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] }
                })
                .from(wrapper)
                .save();

            wrapper.remove();
            setStatus('PDF wurde heruntergeladen. Du wirst zurück zum Dokumentencenter geleitet.');
            setTimeout(() => {
                if (window.history.length > 1) {
                    window.history.back();
                }
            }, 900);
        } catch (error) {
            console.error(error);
            setStatus('PDF konnte nicht erstellt werden: ' + (error && error.message ? error.message : String(error)));
        }
    }

    window.addEventListener('load', createPdf, { once: true });
})();
</script>
HTML;

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $generatorJs . '</body>', $html);
    } else {
        $html .= $generatorJs;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $html;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>PDF-Download</title></head>';
    echo '<body style="font-family:Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a">';
    echo '<h2>PDF konnte nicht erstellt werden</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/?tab=docs">Zurück zum Dokumentencenter</a></p>';
    echo '</body></html>';
}
