<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require dirname(__DIR__, 2) . '/api/_db.php';

date_default_timezone_set('Europe/Berlin');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'DB-Verbindung nicht verfügbar'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$today = date('Y-m-d');

try {
    $sql = "
        SELECT
            b.id,
            b.nummer,
            b.lagergruppe,
            b.vw_kennung,
            b.klts_pro_behaelter,
            b.einheit,
            b.status,

            COALESCE(lb.menge, 0) AS menge,
            COALESCE(lb.bemerkung, '') AS bemerkung,

            zt.menge AS zaehlung_heute,
            zl.letzte_zaehlung_am

        FROM behaelter b

        LEFT JOIN leergut_bestaende lb
            ON lb.behaelter_id = b.id

        LEFT JOIN leergut_zaehlungen zt
            ON zt.behaelter_id = b.id
           AND zt.datum = :today

        LEFT JOIN (
            SELECT
                behaelter_id,
                MAX(datum) AS letzte_zaehlung_am
            FROM leergut_zaehlungen
            GROUP BY behaelter_id
        ) zl
            ON zl.behaelter_id = b.id

        ORDER BY
            CASE
                WHEN b.nummer IS NULL OR b.nummer = '' THEN 1
                ELSE 0
            END,
            b.nummer ASC,
            b.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':today' => $today
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];

    foreach ($rows as $row) {
        $result[] = [
            'id' => (int)($row['id'] ?? 0),

            'nummer' => (string)($row['nummer'] ?? ''),
            'vw_kennung' => (string)($row['vw_kennung'] ?? ''),
            'klts_pro_behaelter' => (int)($row['klts_pro_behaelter'] ?? 0),

            'lagergruppe' => (string)($row['lagergruppe'] ?? ''),
            'einheit' => (string)($row['einheit'] ?? 'GB'),
            'status' => (string)($row['status'] ?? 'aktiv'),

            // aktueller Live-Bestand
            'menge' => (int)($row['menge'] ?? 0),
            'bemerkung' => (string)($row['bemerkung'] ?? ''),

            // Zusatzinfos
            'zaehlung_heute' => isset($row['zaehlung_heute']) ? (int)$row['zaehlung_heute'] : null,
            'letzte_zaehlung_am' => (string)($row['letzte_zaehlung_am'] ?? '')
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Fehler beim Laden des Leergut-Bestands',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}