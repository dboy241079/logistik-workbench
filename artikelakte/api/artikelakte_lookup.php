<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require dirname(__DIR__, 2) . '/api/_db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
    'ok' => false,
    'error' => 'Datenbankverbindung nicht verfügbar.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function respond(array $data, int $status = 200): void
{
    http_response_code($status);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        echo json_encode([
            'ok' => false,
            'error' => 'JSON-Encode fehlgeschlagen: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    echo $json;
    exit;
}

function addIdentity(array &$set, ?string $value): void
{
    $value = trim((string)$value);
    if ($value !== '') {
        $set[$value] = true;
    }
}

function valuesOf(array $set): array
{
    return array_values(array_keys($set));
}

function buildInCondition(string $column, array $values, array &$params, string $prefix): ?string
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn($v) => trim($v) !== '')));
    if (!$values) {
        return null;
    }

    $placeholders = [];
    foreach ($values as $i => $value) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $params[$key] = $value;
    }

    return $column . ' IN (' . implode(',', $placeholders) . ')';
}

function parseTs(?string $value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    $ts = strtotime($value);
    return $ts === false ? 0 : $ts;
}

function formatDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return date('d.m.Y', $ts);
    }

    return date('d.m.Y H:i:s', $ts);
}

function combineDateTime(?string $date, ?string $time): ?string
{
    $date = trim((string)$date);
    $time = trim((string)$time);

    if ($date === '') {
        return null;
    }

    if ($time === '' || $time === '00:00:00') {
        return $date;
    }

    return $date . ' ' . $time;
}

