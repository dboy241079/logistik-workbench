
<?php

$quizDue = false;
$quizDueReason = '';
$quizDueAt = null;

if (!empty($isLoggedIn)) {
  // $pdo muss im index.php schon existieren (bei dir ist das so)
  $u = $_SESSION['username'] ?? ($currentName ?? '');

  // Schalter:
  // false = wie besprochen: nur fällig, wenn <80% und 2 Monate rum ODER neue Version/noch nie
  // true  = immer alle 2 Monate fällig (falls du das doch willst)
  $FORCE_EVERY_2_MONTHS = false;

  // aktive Version
  $activeVer = $pdo->query("SELECT id FROM quiz_versions WHERE is_active=1 ORDER BY released_at DESC LIMIT 1")->fetchColumn();

  // letzter Versuch
  $st = $pdo->prepare("SELECT * FROM quiz_attempts WHERE username=:u ORDER BY created_at DESC LIMIT 1");
  $st->execute([':u'=>$u]);
  $last = $st->fetch(PDO::FETCH_ASSOC);

  if (!$last) {
    $quizDue = true;
    $quizDueReason = 'Noch kein Quiz gemacht';
  } else {
    $lastTime = strtotime($last['created_at']);
    $twoMonthsAt = strtotime('+2 months', $lastTime);

    // neue Version?
    if ($activeVer && (int)$last['quiz_version_id'] !== (int)$activeVer) {
      // hat der User die aktive Version schon bestanden?
      $chk = $pdo->prepare("SELECT 1 FROM quiz_attempts WHERE username=:u AND quiz_version_id=:v AND passed=1 LIMIT 1");
      $chk->execute([':u'=>$u, ':v'=>(int)$activeVer]);
      if (!$chk->fetchColumn()) {
        $quizDue = true;
        $quizDueReason = 'Neue Version – Quiz erforderlich';
      }
    }

    // wenn nicht wegen Version fällig -> 2 Monate Logik
    if (!$quizDue) {
      $pct = ((int)$last['max_score'] > 0) ? ((int)$last['score'] / (int)$last['max_score']) : 0.0;

      if ($FORCE_EVERY_2_MONTHS) {
        if (time() >= $twoMonthsAt) {
          $quizDue = true;
          $quizDueReason = '2 Monate vorbei – Wiederholung fällig';
        }
      } else {
        if ($pct < 0.80 && time() >= $twoMonthsAt) {
          $quizDue = true;
          $quizDueReason = 'Letztes Ergebnis <80% (Wiederholung nach 2 Monaten)';
        }
      }

      $quizDueAt = date('Y-m-d H:i:s', $twoMonthsAt);
    }
  }
}
?>

<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logistik-Workbench</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/css/dataTables.bootstrap5.min.css">


  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<link rel="stylesheet" href="drivers.css">
<link rel="stylesheet" href="/CSS/fahrer_ubersicht.css">
<link rel="stylesheet" href="/CSS/dashboard.css">
<link rel="stylesheet" href="/CSS/login.css">
<link rel="stylesheet" href="/CSS/navbar.css">
<link rel="stylesheet" href="/CSS/sachnummern.css">
<link rel="stylesheet" href="/CSS/sachnummern-mobile.css?v=2">

</head>

<body class="<?= $isLoggedIn ? '' : 'login-body' ?>">

<?php if (!$isLoggedIn): ?>

  <div class="login-wrapper">
    <video class="login-bg-video" autoplay muted loop playsinline>
      <source src="/media/abgedunkelt203.mp4" type="video/mp4">
      Dein Browser unterstützt keine HTML5-Videos.
    </video>

    <div class="login-overlay"></div>

    <header class="login-topbar">
      <div class="d-flex align-items-center gap-2">
        <img src="/Bilder/logo_standard_tpo.svg" alt="TPO" style="height:28px;">
        <span class="fw-semibold">Logistik-Workbench</span>
      </div>
      <button id="loginToggleBtn" class="btn btn-outline-light btn-sm">
        <i class="bi bi-box-arrow-in-right me-1"></i>
        Workbench betreten
      </button>
    </header>

    <div class="login-center position-absolute top-50 start-50 translate-middle px-3 px-sm-0">

      <div id="landingContent" class="landing-content text-center text-light <?= $loginError ? 'd-none' : '' ?>">
        <h1 class="display-6 fw-semibold mb-3 typewriter-title">
          <span
            id="typewriterText"
            data-text-main="<?=htmlspecialchars($typewriterTextMain)?>"
            data-text-alt="<?=htmlspecialchars($typewriterTextAlt)?>"
          ></span>
          <span class="cursor">|</span>
        </h1>

        <p class="lead mb-0 hero-subtitle">
          <?=htmlspecialchars($heroSubtitle)?>
        </p>
      </div>

      <div id="loginBox" class="login-card-wrapper mt-4 <?= $loginError ? 'show' : 'd-none' ?>">
        <div class="card login-card shadow-lg">
          <div class="card-body">
            <h2 class="h5 mb-3 text-light">Anmeldung</h2>

            <?php if ($loginError): ?>
              <div class="alert alert-danger small mb-3">
                <?=htmlspecialchars($loginError)?>
              </div>
            <?php endif; ?>

            <form method="post" class="small">
              <input type="hidden" name="action" value="login">

              <div class="mb-3">
                <label class="form-label small mb-1 text-light">Benutzername</label>
                <input
                  type="text"
                  name="username"
                  value="<?=htmlspecialchars($loginUsername)?>"
                  class="form-control form-control-sm"
                  autocomplete="username"
                  required
                  autofocus
                >
              </div>

              <div class="mb-3">
                <label class="form-label small mb-1 text-light">Passwort</label>
                <input
                  type="password"
                  name="password"
                  class="form-control form-control-sm"
                  autocomplete="current-password"
                  required
                >
              </div>

              <div class="d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">Anmelden</button>
              </div>

              <p class="text-muted small mt-3 mb-0">
                Rollen werden im Adminbereich vergeben (admin, disposition, staplerfahrer, verpacker, standortleiter, user).
              </p>
            </form>

          </div>
        </div>
      </div>

    </div>
  </div>

