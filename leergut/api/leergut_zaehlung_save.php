<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__, 2) . '/inc/session.php';
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

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
        throw new RuntimeException('Ungültige Nutzdaten');
    }

    $today = date('Y-m-d');
    $user = (string)($_SESSION['username'] ?? 'unknown');

    $stmtBestand = $pdo->prepare("
        INSERT INTO leergut_bestaende
            (behaelter_id, menge, bemerkung, updated_by, updated_at)
        VALUES
            (:behaelter_id, :menge, :bemerkung, :user, NOW())
        ON DUPLICATE KEY UPDATE
            menge = VALUES(menge),
            bemerkung = VALUES(bemerkung),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");

    $stmtZaehlung = $pdo->prepare("
        INSERT INTO leergut_zaehlungen
            (datum, behaelter_id, menge, bemerkung, erstellt_von)
        VALUES
            (:datum, :behaelter_id, :menge, :bemerkung, :user)
        ON DUPLICATE KEY UPDATE
            menge = VALUES(menge),
            bemerkung = VALUES(bemerkung),
            erstellt_von = VALUES(erstellt_von)
    ");

    $pdo->beginTransaction();

    $saved = 0;

    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $menge = max(0, (int)($row['menge'] ?? 0));
        $bemerkung = trim((string)($row['bemerkung'] ?? ''));

        // aktuellen Live-Bestand setzen
        $stmtBestand->execute([
            ':behaelter_id' => $id,
            ':menge' => $menge,
            ':bemerkung' => $bemerkung,
            ':user' => $user
        ]);

        // Tageszählung protokollieren
        $stmtZaehlung->execute([
            ':datum' => $today,
            ':behaelter_id' => $id,
            ':menge' => $menge,
            ':bemerkung' => $bemerkung,
            ':user' => $user
        ]);

        $saved++;
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'ok',
        'saved' => $saved
    ], JSON_UNESCAPED_UNICODE);
} catch (JsonException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ungültiges JSON',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Fehler beim Speichern der Leergut-Zählung',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}