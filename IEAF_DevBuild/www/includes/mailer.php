<?php
/**
 * /proj/www/includes/mailer.php — Production email via PHPMailer + SIUE SMTP
 *
 * Install PHPMailer once on the server:
 *   cd /proj && composer require phpmailer/phpmailer
 *
 * Or without Composer:
 *   cd /proj && mkdir -p vendor/phpmailer
 *   wget https://github.com/PHPMailer/PHPMailer/archive/master.zip -O /tmp/pm.zip
 *   unzip /tmp/pm.zip -d /proj/vendor/phpmailer/
 *
 * SIUE SMTP settings (from ITS — confirm with your ITS contact):
 *   Host: smtp.siue.edu   Port: 587   Auth: TLS   User: your-app-account@siue.edu
 *
 * All functions log to email_log table automatically.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer — try Composer autoload first, then manual
if (file_exists('/proj/vendor/autoload.php')) {
    require_once '/proj/vendor/autoload.php';
} else {
    require_once '/proj/vendor/phpmailer/PHPMailer-master/src/Exception.php';
    require_once '/proj/vendor/phpmailer/PHPMailer-master/src/PHPMailer.php';
    require_once '/proj/vendor/phpmailer/PHPMailer-master/src/SMTP.php';
}

// ── Email config — stored outside webroot ─────────────────────────────────
function _mail_config(): array {
    $cfg_file = '/proj/config/mail.php';
    if (file_exists($cfg_file)) return require $cfg_file;
    return [
        'host'     => 'smtp.siue.edu',
        'port'     => 587,
        'username' => 'no-reply@siue.edu',     // replace with actual app account
        'password' => 'REPLACE_ME',
        'from'     => 'no-reply@siue.edu',
        'from_name'=> 'ACCESS — IEA Forms',
        'debug'    => 0,  // 0=off, 2=verbose (use 2 when troubleshooting)
    ];
}

// ── Core send function ────────────────────────────────────────────────────
/**
 * Send an email. Returns true on success.
 *
 * @param string|array $to       Single email string or ['email' => 'name', ...]
 * @param string       $subject
 * @param string       $body_html  HTML body (plain-text fallback auto-generated)
 * @param ?PDO         $pdo        If provided, logs the send attempt
 * @param int|null     $submission_id  For the email_log foreign key
 * @param string       $type       Log type label
 */
function send_email(
    string|array $to,
    string $subject,
    string $body_html,
    ?PDO $pdo = null,
    ?int $submission_id = null,
    string $type = 'general'
): bool {
    $cfg = _mail_config();
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $cfg['port'];
        $mail->SMTPDebug  = $cfg['debug'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['from'], $cfg['from_name']);
        $mail->addReplyTo('myaccess@siue.edu', 'ACCESS Department');

        // Recipients
        if (is_string($to)) {
            $mail->addAddress($to);
        } else {
            foreach ($to as $email => $name) {
                $mail->addAddress(is_numeric($email) ? $name : $email,
                                  is_numeric($email) ? '' : $name);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = _wrap_html($body_html, $subject);
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], "\n", $body_html));

        $mail->send();
        _log_email($pdo, $submission_id, is_string($to) ? $to : implode(', ', array_keys($to)), $subject, $type, true);
        return true;

    } catch (Exception $e) {
        $msg = $mail->ErrorInfo;
        error_log("PHPMailer error ($type): $msg");
        _log_email($pdo, $submission_id, is_string($to) ? $to : 'multiple', $subject, $type, false, $msg);
        return false;
    }
}

// ── Notification functions ────────────────────────────────────────────────

/**
 * EVENT: Faculty submits a new IEAF → notify all ACCESS admins.
 */
