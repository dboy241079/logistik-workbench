<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo 'Kein Zugriff. Bitte zuerst einloggen.';
    exit;
}

function gdE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$archiveRoot = __DIR__ . '/controlled_archive';
$entries = [];

if (is_dir($archiveRoot)) {
    foreach (glob($archiveRoot . '/*', GLOB_ONLYDIR) ?: [] as $docDir) {
        $documentNo = basename($docDir);
        foreach (glob($docDir . '/Rev_*', GLOB_ONLYDIR) ?: [] as $revDir) {
            $revision = preg_replace('/^Rev_/', '', basename($revDir));
            $manifestPath = $revDir . '/manifest.json';
            $manifest = [];
            if (is_file($manifestPath)) {
                $decoded = json_decode((string)file_get_contents($manifestPath), true);
                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            }

            $filesDir = $revDir . '/files';
            $files = [];
            if (is_dir($filesDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($filesDir) + 1));
                    $files[] = [
                        'path' => $relative,
                        'size' => $fileInfo->getSize(),
                    ];
                }
            }

            $entries[] = [
                'document_no' => (string)($manifest['document_no'] ?? $documentNo),
                'title' => (string)($manifest['title'] ?? 'Gelenktes Dokument'),
                'revision' => (string)($manifest['revision'] ?? $revision),
                'status' => (string)($manifest['status'] ?? 'released'),
                'approved_at' => (string)($manifest['approved_at'] ?? ''),
                'source_commit' => (string)($manifest['source_commit'] ?? ''),
                'files' => $files,
            ];
        }
    }
}

usort($entries, static function (array $a, array $b): int {
    $docCmp = strcmp($a['document_no'], $b['document_no']);
    if ($docCmp !== 0) {
        return $docCmp;
    }
    return version_compare($b['revision'], $a['revision']);
});
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notfall-Download-Center</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
</head>
<body class="bg-slate-100 text-slate-900 text-sm">
<div class="max-w-6xl mx-auto px-4 py-6 space-y-4">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Dokumentenlenkung</div>
            <h1 class="text-xl font-semibold">Notfall-Download-Center</h1>
            <p class="text-xs text-slate-600 mt-1 max-w-3xl">
                Hier liegen unveränderliche archivierte Stände freigegebener gelenkter Dokumente. Alte Revisionen bleiben erhalten und werden nicht überschrieben.
            </p>
        </div>
        <a href="/dokumente/dokumentenlenkung.php?embed=1" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700">← Dokumentenlenkung</a>
    </header>

    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
        <b>Notfallhinweis:</b> Die jeweils aktuelle freigegebene Revision ist die Arbeitsversion. Ältere Revisionen werden ausschließlich als Rückfall-, Nachweis- und Archivstand bereitgestellt.
    </div>

    <?php if (!$entries): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">Noch keine archivierte Revision vorhanden.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($entries as $entry): ?>
                <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="p-4 border-b border-slate-100 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-sky-700"><?= gdE($entry['document_no']) ?></span>
                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Rev. <?= gdE($entry['revision']) ?> · Archiviert</span>
                            </div>
                            <h2 class="text-base font-semibold mt-1"><?= gdE($entry['title']) ?></h2>
                            <?php if ($entry['approved_at'] !== ''): ?><div class="text-[11px] text-slate-500 mt-1">Freigegeben/archiviert: <?= gdE($entry['approved_at']) ?></div><?php endif; ?>
                        </div>
                        <a href="/dokumente/gelenkte_download.php?doc=<?= rawurlencode($entry['document_no']) ?>&rev=<?= rawurlencode($entry['revision']) ?>"
                           class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white">
                            Kompletten Stand als ZIP herunterladen
                        </a>
                    </div>
                    <div class="p-4">
                        <div class="text-xs font-semibold text-slate-600 mb-2">Enthaltene Dateien</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                            <?php foreach ($entry['files'] as $file): ?>
                                <a href="/dokumente/gelenkte_download.php?doc=<?= rawurlencode($entry['document_no']) ?>&rev=<?= rawurlencode($entry['revision']) ?>&file=<?= rawurlencode($file['path']) ?>"
                                   class="rounded-lg border border-slate-200 bg-slate-50 p-3 hover:bg-slate-100">
                                    <div class="text-xs font-medium break-all"><?= gdE($file['path']) ?></div>
                                    <div class="text-[10px] text-slate-500 mt-1"><?= number_format((int)$file['size'] / 1024, 1, ',', '.') ?> KB</div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
