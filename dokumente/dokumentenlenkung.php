<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/controlled_documents_bootstrap.php';

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
        $doc['inspect'] = $inspect;

        $stmt = $pdo->prepare("SELECT * FROM qc_document_revisions WHERE document_id = ? ORDER BY id DESC");
        $stmt->execute([$docId]);
        $doc['revisions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latestRevisionId = (int)($inspect['latest_revision']['id'] ?? 0);
        $doc['approvals'] = [];
        if ($latestRevisionId > 0) {
            $stmt = $pdo->prepare("SELECT approver_code, approval_role, decision, comment, decided_at
                FROM qc_document_approvals
                WHERE revision_id = ?
                ORDER BY id");
            $stmt->execute([$latestRevisionId]);
            $doc['approvals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare("SELECT event_type, details_json, created_at
            FROM qc_document_history
            WHERE document_id = ?
            ORDER BY id DESC
            LIMIT 10");
        $stmt->execute([$docId]);
        $doc['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($doc);
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
                Gelenkte Dokumente, Revisionen und die zugehörigen Quelldateien werden hier nachvollziehbar überwacht.
                Änderungen am freigegebenen bzw. vorbereiteten Stand werden über SHA-256-Dateiprüfungen erkannt und protokolliert.
            </p>
        </div>
        <a href="/?tab=docs" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
            ← Dokumentencenter
        </a>
    </header>

    <section class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-[11px] text-slate-500">Gelenkte Dokumente</div>
            <div class="text-2xl font-semibold mt-1"><?= (int)$summary['total'] ?></div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-white p-4 shadow-sm">
            <div class="text-[11px] text-slate-500">Freigegebener Bestand</div>
            <div class="text-2xl font-semibold text-emerald-700 mt-1"><?= (int)$summary['released'] ?></div>
        </div>
        <div class="rounded-xl border border-sky-200 bg-white p-4 shadow-sm">
            <div class="text-[11px] text-slate-500">Entwürfe</div>
            <div class="text-2xl font-semibold text-sky-700 mt-1"><?= (int)$summary['draft'] ?></div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-white p-4 shadow-sm">
            <div class="text-[11px] text-slate-500">In Prüfung</div>
            <div class="text-2xl font-semibold text-amber-700 mt-1"><?= (int)$summary['in_review'] ?></div>
        </div>
        <div class="rounded-xl border <?= $summary['changed'] ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' ?> p-4 shadow-sm">
            <div class="text-[11px] text-slate-500">Dateiänderungen</div>
            <div class="text-2xl font-semibold <?= $summary['changed'] ? 'text-red-700' : 'text-slate-700' ?> mt-1"><?= (int)$summary['changed'] ?></div>
        </div>
    </section>

    <?php foreach ($docs as $doc): ?>
        <?php
        $inspect = $doc['inspect'];
        $latest = $inspect['latest_revision'] ?? [];
        [$latestLabel, $latestClass] = cdStatusLabel((string)($latest['status'] ?? ''));
        ?>
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-sky-700"><?= cdE((string)$doc['document_no']) ?></span>
                        <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($latestClass) ?>">
                            Rev. <?= cdE((string)($latest['revision'] ?? '–')) ?> · <?= cdE($latestLabel) ?>
                        </span>
                        <?php if ($inspect['changed']): ?>
                            <span class="inline-flex rounded-full border border-red-200 bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-800">
                                ⚠ Dateiänderung erkannt
                            </span>
                        <?php else: ?>
                            <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                ✓ Dateistand unverändert
                            </span>
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
                                    <div class="min-w-0">
                                        <div class="text-xs font-medium text-slate-800 truncate"><?= cdE((string)$file['file_path']) ?></div>
                                        <div class="text-[10px] text-slate-500 mt-0.5"><?= cdE((string)($file['label'] ?? '')) ?></div>
                                    </div>
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
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold">Rev. <?= cdE((string)$rev['revision']) ?></div>
                                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($statusClass) ?>"><?= cdE($statusText) ?></span>
                                </div>
                                <?php if (!empty($rev['change_reason'])): ?>
                                    <div class="text-xs text-slate-700 mt-2"><?= cdE((string)$rev['change_reason']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($rev['change_description'])): ?>
                                    <div class="text-[11px] text-slate-500 mt-1 leading-relaxed"><?= nl2br(cdE((string)$rev['change_description'])) ?></div>
                                <?php endif; ?>
                                <div class="text-[10px] text-slate-400 mt-2">Angelegt: <?= cdE(date('d.m.Y H:i', strtotime((string)$rev['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="p-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Prüfkette der neuesten Revision</h3>
                    <div class="mt-3 space-y-2">
                        <?php if (!$doc['approvals']): ?>
                            <div class="text-xs text-slate-500">Für diese Revision ist noch keine Prüfkette angelegt.</div>
                        <?php else: ?>
                            <?php foreach ($doc['approvals'] as $approval): ?>
                                <?php
                                $decision = (string)$approval['decision'];
                                $decisionText = $decision === 'approved' ? 'Bestätigt' : ($decision === 'rejected' ? 'Abgelehnt' : 'Ausstehend');
                                $decisionClass = $decision === 'approved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($decision === 'rejected' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                                ?>
                                <div class="flex items-center justify-between rounded-lg border border-slate-200 p-3">
                                    <div>
                                        <div class="text-xs font-semibold"><?= cdE((string)$approval['approver_code']) ?></div>
                                        <div class="text-[10px] text-slate-500"><?= cdE((string)$approval['approval_role']) ?></div>
                                    </div>
                                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold <?= cdE($decisionClass) ?>"><?= cdE($decisionText) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mt-5">Protokoll</h3>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($doc['history'] as $event): ?>
                            <?php
                            $eventLabel = match ((string)$event['event_type']) {
                                'revision_imported' => 'Ausgangsrevision übernommen',
                                'revision_created' => 'Neue Revision angelegt',
                                'file_change_detected' => 'Dateiänderung erkannt',
                                default => (string)$event['event_type'],
                            };
                            ?>
                            <div class="border-l-2 border-slate-200 pl-3 py-1">
                                <div class="text-xs text-slate-700"><?= cdE($eventLabel) ?></div>
                                <div class="text-[10px] text-slate-400"><?= cdE(date('d.m.Y H:i', strtotime((string)$event['created_at']))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-xs text-sky-900">
        <b>Nächster Workflow-Schritt:</b> Die technische Grundlage für Revisionen, Dateihashes, MAD/MED/CBE-Prüfkette und Historie ist vorhanden.
        Der Versand der Prüfmail sowie die Schaltflächen „Bestätigen / Ablehnen“ werden auf dieser Basis ergänzt, ohne die vorhandenen Dokumente neu zu strukturieren.
    </section>
</div>
</body>
</html>