<?php else: ?>

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-dark nav-red nav-glass shadow-sm nav-fade">
  <div class="container-fluid">

    <a class="navbar-brand d-flex flex-column justify-content-center" href="#">
      <div class="d-flex align-items-center">
        <img src="/Bilder/logo_standard_tpo.svg" alt="TPO" class="brand-logo me-2" style="height:28px;">
        <span class="fw-semibold">Logistik-Workbench</span>
      </div>
      <small class="d-none d-xl-inline">
        <?=htmlspecialchars($projectLabel)?> · Standort <?=htmlspecialchars($locationLabel)?>
      </small>
    </a>

    <!-- ✅ Mobile: Offcanvas Button -->
    <button class="navbar-toggler d-lg-none" type="button"
        data-bs-toggle="offcanvas" data-bs-target="#wbOffcanvas"
        aria-controls="wbOffcanvas">
        <span class="navbar-toggler-icon"></span>
     </button>

  
    <!-- ✅ Desktop Nav (Tabs) -->
    <div class="collapse navbar-collapse d-none d-lg-flex" id="mainTabsCollapse">

      <ul class="nav nav-tabs navbar-nav mx-auto justify-content-center flex-wrap nav-tabs-scroll" id="mainTabs" role="tablist">

        <?php if (canSeeTab('dashboard', $currentRole, $tabPermissions)): ?>
          <li class="nav-item">
            <button
              id="tab-dashboard"
              class="nav-link<?=($defaultTabKey === 'dashboard' ? ' active' : '')?>"
              data-bs-toggle="tab"
              data-bs-target="#dashboard"
              data-url="/dashboard.html"
              data-module="/js/dashboard.js"
              data-init="initDashboard"
              type="button" role="tab"
              aria-selected="<?=($defaultTabKey === 'dashboard' ? 'true' : 'false')?>"
            >
              <i class="bi bi-speedometer2 me-1"></i> <span>Dashboard</span>
            </button>
          </li>
        <?php endif; ?>

       <?php
  $canDrivers = canSeeTab('drivers', $currentRole, $tabPermissions);
  $showDrivers = $canDrivers;
  $driversActive = in_array($defaultTabKey, ['drivers', 'drivers_kiosk'], true);
?>

        <?php if ($showDrivers): ?>
  <li class="nav-item dropdown">
    <button
      id="tab-drivers"
      class="nav-link dropdown-toggle<?= ($driversActive ? ' active' : '') ?>"
      data-bs-toggle="dropdown"
      type="button"
      aria-expanded="false"
    >
      <i class="bi bi-truck me-1"></i>
      <span>Fahrer</span>
    </button>

    <ul class="dropdown-menu nav-dropdown-menu" aria-labelledby="tab-drivers">

      <li>
        <button
          class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'drivers' ? ' active' : '') ?>"
          data-bs-toggle="tab"
          data-bs-target="#drivers"
          data-url="/fahrer_ubersicht.html"
          data-module="/js/fahrer_ubersicht.js"
          data-init="initFahrerUebersicht"
          type="button"
          role="tab"
          aria-selected="<?= ($defaultTabKey === 'drivers' ? 'true' : 'false') ?>"
        >
          <i class="bi bi-speedometer2 text-primary"></i>
          <span>Fahrerübersicht</span>
        </button>
      </li>

      <li>
        <button
          class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'drivers_kiosk' ? ' active' : '') ?>"
          data-bs-toggle="tab"
          data-bs-target="#drivers_kiosk"
          type="button"
          role="tab"
          aria-selected="<?= ($defaultTabKey === 'drivers_kiosk' ? 'true' : 'false') ?>"
        >
          <i class="bi bi-keyboard text-success"></i>
          <span>Fahrer-Kiosk</span>
        </button>
      </li>

    </ul>
  </li>
