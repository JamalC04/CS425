<?php
/**
 * auth.php — Authentication & Role Middleware
 * Include at the top of every page: require_once __DIR__ . '/auth.php';
 *
 * Reads the logged-in user from Apache's /auth/userinfo endpoint,
 * looks them up in MariaDB, and exposes $CURRENT_USER globally.
 *
 * $CURRENT_USER shape:
 *   [
 *     'user_id'    => int,
 *     'email'      => string,
 *     'given_name' => string,
 *     'surname'    => string,
 *     'role'       => 'student'|'faculty'|'admin',
 *     'entra_oid'  => string,
 *   ]
 * or null if not authenticated.
 */

require_once __DIR__ . '/db.php';

/**
 * Fetch the current user's Entra ID attributes from Apache's mellon endpoint.
 * Returns array of attributes or null if unauthenticated.
 */
function fetch_userinfo(): ?array {
    // Call the local /auth/userinfo endpoint (loopback — always fast)
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 3,
            'header'  => implode("\r\n", [
                // Forward the session cookie so mellon recognises the session
                'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
            ]),
        ],
    ]);

    $json = @file_get_contents('http://localhost/auth/userinfo', false, $ctx);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!$data || !($data['authenticated'] ?? false)) return null;

    return $data['attributes'] ?? null;
}

/**
 * Look up a user by email. If not found, optionally auto-provision as 'student'.
 * Returns user row or null.
 */
function find_or_create_user(array $attrs): ?array {
    $pdo   = db();
    $email = strtolower(trim($attrs['emailaddress'] ?? ''));
    if (!$email) return null;

    // Look up existing user
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Update name / entra_oid if changed
        $pdo->prepare('UPDATE users SET given_name=?, surname=?, entra_oid=? WHERE user_id=?')
            ->execute([
                $attrs['givenname'] ?? $user['given_name'],
                $attrs['surname']   ?? $user['surname'],
                $attrs['objectid']  ?? $user['entra_oid'],
                $user['user_id'],
            ]);
        return $user;
    }

    // Auto-provision new users as 'student'.
    // Role upgrades (faculty/admin) must be done manually via the DB.
    $pdo->prepare(
        'INSERT INTO users (email, given_name, surname, role, entra_oid)
         VALUES (?, ?, ?, \'student\', ?)'
    )->execute([
        $email,
        $attrs['givenname'] ?? '',
        $attrs['surname']   ?? '',
        $attrs['objectid']  ?? null,
    ]);

    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Redirect to the mellon login page, returning to current URL after auth.
 */
function require_login(): never {
    $return_to = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: /mellon/login?ReturnTo=' . $return_to);
    exit;
}

/**
 * Enforce that the current user has one of the allowed roles.
 * Call after init_auth(). Shows 403 if role not allowed.
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

$CURRENT_USER = null;

$attrs = fetch_userinfo();
if ($attrs) {
    $CURRENT_USER = find_or_create_user($attrs);
}
