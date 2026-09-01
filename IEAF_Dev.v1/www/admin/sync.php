<?php
/**
 * admin/sync.php — Manual Banner Course Sync
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();
$sync_output = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_sync'])) {
    $term = preg_replace('/[^0-9]/', '', $_POST['term_code'] ?? '');
    if ($term && strlen($term) === 6) {
        $db_name = preg_replace('/[^a-z0-9_]/', '', $_ENV['DB_NAME'] ?? 'dev');
        $cmd = escapeshellcmd("/usr/bin/python3 /proj/scripts/sync_banner.py")
             . " --term " . escapeshellarg($term)
             . " --db "   . escapeshellarg($db_name)
             . " 2>&1";
        $sync_output = shell_exec($cmd) ?? 'No output returned.';
        $_SESSION['flash_success'] = "Banner sync triggered for term $term.";
    } else {
        $errors[] = 'Invalid term code. Use 6-digit format e.g. 202530.';
    }
}

$last_sync = '';
if (file_exists('/proj/logs/sync_banner.log')) {
    $lines = array_filter(array_map('trim', file('/proj/logs/sync_banner.log')));
    $last_sync = end($lines) ?: '';
}

$course_count = $pdo->query("SELECT COUNT(*) FROM courses WHERE active=1")->fetchColumn();
$user_count   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$pending      = $pdo->query("SELECT COUNT(*) FROM ieaf_submissions WHERE status='submitted'")->fetchColumn();

$PAGE_TITLE   = 'Banner Sync — Admin';
$PAGE_HEADING = 'Banner Course Sync';
$PAGE_SUBHEADING = 'Sync course and instructor data from SIUE Banner SSB';
$ACTIVE_NAV   = 'sync';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $e): ?>
  <div class="alert alert--error" role="alert"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
  <div class="card"><div class="card__body" style="text-align:center">
    <div style="font-size:2rem;font-weight:700;color:var(--siue-navy)"><?= $course_count ?></div>
    <div style="color:var(--gray-600);font-size:.85rem">Active courses</div>
  </div></div>
  <div class="card"><div class="card__body" style="text-align:center">
    <div style="font-size:2rem;font-weight:700;color:var(--siue-navy)"><?= $user_count ?></div>
    <div style="color:var(--gray-600);font-size:.85rem">Total users</div>
  </div></div>
  <div class="card"><div class="card__body" style="text-align:center">
    <div style="font-size:2rem;font-weight:700;color:<?= $pending > 0 ? 'var(--siue-red)' : 'var(--success)' ?>"><?= $pending ?></div>
    <div style="color:var(--gray-600);font-size:.85rem">Awaiting review</div>
  </div></div>
</div>

<div class="card mb-3">
  <div class="card__header">Manual Banner Sync</div>
  <div class="card__body">
    <p style="color:var(--gray-600);font-size:.9rem;margin-bottom:1rem">
      Fetches all courses and instructors from Banner SSB for the given term.
      Runs automatically via cron on Jan 1, May 1, and Aug 1.<br>
      <strong>Term code format:</strong> <code>YYYYTT</code> — TT = 10 (Fall), 20 (Spring), 30 (Summer)<br>
      Examples: <code>202520</code> = Spring 2025 &nbsp;|&nbsp; <code>202510</code> = Fall 2025
    </p>
    <form method="post" action="/admin/sync.php"
          style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap"
          aria-label="Run Banner sync">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="term_code">Term Code</label>
        <input class="form-control" type="text" id="term_code" name="term_code"
               placeholder="e.g. 202530" maxlength="6" pattern="[0-9]{6}"
               style="width:160px" aria-required="true" required>
      </div>
      <button type="submit" name="run_sync" value="1" class="btn btn--secondary"
              data-confirm="Run Banner sync now? This may take a minute.">
        Run Sync Now
      </button>
    </form>

    <?php if ($sync_output): ?>
    <pre style="margin-top:1rem;background:var(--gray-900);color:#7ec87e;padding:1rem;
                border-radius:var(--radius);font-size:.8rem;overflow-x:auto;max-height:300px"><?= htmlspecialchars($sync_output) ?></pre>
    <?php endif; ?>
  </div>
  <?php if ($last_sync): ?>
  <div class="card__footer" style="font-size:.8rem;color:var(--gray-600)">
    <strong>Last sync log entry:</strong> <?= htmlspecialchars(substr($last_sync, 0, 160)) ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__body" style="font-size:.875rem;color:var(--gray-600)">
    <strong>Cron schedule</strong> (set via <code>sudo crontab -e</code>):<br>
    <code>0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py &gt;&gt; /proj/logs/sync_banner.log 2&gt;&amp;1</code>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
