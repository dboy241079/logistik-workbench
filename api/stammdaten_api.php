<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../inc/session.php';

$who = trim((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Unbekannt'));
if ($who === '') {
    $who = 'Unbekannt';
}

$role = $_SESSION['role'] ?? '';
$canEdit   = in_array($role, ['admin', 'disposition', 'standortleiter'], true);
$canDelete = ($role === 'admin');

$API_VERSION = 'stammdaten_v4_clean_2026-04-23';

$DB_HOST = 'db5020492258.hosting-data.io';
$DB_NAME = 'dbs15690997';
$DB_USER = 'dbu216810';
$DB_PASS = 'Mikesch241079!';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_connect_failed',
        'v' => $API_VERSION
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $data = []): void
{
    global $API_VERSION;
    echo json_encode(['ok' => true, 'v' => $API_VERSION] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function err(string $msg, int $code = 400): void
{
    global $API_VERSION;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg, 'v' => $API_VERSION], JSON_UNESCAPED_UNICODE);
    exit;
}

function isDuplicateError(mysqli_sql_exception $e, mysqli $mysqli): bool
{
    return ((int)$e->getCode() === 1062 || (int)$mysqli->errno === 1062);
}

function makeSachKey(string $s): string
{
    $s = trim($s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/[^A-Z0-9]/u', '', $s);
    return $s ?: '';
}

function nullIfEmpty(mixed $v): ?string
{
    $v = trim((string)$v);
    return ($v === '') ? null : $v;
}

function normalize_plates(mixed $raw): string
{
    if (is_array($raw)) {
        $arr = $raw;
    } else {
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }

        if (isset($s[0]) && $s[0] === '[') {
            $arr = json_decode($s, true);
            if (!is_array($arr)) {
                $arr = preg_split('/[,\n;]+/', $s);
            }
        } else {
            $arr = preg_split('/[,\n;]+/', $s);
        }
    }

    $seen = [];
    foreach ($arr as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }

        $p = mb_strtoupper($p, 'UTF-8');
        $p = preg_replace('/\s+/', ' ', $p);

        if (!preg_match('/^[A-ZÄÖÜ0-9\- ]{1,32}$/u', $p)) {
            continue;
        }

        $seen[$p] = true;
    }

    return implode(', ', array_keys($seen));
}

function normalize_status(mixed $status): string
{
    $status = trim(mb_strtolower((string)$status, 'UTF-8'));
    $allowed = ['aktiv', 'defekt', 'gesperrt'];
    return in_array($status, $allowed, true) ? $status : 'aktiv';
}

function normalize_einheit(mixed $einheit): string
{
    $einheit = trim(mb_strtoupper((string)$einheit, 'UTF-8'));
    $allowed = ['GB', 'PAL', 'STK', 'KLT'];
    return in_array($einheit, $allowed, true) ? $einheit : 'GB';
}

function normalize_int(mixed $value): int
{
    $n = (int)$value;
    return max(0, $n);
}

$type   = $_GET['type']   ?? $_POST['type']   ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$cfg = [
    'sachnummer' => ['table' => 'sachnummern', 'field' => 'sachnummer'],
    'behaelter'  => ['table' => 'behaelter',   'field' => 'nummer'],
    'spedition'  => ['table' => 'speditionen', 'field' => 'name'],
];

$LG_ALLOWED = ['W1', 'X3', 'X3(B)', 'G9', 'B1', 'B1(T)', 'Bauteile', 'BM', 'Sarajevo', 'Müll'];

if ($action !== 'stats_all' && !isset($cfg[$type])) {
    err('unknown_type', 400);
}

$table = $cfg[$type]['table'] ?? null;

