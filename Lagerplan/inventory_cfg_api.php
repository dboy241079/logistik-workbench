<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$path = dirname(__DIR__) . '/data/inventory_cfg.json'; // /LKW/data/inventory_cfg.json

$cfg = [
  'active' => false,
  'zones' => [],
  'updated_at' => null,
  'updated_by' => null,
];

if (is_file($path)) {
  $raw = @file_get_contents($path);
  $js  = json_decode((string)$raw, true);

  if (is_array($js)) {
    // merge ohne fancy syntax (robust)
    foreach ($js as $k => $v) {
      $cfg[$k] = $v;
    }
  }
}

$cfg['active'] = !empty($cfg['active']);
$cfg['zones']  = (isset($cfg['zones']) && is_array($cfg['zones'])) ? array_values($cfg['zones']) : [];

echo json_encode(
  [
    'ok' => true,
    'active' => $cfg['active'],
    'zones' => $cfg['zones'],
    'updated_at' => $cfg['updated_at'],
    'updated_by' => $cfg['updated_by'],
  ],
  JSON_UNESCAPED_UNICODE
);
