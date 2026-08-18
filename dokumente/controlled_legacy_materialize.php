<?php
declare(strict_types=1);

require_once __DIR__ . '/controlled_legacy_snapshots.php';

function qcLegacyMaterializeArchive(string $documentNo, string $revision): bool
{
    $snapshot = qcLegacySnapshotGet($documentNo, $revision);
    if (!$snapshot) {
        return false;
    }

    $safeDoc = preg_replace('/[^A-Za-z0-9_.-]/', '_', $documentNo);
    $safeRev = preg_replace('/[^A-Za-z0-9_.-]/', '_', $revision);
    if (!$safeDoc || !$safeRev) {
        return false;
    }

    $archiveRoot = __DIR__ . '/controlled_archive/' . $safeDoc . '/Rev_' . $safeRev;
    $filesDir = $archiveRoot . '/files';
    $cssDir = $filesDir . '/CSS';
    $jsDir = $filesDir . '/js';

    foreach ([$archiveRoot, $filesDir, $cssDir, $jsDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }

    $writes = [
        $filesDir . '/druck_wa.html' => (string)$snapshot['html'],
        $cssDir . '/drucken.css' => (string)$snapshot['css'],
        $jsDir . '/drucken.js' => (string)$snapshot['js'],
    ];

    foreach ($writes as $path => $content) {
        if (!is_file($path) && file_put_contents($path, $content, LOCK_EX) === false) {
            return false;
        }
    }

    $manifestPath = $archiveRoot . '/manifest.json';
    if (!is_file($manifestPath)) {
        $manifest = [
            'document_no' => $snapshot['document_no'],
            'title' => $snapshot['title'],
            'revision' => $snapshot['revision'],
            'status' => 'released',
            'approved_at' => $snapshot['approved_at'],
            'source' => 'legacy_snapshot',
            'source_commit' => $snapshot['source_commit'],
            'note' => 'Historischer freigegebener Altstand. Automatisch aus dem unveränderlichen Legacy-Snapshot materialisiert.',
        ];
        if (file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) === false) {
            return false;
        }
    }

    return is_file($filesDir . '/druck_wa.html')
        && is_file($cssDir . '/drucken.css')
        && is_file($jsDir . '/drucken.js');
}
