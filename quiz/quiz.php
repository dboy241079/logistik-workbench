<?php
declare(strict_types=1);

require __DIR__ . '/../inc/session.php';
require __DIR__ . '/../inc/rbac.php';

// Quiz darf nur von eingeloggten Benutzern mit freigegebenem Tab geöffnet werden
$AUTH_REQUIRE_LOGIN = true;
$AUTH_REQUIRE_EMBED = false;   // Quiz darf normal direkt geöffnet werden
$AUTH_TAB_KEY       = 'quiz';
$AUTH_DEFAULT_TAB   = 'dashboard';
$AUTH_DENY_MODE     = 'redirect';

require __DIR__ . '/../inc/auth_embed.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dispo-Quiz</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 980px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-0">Disponenten-Test</h1>
      <div class="text-muted small">25 Fragen · Ziel: 80%</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <div class="badge text-bg-secondary" id="timer">30:00</div>
      <a class="btn btn-outline-secondary btn-sm" href="/index.php">
        <i class="bi bi-arrow-left me-1"></i> Zurück
      </a>
    </div>
  </div>

    <div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">
      <i class="bi bi-info-circle me-1"></i> Warum dieser Test?
    </h2>
    <p class="mb-2 small text-muted">
      Dieser kurze Wissens-Check hilft uns, dass im Alltag alles sauber läuft:
      richtige Ware, richtige Infos, sichere Transporte und korrekte Abläufe – auch bei Datenschutz und Zoll.
    </p>
    <p class="mb-0 small text-muted">
      Es geht nicht ums „Ausfragen“, sondern darum, Lücken früh zu erkennen, damit wir gezielt nachschulen können
      und Fehler (Zeitverlust, Stopper, Schäden) vermeiden. Am Ende siehst du eine Auswertung nach Kategorien.
    </p>
  </div>
</div>



  <div id="loading" class="alert alert-info">Fragen werden geladen…</div>


  <div id="quizCard" class="card shadow-sm d-none">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="small text-muted">Frage <span id="idx">1</span>/<span id="total">25</span></div>
        <span class="badge text-bg-dark" id="cat"></span>
      </div>

      <h2 class="h5" id="qtext"></h2>
      <div class="mt-3" id="answers"></div>

      <div class="d-flex gap-2 mt-4">
        <button class="btn btn-outline-secondary" id="prev">Zurück</button>
        <button class="btn btn-primary" id="next">Weiter</button>
        <button class="btn btn-success ms-auto d-none" id="finish">Abgeben</button>
      </div>

      <div class="alert alert-warning mt-3 d-none" id="warn"></div>
    </div>
  </div>

  <div id="resultCard" class="card shadow-sm mt-3 d-none">
    <div class="card-body">
      <h3 class="h5">Ergebnis</h3>
      <p class="mb-2">Punkte: <b id="score"></b> / <span id="max"></span></p>
      <p class="mb-3">Status: <b id="status"></b></p>
      <div id="mailInfo" class="alert alert-secondary py-2 small d-none mb-3"></div>


      <h4 class="h6">Kategorie-Auswertung</h4>
      <div id="breakdown"></div>

      <hr>
      <a class="btn btn-primary" href="/index.php">Zurück zur Workbench</a>
    </div>
  </div>
</div>

