<?php
/**
 * db.php — MariaDB connection (PDO singleton)
 *
 * Reads environment from /proj/config/db.php (not web-accessible).
 * Falls back to compile-time defaults for local dev.
 *
 * Usage: $pdo = db();
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Load config from outside webroot
    $config_file = '/proj/config/db.php';
    if (file_exists($config_file)) {
        $cfg = require $config_file;
    } else {
        // Dev fallback — CHANGE before deploying to prod
        $cfg = [
            'host'   => '127.0.0.1',
            'port'   => '3306',
            'dbname' => 'dev',           // swap to 'prod' for production
            'user'   => 'iaefuser',
            'pass'   => '',              // set in /proj/config/db.php
            'charset'=> 'utf8mb4',
        ];
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'], $cfg['port'], $cfg['dbname'], $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
