<?php
/**
 * index.php — Entry point
 *
 * Uses the confirmed working auth pattern from the test landing page:
 * reads MELLON_ attributes directly from $_SERVER with HTTP_ fallback,
 * and uses auth_attempted loop guard to prevent infinite redirects.
 */
require_once __DIR__ . '/includes/auth.php';

// auth.php already handles the login redirect if needed.
// If we get here, $CURRENT_USER is set.
if (!$CURRENT_USER) {
    require_login();
}

match ($CURRENT_USER['role']) {
    'admin'   => header('Location: /admin/dashboard.php'),
    'faculty' => header('Location: /faculty/dashboard.php'),
    default   => header('Location: /student/dashboard.php'),
};
exit;
