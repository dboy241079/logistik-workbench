<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';
require_once __DIR__ . '/../../inc/rbac.php';

function out(bool $ok, array $data = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // ✅ Diese API hängt bei dir im Sachnummern/Warenstamm-Tab (Kunden)
  // Wenn du sie lieber am Warenausgang koppeln willst -> 'outbound'
  rbac_require_tab_json($pdo, 'goods'); // oder: 'outbound'

  $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

  if ($action === 'list') {
    $stmt = $pdo->query("
      SELECT
        code, name,
        address1, address2,
        postal, city,
        country, note,
        updated_at
      FROM cmr_recipients
      ORDER BY code ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    out(true, ['items' => $items]);
  }

  if ($action === 'upsert') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];

    $origCode = trim((string)($payload['orig_code'] ?? ''));
    $code     = trim((string)($payload['code'] ?? ''));
    if ($code === '') out(false, ['error' => 'code fehlt'], 400);

    $data = [
      'code'     => $code,
      'name'     => trim((string)($payload['name'] ?? '')),
      'address1' => trim((string)($payload['address1'] ?? '')),
      'address2' => trim((string)($payload['address2'] ?? '')),
      'postal'   => trim((string)($payload['postal'] ?? '')),
      'city'     => trim((string)($payload['city'] ?? '')),
      'country'  => trim((string)($payload['country'] ?? 'Deutschland')),
      'note'     => trim((string)($payload['note'] ?? '')),
    ];

    if ($data['name'] === '' || $data['address1'] === '' || $data['postal'] === '' || $data['city'] === '' || $data['country'] === '') {
      out(false, ['error' => 'Bitte Name, Adresse1, PLZ, Ort, Land füllen.'], 400);
    }

    $pdo->beginTransaction();

    // Wenn orig_code gesetzt ist und anders als code: Rename/Update
    if ($origCode !== '' && $origCode !== $code) {
      $chk = $pdo->prepare("SELECT 1 FROM cmr_recipients WHERE code = :c LIMIT 1");
      $chk->execute([':c' => $origCode]);
      if (!$chk->fetchColumn()) {
        $pdo->rollBack();
        out(false, ['error' => 'orig_code nicht gefunden'], 404);
      }

      // Zielcode darf nicht schon existieren
      $chk2 = $pdo->prepare("SELECT 1 FROM cmr_recipients WHERE code = :c LIMIT 1");
      $chk2->execute([':c' => $code]);
      if ($chk2->fetchColumn()) {
        $pdo->rollBack();
        out(false, ['error' => 'code existiert bereits'], 409);
      }

      $sql = "UPDATE cmr_recipients SET
        code=:code, name=:name, address1=:address1, address2=:address2,
        postal=:postal, city=:city, country=:country, note=:note,
        updated_at=NOW()
      WHERE code=:orig";
      $st = $pdo->prepare($sql);
      $st->execute($data + [':orig' => $origCode]);

      $pdo->commit();
      out(true);
    }

    // Normal: upsert by code
    $chk = $pdo->prepare("SELECT 1 FROM cmr_recipients WHERE code=:code LIMIT 1");
    $chk->execute([':code' => $code]);
    $exists = (bool)$chk->fetchColumn();

    if ($exists) {
      $sql = "UPDATE cmr_recipients SET
        name=:name, address1=:address1, address2=:address2,
        postal=:postal, city=:city, country=:country, note=:note,
        updated_at=NOW()
      WHERE code=:code";
      $st = $pdo->prepare($sql);
      $st->execute($data);
    } else {
      $sql = "INSERT INTO cmr_recipients
        (code, name, address1, address2, postal, city, country, note, updated_at)
      VALUES
        (:code, :name, :address1, :address2, :postal, :city, :country, :note, NOW())";
      $st = $pdo->prepare($sql);
      $st->execute($data);
    }

    $pdo->commit();
    out(true);
  }

  if ($action === 'delete') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $code = trim((string)($payload['code'] ?? ''));
    if ($code === '') out(false, ['error' => 'code fehlt'], 400);

    $st = $pdo->prepare("DELETE FROM cmr_recipients WHERE code=:code");
    $st->execute([':code' => $code]);

    out(true);
  }

  out(false, ['error' => 'unknown action'], 404);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  out(false, ['error' => 'exception', 'msg' => $e->getMessage()], 500);
}
