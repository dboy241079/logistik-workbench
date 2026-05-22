<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__, 2) . '/api/_db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Datenbankverbindung nicht verfügbar.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeSachnummerKey(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    return preg_replace('/[^A-Z0-9]/u', '', $value) ?? '';
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 8);
$limit = max(1, min(15, $limit));

if ($q === '') {
    respond([
        'ok' => true,
        'items' => [],
        'exact_match' => false,
        'exact_item' => null,
    ]);
}

$key = normalizeSachnummerKey($q);

if ($key === '') {
    respond([
        'ok' => true,
        'items' => [],
        'exact_match' => false,
        'exact_item' => null,
    ]);
}

/* Exakter Treffer */
$sqlExact = "
    SELECT
        id,
        sachnummer,
        sachnummer_key,
        lagergruppe
    FROM sachnummern
    WHERE sachnummer_key = :key
       OR sachnummer = :raw
    ORDER BY sachnummer ASC
    LIMIT 1
";
$stmt = $pdo->prepare($sqlExact);
$stmt->execute([
    'key' => $key,
    'raw' => $q,
]);
$exactItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

/* Vorschläge */
$sqlSuggest = "
    SELECT
        id,
        sachnummer,
        sachnummer_key,
        lagergruppe
    FROM sachnummern
    WHERE sachnummer LIKE :likeRaw
       OR sachnummer_key LIKE :likeKey
    ORDER BY
        CASE
            WHEN sachnummer_key = :exactKey THEN 0
            WHEN sachnummer = :exactRaw THEN 1
            WHEN sachnummer_key LIKE :prefixKey THEN 2
            WHEN sachnummer LIKE :prefixRaw THEN 3
            ELSE 4
        END,
        sachnummer ASC
    LIMIT {$limit}
";

$stmt = $pdo->prepare($sqlSuggest);
$stmt->execute([
    'likeRaw'   => '%' . $q . '%',
    'likeKey'   => '%' . $key . '%',
    'exactKey'  => $key,
    'exactRaw'  => $q,
    'prefixKey' => $key . '%',
    'prefixRaw' => $q . '%',
]);

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

respond([
    'ok' => true,
    'items' => array_map(static function(array $row): array {
        return [
            'id'             => (int)$row['id'],
            'sachnummer'     => (string)$row['sachnummer'],
            'sachnummer_key' => (string)$row['sachnummer_key'],
            'lagergruppe'    => (string)($row['lagergruppe'] ?? ''),
        ];
    }, $items),
    'exact_match' => $exactItem !== null,
    'exact_item'  => $exactItem ? [
        'id'             => (int)$exactItem['id'],
        'sachnummer'     => (string)$exactItem['sachnummer'],
        'sachnummer_key' => (string)$exactItem['sachnummer_key'],
        'lagergruppe'    => (string)($exactItem['lagergruppe'] ?? ''),
    ] : null,
]);