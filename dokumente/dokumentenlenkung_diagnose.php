<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');

function out(string $label, bool $ok, string $detail = ''): void
{
    $color = $ok ? '#166534' : '#991b1b';
    $bg = $ok ? '#f0fdf4' : '#fef2f2';
    echo '<div style="margin:8px 0;padding:10px 12px;border:1px solid ' . $color . ';background:' . $bg . ';border-radius:8px;font-family:Arial,sans-serif">';
    echo '<b style="color:' . $color . '">' . htmlspecialchars(($ok ? 'OK – ' : 'FEHLER – ') . $label, ENT_QUOTES, 'UTF-8') . '</b>';
    if ($detail !== '') {
        echo '<pre style="white-space:pre-wrap;margin:6px 0 0;font-size:12px">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</div>';
}

echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Dokumentenlenkung Diagnose</title></head><body style="max-width:1000px;margin:20px auto;padding:0 16px;background:#f8fafc">';
echo '<h1 style="font-family:Arial,sans-serif">Dokumentenlenkung – Diagnose</h1>';
echo '<p style="font-family:Arial,sans-serif">Diese Seite ändert keine Dokumentrevision und versendet keine E-Mail. Sie prüft nur PHP, Dateien, DB und Schema.</p>';

out('PHP-Version', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);

$files = [
    __DIR__ . '/../inc/session.php',
    __DIR__ . '/../api/_db.php',
    __DIR__ . '/controlled_documents_bootstrap.php',
    __DIR__ . '/controlled_documents_workflow.php',
    __DIR__ . '/dokumentenlenkung.php',
    __DIR__ . '/dokumentenfreigabe.php',
];
foreach ($files as $file) {
    out('Datei ' . basename($file), is_file($file), $file);
}

try {
    require_once __DIR__ . '/../inc/session.php';
    out('Session-Datei geladen', true, 'Session-ID: ' . session_id());
} catch (Throwable $e) {
    out('Session-Datei laden', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
}

try {
    require_once __DIR__ . '/../api/_db.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('$pdo ist nicht als PDO verfügbar.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    out('Datenbankverbindung', true, 'PDO-Treiber: ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
    out('Datenbankverbindung', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
}

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/controlled_documents_bootstrap.php';
        out('Bootstrap-Datei laden', true);
    } catch (Throwable $e) {
        out('Bootstrap-Datei laden', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    }

    if (function_exists('qcControlledEnsureTables')) {
        try {
            qcControlledEnsureTables($pdo);
            out('Phase-1-Tabellen prüfen/anlegen', true);
        } catch (Throwable $e) {
            out('Phase-1-Tabellen prüfen/anlegen', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        }
    }

    try {
        require_once __DIR__ . '/controlled_documents_workflow.php';
        out('Workflow-Datei laden', true);
    } catch (Throwable $e) {
        out('Workflow-Datei laden', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    }

    if (function_exists('qcWorkflowColumnExists')) {
        try {
            $exists = qcWorkflowColumnExists($pdo, 'qc_document_approvals', 'approval_token_hash');
            out('Spaltenprüfung qcWorkflowColumnExists()', true, 'approval_token_hash vorhanden: ' . ($exists ? 'ja' : 'nein'));
        } catch (Throwable $e) {
            out('Spaltenprüfung qcWorkflowColumnExists()', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        }
    }

    if (function_exists('qcWorkflowEnsureSchema')) {
        try {
            qcWorkflowEnsureSchema($pdo);
            out('Phase-2-Schema prüfen/anlegen', true);
        } catch (Throwable $e) {
            out('Phase-2-Schema prüfen/anlegen', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        }
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
        out('users-Tabelle lesen', true, 'Spalten: ' . implode(', ', array_map('strval', $cols)));
    } catch (Throwable $e) {
        out('users-Tabelle lesen', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    }

    if (function_exists('qcWorkflowActiveUsers')) {
        try {
            $users = qcWorkflowActiveUsers($pdo);
            out('Aktive Workbench-Benutzer laden', true, count($users) . ' Benutzer gefunden');
        } catch (Throwable $e) {
            out('Aktive Workbench-Benutzer laden', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        }
    }

    if (function_exists('qcControlledBootstrap')) {
        try {
            $summary = qcControlledBootstrap($pdo);
            out('Kompletter Dokumenten-Bootstrap', true, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (Throwable $e) {
            out('Kompletter Dokumenten-Bootstrap', false, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
        }
    }
}

echo '<p style="font-family:Arial,sans-serif;margin-top:20px"><b>Bitte kopiere mir den ersten roten FEHLER-Block.</b></p>';
echo '</body></html>';