function firstNotEmpty(array $rows, string $key): ?string
{
    foreach ($rows as $row) {
        $v = trim((string)($row[$key] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return null;
}

function addDateOnly(array &$target, ?string $rawDate): void
{
    $rawDate = trim((string)$rawDate);
    if ($rawDate === '') {
        return;
    }

    $ts = parseTs($rawDate);
    if ($ts <= 0) {
        return;
    }

    $target[] = [
        'raw' => $rawDate,
        'ts'  => $ts,
    ];
}

function addDatedValue(array &$target, ?string $rawDate, ?string $value): void
{
    $rawDate = trim((string)$rawDate);
    $value   = trim((string)$value);

    if ($rawDate === '' || $value === '') {
        return;
    }

    $ts = parseTs($rawDate);
    if ($ts <= 0) {
        return;
    }

    $target[] = [
        'raw'   => $rawDate,
        'ts'    => $ts,
        'value' => $value,
    ];
}

function pickEarliestDate(array $items): ?string
{
    if (!$items) {
        return null;
    }

    usort($items, static fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
    return formatDate($items[0]['raw'] ?? null);
}

function pickLatestDate(array $items): ?string
{
    if (!$items) {
        return null;
    }

    usort($items, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    return formatDate($items[0]['raw'] ?? null);
}

function pickLatestValue(array $items): ?string
{
    if (!$items) {
        return null;
    }

    usort($items, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    return $items[0]['value'] ?? null;
}

function buildSlotLocation(array $row): string
{
    $parts = [];

    $halle = trim((string)($row['halle'] ?? ''));
    $zone  = trim((string)($row['zone'] ?? ''));
    $reihe = trim((string)($row['reihe'] ?? ''));
    $platz = trim((string)($row['platz'] ?? ''));
    $slot  = trim((string)($row['slot_index'] ?? ''));

    if ($halle !== '') $parts[] = $halle;
    if ($zone !== '')  $parts[] = $zone;
    if ($reihe !== '') $parts[] = 'Reihe ' . $reihe;
    if ($platz !== '') $parts[] = 'Platz ' . $platz;
    if ($slot !== '')  $parts[] = 'Slot ' . $slot;

    return implode(' / ', $parts);
}
function normalizeSachnummerKey(string $value): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    return preg_replace('/[^A-Z0-9]/u', '', $value) ?? '';
}

function appendTimelineEvent(
    array &$timeline,
    string $typ,
    ?string $zeitpunktRaw,
    ?string $benutzer,
    ?string $quelle,
    ?string $status,
    ?string $lagerort,
    ?string $menge,
    ?string $beschreibung
): void {
    $zeitpunktRaw = trim((string)$zeitpunktRaw);
    if ($zeitpunktRaw === '') {
        return;
    }

    $timeline[] = [
        'typ'           => $typ,
        'zeitpunkt_raw' => $zeitpunktRaw,
        'zeitpunkt'     => formatDate($zeitpunktRaw),
        'benutzer'      => (string)$benutzer,
        'quelle'        => (string)$quelle,
        'status'        => (string)$status,
        'lagerort'      => (string)$lagerort,
        'menge'         => (string)$menge,
        'beschreibung'  => (string)$beschreibung,
    ];
}

function sortTimeline(array &$timeline): void
{
    usort($timeline, static function(array $a, array $b): int {
        return parseTs((string)($b['zeitpunkt_raw'] ?? '')) <=> parseTs((string)($a['zeitpunkt_raw'] ?? ''));
    });
}

$q = trim((string)($_GET['q'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? 'all'));
$limit = (int)($_GET['limit'] ?? 200);
$limit = max(1, min(500, $limit));
$searchKind = trim((string)($_GET['search_kind'] ?? ''));

/* ----------------------------------------------------------
 * EXAKTE SACHNUMMERN-SUCHE
 * Keine Aufweitung auf Lieferschein / Referenz / Ausgang
 * ---------------------------------------------------------- */
if ($searchKind === 'sachnummer_exact') {
    $stmt = $pdo->prepare("
        SELECT sachnummer
        FROM sachnummern
        WHERE sachnummer = :raw
           OR sachnummer_key = :key
        LIMIT 1
    ");
    $stmt->execute([
        'raw' => $q,
        'key' => normalizeSachnummerKey($q),
    ]);

    $masterSachnummer = $stmt->fetchColumn();
    $masterSachnummer = $masterSachnummer !== false ? trim((string)$masterSachnummer) : '';

    if ($masterSachnummer === '') {
        respond([
            'ok' => false,
            'error' => 'Die Sachnummer wurde in der Stammdaten-Tabelle nicht gefunden.'
        ], 422);
    }

    $matches = [];
    $timeline = [];
    $foundSources = [];
    $aktiveSlotsCount = 0;
$gesamtMengeAktiv = 0;

    /* 1) artikel_historie */
    if ($sourceFilter === 'all' || $sourceFilter === 'historie') {
        $sql = "
            SELECT
                id,
                sachnummer,
                referenznummer,
                lieferschein,
                ereignis_typ,
                zeitpunkt,
                benutzer,
                quelle_tabelle,
                quelle_id,
                status,
                lagerort,
                menge,
                beschreibung
            FROM artikel_historie
            WHERE sachnummer = :sach
            ORDER BY zeitpunkt DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sach' => $masterSachnummer]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Artikelhistorie';

            foreach ($rows as $row) {
                $typ = trim((string)$row['ereignis_typ']);
                $zeit = trim((string)$row['zeitpunkt']);

                $matches[] = [
    'quelle'         => 'lager_slots',
    'quelle_label'   => 'Lagerbestand',
    'typ'            => 'Einlagerung',
    'sachnummer'     => (string)$row['sachnummer'],
    'referenznummer' => (string)$row['referenznr'],
    'lieferschein'   => (string)$row['lieferschein'],
    'eingang_nr'     => '',
    'ausgang_nr'     => '',
    'order_no'       => '',
    'zeitpunkt_raw'  => $basisZeit,
    'zeitpunkt'      => formatDate($basisZeit),
    'geliefert_am'   => null,
    'eingelagert_am' => formatDate($eingelagertRaw),
    'ausgebucht_am'  => null,
    'verladen_am'    => null,
    'benutzer'       => $benutzer,
    'status'         => $status,
    'lagerort'       => $lagerort,
    'menge'          => $menge,
    'beschreibung'   => 'Artikel wurde eingelagert',
    'quelle_id'      => (string)$row['id'],
];

                appendTimelineEvent(
                    $timeline,
                    $typ !== '' ? $typ : 'Historie',
                    $zeit,
                    (string)$row['benutzer'],
                    'Artikelhistorie',
                    (string)$row['status'],
                    (string)$row['lagerort'],
                    (string)$row['menge'],
                    (string)$row['beschreibung']
                );
            }
        }
    }

    /* 2) wareneingang_old_20251215 */
    if ($sourceFilter === 'all' || $sourceFilter === 'wareneingang') {
        $sql = "
            SELECT
                id,
                eingang_nr,
                lieferschein,
                lagergruppe,
                datum,
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
            WHERE sachnummer = :sach
            ORDER BY datum DESC, id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sach' => $masterSachnummer]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Wareneingang Alt';

            foreach ($rows as $row) {
                $geliefertRaw = combineDateTime($row['datum'], $row['ankunft'])
                    ?: combineDateTime($row['datum'], $row['beginn'])
                    ?: trim((string)$row['datum']);

                $benutzer = trim((string)$row['gebucht_von']);
                $status   = trim((string)$row['gebucht']) !== '' ? (string)$row['gebucht'] : 'Geliefert';
                $lagerort = trim((string)$row['lagergruppe']);

                $menge = (int)$row['menge'] > 0
                    ? (string)$row['menge']
                    : ((int)$row['behaelter'] + (int)$row['zus_behaelter'] > 0
                        ? (string)((int)$row['behaelter'] + (int)$row['zus_behaelter'])
                        : '');

                $matches[] = [
    'quelle'         => 'wareneingang_old_20251215',
    'quelle_label'   => 'Wareneingang Alt',
    'typ'            => 'Lieferung',
    'sachnummer'     => (string)$row['sachnummer'],
    'referenznummer' => '',
    'lieferschein'   => (string)$row['lieferschein'],
    'eingang_nr'     => (string)$row['eingang_nr'],
    'ausgang_nr'     => '',
    'order_no'       => '',
    'zeitpunkt_raw'  => $geliefertRaw,
    'zeitpunkt'      => formatDate($geliefertRaw),
    'geliefert_am'   => formatDate($geliefertRaw),
    'eingelagert_am' => null,
    'ausgebucht_am'  => null,
    'verladen_am'    => null,
    'benutzer'       => $benutzer,
    'status'         => $status,
    'lagerort'       => $lagerort,
    'menge'          => $menge,
    'beschreibung'   => 'Artikel wurde im Wareneingang erfasst',
    'quelle_id'      => (string)$row['id'],
];

                appendTimelineEvent(
                    $timeline,
                    'Lieferung',
                    $geliefertRaw,
                    $benutzer,
                    'Wareneingang Alt',
                    $status,
                    $lagerort,
                    $menge,
                    'Artikel wurde im Wareneingang erfasst'
                );
            }
        }
    }

    /* 3) lager_slots */
    if ($sourceFilter === 'all' || $sourceFilter === 'lager') {
        $sql = "
            SELECT
                id,
                halle,
                zone,
                reihe,
                platz,
                slot_index,
                referenznr,
                sachnummer,
                lieferschein,
                eingelagert_am,
                user_name,
                created_at,
                updated_at,
                menge,
                deleted_at,
                deleted_by
            FROM lager_slots
            WHERE sachnummer = :sach
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sach' => $masterSachnummer]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Lagerbestand';

            foreach ($rows as $row) {
                $lagerort = buildSlotLocation($row);

                $eingelagertRaw = trim((string)$row['eingelagert_am']) !== ''
                    ? (string)$row['eingelagert_am']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : null);

                $basisZeit = trim((string)$row['updated_at']) !== ''
                    ? (string)$row['updated_at']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : $eingelagertRaw);

                $status   = trim((string)$row['deleted_at']) === '' ? 'Im Lager' : 'Aus Lager entfernt';
                $benutzer = trim((string)$row['user_name']);
                $menge    = (string)$row['menge'];
                $isAktiv = trim((string)$row['deleted_at']) === '';
if ($isAktiv) {
    $aktiveSlotsCount++;
    $gesamtMengeAktiv += (int)$row['menge'];
}

                $matches[] = [
                    'quelle'         => 'lager_slots',
                    'quelle_label'   => 'Lagerbestand',
                    'typ'            => 'Einlagerung',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => (string)$row['referenznr'],
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $basisZeit,
                    'zeitpunkt'      => formatDate($basisZeit),
                    'geliefert_am'   => null,
                    'eingelagert_am' => formatDate($eingelagertRaw),
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => $benutzer,
                    'status'         => $status,
                    'lagerort'       => $lagerort,
                    'menge'          => $menge,
                    'beschreibung'   => 'Artikel wurde eingelagert',
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    'Einlagerung',
                    $eingelagertRaw,
                    $benutzer,
                    'Lagerbestand',
                    $status,
                    $lagerort,
                    $menge,
                    'Artikel wurde eingelagert'
                );

                if (trim((string)$row['deleted_at']) !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Auslagerung',
                        (string)$row['deleted_at'],
                        (string)$row['deleted_by'],
                        'Lagerbestand',
                        'Aus Lager entfernt',
                        $lagerort,
                        $menge,
                        'Artikel wurde aus dem Lager-Slot entfernt'
                    );
                }
            }
        }
    }

    /* 4) warenausgang + kommi_orders */
    if ($sourceFilter === 'all' || $sourceFilter === 'warenausgang') {
        $sql = "
            SELECT
                wa.id AS wa_id,
                wa.ausgang_nr,
                wa.lieferschein,
                wa.lagergruppe,
                wa.datum,
                wa.ankunft,
                wa.beginn,
                wa.ende,
                wa.behaelter,
                wa.zus_behaelter,
                wa.behaelternr,
                wa.sachnummer,
                wa.gebucht,
                wa.gebucht_von,
                wa.created_at AS wa_created_at,
                wa.updated_at AS wa_updated_at,

                ko.id AS ko_id,
                ko.order_no,
                ko.source_ausgang_nr,
                ko.status AS ko_status,
                ko.priority,
                ko.exit_gate,
                ko.assigned_picker,
                ko.assigned_loader,
                ko.created_by,
                ko.created_at AS ko_created_at,
                ko.picked_at,
                ko.staged_at,
                ko.loaded_at,
                ko.note,
                ko.prepared_signed_at,
                ko.prepared_signature_name,
                ko.loaded_signed_at,
                ko.loaded_signature_name
            FROM warenausgang wa
            LEFT JOIN kommi_orders ko
                ON ko.source_ausgang_nr = wa.ausgang_nr
            WHERE wa.sachnummer = :sach
            ORDER BY COALESCE(ko.loaded_at, ko.staged_at, ko.picked_at, ko.created_at, wa.updated_at, wa.created_at) DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['sach' => $masterSachnummer]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Warenausgang / Kommi';

            foreach ($rows as $row) {
                $orderCreatedRaw = trim((string)$row['ko_created_at']);
                $pickedRaw       = trim((string)$row['picked_at']);
                $stagedRaw       = trim((string)$row['staged_at']);
                $loadedRaw       = trim((string)$row['loaded_at']);
                $loadedSignedRaw = trim((string)$row['loaded_signed_at']);

                $ausgebuchtRaw = $pickedRaw
                    ?: $stagedRaw
                    ?: $orderCreatedRaw
                    ?: trim((string)$row['wa_created_at']);

                $verladenRaw = $loadedRaw ?: $loadedSignedRaw;

                $lagerort = trim((string)$row['lagergruppe']);
                if ((string)$row['exit_gate'] !== '' && $row['exit_gate'] !== null) {
                    $lagerort = 'Tor ' . $row['exit_gate'] . ($lagerort !== '' ? ' / ' . $lagerort : '');
                }

                $mengeInt = (int)$row['behaelter'] + (int)$row['zus_behaelter'];
                $menge = $mengeInt > 0 ? (string)$mengeInt : '';

                $status = trim((string)$row['ko_status']);
                if ($status === '') {
                    if ($verladenRaw !== '') {
                        $status = 'VERLADEN';
                    } elseif ($stagedRaw !== '') {
                        $status = 'BEREITGESTELLT';
                    } elseif ($pickedRaw !== '') {
                        $status = 'KOMMISSIONIERT';
                    } elseif ($ausgebuchtRaw !== '') {
                        $status = 'AUSGEBUCHT';
                    } else {
                        $status = 'OFFEN';
                    }
                }

                $letzterBenutzer =
                    trim((string)$row['loaded_signature_name']) ?:
                    trim((string)$row['assigned_loader']) ?:
                    trim((string)$row['prepared_signature_name']) ?:
                    trim((string)$row['assigned_picker']) ?:
                    trim((string)$row['gebucht_von']) ?:
                    trim((string)$row['created_by']);

                $matches[] = [
    'quelle'         => 'kommi_orders',
    'quelle_label'   => 'Warenausgang / Kommi',
    'typ'            => $status,
    'sachnummer'     => (string)$row['sachnummer'],
    'referenznummer' => '',
    'lieferschein'   => (string)$row['lieferschein'],
    'eingang_nr'     => '',
    'ausgang_nr'     => (string)$row['ausgang_nr'],
    'order_no'       => (string)$row['order_no'],
    'zeitpunkt_raw'  => $verladenRaw ?: $ausgebuchtRaw,
    'zeitpunkt'      => formatDate($verladenRaw ?: $ausgebuchtRaw),
    'geliefert_am'   => null,
    'eingelagert_am' => null,
    'ausgebucht_am'  => formatDate($ausgebuchtRaw),
    'verladen_am'    => formatDate($verladenRaw),
    'benutzer'       => $letzterBenutzer,
    'status'         => $status,
    'lagerort'       => $lagerort,
    'menge'          => $menge,
    'beschreibung'   => 'Ausgangsprozess über Kommi-Auftrag',
    'quelle_id'      => (string)($row['ko_id'] ?: $row['wa_id']),
];

                if ($orderCreatedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Kommi-Auftrag erstellt',
                        $orderCreatedRaw,
                        (string)$row['created_by'],
                        'Kommi',
                        'OFFEN',
                        $lagerort,
                        $menge,
                        'Kommi-Auftrag wurde erstellt'
                    );
                }

                if ($pickedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Ausbuchung / Kommissionierung',
                        $pickedRaw,
                        (string)($row['assigned_picker'] ?: $row['created_by']),
                        'Kommi',
                        'KOMMISSIONIERT',
                        $lagerort,
                        $menge,
                        'Artikel wurde kommissioniert / ausgebucht'
                    );
                }

                if ($stagedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Bereitstellung',
                        $stagedRaw,
                        (string)($row['assigned_picker'] ?: $row['prepared_signature_name'] ?: $row['created_by']),
                        'Kommi',
                        'BEREITGESTELLT',
                        $lagerort,
                        $menge,
                        'Artikel wurde bereitgestellt'
                    );
                }

                if ($loadedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Verladung',
                        $loadedRaw,
                        (string)($row['assigned_loader'] ?: $row['loaded_signature_name'] ?: $row['created_by']),
                        'Kommi',
                        'VERLADEN',
                        $lagerort,
                        $menge,
                        'Artikel wurde verladen'
                    );
                }
            }
        }
    }

    sortTimeline($timeline);

    respond([
        'ok' => true,
        'query' => $q,
        'search_mode' => 'exact_sachnummer',
        'found_sources' => array_values(array_unique($foundSources)),
        'overview' => [
            'sachnummer'           => $masterSachnummer,
            'referenznummer'       => null,
            'status'               => 'Sammelansicht Sachnummer',
            'bearbeitet_von'       => null,
            'geliefert_am'         => null,
            'eingelagert_am'       => null,
            'ausgebucht_am'        => null,
            'verladen_am'          => null,
            'letzte_bewegung'      => null,
            'aktueller_lagerplatz' => null,
            'letzter_bearbeiter'   => null,
            'letzter_ort'          => null,
            'treffer_gesamt'       => count($matches),
        ],
        'matches' => $matches,
        'timeline' => $timeline,
    ]);
}