<?php endif; ?>

        <?php
  $canGoods    = canSeeTab('goods', $currentRole, $tabPermissions);
  $canLeergut  = canSeeTab('leergut', $currentRole, $tabPermissions);
  $showStamm   = ($canGoods || $canLeergut);
  $stammActive = in_array($defaultTabKey, ['goods', 'leergut'], true);
?>

<?php if ($showStamm): ?>
  <li class="nav-item dropdown">
    <button
      id="tab-stammdaten"
      class="nav-link dropdown-toggle<?= ($stammActive ? ' active' : '') ?>"
      data-bs-toggle="dropdown"
      type="button"
      aria-expanded="false"
    >
      <i class="bi bi-box me-1"></i>
      <span>Warenstamm</span>
    </button>

    <ul class="dropdown-menu nav-dropdown-menu" aria-labelledby="tab-stammdaten">

      <?php if ($canGoods): ?>
        <li>
          <button
            class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'goods' ? ' active' : '') ?>"
            data-bs-toggle="tab"
            data-bs-target="#goods"
            data-url="/sachnummern.php"
            data-module="/js/sachnummern.js"
            data-init="initSachnummern"
            type="button"
            role="tab"
            aria-selected="<?= ($defaultTabKey === 'goods' ? 'true' : 'false') ?>"
          >
            <i class="bi bi-box text-primary"></i>
            <span>Warenstamm</span>
          </button>
        </li>
      <?php endif; ?>

      <?php if ($canLeergut): ?>
        <li>
          <button
            class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'leergut' ? ' active' : '') ?>"
            data-bs-toggle="tab"
            data-bs-target="#leergut"
            type="button"
            role="tab"
            aria-selected="<?= ($defaultTabKey === 'leergut' ? 'true' : 'false') ?>"
          >
            <i class="bi bi-basket text-success"></i>
            <span>Leergut</span>
          </button>
        </li>
      <?php endif; ?>

    </ul>
  </li>
<?php endif; ?>

        <?php
          $canInbound  = canSeeTab('inbound',  $currentRole, $tabPermissions);
$canOutbound = canSeeTab('outbound', $currentRole, $tabPermissions);

/* Kommi Orders hängt hier erstmal an outbound dran */
$canKommiOrders = $canOutbound;

$showWarenfluss   = ($canInbound || $canOutbound || $canKommiOrders);
$warenflussActive = in_array($defaultTabKey, ['inbound','outbound','kommi_orders'], true);
        ?>

        <?php if ($showWarenfluss): ?>
          <li class="nav-item dropdown">
            <button
              id="tab-warenfluss"
              class="nav-link dropdown-toggle<?= ($warenflussActive ? ' active' : '') ?>"
              data-bs-toggle="dropdown"
              type="button"
              aria-expanded="false"
            >
              <i class="bi bi-arrow-left-right me-1"></i>
              <span>Warenfluss</span>
            </button>

            <ul class="dropdown-menu nav-dropdown-menu" aria-labelledby="tab-warenfluss">

            <?php if (canSeeTab('referenzvergleich', $currentRole, $tabPermissions)): ?>
  <li>
    <button
      class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'referenzvergleich' ? ' active' : '') ?>"
      data-bs-toggle="tab"
      data-bs-target="#referenzvergleich"
      type="button"
      role="tab"
      aria-selected="<?= ($defaultTabKey === 'referenzvergleich' ? 'true' : 'false') ?>"
    >
      <i class="bi bi-upc-scan text-primary"></i>
      <span>Ref.-Vergleich</span>
    </button>
  </li>
<?php endif; ?>
              <?php if ($canInbound): ?>
                <li>
                  <button
                    class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'inbound' ? ' active' : '') ?>"
                    data-bs-toggle="tab"
                    data-bs-target="#inbound"
                    type="button"
                    role="tab"
                    aria-selected="<?= ($defaultTabKey === 'inbound' ? 'true' : 'false') ?>"
                  >
                    <i class="bi bi-arrow-down-circle text-success"></i>
                    <span>Wareneingang</span>
                  </button>
                </li>
              <?php endif; ?>

              <?php if ($canOutbound): ?>
                <li>
                  <button
                    class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'outbound' ? ' active' : '') ?>"
                    data-bs-toggle="tab"
                    data-bs-target="#outbound"
                    type="button"
                    role="tab"
                    aria-selected="<?= ($defaultTabKey === 'outbound' ? 'true' : 'false') ?>"
                  >
                    <i class="bi bi-arrow-up-circle text-danger"></i>
                    <span>Warenausgang</span>
                  </button>
                </li>
              <?php endif; ?>

              <?php if ($canKommiOrders): ?>
  <li>
    <button
      class="dropdown-item d-flex align-items-center gap-2<?= ($defaultTabKey === 'kommi_orders' ? ' active' : '') ?>"
      data-bs-toggle="tab"
      data-bs-target="#kommi_orders"
      type="button"
      role="tab"
      aria-selected="<?= ($defaultTabKey === 'kommi_orders' ? 'true' : 'false') ?>"
    >
      <i class="bi bi-list-check text-primary"></i>
      <span>Kommi Orders</span>
    </button>
  </li>
