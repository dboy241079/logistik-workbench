<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/controlled_documents_bootstrap.php';
require_once __DIR__ . '/controlled_documents_sequential.php';

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3 small">Kein Zugriff. Bitte zuerst einloggen.</div>';
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

try {
    qcSequentialEnsureSchema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        qcWorkflowRequireCsrf($_POST['csrf'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_contacts') {
            qcWorkflowSaveContacts($pdo, [
                'MAD' => [
                    'user_id' => $_POST['contact_user_MAD'] ?? null,
                    'email' => $_POST['contact_email_MAD'] ?? '',
                    'display_name' => 'Betriebsleiter',
                ],
                'MED' => [
                    'user_id' => $_POST['contact_user_MED'] ?? null,
                    'email' => $_POST['contact_email_MED'] ?? '',
                    'display_name' => 'Operations Manager',
                ],
                'CBE' => [
                    'user_id' => $_POST['contact_user_CBE'] ?? null,
                    'email' => $_POST['contact_email_CBE'] ?? '',
                    'display_name' => 'Geschäftsführer',
                ],
            ]);
            qcSequentialEnsureSchema($pdo);
            $flashSuccess = 'Freigabekette wurde gespeichert.';
        } elseif ($action === 'refresh_snapshot') {
            qcWorkflowRefreshDraftSnapshot($pdo, (int)($_POST['revision_id'] ?? 0));
            $flashSuccess = 'Der aktuelle Dateistand wurde in den Entwurf übernommen.';
        } elseif ($action === 'start_review') {
            $mailResults = qcSequentialStartReview($pdo, (int)($_POST['revision_id'] ?? 0));
            $first = $mailResults[0] ?? null;
            if ($first && $first['ok']) {
                $flashSuccess = 'Freigabe gestartet. Zuerst wurde nur der Betriebsleiter informiert.';
            } else {
                $flashError = 'Freigabe wurde gestartet, aber die E-Mail an den Betriebsleiter konnte vom Server nicht bestätigt werden.';
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    }

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
                $flashError = 'Die laufende Prüfung wurde zurückgesetzt, weil sich überwachte Dateien verändert haben.';
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
            $stmt = $pdo->prepare("SELECT approver_code, approver_user_id, approver_email, approval_role, decision, comment,
                    decided_at, notified_at, approval_token_hash
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
                Freigabe immer nacheinander: Betriebsleiter → Operations Manager → Geschäftsführer.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/dokumente/gelenkte_downloads.php?embed=1" class="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">Notfall-Download-Center</a>
            <a href="/?tab=docs" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700">← Dokumentencenter</a>
        </div>
    </header>

    <?php if ($flashSuccess): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800"><b>Erledigt:</b> <?= cdE($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-800"><b>Hinweis:</b> <?= cdE($flashError) ?></div>
    <?php endif; ?>

    <section class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-[11px] text-slate-500">Dokumente</div><div class="text-2xl font-semibold"><?= (int)$summary['total'] ?></div></div>
        <div class="rounded-xl border border-emerald-200 bg-white p-4"><div class="text-[11px] text-slate-500">Freigegeben</div><div class="text-2xl font-semibold text-emerald-700"><?= (int)$summary['released'] ?></div></div>
        <div class="rounded-xl border border-sky-200 bg-white p-4"><div class="text-[11px] text-slate-500">Entwürfe</div><div class="text-2xl font-semibold text-sky-700"><?= (int)$summary['draft'] ?></div></div>
        <div class="rounded-xl border border-amber-200 bg-white p-4"><div class="text-[11px] text-slate-500">In Prüfung</div><div class="text-2xl font-semibold text-amber-700"><?= (int)$summary['in_review'] ?></div></div>
        <div class="rounded-xl border <?= $summary['changed'] ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' ?> p-4"><div class="text-[11px] text-slate-500">Änderungen</div><div class="text-2xl font-semibold <?= $summary['changed'] ? 'text-red-700' : '' ?>"><?= (int)$summary['changed'] ?></div></div>
    </section>

    <?php if (qcWorkflowCanManage()): ?>
        <details class="rounded-xl border border-violet-200 bg-white shadow-sm" open>
            <summary class="cursor-pointer list-none p-4">
                <div class="text-sm font-semibold">Freigabekette konfigurieren</div>
                <div class="text-[11px] text-slate-500 mt-1">Jede Stufe wird erst informiert, wenn die vorherige bestätigt hat.</div>
            </summary>
            <form method="post" class="border-t border-slate-100 p-4">
                <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                <input type="hidden" name="action" value="save_contacts">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
                    <?php foreach (['MAD'=>'1. Betriebsleiter','MED'=>'2. Operations Manager','CBE'=>'3. Geschäftsführer'] as $code => $label): ?>
                        <?php $contact = $contactMap[$code] ?? []; ?>
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="font-semibold"><?= cdE($label) ?></div>
                            <label class="block text-[11px] font-semibold text-slate-600 mt-3 mb-1">Workbench-Benutzer</label>
                            <select name="contact_user_<?= cdE($code) ?>" class="w-full rounded-lg border-slate-300 text-xs">
                                <option value="">Bitte auswählen …</option>
                                <?php foreach ($users as $user): ?>
                                    <?php $userLabel = trim((string)($user['display_name'] ?? '')) ?: (string)$user['username']; ?>
                                    <option value="<?= (int)$user['id'] ?>" <?= ((int)($contact['user_id'] ?? 0) === (int)$user['id']) ? 'selected' : '' ?>><?= cdE($userLabel . ' (' . $user['username'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="block text-[11px] font-semibold text-slate-600 mt-3 mb-1">E-Mail-Adresse</label>
                            <input type="email" name="contact_email_<?= cdE($code) ?>" value="<?= cdE((string)($contact['email'] ?? '')) ?>" class="w-full rounded-lg border-slate-300 text-xs" placeholder="name@firma.de">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="mt-4 rounded-lg bg-violet-600 px-4 py-2 text-xs font-semibold text-white">Freigabekette speichern</button>
            </form>
        </details>
    <?php endif; ?>

    <?php foreach ($docs as $doc): ?>
        <?php
        $inspect = $doc['inspect'];
        $latest = $inspect['latest_revision'] ?? [];
        [$statusText, $statusClass] = cdStatusLabel((string)($latest['status'] ?? ''));
        $latestRevisionId = (int)($latest['id'] ?? 0);
        $latestStatus = (string)($latest['status'] ?? '');
        ?>
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex flex-col gap-3 lg:flex-row lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-sky-700"><?= cdE((string)$doc['document_no']) ?></span>
                        <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($statusClass) ?>">Rev. <?= cdE((string)($latest['revision'] ?? '–')) ?> · <?= cdE($statusText) ?></span>
                        <?php if ($inspect['changed']): ?><span class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-700">Dateiänderung erkannt</span><?php endif; ?>
                    </div>
                    <h2 class="text-base font-semibold mt-1"><?= cdE((string)$doc['title']) ?></h2>
                    <p class="text-xs text-slate-500 mt-1"><?= cdE((string)($doc['description'] ?? '')) ?></p>
                </div>
                <div class="text-xs text-slate-500">Aktuell freigegeben: <b>Rev. <?= cdE((string)($doc['current_revision'] ?? '–')) ?></b></div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3">
                <div class="p-4 xl:border-r border-slate-100">
                    <h3 class="text-xs font-semibold uppercase text-slate-500">Dateistand</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($inspect['current_files'] as $file): ?>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="text-xs font-medium"><?= cdE((string)$file['file_path']) ?></div>
                                <div class="text-[10px] mt-1 <?= $file['state'] === 'unchanged' ? 'text-emerald-700' : 'text-red-700' ?>"><?= cdE($file['state'] === 'unchanged' ? 'Unverändert' : ($file['state'] === 'missing' ? 'Fehlt' : 'Geändert')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (qcWorkflowCanManage() && $inspect['changed'] && in_array($latestStatus, ['draft','rejected'], true)): ?>
                        <form method="post" class="mt-3">
                            <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                            <input type="hidden" name="action" value="refresh_snapshot">
                            <input type="hidden" name="revision_id" value="<?= $latestRevisionId ?>">
                            <button class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white">Dateistand in Entwurf übernehmen</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="p-4 xl:border-r border-slate-100">
                    <h3 class="text-xs font-semibold uppercase text-slate-500">Freigabekette</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['approvals'] as $approval): ?>
                            <?php
                            $decision = (string)$approval['decision'];
                            $active = $decision === 'pending' && !empty($approval['approval_token_hash']);
                            if ($decision === 'approved') {
                                $badge = 'Bestätigt'; $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                            } elseif ($decision === 'rejected') {
                                $badge = 'Abgelehnt'; $badgeClass = 'bg-red-50 text-red-700 border-red-200';
                            } elseif ($active) {
                                $badge = 'Jetzt an der Reihe'; $badgeClass = 'bg-amber-50 text-amber-700 border-amber-200';
                            } else {
                                $badge = 'Wartet'; $badgeClass = 'bg-slate-50 text-slate-500 border-slate-200';
                            }
                            ?>
                            <div class="flex items-center justify-between rounded-lg border border-slate-200 p-3">
                                <div>
                                    <div class="text-xs font-semibold"><?= cdE((string)$approval['approval_role']) ?></div>
                                    <div class="text-[10px] text-slate-500"><?= cdE((string)($approval['approver_email'] ?? '')) ?></div>
                                </div>
                                <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= $badgeClass ?>"><?= cdE($badge) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (qcWorkflowCanManage() && in_array($latestStatus, ['draft','rejected'], true) && !$inspect['changed']): ?>
                        <form method="post" class="mt-4">
                            <input type="hidden" name="csrf" value="<?= cdE(qcWorkflowCsrfToken()) ?>">
                            <input type="hidden" name="action" value="start_review">
                            <input type="hidden" name="revision_id" value="<?= $latestRevisionId ?>">
                            <button class="rounded-lg bg-amber-500 px-4 py-2 text-xs font-semibold text-white">Freigabe beim Betriebsleiter starten</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="p-4">
                    <h3 class="text-xs font-semibold uppercase text-slate-500">Revisionen</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['revisions'] as $rev): ?>
                            <?php [$revText, $revClass] = cdStatusLabel((string)$rev['status']); ?>
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <b>Rev. <?= cdE((string)$rev['revision']) ?></b>
                                    <span class="rounded-full border px-2 py-0.5 text-[10px] <?= $revClass ?>"><?= cdE($revText) ?></span>
                                </div>
                                <?php if (!empty($rev['change_reason'])): ?><div class="text-xs mt-2"><?= cdE((string)$rev['change_reason']) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="/dokumente/gelenkte_downloads.php?embed=1" class="mt-4 inline-flex rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">Freigegebene Notfallstände herunterladen</a>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-xs text-sky-900">
        <b>Ablauf:</b> Betriebsleiter bestätigt → erst dann erhält der Operations Manager seine E-Mail → nach dessen Bestätigung erhält der Geschäftsführer seine E-Mail → erst nach dessen Bestätigung wird die Revision freigegeben und archiviert.
    </section>
</div>
</body>
</html>