if ($q === '') {
    respond([
        'ok' => false,
        'error' => 'Suchbegriff fehlt.'
    ], 422);
}

$like = '%' . $q . '%';

/* ----------------------------------------------------------
 * Prüfen: ist die Eingabe eine echte Referenznummer?
 * ---------------------------------------------------------- */
$isStrictReferenceSearch = false;
$strictReferenceNumbers = [];

$stmt = $pdo->prepare("
    SELECT DISTINCT referenznr
    FROM lager_slots
    WHERE referenznr = :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $q]);
$strictRefRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($strictRefRows) {
    $isStrictReferenceSearch = true;
    foreach ($strictRefRows as $row) {
        $ref = trim((string)($row['referenznr'] ?? ''));
        if ($ref !== '') {
            $strictReferenceNumbers[$ref] = true;
        }
    }
}

$stmt = $pdo->prepare("
    SELECT DISTINCT referenznummer
    FROM artikel_historie
    WHERE referenznummer = :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $q]);
$strictHistRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($strictHistRows) {
    $isStrictReferenceSearch = true;
    foreach ($strictHistRows as $row) {
        $ref = trim((string)($row['referenznummer'] ?? ''));
        if ($ref !== '') {
            $strictReferenceNumbers[$ref] = true;
        }
    }
}

