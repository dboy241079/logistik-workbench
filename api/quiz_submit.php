<?php
declare(strict_types=1);
require __DIR__ . '/../inc/session.php';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/quiz_api_errors.log');

require __DIR__ . '/_db.php';

$QUIZ_REPORT_TO   = 'daniel.struebig@teamprojekt-outsourcing.de';
$QUIZ_REPORT_FROM = 'no-reply@danielstruebig.bplaced.net'; // ggf. anpassen


try {
  $username = $_SESSION['username'] ?? null;
  if (!$username) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'not logged in']);
    exit;
  }

  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad json']);
    exit;
  }

  $attempt = $_SESSION['quiz_attempt'] ?? null;
  if (!$attempt || ($body['attempt_token'] ?? '') !== ($attempt['token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid attempt']);
    exit;
  }

  $answers = $body['answers'] ?? [];
  if (!is_array($answers)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'answers missing']);
    exit;
  }

  $questionIds = $attempt['question_ids'];
  $qSet = array_flip($questionIds);

  $selectedByQ = [];
  foreach ($answers as $a) {
    $qid = (int)($a['question_id'] ?? 0);
    $oid = (int)($a['option_id'] ?? 0);
    if ($qid && $oid && isset($qSet[$qid])) $selectedByQ[$qid] = $oid;
  }

  // Query IN (...) mit named placeholders
  $ph = [];
  $params = [];
  foreach ($questionIds as $i => $id) {
    $k = ":q{$i}";
    $ph[] = $k;
    $params[$k] = (int)$id;
  }

  $stmt = $pdo->prepare("
    SELECT q.id AS qid, c.key_name AS cat, o.id AS oid, o.is_correct
    FROM quiz_questions q
    JOIN quiz_categories c ON c.id = q.category_id
    JOIN quiz_options o ON o.question_id = q.id
    WHERE q.id IN (" . implode(',', $ph) . ")
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $correctOpt = [];
  $qCat = [];
  foreach ($rows as $r) {
    $qid = (int)$r['qid'];
    $qCat[$qid] = (string)$r['cat'];
    if ((int)$r['is_correct'] === 1) $correctOpt[$qid] = (int)$r['oid'];
  }

  $score = 0;
  $catStats = [];
  foreach ($questionIds as $qid) {
    $cat = $qCat[$qid] ?? 'unknown';
    if (!isset($catStats[$cat])) $catStats[$cat] = ['correct'=>0,'total'=>0,'pct'=>0];
    $catStats[$cat]['total']++;

    $sel = $selectedByQ[$qid] ?? null;
    if ($sel !== null && isset($correctOpt[$qid]) && $sel === $correctOpt[$qid]) {
      $score++;
      $catStats[$cat]['correct']++;
    }
  }

  $max = count($questionIds);
  foreach ($catStats as $k => $v) {
    $catStats[$k]['pct'] = $v['total'] ? (int)round(($v['correct'] / $v['total']) * 100) : 0;
  }

  $passed = ($max > 0 && ($score / $max) >= 0.80) ? 1 : 0;

  $pdo->prepare("
    INSERT INTO quiz_attempts (username, quiz_version_id, score, max_score, passed, category_breakdown_json, answers_json, created_at)
    VALUES (:u, :v, :s, :m, :p, :cb, :aj, NOW())
  ")->execute([
    ':u'=>$username,
    ':v'=>(int)$attempt['quiz_version_id'],
    ':s'=>$score,
    ':m'=>$max,
    ':p'=>$passed,
    ':cb'=>json_encode($catStats, JSON_UNESCAPED_UNICODE),
    ':aj'=>json_encode($selectedByQ, JSON_UNESCAPED_UNICODE),
  ]);

 
$attemptId = (int)$pdo->lastInsertId();

$percent = ($max > 0) ? (int)round(($score / $max) * 100) : 0;
$passedText = $passed ? 'BESTANDEN' : 'NICHT BESTANDEN';

