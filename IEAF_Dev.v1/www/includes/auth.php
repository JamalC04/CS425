<?php
/**
 * includes/auth.php — Production Authentication
 *
 * Reads mod_auth_mellon attributes using the confirmed working pattern
 * from index.php. Tries $_SERVER['MELLON_*'] first, then falls back to
 * $_SERVER['HTTP_MELLON_*'] (which is how this server passes them).
 *
 * No loopback HTTP call. No infinite loop possible.
 * Apache's MellonEnable "info" is sufficient — PHP handles the redirect
 * with the auth_attempted loop guard.
 */

require_once '/proj/config/db.php';

/**
 * Read Entra ID attributes from mod_auth_mellon.
 * Returns null if no Mellon session exists (user not authenticated).
 */
function fetch_userinfo(): ?array {
    // Try direct env vars first, then HTTP_ prefixed fallback
    // (this server passes them as HTTP_ headers)
    $email = strtolower(trim(
        $_SERVER['MELLON_emailaddress']     ??
        $_SERVER['HTTP_MELLON_EMAILADDRESS'] ?? ''
    ));

    $given = trim(
        $_SERVER['MELLON_givenname']     ??
        $_SERVER['HTTP_MELLON_GIVENNAME'] ?? ''
    );

    $surname = trim(
        $_SERVER['MELLON_surname']     ??
        $_SERVER['HTTP_MELLON_SURNAME'] ?? ''
    );

    $oid = trim(
        $_SERVER['MELLON_objectid']     ??
        $_SERVER['HTTP_MELLON_OBJECTID'] ?? ''
    );

    // Build full name — fall back to sAMAccountName or REMOTE_USER if needed
    $full_name = trim("$given $surname");
    if (!$full_name) {
        $full_name = trim(
            $_SERVER['MELLON_sAMAccountName'] ??
            $_SERVER['HTTP_MELLON_SAMACCOUNTNAME'] ??
            $_SERVER['REMOTE_USER'] ?? ''
        );
    }

    // If we have neither a name nor an email, user is not authenticated
    if (!$full_name && !$email) {
        return null;
    }

    return [
        'emailaddress' => $email,
        'givenname'    => $given,
        'surname'      => $surname,
        'objectid'     => $oid,
        'full_name'    => $full_name,
    ];
}

/**
 * Look up or auto-provision the user in MariaDB.
 * Every authenticated Entra ID account is created as 'student' on first login.
 */
function find_or_create_user(array $attrs): ?array {
    $pdo   = db();
    $email = $attrs['emailaddress'];

    // If no email, use full_name as a unique key fallback
    if (!$email) {
        error_log("AUTH: No email in Mellon attrs — cannot provision user.");
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if (!$user['active']) {
            error_log("AUTH: Blocked inactive account: $email");
            return null;
        }
        $pdo->prepare(
            'UPDATE users SET given_name=?, surname=?, entra_oid=?, updated_at=NOW()
             WHERE user_id=?'
        )->execute([
            $attrs['givenname'] ?: $user['given_name'],
            $attrs['surname']   ?: $user['surname'],
            $attrs['objectid']  ?: $user['entra_oid'],
            $user['user_id'],
        ]);
        return $user;
    }

    // Auto-provision new user as student
    $pdo->prepare(
        "INSERT INTO users (email, given_name, surname, role, entra_oid, active)
         VALUES (?, ?, ?, 'student', ?, 1)"
    )->execute([
        $email,
        $attrs['givenname'],
        $attrs['surname'],
        $attrs['objectid'] ?: null,
    ]);

    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Redirect to Mellon login with loop guard.
 * Adds ?auth_attempted=1 so if it fails again we show an error
 * instead of looping forever.
 */
function require_login(): never {
    if (!isset($_GET['auth_attempted'])) {
        $return_to = urlencode(($_SERVER['REQUEST_URI'] ?? '/') . '?auth_attempted=1');
        header('Location: /mellon/login?ReturnTo=' . $return_to);
    } else {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
              <title>Authentication Error</title></head><body>
              <h1>Authentication Error</h1>
              <p>Unable to retrieve your session attributes after login.</p>
              <p><a href="/mellon/login?ReturnTo=/">Try again</a></p>
              </body></html>';
    }
    exit;
}

/**
 * Enforce role access. Shows 403 page if role is insufficient.
 */
function require_role(string ...$roles): void {
    global $CURRENT_USER;
    if (!$CURRENT_USER || !in_array($CURRENT_USER['role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../views/403.php';
        exit;
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$CURRENT_USER = null;
$attrs = fetch_userinfo();

if ($attrs) {
    $CURRENT_USER = find_or_create_user($attrs);
}

// If still no user, send to login (with loop guard)
if (!$CURRENT_USER && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/mellon')) {
    require_login();
}
