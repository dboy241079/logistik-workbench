<?php
declare(strict_types=1);

require __DIR__ . '/../../inc/session.php';
require __DIR__ . '/../../api/_db.php';
require __DIR__ . '/../../inc/rbac.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function out(bool $ok, array $data = []): void {
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function qi(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Ungültiger SQL-Identifier: ' . $name);
    }
    return '`' . $name . '`';
}

function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string {
    if (!$candidates) {
        return null;
    }

    $ph = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name IN ($ph)
    ";

    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$table], $candidates));
    $found = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($candidates as $c) {
        if (in_array($c, $found, true)) {
            return $c;
        }
    }
    return null;
}

function normAusgang(string $value): string {
    return trim($value);
}

function statusRank(string $status): int {
    return match ($status) {
        'VERLADEN_OK'      => 600,
        'VERLADUNG'        => 500,
        'BEREITGESTELLT'   => 400,
        'KOMMISSIONIERUNG' => 300,
        'OFFEN'            => 200,
        'PROBLEM'          => 100,
        default            => 0,
    };
}

function pickBestOrderPerAusgang(array $rows): array {
    $best = [];

    foreach ($rows as $row) {
        $nr = normAusgang((string)($row['ausgang_nr'] ?? ''));
        if ($nr === '') {
            continue;
        }

        $row['ausgang_nr'] = $nr;
        $rowStatus = (string)($row['status'] ?? '');
        $rowRank   = statusRank($rowStatus);
        $rowLoaded = !empty($row['loaded_at']) ? strtotime((string)$row['loaded_at']) : 0;
        $rowId     = (int)($row['id'] ?? 0);

        if (!isset($best[$nr])) {
            $best[$nr] = $row;
            continue;
        }

        $cur       = $best[$nr];
        $curStatus = (string)($cur['status'] ?? '');
        $curRank   = statusRank($curStatus);
        $curLoaded = !empty($cur['loaded_at']) ? strtotime((string)$cur['loaded_at']) : 0;
        $curId     = (int)($cur['id'] ?? 0);

        $replace = false;

        if ($rowRank > $curRank) {
            $replace = true;
        } elseif ($rowRank === $curRank && $rowLoaded > $curLoaded) {
            $replace = true;
        } elseif ($rowRank === $curRank && $rowLoaded === $curLoaded && $rowId > $curId) {
            $replace = true;
        }

        if ($replace) {
            $best[$nr] = $row;
        }
    }

    return array_values($best);
}

if (!rbac_uid()) {
    out(false, ['error' => 'Nicht eingeloggt.']);
}

if (!rbac_can_tab($pdo, 'outbound')) {
    out(false, ['error' => 'Keine Berechtigung.']);
}

$payload  = json_decode(file_get_contents('php://input'), true) ?: [];
$ausgangs = $payload['ausgang_nrs'] ?? [];

if (!is_array($ausgangs)) {
    $ausgangs = [];
}

$ausgangs = array_values(array_unique(array_filter(array_map(
    static fn($v) => normAusgang((string)$v),
    $ausgangs
))));

if (!$ausgangs) {
    out(true, ['items' => []]);
}

$ph = implode(',', array_fill(0, count($ausgangs), '?'));

try {
    $sql = "
        SELECT
            id,
            order_no,
            TRIM(source_ausgang_nr) AS ausgang_nr,
            status,
            exit_gate,
            loaded_at
        FROM kommi_orders
        WHERE TRIM(source_ausgang_nr) IN ($ph)
        ORDER BY source_ausgang_nr ASC, id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($ausgangs);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // WICHTIG: pro Ausgangsnummer genau einen Datensatz auswählen
    $items = pickBestOrderPerAusgang($rows);

    if ($items && tableExists($pdo, 'warenausgang')) {
        $colAusgang = firstExistingColumn($pdo, 'warenausgang', [
            'ausgang_nr', 'ausgang', 'ausgangsnummer', 'ausgangs_nr'
        ]);
        $colShipper = firstExistingColumn($pdo, 'warenausgang', [
            'spediteur', 'shipper', 'spedition'
        ]);
        $colLicence = firstExistingColumn($pdo, 'warenausgang', [
            'kennzeichen', 'kennz', 'licence', 'license'
        ]);
        $colLs = firstExistingColumn($pdo, 'warenausgang', [
            'lieferschein', 'lieferscheinnummer', 'lieferschein_nr', 'ls'
        ]);

        if ($colAusgang !== null) {
            $selShipper = $colShipper !== null
                ? "MAX(NULLIF(TRIM(" . qi($colShipper) . "), '')) AS shipper"
                : "'' AS shipper";

            $selLicence = $colLicence !== null
                ? "MAX(NULLIF(TRIM(" . qi($colLicence) . "), '')) AS licence"
                : "'' AS licence";

            $selLs = $colLs !== null
                ? "MAX(NULLIF(TRIM(" . qi($colLs) . "), '')) AS lieferschein"
                : "'' AS lieferschein";

            $sqlWa = "
                SELECT
                    TRIM(CAST(" . qi($colAusgang) . " AS CHAR)) AS ausgang_nr,
                    $selShipper,
                    $selLicence,
                    $selLs
                FROM " . qi('warenausgang') . "
                WHERE TRIM(CAST(" . qi($colAusgang) . " AS CHAR)) IN ($ph)
                GROUP BY TRIM(CAST(" . qi($colAusgang) . " AS CHAR))
            ";

            $stWa = $pdo->prepare($sqlWa);
            $stWa->execute($ausgangs);
            $waRows = $stWa->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $waMap = [];
            foreach ($waRows as $r) {
                $nr = normAusgang((string)($r['ausgang_nr'] ?? ''));
                if ($nr === '') {
                    continue;
                }

                $waMap[$nr] = [
                    'shipper'      => trim((string)($r['shipper'] ?? '')),
                    'licence'      => trim((string)($r['licence'] ?? '')),
                    'lieferschein' => trim((string)($r['lieferschein'] ?? '')),
                ];
            }

            foreach ($items as &$it) {
                $nr = normAusgang((string)($it['ausgang_nr'] ?? ''));
                $wa = $waMap[$nr] ?? null;

                $it['shipper']      = (string)($wa['shipper'] ?? '');
                $it['licence']      = (string)($wa['licence'] ?? '');
                $it['lieferschein'] = (string)($wa['lieferschein'] ?? '');
            }
            unset($it);
        } else {
            foreach ($items as &$it) {
                $it['shipper']      = '';
                $it['licence']      = '';
                $it['lieferschein'] = '';
            }
            unset($it);
        }
    } else {
        foreach ($items as &$it) {
            $it['shipper']      = (string)($it['shipper'] ?? '');
            $it['licence']      = (string)($it['licence'] ?? '');
            $it['lieferschein'] = (string)($it['lieferschein'] ?? '');
        }
        unset($it);
    }

    out(true, ['items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    out(false, ['error' => $e->getMessage()]);
}