$strictReferenceNumbers = valuesOf($strictReferenceNumbers);

$ident = [
    'sachnummer'     => [],
    'referenznummer' => [],
    'lieferschein'   => [],
    'ausgang_nr'     => [],
    'order_no'       => [],
];

$matches = [];
$timeline = [];
$foundSources = [];

$lieferungDates   = [];
$einlagerungDates = [];
$ausbuchungDates  = [];
$verladungDates   = [];

$bearbeiterValues = [];
$lagerortValues   = [];
$aktiveLagerorte  = [];

/* ----------------------------------------------------------
 * STRICT REFERENCE MODE
 * Nur referenzgenaue Historie, keine Aufweitung
 * ---------------------------------------------------------- */
if ($isStrictReferenceSearch) {
    if ($sourceFilter === 'all' || $sourceFilter === 'lager') {
        $params = [];
        $cond = buildInCondition('referenznr', $strictReferenceNumbers, $params, 'sr');

        $sql = "
            SELECT
                id,
                halle,
                zone,
                reihe,
                platz,
                slot_index,
                referenznr,
                sachnummer,
                lieferschein,
                eingelagert_am,
                user_name,
                created_at,
                updated_at,
                menge,
                deleted_at,
                deleted_by
            FROM lager_slots
            WHERE {$cond}
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Lagerbestand';

            foreach ($rows as $row) {
                $lagerort = buildSlotLocation($row);

                $eingelagertRaw = trim((string)$row['eingelagert_am']) !== ''
                    ? (string)$row['eingelagert_am']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : null);

                $basisZeit = trim((string)$row['updated_at']) !== ''
                    ? (string)$row['updated_at']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : $eingelagertRaw);

                $status   = trim((string)$row['deleted_at']) === '' ? 'Im Lager' : 'Aus Lager entfernt';
                $benutzer = trim((string)$row['user_name']);
                $menge    = (string)$row['menge'];

                $matches[] = [
                    'quelle'         => 'lager_slots',
                    'quelle_label'   => 'Lagerbestand',
                    'typ'            => 'Einlagerung',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => (string)$row['referenznr'],
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $basisZeit,
                    'zeitpunkt'      => formatDate($basisZeit),
                    'geliefert_am'   => null,
                    'eingelagert_am' => formatDate($eingelagertRaw),
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => $benutzer,
                    'status'         => $status,
                    'lagerort'       => $lagerort,
                    'menge'          => $menge,
                    'beschreibung'   => 'Artikel wurde eingelagert',
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    'Einlagerung',
                    $eingelagertRaw,
                    $benutzer,
                    'Lagerbestand',
                    $status,
                    $lagerort,
                    $menge,
                    'Artikel wurde eingelagert'
                );

                if (trim((string)$row['deleted_at']) !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Auslagerung',
                        (string)$row['deleted_at'],
                        (string)$row['deleted_by'],
                        'Lagerbestand',
                        'Aus Lager entfernt',
                        $lagerort,
                        $menge,
                        'Artikel wurde aus dem Lager-Slot entfernt'
                    );
                }

                addDateOnly($einlagerungDates, $eingelagertRaw);
                addDatedValue($bearbeiterValues, $basisZeit, $benutzer);
                addDatedValue($lagerortValues, $basisZeit, $lagerort);

                if (trim((string)$row['deleted_at']) === '') {
                    addDatedValue($aktiveLagerorte, $basisZeit, $lagerort);
                }
            }
        }
    }

    if ($sourceFilter === 'all' || $sourceFilter === 'historie') {
        $params = [];
        $cond = buildInCondition('referenznummer', $strictReferenceNumbers, $params, 'hr');

        $sql = "
            SELECT
                id,
                sachnummer,
                referenznummer,
                lieferschein,
                ereignis_typ,
                zeitpunkt,
                benutzer,
                quelle_tabelle,
                quelle_id,
                status,
                lagerort,
                menge,
                beschreibung
            FROM artikel_historie
            WHERE {$cond}
            ORDER BY zeitpunkt DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Artikelhistorie';

            foreach ($rows as $row) {
                $typ = trim((string)$row['ereignis_typ']);
                $zeit = trim((string)$row['zeitpunkt']);

                $matches[] = [
                    'quelle'         => 'artikel_historie',
                    'quelle_label'   => 'Artikelhistorie',
                    'typ'            => $typ !== '' ? $typ : 'Historie',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => (string)$row['referenznummer'],
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $zeit,
                    'zeitpunkt'      => formatDate($zeit),
                    'geliefert_am'   => null,
                    'eingelagert_am' => null,
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => (string)$row['benutzer'],
                    'status'         => (string)$row['status'],
                    'lagerort'       => (string)$row['lagerort'],
                    'menge'          => (string)$row['menge'],
                    'beschreibung'   => (string)$row['beschreibung'],
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    $typ !== '' ? $typ : 'Historie',
                    $zeit,
                    (string)$row['benutzer'],
                    'Artikelhistorie',
                    (string)$row['status'],
                    (string)$row['lagerort'],
                    (string)$row['menge'],
                    (string)$row['beschreibung']
                );

                $typLower = mb_strtolower($typ);
                if (str_contains($typLower, 'liefer') || str_contains($typLower, 'wareneingang')) {
                    addDateOnly($lieferungDates, $zeit);
                }
                if (str_contains($typLower, 'einlager') || str_contains($typLower, 'lager')) {
                    addDateOnly($einlagerungDates, $zeit);
                }
                if (str_contains($typLower, 'ausbuch')) {
                    addDateOnly($ausbuchungDates, $zeit);
                }
                if (str_contains($typLower, 'verlad')) {
                    addDateOnly($verladungDates, $zeit);
                }

                addDatedValue($bearbeiterValues, $zeit, (string)$row['benutzer']);
                addDatedValue($lagerortValues, $zeit, (string)$row['lagerort']);
            }
        }
    }

    sortTimeline($timeline);

    $referenznummer = firstNotEmpty($matches, 'referenznummer');
    $sachnummer     = firstNotEmpty($matches, 'sachnummer');

    $geliefertAm    = pickEarliestDate($lieferungDates);
    $eingelagertAm  = pickEarliestDate($einlagerungDates);
    $ausgebuchtAm   = pickLatestDate($ausbuchungDates);
    $verladenAm     = pickLatestDate($verladungDates);

    $bearbeitetVon  = pickLatestValue($bearbeiterValues);
    $letzterOrt     = pickLatestValue($lagerortValues);
    $aktuellerOrt   = pickLatestValue($aktiveLagerorte) ?: $letzterOrt;
    $letzteBewegung = isset($timeline[0]) ? formatDate($timeline[0]['zeitpunkt_raw'] ?? null) : null;

    $status = 'Referenznummer gefunden';
    if ($verladenAm) {
        $status = 'Verladen';
    } elseif ($ausgebuchtAm) {
        $status = 'Ausgebucht';
    } elseif ($aktuellerOrt) {
        $status = 'Im Lager';
    } elseif ($eingelagertAm) {
        $status = 'Eingelagert';
    } elseif ($geliefertAm) {
        $status = 'Geliefert';
    }

    respond([
    'ok' => true,
    'query' => $q,
    'search_mode' => 'strict_reference',
    'found_sources' => array_values(array_unique($foundSources)),
    'overview' => [
        'sachnummer'           => $sachnummer,
        'referenznummer'       => $referenznummer,
        'status'               => $status,
        'bearbeitet_von'       => $bearbeitetVon,
        'geliefert_am'         => $geliefertAm,
        'eingelagert_am'       => $eingelagertAm,
        'ausgebucht_am'        => $ausgebuchtAm,
        'verladen_am'          => $verladenAm,
        'letzte_bewegung'      => $letzteBewegung,
        'aktueller_lagerplatz' => $aktuellerOrt,
        'letzter_bearbeiter'   => $bearbeitetVon,
        'letzter_ort'          => $aktuellerOrt,
        'treffer_gesamt'       => count($matches),
    ],
    'matches' => $matches,
    'timeline' => $timeline,
]);
}

