<?php
declare(strict_types=1);

require_once __DIR__ . '/controlled_legacy_materialize.php';

function qcCenterStageOrder(): array
{
    return ['MAD', 'MED', 'CBE'];
}

function qcCenterStageLabel(string $code): string
{
    return match ($code) {
        'MAD' => 'Betriebsleiter',
        'MED' => 'Operations Manager',
        'CBE' => 'Geschäftsführer',
        default => $code,
    };
}

function qcCenterCategoryForDocument(string $documentNo): string
{
    return match ($documentNo) {
        'F_059_0001' => 'Wareneingang',
        default => 'Allgemein / ohne Kategorie',
    };
}

function qcCenterArchiveExists(string $documentNo, string $revision): bool
{
    $safeDoc = preg_replace('/[^A-Za-z0-9_.-]/', '_', $documentNo);
    $safeRev = preg_replace('/[^A-Za-z0-9_.-]/', '_', $revision);
    if ($safeDoc === '' || $safeRev === '') {
        return false;
    }

    $filesDir = __DIR__ . '/controlled_archive/' . $safeDoc . '/Rev_' . $safeRev . '/files';
    if (is_dir($filesDir)) {
        return true;
    }

    if (qcLegacySnapshotAvailable($documentNo, $revision)) {
        return qcLegacyMaterializeArchive($documentNo, $revision) && is_dir($filesDir);
    }

    return false;
}

function qcCenterLoadApprovals(PDO $pdo, int $revisionId): array
{
    $stmt = $pdo->prepare("SELECT
            a.approver_code,
            a.approval_role,
            a.decision,
            a.comment,
            a.decided_at,
            a.approver_user_id,
            COALESCE(u.display_name, u.username, a.approver_code) AS approver_name
        FROM qc_document_approvals a
        LEFT JOIN users u ON u.id = a.approver_user_id
        WHERE a.revision_id = ?
        ORDER BY FIELD(a.approver_code, 'MAD','MED','CBE'), a.id");
    $stmt->execute([$revisionId]);

    $byCode = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = (string)$row['approver_code'];
        $row['stage_label'] = qcCenterStageLabel($code);
        $byCode[$code] = $row;
    }

    $result = [];
    foreach (qcCenterStageOrder() as $code) {
        if (isset($byCode[$code])) {
            $result[] = $byCode[$code];
        }
    }
    return $result;
}

function qcCenterLoadRevisions(PDO $pdo, int $documentId, int $currentRevisionId, string $documentNo): array
{
    $stmt = $pdo->prepare("SELECT id, revision, status, change_type, change_reason, change_description,
            created_at, submitted_at, approved_at
        FROM qc_document_revisions
        WHERE document_id = ?
          AND status IN ('released','superseded')
        ORDER BY id DESC");
    $stmt->execute([$documentId]);

    $current = null;
    $older = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $revision) {
        $revisionId = (int)$revision['id'];
        $revisionNo = (string)$revision['revision'];
        $revision['archive_available'] = qcCenterArchiveExists($documentNo, $revisionNo);
        $revision['approvals'] = qcCenterLoadApprovals($pdo, $revisionId);

        if ($revisionId === $currentRevisionId) {
            $current = $revision;
        } else {
            $older[] = $revision;
        }
    }

    return ['current' => $current, 'older' => $older];
}

function qcCenterLoadControlledDocuments(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT
            d.id,
            d.document_no,
            d.title,
            d.description,
            d.owner_code,
            d.current_revision_id,
            d.created_at,
            d.updated_at,
            r.revision AS current_revision,
            r.status AS current_status,
            r.change_reason AS current_change_reason,
            r.change_description AS current_change_description,
            r.approved_at AS current_approved_at
        FROM qc_controlled_documents d
        JOIN qc_document_revisions r ON r.id = d.current_revision_id
        WHERE d.active = 1
          AND r.status = 'released'
        ORDER BY d.document_no");

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $documentId = (int)$row['id'];
        $currentRevisionId = (int)$row['current_revision_id'];
        $documentNo = (string)$row['document_no'];
        $revisions = qcCenterLoadRevisions($pdo, $documentId, $currentRevisionId, $documentNo);

        if (!$revisions['current']) {
            continue;
        }

        $currentApprovals = $revisions['current']['approvals'];
        $finalApprover = null;
        foreach ($currentApprovals as $approval) {
            if ((string)$approval['approver_code'] === 'CBE' && (string)$approval['decision'] === 'approved') {
                $finalApprover = $approval;
                break;
            }
        }

        $title = $documentNo . ' – ' . (string)$row['title'];
        $category = qcCenterCategoryForDocument($documentNo);
        $result[] = [
            '_controlled' => true,
            'id' => 'controlled-' . $documentId,
            'title' => $title,
            'original_name' => 'Gelenktes Dokument · Rev. ' . (string)$row['current_revision'],
            'filename' => '',
            'category' => $category,
            'hall' => $category,
            'created_at' => (string)($row['current_approved_at'] ?: $row['updated_at'] ?: $row['created_at']),
            'uploaded_by' => null,
            'visible_roles' => null,
            'active' => 1,
            'uploader' => $finalApprover
                ? ((string)$finalApprover['approver_name'] . ' · Geschäftsführer')
                : 'Dokumentenlenkung',
            'document_no' => $documentNo,
            'document_title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'owner_code' => (string)($row['owner_code'] ?? ''),
            'current_revision_id' => $currentRevisionId,
            'current_revision' => (string)$row['current_revision'],
            'current_approved_at' => (string)($row['current_approved_at'] ?? ''),
            'current_change_reason' => (string)($row['current_change_reason'] ?? ''),
            'current_change_description' => (string)($row['current_change_description'] ?? ''),
            'current_archive_available' => (bool)$revisions['current']['archive_available'],
            'approvals' => $currentApprovals,
            'older_revisions' => $revisions['older'],
        ];
    }

    return $result;
}
