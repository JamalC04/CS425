<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('faculty', 'admin');

$pdo = db();

// Get courses taught by this faculty member
$stmt = $pdo->prepare("
    SELECT
        c.course_id, c.course_name, c.crn, c.section, c.term,
        -- Standard IEAF
        s_std.submission_id  AS std_id,
        s_std.status         AS std_status,
        s_std.updated_at     AS std_updated,
        -- Essential IEAF
        s_ess.submission_id  AS ess_id,
        s_ess.status         AS ess_status,
        s_ess.updated_at     AS ess_updated
    FROM courses c
    LEFT JOIN ieaf_submissions s_std
        ON s_std.course_id = c.course_id AND s_std.form_type = 'standard'
        AND s_std.submitted_by = ?
    LEFT JOIN ieaf_submissions s_ess
        ON s_ess.course_id = c.course_id AND s_ess.form_type = 'essential'
        AND s_ess.submitted_by = ?
    WHERE c.faculty_id = ? AND c.active = 1
    ORDER BY c.term DESC, c.course_name ASC
");
$stmt->execute([
    $CURRENT_USER['user_id'],
    $CURRENT_USER['user_id'],
    $CURRENT_USER['user_id'],
]);
$courses = $stmt->fetchAll();

$PAGE_TITLE      = 'My Courses — ACCESS Portal';
$PAGE_HEADING    = 'My Courses';
$PAGE_SUBHEADING = 'Start or continue an IEAF for each of your courses';
$ACTIVE_NAV      = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($courses)): ?>
  <div class="alert alert--info">
    No courses found. If your courses are missing, please contact ACCESS — your roster may not have
    been imported yet.
  </div>
<?php else: ?>

<div class="alert alert--info" role="note">
  <strong>Two form types:</strong>
  The <strong>Standard IEAF</strong> is for courses where you agree with the accommodation without
  concern. The <strong>Essential Abilities IEAF</strong> is for courses where you have concerns about
  fundamental alteration of the curriculum. You may file both for the same course.
</div>

<div class="card">
  <div class="table-wrap">
    <table aria-label="Your courses and IEAF submission status">
      <thead>
        <tr>
          <th scope="col">Course</th>
          <th scope="col">CRN / Section</th>
          <th scope="col">Term</th>
          <th scope="col">Standard IEAF</th>
          <th scope="col">Essential Abilities IEAF</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($courses as $row): ?>
        <tr>
          <td><strong><?= htmlspecialchars($row['course_name']) ?></strong></td>
          <td>
            <?= htmlspecialchars($row['crn']) ?>
            <?php if ($row['section']): ?>
              <small style="color:var(--gray-500)"> / §<?= htmlspecialchars($row['section']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($row['term']) ?></td>

          <!-- Standard IEAF column -->
          <td>
            <?php if ($row['std_id']): ?>
              <span class="status status--<?= $row['std_status'] ?>"><?= ucfirst($row['std_status']) ?></span><br>
              <small style="color:var(--gray-500)">
                <?= date('M j, Y', strtotime($row['std_updated'])) ?>
              </small><br>
              <a href="/forms/ieaf_standard.php?id=<?= $row['std_id'] ?>" class="btn btn--ghost btn--sm mt-1"
                aria-label="<?= in_array($row['std_status'], ['submitted','approved']) ? 'View' : 'Edit' ?> Standard IEAF for <?= htmlspecialchars($row['course_name']) ?>">
                <?= in_array($row['std_status'], ['submitted','approved']) ? 'View' : 'Edit Draft' ?>
              </a>
            <?php else: ?>
              <a href="/forms/ieaf_standard.php?course_id=<?= $row['course_id'] ?>"
                 class="btn btn--ghost btn--sm"
                 aria-label="Start Standard IEAF for <?= htmlspecialchars($row['course_name']) ?>">Start Form</a>
            <?php endif; ?>
          </td>

          <!-- Essential Abilities IEAF column -->
          <td>
            <?php if ($row['ess_id']): ?>
              <span class="status status--<?= $row['ess_status'] ?>"><?= ucfirst($row['ess_status']) ?></span><br>
              <small style="color:var(--gray-500)">
                <?= date('M j, Y', strtotime($row['ess_updated'])) ?>
              </small><br>
              <a href="/forms/ieaf_essential.php?id=<?= $row['ess_id'] ?>" class="btn btn--ghost btn--sm mt-1"
                aria-label="<?= in_array($row['ess_status'], ['submitted','approved']) ? 'View' : 'Edit' ?> Essential Abilities IEAF for <?= htmlspecialchars($row['course_name']) ?>">
                <?= in_array($row['ess_status'], ['submitted','approved']) ? 'View' : 'Edit Draft' ?>
              </a>
            <?php else: ?>
              <a href="/forms/ieaf_essential.php?course_id=<?= $row['course_id'] ?>"
                 class="btn btn--ghost btn--sm"
                 aria-label="Start Essential Abilities IEAF for <?= htmlspecialchars($row['course_name']) ?>">Start Form</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
