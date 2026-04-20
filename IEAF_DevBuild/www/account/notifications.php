<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();

$pdo = db();
$uid  = $CURRENT_USER['user_id'];
$role = $CURRENT_USER['role'];

$pdo->prepare("INSERT IGNORE INTO notification_prefs (user_id) VALUES (?)")->execute([$uid]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowed = match($role) {
        'student' => ['form_approved'],
        'faculty' => ['form_submitted_confirm','form_rejected','draft_reminder'],
        'admin'   => ['new_submission','weekly_digest'],
        default   => [],
    };
    $updates = []; $params = [];
    foreach ($allowed as $col) { $updates[] = "$col = ?"; $params[] = isset($_POST[$col]) ? 1 : 0; }
    $params[] = $uid;
    if ($updates) $pdo->prepare("UPDATE notification_prefs SET ".implode(',',$updates)." WHERE user_id=?")->execute($params);
    $_SESSION['flash_success'] = 'Notification preferences saved.';
    header('Location: /account/notifications.php'); exit;
}

$stmt = $pdo->prepare('SELECT * FROM notification_prefs WHERE user_id=?');
$stmt->execute([$uid]);
$prefs = $stmt->fetch() ?: [];
$ck = fn($k) => !empty($prefs[$k]) ? 'checked' : '';

$PAGE_TITLE      = 'Notification Preferences';
$PAGE_HEADING    = 'Email Notification Preferences';
$PAGE_SUBHEADING = 'Choose which emails you receive from the ACCESS IEA Forms Portal';
$ACTIVE_NAV      = 'notifications';
require_once __DIR__ . '/../includes/header.php';

$prefs_by_role = [
    'student' => [
        ['key'=>'form_approved','title'=>'IEA Form approved','desc'=>'Email me when an IEAF for one of my courses has been approved by ACCESS.'],
    ],
    'faculty' => [
        ['key'=>'form_submitted_confirm','title'=>'Submission confirmation','desc'=>'Email me a confirmation when I successfully submit a form to ACCESS.'],
        ['key'=>'form_rejected','title'=>'Form returned with comments','desc'=>'Email me when ACCESS returns my form asking for changes. (Recommended — keep this on.)'],
        ['key'=>'draft_reminder','title'=>'Draft reminder','desc'=>'Remind me if I have an unsubmitted draft that has been sitting for more than 7 days.'],
    ],
    'admin' => [
        ['key'=>'new_submission','title'=>'New submission alert','desc'=>'Email me immediately when a faculty member submits an IEAF for review.'],
        ['key'=>'weekly_digest','title'=>'Weekly pending digest','desc'=>'Email me a Monday summary of all submissions still waiting for review.'],
    ],
];
$my_prefs = $prefs_by_role[$role] ?? [];
?>
<div style="max-width:640px">
<form method="post" action="/account/notifications.php" aria-label="Email notification preferences">
  <div class="card mb-2">
    <div class="card__header" id="prefs-heading">Email Preferences — <?= ucfirst($role) ?></div>
    <div class="card__body">
      <?php if (empty($my_prefs)): ?>
        <p style="color:var(--gray-600)">No notification settings available for your role.</p>
      <?php else: ?>
        <fieldset style="border:0;padding:0;margin:0" aria-labelledby="prefs-heading">
          <legend class="sr-only">Email notification toggles for <?= ucfirst($role) ?> role</legend>
          <?php foreach ($my_prefs as $i => $p): ?>
          <div class="pref-toggle <?= $i===count($my_prefs)-1 ? '' : '' ?>">
            <div class="pref-toggle__track">
              <input type="checkbox" name="<?= $p['key'] ?>" value="1"
                     id="pref_<?= $p['key'] ?>" <?= $ck($p['key']) ?>
                     aria-describedby="desc_<?= $p['key'] ?>">
              <span class="pref-toggle__slider" aria-hidden="true"></span>
            </div>
            <div class="pref-toggle__text">
              <label for="pref_<?= $p['key'] ?>"><strong><?= htmlspecialchars($p['title']) ?></strong></label>
              <span id="desc_<?= $p['key'] ?>"><?= htmlspecialchars($p['desc']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </fieldset>
      <?php endif; ?>
    </div>
  </div>
  <div class="actions mt-2">
    <button type="submit" class="btn btn--primary">Save Preferences</button>
    <a href="/" class="btn btn--ghost">Cancel</a>
  </div>
</form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
