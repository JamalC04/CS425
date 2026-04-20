<?php
/**
 * /proj/config/mail.php
 * Lives OUTSIDE the Apache DocumentRoot — never web-accessible.
 *
 * Get SMTP credentials from SIUE ITS:
 *   Submit a ticket at: https://www.siue.edu/its/
 *   Request: "SMTP relay credentials for internal application (ACCESS IEAF portal)"
 *   They typically provide: host, port, username, and an app password.
 *
 * Common SIUE SMTP options:
 *   Option A — smtp.siue.edu:587 (TLS) with dedicated service account
 *   Option B — Use Microsoft 365 SMTP relay (smtp.office365.com:587)
 *              since SIUE email is on M365. Auth with an app password on
 *              a shared mailbox like myaccess@siue.edu.
 */
return [
    'host'      => 'smtp.office365.com',    // M365 SMTP relay
    'port'      => 587,
    'username'  => 'myaccess@siue.edu',     // the ACCESS shared mailbox
    'password'  => 'REPLACE_ME',            // app password from M365 admin
    'from'      => 'myaccess@siue.edu',
    'from_name' => 'ACCESS — IEA Forms',
    'debug'     => 0,   // set to 2 and tail error.log to troubleshoot SMTP
];