<?php endif; ?>

            </ul>
          </li>
        <?php endif; ?>

        <?php if (canSeeTab('artikelakte', $currentRole, $tabPermissions)): ?>
  <li class="nav-item">
    <button
      class="nav-link<?=($defaultTabKey === 'artikelakte' ? ' active' : '')?>"
      data-bs-toggle="tab"
      data-bs-target="#artikelakte"
      type="button" role="tab"
      aria-selected="<?=($defaultTabKey === 'artikelakte' ? 'true' : 'false')?>"
    >
      <i class="bi bi-search me-1"></i> <span>Artikelakte</span>
    </button>
  </li>
<?php endif; ?>

        <?php if (canSeeTab('special', $currentRole, $tabPermissions)): ?>
          <li class="nav-item">
            <button
              class="nav-link<?=($defaultTabKey === 'special' ? ' active' : '')?>"
              data-bs-toggle="tab"
              data-bs-target="#special"
              type="button" role="tab"
              aria-selected="<?=($defaultTabKey === 'special' ? 'true' : 'false')?>"
            >
              <i class="bi bi-wrench me-1"></i> <span>100%-Prüfung</span>
            </button>
          </li>
        <?php endif; ?>

          <?php if (canSeeTab('lagerplan', $currentRole, $tabPermissions)): ?>
  <li class="nav-item">
    <button
      id="tab-lagerplan"
      class="nav-link<?=($defaultTabKey === 'lagerplan' ? ' active' : '')?>"
      data-bs-toggle="tab"
      data-bs-target="#lagerplan"
      type="button" role="tab"
      aria-selected="<?=($defaultTabKey === 'lagerplan' ? 'true' : 'false')?>"
    >
      <i class="bi bi-map me-1"></i> <span>Lagerplan</span>
    </button>
  </li>
<?php endif; ?>



        <?php if (canSeeTab('docs', $currentRole, $tabPermissions)): ?>
          <li class="nav-item">
            <button
              class="nav-link<?=($defaultTabKey === 'docs' ? ' active' : '')?>"
              data-bs-toggle="tab"
              data-bs-target="#docs"
              type="button" role="tab"
              aria-selected="<?=($defaultTabKey === 'docs' ? 'true' : 'false')?>"
            >
              <i class="bi bi-folder2-open me-1"></i> <span>Dokumente</span>
            </button>
          </li>
        <?php endif; ?>

        <?php if (canSeeTab('arbeitsanweisung', $currentRole, $tabPermissions)): ?>
  <li class="nav-item">
    <button
      class="nav-link<?=($defaultTabKey === 'arbeitsanweisung' ? ' active' : '')?>"
      data-bs-toggle="tab"
      data-bs-target="#arbeitsanweisung"
      type="button" role="tab"
      aria-selected="<?=($defaultTabKey === 'arbeitsanweisung' ? 'true' : 'false')?>"
    >
      <i class="bi bi-file-earmark-text me-1"></i> <span>Arbeitsanweisung</span>
    </button>
  </li>
<?php endif; ?>

        <?php if (canSeeTab('admin', $currentRole, $tabPermissions)): ?>
          <li class="nav-item">
            <button
              class="nav-link<?=($defaultTabKey === 'admin' ? ' active' : '')?>"
              data-bs-toggle="tab"
              data-bs-target="#admin"
              type="button" role="tab"
              aria-selected="<?=($defaultTabKey === 'admin' ? 'true' : 'false')?>"
            >
              <i class="bi bi-shield-lock me-1"></i> <span>Admin</span>
            </button>
          </li>
        <?php endif; ?>

      </ul>

      <!-- Desktop User -->
      <div class="navbar-nav ms-lg-3 mt-3 mt-lg-0">
        <div class="d-flex align-items-center gap-2 small text-light">
          <div class="position-relative">
            <img
              src="<?=htmlspecialchars($avatarUrl)?>"
              alt="Profilbild von <?=htmlspecialchars($currentName)?>"
              class="rounded-circle border border-secondary-subtle"
              style="width:54px; height:54px; object-fit:cover;"
            >
            <span
              id="onlineDot"
              class="position-absolute bottom-0 end-0 translate-middle p-1 bg-success border border-dark rounded-circle <?= $isOnline ? '' : 'd-none' ?>"
              title="Online"
              style="width:10px; height:10px;"
            ></span>
          </div>

          <div class="d-flex flex-column">
            <span class="small">Angemeldet als</span>
            <span>
              <span class="fw-semibold"><?=htmlspecialchars($currentName)?></span>
              <span class="badge bg-secondary ms-1"><?=htmlspecialchars($currentRole)?></span>

              <span
                id="onlineCountBadge"
                class="badge rounded-pill bg-success ms-2 <?= ($onlineUsersCount > 0 ? '' : 'd-none') ?>"
              >
                <span id="onlineCountNum"><?=$onlineUsersCount?></span> online
              </span>
            </span>
          </div>
          <?php if (canSeeTab('quiz', $currentRole, $tabPermissions)): ?>
  <a href="/quiz/quiz.php"
     class="btn btn-outline-light btn-sm me-2 position-relative <?= $quizDue ? 'wb-bell-glow' : '' ?>"
     title="<?= $quizDue ? htmlspecialchars($quizDueReason) : 'Quiz' ?>">
    <i class="bi bi-bell"></i>
    <?php if ($quizDue): ?>
      <span class="position-absolute top-0 start-100 translate-middle p-1 bg-warning border border-dark rounded-circle"
            style="width:10px;height:10px;"></span>
    <?php endif; ?>
  </a>
