<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}

$module = trim((string)($_POST['module'] ?? ''));
$allowedModules = ['wareneingang', 'warenausgang'];

if (!in_array($module, $allowedModules, true)) {
    exit('Ungültiges Modul.');
}

if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
    exit('Keine Datei hochgeladen.');
}

$file = $_FILES['excel_file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    exit('Fehler beim Upload.');
}

$originalFilename = (string)($file['name'] ?? 'unbekannt');
$tmpFile = (string)($file['tmp_name'] ?? '');
$ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'xls', 'xlsm', 'csv'], true)) {
    exit('Nur .xlsx, .xls, .xlsm oder .csv erlaubt.');
}

$username = $_SESSION['username'] ?? 'unbekannt';

function normalizeHeader(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));

    $replace = [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
        '/' => ' ',
        '-' => ' ',
        '.' => ' ',
        ':' => ' ',
    ];
    $value = strtr($value, $replace);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function cleanValue(mixed $value): ?string
{
    if ($value === null) return null;
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function mapHeaders(array $headers): array
{
    $aliases = [
        'referenznummer' => ['referenznummer','referenz','ref','referenz nr','ref nr','referenznr'],
        'sachnummer'     => ['sachnummer','sachnr','sach nr','materialnummer','materialnr','teilenummer'],
        'lieferschein'   => ['lieferschein','ls','lieferschein nr','lieferscheinnummer'],
        'menge'          => ['menge','anzahl','qty','stück','stueck'],
        'behaelter'      => ['behaelter','behälter','paletten','palette'],
        'zus_behaelter'  => ['zus behaelter','zus behälter','zusatzbehaelter','zusatz behälter','zusatzbehaelter menge','zusatzbehaelter anzahl'],
        'lagergruppe'    => ['lagergruppe','lager grp','lg'],
        'reihe'          => ['reihe','row'],
        'platz'          => ['platz','lagerplatz','platz nr','platznummer'],
        'bemerkung'      => ['bemerkung','notiz','hinweis','kommentar'],
        'datum'          => ['datum','eingangsdatum','ausgangsdatum','date'],
    ];

    $mapped = [];

    foreach ($headers as $index => $header) {
        $normalizedHeader = normalizeHeader((string)$header);

        foreach ($aliases as $target => $list) {
            foreach ($list as $alias) {
                if ($normalizedHeader === normalizeHeader($alias)) {
                    $mapped[$target] = $index;
                }
            }
        }
    }

    return $mapped;
}

function numOrNull(?string $value): ?float
{
    if ($value === null) return null;
    $value = str_replace([' ', ','], ['', '.'], $value);
    return is_numeric($value) ? (float)$value : null;
}

function intOrNull(?string $value): ?int
{
    if ($value === null) return null;
    $value = str_replace([' ', ','], ['', '.'], $value);
    if (!is_numeric($value)) {
        return null;
    }
    return (int)round((float)$value);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO excel_import_jobs
            (module, original_filename, uploaded_by, total_rows, valid_rows, error_rows, status)
        VALUES
            (?, ?, ?, 0, 0, 0, 'uploaded')
    ");
    $stmt->execute([$module, $originalFilename, $username]);
    $jobId = (int)$pdo->lastInsertId();

    $inputFileType = IOFactory::identify($tmpFile);
    $reader = IOFactory::createReader($inputFileType);
    $spreadsheet = $reader->load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);

    if (!$rows || count($rows) < 2) {
        throw new RuntimeException('Die Datei enthält keine Datenzeilen.');
    }

    $headerRow = array_shift($rows);
    $headerMap = mapHeaders($headerRow);

    $insertRow = $pdo->prepare("
        INSERT INTO excel_import_rows
        (
            job_id, row_number, status, error_text,
            referenznummer, sachnummer, lieferschein, menge,
            behaelter, zus_behaelter, lagergruppe, reihe, platz,
            bemerkung, datum, raw_json, normalized_json
        )
        VALUES
        (
            :job_id, :row_number, :status, :error_text,
            :referenznummer, :sachnummer, :lieferschein, :menge,
            :behaelter, :zus_behaelter, :lagergruppe, :reihe, :platz,
            :bemerkung, :datum, :raw_json, :normalized_json
        )
    ");

    $totalRows = 0;
    $validRows = 0;
    $errorRows = 0;

    foreach ($rows as $i => $row) {
        $excelRowNumber = $i + 2; // +2 weil Header Zeile 1 ist
        $totalRows++;

        $rawAssoc = [];
        foreach ($headerRow as $colIndex => $headerText) {
            $rawAssoc[(string)$headerText] = $row[$colIndex] ?? null;
        }

        $normalized = [
            'referenznummer' => cleanValue(isset($headerMap['referenznummer']) ? (string)($row[$headerMap['referenznummer']] ?? '') : ''),
            'sachnummer'     => cleanValue(isset($headerMap['sachnummer']) ? (string)($row[$headerMap['sachnummer']] ?? '') : ''),
            'lieferschein'   => cleanValue(isset($headerMap['lieferschein']) ? (string)($row[$headerMap['lieferschein']] ?? '') : ''),
            'menge'          => numOrNull(cleanValue(isset($headerMap['menge']) ? (string)($row[$headerMap['menge']] ?? '') : '')),
            'behaelter'      => intOrNull(cleanValue(isset($headerMap['behaelter']) ? (string)($row[$headerMap['behaelter']] ?? '') : '')),
            'zus_behaelter'  => intOrNull(cleanValue(isset($headerMap['zus_behaelter']) ? (string)($row[$headerMap['zus_behaelter']] ?? '') : '')),
            'lagergruppe'    => cleanValue(isset($headerMap['lagergruppe']) ? (string)($row[$headerMap['lagergruppe']] ?? '') : ''),
            'reihe'          => cleanValue(isset($headerMap['reihe']) ? (string)($row[$headerMap['reihe']] ?? '') : ''),
            'platz'          => cleanValue(isset($headerMap['platz']) ? (string)($row[$headerMap['platz']] ?? '') : ''),
            'bemerkung'      => cleanValue(isset($headerMap['bemerkung']) ? (string)($row[$headerMap['bemerkung']] ?? '') : ''),
            'datum'          => cleanValue(isset($headerMap['datum']) ? (string)($row[$headerMap['datum']] ?? '') : ''),
        ];

        $errors = [];

        $allEmpty = true;
        foreach ($normalized as $value) {
            if ($value !== null && $value !== '') {
                $allEmpty = false;
                break;
            }
        }

        if ($allEmpty) {
            continue;
        }

        if ($normalized['referenznummer'] === null && $normalized['sachnummer'] === null) {
            $errors[] = 'Referenznummer und Sachnummer fehlen beide.';
        }

        if ($normalized['menge'] !== null && $normalized['menge'] < 0) {
            $errors[] = 'Menge darf nicht negativ sein.';
        }

        if ($normalized['behaelter'] !== null && $normalized['behaelter'] < 0) {
            $errors[] = 'Behälter darf nicht negativ sein.';
        }

        if ($normalized['zus_behaelter'] !== null && $normalized['zus_behaelter'] < 0) {
            $errors[] = 'Zusatzbehälter darf nicht negativ sein.';
        }

        $status = empty($errors) ? 'ok' : 'error';
        $errorText = empty($errors) ? null : implode(' | ', $errors);

        if ($status === 'ok') {
            $validRows++;
        } else {
            $errorRows++;
        }

        $insertRow->execute([
            ':job_id'           => $jobId,
            ':row_number'       => $excelRowNumber,
            ':status'           => $status,
            ':error_text'       => $errorText,
            ':referenznummer'   => $normalized['referenznummer'],
            ':sachnummer'       => $normalized['sachnummer'],
            ':lieferschein'     => $normalized['lieferschein'],
            ':menge'            => $normalized['menge'],
            ':behaelter'        => $normalized['behaelter'],
            ':zus_behaelter'    => $normalized['zus_behaelter'],
            ':lagergruppe'      => $normalized['lagergruppe'],
            ':reihe'            => $normalized['reihe'],
            ':platz'            => $normalized['platz'],
            ':bemerkung'        => $normalized['bemerkung'],
            ':datum'            => $normalized['datum'],
            ':raw_json'         => json_encode($rawAssoc, JSON_UNESCAPED_UNICODE),
            ':normalized_json'  => json_encode($normalized, JSON_UNESCAPED_UNICODE),
        ]);
    }

    $stmt = $pdo->prepare("
        UPDATE excel_import_jobs
        SET total_rows = ?, valid_rows = ?, error_rows = ?, status = 'parsed'
        WHERE id = ?
    ");
    $stmt->execute([$totalRows, $validRows, $errorRows, $jobId]);

    $pdo->commit();

    header('Location: excel_import_preview.php?job_id=' . $jobId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo '<h2>Import fehlgeschlagen</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}