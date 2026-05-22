<?php
declare(strict_types=1);

require __DIR__ . '/../../inc/session.php';

require __DIR__ . '/../../api/_db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function out(bool $ok, array $data = []): void {
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function need(bool $cond, string $msg): void { if (!$cond) out(false, ['error' => $msg]); }

$username = (string)($_SESSION['username'] ?? '');
$role     = (string)($_SESSION['role'] ?? '');
need($username !== '', 'Nicht eingeloggt.');
need(in_array($role, ['admin','disposition'], true), 'Keine Berechtigung.');

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$ausgang = trim((string)($payload['ausgang_nr'] ?? $_GET['ausgang_nr'] ?? ''));
need($ausgang !== '', 'ausgang_nr fehlt.');

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // Schon vorhanden?
  $st = $pdo->prepare("SELECT id, order_no, status FROM kommi_orders WHERE source_ausgang_nr=? LIMIT 1");
  $st->execute([$ausgang]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $pdo->commit();
    out(true, [
      'existing' => true,
      'order_id' => (int)$row['id'],
      'order_no' => $row['order_no'],
      'status'   => $row['status']
    ]);
  }

  // Soll-Lines aus warenausgang:
  // -> Wir nehmen nur Zeilen, die NICHT final gebucht sind (gebucht != 'Ja')
  $sqlLines = "
    SELECT sachnummer AS sach, SUM(behaelter) AS qty
FROM warenausgang
WHERE ausgang_nr = ?
  AND sachnummer IS NOT NULL AND sachnummer <> ''
  AND behaelter > 0
GROUP BY sachnummer
HAVING SUM(behaelter) > 0

  ";
  $st = $pdo->prepare($sqlLines);
  $st->execute([$ausgang]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);
  // Debug-Counts (nur wenn nix gefunden wurde)
if (count($lines) === 0) {
  $dbg = [];

  $st = $pdo->prepare("SELECT COUNT(*) FROM warenausgang WHERE ausgang_nr=?");
  $st->execute([$ausgang]);
  $dbg['rows_total'] = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM warenausgang WHERE ausgang_nr=? AND sachnummer <> ''");
  $st->execute([$ausgang]);
  $dbg['rows_with_sach'] = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM warenausgang WHERE ausgang_nr=? AND behaelter > 0");
  $st->execute([$ausgang]);
  $dbg['rows_with_qty'] = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM warenausgang WHERE ausgang_nr=? AND gebucht = 'Ja'");
  $st->execute([$ausgang]);
  $dbg['rows_booked_ja'] = (int)$st->fetchColumn();

  $pdo->rollBack();
  out(false, [
    'error' => 'Keine offene Soll-Menge (Sachnummer/Behälter) für diese Ausgangsnummer gefunden.',
    'debug' => $dbg
  ]);
}

  need(count($lines) > 0, 'Keine offenen Soll-Mengen (Sachnummer/Behälter) für diese Ausgangsnummer gefunden.');

  // Auftrag anlegen
  $pdo->prepare("INSERT INTO kommi_orders (order_no, source_ausgang_nr, status, created_by) VALUES ('TMP', ?, 'OFFEN', ?)")
      ->execute([$ausgang, $username]);

  $orderId = (int)$pdo->lastInsertId();
  $orderNo = sprintf("KO-%s-%06d", date('Y'), $orderId);
  $pdo->prepare("UPDATE kommi_orders SET order_no=? WHERE id=?")->execute([$orderNo, $orderId]);

  $missing = [];
  $reservedTotal = 0;

  foreach ($lines as $ln) {
    $sach = trim((string)$ln['sach']);
    $qty  = (int)$ln['qty'];
    if ($sach === '' || $qty <= 0) continue;

    // Line speichern
    $pdo->prepare("INSERT INTO kommi_order_lines (order_id, sachnummer, qty_required) VALUES (?,?,?)")
        ->execute([$orderId, $sach, $qty]);

    // Paletten aus lager_slots holen, die NICHT reserviert sind (und nicht gelöscht)
    // Wichtig: referenznr ist bei dir die "Palette-ID"
    $pickSql = "
      SELECT
  ls.id,
  ls.halle, ls.zone, ls.reihe, ls.platz, ls.slot_index,
  ls.referenznr AS ref_no,
  ls.sachnummer
FROM lager_slots ls
LEFT JOIN kommi_reservations kr ON kr.slot_id = ls.id
WHERE ls.deleted_at IS NULL
  AND ls.sachnummer = ?
  AND ls.referenznr <> ''
  AND kr.id IS NULL
ORDER BY ls.zone, ls.reihe, ls.platz, ls.slot_index
LIMIT $qty
FOR UPDATE

    ";
    $pick = $pdo->prepare($pickSql);
    $pick->execute([$sach]);
    $pallets = $pick->fetchAll(PDO::FETCH_ASSOC);

    if (count($pallets) < $qty) {
      $missing[] = ['sachnummer' => $sach, 'need' => $qty, 'found' => count($pallets)];
      continue;
    }

    foreach ($pallets as $p) {
      $pdo->prepare("
  INSERT INTO kommi_reservations
    (order_id, slot_id, ref_no, sachnummer, halle, zone, reihe, platz, slot, reserved_by)
  VALUES
    (?,?,?,?,?,?,?,?,?,?)
")->execute([
  $orderId,
  (int)$p['id'],
  (string)$p['ref_no'],
  (string)$p['sachnummer'],
  $p['halle'] ?? null,
  $p['zone'] ?? null,
  $p['reihe'] ?? null,
  isset($p['platz']) ? (string)$p['platz'] : null,
  isset($p['slot_index']) ? (string)$p['slot_index'] : null,
  $username
]);


      $reservedTotal++;
    }

    // qty_reserved aktualisieren
    $pdo->prepare("UPDATE kommi_order_lines SET qty_reserved = qty_required WHERE order_id=? AND sachnummer=?")
        ->execute([$orderId, $sach]);
  }

  if ($missing) {
    $pdo->rollBack();
    out(false, [
      'error'   => 'Nicht genügend Paletten im Bestand (lager_slots) für alle Positionen.',
      'missing' => $missing
    ]);
  }

  $pdo->commit();
  out(true, ['order_id' => $orderId, 'order_no' => $orderNo, 'reserved' => $reservedTotal]);

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  out(false, ['error' => $e->getMessage()]);
}