<?php endif; ?>

          <a href="?logout=1" class="btn btn-outline-light btn-sm ms-2">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
          </a>
        </div>
      </div>

    </div>
  </div>
</nav>


<!-- ✅ Mobile Offcanvas -->
<div class="offcanvas offcanvas-start wb-offcanvas"
     tabindex="-1"
     id="wbOffcanvas"
     aria-labelledby="wbOffcanvasLabel"
     data-bs-scroll="false">

  <div class="offcanvas-header">
    <div class="d-flex align-items-center gap-2 w-100">
      <img src="<?=htmlspecialchars($avatarUrl)?>"
           alt="Profilbild"
           class="rounded-circle border border-secondary-subtle"
           style="width:44px;height:44px;object-fit:cover;">

      <div class="flex-grow-1 min-w-0">
        <div id="wbOffcanvasLabel" class="fw-semibold text-light text-truncate">
          <?=htmlspecialchars($currentName)?>
        </div>

        <div class="small">
          <span class="badge bg-secondary"><?=htmlspecialchars($currentRole)?></span>

          <span id="ocOnlineCountBadge"
                class="badge rounded-pill bg-success ms-2 <?= ($onlineUsersCount > 0 ? '' : 'd-none') ?>">
            <span id="ocOnlineCountNum"><?=$onlineUsersCount?></span> online
          </span>
        </div>
      </div>

      <button type="button" class="btn-close btn-close-white ms-2"
              data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
  </div>

  <div class="offcanvas-body">
    <div class="list-group list-group-flush wb-offcanvas-list">

      <?php if (canSeeTab('dashboard', $currentRole, $tabPermissions)): ?>
        <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='dashboard'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#dashboard"
                data-url="/dashboard.html"
                data-module="/js/dashboard.js"
                data-init="initDashboard"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </button>
      <?php endif; ?>

      <?php if ($showDrivers): ?>
  <button class="list-group-item list-group-item-action wb-oc-item"
          data-bs-toggle="collapse"
          data-bs-target="#ocDrivers"
          aria-expanded="<?= $driversActive ? 'true' : 'false' ?>">
    <i class="bi bi-truck me-2"></i> Fahrer
    <i class="bi bi-chevron-down float-end"></i>
  </button>

  <div class="collapse <?= $driversActive ? 'show' : '' ?>" id="ocDrivers">
    <div class="list-group list-group-flush ps-2">

      <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='drivers'?'active':'')?>"
              data-bs-toggle="tab"
              data-bs-target="#drivers"
              data-url="/fahrer_ubersicht.html"
              data-module="/js/fahrer_ubersicht.js"
              data-init="initFahrerUebersicht"
              data-bs-dismiss="offcanvas">
        <i class="bi bi-speedometer2 text-primary me-2"></i> Fahrerübersicht
      </button>

      <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='drivers_kiosk'?'active':'')?>"
              data-bs-toggle="tab"
              data-bs-target="#drivers_kiosk"
              data-bs-dismiss="offcanvas">
        <i class="bi bi-keyboard text-success me-2"></i> Fahrer-Kiosk
      </button>

    </div>
  </div>
<?php endif; ?>

      <?php if ($showStamm): ?>
  <button class="list-group-item list-group-item-action wb-oc-item"
          data-bs-toggle="collapse"
          data-bs-target="#ocStammdaten"
          aria-expanded="<?= $stammActive ? 'true':'false' ?>">
    <i class="bi bi-box me-2"></i> Warenstamm
    <i class="bi bi-chevron-down float-end"></i>
  </button>

  <div class="collapse <?= $stammActive ? 'show':'' ?>" id="ocStammdaten">
    <div class="list-group list-group-flush ps-2">

      <?php if ($canGoods): ?>
        <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='goods'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#goods"
                data-url="/sachnummern.php"
                data-module="/js/sachnummern.js"
                data-init="initSachnummern"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-box text-primary me-2"></i> Warenstamm
        </button>
      <?php endif; ?>

      <?php if ($canLeergut): ?>
        <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='leergut'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#leergut"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-basket text-success me-2"></i> Leergut
        </button>
      <?php endif; ?>

    </div>
  </div>
