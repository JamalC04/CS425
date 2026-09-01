<?php
/**
 * admin/users.php — Manage user roles and access
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($tid === $CURRENT_USER['user_id']) {
        $_SESSION['flash_error'] = 'You cannot modify your own account.';
        header('Location: /admin/users.php'); exit;
    }
    if ($action === 'set_role' && in_array($_POST['role'] ?? '', ['student','faculty','admin'], true)) {
        $pdo->prepare('UPDATE users SET role=?,updated_at=NOW() WHERE user_id=?')->execute([$_POST['role'], $tid]);
        $_SESSION['flash_success'] = 'Role updated.';
    } elseif ($action === 'deactivate') {
        $pdo->prepare('UPDATE users SET active=0,updated_at=NOW() WHERE user_id=?')->execute([$tid]);
        $_SESSION['flash_success'] = 'Account deactivated.';
    } elseif ($action === 'activate') {
        $pdo->prepare('UPDATE users SET active=1,updated_at=NOW() WHERE user_id=?')->execute([$tid]);
        $_SESSION['flash_success'] = 'Account reactivated.';
    }
    header('Location: /admin/users.php'); exit;
}

$search = trim($_GET['q'] ?? '');
$rfilt  = $_GET['role'] ?? '';
$where  = []; $params = [];
if ($search) { $where[] = '(email LIKE ? OR given_name LIKE ? OR surname LIKE ?)'; $like = "%$search%"; $params = [$like,$like,$like]; }
if ($rfilt && in_array($rfilt, ['student','faculty','admin'], true)) { $where[] = 'role=?'; $params[] = $rfilt; }
$wsql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$users = $pdo->prepare("SELECT user_id,email,given_name,surname,role,active,updated_at FROM users $wsql ORDER BY role,surname,given_name LIMIT 300");
$users->execute($params);
$rows = $users->fetchAll();

$counts = array_column($pdo->query("SELECT role,COUNT(*) n FROM users WHERE active=1 GROUP BY role")->fetchAll(), 'n', 'role');

$PAGE_TITLE='Manage Users — Admin'; $PAGE_HEADING='Manage Users';
$PAGE_SUBHEADING=count($rows).' user'.(count($rows)!==1?'s':'').' shown';
$ACTIVE_NAV='users';
require_once __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <?php foreach(['student'=>'Students','faculty'=>'Faculty','admin'=>'Admins'] as $r=>$l): ?>
  <div class="card" style="flex:1;min-width:130px"><div class="card__body" style="text-align:center;padding:.875rem">
    <div style="font-size:1.75rem;font-weight:700;color:var(--siue-navy)"><?= $counts[$r]??0 ?></div>
    <div style="color:var(--gray-600);font-size:.85rem"><?= $l ?></div>
  </div></div>
  <?php endforeach; ?>
</div>

<form method="get" action="/admin/users.php" class="actions mb-2" aria-label="Filter users">
  <input class="form-control" type="search" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Search name or email…" style="max-width:280px" aria-label="Search users">
  <select class="form-control" name="role" style="max-width:160px" aria-label="Filter by role">
    <option value="">All roles</option>
    <?php foreach(['student'=>'Students','faculty'=>'Faculty','admin'=>'Admins'] as $v=>$l): ?>
    <option value="<?=$v?>" <?=$rfilt===$v?'selected':''?>><?=$l?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn--secondary">Filter</button>
  <?php if($search||$rfilt): ?><a href="/admin/users.php" class="btn btn--ghost">Clear</a><?php endif; ?>
  <a href="/admin/import_csv.php" class="btn btn--ghost" style="margin-left:auto">Import CSV</a>
</form>

<div class="card">
  <div class="table-wrap">
    <table aria-label="User accounts">
      <thead><tr>
        <th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th>
        <th scope="col">Status</th><th scope="col">Updated</th><th scope="col">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($rows as $u):
        $is_self = $u['user_id'] === $CURRENT_USER['user_id'];
        $ctx = htmlspecialchars($u['given_name'].' '.$u['surname'].' ('.$u['email'].')');
      ?>
        <tr <?= !$u['active']?'style="opacity:.55"':'' ?>>
          <td><?= htmlspecialchars($u['given_name'].' '.$u['surname']) ?>
            <?php if($is_self): ?><span class="sr-only">(you)</span><small style="color:var(--gray-600)"> (you)</small><?php endif; ?>
          </td>
          <td style="font-size:.875rem"><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="role-badge role-badge--<?=$u['role']?>"><?= ucfirst($u['role']) ?></span></td>
          <td><span style="font-size:.875rem;font-weight:600;color:<?=$u['active']?'var(--success)':'var(--error)'?>"><?=$u['active']?'Active':'Inactive'?></span></td>
          <td style="font-size:.8rem;color:var(--gray-600);white-space:nowrap"><?= date('M j, Y',strtotime($u['updated_at'])) ?></td>
          <td>
            <?php if(!$is_self): ?>
            <div class="actions">
              <form method="post" action="/admin/users.php" style="display:inline-flex;gap:.35rem;align-items:center">
                <input type="hidden" name="user_id" value="<?=$u['user_id']?>">
                <input type="hidden" name="action"  value="set_role">
                <label class="sr-only" for="role_<?=$u['user_id']?>">Change role for <?=$ctx?></label>
                <select class="form-control" name="role" id="role_<?=$u['user_id']?>"
                        style="padding:.3rem .5rem;font-size:.85rem;min-height:0;height:36px">
                  <?php foreach(['student','faculty','admin'] as $r): ?>
                  <option value="<?=$r?>" <?=$u['role']===$r?'selected':''?>><?=ucfirst($r)?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--ghost btn--sm" aria-label="Save role for <?=$ctx?>">Save</button>
              </form>
              <form method="post" action="/admin/users.php" style="display:inline">
                <input type="hidden" name="user_id" value="<?=$u['user_id']?>">
                <input type="hidden" name="action"  value="<?=$u['active']?'deactivate':'activate'?>">
                <button type="submit" class="btn btn--sm <?=$u['active']?'btn--danger':'btn--secondary'?>"
                        aria-label="<?=$u['active']?'Deactivate':'Reactivate'?> <?=$ctx?>"
                        data-confirm="<?=$u['active']?'Deactivate this account?':'Reactivate this account?'?>">
                  <?=$u['active']?'Deactivate':'Reactivate'?>
                </button>
              </form>
            </div>
            <?php else: ?><span style="color:var(--gray-600);font-size:.85rem">Cannot edit own account</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:var(--gray-600);padding:2rem">No users found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
