<?php
require_once __DIR__.'/_bootstrap.php';

$id = (int)($_POST['id'] ?? 0);
if(!$id) json_out(["ok"=>false,"msg"=>"id fehlt"], 400);

$stmt = $pdo->prepare("DELETE FROM container_pallets WHERE id=?");
$stmt->execute([$id]);

json_out(["ok"=>true]);
