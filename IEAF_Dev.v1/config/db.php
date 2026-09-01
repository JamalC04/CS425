<?php
/**
 * /proj/config/db.php
 *
 * Database connection — lives at /proj/config/ which is
 * outside the web root and blocked by Apache.
 * Never accessible from a browser.
 *
 * To switch dev → prod: change 'dev' to 'prod' on the DSN line.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO(
        'mysql:host=localhost;port=3306;dbname=dev;charset=utf8mb4',
        'iaefuser',
        '9-skippers-flame-pencil-3-thunder-BRISK',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}
