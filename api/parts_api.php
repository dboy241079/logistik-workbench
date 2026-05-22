<?php
// parts_api.php
header('Content-Type: application/json; charset=utf-8');

// === DB KONFIG ===
$DB_HOST = "localhost";
$DB_USER = "danielstruebig";
$DB_PASS = "Mikesch01!";
$DB_NAME = "danielstruebig_lkwfahrer";

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>"db_connect_failed"]);
  exit;
}
$mysqli->set_charset("utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

function json_ok($data = []) { echo json_encode(["ok"=>true] + $data); exit; }
function json_err($msg, $code = 400) { http_response_code($code); echo json_encode(["ok"=>false,"error"=>$msg]); exit; }

// --- LIST ---
if ($action === 'list') {
  $q = trim($_GET['q'] ?? '');
  if ($q !== '') {
    $like = "%".$q."%";
    $stmt = $mysqli->prepare("SELECT id,sachnummer,behaelternummer,spedition,created_at,updated_at 
                              FROM parts 
                              WHERE sachnummer LIKE ? OR behaelternummer LIKE ? OR spedition LIKE ?
                              ORDER BY sachnummer ASC LIMIT 1000");
    $stmt->bind_param("sss", $like, $like, $like);
  } else {
    $stmt = $mysqli->prepare("SELECT id,sachnummer,behaelternummer,spedition,created_at,updated_at 
                              FROM parts ORDER BY sachnummer ASC");
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  json_ok(["items"=>$rows]);
}

// --- CREATE ---
if ($action === 'create') {
  $sach = trim($_POST['sachnummer'] ?? '');
  $beh  = trim($_POST['behaelternummer'] ?? '');
  $sped = trim($_POST['spedition'] ?? '');
  if ($sach === '' || $beh === '' || $sped === '') json_err("missing_fields");

  $stmt = $mysqli->prepare("INSERT INTO parts (sachnummer, behaelternummer, spedition) VALUES (?,?,?)");
  if (!$stmt) json_err("prep_failed");
  $stmt->bind_param("sss", $sach, $beh, $sped);
  if (!$stmt->execute()) {
    if ($mysqli->errno === 1062) json_err("duplicate_sachnummer");
    json_err("insert_failed");
  }
  json_ok(["id"=>$mysqli->insert_id]);
}

// --- UPDATE ---
if ($action === 'update') {
  $id   = intval($_POST['id'] ?? 0);
  $sach = trim($_POST['sachnummer'] ?? '');
  $beh  = trim($_POST['behaelternummer'] ?? '');
  $sped = trim($_POST['spedition'] ?? '');
  if ($id<=0 || $sach === '' || $beh === '' || $sped === '') json_err("missing_fields");

  $stmt = $mysqli->prepare("UPDATE parts SET sachnummer=?, behaelternummer=?, spedition=? WHERE id=?");
  if (!$stmt) json_err("prep_failed");
  $stmt->bind_param("sssi", $sach, $beh, $sped, $id);
  if (!$stmt->execute()) {
    if ($mysqli->errno === 1062) json_err("duplicate_sachnummer");
    json_err("update_failed");
  }
  json_ok();
}

// --- DELETE ---
if ($action === 'delete') {
  $id = intval($_POST['id'] ?? 0);
  if ($id<=0) json_err("missing_id");
  $stmt = $mysqli->prepare("DELETE FROM parts WHERE id=?");
  $stmt->bind_param("i", $id);
  if (!$stmt->execute()) json_err("delete_failed");
  json_ok();
}

json_err("unknown_action", 404);