<script>
(() => {
  const START_URL  = "/api/quiz_start.php";
  const SUBMIT_URL = "/api/quiz_submit.php";
  const DURATION_SEC = 30 * 60;

  let attemptToken = null;
  let questions = [];
  let picks = [];
  let i = 0;

  let remaining = DURATION_SEC;
  let timerId = null;

  const $ = (id) => document.getElementById(id);

  function fmt(sec){
    const m = String(Math.floor(sec/60)).padStart(2,"0");
    const s = String(sec%60).padStart(2,"0");
    return `${m}:${s}`;
  }

  function tick(){
    remaining--;
    $("timer").textContent = fmt(Math.max(0, remaining));
    if (remaining <= 0) finish();
  }

  function render(){
    const q = questions[i];
    $("idx").textContent = i + 1;
    $("total").textContent = questions.length;
    $("cat").textContent = q.category_title || q.category_key;
    $("qtext").textContent = q.text;

    const wrap = $("answers");
    wrap.innerHTML = "";
    q.options.forEach((opt) => {
      const id = `q_${q.id}_o_${opt.id}`;
      const div = document.createElement("div");
      div.className = "form-check mb-2";
      div.innerHTML = `
        <input class="form-check-input" type="radio" name="q${q.id}" id="${id}" value="${opt.id}">
        <label class="form-check-label" for="${id}">${opt.text}</label>
      `;
      wrap.appendChild(div);
    });

    // restore
    const chosen = picks[i];
    if (chosen) {
      const el = wrap.querySelector(`input[value="${chosen}"]`);
      if (el) el.checked = true;
    }

    $("prev").disabled = (i === 0);
    $("next").classList.toggle("d-none", i === questions.length - 1);
    $("finish").classList.toggle("d-none", i !== questions.length - 1);
    $("warn").classList.add("d-none");
  }

  function savePick(){
    const q = questions[i];
    const checked = document.querySelector(`#answers input[name="q${q.id}"]:checked`);
    picks[i] = checked ? Number(checked.value) : null;
  }

  async function start(){
    try {
      const res = await fetch(START_URL, { credentials: "same-origin" });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || "start failed");

      attemptToken = data.attempt_token;
      questions = data.questions || [];
      picks = new Array(questions.length).fill(null);

      $("loading").classList.add("d-none");
      $("quizCard").classList.remove("d-none");

      $("timer").textContent = fmt(remaining);
      timerId = setInterval(tick, 1000);

      render();
    } catch (e) {
      $("loading").className = "alert alert-danger";
      $("loading").textContent = "Fehler beim Laden: " + e.message;
    }
  }

  async function finish(){
    savePick();

    if (timerId) clearInterval(timerId);

    // Warnung wenn unbeantwortet
    if (picks.includes(null) && remaining > 0) {
      $("warn").textContent = "Du hast noch unbeantwortete Fragen. Bitte kurz prüfen.";
      $("warn").classList.remove("d-none");
      // timer weiterlaufen lassen
      timerId = setInterval(tick, 1000);
      return;
    }

    const answers = questions.map((q, idx) => ({
      question_id: q.id,
      option_id: picks[idx] || 0
    }));

    $("quizCard").classList.add("d-none");
    $("loading").className = "alert alert-info";
    $("loading").textContent = "Auswertung läuft…";
    $("loading").classList.remove("d-none");

    try {
      const res = await fetch(SUBMIT_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ attempt_token: attemptToken, answers })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || "submit failed");

      $("loading").classList.add("d-none");
      $("resultCard").classList.remove("d-none");

      $("score").textContent = data.score;
      $("max").textContent = data.max;
      $("status").textContent = data.passed ? "BESTANDEN" : "NICHT BESTANDEN";

      const mi = $("mailInfo");
if (mi) {
  mi.classList.remove("d-none", "alert-success", "alert-warning", "alert-secondary");
  if (data.emailed) {
    mi.classList.add("alert-success");
    mi.textContent = "✅ Ergebnis wurde per E-Mail an die Leitung gesendet.";
  } else {
    mi.classList.add("alert-warning");
    mi.textContent = "⚠️ Ergebnis konnte nicht per E-Mail gesendet werden (Server-Mail). Ergebnis ist aber gespeichert.";
  }
}


      const bd = data.categoryBreakdown || {};
      const rows = Object.entries(bd).map(([k,v]) => {
        const pct = v.pct ?? 0;
        const cls = pct >= 80 ? "text-bg-success" : (pct >= 60 ? "text-bg-warning" : "text-bg-danger");
        return `
          <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 bg-white">
            <div class="small">${k}</div>
            <div class="d-flex gap-2 align-items-center">
              <span class="badge ${cls}">${pct}%</span>
              <span class="small text-muted">${v.correct}/${v.total}</span>
            </div>
          </div>
        `;
      }).join("");

      $("breakdown").innerHTML = rows || '<div class="text-muted small">Keine Auswertung.</div>';

    } catch (e) {
      $("loading").className = "alert alert-danger";
      $("loading").textContent = "Fehler bei Abgabe: " + e.message;
    }
  }

  $("prev")?.addEventListener("click", () => { savePick(); i--; render(); });
  $("next")?.addEventListener("click", () => { savePick(); i++; render(); });
  $("finish")?.addEventListener("click", finish);

  start();
})();
</script>
</body>
</html>
