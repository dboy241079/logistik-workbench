<?php
declare(strict_types=1);

function lg_to_int(mixed $value): int
{
    if ($value === null || $value === '') {
        return 0;
    }

    if (is_string($value)) {
        $value = str_replace(['.', ','], ['', '.'], trim($value));
    }

    $n = (int)$value;
    return max(0, $n);
}

function lg_normalize_code(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
}

function lg_extract_bm_effect(?array $row, string $movementType): ?array
{
    if (!$row || !is_array($row)) {
        return null;
    }

    $lagergruppe = strtoupper(trim((string)($row['lagergruppe'] ?? '')));
    if ($lagergruppe !== 'BM') {
        return null;
    }

    $rawCode = trim((string)($row['behaelternr'] ?? ''));
    $code = lg_normalize_code($rawCode);

    if ($code === '') {
        throw new RuntimeException('BM-Leergut kann nicht verbucht werden: Beh.-Nr. / Behältercode fehlt.');
    }

    $anzahlBehaelter = lg_to_int($row['behaelter'] ?? 0);
    $anzahlZusatz    = lg_to_int($row['zus_behaelter'] ?? 0);
    $gesamt          = $anzahlBehaelter + $anzahlZusatz;

    if ($gesamt <= 0) {
        return null;
    }

    $delta = ($movementType === 'eingang') ? $gesamt : -$gesamt;

    return [
        'code'         => $code,
        'raw_code'     => $rawCode,
        'lagergruppe'  => $lagergruppe,
        'behaelter'    => $anzahlBehaelter,
        'zus_behaelter'=> $anzahlZusatz,
        'gesamt'       => $gesamt,
        'delta'        => $delta
    ];
}

function lg_find_behaelter_by_code(PDO $pdo, string $normalizedCode): ?array
{
    $sql = "
        SELECT
            b.id,
            b.nummer,
            b.vw_kennung,
            COALESCE(lb.menge, 0) AS bestand
        FROM behaelter b
        LEFT JOIN leergut_bestaende lb
            ON lb.behaelter_id = b.id
        WHERE
            REPLACE(REPLACE(REPLACE(UPPER(COALESCE(b.nummer, '')), ' ', ''), '-', ''), '_', '') = :code_nummer
            OR REPLACE(REPLACE(REPLACE(UPPER(COALESCE(b.vw_kennung, '')), ' ', ''), '-', ''), '_', '') = :code_vw
        ORDER BY
            CASE
                WHEN REPLACE(REPLACE(REPLACE(UPPER(COALESCE(b.nummer, '')), ' ', ''), '-', ''), '_', '') = :code_first THEN 0
                ELSE 1
            END,
            b.id ASC
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':code_nummer' => $normalizedCode,
        ':code_vw'     => $normalizedCode,
        ':code_first'  => $normalizedCode
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function lg_apply_delta_by_code(
    PDO $pdo,
    string $normalizedCode,
    int $delta,
    string $username = 'system',
    string $source = ''
): void {
    if ($delta === 0) {
        return;
    }

    $row = lg_find_behaelter_by_code($pdo, $normalizedCode);

    if (!$row) {
        throw new RuntimeException(
            'Kein passender Leergut-Behälter gefunden für Code "' . $normalizedCode . '"' .
            ($source !== '' ? ' (' . $source . ')' : '')
        );
    }

    $behaelterId     = (int)$row['id'];
    $currentBestand  = (int)$row['bestand'];
    $newBestand      = max(0, $currentBestand + $delta);

    $sql = "
        INSERT INTO leergut_bestaende
            (behaelter_id, menge, bemerkung, updated_by, updated_at)
        VALUES
            (:behaelter_id, :menge, '', :updated_by, NOW())
        ON DUPLICATE KEY UPDATE
            menge      = VALUES(menge),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':behaelter_id' => $behaelterId,
        ':menge'        => $newBestand,
        ':updated_by'   => $username
    ]);
}

/**
 * Insert:
 *   oldRow = null
 *   newRow = neuer Datensatz
 *
 * Update:
 *   oldRow = alter Datensatz
 *   newRow = neuer Datensatz
 *
 * Delete:
 *   oldRow = alter Datensatz
 *   newRow = null
 */
function sync_leergut_bestand_from_bm_change(
    PDO $pdo,
    ?array $oldRow,
    ?array $newRow,
    string $movementType,
    string $username = 'system',
    string $source = ''
): void {
    $oldEffect = lg_extract_bm_effect($oldRow, $movementType);
    $newEffect = lg_extract_bm_effect($newRow, $movementType);

    // Alten Effekt zurücknehmen
    if ($oldEffect) {
        lg_apply_delta_by_code(
            $pdo,
            $oldEffect['code'],
            -1 * (int)$oldEffect['delta'],
            $username,
            $source . ' | old'
        );
    }

    // Neuen Effekt anwenden
    if ($newEffect) {
        lg_apply_delta_by_code(
            $pdo,
            $newEffect['code'],
            (int)$newEffect['delta'],
            $username,
            $source . ' | new'
        );
    }
}