/* ----------------------------------------------------------
 * NORMALER MODUS
 * ---------------------------------------------------------- */

/* lager_slots: hier steckt die referenznr */
$stmt = $pdo->prepare("
    SELECT referenznr, sachnummer, lieferschein
    FROM lager_slots
    WHERE sachnummer LIKE :q
       OR referenznr LIKE :q
       OR lieferschein LIKE :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    addIdentity($ident['referenznummer'], $row['referenznr'] ?? null);
    addIdentity($ident['sachnummer'], $row['sachnummer'] ?? null);
    addIdentity($ident['lieferschein'], $row['lieferschein'] ?? null);
}

/* wareneingang_alt */
$stmt = $pdo->prepare("
    SELECT sachnummer, lieferschein
    FROM wareneingang_old_20251215
    WHERE sachnummer LIKE :q
       OR lieferschein LIKE :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    addIdentity($ident['sachnummer'], $row['sachnummer'] ?? null);
    addIdentity($ident['lieferschein'], $row['lieferschein'] ?? null);
}

/* warenausgang */
$stmt = $pdo->prepare("
    SELECT ausgang_nr, sachnummer, lieferschein
    FROM warenausgang
    WHERE ausgang_nr LIKE :q
       OR sachnummer LIKE :q
       OR lieferschein LIKE :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    addIdentity($ident['ausgang_nr'], $row['ausgang_nr'] ?? null);
    addIdentity($ident['sachnummer'], $row['sachnummer'] ?? null);
    addIdentity($ident['lieferschein'], $row['lieferschein'] ?? null);
}

/* artikel_historie */
$stmt = $pdo->prepare("
    SELECT sachnummer, referenznummer, lieferschein
    FROM artikel_historie
    WHERE sachnummer LIKE :q
       OR referenznummer LIKE :q
       OR lieferschein LIKE :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    addIdentity($ident['sachnummer'], $row['sachnummer'] ?? null);
    addIdentity($ident['referenznummer'], $row['referenznummer'] ?? null);
    addIdentity($ident['lieferschein'], $row['lieferschein'] ?? null);
}

