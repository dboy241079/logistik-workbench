<?php
require_once __DIR__ . "/_bootstrap.php";

$code = strtoupper(get_str("container_code"));
$slot = (int)get_int("slot", 0);

if (!$code || !preg_match('/^C\d{2}$/', $code)) json_out(["ok"=>false,"msg"=>"Ungültiger Container-Code"], 400);
if ($slot !== 1 && $slot !== 2) json_out(["ok"=>false,"msg"=>"slot muss 1 oder 2 sein"], 400);
if (empty($_FILES["image"]) || $_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
  json_out(["ok"=>false,"msg"=>"Kein gültiges Bild hochgeladen"], 400);
}

$tmp  = $_FILES["image"]["tmp_name"];
$size = (int)$_FILES["image"]["size"];
$name = (string)($_FILES["image"]["name"] ?? "");

if ($size > 6 * 1024 * 1024) json_out(["ok"=>false,"msg"=>"Bild zu groß (max 6MB)"], 400);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($tmp) ?: "";
$allowed = ["image/jpeg"=>"jpg", "image/png"=>"png", "image/webp"=>"webp"];
if (!isset($allowed[$mime])) json_out(["ok"=>false,"msg"=>"Nur JPG/PNG/WEBP erlaubt"], 400);

$ext = $allowed[$mime];

// Zielordner
$dirFs = realpath(__DIR__ . "/../uploads/containers") ?: (__DIR__ . "/../uploads/containers");
$targetDir = $dirFs . "/" . $code;
if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

$filename = "slot{$slot}_" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . $ext;
$fsPath   = $targetDir . "/" . $filename;

// falls es schon ein Bild in dem Slot gibt -> altes File löschen
$st = $pdo->prepare("SELECT file_path FROM container_images WHERE container_code=? AND slot=?");
$st->execute([$code, $slot]);
$old = $st->fetchColumn();
if ($old) {
  $oldFs = __DIR__ . "/.." . $old; // weil file_path mit /LKW/... gespeichert wird
  if (is_file($oldFs)) @unlink($oldFs);
}

if (!move_uploaded_file($tmp, $fsPath)) {
  json_out(["ok"=>false,"msg"=>"Upload fehlgeschlagen (move_uploaded_file)"], 500);
}

$publicPath = "/LKW/Container/uploads/containers/{$code}/{$filename}";

$st = $pdo->prepare("
  INSERT INTO container_images (container_code, slot, file_path, original_name, mime, file_size)
  VALUES (:code,:slot,:path,:name,:mime,:size)
  ON DUPLICATE KEY UPDATE
    file_path=VALUES(file_path),
    original_name=VALUES(original_name),
    mime=VALUES(mime),
    file_size=VALUES(file_size),
    uploaded_at=CURRENT_TIMESTAMP
");
$st->execute([
  ":code"=>$code, ":slot"=>$slot, ":path"=>$publicPath,
  ":name"=>$name, ":mime"=>$mime, ":size"=>$size
]);

json_out(["ok"=>true, "container_code"=>$code, "slot"=>$slot, "url"=>$publicPath]);
