<?php
/**
 * includes/auth.php — Production Authentication
 *
 * Attribute map confirmed from debug.php on iaef.isg.siue.edu:
 *
 *   MELLON_email       → user's SIUE email  (NOT emailaddress)
 *   MELLON_givenname   → first name
 *   MELLON_surname     → last name
 *   MELLON_siueId      → SIUE ID number
 *   MELLON_NAME_ID     → email (reliable fallback)
 *   REMOTE_USER        → username@siue.edu  (ultimate fallback)
 *
 * MellonEnable "info" is kept in Apache config.
 * PHP handles the auth redirect with auth_attempted loop guard.
 */

require_once '/proj/config/db.php';

function fetch_userinfo(): ?array {
    // Email — confirmed key is MELLON_email (not MELLON_emailaddress)
    $email = strtolower(trim(
        $_SERVER['MELLON_email']     ??
        $_SERVER['MELLON_NAME_ID']   ??
        $_SERVER['REMOTE_USER']      ?? ''
    ));

    // First name
    $given = trim(
        $_SERVER['MELLON_givenname'] ??
        $_SERVER['HTTP_MELLON_GIVENNAME'] ?? ''
    );

    // Last name
    $surname = trim(
        $_SERVER['MELLON_surname'] ??
        $_SERVER['HTTP_MELLON_SURNAME'] ?? ''
    );

    // Object ID — full URI form with slashes → underscores
    $oid = trim(
        $_SERVER['MELLON_http://schemas_microsoft_com/identity/claims/objectidentifier'] ??
        $_SERVER['MELLON_objectid'] ?? ''
    );

    // SIUE ID
    $siue_id = trim($_SERVER['MELLON_siueId'] ?? '');

    // Build display name
    $full_name = trim("$given $surname");
    if (!$full_name) {
        // Fall back to the part of REMOTE_USER before the @
        $full_name = explode('@', $email)[0] ?? '';
    }

    if (!$email) {
        return null;
    }

    return [
        'emailaddress' => $email,
        'givenname'    => $given,
        'surname'      => $surname,
        'objectid'     => $oid,
        'siue_id'      => $siue_id,
        'full_name'    => $full_name,
    ];
}

function find_or_create_user(array $attrs): ?array {
    $pdo   = db();
    $email = $attrs['emailaddress'];

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
        // Keep name current
        $pdo->prepare(
            'UPDATE users SET given_name=?, surname=?, entra_oid=?, updated_at=NOW()
             WHERE user_id=?'
        )->execute([
            $attrs['givenname'] ?: $user['given_name'],
            $attrs['surname']   ?: $user['surname'],
            $attrs['objectid']  ?: $user['entra_oid'],
            $user['user_id'],
        ]);
        // Re-fetch updated row
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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

function require_login(): never {
    if (!isset($_GET['auth_attempted'])) {
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        // Append auth_attempted to ReturnTo so we catch loops
        $sep    = str_contains($uri, '?') ? '&' : '?';
        header('Location: /mellon/login?ReturnTo=' . urlencode($uri . $sep . 'auth_attempted=1'));
    } else {
        http_response_code(403);
        include __DIR__ . '/../views/403_auth.php';
    }
    exit;
}

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

if (!$CURRENT_USER && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/mellon')) {
    require_login();
}