/* kommi_orders */
$stmt = $pdo->prepare("
    SELECT order_no, source_ausgang_nr
    FROM kommi_orders
    WHERE order_no LIKE :q
       OR source_ausgang_nr LIKE :q
    LIMIT {$limit}
");
$stmt->execute(['q' => $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    addIdentity($ident['order_no'], $row['order_no'] ?? null);
    addIdentity($ident['ausgang_nr'], $row['source_ausgang_nr'] ?? null);
}

$ausgangIds = valuesOf($ident['ausgang_nr']);
if ($ausgangIds) {
    $params = [];
    $cond = buildInCondition('ausgang_nr', $ausgangIds, $params, 'a');
    $sql = "
        SELECT ausgang_nr, sachnummer, lieferschein
        FROM warenausgang
        WHERE {$cond}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        addIdentity($ident['ausgang_nr'], $row['ausgang_nr'] ?? null);
        addIdentity($ident['sachnummer'], $row['sachnummer'] ?? null);
        addIdentity($ident['lieferschein'], $row['lieferschein'] ?? null);
    }
}

$sachnummern     = valuesOf($ident['sachnummer']);
$referenznummern = valuesOf($ident['referenznummer']);
$lieferscheine   = valuesOf($ident['lieferschein']);
$ausgangNrn      = valuesOf($ident['ausgang_nr']);
$orderNos        = valuesOf($ident['order_no']);

if (!$sachnummern && !$referenznummern && !$lieferscheine && !$ausgangNrn && !$orderNos) {
    respond([
        'ok' => true,
        'query' => $q,
        'search_mode' => 'normal',
        'found_sources' => [],
        'overview' => [
            'sachnummer'           => null,
            'referenznummer'       => null,
            'status'               => 'Nicht gefunden',
            'bearbeitet_von'       => null,
            'geliefert_am'         => null,
            'eingelagert_am'       => null,
            'ausgebucht_am'        => null,
            'verladen_am'          => null,
            'letzte_bewegung'      => null,
            'aktueller_lagerplatz' => null,
            'letzter_bearbeiter'   => null,
            'letzter_ort'          => null,
            'treffer_gesamt'       => 0,
        ],
        'matches' => [],
        'timeline' => [],
    ]);
}

/* artikel_historie */
if ($sourceFilter === 'all' || $sourceFilter === 'historie') {
    $params = [];
    $where = [];

    if ($sachnummern) {
        $where[] = buildInCondition('sachnummer', $sachnummern, $params, 'hs');
    }
    if ($referenznummern) {
        $where[] = buildInCondition('referenznummer', $referenznummern, $params, 'hr');
    }
    if ($lieferscheine) {
        $where[] = buildInCondition('lieferschein', $lieferscheine, $params, 'hl');
    }

    if ($where) {
        $sql = "
            SELECT
                id,
                sachnummer,
                referenznummer,
                lieferschein,
                ereignis_typ,
                zeitpunkt,
                benutzer,
                quelle_tabelle,
                quelle_id,
                status,
                lagerort,
                menge,
                beschreibung
            FROM artikel_historie
            WHERE " . implode(' OR ', $where) . "
            ORDER BY zeitpunkt DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Artikelhistorie';

            foreach ($rows as $row) {
                $typ = trim((string)$row['ereignis_typ']);
                $zeit = trim((string)$row['zeitpunkt']);

                $matches[] = [
                    'quelle'         => 'artikel_historie',
                    'quelle_label'   => 'Artikelhistorie',
                    'typ'            => $typ !== '' ? $typ : 'Historie',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => (string)$row['referenznummer'],
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $zeit,
                    'zeitpunkt'      => formatDate($zeit),
                    'geliefert_am'   => null,
                    'eingelagert_am' => null,
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => (string)$row['benutzer'],
                    'status'         => (string)$row['status'],
                    'lagerort'       => (string)$row['lagerort'],
                    'menge'          => (string)$row['menge'],
                    'beschreibung'   => (string)$row['beschreibung'],
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    $typ !== '' ? $typ : 'Historie',
                    $zeit,
                    (string)$row['benutzer'],
                    'Artikelhistorie',
                    (string)$row['status'],
                    (string)$row['lagerort'],
                    (string)$row['menge'],
                    (string)$row['beschreibung']
                );

                $typLower = mb_strtolower($typ);
                if (str_contains($typLower, 'liefer') || str_contains($typLower, 'wareneingang')) {
                    addDateOnly($lieferungDates, $zeit);
                }
                if (str_contains($typLower, 'einlager') || str_contains($typLower, 'lager')) {
                    addDateOnly($einlagerungDates, $zeit);
                }
                if (str_contains($typLower, 'ausbuch')) {
                    addDateOnly($ausbuchungDates, $zeit);
                }
                if (str_contains($typLower, 'verlad')) {
                    addDateOnly($verladungDates, $zeit);
                }

                addDatedValue($bearbeiterValues, $zeit, (string)$row['benutzer']);
                addDatedValue($lagerortValues, $zeit, (string)$row['lagerort']);
            }
        }
    }
}

/* wareneingang_old_20251215 */
if ($sourceFilter === 'all' || $sourceFilter === 'wareneingang') {
    $params = [];
    $where = [];

    if ($sachnummern) {
        $where[] = buildInCondition('sachnummer', $sachnummern, $params, 'ws');
    }
    if ($lieferscheine) {
        $where[] = buildInCondition('lieferschein', $lieferscheine, $params, 'wl');
    }

    if ($where) {
        $sql = "
            SELECT
                id,
                eingang_nr,
                lieferschein,
                lagergruppe,
                datum,
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
            WHERE " . implode(' OR ', $where) . "
            ORDER BY datum DESC, id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Wareneingang Alt';

            foreach ($rows as $row) {
                $geliefertRaw = combineDateTime($row['datum'], $row['ankunft'])
                    ?: combineDateTime($row['datum'], $row['beginn'])
                    ?: trim((string)$row['datum']);

                $benutzer = trim((string)$row['gebucht_von']);
                $status   = trim((string)$row['gebucht']) !== '' ? (string)$row['gebucht'] : 'Geliefert';
                $lagerort = trim((string)$row['lagergruppe']);

                $menge = (int)$row['menge'] > 0
                    ? (string)$row['menge']
                    : ((int)$row['behaelter'] + (int)$row['zus_behaelter'] > 0
                        ? (string)((int)$row['behaelter'] + (int)$row['zus_behaelter'])
                        : '');

                $matches[] = [
                    'quelle'         => 'wareneingang_old_20251215',
                    'quelle_label'   => 'Wareneingang Alt',
                    'typ'            => 'Lieferung',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => '',
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $geliefertRaw,
                    'zeitpunkt'      => formatDate($geliefertRaw),
                    'geliefert_am'   => formatDate($geliefertRaw),
                    'eingelagert_am' => null,
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => $benutzer,
                    'status'         => $status,
                    'lagerort'       => $lagerort,
                    'menge'          => $menge,
                    'beschreibung'   => 'Artikel wurde im Wareneingang erfasst',
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    'Lieferung',
                    $geliefertRaw,
                    $benutzer,
                    'Wareneingang Alt',
                    $status,
                    $lagerort,
                    $menge,
                    'Artikel wurde im Wareneingang erfasst'
                );

                addDateOnly($lieferungDates, $geliefertRaw);
                addDatedValue($bearbeiterValues, $geliefertRaw, $benutzer);
                addDatedValue($lagerortValues, $geliefertRaw, $lagerort);
            }
        }
    }
}

