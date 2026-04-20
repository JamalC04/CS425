<?php
/**
 * /admin/dashboard.php — WCAG 2.1 AA compliant
 * FIX 2.4.4: every "View", "Approve", "Reject" button carries
 *            aria-label with course name + faculty for uniqueness
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();
$filter = $_GET['status'] ?? '';
$valid  = ['','submitted','approved','rejected','draft'];
$where  = (in_array($filter,$valid,true) && $filter) ? 'WHERE s.status = ?' : '';
$params = $where ? [$filter] : [];

$rows = $pdo->prepare("
    SELECT s.submission_id, s.status, s.form_type, s.created_at,
           c.course_name, c.crn, c.term,
           CONCAT(u.given_name,' ',u.surname) AS faculty_name,
           u.email AS faculty_email
    FROM ieaf_submissions s
    JOIN courses c ON c.course_id=s.course_id
    JOIN users u ON u.user_id=s.submitted_by
    $where
    ORDER BY FIELD(s.status,'submitted','draft','rejected','approved'), s.created_at DESC
");
$rows->execute($params);
$submissions = $rows->fetchAll();

$PAGE_TITLE      = 'All Submissions — Admin';
$PAGE_HEADING    = 'All Submissions';
$PAGE_SUBHEADING = count($submissions) . ' record' . (count($submissions)!==1?'s':'');
$ACTIVE_NAV      = 'dashboard';
require_once __DIR__.'/../includes/header.php';
?>

<!-- Filter controls -->
<div class="actions mb-2" role="group" aria-label="Filter submissions by status">
  <?php foreach ([''=>'All','submitted'=>'Submitted','approved'=>'Approved','rejected'=>'Rejected','draft'=>'Draft'] as $val => $label): ?>
    <a href="?status=<?= $val ?>"
       class="btn btn--sm <?= $filter===$val ? 'btn--secondary' : 'btn--ghost' ?>"
       <?= $filter===$val ? 'aria-current="true"' : '' ?>
       aria-label="Show <?= $label ?> submissions">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-wrap">
    <table aria-label="IEAF submissions">
      <thead>
        <tr>
          <th scope="col">ID</th>
          <th scope="col">Status</th>
          <th scope="col">Type</th>
          <th scope="col">Course</th>
          <th scope="col">Faculty</th>
          <th scope="col">Term</th>
          <th scope="col">Submitted</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($submissions as $r):
        $ctx = htmlspecialchars($r['course_name'] . ' — ' . $r['faculty_name']);
        $view_url = '/forms/ieaf_' . $r['form_type'] . '.php?id=' . $r['submission_id'];
      ?>
        <tr>
          <td><?= $r['submission_id'] ?></td>
          <td><span class="status status--<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
          <td>
            <span style="font-size:.85rem;color:var(--gray-600)">
              <?= $r['form_type']==='essential' ? 'Essential Abilities' : 'Standard' ?>
            </span>
          </td>
          <td>
            <strong><?= htmlspecialchars($r['course_name']) ?></strong><br>
            <small style="color:var(--gray-600)"><?= htmlspecialchars($r['crn']) ?></small>
          </td>
          <td>
            <?= htmlspecialchars($r['faculty_name']) ?><br>
            <small style="color:var(--gray-600)"><?= htmlspecialchars($r['faculty_email']) ?></small>
          </td>
          <td><?= htmlspecialchars($r['term']) ?></td>
          <td style="white-space:nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
          <td>
            <div class="actions">
              <!-- FIX 2.4.4: aria-label includes unique course context -->
              <a href="<?= $view_url ?>"
                 class="btn btn--ghost btn--sm"
                 aria-label="View IEAF for <?= $ctx ?>">View</a>

              <?php if ($r['status']==='submitted'): ?>
                <a href="/admin/approve.php?id=<?= $r['submission_id'] ?>&action=approve"
                   class="btn btn--secondary btn--sm"
                   aria-label="Approve IEAF for <?= $ctx ?>"
                   data-confirm="Approve this submission and notify enrolled students?">
                   Approve
                </a>
                <a href="/admin/approve.php?id=<?= $r['submission_id'] ?>&action=reject"
                   class="btn btn--danger btn--sm"
                   aria-label="Reject IEAF for <?= $ctx ?>">
                   Reject
                </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($submissions)): ?>
        <tr>
          <td colspan="8" style="text-align:center;color:var(--gray-600);padding:2rem">
            No submissions found.
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
