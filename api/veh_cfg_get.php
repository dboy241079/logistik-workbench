<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../inc/session.php';

// TODO: hier ggf. euren Admin-Check einbauen (z.B. $_SESSION['is_admin'])
if (!isset($_SESSION['username'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$path = dirname(__DIR__) . '/data/veh_cfg.json'; // /LKW/data/veh_cfg.json


if (!is_file($path)) {
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'not_found','msg'=>'veh_cfg.json nicht gefunden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents($path);
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'invalid_json','msg'=>'veh_cfg.json ist kein gültiges JSON'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['ok'=>true,'cfg'=>$data], JSON_UNESCAPED_UNICODE);
