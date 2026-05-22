<?php
define('AUTH_MODE','api');           // bei geschützten Endpoints
require __DIR__.'/_db.php';
require __DIR__.'/_auth.php';


header('Content-Type: text/plain; charset=utf-8');

function colExists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
  $stmt->execute([':c'=>$col]);
  return (bool)$stmt->fetch();
}

$table = 'driver_stamps';
$did = [];

try {
  if (!colExists($pdo, $table, 'created_at')) {
    $pdo->exec("ALTER TABLE `$table`
      ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $did[] = "added created_at";
  }
  if (!colExists($pdo, $table, 'updated_at')) {
    $pdo->exec("ALTER TABLE `$table`
      ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    $did[] = "added updated_at";
  }
  // optionaler Index:
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_date ON `$table` (date)");

  echo $did ? ("OK: ".implode(', ', $did)) : "OK: nothing to do";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: ".$e->getMessage();
}
