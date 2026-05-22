<?php
require_once __DIR__ . "/_bootstrap.php";

/**
 * Erwartet (POST bevorzugt, GET geht auch):
 * - code oder container_code
 * - referenznr
 * - sachnummer
 * Optional:
 * - lieferschein
 * - menge
 */

// Code robust lesen (beide Varianten akzeptieren)
$code  = strtoupper(get_str("code") ?: get_str("container_code"));
$ref   = get_str("referenznr");
$sach  = get_str("sachnummer");
$ls    = get_str("lieferschein");
$menge = max(1, get_int("menge", 1));

// Pflichtfelder
if (!$code || !$ref || !$sach) {
  json_out([
    "ok"    => false,
    "error" => "missing_fields",
    "msg"   => "code, referenznr, sachnummer sind Pflicht."
  ], 400);
}

// Container prüfen + Kapazität laden
$st = $pdo->prepare("SELECT capacity FROM container_master WHERE code = ?");
$st->execute([$code]);
$cap = (int)$st->fetchColumn();

if ($cap <= 0) {
  json_out([
    "ok"    => false,
    "error" => "not_found",
    "msg"   => "Container nicht gefunden: $code"
  ], 404);
}

// belegte Positionen laden (Spalte heißt bei dir: pos)
$st = $pdo->prepare("SELECT pos FROM container_pallets WHERE container_code = ? ORDER BY pos ASC");
$st->execute([$code]);
$used = $st->fetchAll(PDO::FETCH_COLUMN, 0);

$usedMap = [];
foreach ($used as $u) {
  $usedMap[(int)$u] = true;
}

// kleinste freie Position finden
$pos = 0;
for ($i = 1; $i <= $cap; $i++) {
  if (!isset($usedMap[$i])) { $pos = $i; break; }
}

if ($pos === 0) {
  json_out([
    "ok"    => false,
    "error" => "full",
    "msg"   => "Container $code ist voll ($cap/$cap)."
  ], 409);
}

// Insert
try {
  $st = $pdo->prepare("
    INSERT INTO container_pallets (container_code, pos, referenznr, sachnummer, lieferschein, menge)
    VALUES (:code, :pos, :ref, :sach, :ls, :menge)
  ");

  $st->execute([
    ":code"  => $code,
    ":pos"   => $pos,
    ":ref"   => $ref,
    ":sach"  => $sach,
    ":ls"    => ($ls !== "" ? $ls : null),
    ":menge" => $menge
  ]);

  json_out([
    "ok"  => true,
    "id"  => (int)$pdo->lastInsertId(),
    "pos" => $pos
  ]);

} catch (PDOException $e) {
  // Duplicate (z.B. unique auf referenznr)
  if ((int)($e->errorInfo[1] ?? 0) === 1062) {
    json_out([
      "ok"    => false,
      "error" => "duplicate_ref",
      "msg"   => "Referenz existiert bereits: $ref"
    ], 409);
  }

  json_out([
    "ok"    => false,
    "error" => "exception",
    "msg"   => $e->getMessage()
  ], 500);
}
