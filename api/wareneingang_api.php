<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/_db.php';
    require_once __DIR__ . '/../inc/session.php';
    require_once __DIR__ . '/../inc/leergut_bestand_sync.php';

    date_default_timezone_set('Europe/Berlin');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $user   = (string)($_SESSION['username'] ?? 'unknown');

    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT
                id,
                eingang_nr,
                lieferschein,
                lagergruppe,
                datum,
                kennzeichen,
                land,
                spedition,
                ankunft,
                beginn,
                ende,
                behaelter,
                zus_behaelter,
                behaelternr,
                sachnummer,
                menge,
                gebucht,
                gebucht_von
            FROM wareneingang_old_20251215
            ORDER BY id DESC
        ");

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(
            ['ok' => true, 'items' => $items],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if ($action === 'upsert') {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];

        $fields = [
            'eingang_nr',
            'lieferschein',
            'lagergruppe',
            'datum',
            'kennzeichen',
            'land',
            'spedition',
            'ankunft',
            'beginn',
            'ende',
            'behaelter',
            'zus_behaelter',
            'behaelternr',
            'sachnummer',
            'menge',
            'gebucht',
            'gebucht_von'
        ];

        $data = [];
        foreach ($fields as $f) {
            $data[$f] = $payload[$f] ?? null;
        }

        $pdo->beginTransaction();

        try {
            if (!empty($payload['id'])) {
                $id = (int)$payload['id'];

                $stmtOld = $pdo->prepare("
                    SELECT
                        id,
                        lagergruppe,
                        behaelter,
                        zus_behaelter,
                        behaelternr
                    FROM wareneingang_old_20251215
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtOld->execute([':id' => $id]);
                $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    throw new RuntimeException('Wareneingang-Datensatz nicht gefunden.');
                }

                $sql = "
                    UPDATE wareneingang_old_20251215 SET
                        eingang_nr    = :eingang_nr,
                        lieferschein  = :lieferschein,
                        lagergruppe   = :lagergruppe,
                        datum         = :datum,
                        kennzeichen   = :kennzeichen,
                        land          = :land,
                        spedition     = :spedition,
                        ankunft       = :ankunft,
                        beginn        = :beginn,
                        ende          = :ende,
                        behaelter     = :behaelter,
                        zus_behaelter = :zus_behaelter,
                        behaelternr   = :behaelternr,
                        sachnummer    = :sachnummer,
                        menge         = :menge,
                        gebucht       = :gebucht,
                        gebucht_von   = :gebucht_von
                    WHERE id = :id
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($data + ['id' => $id]);

                $newRow = [
                    'lagergruppe'   => $data['lagergruppe'],
                    'behaelter'     => $data['behaelter'],
                    'zus_behaelter' => $data['zus_behaelter'],
                    'behaelternr'   => $data['behaelternr']
                ];

                sync_leergut_bestand_from_bm_change(
                    $pdo,
                    $oldRow,
                    $newRow,
                    'eingang',
                    $user,
                    'wareneingang upsert ID ' . $id
                );

                $pdo->commit();

                echo json_encode(
                    ['ok' => true, 'row' => ['id' => $id]],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            $sql = "
                INSERT INTO wareneingang_old_20251215
                (
                    eingang_nr,
                    lieferschein,
                    lagergruppe,
                    datum,
                    kennzeichen,
                    land,
                    spedition,
                    ankunft,
                    beginn,
                    ende,
                    behaelter,
                    zus_behaelter,
                    behaelternr,
                    sachnummer,
                    menge,
                    gebucht,
                    gebucht_von
                )
                VALUES
                (
                    :eingang_nr,
                    :lieferschein,
                    :lagergruppe,
                    :datum,
                    :kennzeichen,
                    :land,
                    :spedition,
                    :ankunft,
                    :beginn,
                    :ende,
                    :behaelter,
                    :zus_behaelter,
                    :behaelternr,
                    :sachnummer,
                    :menge,
                    :gebucht,
                    :gebucht_von
                )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);

            $id = (int)$pdo->lastInsertId();

            $newRow = [
                'lagergruppe'   => $data['lagergruppe'],
                'behaelter'     => $data['behaelter'],
                'zus_behaelter' => $data['zus_behaelter'],
                'behaelternr'   => $data['behaelternr']
            ];

            sync_leergut_bestand_from_bm_change(
                $pdo,
                null,
                $newRow,
                'eingang',
                $user,
                'wareneingang insert ID ' . $id
            );

            $pdo->commit();

            echo json_encode(
                ['ok' => true, 'row' => ['id' => $id]],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('invalid id');
        }

        $pdo->beginTransaction();

        try {
            $stmtOld = $pdo->prepare("
                SELECT
                    id,
                    lagergruppe,
                    behaelter,
                    zus_behaelter,
                    behaelternr
                FROM wareneingang_old_20251215
                WHERE id = :id
                LIMIT 1
            ");
            $stmtOld->execute([':id' => $id]);
            $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldRow) {
                throw new RuntimeException('Wareneingang-Datensatz nicht gefunden.');
            }

            $stmt = $pdo->prepare("DELETE FROM wareneingang_old_20251215 WHERE id = :id");
            $stmt->execute([':id' => $id]);

            sync_leergut_bestand_from_bm_change(
                $pdo,
                $oldRow,
                null,
                'eingang',
                $user,
                'wareneingang delete ID ' . $id
            );

            $pdo->commit();

            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE
    );
}