/* =========================
   LIST
========================= */
if ($action === 'list') {
    $q = trim((string)($_GET['q'] ?? ''));

    if ($type === 'sachnummer') {
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    sachnummer,
                    sachnummer_key,
                    lagergruppe,
                    brt_gew,
                    behaelter_nr,
                    zus_behaelter,
                    updated_at,
                    updated_by
                FROM sachnummern
                WHERE sachnummer LIKE ? OR lagergruppe LIKE ?
                ORDER BY sachnummer ASC
                LIMIT 100
            ");
            $stmt->bind_param('ss', $like, $like);
        } else {
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    sachnummer,
                    sachnummer_key,
                    lagergruppe,
                    brt_gew,
                    behaelter_nr,
                    zus_behaelter,
                    updated_at,
                    updated_by
                FROM sachnummern
                ORDER BY sachnummer ASC
                LIMIT 500
            ");
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }

        ok(['items' => $items]);
    }

    if ($type === 'behaelter') {
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    nummer,
                    lagergruppe,
                    vw_kennung,
                    klts_pro_behaelter,
                    einheit,
                    status,
                    updated_at
                FROM behaelter
                WHERE nummer LIKE ?
                   OR lagergruppe LIKE ?
                   OR vw_kennung LIKE ?
                ORDER BY nummer ASC
                LIMIT 1000
            ");
            $stmt->bind_param('sss', $like, $like, $like);
        } else {
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    nummer,
                    lagergruppe,
                    vw_kennung,
                    klts_pro_behaelter,
                    einheit,
                    status,
                    updated_at
                FROM behaelter
                ORDER BY nummer ASC
            ");
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['klts_pro_behaelter'] = (int)($row['klts_pro_behaelter'] ?? 0);
            $items[] = $row;
        }

        ok(['items' => $items]);
    }

    if ($type === 'spedition') {
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    name,
                    plates,
                    updated_at
                FROM speditionen
                WHERE name LIKE ? OR plates LIKE ?
                ORDER BY name ASC, plates ASC
                LIMIT 1000
            ");
            $stmt->bind_param('ss', $like, $like);
        } else {
            $stmt = $mysqli->prepare("
                SELECT
                    id,
                    name,
                    plates,
                    updated_at
                FROM speditionen
                ORDER BY name ASC, plates ASC
            ");
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }

        ok(['items' => $items]);
    }
}

