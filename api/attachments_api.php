<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---- Basis: Speicherort für Anhänge ----------------------------------------
$BASE_FS      = __DIR__ . '/../uploads/attachments'; // Filesystem-Basis
$BASE_URL_REL = 'uploads/attachments';               // Relativ unter /LKW/

// ---- JSON Helper ------------------------------------------------------------
function json_out(bool $ok, array $extra = [], int $http = 200): void {
    header('Content-Type: application/json; charset=utf-8', true, $http);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $extra = []): void {
    json_out(true, $extra, 200);
}

function json_fail(string $msg, int $http = 400, array $extra = []): void {
    json_out(false, array_merge(['error' => $msg], $extra), $http);
}

// ---- Utils ------------------------------------------------------------------
function sanitize_name(string $s): string {
    $s = preg_replace('~[^A-Za-z0-9._-]~', '_', $s);
    $s = preg_replace('~_+~', '_', (string)$s);
    return trim((string)$s, '._-');
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Konnte Verzeichnis nicht anlegen: ' . $path);
        }
    }
}

function safe_mime_type(string $file): string {
    if (!is_file($file)) {
        return 'application/octet-stream';
    }

    // 1. finfo, wenn verfügbar
    if (class_exists('finfo')) {
        try {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $m  = $fi->file($file);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        } catch (Throwable $e) {
            // Fallback weiter unten
        }
    }

    // 2. mime_content_type, wenn verfügbar
    if (function_exists('mime_content_type')) {
        try {
            $m = mime_content_type($file);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        } catch (Throwable $e) {
            // Fallback weiter unten
        }
    }

    // 3. einfache Extension-Fallbacks
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));

    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'pdf'         => 'application/pdf',
        'txt'         => 'text/plain',
        default       => 'application/octet-stream',
    };
}

/**
 * Liest Nummer + Scope aus GET/POST.
 * Unterstützt:
 * - ausgang=...  -> wa
 * - eingang=...  -> we
 * optional zusätzlich:
 * - nr=... + scope=wa|we|ausgang|eingang
 */
function read_number_and_scope(array $src): array {
    $nrRaw = '';

    if (isset($src['ausgang']) && $src['ausgang'] !== '') {
        $nrRaw = (string)$src['ausgang'];
        $scope = 'wa';
    } elseif (isset($src['eingang']) && $src['eingang'] !== '') {
        $nrRaw = (string)$src['eingang'];
        $scope = 'we';
    } elseif (isset($src['nr']) && $src['nr'] !== '') {
        $nrRaw = (string)$src['nr'];
        $scopeRaw = strtolower(trim((string)($src['scope'] ?? $src['type'] ?? 'we')));
        $scope = in_array($scopeRaw, ['wa', 'ausgang'], true) ? 'wa' : 'we';
    } else {
        json_fail('Parameter "eingang" oder "ausgang" fehlt', 400);
    }

    $nrSafe = sanitize_name($nrRaw);
    if ($nrSafe === '') {
        json_fail('Ungültige Eingangs-/Ausgangsnummer', 400);
    }

    return [$nrSafe, $scope];
}

