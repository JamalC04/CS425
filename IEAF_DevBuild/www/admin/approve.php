<?php
/**
 * /admin/approve.php
 * Handles approve and reject actions from the admin dashboard.
 * For reject: shows a form to enter rejection notes before finalizing.
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();
$id     = (int)($_GET['id'] ?? $_POST['submission_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$id || !in_array($action, ['approve','reject'], true)) {
    header('Location: /admin/dashboard.php');
    exit;
}

// Load submission
$stmt = $pdo->prepare('
    SELECT s.*, c.course_name, c.term,
           CONCAT(u.given_name," ",u.surname) AS faculty_name, u.email AS faculty_email
    FROM ieaf_submissions s
    JOIN courses c ON c.course_id = s.course_id
    JOIN users u ON u.user_id = s.submitted_by
    WHERE s.submission_id = ? AND s.status = "submitted"
');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub) {
    $_SESSION['flash_error'] = 'Submission not found or already processed.';
    header('Location: /admin/dashboard.php');
    exit;
}

// ── Handle rejection form POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reject') {
    $note = trim($_POST['rejection_note'] ?? '');
    if (empty($note)) {
        $show_reject_form = true;
        $error = 'Please provide a note explaining what changes are needed.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                UPDATE ieaf_submissions
                SET status="rejected", rejection_note=?, approved_by=?, approved_at=NOW()
                WHERE submission_id=?
            ')->execute([$note, $CURRENT_USER['user_id'], $id]);

            $pdo->prepare('INSERT INTO submission_history (submission_id,changed_by,action,note) VALUES (?,?,?,?)')
                ->execute([$id, $CURRENT_USER['user_id'], 'rejected', $note]);

            $pdo->commit();

            require_once __DIR__ . '/../includes/mailer.php';
            notify_faculty_rejected($pdo, $id, $note);

            $_SESSION['flash_success'] = 'Form returned to faculty with comments.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Reject error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Error processing rejection.';
        }
        header('Location: /admin/dashboard.php');
        exit;
    }
}

// ── Handle approve ────────────────────────────────────────────────────────
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            UPDATE ieaf_submissions
            SET status="approved", approved_by=?, approved_at=NOW(), rejection_note=NULL
            WHERE submission_id=?
        ')->execute([$CURRENT_USER['user_id'], $id]);

        $pdo->prepare('INSERT INTO submission_history (submission_id,changed_by,action) VALUES (?,?,?)')
            ->execute([$id, $CURRENT_USER['user_id'], 'approved']);

        $pdo->commit();

        require_once __DIR__ . '/../includes/mailer.php';
        notify_students_approved($pdo, $id);

        $_SESSION['flash_success'] = 'Form approved. Enrolled students have been notified.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Approve error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Error approving submission.';
    }
    header('Location: /admin/dashboard.php');
    exit;
}

// ── Show confirmation / rejection note form ───────────────────────────────
$PAGE_TITLE   = $action === 'approve' ? 'Approve Submission' : 'Reject Submission';
$PAGE_HEADING = $action === 'approve' ? 'Approve Form' : 'Return Form with Comments';
$ACTIVE_NAV   = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:640px">
  <div class="card__header">
    <?= $action === 'approve' ? 'Confirm Approval' : 'Rejection / Revision Request' ?>
  </div>
  <div class="card__body">
    <p>
      <strong>Course:</strong> <?= htmlspecialchars($sub['course_name']) ?> (<?= htmlspecialchars($sub['term']) ?>)<br>
      <strong>Faculty:</strong> <?= htmlspecialchars($sub['faculty_name']) ?>
      &lt;<?= htmlspecialchars($sub['faculty_email']) ?>&gt;<br>
      <strong>Form type:</strong> <?= $sub['form_type'] === 'essential' ? 'Essential Abilities IEAF' : 'Standard IEAF' ?><br>
      <strong>Submitted:</strong> <?= date('M j, Y g:ia', strtotime($sub['created_at'])) ?>
    </p>

    <?php if (!empty($error)): ?>
      <div class="alert alert--error mt-2" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/approve.php" style="margin-top:1.25rem">
      <input type="hidden" name="submission_id" value="<?= $id ?>">
      <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">

      <?php if ($action === 'reject'): ?>
        <div class="form-group">
          <label class="form-label" for="rejection_note">
            Comments for faculty <span class="required" aria-hidden="true">*</span><span class="sr-only">(required)</span>
          </label>
          <textarea class="form-control" id="rejection_note" name="rejection_note"
                    rows="6" required aria-required="true" aria-describedby="rejection-note-hint"
                    placeholder="Explain what needs to be changed or clarified before this form can be approved..."><?= htmlspecialchars($_POST['rejection_note'] ?? '') ?></textarea>
          <p class="form-hint" id="rejection-note-hint">
            This message will be emailed to the faculty member and displayed on their form.
          </p>
        </div>
      <?php else: ?>
        <p class="alert alert--success">
          Approving this form will notify all enrolled students in this course by email.
        </p>
      <?php endif; ?>

      <div class="actions mt-2">
        <?php if ($action === 'approve'): ?>
          <button type="submit" class="btn btn--secondary">Confirm Approval &amp; Notify Students</button>
        <?php else: ?>
          <button type="submit" class="btn btn--primary"
                  style="background:var(--error);border-color:var(--error)">
            Return to Faculty
          </button>
        <?php endif; ?>
        <a href="/admin/dashboard.php" class="btn btn--ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