/* lager_slots */
if ($sourceFilter === 'all' || $sourceFilter === 'lager') {
    $params = [];
    $where = [];

    if ($sachnummern) {
        $where[] = buildInCondition('sachnummer', $sachnummern, $params, 'ls');
    }
    if ($referenznummern) {
        $where[] = buildInCondition('referenznr', $referenznummern, $params, 'lr');
    }
    if ($lieferscheine) {
        $where[] = buildInCondition('lieferschein', $lieferscheine, $params, 'll');
    }

    if ($where) {
        $sql = "
            SELECT
                id,
                halle,
                zone,
                reihe,
                platz,
                slot_index,
                referenznr,
                sachnummer,
                lieferschein,
                eingelagert_am,
                user_name,
                created_at,
                updated_at,
                menge,
                deleted_at,
                deleted_by
            FROM lager_slots
            WHERE " . implode(' OR ', $where) . "
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Lagerbestand';

            foreach ($rows as $row) {
                $lagerort = buildSlotLocation($row);

                $eingelagertRaw = trim((string)$row['eingelagert_am']) !== ''
                    ? (string)$row['eingelagert_am']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : null);

                $basisZeit = trim((string)$row['updated_at']) !== ''
                    ? (string)$row['updated_at']
                    : (trim((string)$row['created_at']) !== '' ? (string)$row['created_at'] : $eingelagertRaw);

                $status   = trim((string)$row['deleted_at']) === '' ? 'Im Lager' : 'Aus Lager entfernt';
                $benutzer = trim((string)$row['user_name']);
                $menge    = (string)$row['menge'];

                $matches[] = [
                    'quelle'         => 'lager_slots',
                    'quelle_label'   => 'Lagerbestand',
                    'typ'            => 'Einlagerung',
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => (string)$row['referenznr'],
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $basisZeit,
                    'zeitpunkt'      => formatDate($basisZeit),
                    'geliefert_am'   => null,
                    'eingelagert_am' => formatDate($eingelagertRaw),
                    'ausgebucht_am'  => null,
                    'verladen_am'    => null,
                    'benutzer'       => $benutzer,
                    'status'         => $status,
                    'lagerort'       => $lagerort,
                    'menge'          => $menge,
                    'beschreibung'   => 'Artikel wurde eingelagert',
                    'quelle_id'      => (string)$row['id'],
                ];

                appendTimelineEvent(
                    $timeline,
                    'Einlagerung',
                    $eingelagertRaw,
                    $benutzer,
                    'Lagerbestand',
                    $status,
                    $lagerort,
                    $menge,
                    'Artikel wurde eingelagert'
                );

                if (trim((string)$row['deleted_at']) !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Auslagerung',
                        (string)$row['deleted_at'],
                        (string)$row['deleted_by'],
                        'Lagerbestand',
                        'Aus Lager entfernt',
                        $lagerort,
                        $menge,
                        'Artikel wurde aus dem Lager-Slot entfernt'
                    );
                }

                addDateOnly($einlagerungDates, $eingelagertRaw);
                addDatedValue($bearbeiterValues, $basisZeit, $benutzer);
                addDatedValue($lagerortValues, $basisZeit, $lagerort);

                if (trim((string)$row['deleted_at']) === '') {
                    addDatedValue($aktiveLagerorte, $basisZeit, $lagerort);
                }
            }
        }
    }
}

