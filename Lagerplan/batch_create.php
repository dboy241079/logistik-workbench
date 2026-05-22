<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api/_db.php';

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Nur POST.');

  $title = trim($_POST['title'] ?? '');
  $source = trim($_POST['source'] ?? 'MANUELL');
  $expected = (int)($_POST['expected_count'] ?? 0);

  if ($title === '') $title = 'Vorgang-' . date('Ymd-His');
  if (!in_array($source, ['LIEFERUNG','UMP','MANUELL'], true)) $source = 'MANUELL';
  if ($expected < 0) $expected = 0;

  $user = $_SESSION['username'] ?? null;

  $stmt = $pdo->prepare("INSERT INTO lager_batches (title, source, expected_count, created_by) VALUES (?,?,?,?)");
  $stmt->execute([$title, $source, $expected, $user]);

  echo json_encode(['ok'=>true, 'batch_id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
}
