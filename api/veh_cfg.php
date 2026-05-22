<?php
// /LKW/api/veh_cfg.php
// Robust: legt /LKW/data bei Bedarf an, schreibt atomar, gibt nur JSON aus.

ini_set('display_errors', '0');       // keine PHP-Warnungen an den Client
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$DATA_DIR = dirname(__DIR__) . '/data';
$CFG_FILE = $DATA_DIR . '/veh_cfg.json';

// Ordner sicherstellen
if (!is_dir($DATA_DIR)) {
  if (!mkdir($DATA_DIR, 0775, true)) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Cannot create data directory: '.$DATA_DIR], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// ---- Helpers ----
function default_cfg() {
  return [
    'vehicles' => [
      ['id'=>'veh1','title'=>'Fahrzeug 1','plate'=>'','driver'=>''],
      ['id'=>'veh2','title'=>'Fahrzeug 2','plate'=>'','driver'=>''],
      ['id'=>'veh3','title'=>'Fahrzeug 3','plate'=>'','driver'=>''],
    ],
    'toursPerDay' => 4
  ];
}
function load_cfg($file) {
  if (!file_exists($file)) return default_cfg();
  $json = file_get_contents($file);
  $cfg = json_decode($json, true);
  if (!is_array($cfg)) $cfg = default_cfg();

  // Normalisieren
  if (!isset($cfg['toursPerDay']) || !is_numeric($cfg['toursPerDay'])) $cfg['toursPerDay'] = 4;
  if (!isset($cfg['vehicles']) || !is_array($cfg['vehicles'])) $cfg['vehicles'] = [];
  foreach ($cfg['vehicles'] as &$v) {
    $v['id']     = $v['id']     ?? '';
    $v['title']  = $v['title']  ?? '';
    $v['plate']  = $v['plate']  ?? '';
    $v['driver'] = $v['driver'] ?? '';
  }
  return $cfg;
}

// ---- Router ----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $cfg = load_cfg($CFG_FILE);
  echo json_encode(['ok'=>true, 'cfg'=>$cfg], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $toursPerDay = intval($in['toursPerDay'] ?? 4);
  if ($toursPerDay < 1 || $toursPerDay > 12) $toursPerDay = 4;

  $vehiclesIn = $in['vehicles'] ?? [];
  if (!is_array($vehiclesIn)) $vehiclesIn = [];

  $vehicles = [];
  $i = 1;
  foreach ($vehiclesIn as $v) {
    $id     = trim($v['id']     ?? '') ?: ('veh'.$i);
    $title  = trim($v['title']  ?? ('Fahrzeug '.$i));
    $plate  = trim($v['plate']  ?? '');
    $driver = trim($v['driver'] ?? '');
    $vehicles[] = ['id'=>$id, 'title'=>$title, 'plate'=>$plate, 'driver'=>$driver];
    $i++;
  }

  $cfg = ['toursPerDay'=>$toursPerDay, 'vehicles'=>$vehicles];

  // Atomar schreiben: erst .tmp, dann rename()
  $tmp = $CFG_FILE . '.tmp';
  $payload = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

  $bytes = @file_put_contents($tmp, $payload);
  if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Could not write temp file: '.$tmp], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!@rename($tmp, $CFG_FILE)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Could not move config file to '.$CFG_FILE], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Dateirechte (optional)
  @chmod($CFG_FILE, 0664);

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
  exit;
}

// Fallback
http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method not allowed'], JSON_UNESCAPED_UNICODE);
