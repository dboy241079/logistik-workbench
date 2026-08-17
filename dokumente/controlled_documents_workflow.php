<?php
declare(strict_types=1);

require_once __DIR__ . '/controlled_documents_bootstrap.php';

function qcWorkflowColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function qcWorkflowEnsureSchema(PDO $pdo): void
{
    qcControlledEnsureTables($pdo);

    $alter = [
        'approval_token_hash' => "ALTER TABLE qc_document_approvals ADD COLUMN approval_token_hash CHAR(64) NULL AFTER document_hash",
        'approver_email' => "ALTER TABLE qc_document_approvals ADD COLUMN approver_email VARCHAR(255) NULL AFTER approver_user_id",
        'token_created_at' => "ALTER TABLE qc_document_approvals ADD COLUMN token_created_at DATETIME NULL AFTER approval_token_hash",
        'notified_at' => "ALTER TABLE qc_document_approvals ADD COLUMN notified_at DATETIME NULL AFTER token_created_at",
    ];
    foreach ($alter as $column => $sql) {
        if (!qcWorkflowColumnExists($pdo, 'qc_document_approvals', $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_approver_contacts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        approver_code VARCHAR(64) NOT NULL,
        display_name VARCHAR(255) NULL,
        user_id BIGINT UNSIGNED NULL,
        email VARCHAR(255) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qc_doc_approver_code (approver_code),
        KEY idx_qc_doc_approver_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qc_document_mail_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        revision_id BIGINT UNSIGNED NOT NULL,
        approver_code VARCHAR(64) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL,
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_qc_document_mail_log_rev (revision_id),
        KEY idx_qc_document_mail_log_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seed = $pdo->prepare("INSERT INTO qc_document_approver_contacts (approver_code, display_name)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)");
    foreach ([['MAD', 'MAD'], ['MED', 'MED'], ['CBE', 'CBE']] as [$code, $name]) {
        $seed->execute([$code, $name]);
    }
}

function qcWorkflowCanManage(): bool
{
    $role = strtolower(trim((string)($_SESSION['role'] ?? $_SESSION['app_role'] ?? $_SESSION['currentRole'] ?? '')));
    return in_array($role, ['admin', 'standortleiter'], true);
}

function qcWorkflowCurrentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function qcWorkflowCsrfToken(): string
{
    if (empty($_SESSION['controlled_docs_csrf'])) {
        $_SESSION['controlled_docs_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['controlled_docs_csrf'];
}

function qcWorkflowRequireCsrf(?string $token): void
{
    $expected = (string)($_SESSION['controlled_docs_csrf'] ?? '');
    if ($expected === '' || $token === null || !hash_equals($expected, $token)) {
        throw new RuntimeException('Ungültige Sitzung. Bitte Seite neu laden.');
    }
}

function qcWorkflowContacts(PDO $pdo): array
{
    qcWorkflowEnsureSchema($pdo);
    $rows = $pdo->query("SELECT c.approver_code, c.display_name, c.user_id, c.email,
            COALESCE(u.display_name, u.username) AS user_label,
            u.username
        FROM qc_document_approver_contacts c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.active = 1
        ORDER BY FIELD(c.approver_code, 'MAD','MED','CBE'), c.approver_code")
        ->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

function qcWorkflowActiveUsers(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, display_name
        FROM users
        WHERE active = 1 AND deleted_at IS NULL
        ORDER BY COALESCE(NULLIF(display_name,''), username), username")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function qcWorkflowSaveContacts(PDO $pdo, array $input): void
{
    if (!qcWorkflowCanManage()) {
        throw new RuntimeException('Keine Berechtigung für die Prüfer-Zuordnung.');
    }

    $stmt = $pdo->prepare("UPDATE qc_document_approver_contacts
        SET user_id = ?, email = ?, display_name = ?
        WHERE approver_code = ?");

    foreach (['MAD','MED','CBE'] as $code) {
        $userId = (int)($input[$code]['user_id'] ?? 0);
        $email = trim((string)($input[$code]['email'] ?? ''));
        $displayName = trim((string)($input[$code]['display_name'] ?? $code));

        if ($userId <= 0) {
            throw new RuntimeException($code . ': Bitte einen Workbench-Benutzer auswählen.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($code . ': Bitte eine gültige E-Mail-Adresse eintragen.');
        }

        $stmt->execute([$userId, $email, $displayName !== '' ? $displayName : $code, $code]);
    }
}

function qcWorkflowBaseUrl(): string
{
    $forwarded = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'your-workbench.de'));
    return $scheme . '://' . ($host ?: 'your-workbench.de');
}

function qcWorkflowSendMail(string $to, string $subject, string $html): array
{
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'your-workbench.de'));
    $from = 'noreply@' . ($host ?: 'your-workbench.de');
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8')
        : $subject;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: Logistik-Workbench <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    if (!function_exists('mail')) {
        return [false, 'PHP mail() ist auf dem Server nicht verfügbar.'];
    }

    $ok = @mail($to, $encodedSubject, $html, implode("\r\n", $headers));
    return [$ok, $ok ? null : 'Der Server hat den Mailversand nicht bestätigt.'];
}

function qcWorkflowRefreshDraftSnapshot(PDO $pdo, int $revisionId): void
{
    if (!qcWorkflowCanManage()) {
        throw new RuntimeException('Keine Berechtigung.');
    }

    $stmt = $pdo->prepare("SELECT r.*, d.id AS document_id
        FROM qc_document_revisions r
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$revisionId]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rev) {
        throw new RuntimeException('Revision nicht gefunden.');
    }
    if (!in_array((string)$rev['status'], ['draft', 'rejected'], true)) {
        throw new RuntimeException('Nur Entwürfe oder abgelehnte Revisionen können aktualisiert werden.');
    }

    $documentId = (int)$rev['document_id'];
    $files = qcControlledGetTrackedFiles($pdo, $documentId, qcControlledRootPath());
    foreach ($files as $file) {
        if (!$file['exists']) {
            throw new RuntimeException('Datei fehlt: ' . $file['file_path']);
        }
    }
    $hash = qcControlledCombinedHash($files);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM qc_document_revision_files WHERE revision_id = ?")->execute([$revisionId]);
        $ins = $pdo->prepare("INSERT INTO qc_document_revision_files (revision_id, file_path, sha256, size_bytes)
            VALUES (?, ?, ?, ?)");
        foreach ($files as $file) {
            $ins->execute([$revisionId, $file['file_path'], $file['sha256'], $file['size_bytes']]);
        }
        $pdo->prepare("UPDATE qc_document_revisions
            SET source_combined_hash = ?, status = 'draft', submitted_at = NULL, approved_at = NULL
            WHERE id = ?")->execute([$hash, $revisionId]);
        $pdo->prepare("UPDATE qc_document_approvals
            SET decision = 'pending', comment = NULL, decided_at = NULL, approval_token_hash = NULL,
                token_created_at = NULL, notified_at = NULL, approver_email = NULL
            WHERE revision_id = ?")->execute([$revisionId]);

        qcControlledHistory($pdo, $documentId, $revisionId, 'draft_snapshot_refreshed',
            'snapshot:' . $revisionId . ':' . $hash,
            ['combined_hash' => $hash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function qcWorkflowInvalidateReviewIfChanged(PDO $pdo, int $documentId, array $inspect): bool
{
    $latest = $inspect['latest_revision'] ?? null;
    if (!$latest || (string)($latest['status'] ?? '') !== 'in_review' || !$inspect['changed']) {
        return false;
    }

    $revisionId = (int)$latest['id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE qc_document_revisions SET status = 'draft', submitted_at = NULL WHERE id = ?")
            ->execute([$revisionId]);
        $pdo->prepare("UPDATE qc_document_approvals
            SET decision = 'pending', comment = NULL, decided_at = NULL,
                approval_token_hash = NULL, token_created_at = NULL, notified_at = NULL
            WHERE revision_id = ?")->execute([$revisionId]);
        qcControlledHistory($pdo, $documentId, $revisionId, 'review_invalidated',
            'invalidate:' . $revisionId . ':' . $inspect['current_combined_hash'],
            ['reason' => 'Quelldateien nach Prüfungsstart verändert', 'combined_hash' => $inspect['current_combined_hash']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function qcWorkflowStartReview(PDO $pdo, int $revisionId): array
{
    if (!qcWorkflowCanManage()) {
        throw new RuntimeException('Keine Berechtigung zum Starten der Prüfung.');
    }

    qcWorkflowEnsureSchema($pdo);

    $stmt = $pdo->prepare("SELECT r.*, d.document_no, d.title
        FROM qc_document_revisions r
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$revisionId]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rev) {
        throw new RuntimeException('Revision nicht gefunden.');
    }
    if (!in_array((string)$rev['status'], ['draft', 'in_review'], true)) {
        throw new RuntimeException('Diese Revision kann nicht zur Prüfung versendet werden.');
    }

    $documentId = (int)$rev['document_id'];
    $inspect = qcControlledInspectDocument($pdo, $documentId, qcControlledRootPath());
    if ((int)($inspect['latest_revision']['id'] ?? 0) !== $revisionId) {
        throw new RuntimeException('Nur die neueste Revision kann geprüft werden.');
    }
    if ($inspect['changed']) {
        throw new RuntimeException('Der Dateistand weicht vom Entwurf ab. Bitte zuerst „Dateistand in Entwurf übernehmen“ ausführen.');
    }

    $contacts = qcWorkflowContacts($pdo);
    $byCode = [];
    foreach ($contacts as $contact) {
        $byCode[(string)$contact['approver_code']] = $contact;
    }
    foreach (['MAD','MED','CBE'] as $code) {
        $contact = $byCode[$code] ?? null;
        if (!$contact || (int)($contact['user_id'] ?? 0) <= 0 || !filter_var((string)($contact['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($code . ' ist noch nicht vollständig mit Benutzer und E-Mail-Adresse konfiguriert.');
        }
    }

    $combinedHash = (string)$inspect['current_combined_hash'];
    $tokens = [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE qc_document_revisions SET status = 'in_review', submitted_at = NOW() WHERE id = ?")
            ->execute([$revisionId]);

        $approvalUp = $pdo->prepare("UPDATE qc_document_approvals
            SET approver_user_id = ?, approver_email = ?, decision = 'pending', comment = NULL,
                decided_at = NULL, document_hash = ?, approval_token_hash = ?, token_created_at = NOW()
            WHERE revision_id = ? AND approver_code = ?");

        foreach (['MAD','MED','CBE'] as $code) {
            $contact = $byCode[$code];
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $approvalUp->execute([
                (int)$contact['user_id'],
                (string)$contact['email'],
                $combinedHash,
                $tokenHash,
                $revisionId,
                $code,
            ]);
            $tokens[$code] = $rawToken;
        }

        qcControlledHistory($pdo, $documentId, $revisionId, 'review_started',
            'review-start:' . $revisionId . ':' . $combinedHash,
            ['revision' => $rev['revision'], 'combined_hash' => $combinedHash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $results = [];
    $baseUrl = qcWorkflowBaseUrl();
    $log = $pdo->prepare("INSERT INTO qc_document_mail_log
        (revision_id, approver_code, recipient_email, subject, status, error_message, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $mark = $pdo->prepare("UPDATE qc_document_approvals SET notified_at = NOW() WHERE revision_id = ? AND approver_code = ?");

    foreach (['MAD','MED','CBE'] as $code) {
        $contact = $byCode[$code];
        $link = $baseUrl . '/dokumente/dokumentenfreigabe.php?token=' . rawurlencode($tokens[$code]);
        $subject = 'Dokumentenprüfung – ' . $rev['document_no'] . ' Rev. ' . $rev['revision'];
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#111">'
            . '<h2>Dokumentenprüfung</h2>'
            . '<p>Für das gelenkte Dokument <b>' . htmlspecialchars((string)$rev['document_no']) . ' – ' . htmlspecialchars((string)$rev['title']) . '</b> liegt die Revision <b>' . htmlspecialchars((string)$rev['revision']) . '</b> zur Prüfung vor.</p>'
            . '<p>Bitte öffne die Workbench und bestätige oder lehne die Revision dort ab.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:10px 16px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:6px">Dokument prüfen</a></p>'
            . '<p style="font-size:12px;color:#666">Die Bestätigung wird mit Benutzer, Zeitstempel und Dokument-Hash protokolliert. Wird der Dateistand nach Prüfungsstart verändert, verliert der Link seine Gültigkeit.</p>'
            . '</body></html>';

        [$ok, $error] = qcWorkflowSendMail((string)$contact['email'], $subject, $html);
        $log->execute([
            $revisionId,
            $code,
            (string)$contact['email'],
            $subject,
            $ok ? 'sent' : 'failed',
            $error,
            $ok ? date('Y-m-d H:i:s') : null,
        ]);
        if ($ok) {
            $mark->execute([$revisionId, $code]);
        }
        $results[] = [
            'code' => $code,
            'email' => (string)$contact['email'],
            'ok' => $ok,
            'error' => $error,
        ];
    }

    return $results;
}

function qcWorkflowArchiveAndRelease(PDO $pdo, int $revisionId): string
{
    $stmt = $pdo->prepare("SELECT r.*, d.document_no, d.title, d.current_revision_id
        FROM qc_document_revisions r
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$revisionId]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rev) {
        throw new RuntimeException('Revision nicht gefunden.');
    }

    $documentId = (int)$rev['document_id'];
    $files = qcControlledGetTrackedFiles($pdo, $documentId, qcControlledRootPath());
    $currentHash = qcControlledCombinedHash($files);
    if (!hash_equals((string)$rev['source_combined_hash'], $currentHash)) {
        throw new RuntimeException('Der Dateistand hat sich verändert. Freigabe wurde gestoppt.');
    }

    $safeDoc = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$rev['document_no']);
    $safeRev = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$rev['revision']);
    $archiveRoot = __DIR__ . '/controlled_archive/' . $safeDoc . '/Rev_' . $safeRev;
    if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0775, true) && !is_dir($archiveRoot)) {
        throw new RuntimeException('Archivordner konnte nicht erstellt werden.');
    }

    $manifestFiles = [];
    foreach ($files as $file) {
        if (!$file['exists']) {
            throw new RuntimeException('Datei fehlt: ' . $file['file_path']);
        }
        $relative = ltrim((string)$file['file_path'], '/');
        $source = rtrim(qcControlledRootPath(), '/\\') . DIRECTORY_SEPARATOR . $relative;
        $target = $archiveRoot . '/files/' . $relative;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Archiv-Unterordner konnte nicht erstellt werden.');
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Datei konnte nicht archiviert werden: ' . $file['file_path']);
        }
        $snapshotPath = str_replace('\\', '/', substr($target, strlen(qcControlledRootPath())));
        $pdo->prepare("UPDATE qc_document_revision_files SET snapshot_path = ? WHERE revision_id = ? AND file_path = ?")
            ->execute([$snapshotPath, $revisionId, $file['file_path']]);
        $manifestFiles[] = [
            'file_path' => $file['file_path'],
            'sha256' => $file['sha256'],
            'size_bytes' => $file['size_bytes'],
            'snapshot_path' => $snapshotPath,
        ];
    }

    $approvalsStmt = $pdo->prepare("SELECT approver_code, approver_user_id, approver_email, approval_role, decision, comment, decided_at
        FROM qc_document_approvals WHERE revision_id = ? ORDER BY id");
    $approvalsStmt->execute([$revisionId]);
    $approvals = $approvalsStmt->fetchAll(PDO::FETCH_ASSOC);

    $manifest = [
        'document_no' => $rev['document_no'],
        'title' => $rev['title'],
        'revision' => $rev['revision'],
        'combined_hash' => $currentHash,
        'approved_at' => date(DATE_ATOM),
        'files' => $manifestFiles,
        'approvals' => $approvals,
    ];
    file_put_contents($archiveRoot . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $pdo->beginTransaction();
    try {
        $oldRevisionId = (int)($rev['current_revision_id'] ?? 0);
        if ($oldRevisionId > 0 && $oldRevisionId !== $revisionId) {
            $pdo->prepare("UPDATE qc_document_revisions SET status = 'superseded' WHERE id = ?")
                ->execute([$oldRevisionId]);
        }
        $pdo->prepare("UPDATE qc_document_revisions SET status = 'released', approved_at = NOW() WHERE id = ?")
            ->execute([$revisionId]);
        $pdo->prepare("UPDATE qc_controlled_documents SET current_revision_id = ? WHERE id = ?")
            ->execute([$revisionId, $documentId]);
        qcControlledHistory($pdo, $documentId, $revisionId, 'revision_released',
            'released:' . $revisionId . ':' . $currentHash,
            ['revision' => $rev['revision'], 'combined_hash' => $currentHash, 'archive' => $archiveRoot]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $archiveRoot;
}

function qcWorkflowRecordDecision(PDO $pdo, string $rawToken, string $decision, string $comment): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new RuntimeException('Ungültige Entscheidung.');
    }

    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT a.*, r.document_id, r.revision, r.status AS revision_status, r.source_combined_hash,
            d.document_no, d.title
        FROM qc_document_approvals a
        JOIN qc_document_revisions r ON r.id = a.revision_id
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE a.approval_token_hash = ? LIMIT 1");
    $stmt->execute([$tokenHash]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$approval) {
        throw new RuntimeException('Freigabelink ist ungültig oder nicht mehr aktiv.');
    }

    $currentUserId = qcWorkflowCurrentUserId();
    if ($currentUserId <= 0 || $currentUserId !== (int)$approval['approver_user_id']) {
        throw new RuntimeException('Dieser Freigabelink ist einem anderen Workbench-Benutzer zugeordnet.');
    }
    if ((string)$approval['revision_status'] !== 'in_review') {
        throw new RuntimeException('Diese Revision befindet sich nicht mehr in Prüfung.');
    }

    $documentId = (int)$approval['document_id'];
    $inspect = qcControlledInspectDocument($pdo, $documentId, qcControlledRootPath());
    if ($inspect['changed']) {
        qcWorkflowInvalidateReviewIfChanged($pdo, $documentId, $inspect);
        throw new RuntimeException('Der Dateistand wurde nach Prüfungsstart verändert. Die Prüfung wurde automatisch zurückgesetzt.');
    }
    if (!hash_equals((string)$approval['document_hash'], (string)$inspect['current_combined_hash'])) {
        throw new RuntimeException('Dokument-Hash stimmt nicht mehr mit der versendeten Prüfung überein.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE qc_document_approvals
            SET decision = ?, comment = ?, decided_at = NOW(), approval_token_hash = NULL
            WHERE id = ?")
            ->execute([$decision, $comment !== '' ? $comment : null, (int)$approval['id']]);

        qcControlledHistory($pdo, $documentId, (int)$approval['revision_id'],
            $decision === 'approved' ? 'approval_granted' : 'approval_rejected',
            'decision:' . (int)$approval['id'] . ':' . $decision,
            ['approver_code' => $approval['approver_code'], 'user_id' => $currentUserId, 'comment' => $comment]);

        if ($decision === 'rejected') {
            $pdo->prepare("UPDATE qc_document_revisions SET status = 'rejected' WHERE id = ?")
                ->execute([(int)$approval['revision_id']]);
            $pdo->prepare("UPDATE qc_document_approvals
                SET approval_token_hash = NULL
                WHERE revision_id = ? AND decision = 'pending'")
                ->execute([(int)$approval['revision_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $released = false;
    if ($decision === 'approved') {
        $stmt = $pdo->prepare("SELECT
            SUM(decision = 'approved') AS approved_count,
            SUM(decision = 'pending') AS pending_count,
            SUM(decision = 'rejected') AS rejected_count,
            COUNT(*) AS total_count
            FROM qc_document_approvals WHERE revision_id = ?");
        $stmt->execute([(int)$approval['revision_id']]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($counts['total_count'] ?? 0) > 0
            && (int)($counts['pending_count'] ?? 0) === 0
            && (int)($counts['rejected_count'] ?? 0) === 0
            && (int)($counts['approved_count'] ?? 0) === (int)($counts['total_count'] ?? 0)) {
            qcWorkflowArchiveAndRelease($pdo, (int)$approval['revision_id']);
            $released = true;
        }
    }

    return [
        'document_no' => (string)$approval['document_no'],
        'title' => (string)$approval['title'],
        'revision' => (string)$approval['revision'],
        'decision' => $decision,
        'released' => $released,
        'approver_code' => (string)$approval['approver_code'],
    ];
}

function qcWorkflowGetApprovalByToken(PDO $pdo, string $rawToken): ?array
{
    if ($rawToken === '') {
        return null;
    }
    $hash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("SELECT a.*, r.document_id, r.revision, r.status AS revision_status,
            r.change_reason, r.change_description, d.document_no, d.title
        FROM qc_document_approvals a
        JOIN qc_document_revisions r ON r.id = a.revision_id
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE a.approval_token_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
