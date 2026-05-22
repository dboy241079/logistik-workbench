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
                wa.id,
                wa.ausgang_nr,
                wa.lieferschein,
                wa.lagergruppe,
                wa.empfaenger_code,
                wa.datum,
                wa.kennzeichen,
                wa.land,
                wa.spedition,
                wa.ankunft,
                wa.beginn,
                wa.ende,
                wa.behaelter,
                wa.zus_behaelter,
                wa.behaelternr,
                wa.sachnummer,
                wa.brt_gew,
                wa.gebucht,
                wa.gebucht_von,

                ko.id                    AS kommi_order_id,
                ko.order_no              AS kommi_order_no,
                ko.status                AS kommi_status,
                ko.assigned_picker       AS kommi_picker,
                ko.assigned_loader       AS kommi_loader,
                ko.picked_at             AS kommi_picked_at,
                ko.staged_at             AS kommi_staged_at,
                ko.loaded_at             AS kommi_loaded_at,
                ko.prepared_signed_at    AS kommi_prepared_signed_at,
                ko.loaded_signed_at      AS kommi_loaded_signed_at,

                CASE
                    WHEN ko.id IS NOT NULL THEN 1
                    ELSE 0
                END AS kommi_exists,

                CASE
                    WHEN ko.status = 'VERLADEN_OK'
                      OR ko.loaded_at IS NOT NULL
                      OR (
                           ko.prepared_signed_at IS NOT NULL
                           AND ko.loaded_signed_at IS NOT NULL
                         )
                    THEN 1
                    ELSE 0
                END AS kommi_done

            FROM warenausgang wa
            LEFT JOIN (
                SELECT k.*
                FROM kommi_orders k
                INNER JOIN (
                    SELECT source_ausgang_nr, MAX(id) AS max_id
                    FROM kommi_orders
                    GROUP BY source_ausgang_nr
                ) x
                    ON x.max_id = k.id
            ) ko
                ON ko.source_ausgang_nr = wa.ausgang_nr

            ORDER BY wa.id DESC
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
            'ausgang_nr',
            'lieferschein',
            'lagergruppe',
            'empfaenger_code',
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
            'brt_gew',
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
                    FROM warenausgang
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtOld->execute([':id' => $id]);
                $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    throw new RuntimeException('Warenausgang-Datensatz nicht gefunden.');
                }

                $sql = "
                    UPDATE warenausgang SET
                        ausgang_nr      = :ausgang_nr,
                        lieferschein    = :lieferschein,
                        lagergruppe     = :lagergruppe,
                        empfaenger_code = :empfaenger_code,
                        datum           = :datum,
                        kennzeichen     = :kennzeichen,
                        land            = :land,
                        spedition       = :spedition,
                        ankunft         = :ankunft,
                        beginn          = :beginn,
                        ende            = :ende,
                        behaelter       = :behaelter,
                        zus_behaelter   = :zus_behaelter,
                        behaelternr     = :behaelternr,
                        sachnummer      = :sachnummer,
                        brt_gew         = :brt_gew,
                        gebucht         = :gebucht,
                        gebucht_von     = :gebucht_von
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
                    'ausgang',
                    $user,
                    'warenausgang upsert ID ' . $id
                );

                $pdo->commit();

                echo json_encode(
                    ['ok' => true, 'row' => ['id' => $id]],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            $sql = "
                INSERT INTO warenausgang
                (
                    ausgang_nr,
                    lieferschein,
                    lagergruppe,
                    empfaenger_code,
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
                    brt_gew,
                    gebucht,
                    gebucht_von
                )
                VALUES
                (
                    :ausgang_nr,
                    :lieferschein,
                    :lagergruppe,
                    :empfaenger_code,
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
                    :brt_gew,
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
                'ausgang',
                $user,
                'warenausgang insert ID ' . $id
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
                FROM warenausgang
                WHERE id = :id
                LIMIT 1
            ");
            $stmtOld->execute([':id' => $id]);
            $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldRow) {
                throw new RuntimeException('Warenausgang-Datensatz nicht gefunden.');
            }

            $stmt = $pdo->prepare("DELETE FROM warenausgang WHERE id = :id");
            $stmt->execute([':id' => $id]);

            sync_leergut_bestand_from_bm_change(
                $pdo,
                $oldRow,
                null,
                'ausgang',
                $user,
                'warenausgang delete ID ' . $id
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