/* =========================
   CREATE SACHNUMMER
========================= */
if ($action === 'create' && $type === 'sachnummer') {
    if (!$canEdit) {
        err('forbidden', 403);
    }

    $sach = trim((string)($_POST['sachnummer'] ?? ''));
    $lg   = trim((string)($_POST['lagergruppe'] ?? ''));
    $key  = makeSachKey($sach);

    $brt = nullIfEmpty($_POST['brt_gew'] ?? null);
    $beh = nullIfEmpty($_POST['behaelter_nr'] ?? null);
    $zus = nullIfEmpty($_POST['zus_behaelter'] ?? null);

    if ($sach === '') {
        err('missing_field_sachnummer');
    }
    if ($key === '') {
        err('invalid_sachnummer_key');
    }
    if (!in_array($lg, $LG_ALLOWED, true)) {
        err('invalid_lagergruppe');
    }

    try {
        $stmt = $mysqli->prepare("
            INSERT INTO sachnummern
                (sachnummer, sachnummer_key, lagergruppe, brt_gew, behaelter_nr, zus_behaelter, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssssss', $sach, $key, $lg, $brt, $beh, $zus, $who, $who);
        $stmt->execute();

        ok([
            'id' => $mysqli->insert_id,
            'merged' => false
        ]);

    } catch (mysqli_sql_exception $e) {
        if (isDuplicateError($e, $mysqli)) {
            $sel = $mysqli->prepare("SELECT id FROM sachnummern WHERE sachnummer_key = ? LIMIT 1");
            $sel->bind_param('s', $key);
            $sel->execute();
            $res = $sel->get_result();
            $row = $res->fetch_assoc();
            $sel->close();

            if (!$row || empty($row['id'])) {
                err('duplicate_lookup_failed', 500);
            }

            $id = (int)$row['id'];

            $fields = [
                'sachnummer'     => $sach,
                'sachnummer_key' => $key,
                'lagergruppe'    => $lg,
                'updated_by'     => $who,
            ];

            if ($brt !== null) {
                $fields['brt_gew'] = $brt;
            }
            if ($beh !== null) {
                $fields['behaelter_nr'] = $beh;
            }
            if ($zus !== null) {
                $fields['zus_behaelter'] = $zus;
            }

            $setParts = [];
            $types = '';
            $vals = [];

            foreach ($fields as $col => $val) {
                $setParts[] = $col . '=?';
                $types .= 's';
                $vals[] = $val;
            }

            $types .= 'i';
            $vals[] = $id;

            $sql = "UPDATE sachnummern SET " . implode(', ', $setParts) . " WHERE id=?";
            $up = $mysqli->prepare($sql);
            $up->bind_param($types, ...$vals);
            $up->execute();

            ok([
                'id' => $id,
                'merged' => true
            ]);
        }

        err('insert_failed', 500);
    }
}

/* =========================
   CREATE BEHAELTER
========================= */
if ($action === 'create' && $type === 'behaelter') {
    if (!$canEdit) {
        err('forbidden', 403);
    }

    $nummer  = trim((string)($_POST['nummer'] ?? ''));
    $lg      = trim((string)($_POST['lagergruppe'] ?? ''));
    $vw      = trim((string)($_POST['vw_kennung'] ?? ''));
    $klts    = normalize_int($_POST['klts_pro_behaelter'] ?? 0);
    $einheit = normalize_einheit($_POST['einheit'] ?? 'GB');
    $status  = normalize_status($_POST['status'] ?? 'aktiv');

    if ($nummer === '') {
        err('missing_field_nummer');
    }
    if ($lg !== '' && !in_array($lg, $LG_ALLOWED, true)) {
        err('invalid_lagergruppe');
    }

    try {
        $stmt = $mysqli->prepare("
            INSERT INTO behaelter
                (nummer, lagergruppe, vw_kennung, klts_pro_behaelter, einheit, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssiss', $nummer, $lg, $vw, $klts, $einheit, $status);
        $stmt->execute();

        ok(['id' => $mysqli->insert_id]);

    } catch (mysqli_sql_exception $e) {
        if (isDuplicateError($e, $mysqli)) {
            err('duplicate', 409);
        }
        err('insert_failed', 500);
    }
}

/* =========================
   CREATE SPEDITION
========================= */
if ($action === 'create' && $type === 'spedition') {
    if (!$canEdit) {
        err('forbidden', 403);
    }

    $name   = trim((string)($_POST['name'] ?? ''));
    $plates = normalize_plates($_POST['plates'] ?? '');

    if ($name === '') {
        err('missing_name');
    }
    if ($plates === '') {
        err('missing_plates');
    }

    try {
        $stmt = $mysqli->prepare("
            INSERT INTO speditionen (name, plates)
            VALUES (?, ?)
        ");
        $stmt->bind_param('ss', $name, $plates);
        $stmt->execute();

        ok(['id' => $mysqli->insert_id]);

    } catch (mysqli_sql_exception $e) {
        if (isDuplicateError($e, $mysqli)) {
            err('duplicate_spedition_name_plates', 409);
        }
        err('insert_failed', 500);
    }
}

/* =========================
   UPDATE
========================= */
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        err('missing_id');
    }

    if (!$canEdit) {
        err('forbidden', 403);
    }

    if ($type === 'sachnummer') {
        $sach = trim((string)($_POST['sachnummer'] ?? ''));
        $lg   = trim((string)($_POST['lagergruppe'] ?? ''));
        $key  = makeSachKey($sach);

        $brt = nullIfEmpty($_POST['brt_gew'] ?? null);
        $beh = nullIfEmpty($_POST['behaelter_nr'] ?? null);
        $zus = nullIfEmpty($_POST['zus_behaelter'] ?? null);

        if ($sach === '') {
            err('missing_field_sachnummer');
        }
        if ($key === '') {
            err('invalid_sachnummer_key');
        }
        if (!in_array($lg, $LG_ALLOWED, true)) {
            err('invalid_lagergruppe');
        }

        try {
            $stmt = $mysqli->prepare("
                UPDATE sachnummern
                SET
                    sachnummer=?,
                    sachnummer_key=?,
                    lagergruppe=?,
                    brt_gew=?,
                    behaelter_nr=?,
                    zus_behaelter=?,
                    updated_by=?
                WHERE id=?
            ");
            $stmt->bind_param('sssssssi', $sach, $key, $lg, $brt, $beh, $zus, $who, $id);
            $stmt->execute();

            ok();

        } catch (mysqli_sql_exception $e) {
            if (isDuplicateError($e, $mysqli)) {
                err('duplicate', 409);
            }
            err('update_failed', 500);
        }
    }

    if ($type === 'behaelter') {
        $nummer  = trim((string)($_POST['nummer'] ?? ''));
        $lg      = trim((string)($_POST['lagergruppe'] ?? ''));
        $vw      = trim((string)($_POST['vw_kennung'] ?? ''));
        $klts    = normalize_int($_POST['klts_pro_behaelter'] ?? 0);
        $einheit = normalize_einheit($_POST['einheit'] ?? 'GB');
        $status  = normalize_status($_POST['status'] ?? 'aktiv');

        if ($nummer === '') {
            err('missing_field_nummer');
        }
        if ($lg !== '' && !in_array($lg, $LG_ALLOWED, true)) {
            err('invalid_lagergruppe');
        }

        try {
            $stmt = $mysqli->prepare("
                UPDATE behaelter
                SET
                    nummer=?,
                    lagergruppe=?,
                    vw_kennung=?,
                    klts_pro_behaelter=?,
                    einheit=?,
                    status=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param('sssissi', $nummer, $lg, $vw, $klts, $einheit, $status, $id);
            $stmt->execute();

            ok();

        } catch (mysqli_sql_exception $e) {
            if (isDuplicateError($e, $mysqli)) {
                err('duplicate', 409);
            }
            err('update_failed', 500);
        }
    }

    if ($type === 'spedition') {
        $name   = trim((string)($_POST['name'] ?? ''));
        $plates = normalize_plates($_POST['plates'] ?? '');

        if ($name === '') {
            err('missing_name');
        }
        if ($plates === '') {
            err('missing_plates');
        }

        try {
            $stmt = $mysqli->prepare("
                UPDATE speditionen
                SET
                    name=?,
                    plates=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param('ssi', $name, $plates, $id);
            $stmt->execute();

            ok();

        } catch (mysqli_sql_exception $e) {
            if (isDuplicateError($e, $mysqli)) {
                err('duplicate_spedition_name_plates', 409);
            }
            err('update_failed', 500);
        }
    }
}

/* =========================
   DELETE
========================= */
if ($action === 'delete') {
    if (!$canDelete) {
        err('forbidden', 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        err('missing_id');
    }

    $stmt = $mysqli->prepare("DELETE FROM {$table} WHERE id=?");
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        err('delete_failed', 500);
    }

    ok();
}

/* =========================
   STATS_ALL
========================= */
if ($action === 'stats_all') {
    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    $date = preg_replace('/[^0-9\-]/', '', $date);

    $queries = [
        'spedition'  => "SELECT COUNT(*) AS cnt FROM speditionen WHERE DATE(created_at) <= ?",
        'behaelter'  => "SELECT COUNT(*) AS cnt FROM behaelter WHERE DATE(created_at) <= ?",
        'sachnummer' => "SELECT COUNT(*) AS cnt FROM sachnummern WHERE DATE(created_at) <= ?",
    ];

    $counts = [];
    foreach ($queries as $key => $sql) {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $counts[$key] = (int)($row['cnt'] ?? 0);
    }

    ok([
        'stats' => [
            'spedition'  => $counts['spedition'],
            'behaelter'  => $counts['behaelter'],
            'sachnummer' => $counts['sachnummer'],
            'total'      => $counts['spedition'] + $counts['behaelter'] + $counts['sachnummer'],
        ]
    ]);
}

err('unknown_action', 400);