<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../api/_db.php'; // ggf. anpassen

$cap = 48;

$stmt = $pdo->prepare("
  INSERT INTO containers (code, capacity)
  VALUES (?, ?)
  ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)
");

for ($i=1; $i<=52; $i++){
  $code = "C" . str_pad($i, 2, "0", STR_PAD_LEFT);
  $stmt->execute([$code, $cap]);
}

echo json_encode(["ok"=>true, "seeded"=>52], JSON_UNESCAPED_UNICODE);
