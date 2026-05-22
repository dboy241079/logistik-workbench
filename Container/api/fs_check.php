<?php
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__;
$files = @scandir($dir) ?: [];

echo json_encode([
  "ok" => true,
  "dir" => $dir,
  "has_container_load" => file_exists($dir . "/container_load.php"),
  "has_container_add"  => file_exists($dir . "/container_add.php"),
  "files_preview" => array_values(array_filter($files, fn($f) => stripos($f, "container_") === 0)),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
