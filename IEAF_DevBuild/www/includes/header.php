<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($PAGE_TITLE ?? 'ACCESS — IEA Forms') ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<!-- FIX 2.4.7: skip link uses clip-path off-screen, not top:-100% -->
<a class="skip-link" href="#main-content">Skip to main content</a>

<!-- ── SIUE Top Bar ─────────────────────────────────────────────────────── -->
<!-- FIX 1.4.3: text now solid white, not rgba(.75) which failed -->
<div class="siue-topbar" role="banner">
  <a href="https://www.siue.edu" class="siue-topbar__wordmark"
     aria-label="Southern Illinois University Edwardsville — home page">
    <span class="siue-topbar__siue" aria-hidden="true">SIUE</span>
    <span class="siue-topbar__full">Southern Illinois University Edwardsville</span>
  </a>
</div>

<!-- ── Department Header ────────────────────────────────────────────────── -->
<header class="dept-header">
  <div class="dept-header__inner">
    <a href="/" class="dept-header__title">
      ACCESS — IEA Forms Portal
    </a>
    <div class="dept-header__user">
      <?php if ($CURRENT_USER): ?>
        <span class="user-name" aria-label="Signed in as <?= htmlspecialchars($CURRENT_USER['given_name'] . ' ' . $CURRENT_USER['surname']) ?>">
          <?= htmlspecialchars($CURRENT_USER['given_name'] . ' ' . $CURRENT_USER['surname']) ?>
          <span class="role-badge role-badge--<?= $CURRENT_USER['role'] ?>" aria-label="Role: <?= ucfirst($CURRENT_USER['role']) ?>">
            <?= ucfirst($CURRENT_USER['role']) ?>
          </span>
        </span>
        <a href="/account/notifications.php" class="btn btn--outline btn--sm">
          Notifications
        </a>
        <a href="/mellon/logout?ReturnTo=/" class="btn btn--outline btn--sm">
          Sign out
        </a>
      <?php else: ?>
        <a href="/mellon/login?ReturnTo=/" class="btn btn--primary btn--sm">Sign in with SIUE</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- ── Navigation ───────────────────────────────────────────────────────── -->
<?php if ($CURRENT_USER): ?>
<nav class="site-nav" aria-label="Main navigation">
  <div class="site-nav__inner">
    <?php
    $nav_active = $ACTIVE_NAV ?? '';
    if ($CURRENT_USER['role'] === 'student'): ?>
      <!-- FIX 2.4.6: aria-current="page" on active link (not just class) -->
      <a class="site-nav__link <?= $nav_active === 'dashboard' ? 'is-active' : '' ?>"
         href="/student/dashboard.php"
         <?= $nav_active === 'dashboard' ? 'aria-current="page"' : '' ?>>My Forms</a>

    <?php elseif ($CURRENT_USER['role'] === 'faculty'): ?>
      <a class="site-nav__link <?= $nav_active === 'dashboard' ? 'is-active' : '' ?>"
         href="/faculty/dashboard.php"
         <?= $nav_active === 'dashboard' ? 'aria-current="page"' : '' ?>>My Courses</a>
      <a class="site-nav__link <?= $nav_active === 'new_std' ? 'is-active' : '' ?>"
         href="/forms/ieaf_standard.php"
         <?= $nav_active === 'new_std' ? 'aria-current="page"' : '' ?>>Standard IEAF</a>
      <a class="site-nav__link <?= $nav_active === 'new_ess' ? 'is-active' : '' ?>"
         href="/forms/ieaf_essential.php"
         <?= $nav_active === 'new_ess' ? 'aria-current="page"' : '' ?>>Essential Abilities IEAF</a>

    <?php elseif ($CURRENT_USER['role'] === 'admin'): ?>
      <a class="site-nav__link <?= $nav_active === 'dashboard' ? 'is-active' : '' ?>"
         href="/admin/dashboard.php"
         <?= $nav_active === 'dashboard' ? 'aria-current="page"' : '' ?>>All Submissions</a>
      <a class="site-nav__link <?= $nav_active === 'users' ? 'is-active' : '' ?>"
         href="/admin/users.php"
         <?= $nav_active === 'users' ? 'aria-current="page"' : '' ?>>Manage Users</a>
      <a class="site-nav__link <?= $nav_active === 'sync' ? 'is-active' : '' ?>"
         href="/admin/sync.php"
         <?= $nav_active === 'sync' ? 'aria-current="page"' : '' ?>>Sync &amp; Email Log</a>
    <?php endif; ?>
  </div>
</nav>
<?php endif; ?>

<!-- ── Page Content ─────────────────────────────────────────────────────── -->
<main id="main-content" class="main-content">

  <?php if (!empty($PAGE_HEADING)): ?>
  <div class="page-header">
    <h1 class="page-header__title"><?= htmlspecialchars($PAGE_HEADING) ?></h1>
    <?php if (!empty($PAGE_SUBHEADING)): ?>
    <p class="page-header__sub"><?= htmlspecialchars($PAGE_SUBHEADING) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_success'])): ?>
  <!-- role="status" for non-urgent, role="alert" for errors -->
  <div class="alert alert--success" role="status" aria-live="polite">
    <?= htmlspecialchars($_SESSION['flash_success']) ?>
  </div>
  <?php unset($_SESSION['flash_success']); endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert--error" role="alert" aria-live="assertive">
    <?= htmlspecialchars($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); endif; ?>
