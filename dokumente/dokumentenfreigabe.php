<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/session.php';
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/controlled_documents_workflow.php';

if (!isset($_SESSION['username'])) {
    $return = '/dokumente/dokumentenfreigabe.php?token=' . rawurlencode((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    header('Location: /?return_to=' . rawurlencode($return));
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
qcWorkflowEnsureSchema($pdo);

function dfE(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = null;
$error = null;
$result = null;

try {
    $approval = qcWorkflowGetApprovalByToken($pdo, $token);
    if (!$approval) {
        throw new RuntimeException('Dieser Freigabelink ist ungültig, bereits verwendet oder wurde zurückgesetzt.');
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId <= 0 || $currentUserId !== (int)$approval['approver_user_id']) {
        throw new RuntimeException('Dieser Freigabelink ist einem anderen Workbench-Benutzer zugeordnet.');
    }

    $inspect = qcControlledInspectDocument($pdo, (int)$approval['document_id'], qcControlledRootPath());
    if ((string)$approval['revision_status'] === 'in_review' && $inspect['changed']) {
        qcWorkflowInvalidateReviewIfChanged($pdo, (int)$approval['document_id'], $inspect);
        throw new RuntimeException('Der Dateistand wurde nach Prüfungsstart verändert. Die Prüfung wurde automatisch zurückgesetzt.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        qcWorkflowRequireCsrf($_POST['csrf'] ?? null);
        $decision = (string)($_POST['decision'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $result = qcWorkflowRecordDecision($pdo, $token, $decision, $comment);
        $message = $decision === 'approved'
            ? ($result['released'] ? 'Bestätigung gespeichert. Alle Prüfer haben bestätigt – die Revision ist jetzt freigegeben.' : 'Bestätigung gespeichert. Die Revision wartet noch auf weitere Bestätigungen.')
            : 'Ablehnung gespeichert. Die Revision wurde zurück in die Bearbeitung gegeben.';
        $approval = null;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $approval = null;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentenprüfung</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
</head>
<body class="bg-slate-100 text-slate-900 text-sm">
<div class="max-w-3xl mx-auto px-4 py-8 space-y-4">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <div class="text-[11px] uppercase tracking-wider font-semibold text-sky-700">Dokumentenlenkung</div>
            <h1 class="text-xl font-semibold mt-1">Dokumentenprüfung</h1>
            <p class="text-xs text-slate-500 mt-1">Angemeldet als <?= dfE((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? '')) ?></p>
        </div>

        <?php if ($error): ?>
            <div class="m-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
                <div class="font-semibold">Prüfung nicht möglich</div>
                <div class="text-xs mt-1"><?= dfE($error) ?></div>
            </div>
        <?php elseif ($message): ?>
            <div class="m-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                <div class="font-semibold">Erledigt</div>
                <div class="text-xs mt-1"><?= dfE($message) ?></div>
            </div>
        <?php elseif ($approval): ?>
            <div class="p-5 space-y-5">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-sky-700"><?= dfE((string)$approval['document_no']) ?></span>
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Rev. <?= dfE((string)$approval['revision']) ?> · In Prüfung</span>
                    </div>
                    <h2 class="text-base font-semibold mt-2"><?= dfE((string)$approval['title']) ?></h2>
                    <?php if (!empty($approval['change_reason'])): ?>
                        <div class="mt-3 text-xs font-semibold text-slate-700">Änderungsgrund</div>
                        <div class="text-xs text-slate-600 mt-1"><?= dfE((string)$approval['change_reason']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($approval['change_description'])): ?>
                        <div class="mt-3 text-xs font-semibold text-slate-700">Änderungsbeschreibung</div>
                        <div class="text-xs text-slate-600 mt-1 leading-relaxed"><?= nl2br(dfE((string)$approval['change_description'])) ?></div>
                    <?php endif; ?>
                    <div class="mt-3 text-[11px] text-slate-500">Deine Rolle in der Prüfkette: <b><?= dfE((string)$approval['approver_code']) ?> · <?= dfE((string)$approval['approval_role']) ?></b></div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="/druck_wa.html" target="_blank" rel="noopener" class="rounded-lg border border-sky-300 bg-sky-50 px-4 py-2 text-xs font-semibold text-sky-800 hover:bg-sky-100">Dokument öffnen ↗</a>
                    <a href="/dokumente/dokumentenlenkung.php?embed=1" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Dokumentenlenkung</a>
                </div>

                <form method="post" class="rounded-xl border border-slate-200 p-4 space-y-4">
                    <input type="hidden" name="csrf" value="<?= dfE(qcWorkflowCsrfToken()) ?>">
                    <input type="hidden" name="token" value="<?= dfE($token) ?>">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Kommentar / Prüfhinweis</label>
                        <textarea name="comment" rows="4" class="w-full rounded-lg border-slate-300 text-sm" placeholder="Optionaler Kommentar zur Prüfung …"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="submit" name="decision" value="approved" class="rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">✓ Bestätigen</button>
                        <button type="submit" name="decision" value="rejected" class="rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-700">✕ Ablehnen</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="border-t border-slate-100 p-5 text-[11px] text-slate-500">
            Jede Entscheidung wird mit Benutzer, Zeitpunkt und Dokument-Hash protokolliert. Nach einer Dateiveränderung werden offene Prüfungen automatisch ungültig.
        </div>
    </div>
</div>
</body>
</html>
