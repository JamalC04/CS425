<?php
/**
 * index.php — Entry point
 * Reads auth state and redirects user to their role-specific dashboard.
 * If unauthenticated, shows a login landing page.
 */
session_start();
require_once __DIR__ . '/includes/auth.php';

if (!$CURRENT_USER) {
    // Show login landing page
    $PAGE_TITLE   = 'Sign In — ACCESS IEA Forms';
    $PAGE_HEADING = null;
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div style="max-width:520px; margin:4rem auto; text-align:center;">
      <h1 style="font-size:1.75rem; color:var(--siue-navy); margin-bottom:.75rem;">
        IEA Forms Portal
      </h1>
      <p style="color:var(--gray-500); margin-bottom:2rem;">
        Sign in with your SIUE Microsoft account to access your forms.
      </p>
      <a href="/mellon/login?ReturnTo=/" class="btn btn--primary btn--lg">
        Sign in with SIUE
      </a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Redirect to role dashboard
match ($CURRENT_USER['role']) {
    'admin'   => header('Location: /admin/dashboard.php'),
    'faculty' => header('Location: /faculty/dashboard.php'),
    default   => header('Location: /student/dashboard.php'),
};
exit;
