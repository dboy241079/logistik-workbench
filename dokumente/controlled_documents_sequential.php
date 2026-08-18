<?php
declare(strict_types=1);

require_once __DIR__ . '/controlled_documents_workflow.php';

function qcSequentialStageLabels(): array
{
    return [
        'MAD' => 'Betriebsleiter',
        'MED' => 'Operations Manager',
        'CBE' => 'Geschäftsführer',
    ];
}

function qcSequentialEnsureSchema(PDO $pdo): void
{
    qcWorkflowEnsureSchema($pdo);

    $roles = qcSequentialStageLabels();
    $stmt = $pdo->prepare("UPDATE qc_document_approvals SET approval_role = ? WHERE approver_code = ?");
    foreach ($roles as $code => $label) {
        $stmt->execute([$label, $code]);
    }

    $stmt = $pdo->prepare("UPDATE qc_document_approver_contacts SET display_name = ? WHERE approver_code = ?");
    foreach ($roles as $code => $label) {
        $stmt->execute([$label, $code]);
    }
}

function qcSequentialOrderedCodes(): array
{
    return ['MAD', 'MED', 'CBE'];
}

function qcSequentialNotifyApprover(PDO $pdo, int $revisionId, string $code): array
{
    if (!in_array($code, qcSequentialOrderedCodes(), true)) {
        throw new RuntimeException('Unbekannte Freigabestufe.');
    }

    $stageLabel = qcSequentialStageLabels()[$code] ?? $code;

    $stmt = $pdo->prepare("SELECT r.id AS revision_id, r.revision, r.status, r.document_id, r.source_combined_hash,
            d.document_no, d.title,
            a.id AS approval_id, a.decision,
            c.user_id, c.email
        FROM qc_document_revisions r
        JOIN qc_controlled_documents d ON d.id = r.document_id
        JOIN qc_document_approvals a ON a.revision_id = r.id AND a.approver_code = ?
        JOIN qc_document_approver_contacts c ON c.approver_code = a.approver_code AND c.active = 1
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$code, $revisionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Freigabestufe konnte nicht geladen werden.');
    }
    if ((string)$row['status'] !== 'in_review') {
        throw new RuntimeException('Die Revision befindet sich nicht in Prüfung.');
    }
    if ((string)$row['decision'] !== 'pending') {
        throw new RuntimeException('Diese Freigabestufe ist nicht mehr offen.');
    }

    $userId = (int)($row['user_id'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException($stageLabel . ' ist noch nicht vollständig mit Workbench-Benutzer und E-Mail-Adresse konfiguriert.');
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $pdo->prepare("UPDATE qc_document_approvals
        SET approver_user_id = ?, approver_email = ?, approval_token_hash = ?, token_created_at = NOW(), notified_at = NULL
        WHERE id = ?")
        ->execute([$userId, $email, $tokenHash, (int)$row['approval_id']]);

    $link = qcWorkflowBaseUrl() . '/dokumente/dokumentenfreigabe.php?token=' . rawurlencode($rawToken);
    $subject = 'Dokumentenfreigabe – ' . $row['document_no'] . ' Rev. ' . $row['revision'] . ' – ' . $stageLabel;
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#111">'
        . '<h2>Dokumentenfreigabe</h2>'
        . '<p>Die Revision <b>' . htmlspecialchars((string)$row['revision']) . '</b> des gelenkten Dokuments '
        . '<b>' . htmlspecialchars((string)$row['document_no']) . ' – ' . htmlspecialchars((string)$row['title']) . '</b> '
        . 'ist jetzt bei dir als <b>' . htmlspecialchars($stageLabel) . '</b> zur Prüfung.</p>'
        . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:10px 16px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:6px">Dokument prüfen</a></p>'
        . '<p style="font-size:12px;color:#666">Erst nach deiner Bestätigung wird automatisch die nächste Freigabestufe informiert. Änderungen am Dateistand machen die laufende Prüfung ungültig.</p>'
        . '</body></html>';

    [$ok, $error] = qcWorkflowSendMail($email, $subject, $html);

    $pdo->prepare("INSERT INTO qc_document_mail_log
        (revision_id, approver_code, recipient_email, subject, status, error_message, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $revisionId,
            $code,
            $email,
            $subject,
            $ok ? 'sent' : 'failed',
            $error,
            $ok ? date('Y-m-d H:i:s') : null,
        ]);

    if ($ok) {
        $pdo->prepare("UPDATE qc_document_approvals SET notified_at = NOW() WHERE id = ?")
            ->execute([(int)$row['approval_id']]);
    }

    qcControlledHistory(
        $pdo,
        (int)$row['document_id'],
        $revisionId,
        'approval_stage_notified',
        'notify:' . $revisionId . ':' . $code . ':' . $tokenHash,
        ['approver_code' => $code, 'stage' => $stageLabel, 'email' => $email, 'mail_ok' => $ok]
    );

    return [
        'code' => $code,
        'stage' => $stageLabel,
        'email' => $email,
        'ok' => $ok,
        'error' => $error,
    ];
}

function qcSequentialStartReview(PDO $pdo, int $revisionId): array
{
    if (!qcWorkflowCanManage()) {
        throw new RuntimeException('Keine Berechtigung zum Starten der Prüfung.');
    }

    qcSequentialEnsureSchema($pdo);

    $stmt = $pdo->prepare("SELECT r.*, d.document_no, d.title
        FROM qc_document_revisions r
        JOIN qc_controlled_documents d ON d.id = r.document_id
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$revisionId]);
    $rev = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rev) {
        throw new RuntimeException('Revision nicht gefunden.');
    }
    if (!in_array((string)$rev['status'], ['draft', 'rejected'], true)) {
        throw new RuntimeException('Nur ein Entwurf kann neu zur Freigabe gestartet werden.');
    }

    $documentId = (int)$rev['document_id'];
    $inspect = qcControlledInspectDocument($pdo, $documentId, qcControlledRootPath());
    if ((int)($inspect['latest_revision']['id'] ?? 0) !== $revisionId) {
        throw new RuntimeException('Nur die neueste Revision kann geprüft werden.');
    }
    if ($inspect['changed']) {
        throw new RuntimeException('Der Dateistand weicht vom Entwurf ab. Bitte zuerst den aktuellen Dateistand in den Entwurf übernehmen.');
    }

    $contacts = qcWorkflowContacts($pdo);
    $byCode = [];
    foreach ($contacts as $contact) {
        $byCode[(string)$contact['approver_code']] = $contact;
    }
    foreach (qcSequentialOrderedCodes() as $code) {
        $contact = $byCode[$code] ?? null;
        if (!$contact || (int)($contact['user_id'] ?? 0) <= 0 || !filter_var((string)($contact['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $label = qcSequentialStageLabels()[$code] ?? $code;
            throw new RuntimeException($label . ' ist noch nicht vollständig mit Benutzer und E-Mail-Adresse konfiguriert.');
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE qc_document_revisions
            SET status = 'in_review', submitted_at = NOW(), approved_at = NULL
            WHERE id = ?")
            ->execute([$revisionId]);

        $reset = $pdo->prepare("UPDATE qc_document_approvals
            SET approver_user_id = ?, approver_email = ?, decision = 'pending', comment = NULL, decided_at = NULL,
                document_hash = ?, approval_token_hash = NULL, token_created_at = NULL, notified_at = NULL
            WHERE revision_id = ? AND approver_code = ?");

        foreach (qcSequentialOrderedCodes() as $code) {
            $contact = $byCode[$code];
            $reset->execute([
                (int)$contact['user_id'],
                (string)$contact['email'],
                (string)$inspect['current_combined_hash'],
                $revisionId,
                $code,
            ]);
        }

        qcControlledHistory(
            $pdo,
            $documentId,
            $revisionId,
            'review_started_sequential',
            'review-sequential:' . $revisionId . ':' . (string)$inspect['current_combined_hash'],
            ['revision' => $rev['revision'], 'order' => qcSequentialOrderedCodes()]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [qcSequentialNotifyApprover($pdo, $revisionId, 'MAD')];
}

function qcSequentialNextPendingCode(PDO $pdo, int $revisionId): ?string
{
    $stmt = $pdo->prepare("SELECT approver_code, decision
        FROM qc_document_approvals
        WHERE revision_id = ?
        ORDER BY FIELD(approver_code, 'MAD','MED','CBE'), id");
    $stmt->execute([$revisionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach (qcSequentialOrderedCodes() as $code) {
        foreach ($rows as $row) {
            if ((string)$row['approver_code'] === $code && (string)$row['decision'] === 'pending') {
                return $code;
            }
        }
    }
    return null;
}

function qcSequentialRecordDecision(PDO $pdo, string $rawToken, string $decision, string $comment): array
{
    $before = qcWorkflowGetApprovalByToken($pdo, $rawToken);
    if (!$before) {
        throw new RuntimeException('Freigabelink ist ungültig oder nicht mehr aktiv.');
    }

    $result = qcWorkflowRecordDecision($pdo, $rawToken, $decision, $comment);
    $result['next_notification'] = null;

    if ($decision === 'approved' && !$result['released']) {
        $nextCode = qcSequentialNextPendingCode($pdo, (int)$before['revision_id']);
        if ($nextCode !== null) {
            $result['next_notification'] = qcSequentialNotifyApprover($pdo, (int)$before['revision_id'], $nextCode);
        }
    }

    return $result;
}