function notify_admins_new_submission(PDO $pdo, int $submission_id): void {
    $info = _load_submission_info($pdo, $submission_id);
    if (!$info) return;

    $admins = $pdo->query("SELECT email, given_name FROM users WHERE role='admin' AND active=1")
                  ->fetchAll(PDO::FETCH_ASSOC);
    if (!$admins) return;

    $form_label = $info['form_type'] === 'essential' ? 'Essential Abilities IEAF' : 'Standard IEAF';
    $url = "https://Server.Name/admin/dashboard.php";

    foreach ($admins as $admin) {
        $body = _email_body("New IEAF Requires Review", "
            <p>Hi {$admin['given_name']},</p>
            <p>A new <strong>$form_label</strong> has been submitted and requires your review.</p>
            <table class='info-table'>
              <tr><th>Course</th><td>{$info['course_name']} ({$info['crn']})</td></tr>
              <tr><th>Term</th><td>{$info['term']}</td></tr>
              <tr><th>Faculty</th><td>{$info['faculty_name']} &lt;{$info['faculty_email']}&gt;</td></tr>
              <tr><th>Submitted</th><td>{$info['created_at']}</td></tr>
            </table>
            <p><a class='btn' href='$url'>Review in Portal</a></p>
        ");
        send_email(
            $admin['email'],
            "[ACCESS] New IEAF Submitted: {$info['course_name']} ({$info['term']})",
            $body, $pdo, $submission_id, 'admin_notify'
        );
    }
}

/**
 * EVENT: Admin approves an IEAF → notify all enrolled students.
 */
function notify_students_approved(PDO $pdo, int $submission_id): void {
    $info = _load_submission_info($pdo, $submission_id);
    if (!$info) return;

    $students = $pdo->prepare("
        SELECT u.email, u.given_name
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id
        WHERE e.course_id = ?
    ");
    $students->execute([$info['course_id']]);

    $form_label = $info['form_type'] === 'essential' ? 'Essential Abilities IEAF' : 'Standard IEAF';
    $url = "https://Server.Name/student/dashboard.php";

    foreach ($students->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $body = _email_body("Your IEA Form is Ready", "
            <p>Hi {$s['given_name']},</p>
            <p>An <strong>$form_label</strong> for one of your courses has been approved and
            is now available for you to view.</p>
            <table class='info-table'>
              <tr><th>Course</th><td>{$info['course_name']}</td></tr>
              <tr><th>Term</th><td>{$info['term']}</td></tr>
              <tr><th>Instructor</th><td>{$info['faculty_name']}</td></tr>
            </table>
            <p><a class='btn' href='$url'>View My Forms</a></p>
            <p style='color:#666;font-size:.85em'>
              If you have any questions about your accommodations, contact ACCESS at
              <a href='mailto:myaccess@siue.edu'>myaccess@siue.edu</a>.
            </p>
        ");
        send_email(
            $s['email'],
            "[ACCESS] Your IEA Form is Ready: {$info['course_name']}",
            $body, $pdo, $submission_id, 'student_approved'
        );
    }
}

/**
 * EVENT: Admin rejects/requests changes → notify the faculty member.
 */
function notify_faculty_rejected(PDO $pdo, int $submission_id, string $note): void {
    $info = _load_submission_info($pdo, $submission_id);
    if (!$info) return;

    $form_label = $info['form_type'] === 'essential' ? 'Essential Abilities IEAF' : 'Standard IEAF';
    $edit_url   = "https://Server.Name/forms/ieaf_{$info['form_type']}.php?id=$submission_id";

    $body = _email_body("Your IEAF Needs Changes", "
        <p>Hi {$info['faculty_name']},</p>
        <p>Your <strong>$form_label</strong> for <strong>{$info['course_name']}</strong>
        has been returned by ACCESS and requires changes before it can be approved.</p>
        <div class='note-box'>
          <strong>Comments from ACCESS:</strong><br>
          " . nl2br(htmlspecialchars($note)) . "
        </div>
        <p><a class='btn' href='$edit_url'>Edit and Resubmit</a></p>
        <p style='color:#666;font-size:.85em'>
          If you have questions, reply to this email or contact
          <a href='mailto:myaccess@siue.edu'>myaccess@siue.edu</a>.
        </p>
    ");
    send_email(
        $info['faculty_email'],
        "[ACCESS] IEAF Needs Changes: {$info['course_name']} ({$info['term']})",
        $body, $pdo, $submission_id, 'faculty_rejected'
    );
}

/**
 * EVENT: Faculty saves a draft (optional — keep as reminder after X days).
 * Call from a cron job, not inline.
 */
function notify_faculty_draft_reminder(PDO $pdo, int $days_old = 7): void {
    $stale = $pdo->prepare("
        SELECT s.submission_id, s.course_id, c.course_name, c.term,
               u.email AS faculty_email, u.given_name AS faculty_name
        FROM ieaf_submissions s
        JOIN courses c ON c.course_id = s.course_id
        JOIN users u ON u.user_id = s.submitted_by
        WHERE s.status = 'draft'
          AND s.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stale->execute([$days_old]);

    foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $url  = "https://Server.Name/forms/ieaf_standard.php?id={$row['submission_id']}";
        $body = _email_body("Unfinished IEAF Reminder", "
            <p>Hi {$row['faculty_name']},</p>
            <p>You have an unsubmitted IEA Form draft for <strong>{$row['course_name']}</strong>
            ({$row['term']}) that has been sitting for $days_old+ days.</p>
            <p>Students cannot view their accommodation forms until you submit and ACCESS approves it.</p>
            <p><a class='btn' href='$url'>Complete and Submit</a></p>
        ");
        send_email(
            $row['faculty_email'],
            "[ACCESS] Reminder: Unsubmitted IEAF for {$row['course_name']}",
            $body, $pdo, $row['submission_id'], 'draft_reminder'
        );
    }
}

/**
 * EVENT: Admin weekly digest — outstanding submissions needing action.
 * Run via cron every Monday.
 */
function send_admin_weekly_digest(PDO $pdo): void {
    $pending = $pdo->query("
        SELECT s.submission_id, c.course_name, c.term, s.created_at,
               u.given_name || ' ' || u.surname AS faculty_name, s.form_type
        FROM ieaf_submissions s
        JOIN courses c ON c.course_id = s.course_id
        JOIN users u ON u.user_id = s.submitted_by
        WHERE s.status = 'submitted'
        ORDER BY s.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$pending) return;  // Nothing pending — don't spam admins

    $admins = $pdo->query("SELECT email, given_name FROM users WHERE role='admin' AND active=1")
                  ->fetchAll(PDO::FETCH_ASSOC);

    $rows = '';
    foreach ($pending as $r) {
        $label = $r['form_type'] === 'essential' ? 'Essential' : 'Standard';
        $age   = (new DateTime($r['created_at']))->diff(new DateTime())->days;
        $rows .= "<tr>
            <td>{$r['course_name']} ({$r['term']})</td>
            <td>{$r['faculty_name']}</td>
            <td>$label</td>
            <td>{$age}d ago</td>
        </tr>";
    }

    $count = count($pending);
    $url   = "https://Server.Name/admin/dashboard.php?status=submitted";

    foreach ($admins as $admin) {
        $body = _email_body("Weekly IEAF Digest", "
            <p>Hi {$admin['given_name']},</p>
            <p>There are <strong>$count</strong> IEAF submission(s) waiting for your review.</p>
            <table class='info-table'>
              <thead><tr><th>Course</th><th>Faculty</th><th>Type</th><th>Waiting</th></tr></thead>
              <tbody>$rows</tbody>
            </table>
            <p><a class='btn' href='$url'>Review All Pending</a></p>
        ");
        send_email(
            $admin['email'],
            "[ACCESS] Weekly Digest: $count IEAF(s) Awaiting Review",
            $body, $pdo, null, 'admin_digest'
        );
    }
}

// ── HTML email template ────────────────────────────────────────────────────
function _email_body(string $heading, string $content): string {
    return "
        <div style='font-family:\"Segoe UI\",sans-serif;max-width:560px;margin:0 auto'>
          <div style='background:#CC0033;padding:1rem 1.5rem;border-radius:6px 6px 0 0'>
            <span style='color:white;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase'>
              ACCESS — IEA Forms Portal
            </span>
          </div>
          <div style='background:#1B2A4A;padding:.5rem 1.5rem'>
            <h1 style='color:white;font-size:1.1rem;margin:0;font-weight:700'>$heading</h1>
          </div>
          <div style='background:#ffffff;padding:1.5rem;border:1px solid #e0e0e0;border-top:0'>
            $content
          </div>
          <div style='background:#f5f5f5;padding:.75rem 1.5rem;border:1px solid #e0e0e0;border-top:0;border-radius:0 0 6px 6px;font-size:.75rem;color:#666'>
            Southern Illinois University Edwardsville &bull; ACCESS Department &bull;
            <a href='mailto:myaccess@siue.edu' style='color:#CC0033'>myaccess@siue.edu</a><br>
            This is an automated message from the IEA Forms Portal. Do not reply directly to this email.
          </div>
        </div>
    ";
}

function _wrap_html(string $body, string $subject): string {
    $style = "
        <style>
          .info-table { width:100%; border-collapse:collapse; margin:1rem 0; font-size:.9rem; }
          .info-table th { text-align:left; padding:.4rem .75rem; background:#f0f0f0; color:#333; width:120px; }
          .info-table td { padding:.4rem .75rem; border-bottom:1px solid #eee; }
          .info-table thead th { background:#1B2A4A; color:white; }
          .btn { display:inline-block; background:#CC0033; color:white; padding:.6rem 1.25rem;
                 text-decoration:none; border-radius:4px; font-weight:700; font-size:.9rem; margin:.75rem 0; }
          .note-box { background:#fff8e6; border-left:4px solid #B8993A; padding:.75rem 1rem;
                      margin:1rem 0; border-radius:0 4px 4px 0; font-size:.9rem; }
        </style>
    ";
    return "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>
            <title>$subject</title>$style</head><body>$body</body></html>";
}

// ── Internal helpers ──────────────────────────────────────────────────────
function _load_submission_info(PDO $pdo, int $submission_id): ?array {
    $stmt = $pdo->prepare("
        SELECT s.submission_id, s.course_id, s.form_type, s.created_at,
               c.course_name, c.crn, c.term,
               CONCAT(u.given_name,' ',u.surname) AS faculty_name,
               u.email AS faculty_email
        FROM ieaf_submissions s
        JOIN courses c ON c.course_id = s.course_id
        JOIN users u ON u.user_id = s.submitted_by
        WHERE s.submission_id = ?
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function _log_email(?PDO $pdo, ?int $submission_id, string $recipient, string $subject, string $type, bool $success, string $error = ''): void {
    if (!$pdo) return;
    try {
        $pdo->prepare("
            INSERT INTO email_log (submission_id, recipient, subject, type, success, error_msg)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$submission_id, $recipient, $subject, $type, $success ? 1 : 0, $error ?: null]);
    } catch (Throwable) { /* never fail on logging */ }
}
