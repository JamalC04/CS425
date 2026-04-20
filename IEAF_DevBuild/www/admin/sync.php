<?php
/**
 * /admin/sync.php — Admin panel for Banner sync and email log
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();

// Manual sync trigger
$sync_output = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_sync'])) {
    $term = preg_replace('/[^0-9]/', '', $_POST['term_code'] ?? '');
    if ($term && strlen($term) === 6) {
        $db_flag = '--db prod';
        $cmd = "/usr/bin/python3 /proj/scripts/sync_banner.py --term " . escapeshellarg($term) . " $db_flag 2>&1";
        $sync_output = shell_exec($cmd) ?? 'No output returned.';
        $_SESSION['flash_success'] = "Banner sync triggered for term $term.";
    } else {
        $_SESSION['flash_error'] = 'Invalid term code. Use 6-digit format like 202530.';
    }
}

// Email log
$email_log = $pdo->query("
    SELECT el.*, s.form_type,
           c.course_name
    FROM email_log el
    LEFT JOIN ieaf_submissions s ON s.submission_id = el.submission_id
    LEFT JOIN courses c ON c.course_id = s.course_id
    ORDER BY el.sent_at DESC
    LIMIT 100
")->fetchAll();

// Quick sync status — last log entry
$last_sync = null;
if (file_exists('/proj/logs/sync_banner.log')) {
    $lines = array_filter(array_map('trim', file('/proj/logs/sync_banner.log')));
    $last_sync = end($lines);
}

// Pending draft count
$pending_drafts = $pdo->query("
    SELECT COUNT(*) FROM ieaf_submissions WHERE status='draft'
    AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetchColumn();

$PAGE_TITLE   = 'Sync & Email Log — Admin';
$PAGE_HEADING = 'Banner Sync & Email Log';
$ACTIVE_NAV   = 'sync';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Stats row ──────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.75rem">

  <div class="card">
    <div class="card__body" style="text-align:center">
      <div style="font-size:2rem;font-weight:700;color:var(--siue-navy)"><?= count($email_log) ?></div>
      <div style="color:var(--gray-500);font-size:.85rem">Emails logged (last 100)</div>
    </div>
  </div>

  <div class="card">
    <div class="card__body" style="text-align:center">
      <div style="font-size:2rem;font-weight:700;color:<?= $pending_drafts > 0 ? 'var(--siue-red)' : 'var(--success)' ?>">
        <?= $pending_drafts ?>
      </div>
      <div style="color:var(--gray-500);font-size:.85rem">Stale drafts (&gt;7 days)</div>
    </div>
  </div>

  <div class="card">
    <div class="card__body">
      <div style="font-size:.8rem;font-weight:700;color:var(--gray-500);margin-bottom:.25rem">Last Banner sync</div>
      <div style="font-size:.85rem;color:var(--gray-700);font-family:monospace;word-break:break-all">
        <?= $last_sync ? htmlspecialchars(substr($last_sync, 0, 120)) : 'No log found' ?>
      </div>
    </div>
  </div>

</div>

<!-- ── Manual Banner Sync ─────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card__header">Manual Banner Course Sync</div>
  <div class="card__body">
    <p style="color:var(--gray-500);font-size:.9rem;margin-bottom:1rem">
      Syncs courses and instructors from SIUE Banner SSB for the given term.
      This runs automatically via cron on Jan 1, May 1, and Aug 1.
      Term code format: <code>YYYYTT</code> where TT = 10 (Fall), 20 (Spring), 30 (Summer).<br>
      Examples: <code>202520</code> = Spring 2025 &nbsp;|&nbsp; <code>202510</code> = Fall 2025
    </p>
    <form method="post" action="/admin/sync.php" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="term_code">Term Code</label>
        <input class="form-control" type="text" id="term_code" name="term_code"
               placeholder="e.g. 202530" maxlength="6" pattern="[0-9]{6}"
               style="width:160px" required>
      </div>
      <button type="submit" name="run_sync" value="1" class="btn btn--secondary"
              onclick="return confirm('Run Banner sync now? This may take a minute.')">
        Run Sync Now
      </button>
    </form>

    <?php if ($sync_output): ?>
    <pre style="margin-top:1rem;background:var(--gray-900);color:#7ec87e;padding:1rem;
                border-radius:var(--radius);font-size:.8rem;overflow-x:auto;max-height:300px"><?= htmlspecialchars($sync_output) ?></pre>
    <?php endif; ?>
  </div>
  <div class="card__footer" style="font-size:.8rem;color:var(--gray-500)">
    <strong>Cron schedule</strong> (set in <code>crontab -e</code>):<br>
    <code>0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py &gt;&gt; /proj/logs/sync_banner.log 2&gt;&amp;1</code>
  </div>
</div>

<!-- ── Email Log ──────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card__header">Email Log (last 100)</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Sent</th>
          <th>Type</th>
          <th>Recipient</th>
          <th>Subject</th>
          <th>Course</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($email_log as $r): ?>
        <tr>
          <td style="white-space:nowrap;font-size:.8rem"><?= date('M j g:ia', strtotime($r['sent_at'])) ?></td>
          <td>
            <span style="font-size:.75rem;background:var(--gray-100);padding:.2em .5em;border-radius:2px">
              <?= htmlspecialchars($r['type']) ?>
            </span>
          </td>
          <td style="font-size:.85rem"><?= htmlspecialchars($r['recipient']) ?></td>
          <td style="font-size:.85rem"><?= htmlspecialchars($r['subject']) ?></td>
          <td style="font-size:.85rem;color:var(--gray-500)"><?= htmlspecialchars($r['course_name'] ?? '—') ?></td>
          <td>
            <?php if ($r['success']): ?>
              <span class="status status--approved">Sent</span>
            <?php else: ?>
              <span class="status status--rejected" title="<?= htmlspecialchars($r['error_msg'] ?? '') ?>">
                Failed
              </span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($email_log)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--gray-500);padding:2rem">No emails sent yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