/* warenausgang + kommi_orders */
if ($sourceFilter === 'all' || $sourceFilter === 'warenausgang') {
    $params = [];
    $where = [];

    if ($sachnummern) {
        $where[] = buildInCondition('wa.sachnummer', $sachnummern, $params, 'os');
    }
    if ($lieferscheine) {
        $where[] = buildInCondition('wa.lieferschein', $lieferscheine, $params, 'ol');
    }
    if ($ausgangNrn) {
        $where[] = buildInCondition('wa.ausgang_nr', $ausgangNrn, $params, 'oa');
        $where[] = buildInCondition('ko.source_ausgang_nr', $ausgangNrn, $params, 'ob');
    }
    if ($orderNos) {
        $where[] = buildInCondition('ko.order_no', $orderNos, $params, 'oo');
    }

    if ($where) {
        $sql = "
            SELECT
                wa.id AS wa_id,
                wa.ausgang_nr,
                wa.lieferschein,
                wa.lagergruppe,
                wa.datum,
                wa.ankunft,
                wa.beginn,
                wa.ende,
                wa.behaelter,
                wa.zus_behaelter,
                wa.behaelternr,
                wa.sachnummer,
                wa.gebucht,
                wa.gebucht_von,
                wa.created_at AS wa_created_at,
                wa.updated_at AS wa_updated_at,

                ko.id AS ko_id,
                ko.order_no,
                ko.source_ausgang_nr,
                ko.status AS ko_status,
                ko.priority,
                ko.exit_gate,
                ko.assigned_picker,
                ko.assigned_loader,
                ko.created_by,
                ko.created_at AS ko_created_at,
                ko.picked_at,
                ko.staged_at,
                ko.loaded_at,
                ko.note,
                ko.prepared_signed_at,
                ko.prepared_signature_name,
                ko.loaded_signed_at,
                ko.loaded_signature_name
            FROM warenausgang wa
            LEFT JOIN kommi_orders ko
                ON ko.source_ausgang_nr = wa.ausgang_nr
            WHERE " . implode(' OR ', $where) . "
            ORDER BY COALESCE(ko.loaded_at, ko.staged_at, ko.picked_at, ko.created_at, wa.updated_at, wa.created_at) DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $foundSources[] = 'Warenausgang / Kommi';

            foreach ($rows as $row) {
                $orderCreatedRaw = trim((string)$row['ko_created_at']);
                $pickedRaw       = trim((string)$row['picked_at']);
                $stagedRaw       = trim((string)$row['staged_at']);
                $loadedRaw       = trim((string)$row['loaded_at']);
                $loadedSignedRaw = trim((string)$row['loaded_signed_at']);

                $ausgebuchtRaw = $pickedRaw
                    ?: $stagedRaw
                    ?: $orderCreatedRaw
                    ?: trim((string)$row['wa_created_at']);

                $verladenRaw = $loadedRaw ?: $loadedSignedRaw;

                $lagerort = trim((string)$row['lagergruppe']);
                if ((string)$row['exit_gate'] !== '' && $row['exit_gate'] !== null) {
                    $lagerort = 'Tor ' . $row['exit_gate'] . ($lagerort !== '' ? ' / ' . $lagerort : '');
                }

                $mengeInt = (int)$row['behaelter'] + (int)$row['zus_behaelter'];
                $menge = $mengeInt > 0 ? (string)$mengeInt : '';

                $status = trim((string)$row['ko_status']);
                if ($status === '') {
                    if ($verladenRaw !== '') {
                        $status = 'VERLADEN';
                    } elseif ($stagedRaw !== '') {
                        $status = 'BEREITGESTELLT';
                    } elseif ($pickedRaw !== '') {
                        $status = 'KOMMISSIONIERT';
                    } elseif ($ausgebuchtRaw !== '') {
                        $status = 'AUSGEBUCHT';
                    } else {
                        $status = 'OFFEN';
                    }
                }

                $letzterBenutzer =
                    trim((string)$row['loaded_signature_name']) ?:
                    trim((string)$row['assigned_loader']) ?:
                    trim((string)$row['prepared_signature_name']) ?:
                    trim((string)$row['assigned_picker']) ?:
                    trim((string)$row['gebucht_von']) ?:
                    trim((string)$row['created_by']);

                $matches[] = [
                    'quelle'         => 'kommi_orders',
                    'quelle_label'   => 'Warenausgang / Kommi',
                    'typ'            => $status,
                    'sachnummer'     => (string)$row['sachnummer'],
                    'referenznummer' => '',
                    'lieferschein'   => (string)$row['lieferschein'],
                    'zeitpunkt_raw'  => $verladenRaw ?: $ausgebuchtRaw,
                    'zeitpunkt'      => formatDate($verladenRaw ?: $ausgebuchtRaw),
                    'geliefert_am'   => null,
                    'eingelagert_am' => null,
                    'ausgebucht_am'  => formatDate($ausgebuchtRaw),
                    'verladen_am'    => formatDate($verladenRaw),
                    'benutzer'       => $letzterBenutzer,
                    'status'         => $status,
                    'lagerort'       => $lagerort,
                    'menge'          => $menge,
                    'beschreibung'   => 'Ausgangsprozess über Kommi-Auftrag',
                    'quelle_id'      => (string)($row['ko_id'] ?: $row['wa_id']),
                ];

                if ($orderCreatedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Kommi-Auftrag erstellt',
                        $orderCreatedRaw,
                        (string)$row['created_by'],
                        'Kommi',
                        'OFFEN',
                        $lagerort,
                        $menge,
                        'Kommi-Auftrag wurde erstellt'
                    );
                }

                if ($pickedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Ausbuchung / Kommissionierung',
                        $pickedRaw,
                        (string)($row['assigned_picker'] ?: $row['created_by']),
                        'Kommi',
                        'KOMMISSIONIERT',
                        $lagerort,
                        $menge,
                        'Artikel wurde kommissioniert / ausgebucht'
                    );
                }

                if ($stagedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Bereitstellung',
                        $stagedRaw,
                        (string)($row['assigned_picker'] ?: $row['prepared_signature_name'] ?: $row['created_by']),
                        'Kommi',
                        'BEREITGESTELLT',
                        $lagerort,
                        $menge,
                        'Artikel wurde bereitgestellt'
                    );
                }

                if (trim((string)$row['prepared_signed_at']) !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Bereitstellung signiert',
                        (string)$row['prepared_signed_at'],
                        (string)$row['prepared_signature_name'],
                        'Kommi',
                        'SIGNIERT',
                        $lagerort,
                        $menge,
                        'Bereitstellung wurde signiert'
                    );
                }

                if ($loadedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Verladung',
                        $loadedRaw,
                        (string)($row['assigned_loader'] ?: $row['loaded_signature_name'] ?: $row['created_by']),
                        'Kommi',
                        'VERLADEN',
                        $lagerort,
                        $menge,
                        'Artikel wurde verladen'
                    );
                }

                if ($loadedSignedRaw !== '') {
                    appendTimelineEvent(
                        $timeline,
                        'Verladung signiert',
                        $loadedSignedRaw,
                        (string)$row['loaded_signature_name'],
                        'Kommi',
                        'SIGNIERT',
                        $lagerort,
                        $menge,
                        'Verladung wurde signiert'
                    );
                }

                addDateOnly($ausbuchungDates, $ausgebuchtRaw);
                addDateOnly($verladungDates, $verladenRaw);

                addDatedValue($bearbeiterValues, $verladenRaw ?: $ausgebuchtRaw, $letzterBenutzer);
                addDatedValue($lagerortValues, $verladenRaw ?: $ausgebuchtRaw, $lagerort);
            }
        }
    }
}

sortTimeline($timeline);

$sachnummer     = firstNotEmpty($matches, 'sachnummer');
$referenznummer = firstNotEmpty($matches, 'referenznummer');

$geliefertAm    = pickEarliestDate($lieferungDates);
$eingelagertAm  = pickEarliestDate($einlagerungDates);
$ausgebuchtAm   = pickLatestDate($ausbuchungDates);
$verladenAm     = pickLatestDate($verladungDates);

$bearbeitetVon  = pickLatestValue($bearbeiterValues);
$letzterOrt     = pickLatestValue($lagerortValues);
$aktuellerOrt   = pickLatestValue($aktiveLagerorte) ?: $letzterOrt;
$letzteBewegung = isset($timeline[0]) ? formatDate($timeline[0]['zeitpunkt_raw'] ?? null) : null;

$status = 'Unbekannt';
if ($verladenAm) {
    $status = 'Verladen';
} elseif ($ausgebuchtAm) {
    $status = 'Ausgebucht / Kommissioniert';
} elseif ($aktuellerOrt) {
    $status = 'Im Lager';
} elseif ($eingelagertAm) {
    $status = 'Eingelagert';
} elseif ($geliefertAm) {
    $status = 'Geliefert';
}

respond([
    'ok' => true,
    'query' => $q,
    'search_mode' => 'normal',
    'found_sources' => array_values(array_unique($foundSources)),
    'overview' => [
        'sachnummer'           => $sachnummer,
        'referenznummer'       => $referenznummer,
        'status'               => $status,
        'bearbeitet_von'       => $bearbeitetVon,
        'geliefert_am'         => $geliefertAm,
        'eingelagert_am'       => $eingelagertAm,
        'ausgebucht_am'        => $ausgebuchtAm,
        'verladen_am'          => $verladenAm,
        'letzte_bewegung'      => $letzteBewegung,
        'aktueller_lagerplatz' => $aktuellerOrt,
        'letzter_bearbeiter'   => $bearbeitetVon,
        'letzter_ort'          => $aktuellerOrt,
        'treffer_gesamt'       => count($matches),
    ],
    'matches' => $matches,
    'timeline' => $timeline,
]);