$subject = "Quiz: {$username} – {$percent}% ({$score}/{$max}) – {$passedText}";

// Kategorien nach Prozent aufsteigend sortieren
$cats = [];
foreach ($catStats as $cat => $v) {
  $cats[] = [
    'cat' => $cat,
    'correct' => (int)($v['correct'] ?? 0),
    'total' => (int)($v['total'] ?? 0),
    'pct' => (int)($v['pct'] ?? 0),
  ];
}
usort($cats, fn($a,$b) => $a['pct'] <=> $b['pct']);

// Ampel-Emoji
$badge = function(int $pct): string {
  if ($pct >= 80) return "🟢";
  if ($pct >= 60) return "🟡";
  return "🔴";
};

// Top 3 Schwächen (nur Kategorien mit total>0)
$weak = array_values(array_filter($cats, fn($x) => $x['total'] > 0));
$top3 = array_slice($weak, 0, 3);

$lines = [];
$lines[] = "LOGISTIK-WORKBENCH · QUIZ-ERGEBNIS";
$lines[] = str_repeat("=", 34);
$lines[] = "Attempt-ID : {$attemptId}";
$lines[] = "User       : {$username}";
$lines[] = "Zeit       : " . date('d.m.Y H:i') . " (Europe/Berlin)";
$lines[] = "Gesamt     : {$percent}% ({$score}/{$max}) · {$passedText}";
$lines[] = "";

$lines[] = "SCHWERPUNKTE (Top 3 Nachschulung)";
$lines[] = str_repeat("-", 34);
if (count($top3) === 0) {
  $lines[] = "- (keine Daten)";
} else {
  foreach ($top3 as $t) {
    $lines[] = "{$badge($t['pct'])} {$t['cat']} – {$t['pct']}% ({$t['correct']}/{$t['total']})";
  }
}
$lines[] = "";

// High-Risk extra hervorheben
if (isset($catStats['zoll_high_risk'])) {
  $hr = $catStats['zoll_high_risk'];
  $hrPct = (int)($hr['pct'] ?? 0);
  $lines[] = "HIGH-RISK CHECK";
  $lines[] = str_repeat("-", 34);
  $lines[] = "{$badge($hrPct)} zoll_high_risk – {$hrPct}% ({$hr['correct']}/{$hr['total']})";
  $lines[] = "";
}

$lines[] = "KATEGORIE-AUSWERTUNG";
$lines[] = str_repeat("-", 34);
foreach ($cats as $c) {
  $lines[] = "{$badge($c['pct'])} {$c['cat']}: {$c['pct']}% ({$c['correct']}/{$c['total']})";
}
$lines[] = "";
$lines[] = "Hinweis: Es werden bewusst keine einzelnen Antworten/Fragen per Mail versendet.";

$body = implode("\r\n", $lines);



// Mail senden
$headers = [
  "MIME-Version: 1.0",
  "Content-Type: text/plain; charset=UTF-8",
  "From: Logistik-Workbench <{$QUIZ_REPORT_FROM}>",
  "Reply-To: {$QUIZ_REPORT_TO}",
];

$emailed = @mail(
  $QUIZ_REPORT_TO,
  '=?UTF-8?B?'.base64_encode($subject).'?=',
  $body,
  implode("\r\n", $headers)
);

if (!$emailed) {
  error_log("quiz_submit: mail() failed for attempt {$attemptId} user={$username}");
}


  unset($_SESSION['quiz_attempt']);

  echo json_encode([
    'ok'=>true,
    'score'=>$score,
    'max'=>$max,
    'passed'=>(bool)$passed,
    'emailed' => (bool)$emailed,
    'categoryBreakdown'=>$catStats
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  error_log("quiz_submit error: " . $e->getMessage());
  echo json_encode(['ok'=>false,'error'=>'Serverfehler. Siehe Log: /api/quiz_api_errors.log']);
}

