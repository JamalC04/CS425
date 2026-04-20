<?php
/**
 * /forms/ieaf_standard.php — Standard IEAF (WCAG 2.1 AA compliant)
 * Key WCAG fixes applied:
 *   1.3.1 — aria-required="true" on required inputs; sr-only text for required asterisk
 *   1.3.5 — autocomplete attributes on personal data fields
 *   2.4.4 — unique aria-label on action buttons (includes course name)
 *   4.1.2 — error states use aria-describedby linking to error message
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('faculty', 'admin');

$pdo = db();
$submission = null;
$data = [];
$course = null;
$readonly = false;

if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.course_name AS c_name, c.crn, c.term
        FROM ieaf_submissions s JOIN courses c ON c.course_id=s.course_id
        WHERE s.submission_id=? AND s.form_type='standard'
    ");
    $stmt->execute([$_GET['id']]);
    $submission = $stmt->fetch();
    if (!$submission) { http_response_code(404); die('Not found.'); }
    if ($CURRENT_USER['role']==='faculty') {
        if ($submission['submitted_by'] != $CURRENT_USER['user_id']) { http_response_code(403); die('Access denied.'); }
        $readonly = in_array($submission['status'], ['submitted','approved']);
    }
    $d = $pdo->prepare('SELECT * FROM ieaf_standard WHERE submission_id=?');
    $d->execute([$submission['submission_id']]);
    $data = $d->fetch() ?: [];
} elseif (!empty($_GET['course_id'])) {
    $cs = $pdo->prepare('SELECT * FROM courses WHERE course_id=?');
    $cs->execute([$_GET['course_id']]);
    $course = $cs->fetch();
}

$f  = fn(string $k, $def='') => htmlspecialchars((string)($data[$k] ?? $def));
$ck = fn(string $k) => !empty($data[$k]) ? 'checked' : '';

$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && !$readonly) {
    $action = $_POST['_action'] ?? 'save';
    $cid    = (int)($_POST['course_id'] ?? 0);
    $sid    = (int)($_POST['submission_id'] ?? 0);
    if ($action==='submit') {
        if (empty(trim($_POST['instructor_name']??''))) $errors['instructor_name'] = 'Instructor name is required.';
        if (empty(trim($_POST['course_name']??'')))     $errors['course_name']     = 'Course name is required.';
    }
    if (empty($errors)) {
        try {
            $status = $action==='submit' ? 'submitted' : 'draft';
            if ($sid) {
                $pdo->prepare("UPDATE ieaf_submissions SET status=?,updated_at=NOW() WHERE submission_id=?")
                    ->execute([$status,$sid]);
            } else {
                $pdo->prepare("INSERT INTO ieaf_submissions (course_id,submitted_by,form_type,status) VALUES (?,?,?,?)")
                    ->execute([$cid?:($course['course_id']??0), $CURRENT_USER['user_id'],'standard',$status]);
                $sid = (int)$pdo->lastInsertId();
            }
            $row = [
                'instructor_name'        => trim($_POST['instructor_name']??''),
                'instructor_email'       => trim($_POST['instructor_email']??''),
                'course_name'            => trim($_POST['course_name']??''),
                'previously_filled'      => isset($_POST['previously_filled'])?1:0,
                'has_updates'            => isset($_POST['has_updates'])?1:0,
                'agreement_yes'          => isset($_POST['agreement_yes'])?1:0,
                'makeup_activities'      => trim($_POST['makeup_activities']??''),
                'cannot_miss_activities' => trim($_POST['cannot_miss_activities']??''),
            ];
            $cols = implode(',', array_keys($row));
            $ph   = implode(',', array_fill(0,count($row),'?'));
            $ups  = implode(',', array_map(fn($k)=>"$k=VALUES($k)", array_keys($row)));
            $pdo->prepare("INSERT INTO ieaf_standard (submission_id,$cols) VALUES (?,$ph) ON DUPLICATE KEY UPDATE $ups")
                ->execute(array_merge([$sid], array_values($row)));
            $pdo->prepare("INSERT INTO submission_history (submission_id,changed_by,action,snapshot) VALUES (?,?,?,?)")
                ->execute([$sid,$CURRENT_USER['user_id'],$action==='submit'?'submitted':'edited',json_encode($row)]);
            if ($action==='submit') {
                require_once __DIR__.'/../includes/mailer.php';
                notify_admins_new_submission($pdo,$sid);
                notify_faculty_submission_confirm($pdo,$sid);
            }
            $_SESSION['flash_success'] = $action==='submit' ? 'Form submitted to ACCESS for review.' : 'Draft saved.';
            header('Location: /forms/ieaf_standard.php?id='.$sid); exit;
        } catch (Throwable $e) {
            error_log('IEAF std: '.$e->getMessage());
            $errors['_db'] = 'A database error occurred. Please try again.';
        }
    }
}

$course_label = $course['course_name'] ?? ($data['course_name'] ?? 'this course');
$PAGE_TITLE = 'Standard IEAF — ' . $course_label;
$PAGE_HEADING = null;
$ACTIVE_NAV = 'new_std';
require_once __DIR__.'/../includes/header.php';
?>

<?php if (!empty($errors['_db'])): ?>
  <div class="alert alert--error" role="alert"><?= htmlspecialchars($errors['_db']) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div class="alert alert--error" role="alert" id="form-errors">
    <strong>Please correct the following before submitting:</strong>
    <ul style="margin:.5rem 0 0 1.25rem">
      <?php foreach ($errors as $k => $msg): if($k==='_db') continue; ?>
        <li><a href="#<?= $k ?>"><?= htmlspecialchars($msg) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($submission && in_array($submission['status'],['submitted','approved']) && $CURRENT_USER['role']!=='admin'): ?>
  <div class="alert alert--info" role="status">
    This form is <strong><?= ucfirst($submission['status']) ?></strong> — read only.
    <a href="/forms/history.php?id=<?= $submission['submission_id'] ?>">View edit history</a>
  </div>
<?php endif; ?>
<?php if ($submission && $submission['status']==='rejected'): ?>
  <div class="alert alert--error" role="alert">
    <strong>Changes requested by ACCESS:</strong><br>
    <?= htmlspecialchars($submission['rejection_note']??'') ?>
  </div>
<?php endif; ?>

<form method="post" action="/forms/ieaf_standard.php" novalidate
      aria-label="Intermittent/Extended Absence Accommodation form">
  <input type="hidden" name="submission_id" value="<?= $submission['submission_id'] ?? '' ?>">
  <input type="hidden" name="course_id" value="<?= htmlspecialchars($_GET['course_id'] ?? ($submission['course_id'] ?? '')) ?>">

  <div class="pdf-form-header">
    <p class="pdf-form-header__dept">Accessible Campus Community &amp; Equitable Student Support</p>
    <h1 class="pdf-form-header__title">Intermittent/Extended Absence Accommodation</h1>
    <div class="pdf-form-header__rule" role="presentation"></div>
  </div>

  <!-- Header fields -->
  <div class="form-section">
    <h2 class="form-section__heading" id="sec-instructor">Instructor &amp; Course</h2>
    <div class="form-section__body">
      <div class="form-row">
        <div class="form-group">
          <!-- FIX 1.3.1 + 1.3.5: aria-required + autocomplete on personal fields -->
          <label class="form-label" for="instructor_name">
            Instructor Name
            <span class="required" aria-hidden="true">*</span>
            <span class="sr-only">(required)</span>
          </label>
          <input class="form-control <?= isset($errors['instructor_name']) ? 'is-invalid' : '' ?>"
                 type="text" id="instructor_name" name="instructor_name"
                 value="<?= $f('instructor_name', $CURRENT_USER['given_name'].' '.$CURRENT_USER['surname']) ?>"
                 autocomplete="name"
                 aria-required="true"
                 <?= isset($errors['instructor_name']) ? 'aria-describedby="instructor_name-error"' : '' ?>
                 <?= $readonly ? 'readonly' : '' ?> required>
          <?php if (isset($errors['instructor_name'])): ?>
            <p class="form-error" id="instructor_name-error" role="alert">
              <?= htmlspecialchars($errors['instructor_name']) ?>
            </p>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label" for="instructor_email">eMail</label>
          <input class="form-control" type="email" id="instructor_email" name="instructor_email"
                 value="<?= $f('instructor_email', $CURRENT_USER['email']) ?>"
                 autocomplete="email"
                 <?= $readonly ? 'readonly' : '' ?>>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="course_name">
          Course
          <span class="required" aria-hidden="true">*</span>
          <span class="sr-only">(required)</span>
        </label>
        <input class="form-control <?= isset($errors['course_name']) ? 'is-invalid' : '' ?>"
               type="text" id="course_name" name="course_name"
               value="<?= $f('course_name', $course['course_name'] ?? '') ?>"
               aria-required="true"
               <?= isset($errors['course_name']) ? 'aria-describedby="course_name-error"' : '' ?>
               <?= $readonly ? 'readonly' : '' ?> required>
        <?php if (isset($errors['course_name'])): ?>
          <p class="form-error" id="course_name-error" role="alert">
            <?= htmlspecialchars($errors['course_name']) ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Checkboxes + description -->
  <div class="form-section">
    <h2 class="form-section__heading" id="sec-previous">Previous Form Status</h2>
    <div class="form-section__body">
      <!-- FIX 1.3.1: group related checkboxes in fieldset -->
      <fieldset style="border:0;padding:0;margin:0 0 1rem 0">
        <legend class="sr-only">Previous form status</legend>
        <div class="inline-checks">
          <label class="inline-check">
            <input type="checkbox" name="previously_filled" value="1"
                   <?= $ck('previously_filled') ?> <?= $readonly?'disabled':'' ?>>
            <span>Have you previously filled out this form for this course?</span>
          </label>
          <label class="inline-check">
            <input type="checkbox" name="has_updates" value="1"
                   <?= $ck('has_updates') ?> <?= $readonly?'disabled':'' ?>>
            <span>Are there updates to a previous form?</span>
          </label>
        </div>
      </fieldset>
      <div class="form-description" role="note">
        <p>Intermittent/Extended Absence Accommodation is designed to provide equal access to
        students in situations where the diagnosis of a documented disability could potentially
        result in consecutive or reoccurring absences. Please note, exceptions to attendance
        policies will be determined on an individual, case by case basis. For maximum efficacy
        in implementing this accommodation, as the instructor for the course, please help us
        in identifying the following:</p>
      </div>
    </div>
  </div>

  <!-- Agreement -->
  <div class="form-section">
    <h2 class="form-section__heading" id="sec-agreement">Agreement</h2>
    <div class="form-section__body">
      <div class="form-group">
        <p class="form-field-label" id="agreement-label">
          Are you in agreement with the intermittent/extended absence accommodation without
          concern for potential fundamental alteration of the curriculum?
        </p>
        <label class="inline-check" style="margin-top:.5rem">
          <input type="checkbox" name="agreement_yes" value="1"
                 <?= $ck('agreement_yes') ?> <?= $readonly?'disabled':'' ?>
                 aria-labelledby="agreement-label">
          <span><strong>Yes</strong> — Please provide information below and return.</span>
        </label>
        <p class="form-hint" style="margin-top:.75rem">
          If <strong>No</strong>, please complete the
          <a href="/forms/ieaf_essential.php?course_id=<?= htmlspecialchars($_GET['course_id'] ?? ($submission['course_id'] ?? '')) ?>">
            Essential Abilities IEAF form
          </a>
          and return it to <a href="mailto:myaccess@siue.edu">myaccess@siue.edu</a>.
        </p>
      </div>
    </div>
  </div>

  <!-- Makeup activities -->
  <div class="form-section">
    <h2 class="form-section__heading" id="sec-makeup">Make-up Activities</h2>
    <div class="form-section__body">
      <div class="form-group">
        <label class="form-label" for="makeup_activities">
          Please list all activities the student is permitted to make up and how long they will
          have to submit the assignment/activity after an absence, either extended or intermittent.
        </label>
        <textarea class="form-control" id="makeup_activities" name="makeup_activities"
                  rows="7" <?= $readonly ? 'readonly' : '' ?>
                  aria-describedby="makeup-hint"><?= $f('makeup_activities') ?></textarea>
        <p class="form-hint" id="makeup-hint">
          Include specific timeframes (e.g., "within 5 business days of return").
        </p>
      </div>
    </div>
  </div>

  <!-- Cannot miss -->
  <div class="form-section">
    <h2 class="form-section__heading" id="sec-cannot-miss">Activities Students Cannot Miss</h2>
    <div class="form-section__body">
      <div class="form-group">
        <label class="form-label" for="cannot_miss_activities">
          Which activities are the student unable to miss or make up due to the nature of the
          activity as it relates to the essential requirements of this course?
          If the answer is none, please indicate so below.
        </label>
        <textarea class="form-control" id="cannot_miss_activities" name="cannot_miss_activities"
                  rows="7" <?= $readonly ? 'readonly' : '' ?>><?= $f('cannot_miss_activities') ?></textarea>
      </div>
    </div>
  </div>

  <div class="pdf-footer-notice" role="note">
    <em>If at any point in the semester the frequency or duration of absences becomes so significant
    that it appears a student may not be able to demonstrate competency or complete required course
    work by the end of the semester, and an incomplete is not an option, please contact ACCESS at
    <a href="mailto:myaccess@siue.edu">myaccess@siue.edu</a> to discuss remaining options.</em>
  </div>

  <?php
  $course_context = htmlspecialchars($course_label);
  ?>
  <?php if (!$readonly): ?>
  <div class="actions mt-3">
    <button type="submit" name="_action" value="save" class="btn btn--ghost">
      Save Draft
    </button>
    <button type="submit" name="_action" value="submit" class="btn btn--primary">
      Submit to ACCESS
    </button>
    <?php if ($submission): ?>
      <!-- FIX 2.4.4: unique link label includes submission context -->
      <a href="/forms/history.php?id=<?= $submission['submission_id'] ?>"
         class="btn btn--ghost btn--sm" style="margin-left:auto"
         aria-label="View edit history for <?= $course_context ?>">
        View History
      </a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="actions mt-3">
    <button type="button" onclick="window.print()" class="btn btn--ghost">
      Print Form
    </button>
    <?php if ($submission): ?>
      <a href="/forms/history.php?id=<?= $submission['submission_id'] ?>"
         class="btn btn--ghost btn--sm"
         aria-label="View edit history for <?= $course_context ?>">
        View History
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</form>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
