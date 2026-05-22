<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../inc/session.php';

// TODO: hier ggf. euren Admin-Check einbauen
if (!isset($_SESSION['username'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

function out(bool $ok, array $extra = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$path = dirname(__DIR__) . '/data/veh_cfg.json';


$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) out(false, ['error'=>'bad_json','msg'=>'Body ist kein JSON'], 400);

$cfg = $payload['cfg'] ?? null;
if (!is_array($cfg)) out(false, ['error'=>'missing_cfg','msg'=>'cfg fehlt'], 400);

$toursPerDay = (int)($cfg['toursPerDay'] ?? 4);
$vehicles = $cfg['vehicles'] ?? null;
if (!is_array($vehicles)) out(false, ['error'=>'missing_vehicles','msg'=>'vehicles fehlt'], 400);

// validieren + säubern
$seen = [];
$cleanVehicles = [];

foreach ($vehicles as $v) {
  if (!is_array($v)) continue;

  $id = trim((string)($v['id'] ?? ''));
  $title = trim((string)($v['title'] ?? ''));
  $plate = trim((string)($v['plate'] ?? ''));
  $driver = trim((string)($v['driver'] ?? ''));

  if ($id === '') out(false, ['error'=>'bad_vehicle','msg'=>'Vehicle ohne id'], 400);
  if (isset($seen[$id])) out(false, ['error'=>'duplicate_id','msg'=>"Doppelte id: {$id}"], 400);
  $seen[$id] = true;

  // optional: wenn du id-Format einschränken willst:
  // if (!preg_match('/^veh\d+$/', $id)) out(false, ['error'=>'bad_id','msg'=>"Ungültige id: {$id}"], 400);

  if ($title === '') $title = $plate !== '' ? $plate : $id;

  $cleanVehicles[] = [
    'id' => $id,
    'title' => $title,
    'plate' => $plate,
    'driver' => $driver,
  ];
}

$clean = [
  'toursPerDay' => $toursPerDay,
  'vehicles' => $cleanVehicles,
];

// Backup + atomar schreiben
if (is_file($path)) {
  @copy($path, $path . '.bak_' . date('Ymd_His'));
}

$tmp = $path . '.tmp';
$json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($json === false) out(false, ['error'=>'encode_failed','msg'=>'JSON encode fehlgeschlagen'], 500);
if (@file_put_contents($tmp, $json, LOCK_EX) === false) out(false, ['error'=>'write_failed','msg'=>'Konnte tmp nicht schreiben (Rechte?)'], 500);
if (!@rename($tmp, $path)) out(false, ['error'=>'rename_failed','msg'=>'Konnte tmp nicht ersetzen (Rechte?)'], 500);

out(true, ['saved'=>true]);
