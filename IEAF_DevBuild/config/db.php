<?php
/**
 * /proj/config/db.php
 * Lives OUTSIDE the Apache DocumentRoot — never web-accessible.
 * Fill in the password from /root/iaefuser_setup.sql
 */
return [
    'host'    => '127.0.0.1',
    'port'    => '3306',
    'dbname'  => 'dev',       // change to 'prod' for production
    'user'    => 'iaefuser',
    'pass'    => 'REPLACE_ME', // from /root/iaefuser_setup.sql
    'charset' => 'utf8mb4',
];
