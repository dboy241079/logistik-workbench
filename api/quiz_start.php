<?php
declare(strict_types=1);
require __DIR__ . '/../inc/session.php';

header('Content-Type: application/json; charset=utf-8');

// Fehler nicht als HTML ausgeben (sonst kaputtes JSON)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/quiz_api_errors.log');

require __DIR__ . '/_db.php';

try {
  $username = $_SESSION['username'] ?? null;
  if (!$username) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'not logged in']);
    exit;
  }

  // aktive Version holen (falls keine -> Notfall: 0)
  $ver = $pdo->query("SELECT id FROM quiz_versions WHERE is_active=1 ORDER BY released_at DESC LIMIT 1")->fetchColumn();
  if (!$ver) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'no active quiz_version']);
    exit;
  }

  // 25 Fragen – Quoten (summe = 25)
  $QUOTAS = [
    'workbench'        => 5,
    'datenschutz'      => 4,
    'transport'        => 4,
    'ladungssicherung' => 4,
    'logistik_7r'      => 2,
    'personalwesen'    => 2,
    'zoll_p36'         => 3,
    'zoll_high_risk'   => 1,
  ];

  function pickQuestions(PDO $pdo, string $catKey, int $limit, array $excludeIds): array {
    $sql = "
      SELECT q.id, q.question_text, c.key_name AS category_key, c.title AS category_title
      FROM quiz_questions q
      JOIN quiz_categories c ON c.id = q.category_id
      WHERE q.is_active = 1 AND c.key_name = :cat
    ";

    $params = [':cat' => $catKey];

    if (!empty($excludeIds)) {
      $ph = [];
      foreach ($excludeIds as $i => $id) {
        $k = ":ex{$i}";
        $ph[] = $k;
        $params[$k] = (int)$id;
      }
      $sql .= " AND q.id NOT IN (" . implode(',', $ph) . ")";
    }

    $sql .= " ORDER BY RAND() LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  $picked = [];
  $pickedIds = [];

  foreach ($QUOTAS as $cat => $lim) {
    $rows = pickQuestions($pdo, $cat, (int)$lim, $pickedIds);
    if (count($rows) < $lim) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>"not enough questions in category: $cat"]);
      exit;
    }
    foreach ($rows as $r) {
      $picked[] = $r;
      $pickedIds[] = (int)$r['id'];
    }
  }

  // Optionen laden (ohne Lösungen)
  $ph = [];
  $params = [];
  foreach ($pickedIds as $i => $id) {
    $k = ":q{$i}";
    $ph[] = $k;
    $params[$k] = (int)$id;
  }

  $opt = $pdo->prepare("
    SELECT id, question_id, option_text
    FROM quiz_options
    WHERE question_id IN (" . implode(',', $ph) . ")
    ORDER BY question_id, sort_order, id
  ");
  $opt->execute($params);
  $options = $opt->fetchAll(PDO::FETCH_ASSOC);

  $map = [];
  foreach ($options as $o) {
    $qid = (int)$o['question_id'];
    $map[$qid][] = ['id'=>(int)$o['id'], 'text'=>$o['option_text']];
  }

  // Fragen mischen
  shuffle($picked);

  $out = [];
  foreach ($picked as $q) {
    $qid = (int)$q['id'];
    $out[] = [
      'id' => $qid,
      'text' => $q['question_text'],
      'category_key' => $q['category_key'],
      'category_title' => $q['category_title'],
      'options' => $map[$qid] ?? []
    ];
  }

  $token = bin2hex(random_bytes(16));
  $_SESSION['quiz_attempt'] = [
    'token' => $token,
    'quiz_version_id' => (int)$ver,
    'question_ids' => $pickedIds,
    'started_at' => time()
  ];

  echo json_encode([
    'ok'=>true,
    'attempt_token'=>$token,
    'quiz_version_id'=>(int)$ver,
    'questions'=>$out
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  // ins Log
  error_log("quiz_start error: " . $e->getMessage());
  echo json_encode(['ok'=>false,'error'=>'Serverfehler. Siehe Log: /api/quiz_api_errors.log']);
}