// ---- Main -------------------------------------------------------------------
try {
    $action = isset($_GET['action']) ? (string)$_GET['action'] : (isset($_POST['action']) ? (string)$_POST['action'] : '');
    if ($action === '') {
        json_fail('action fehlt', 400);
    }

    ensure_dir($BASE_FS);

    switch ($action) {
        case 'list':
            list($nrSafe, $scope) = read_number_and_scope($_GET);

            $dirNew    = $BASE_FS . '/' . $scope . '/' . $nrSafe; // .../we|wa/<NR>
            $dirLegacy = $BASE_FS . '/' . $nrSafe;                // .../<NR>

            $items = [];

            $scan = function (string $dirAbs, string $baseRel) use (&$items, $nrSafe, $scope, $BASE_URL_REL): void {
                if (!is_dir($dirAbs)) {
                    return;
                }

                $dh = @opendir($dirAbs);
                if ($dh === false) {
                    return;
                }

                while (($file = readdir($dh)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    $full = $dirAbs . '/' . $file;
                    if (!is_file($full)) {
                        continue;
                    }

                    $items[] = [
                        'id'          => $scope . '::' . $nrSafe . '::' . $file,
                        'filename'    => $file,
                        'mime_type'   => safe_mime_type($full),
                        'size_bytes'  => @filesize($full) ?: 0,
                        'uploaded_at' => date('c', @filemtime($full) ?: time()),
                        'path_rel'    => rtrim($BASE_URL_REL, '/') . '/' . ltrim($baseRel, '/') . '/' . rawurlencode($file),
                    ];
                }

                closedir($dh);
            };

            $scan($dirNew, $scope . '/' . $nrSafe);
            $scan($dirLegacy, $nrSafe);

            usort($items, function (array $a, array $b): int {
                return strcmp((string)$b['uploaded_at'], (string)$a['uploaded_at']);
            });

            json_ok(['items' => $items]);
            break;

        case 'upload':
            list($nrSafe, $scope) = read_number_and_scope($_POST);

            if (empty($_FILES['files'])) {
                json_fail('Keine Dateien übergeben (files[])', 400);
            }

            $dirScope = $BASE_FS . '/' . $scope;
            ensure_dir($dirScope);

            $dir = $dirScope . '/' . $nrSafe;
            ensure_dir($dir);

            $files = $_FILES['files'];
            $multiple = is_array($files['name']);
            $count = $multiple ? count($files['name']) : 1;

            $saved = [];

            for ($i = 0; $i < $count; $i++) {
                $name = $multiple ? (string)$files['name'][$i] : (string)$files['name'];
                $tmp  = $multiple ? (string)$files['tmp_name'][$i] : (string)$files['tmp_name'];
                $err  = $multiple ? (int)$files['error'][$i] : (int)$files['error'];
                $size = $multiple ? (int)$files['size'][$i] : (int)$files['size'];

                if ($err !== UPLOAD_ERR_OK) {
                    continue;
                }

                $safeBase = sanitize_name($name);
                if ($safeBase === '') {
                    $safeBase = 'file';
                }

                $targetName = date('Ymd_His') . '_' . uniqid('', true) . '_' . $safeBase;
                $target     = $dir . '/' . $targetName;

                if (!@move_uploaded_file($tmp, $target)) {
                    continue;
                }

                $saved[] = [
                    'id'          => $scope . '::' . $nrSafe . '::' . $targetName,
                    'filename'    => $targetName,
                    'mime_type'   => safe_mime_type($target),
                    'size_bytes'  => @filesize($target) ?: $size,
                    'uploaded_at' => date('c', @filemtime($target) ?: time()),
                    'path_rel'    => rtrim($BASE_URL_REL, '/') . '/' . $scope . '/' . $nrSafe . '/' . rawurlencode($targetName),
                ];
            }

            json_ok(['items' => $saved]);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            if ($id === '') {
                json_fail('Parameter "id" fehlt/ungültig', 400);
            }

            $parts = explode('::', $id);

            if (count($parts) === 3) {
                $scope   = sanitize_name((string)$parts[0]);
                $nrSafe  = sanitize_name((string)$parts[1]);
                $fileRel = basename((string)$parts[2]);
            } elseif (count($parts) === 2) {
                $scope   = 'we';
                $nrSafe  = sanitize_name((string)$parts[0]);
                $fileRel = basename((string)$parts[1]);
            } else {
                json_fail('Ungültiges id-Format', 400);
            }

            if ($scope !== 'we' && $scope !== 'wa') {
                json_fail('Ungültiger Scope', 400);
            }

            $file = $BASE_FS . '/' . $scope . '/' . $nrSafe . '/' . $fileRel;

            if (!is_file($file)) {
                $legacy = $BASE_FS . '/' . $nrSafe . '/' . $fileRel;
                if (is_file($legacy)) {
                    $file = $legacy;
                } else {
                    json_fail('Datei nicht gefunden', 404);
                }
            }

            if (!@unlink($file)) {
                json_fail('Löschen fehlgeschlagen', 500);
            }

            json_ok();
            break;

        default:
            json_fail('Unbekannte action', 400);
            break;
    }
} catch (Throwable $e) {
    json_out(false, [
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine()
    ], 500);
}