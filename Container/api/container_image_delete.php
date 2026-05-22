<?php
require_once __DIR__ . "/_bootstrap.php";

$code = strtoupper(get_str("container_code"));
$slot = (int)get_int("slot", 0);

if (!$code || ($slot !== 1 && $slot !== 2)) json_out(["ok"=>false,"msg"=>"container_code/slot fehlt"], 400);

$st = $pdo->prepare("SELECT file_path FROM container_images WHERE container_code=? AND slot=?");
$st->execute([$code,$slot]);
$path = $st->fetchColumn();

$st = $pdo->prepare("DELETE FROM container_images WHERE container_code=? AND slot=?");
$st->execute([$code,$slot]);

if ($path) {
  $fs = __DIR__ . "/.." . $path;
  if (is_file($fs)) @unlink($fs);
}

json_out(["ok"=>true]);
