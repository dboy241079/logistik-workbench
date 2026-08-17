<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/controlled_documents_bootstrap.php';
require_once __DIR__ . '/controlled_documents_workflow.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3 small">Kein Zugriff. Bitte zuerst einloggen.</div>';
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
qcWorkflowEnsureSchema($pdo);

function cdE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cdStatusLabel(string $status): array
{
    return match ($status) {
        'released' => ['Freigegeben', 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        'in_review' => ['In Prüfung', 'bg-amber-100 text-amber-800 border-amber-200'],
        'draft' => ['Entwurf', 'bg-sky-100 text-sky-800 border-sky-200'],
        'rejected' => ['Abgelehnt', 'bg-red-100 text-red-800 border-red-200'],
        'superseded' => ['Ersetzt', 'bg-slate-100 text-slate-600 border-slate-200'],
        default => [$status !== '' ? $status : '–', 'bg-slate-100 text-slate-600 border-slate-200'],
    };
}

$flashSuccess = null;
$flashError = null;
$mailResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        qcWorkflowRequireCsrf($_POST['csrf'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_contacts') {
            qcWorkflowSaveContacts($pdo, [
                'MAD' => [
                    'user_id' => $_POST['contact_user_MAD'] ?? null,
                    'email' => $_POST['contact_email_MAD'] ?? '',
                    'display_name' => 'MAD',
                ],
                'MED' => [
                    'user_id' => $_POST['contact_user_MED'] ?? null,
                    'email' => $_POST['contact_email_MED'] ?? '',
                    'display_name' => 'MED',
                ],
                'CBE' => [
                    'user_id' => $_POST['contact_user_CBE'] ?? null,
                    'email' => $_POST['contact_email_CBE'] ?? '',
                    'display_name' => 'CBE',
                ],
            ]);
            $flashSuccess = 'Prüfer-Zuordnung wurde gespeichert.';
        } elseif ($action === 'refresh_snapshot') {
            qcWorkflowRefreshDraftSnapshot($pdo, (int)($_POST['revision_id'] ?? 0));
            $flashSuccess = 'Der aktuelle Dateistand wurde in den Entwurf übernommen. Alle bisherigen Prüfungen wurden zurückgesetzt.';
        } elseif ($action === 'start_review') {
            $mailResults = qcWorkflowStartReview($pdo, (int)($_POST['revision_id'] ?? 0));
            $sent = count(array_filter($mailResults, static fn(array $r): bool => (bool)$r['ok']));
            $failed = count($mailResults) - $sent;
            if ($failed === 0) {
                $flashSuccess = 'Prüfung gestartet. Die Prüfmail wurde an MAD, MED und CBE versendet.';
            } else {
                $flashError = 'Prüfung wurde gestartet, aber ' . $failed . ' von ' . count($mailResults) . ' E-Mails konnten vom Server nicht bestätigt werden. Die Freigabelinks sind trotzdem angelegt; prüfe unten das Mailprotokoll.';
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }
}

try {
    $summary = qcControlledBootstrap($pdo);
    $rootPath = qcControlledRootPath();

    $docs = $pdo->query("SELECT d.*,
            r.revision AS current_revision,
            r.status AS current_status
        FROM qc_controlled_documents d
        LEFT JOIN qc_document_revisions r ON r.id = d.current_revision_id
        WHERE d.active = 1
        ORDER BY d.document_no")
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as &$doc) {
        $docId = (int)$doc['id'];
        $inspect = qcControlledInspectDocument($pdo, $docId, $rootPath);

        if (qcWorkflowInvalidateReviewIfChanged($pdo, $docId, $inspect)) {
            $inspect = qcControlledInspectDocument($pdo, $docId, $rootPath);
            if ($flashError === null && $flashSuccess === null) {
                $flashError = 'Eine laufende Dokumentenprüfung wurde zurückgesetzt, weil sich überwachte Quelldateien verändert haben.';
            }
        }

        $doc['inspect'] = $inspect;

        $stmt = $pdo->prepare("SELECT * FROM qc_document_revisions WHERE document_id = ? ORDER BY id DESC");
        $stmt->execute([$docId]);
        $doc['revisions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latestRevisionId = (int)($inspect['latest_revision']['id'] ?? 0);
        $doc['approvals'] = [];
        $doc['mail_log'] = [];
        if ($latestRevisionId > 0) {
            $stmt = $pdo->prepare("SELECT approver_code, approver_user_id, approver_email, approval_role, decision, comment, decided_at, notified_at
                FROM qc_document_approvals
                WHERE revision_id = ?
                ORDER BY FIELD(approver_code, 'MAD','MED','CBE'), id");
            $stmt->execute([$latestRevisionId]);
            $doc['approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT approver_code, recipient_email, status, error_message, sent_at, created_at
                FROM qc_document_mail_log
                WHERE revision_id = ?
                ORDER BY id DESC
                LIMIT 12");
            $stmt->execute([$latestRevisionId]);
            $doc['mail_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare("SELECT event_type, details_json, created_at
            FROM qc_document_history
            WHERE document_id = ?
            ORDER BY id DESC
            LIMIT 15");
        $stmt->execute([$docId]);
        $doc['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($doc);

    $contacts = qcWorkflowContacts($pdo);
    $users = qcWorkflowActiveUsers($pdo);
    $contactMap = [];
    foreach ($contacts as $contact) {
        $contactMap[(string)$contact['approver_code']] = $contact;
    }

    $summary = qcControlledGetSummary($pdo, $rootPath);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div style="padding:20px;font-family:Arial,sans-serif">';
    echo '<h2>Dokumentenlenkung konnte nicht geladen werden</h2>';
    echo '<pre>' . cdE($e->getMessage()) . '</pre>';
    echo '</div>';
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentenlenkung</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
</head>
<body class="bg-slate-100 text-slate-900 text-sm">
<div class="w-full py-4 px-3 sm:px-6 lg:px-10 space-y-4">
    <header class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-sky-700">Dokumentencenter</div>
            <h1 class="text-xl font-semibold">Dokumentenlenkung</h1>
            <p class="text-xs text-slate-600 mt-1 max-w-3xl">
                Gelenkte Dokumente, Revisionen, Prüfung und Freigabe werden hier zentral verwaltet. Der Dateistand wird per SHA-256 überwacht.
            </p>
        </div>
        <a href="/?tab=docs" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
            ← Dokumentencenter
        </a>
    </header>

    <?php if ($flashSuccess): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800"><b>Erledigt:</b> <?= cdE($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-800"><b>Hinweis:</b> <?= cdE($flashError) ?></div>
    <?php endif; ?>

    <section class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[11px] text-slate-500">Gelenkte Dokumente</div><div class="text-2xl font-semibold mt-1"><?= (int)$summary['total'] ?></div></div>
        <div class="rounded-xl border border-emerald-200 bg-white p-4 shadow-sm"><div class="text-[11px] text-slate-500">Freigegeben</div><div class="text-2xl font-semibold text-emerald-700 mt-1"><?= (int)$summary['released'] ?></div></div>
        <div class="rounded-xl border border-sky-200 bg-white p-4 shadow-sm"><div class="text-[11px] text-slate-500">Entwürfe</div><div class="text-2xl font-semibold text-sky-700 mt-1"><?= (int)$summary['draft'] ?></div></div>
        <div class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm"><div class="text-[11px] text-slate-500">In Prüfung</div><div class="text-2xl font-semibold text-amber-700 mt-1"><?= (int)$summary['in_review'] ?></div></div>
        <div class="rounded-xl border <?= $summary['changed'] ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' ?> p-4 shadow-sm"><div class="text-[11px] text-slate-500">Dateiänderungen</div><div class="text-2xl font-semibold <?= $summary['changed'] ? 'text-red-700' : 'text-slate-700' ?> mt-1"><?= (int)$summary['changed'] ?></div></div>
    </section>

    <?php if (qcWorkflowCanManage()): ?>
        <details class="rounded-xl border border-violet-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer list-none p-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-sm font-semibold">MAD / MED / CBE – Prüfer-Zuordnung</div>
                    <div class="text-[11px] text-slate-500 mt-1">Einmalig Workbench-Benutzer und E-Mail-Adresse zuordnen. Die Bestätigung ist nur mit dem zugeordneten Benutzer möglich.</div>
                </div>
                <span class="text-xs font-semibold text-violet-700">Konfiguration</span>
            </summary>
            <form method="post" class="border-t border-slate-100 p-4">
                <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                <input type="hidden" name="action" value="save_contacts">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
                    <?php foreach (['MAD'=>'Ersteller','MED'=>'Prüfer','CBE'=>'Freigeber'] as $code => $roleLabel): ?>
                        <?php $contact = $contactMap[$code] ?? []; ?>
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="font-semibold"><?= cdE($code) ?> <span class="font-normal text-xs text-slate-500">· <?= cdE($roleLabel) ?></span></div>
                            <label class="block text-[11px] font-semibold text-slate-600 mt-3 mb-1">Workbench-Benutzer</label>
                            <select name="contact_user_<?= cdE($code) ?>" class="w-full rounded-lg border-slate-300 text-xs">
                                <option value="">Bitte auswählen …</option>
                                <?php foreach ($users as $user): ?>
                                    <?php $label = trim((string)($user['display_name'] ?? '')) ?: (string)$user['username']; ?>
                                    <option value="<?= (int)$user['id'] ?>" <?= ((int)($contact['user_id'] ?? 0) === (int)$user['id']) ? 'selected' : '' ?>><?= cdE($label . ' (' . $user['username'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="block text-[11px] font-semibold text-slate-600 mt-3 mb-1">E-Mail-Adresse</label>
                            <input type="email" name="contact_email_<?= cdE($code) ?>" value="<?= cdE((string)($contact['email'] ?? '')) ?>" class="w-full rounded-lg border-slate-300 text-xs" placeholder="name@firma.de">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="mt-4 rounded-lg bg-violet-600 px-4 py-2 text-xs font-semibold text-white hover:bg-violet-700">Prüfer-Zuordnung speichern</button>
            </form>
        </details>
    <?php endif; ?>

    <?php foreach ($docs as $doc): ?>
        <?php
        $inspect = $doc['inspect'];
        $latest = $inspect['latest_revision'] ?? [];
        [$latestLabel, $latestClass] = cdStatusLabel((string)($latest['status'] ?? ''));
        $latestRevisionId = (int)($latest['id'] ?? 0);
        $latestStatus = (string)($latest['status'] ?? '');
        ?>
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-sky-700"><?= cdE((string)$doc['document_no']) ?></span>
                        <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($latestClass) ?>">Rev. <?= cdE((string)($latest['revision'] ?? '–')) ?> · <?= cdE($latestLabel) ?></span>
                        <?php if ($inspect['changed']): ?>
                            <span class="inline-flex rounded-full border border-red-200 bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-800">⚠ Dateiänderung erkannt</span>
                        <?php else: ?>
                            <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">✓ Dateistand stimmt mit Revision überein</span>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-base font-semibold mt-1"><?= cdE((string)$doc['title']) ?></h2>
                    <p class="text-xs text-slate-500 mt-1"><?= cdE((string)($doc['description'] ?? '')) ?></p>
                </div>
                <div class="text-xs text-slate-500 lg:text-right">
                    <div>Dokumentenverantwortung: <b class="text-slate-700"><?= cdE((string)($doc['owner_code'] ?? '–')) ?></b></div>
                    <div class="mt-1">Aktuell freigegeben: <b class="text-slate-700">Rev. <?= cdE((string)($doc['current_revision'] ?? '–')) ?></b></div>
                </div>
            </div>

            <?php if (qcWorkflowCanManage() && $latestRevisionId > 0): ?>
                <div class="border-b border-slate-100 bg-slate-50 p-4 flex flex-wrap gap-2">
                    <?php if ($inspect['changed'] && in_array($latestStatus, ['draft','rejected'], true)): ?>
                        <form method="post" onsubmit="return confirm('Aktuellen Dateistand in Rev. <?= cdE((string)($latest['revision'] ?? '')) ?> übernehmen? Bestehende Prüfentscheidungen werden zurückgesetzt.');">
                            <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                            <input type="hidden" name="action" value="refresh_snapshot">
                            <input type="hidden" name="revision_id" value="<?= $latestRevisionId ?>">
                            <button type="submit" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-700">↻ Dateistand in Entwurf übernehmen</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$inspect['changed'] && in_array($latestStatus, ['draft','in_review'], true)): ?>
                        <form method="post" onsubmit="return confirm('Rev. <?= cdE((string)($latest['revision'] ?? '')) ?> jetzt an MAD, MED und CBE zur Prüfung versenden?');">
                            <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                            <input type="hidden" name="action" value="start_review">
                            <input type="hidden" name="revision_id" value="<?= $latestRevisionId ?>">
                            <button type="submit" class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-600">✉ Zur Prüfung versenden</button>
                        </form>
                    <?php endif; ?>

                    <a href="/druck_wa.html" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">Dokument öffnen ↗</a>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-0">
                <div class="p-4 xl:border-r border-slate-100">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Überwachte Dateien</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($inspect['current_files'] as $file): ?>
                            <?php
                            $state = (string)$file['state'];
                            $stateLabel = $state === 'unchanged' ? 'Unverändert' : ($state === 'missing' ? 'Fehlt' : 'Geändert');
                            $stateClass = $state === 'unchanged' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-red-700 bg-red-50 border-red-200';
                            ?>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0"><div class="text-xs font-medium text-slate-800 truncate"><?= cdE((string)$file['file_path']) ?></div><div class="text-[10px] text-slate-500 mt-0.5"><?= cdE((string)($file['label'] ?? '')) ?></div></div>
                                    <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($stateClass) ?>"><?= cdE($stateLabel) ?></span>
                                </div>
                                <div class="mt-2 font-mono text-[9px] text-slate-400 break-all">SHA-256: <?= cdE((string)($file['sha256'] ?? 'nicht verfügbar')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="p-4 xl:border-r border-slate-100">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Revisionen</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['revisions'] as $rev): ?>
                            <?php [$statusText, $statusClass] = cdStatusLabel((string)$rev['status']); ?>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="flex items-center justify-between gap-2"><div class="font-semibold">Rev. <?= cdE((string)$rev['revision']) ?></div><span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($statusClass) ?>"><?= cdE($statusText) ?></span></div>
                                <?php if (!empty($rev['change_reason'])): ?><div class="text-xs text-slate-700 mt-2"><?= cdE((string)$rev['change_reason']) ?></div><?php endif; ?>
                                <?php if (!empty($rev['change_description'])): ?><div class="text-[11px] text-slate-500 mt-1 leading-relaxed"><?= nl2br(cdE((string)$rev['change_description'])) ?></div><?php endif; ?>
                                <div class="text-[10px] text-slate-400 mt-2">Angelegt: <?= cdE(date('d.m.Y H:i', strtotime((string)$rev['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="p-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Prüfkette der neuesten Revision</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['approvals'] as $approval): ?>
                            <?php
                            $decision = (string)$approval['decision'];
                            $decisionText = $decision === 'approved' ? 'Bestätigt' : ($decision === 'rejected' ? 'Abgelehnt' : 'Ausstehend');
                            $decisionClass = $decision === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($decision === 'rejected' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                            ?>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div><div class="text-xs font-semibold"><?= cdE((string)$approval['approver_code']) ?></div><div class="text-[10px] text-slate-500"><?= cdE((string)$approval['approval_role']) ?></div></div>
                                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($decisionClass) ?>"><?= cdE($decisionText) ?></span>
                                </div>
                                <?php if (!empty($approval['approver_email'])): ?><div class="text-[10px] text-slate-400 mt-2"><?= cdE((string)$approval['approver_email']) ?></div><?php endif; ?>
                                <?php if (!empty($approval['decided_at'])): ?><div class="text-[10px] text-slate-400 mt-1">Entschieden: <?= cdE(date('d.m.Y H:i', strtotime((string)$approval['decided_at']))) ?></div><?php endif; ?>
                                <?php if (!empty($approval['comment'])): ?><div class="text-[11px] text-slate-600 mt-2 border-l-2 border-slate-200 pl-2"><?= nl2br(cdE((string)$approval['comment'])) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($doc['mail_log']): ?>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mt-5">Mailprotokoll</h3>
                        <div class="mt-3 space-y-2">
                            <?php foreach ($doc['mail_log'] as $mail): ?>
                                <div class="rounded-lg border border-slate-200 p-2 text-[10px]">
                                    <div class="flex items-center justify-between gap-2"><span class="font-semibold"><?= cdE((string)$mail['approver_code']) ?> · <?= cdE((string)$mail['recipient_email']) ?></span><span class="<?= $mail['status'] === 'sent' ? 'text-emerald-700' : 'text-red-700' ?> font-semibold"><?= $mail['status'] === 'sent' ? 'Versendet' : 'Fehler' ?></span></div>
                                    <?php if (!empty($mail['error_message'])): ?><div class="text-red-600 mt-1"><?= cdE((string)$mail['error_message']) ?></div><?php endif; ?>
                                    <div class="text-slate-400 mt-1"><?= cdE(date('d.m.Y H:i', strtotime((string)($mail['sent_at'] ?: $mail['created_at'])))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mt-5">Protokoll</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['history'] as $event): ?>
                            <?php
                            $eventLabel = match ((string)$event['event_type']) {
                                'revision_imported' => 'Ausgangsrevision übernommen',
                                'revision_created' => 'Neue Revision angelegt',
                                'file_change_detected' => 'Dateiänderung erkannt',
                                'draft_snapshot_refreshed' => 'Dateistand in Entwurf übernommen',
                                'review_started' => 'Prüfung gestartet',
                                'review_invalidated' => 'Prüfung wegen Dateiänderung zurückgesetzt',
                                'approval_granted' => 'Bestätigung erteilt',
                                'approval_rejected' => 'Revision abgelehnt',
                                'revision_released' => 'Revision freigegeben und archiviert',
                                default => (string)$event['event_type'],
                            };
                            ?>
                            <div class="border-l-2 border-slate-200 pl-3 py-1"><div class="text-xs text-slate-700"><?= cdE($eventLabel) ?></div><div class="text-[10px] text-slate-400"><?= cdE(date('d.m.Y H:i', strtotime((string)$event['created_at']))) ?></div></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-xs text-sky-900">
        <b>Freigabelogik:</b> Eine Revision wird erst freigegeben, wenn MAD, MED und CBE mit ihrem jeweils zugeordneten Workbench-Benutzer bestätigt haben. Bei Ablehnung geht sie zurück in die Bearbeitung. Bei einer Dateiänderung während der Prüfung werden offene Freigaben automatisch ungültig. Nach vollständiger Freigabe wird ein unveränderlicher Dateisnapshot mit Manifest im Dokumentenarchiv erzeugt.
    </section>
</div>
</body>
</html>
