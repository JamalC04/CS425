#!/usr/bin/env php
<?php
/**
 * /proj/scripts/cron_notifications.php
 * Scheduled email jobs that can't run inline (slow, bulk).
 *
 * Add to crontab (crontab -e as root or apache user):
 *
 *   # Draft reminder — runs Mon/Wed/Fri at 9am
 *   0 9 * * 1,3,5 /usr/bin/php /proj/scripts/cron_notifications.php --job=draft_reminder >> /proj/logs/cron.log 2>&1
 *
 *   # Weekly digest — runs every Monday at 8am
 *   0 8 * * 1 /usr/bin/php /proj/scripts/cron_notifications.php --job=weekly_digest >> /proj/logs/cron.log 2>&1
 *
 *   # Banner sync — runs 1st of Jan, May, Aug at 3am
 *   0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py >> /proj/logs/sync_banner.log 2>&1
 */

// Bootstrap
define('CRON', true);
require_once '/proj/www/includes/db.php';
require_once '/proj/www/includes/mailer.php';

$pdo = db();

// Parse --job argument
$job = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $job = substr($arg, 6);
    }
}

$ts = date('Y-m-d H:i:s');

switch ($job) {
    case 'draft_reminder':
        echo "[$ts] Running draft_reminder...\n";
        notify_faculty_draft_reminder($pdo, days_old: 7);
        echo "[$ts] Done.\n";
        break;

    case 'weekly_digest':
        echo "[$ts] Running weekly_digest...\n";
        send_admin_weekly_digest($pdo);
        echo "[$ts] Done.\n";
        break;

    default:
        echo "[$ts] Usage: cron_notifications.php --job=draft_reminder|weekly_digest\n";
        exit(1);
}
