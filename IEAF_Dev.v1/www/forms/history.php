<?php
/**
 * /forms/history.php — Audit history for a submission
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$sub = $pdo->prepare('SELECT s.*, c.course_name FROM ieaf_submissions s JOIN courses c ON c.course_id=s.course_id WHERE s.submission_id=?');
$sub->execute([$id]);
$submission = $sub->fetch();
if (!$submission) { http_response_code(404); die('Not found.'); }

// Students can only see approved form histories for their enrolled courses
if ($CURRENT_USER['role'] === 'student') {
    $chk = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id=? AND course_id=?');
    $chk->execute([$CURRENT_USER['user_id'], $submission['course_id']]);
    if (!$chk->fetchColumn()) { http_response_code(403); die('Access denied.'); }
}

$hist = $pdo->prepare('
    SELECT h.*, CONCAT(u.given_name," ",u.surname) AS changed_by_name
    FROM submission_history h
    JOIN users u ON u.user_id = h.changed_by
    WHERE h.submission_id = ?
    ORDER BY h.created_at DESC
');
$hist->execute([$id]);
$history = $hist->fetchAll();

$PAGE_TITLE   = 'Form History';
$PAGE_HEADING = 'Submission History';
$PAGE_SUBHEADING = htmlspecialchars($submission['course_name']);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="table-wrap">
    <table aria-label="Submission history log">
      <thead>
        <tr>
          <th scope="col">Date / Time</th>
          <th scope="col">Action</th>
          <th scope="col">By</th>
          <th scope="col">Note</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= date('M j, Y g:ia', strtotime($h['created_at'])) ?></td>
          <td><span class="status status--<?= $h['action'] === 'approved' ? 'approved' : ($h['action'] === 'rejected' ? 'rejected' : 'submitted') ?>"><?= ucfirst($h['action']) ?></span></td>
          <td><?= htmlspecialchars($h['changed_by_name']) ?></td>
          <td><?= htmlspecialchars($h['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="actions mt-2">
  <a href="/forms/ieaf.php?id=<?= $id ?>" class="btn btn--ghost btn--sm">← Back to Form</a>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
