<?php
require_once __DIR__.'/_bootstrap.php';

$q = trim($_GET['q'] ?? '');
if(strlen($q) < 1) json_out(["ok"=>true,"items"=>[]]);

$stmt = $pdo->prepare("
  SELECT container_code, pos, referenznr, sachnummer, lieferschein
  FROM container_pallets
  WHERE referenznr LIKE ? OR sachnummer LIKE ? OR lieferschein LIKE ?
  ORDER BY container_code, pos
  LIMIT 200
");
$like = "%".$q."%";
$stmt->execute([$like,$like,$like]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_out(["ok"=>true,"items"=>$items]);
