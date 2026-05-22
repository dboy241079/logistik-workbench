<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

echo "__DIR__ = " . __DIR__ . "\n\n";

$files = glob(__DIR__ . "/*.json");
if (!$files) {
  echo "Keine .json Dateien in diesem Ordner gefunden.\n";
  exit;
}

foreach ($files as $f) {
  echo basename($f) . "  |  " . filesize($f) . " bytes\n";
}