<?php endif; ?>

      <?php if ($showWarenfluss): ?>
        <button class="list-group-item list-group-item-action wb-oc-item"
                data-bs-toggle="collapse"
                data-bs-target="#ocWarenfluss"
                aria-expanded="<?= $warenflussActive ? 'true':'false' ?>">
          <i class="bi bi-arrow-left-right me-2"></i> Warenfluss
          <i class="bi bi-chevron-down float-end"></i>
        </button>

        <div class="collapse <?= $warenflussActive ? 'show':'' ?>" id="ocWarenfluss">
          <div class="list-group list-group-flush ps-2">
            <?php if ($canInbound): ?>
              <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='inbound'?'active':'')?>"
                      data-bs-toggle="tab"
                      data-bs-target="#inbound"
                      data-bs-dismiss="offcanvas">
                <i class="bi bi-arrow-down-circle text-success me-2"></i> Wareneingang
              </button>
            <?php endif; ?>

            <?php if ($canOutbound): ?>
              <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='outbound'?'active':'')?>"
                      data-bs-toggle="tab"
                      data-bs-target="#outbound"
                      data-bs-dismiss="offcanvas">
                <i class="bi bi-arrow-up-circle text-danger me-2"></i> Warenausgang
              </button>
            <?php endif; ?>

            <?php if ($canKommiOrders): ?>
  <button class="list-group-item list-group-item-action wb-oc-sub <?=($defaultTabKey==='kommi_orders'?'active':'')?>"
          data-bs-toggle="tab"
          data-bs-target="#kommi_orders"
          data-bs-dismiss="offcanvas">
    <i class="bi bi-list-check text-primary me-2"></i> Kommi Orders
  </button>
<?php endif; ?>

            <?php if (canSeeTab('referenzvergleich', $currentRole, $tabPermissions)): ?>
  <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='referenzvergleich'?'active':'')?>"
          data-bs-toggle="tab"
          data-bs-target="#referenzvergleich"
          data-bs-dismiss="offcanvas">
    <i class="bi bi-upc-scan me-2"></i> Ref.-Vergleich
  </button>
<?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (canSeeTab('artikelakte', $currentRole, $tabPermissions)): ?>
  <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='artikelakte'?'active':'')?>"
          data-bs-toggle="tab"
          data-bs-target="#artikelakte"
          data-bs-dismiss="offcanvas">
    <i class="bi bi-search me-2"></i> Artikelakte
  </button>
<?php endif; ?>

      <?php if (canSeeTab('special', $currentRole, $tabPermissions)): ?>
        <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='special'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#special"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-wrench me-2"></i> 100%-Prüfung
        </button>
      <?php endif; ?>

      <?php if (canSeeTab('lagerplan', $currentRole, $tabPermissions)): ?>
  <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='lagerplan'?'active':'')?>"
          data-bs-toggle="tab"
          data-bs-target="#lagerplan"
          data-bs-dismiss="offcanvas">
    <i class="bi bi-map me-2"></i> Lagerplan
  </button>
<?php endif; ?>


      <?php if (canSeeTab('docs', $currentRole, $tabPermissions)): ?>
        <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='docs'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#docs"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-folder2-open me-2"></i> Dokumente
        </button>
      <?php endif; ?>

      <?php if (canSeeTab('arbeitsanweisung', $currentRole, $tabPermissions)): ?>
  <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='arbeitsanweisung'?'active':'')?>"
          data-bs-toggle="tab"
          data-bs-target="#arbeitsanweisung"
          data-bs-dismiss="offcanvas">
    <i class="bi bi-file-earmark-text me-2"></i> Arbeitsanweisung
  </button>
<?php endif; ?>

      <?php if (canSeeTab('admin', $currentRole, $tabPermissions)): ?>
        <button class="list-group-item list-group-item-action wb-oc-item <?=($defaultTabKey==='admin'?'active':'')?>"
                data-bs-toggle="tab"
                data-bs-target="#admin"
                data-bs-dismiss="offcanvas">
          <i class="bi bi-shield-lock me-2"></i> Admin
        </button>
      <?php endif; ?>
    </div>

    <hr class="border-light opacity-25">

    <a href="?logout=1" class="btn btn-outline-light w-100">
      <i class="bi bi-box-arrow-right me-1"></i> Logout
    </a>
  </div>
</div>



  <div class="tab-content py-4" style="margin: 8px;">

    <?php if (canSeeTab('dashboard', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'dashboard' ? ' show active' : '')?>" id="dashboard" role="tabpanel" aria-labelledby="tab-dashboard">
        <div class="p-3 text-muted">Lade Dashboard…</div>
      </div>
    <?php endif; ?>

    <?php if ($showDrivers): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'drivers' ? ' show active' : '')?>" id="drivers" role="tabpanel">
    <div class="p-3 text-muted">Lade Fahrer…</div>
  </div>

  <div class="tab-pane fade<?=($defaultTabKey === 'drivers_kiosk' ? ' show active' : '')?>" id="drivers_kiosk" role="tabpanel">
    <iframe
      src="https://your-workbench.de/drivers-kiosik.html"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

    <?php if (canSeeTab('goods', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'goods' ? ' show active' : '')?>" id="goods" role="tabpanel">
        <div class="p-3 text-muted">Lade Warenstamm…</div>
      </div>
    <?php endif; ?>
    <?php if (canSeeTab('leergut', $currentRole, $tabPermissions)): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'leergut' ? ' show active' : '')?>" id="leergut" role="tabpanel">
    <iframe
      src="/leergut/leergut_zaehlung.php?embed=1"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

    <?php if (canSeeTab('inbound', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'inbound' ? ' show active' : '')?>" id="inbound" role="tabpanel">
        <iframe src="/wareneingang.php?embed=1" class="w-100 tab-embed-iframe"></iframe>
      </div>
    <?php endif; ?>

    <?php if (canSeeTab('outbound', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'outbound' ? ' show active' : '')?>" id="outbound" role="tabpanel">
        <iframe src="/warenausgang.php?embed=1" class="w-100" style="border:0; min-height: calc(100vh - 140px);"></iframe>
      </div>
    <?php endif; ?>

    <?php if ($canKommiOrders): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'kommi_orders' ? ' show active' : '')?>" id="kommi_orders" role="tabpanel">
    <iframe
      src="https://your-workbench.de/kommi/orders.php"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

    <?php if (canSeeTab('referenzvergleich', $currentRole, $tabPermissions)): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'referenzvergleich' ? ' show active' : '')?>" id="referenzvergleich" role="tabpanel">
    <iframe
      src="/referenznummer_vergleich.html"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

