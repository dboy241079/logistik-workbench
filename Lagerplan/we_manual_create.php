<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../api/_db.php'; // $pdo

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new RuntimeException('Nur POST ist erlaubt.');
  }

  $sachnummer   = trim($_POST['sachnummer'] ?? '');
  $behaelter    = (int)($_POST['behaelter'] ?? 1);
  $lieferschein = trim($_POST['lieferschein'] ?? 'UMP');
  $eingang_nr   = trim($_POST['eingang_nr'] ?? '');

  if ($sachnummer === '') {
    throw new RuntimeException('Sachnummer fehlt.');
  }
  if ($behaelter <= 0) $behaelter = 1;

  if ($eingang_nr === '') {
    // eindeutiger, lesbarer Standard
    $eingang_nr = 'MANUELL-' . date('Ymd-His');
  }

  $datum = date('Y-m-d');

  $stmt = $pdo->prepare("
    INSERT INTO wareneingang (eingang_nr, lieferschein, sachnummer, behaelter, datum)
    VALUES (:eingang_nr, :lieferschein, :sachnummer, :behaelter, :datum)
  ");
  $stmt->execute([
    ':eingang_nr'   => $eingang_nr,
    ':lieferschein' => $lieferschein,
    ':sachnummer'   => $sachnummer,
    ':behaelter'    => $behaelter,
    ':datum'        => $datum,
  ]);

  echo json_encode([
    'ok'       => true,
    'we_id'    => (int)$pdo->lastInsertId(),
    'eingang_nr'=> $eingang_nr
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'  => false,
    'msg' => $e->getMessage()
  ]);
}
