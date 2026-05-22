<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../api/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Methode nicht erlaubt.');
}

$module = trim((string)($_POST['module'] ?? ''));
$allowedModules = ['wareneingang', 'warenausgang'];

if (!in_array($module, $allowedModules, true)) {
    exit('Ungültiges Modul.');
}

$pastedData = (string)($_POST['pasted_data'] ?? '');
$pastedData = str_replace(["\r\n", "\r"], "\n", $pastedData);
$pastedData = trim($pastedData);

if ($pastedData === '') {
    exit('Keine Daten eingefügt.');
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
        '(' => ' ',
        ')' => ' ',
    ];

    $value = strtr($value, $replace);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function cleanValue(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function numOrNull(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    $value = str_replace([' ', ','], ['', '.'], $value);

    return is_numeric($value) ? (float)$value : null;
}

function intOrNull(?string $value): ?int
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    $value = str_replace([' ', ','], ['', '.'], $value);

    if (!is_numeric($value)) {
        return null;
    }

    return (int)round((float)$value);
}

function mapHeaders(array $headers): array
{
    $aliases = [
        'eingangsnummer' => ['eing nr', 'eing.-nr.', 'eingangsnummer', 'eingangsnr', 'eing nr.', 'eing.-nr'],
        'referenznummer' => ['referenznummer','referenz','ref','referenz nr','ref nr','referenznr'],
        'sachnummer'     => ['sachnummer','sachnr','sach nr','materialnummer','materialnr','teilenummer'],
        'lieferschein'   => ['lieferschein','ls','lieferschein nr','lieferscheinnummer'],
        'menge'          => ['menge','anzahl','qty','stück','stueck'],
        'behaelter'      => ['behaelter','behälter','paletten','palette'],
        'zus_behaelter'  => ['zus behaelter','zus behälter','zusatzbehaelter','zusatz behälter','zus beh','zusatz beh','zusatzbehälter','zusatzbehaelter menge','zusatzbehaelter anzahl'],
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
                    break 2;
                }
            }
        }
    }

    return $mapped;
}

function detectDelimiter(array $lines): string
{
    $tabHits = 0;
    $semiHits = 0;

    foreach (array_slice($lines, 0, 5) as $line) {
        if (str_contains($line, "\t")) {
            $tabHits++;
        }
        if (str_contains($line, ';')) {
            $semiHits++;
        }
    }

    return $tabHits >= $semiHits ? "\t" : ';';
}

function parseLine(string $line, string $delimiter): array
{
    if ($delimiter === "\t") {
        return explode("\t", $line);
    }

    return str_getcsv($line, $delimiter);
}

function isHeaderRow(array $row): bool
{
    $map = mapHeaders($row);
    if (count($map) >= 2) {
        return true;
    }

    $joined = normalizeHeader(implode(' ', array_map('strval', $row)));
    $headerWords = ['referenz', 'sachnummer', 'lieferschein', 'menge', 'lagergruppe', 'platz', 'reihe'];

    foreach ($headerWords as $word) {
        if (str_contains($joined, $word)) {
            return true;
        }
    }

    return false;
}

function buildFallbackHeader(int $count): array
{
    $fallback = [];
    for ($i = 0; $i < $count; $i++) {
        $fallback[] = 'spalte_' . ($i + 1);
    }
    return $fallback;
}

$lines = array_values(array_filter(
    explode("\n", $pastedData),
    static fn ($line) => trim((string)$line) !== ''
));

if (count($lines) === 0) {
    exit('Keine verwertbaren Zeilen gefunden.');
}

$delimiter = detectDelimiter($lines);
$parsedRows = [];

foreach ($lines as $line) {
    $parsedRows[] = parseLine($line, $delimiter);
}

$firstRow = $parsedRows[0] ?? [];
$hasHeader = isHeaderRow($firstRow);

if ($hasHeader) {
    $headerRow = $firstRow;
    array_shift($parsedRows);
    $excelStartRow = 2;
} else {
    $maxCols = 0;
    foreach ($parsedRows as $row) {
        $maxCols = max($maxCols, count($row));
    }
    $headerRow = buildFallbackHeader($maxCols);
    $excelStartRow = 1;
}

$headerMap = mapHeaders($headerRow);