<?php if (canSeeTab('artikelakte', $currentRole, $tabPermissions)): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'artikelakte' ? ' show active' : '')?>" id="artikelakte" role="tabpanel">
    <iframe
      src="/artikelakte/artikelakte.php?embed=1"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

    <?php if (canSeeTab('special', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'special' ? ' show active' : '')?>" id="special" role="tabpanel">
        <iframe src="/100_Prufung/100p_dashboard.php?embed=1" class="w-100" style="border:0; min-height: calc(100vh - 140px);"></iframe>
      </div>
    <?php endif; ?>

    <?php if (canSeeTab('lagerplan', $currentRole, $tabPermissions)): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'lagerplan' ? ' show active' : '')?>" id="lagerplan" role="tabpanel" aria-labelledby="tab-lagerplan">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 mobile-plan-switch">
      <div class="fw-semibold mobile-plan-switch-title">
        <i class="bi bi-map me-1"></i> Lagerplan auswählen
      </div>

      <div class="ms-auto mobile-plan-switch-select">
        <select id="lagerplanSelect" class="form-select form-select-sm">
          <option value="/Lagerplan/halle3.php">Halle 3</option>
          <option value="/Lagerplan/halle4.php">Halle 4</option>
          <option value="/Container/containerplan.php">Containerplan</option>
        </select>
      </div>
    </div>

    <div class="container-fluid px-3">
      <iframe
        id="lagerplanFrame"
        src="/Lagerplan/halle3.php"
        class="w-100 tab-embed-iframe"
        style="border:0;"
        loading="lazy"
      ></iframe>
    </div>

  </div>
