<?php
declare(strict_types=1);

/**
 * /LKW/Container/api/_bootstrap.php
 * Gemeinsame Basis für alle Container-API Endpunkte.
 *
 * Ziele:
 * - Immer JSON ausgeben (auch bei Exceptions / Fatal Errors)
 * - DB sauber laden (aus /LKW/api/_db.php)
 * - Helper: get_str / get_int zentral
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');          // keine HTML-Errors in Response
ini_set('html_errors', '0');

// Standard-Header (optional; json_out setzt ihn ebenfalls)
header('Content-Type: application/json; charset=utf-8');

/* ----------------------------------------------------------
   JSON Output Helper (NUR EINMAL DEFINIEREN!)
---------------------------------------------------------- */
function json_out(array $data, int $status = 200): void
{
  http_response_code($status);
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ----------------------------------------------------------
   Exception Handler: alles als JSON zurückgeben
---------------------------------------------------------- */
set_exception_handler(function (Throwable $e): void {
  json_out([
    "ok"    => false,
    "error" => "exception",
    "msg"   => $e->getMessage(),
  ], 500);
});

/* ----------------------------------------------------------
   Fatal Error Handler (Parse/Compile/…): JSON statt HTML
---------------------------------------------------------- */
register_shutdown_function(function (): void {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array($err['type'], $fatalTypes, true)) return;

  // Wenn schon Output lief, können wir es nicht mehr sauber "reparieren"
  if (headers_sent()) return;

  json_out([
    "ok"    => false,
    "error" => "fatal",
    "msg"   => $err['message'] ?? 'Fatal error',
    "file"  => $err['file'] ?? '',
    "line"  => $err['line'] ?? 0,
  ], 500);
});

/* ----------------------------------------------------------
   Request Helper
---------------------------------------------------------- */
function get_str(string $key, string $default = ''): string
{
  $v = $_POST[$key] ?? $_GET[$key] ?? $default;
  return trim((string)$v);
}

function get_int(string $key, int $default = 0): int
{
  $v = $_POST[$key] ?? $_GET[$key] ?? $default;
  return (int)$v;
}

/* ----------------------------------------------------------
   DB laden: /LKW/Container/api -> /LKW/api/_db.php
   Erwartung: _db.php setzt $pdo (PDO)
---------------------------------------------------------- */
require_once dirname(__DIR__, 2) . "/api/_db.php";

$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);

if (!$pdo || !($pdo instanceof PDO)) {
  json_out([
    "ok"    => false,
    "error" => "no_pdo",
    "msg"   => "DB nicht verfügbar: \$pdo fehlt (prüfe /api/_db.php)."
  ], 500);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
