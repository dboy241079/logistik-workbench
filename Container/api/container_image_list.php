<?php
require_once __DIR__ . "/_bootstrap.php";

$code = strtoupper(get_str("container_code"));
if (!$code) json_out(["ok"=>false,"msg"=>"container_code fehlt"], 400);

$st = $pdo->prepare("SELECT slot, file_path AS url FROM container_images WHERE container_code=? ORDER BY slot");
$st->execute([$code]);

json_out(["ok"=>true, "items"=>$st->fetchAll(PDO::FETCH_ASSOC)]);