<?php endif; ?>


    <?php if (canSeeTab('docs', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'docs' ? ' show active' : '')?>" id="docs" role="tabpanel">
        <iframe src="/dokumente/dokumente.php" class="w-100" style="border:0; min-height: calc(100vh - 140px);"></iframe>
      </div>
    <?php endif; ?>

    <?php if (canSeeTab('arbeitsanweisung', $currentRole, $tabPermissions)): ?>
  <div class="tab-pane fade<?=($defaultTabKey === 'arbeitsanweisung' ? ' show active' : '')?>" id="arbeitsanweisung" role="tabpanel">
    <iframe
      src="/arbeitsanweisung-generator.html"
      class="w-100 tab-embed-iframe"
      style="border:0; min-height: calc(100vh - 140px);"
      loading="lazy"
    ></iframe>
  </div>
<?php endif; ?>

    <?php if (canSeeTab('admin', $currentRole, $tabPermissions)): ?>
      <div class="tab-pane fade<?=($defaultTabKey === 'admin' ? ' show active' : '')?>" id="admin" role="tabpanel">
        <iframe id="adminFrame"
        data-src="/admin/admin.php?embed=1"
        src="about:blank"
        class="w-100"
        style="border:0; min-height: calc(100vh - 140px);"></iframe>

      </div>
    <?php endif; ?>

  </div>

<?php endif; ?>



<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- FIX: verhindert, dass DataTables fälschlich CommonJS/require nutzt -->
<script>
  window.module = undefined;
  window.exports = undefined;
</script>


<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>

<script src="/js/login.js"></script>
<script src="/js/workbench.js?v=1"></script>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const adminPane = document.getElementById("admin");
  const frame = document.getElementById("adminFrame");

  if (!adminPane || !frame) return;

  function ensureAdminFrameLoaded() {
    const src = frame.dataset.src || "";
    if (!src) return;

    const current = frame.getAttribute("src") || "";
    if (current && current !== "about:blank") return;

    frame.src = src;
  }

  // Für ALLE Admin-Trigger: Desktop + Mobile
  document.addEventListener("shown.bs.tab", (e) => {
    const btn = e.target?.closest?.('[data-bs-toggle="tab"]');
    if (!btn) return;

    const target = btn.getAttribute("data-bs-target");
    if (target === "#admin") {
      ensureAdminFrameLoaded();
    }
  });

  // Falls Admin schon aktiv ist (z.B. Reload mit ?tab=admin)
  if (adminPane.classList.contains("active") || adminPane.classList.contains("show")) {
    ensureAdminFrameLoaded();
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const navbar = document.getElementById('mainNavbar');
  if (!navbar) return;

  let lastParentY = window.scrollY || 0;
  let ticking = false;

  const MOBILE_BREAKPOINT = 991.98;
  const TOP_SHOW_THRESHOLD = 8;
  const DELTA = 6;
  const HIDE_AFTER = 80;

  function isMobile() {
    return window.innerWidth <= MOBILE_BREAKPOINT;
  }

  function showNavbar() {
    navbar.classList.remove('nav-hidden');
  }

  function hideNavbar() {
    navbar.classList.add('nav-hidden');
  }

  // ✅ GEÄNDERT: nur oben wieder zeigen
  function applyScrollBehavior(y, direction) {
    if (!isMobile()) {
      showNavbar();
      return;
    }

    // Nur ganz oben sichtbar
    if (y <= TOP_SHOW_THRESHOLD) {
      showNavbar();
      return;
    }

    // Nach unten => ausblenden (ab etwas Scrollstrecke)
    if (direction === 'down' && y > HIDE_AFTER) {
      hideNavbar();
      return;
    }

    // Nach oben => NICHT automatisch einblenden
    // (bewusst leer)
  }

  // Fallback: wenn Parent selbst scrollt
  function onParentScroll() {
    const y = window.scrollY || window.pageYOffset || 0;

    if (!isMobile()) {
      showNavbar();
      lastParentY = y;
      ticking = false;
      return;
    }

    const diff = y - lastParentY;

    // Ganz oben immer zeigen
    if (y <= TOP_SHOW_THRESHOLD) {
      showNavbar();
      lastParentY = y;
      ticking = false;
      return;
    }

    if (Math.abs(diff) < DELTA) {
      ticking = false;
      return;
    }

    applyScrollBehavior(y, diff > 0 ? 'down' : 'up');

    lastParentY = y;
    ticking = false;
  }

  function requestTick() {
    if (!ticking) {
      requestAnimationFrame(onParentScroll);
      ticking = true;
    }
  }

  window.addEventListener('scroll', requestTick, { passive: true });

  // ✅ iframe -> parent
  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) return;

    const data = event.data;
    if (!data || typeof data !== 'object') return;
    if (data.type !== 'workbench:iframe-scroll') return;

    const y = Number(data.y || 0);
    const direction = (data.direction === 'down') ? 'down' : 'up';

    applyScrollBehavior(y, direction);
  });

  // Offcanvas offen => Navbar immer sichtbar
  const wbOffcanvas = document.getElementById('wbOffcanvas');
  if (wbOffcanvas) {
    wbOffcanvas.addEventListener('show.bs.offcanvas', () => showNavbar());
    wbOffcanvas.addEventListener('shown.bs.offcanvas', () => showNavbar());
    wbOffcanvas.addEventListener('hidden.bs.offcanvas', () => showNavbar());
  }

  // Optional: bei Tabwechsel sichtbar (kannst du drin lassen)
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach(el => {
    el.addEventListener('shown.bs.tab', () => showNavbar());
  });

  // Start
  showNavbar();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const warenflussToggle = document.getElementById('tab-warenfluss');
  if (!warenflussToggle) return;

  const dropdownEl = warenflussToggle.closest('.dropdown');
  if (!dropdownEl) return;

  const dropdownItems = dropdownEl.querySelectorAll('.dropdown-menu [data-bs-toggle="tab"]');

  dropdownItems.forEach(item => {
    item.addEventListener('click', () => {
      const dropdownInstance = bootstrap.Dropdown.getOrCreateInstance(warenflussToggle);
      dropdownInstance.hide();
    });
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const dropdownToggleIds = ['tab-warenfluss', 'tab-stammdaten'];

  function getOpenDropdownToggles() {
    return dropdownToggleIds
      .map(id => document.getElementById(id))
      .filter(toggle => toggle && toggle.getAttribute('aria-expanded') === 'true');
  }

  function closeOpenDropdowns() {
    getOpenDropdownToggles().forEach(toggle => {
      const instance = bootstrap.Dropdown.getOrCreateInstance(toggle);
      instance.hide();
    });
  }

  // 1) Beim Scrollen schließen
  window.addEventListener('scroll', closeOpenDropdowns, { passive: true });

  // 2) Beim Klick in freien Raum schließen
  document.addEventListener('click', (event) => {
    const openToggles = getOpenDropdownToggles();
    if (!openToggles.length) return;

    const clickedInsideAnyDropdown = openToggles.some(toggle => {
      const dropdown = toggle.closest('.dropdown');
      return dropdown && dropdown.contains(event.target);
    });

    if (!clickedInsideAnyDropdown) {
      closeOpenDropdowns();
    }
  });

  // 3) Optional: Bei Resize auch schließen
  window.addEventListener('resize', closeOpenDropdowns);
});
</script>
</body>
</html>