if (empty($parsedRows)) {
    exit('Es wurden keine Datenzeilen gefunden.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO excel_import_jobs
            (module, original_filename, uploaded_by, total_rows, valid_rows, error_rows, status)
        VALUES
            (?, ?, ?, 0, 0, 0, 'parsed')
    ");
    $stmt->execute([
        $module,
        'Paste-Import',
        $username
    ]);

    $jobId = (int)$pdo->lastInsertId();

    $insertRow = $pdo->prepare("
    INSERT INTO excel_import_rows
    (
        job_id,
        row_number,
        status,
        error_text,
        eingangsnummer,
        referenznummer,
        sachnummer,
        lieferschein,
        menge,
        behaelter,
        zus_behaelter,
        lagergruppe,
        reihe,
        platz,
        bemerkung,
        datum,
        raw_json,
        normalized_json
    )
    VALUES
    (
        :job_id,
        :row_number,
        :status,
        :error_text,
        :eingangsnummer,
        :referenznummer,
        :sachnummer,
        :lieferschein,
        :menge,
        :behaelter,
        :zus_behaelter,
        :lagergruppe,
        :reihe,
        :platz,
        :bemerkung,
        :datum,
        :raw_json,
        :normalized_json
    )
");

    $totalRows = 0;
    $validRows = 0;
    $errorRows = 0;

    foreach ($parsedRows as $i => $row) {
        $rowNumber = $excelStartRow + $i;
        $totalRows++;

        $rawAssoc = [];
        foreach ($headerRow as $colIndex => $headerText) {
            $rawAssoc[(string)$headerText] = $row[$colIndex] ?? null;
        }

        $normalized = [
            'eingangsnummer' => cleanValue(isset($headerMap['eingangsnummer']) ? (string)($row[$headerMap['eingangsnummer']] ?? '') : ($row[0] ?? '')),
            'referenznummer' => cleanValue(isset($headerMap['referenznummer']) ? (string)($row[$headerMap['referenznummer']] ?? '') : ($row[0] ?? '')),
            'sachnummer'     => cleanValue(isset($headerMap['sachnummer']) ? (string)($row[$headerMap['sachnummer']] ?? '') : ($row[1] ?? '')),
            'lieferschein'   => cleanValue(isset($headerMap['lieferschein']) ? (string)($row[$headerMap['lieferschein']] ?? '') : ($row[2] ?? '')),
            'menge'          => numOrNull(cleanValue(isset($headerMap['menge']) ? (string)($row[$headerMap['menge']] ?? '') : ($row[3] ?? ''))),
            'behaelter'      => intOrNull(cleanValue(isset($headerMap['behaelter']) ? (string)($row[$headerMap['behaelter']] ?? '') : ($row[4] ?? ''))),
            'zus_behaelter'  => intOrNull(cleanValue(isset($headerMap['zus_behaelter']) ? (string)($row[$headerMap['zus_behaelter']] ?? '') : ($row[5] ?? ''))),
            'lagergruppe'    => cleanValue(isset($headerMap['lagergruppe']) ? (string)($row[$headerMap['lagergruppe']] ?? '') : ($row[6] ?? '')),
            'reihe'          => cleanValue(isset($headerMap['reihe']) ? (string)($row[$headerMap['reihe']] ?? '') : ($row[7] ?? '')),
            'platz'          => cleanValue(isset($headerMap['platz']) ? (string)($row[$headerMap['platz']] ?? '') : ($row[8] ?? '')),
            'bemerkung'      => cleanValue(isset($headerMap['bemerkung']) ? (string)($row[$headerMap['bemerkung']] ?? '') : ($row[9] ?? '')),
            'datum'          => cleanValue(isset($headerMap['datum']) ? (string)($row[$headerMap['datum']] ?? '') : ($row[10] ?? '')),
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
            $totalRows--;
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
    ':job_id'          => $jobId,
    ':row_number'      => $rowNumber,
    ':status'          => $status,
    ':error_text'      => $errorText,
    ':eingangsnummer'  => $normalized['eingangsnummer'],
    ':referenznummer'  => $normalized['referenznummer'],
    ':sachnummer'      => $normalized['sachnummer'],
    ':lieferschein'    => $normalized['lieferschein'],
    ':menge'           => $normalized['menge'],
    ':behaelter'       => $normalized['behaelter'],
    ':zus_behaelter'   => $normalized['zus_behaelter'],
    ':lagergruppe'     => $normalized['lagergruppe'],
    ':reihe'           => $normalized['reihe'],
    ':platz'           => $normalized['platz'],
    ':bemerkung'       => $normalized['bemerkung'],
    ':datum'           => $normalized['datum'],
    ':raw_json'        => json_encode($rawAssoc, JSON_UNESCAPED_UNICODE),
    ':normalized_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
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
    echo '<h2>Paste-Import fehlgeschlagen</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}