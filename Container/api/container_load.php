<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../api/_db.php'; // => /LKW/api/_db.php

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$code = strtoupper(trim($_GET['code'] ?? ''));
if ($code === '') out(['ok'=>false,'error'=>'bad_request','msg'=>'code fehlt']);

if (preg_match('/^C(\d{1,2})$/', $code, $m)) $code = 'C'.str_pad($m[1],2,'0',STR_PAD_LEFT);
if (!preg_match('/^C\d{2}$/', $code)) out(['ok'=>false,'error'=>'bad_request','msg'=>"Ungültiger Code: $code"]);

// 1) Container existiert? (master!)
$stmt = $pdo->prepare("SELECT code, capacity FROM container_master WHERE code=? LIMIT 1");
$stmt->execute([$code]);
$master = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$master) out(['ok'=>false,'error'=>'not_found','msg'=>"Container nicht gefunden: $code"]);

$capacity = (int)($master['capacity'] ?? 48);

// 2) Paletten laden (darf leer sein)
// 2) Paletten laden (darf leer sein)
$stmt = $pdo->prepare("
  SELECT id, pos, referenznr, sachnummer, lieferschein, menge
  FROM container_pallets
  WHERE container_code=?
  ORDER BY pos ASC
");
$stmt->execute([$code]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

out([
  'ok' => true,
  'code' => $code,
  'capacity' => $capacity,
  'items' => $items
]);
