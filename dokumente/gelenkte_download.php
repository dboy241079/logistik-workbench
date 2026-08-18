<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo 'Kein Zugriff. Bitte zuerst einloggen.';
    exit;
}

function safeSegment(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '..') || preg_match('/[^A-Za-z0-9_.-]/', $value)) {
        throw new RuntimeException('Ungültiger Pfadparameter.');
    }
    return $value;
}

try {
    $doc = safeSegment((string)($_GET['doc'] ?? ''));
    $rev = safeSegment((string)($_GET['rev'] ?? ''));

    $base = __DIR__ . '/controlled_archive/' . $doc . '/Rev_' . $rev;
    $filesDir = $base . '/files';
    if (!is_dir($filesDir)) {
        throw new RuntimeException('Archivierte Revision wurde nicht gefunden.');
    }

    $requestedFile = trim((string)($_GET['file'] ?? ''));
    if ($requestedFile !== '') {
        $requestedFile = str_replace('\\', '/', $requestedFile);
        if (str_contains($requestedFile, '..') || str_starts_with($requestedFile, '/')) {
            throw new RuntimeException('Ungültiger Dateipfad.');
        }

        $absolute = $filesDir . '/' . $requestedFile;
        $realFiles = realpath($filesDir);
        $realAbsolute = realpath($absolute);
        if ($realFiles === false || $realAbsolute === false || !str_starts_with($realAbsolute, $realFiles . DIRECTORY_SEPARATOR) || !is_file($realAbsolute)) {
            throw new RuntimeException('Archivdatei wurde nicht gefunden.');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($realAbsolute) . '"');
        header('Content-Length: ' . filesize($realAbsolute));
        header('X-Content-Type-Options: nosniff');
        readfile($realAbsolute);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP-Download ist auf diesem Server nicht verfügbar. Bitte die Dateien einzeln herunterladen.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'qc_rev_');
    if ($tmp === false) {
        throw new RuntimeException('Temporäre ZIP-Datei konnte nicht erstellt werden.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($filesDir) + 1));
        $zip->addFile($fileInfo->getPathname(), $relative);
    }

    $manifest = $base . '/manifest.json';
    if (is_file($manifest)) {
        $zip->addFile($manifest, 'manifest.json');
    }
    $zip->close();

    $downloadName = $doc . '_Rev_' . $rev . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmp));
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
}
