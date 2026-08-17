<?php
declare(strict_types=1);

function qcControlledEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_controlled_documents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_no VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        owner_code VARCHAR(64) NULL,
        current_revision_id BIGINT UNSIGNED NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_controlled_documents_no (document_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_controlled_document_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_id BIGINT UNSIGNED NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        label VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_controlled_document_file (document_id, file_path),
        KEY idx_qc_controlled_document_files_doc (document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_revisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_id BIGINT UNSIGNED NOT NULL,
        revision VARCHAR(32) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        change_type VARCHAR(32) NULL,
        change_reason VARCHAR(255) NULL,
        change_description TEXT NULL,
        source_combined_hash CHAR(64) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_at DATETIME NULL,
        approved_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_document_revision (document_id, revision),
        KEY idx_qc_document_revisions_doc (document_id),
        KEY idx_qc_document_revisions_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_revision_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        revision_id BIGINT UNSIGNED NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        sha256 CHAR(64) NULL,
        size_bytes BIGINT UNSIGNED NULL,
        snapshot_path VARCHAR(512) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_document_revision_file (revision_id, file_path),
        KEY idx_qc_document_revision_files_rev (revision_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_approvals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        revision_id BIGINT UNSIGNED NOT NULL,
        approver_code VARCHAR(64) NOT NULL,
        approver_user_id BIGINT UNSIGNED NULL,
        approval_role VARCHAR(64) NOT NULL,
        decision VARCHAR(32) NOT NULL DEFAULT 'pending',
        comment TEXT NULL,
        document_hash CHAR(64) NULL,
        decided_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_document_approval (revision_id, approver_code),
        KEY idx_qc_document_approvals_rev (revision_id),
        KEY idx_qc_document_approvals_decision (decision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_id BIGINT UNSIGNED NOT NULL,
        revision_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(64) NOT NULL,
        event_key VARCHAR(191) NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        details_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_document_history_event_key (event_key),
        KEY idx_qc_document_history_doc (document_id),
        KEY idx_qc_document_history_rev (revision_id),
        KEY idx_qc_document_history_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function qcControlledCurrentUserId(): ?int
{
    foreach (['user_id', 'id', 'uid'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }
    return null;
}

function qcControlledRootPath(): string
{
    return dirname(__DIR__);
}

function qcControlledNormalizePath(string $path): string
{
    $path = '/' . ltrim(str_replace('\\', '/', trim($path)), '/');
    if (str_contains($path, '..')) {
        throw new RuntimeException('Ungültiger Dokumentpfad: ' . $path);
    }
    return $path;
}

function qcControlledFileMeta(string $rootPath, string $filePath): array
{
    $filePath = qcControlledNormalizePath($filePath);
    $absolute = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($filePath, '/');

    if (!is_file($absolute)) {
        return [
            'file_path' => $filePath,
            'exists' => false,
            'sha256' => null,
            'size_bytes' => null,
        ];
    }

    $sha = hash_file('sha256', $absolute);
    $size = filesize($absolute);

    return [
        'file_path' => $filePath,
        'exists' => true,
        'sha256' => $sha !== false ? $sha : null,
        'size_bytes' => $size !== false ? (int)$size : null,
    ];
}

function qcControlledCombinedHash(array $files): string
{
    usort($files, static fn(array $a, array $b): int => strcmp((string)$a['file_path'], (string)$b['file_path']));
    $buffer = '';
    foreach ($files as $file) {
        $buffer .= (string)$file['file_path'] . "\0" . (string)($file['sha256'] ?? 'MISSING') . "\n";
    }
    return hash('sha256', $buffer);
}

function qcControlledGetTrackedFiles(PDO $pdo, int $documentId, string $rootPath): array
{
    $stmt = $pdo->prepare("SELECT file_path, label, sort_order
        FROM qc_controlled_document_files
        WHERE document_id = ? AND active = 1
        ORDER BY sort_order, id");
    $stmt->execute([$documentId]);

    $files = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta = qcControlledFileMeta($rootPath, (string)$row['file_path']);
        $meta['label'] = (string)($row['label'] ?? '');
        $meta['sort_order'] = (int)($row['sort_order'] ?? 0);
        $files[] = $meta;
    }
    return $files;
}

function qcControlledHistory(PDO $pdo, int $documentId, ?int $revisionId, string $eventType, string $eventKey, array $details = []): void
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO qc_document_history
        (document_id, revision_id, event_type, event_key, actor_user_id, details_json)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $documentId,
        $revisionId,
        $eventType,
        $eventKey,
        qcControlledCurrentUserId(),
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function qcControlledSeedInitial(PDO $pdo, string $rootPath): int
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO qc_controlled_documents
            (document_no, title, description, owner_code, created_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), owner_code = VALUES(owner_code)");
        $stmt->execute([
            'F_059_0001',
            'Laufzettel Wareneingang',
            'Gelenktes Formular für die Wareneingangsprüfung und Paletten-/KLT-Auflistung.',
            'MAD',
            qcControlledCurrentUserId(),
        ]);

        $stmt = $pdo->prepare("SELECT id, current_revision_id FROM qc_controlled_documents WHERE document_no = ? LIMIT 1");
        $stmt->execute(['F_059_0001']);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new RuntimeException('F_059_0001 konnte nicht angelegt werden.');
        }
        $documentId = (int)$doc['id'];

        $fileStmt = $pdo->prepare("INSERT INTO qc_controlled_document_files
            (document_id, file_path, label, sort_order)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), active = 1");
        $tracked = [
            ['/druck_wa.html', 'Formular / Druckansicht', 10],
            ['/CSS/drucken.css', 'Drucklayout', 20],
            ['/js/drucken.js', 'Formularlogik', 30],
        ];
        foreach ($tracked as [$path, $label, $sort]) {
            $fileStmt->execute([$documentId, $path, $label, $sort]);
        }

        $revStmt = $pdo->prepare("INSERT IGNORE INTO qc_document_revisions
            (document_id, revision, status, change_type, change_reason, change_description, created_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $revStmt->execute([
            $documentId,
            '1.0',
            'released',
            'legacy',
            'Übernahme des bisherigen gelenkten Dokuments',
            'Bisheriger freigegebener Ausgangsstand vor Einführung der digitalen Dokumentenlenkung.',
            qcControlledCurrentUserId(),
            '2025-11-05 00:00:00',
        ]);

        $stmt = $pdo->prepare("SELECT id FROM qc_document_revisions WHERE document_id = ? AND revision = '1.0' LIMIT 1");
        $stmt->execute([$documentId]);
        $rev1Id = (int)$stmt->fetchColumn();

        if (empty($doc['current_revision_id']) && $rev1Id > 0) {
            $stmt = $pdo->prepare("UPDATE qc_controlled_documents SET current_revision_id = ? WHERE id = ?");
            $stmt->execute([$rev1Id, $documentId]);
        }

        $currentFiles = qcControlledGetTrackedFiles($pdo, $documentId, $rootPath);
        $currentHash = qcControlledCombinedHash($currentFiles);

        $revStmt = $pdo->prepare("INSERT IGNORE INTO qc_document_revisions
            (document_id, revision, status, change_type, change_reason, change_description, source_combined_hash, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $revStmt->execute([
            $documentId,
            '2.0',
            'draft',
            'major',
            'Fachliche Anpassung des Wareneingangsformulars',
            'Bereichs-Checkboxen entfernt; NO-Label- und Aktionsspalte entfernt; Tabelle auf vier Spalten reduziert; Dokument-Footer je A4-Seite ergänzt.',
            $currentHash,
            qcControlledCurrentUserId(),
        ]);

        $stmt = $pdo->prepare("SELECT id FROM qc_document_revisions WHERE document_id = ? AND revision = '2.0' LIMIT 1");
        $stmt->execute([$documentId]);
        $rev2Id = (int)$stmt->fetchColumn();

        if ($rev2Id > 0) {
            $fileSnapStmt = $pdo->prepare("INSERT IGNORE INTO qc_document_revision_files
                (revision_id, file_path, sha256, size_bytes)
                VALUES (?, ?, ?, ?)");
            foreach ($currentFiles as $file) {
                $fileSnapStmt->execute([
                    $rev2Id,
                    $file['file_path'],
                    $file['sha256'],
                    $file['size_bytes'],
                ]);
            }

            $approvalStmt = $pdo->prepare("INSERT IGNORE INTO qc_document_approvals
                (revision_id, approver_code, approval_role, decision, document_hash)
                VALUES (?, ?, ?, 'pending', ?)");
            foreach ([['MAD', 'Ersteller'], ['MED', 'Prüfer'], ['CBE', 'Freigeber']] as [$code, $role]) {
                $approvalStmt->execute([$rev2Id, $code, $role, $currentHash]);
            }
        }

        qcControlledHistory($pdo, $documentId, $rev1Id ?: null, 'revision_imported', 'seed:F_059_0001:1.0', [
            'revision' => '1.0',
            'status' => 'released',
        ]);
        qcControlledHistory($pdo, $documentId, $rev2Id ?: null, 'revision_created', 'seed:F_059_0001:2.0', [
            'revision' => '2.0',
            'status' => 'draft',
            'combined_hash' => $currentHash,
        ]);

        $pdo->commit();
        return $documentId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function qcControlledInspectDocument(PDO $pdo, int $documentId, string $rootPath): array
{
    $stmt = $pdo->prepare("SELECT * FROM qc_document_revisions WHERE document_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$documentId]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $currentFiles = qcControlledGetTrackedFiles($pdo, $documentId, $rootPath);
    $currentHash = qcControlledCombinedHash($currentFiles);

    $snapshot = [];
    if ($latest) {
        $stmt = $pdo->prepare("SELECT file_path, sha256, size_bytes FROM qc_document_revision_files WHERE revision_id = ?");
        $stmt->execute([(int)$latest['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshot[(string)$row['file_path']] = $row;
        }
    }

    $changed = false;
    $fileStates = [];
    foreach ($currentFiles as $file) {
        $path = (string)$file['file_path'];
        $oldHash = isset($snapshot[$path]) ? (string)($snapshot[$path]['sha256'] ?? '') : '';
        $newHash = (string)($file['sha256'] ?? '');
        $state = !$file['exists'] ? 'missing' : (($oldHash !== '' && hash_equals($oldHash, $newHash)) ? 'unchanged' : 'changed');
        if ($state !== 'unchanged') {
            $changed = true;
        }
        $file['snapshot_sha256'] = $oldHash !== '' ? $oldHash : null;
        $file['state'] = $state;
        $fileStates[] = $file;
    }

    if ($latest && $changed) {
        $eventKey = 'change:' . $documentId . ':' . (int)$latest['id'] . ':' . $currentHash;
        qcControlledHistory($pdo, $documentId, (int)$latest['id'], 'file_change_detected', $eventKey, [
            'combined_hash' => $currentHash,
            'files' => array_map(static fn(array $f): array => [
                'file_path' => $f['file_path'],
                'state' => $f['state'],
                'sha256' => $f['sha256'],
            ], $fileStates),
        ]);
    }

    return [
        'latest_revision' => $latest,
        'current_files' => $fileStates,
        'current_combined_hash' => $currentHash,
        'changed' => $changed,
    ];
}

function qcControlledGetSummary(PDO $pdo, string $rootPath): array
{
    $rows = $pdo->query("SELECT id, current_revision_id FROM qc_controlled_documents WHERE active = 1 ORDER BY document_no")
        ->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total' => count($rows),
        'released' => 0,
        'draft' => 0,
        'in_review' => 0,
        'changed' => 0,
    ];

    foreach ($rows as $row) {
        $docId = (int)$row['id'];
        if (!empty($row['current_revision_id'])) {
            $stmt = $pdo->prepare("SELECT status FROM qc_document_revisions WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$row['current_revision_id']]);
            if ((string)$stmt->fetchColumn() === 'released') {
                $summary['released']++;
            }
        }

        $inspect = qcControlledInspectDocument($pdo, $docId, $rootPath);
        $latestStatus = (string)($inspect['latest_revision']['status'] ?? '');
        if ($latestStatus === 'draft') {
            $summary['draft']++;
        } elseif ($latestStatus === 'in_review') {
            $summary['in_review']++;
        }
        if ($inspect['changed']) {
            $summary['changed']++;
        }
    }

    return $summary;
}

function qcControlledBootstrap(PDO $pdo): array
{
    qcControlledEnsureTables($pdo);
    $rootPath = qcControlledRootPath();
    qcControlledSeedInitial($pdo, $rootPath);
    return qcControlledGetSummary($pdo, $rootPath);
}
