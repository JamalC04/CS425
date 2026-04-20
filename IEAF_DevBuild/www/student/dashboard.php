<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';

// Students only
if (!$CURRENT_USER) require_login();
require_role('student');

$pdo = db();

// Fetch all courses the student is enrolled in, plus any approved IEAFs
$stmt = $pdo->prepare("
    SELECT
        c.course_name,
        c.crn,
        c.section,
        c.term,
        CONCAT(u.given_name, ' ', u.surname) AS instructor,
        s.submission_id,
        s.status,
        s.updated_at AS form_updated
    FROM enrollments e
    JOIN courses c ON c.course_id = e.course_id
    LEFT JOIN users u ON u.user_id = c.faculty_id
    LEFT JOIN ieaf_submissions s ON s.course_id = c.course_id AND s.status = 'approved'
    WHERE e.student_id = ?
    ORDER BY c.term DESC, c.course_name ASC
");
$stmt->execute([$CURRENT_USER['user_id']]);
$courses = $stmt->fetchAll();

$PAGE_TITLE   = 'My Forms — ACCESS Portal';
$PAGE_HEADING = 'My IEA Forms';
$PAGE_SUBHEADING = 'Forms available for your current courses';
$ACTIVE_NAV   = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($courses)): ?>
  <div class="alert alert--info">
    No enrolled courses found for this term. If this looks wrong, please contact ACCESS.
  </div>
<?php else: ?>
<div class="card">
  <div class="card__header">Your Courses &amp; Forms</div>
  <div class="table-wrap">
    <table aria-label="Enrolled courses and available IEA forms">
      <thead>
        <tr>
          <th scope="col">Course</th>
          <th scope="col">Instructor</th>
          <th scope="col">Term</th>
          <th scope="col">IEAF</th>
          <th scope="col">Status</th>
          <th scope="col">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($courses as $row): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($row['course_name']) ?></strong><br>
            <small style="color:var(--gray-500)"><?= htmlspecialchars($row['crn']) ?></small>
          </td>
          <td><?= htmlspecialchars($row['instructor'] ?? 'TBD') ?></td>
          <td><?= htmlspecialchars($row['term']) ?></td>
          <td>
            <?php if ($row['submission_id']): ?>
              IEAF
            <?php else: ?>
              <span style="color:var(--gray-500)">Not submitted</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['status']): ?>
              <span class="status status--<?= $row['status'] ?>">
                <?= ucfirst($row['status']) ?>
              </span>
            <?php else: ?>
              <span class="status status--draft">Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['submission_id']): ?>
              <a href="/forms/view.php?id=<?= $row['submission_id'] ?>"
                 class="btn btn--ghost btn--sm"
                 aria-label="View and print IEA form for <?= htmlspecialchars($row['course_name']) ?>">
                 View &amp; Print</a>
            <?php else: ?>
              <span class="visually-hidden">No form available</span>—
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
