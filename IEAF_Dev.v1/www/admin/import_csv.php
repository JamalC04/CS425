<?php
/**
 * admin/import_csv.php — CSV Import Tool
 *
 * Accepts two CSV types from Argos/Banner or Qualtrics exports:
 *
 * ENROLLMENT CSV (links students to courses):
 *   student_email, student_first, student_last, crn, course_name, section, term
 *
 * FACULTY/COURSE CSV (creates faculty users and courses):
 *   faculty_email, faculty_first, faculty_last, crn, course_name, section, term
 *
 * Column order does not matter. Header row required.
 * Term format: Spring2025, Fall2025, Summer2025
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!$CURRENT_USER) require_login();
require_role('admin');

$pdo = db();
$result = null; $errors = [];

function norm(string $h): string { return strtolower(trim(preg_replace('/[\s\-]+/', '_', $h))); }

function csv_rows(string $path): array|false {
    $fh = fopen($path, 'r'); if (!$fh) return false;
    $raw = fgetcsv($fh); if (!$raw) { fclose($fh); return false; }
    $headers = array_map('norm', $raw);
    $rows = [];
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) < count($headers)) continue;
        $rows[] = array_combine($headers, array_map('trim', array_slice($line, 0, count($headers))));
    }
    fclose($fh); return $rows;
}

function upsert_user(PDO $p, string $email, string $first, string $last, string $role): int {
    $email = strtolower(trim($email));
    $s = $p->prepare('SELECT user_id,role FROM users WHERE email=?'); $s->execute([$email]);
    $ex = $s->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
        $nr = $ex['role'];
        if ($role==='admin') $nr='admin';
        elseif ($role==='faculty' && $ex['role']==='student') $nr='faculty';
        $p->prepare('UPDATE users SET given_name=?,surname=?,role=?,updated_at=NOW() WHERE user_id=?')
          ->execute([$first,$last,$nr,$ex['user_id']]);
        return $ex['user_id'];
    }
    $p->prepare("INSERT INTO users (email,given_name,surname,role,active) VALUES (?,?,?,?,1)")
      ->execute([$email,$first,$last,$role]);
    $uid=(int)$p->lastInsertId();
    return $uid;
}

function upsert_course(PDO $p, string $crn, string $name, string $sec, string $term, ?int $fid): int {
    $s=$p->prepare('SELECT course_id FROM courses WHERE crn=?'); $s->execute([$crn]);
    $ex=$s->fetchColumn();
    if ($ex) { $p->prepare('UPDATE courses SET course_name=?,section=?,term=?,faculty_id=?,active=1 WHERE course_id=?')->execute([$name,$sec,$term,$fid,$ex]); return (int)$ex; }
    $p->prepare('INSERT INTO courses (crn,course_name,section,term,faculty_id,active) VALUES (?,?,?,?,?,1)')->execute([$crn,$name,$sec,$term,$fid]);
    return (int)$p->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { $errors[]='Upload failed (code '.$file['error'].').'; }
    else {
        $rows = csv_rows($file['tmp_name']);
        if (!$rows) { $errors[]='Could not read CSV or file is empty.'; }
        else {
            $headers = array_keys($rows[0]);
            $type = in_array('student_email',$headers) ? 'enrollment' : (in_array('faculty_email',$headers) ? 'faculty' : 'unknown');
            if ($type==='unknown') { $errors[]='Unrecognised format. Found: '.implode(', ',$headers).'. Need student_email or faculty_email column.'; }
            else {
                $c=['created'=>0,'updated'=>0,'courses'=>0,'enrollments'=>0,'skipped'=>0];
                $rerrs=[];
                $pdo->beginTransaction();
                try {
                    foreach ($rows as $i=>$row) {
                        $line=$i+2;
                        $req = $type==='enrollment'
                            ? ['student_email','student_first','student_last','crn','course_name','term']
                            : ['faculty_email','faculty_first','faculty_last','crn','course_name','term'];
                        foreach ($req as $col) { if (empty($row[$col])) { $rerrs[]="Row $line: missing '$col' — skipped."; $c['skipped']++; continue 2; } }
                        $before=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                        if ($type==='enrollment') {
                            $sid=upsert_user($pdo,$row['student_email'],$row['student_first'],$row['student_last'],'student');
                            $after=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                            $after>$before ? $c['created']++ : $c['updated']++;
                            $cid=upsert_course($pdo,$row['crn'],$row['course_name'],$row['section']??'',$row['term'],null);
                            $c['courses']++;
                            $pdo->prepare('INSERT IGNORE INTO enrollments (student_id,course_id,term) VALUES (?,?,?)')->execute([$sid,$cid,$row['term']]);
                            $c['enrollments']++;
                        } else {
                            $fid=upsert_user($pdo,$row['faculty_email'],$row['faculty_first'],$row['faculty_last'],'faculty');
                            $after=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                            $after>$before ? $c['created']++ : $c['updated']++;
                            upsert_course($pdo,$row['crn'],$row['course_name'],$row['section']??'',$row['term'],$fid);
                            $c['courses']++;
                        }
                    }
                    $pdo->commit();
                    $result=['type'=>$type,'counts'=>$c,'row_errors'=>$rerrs,'total'=>count($rows)];
                } catch (Throwable $e) { $pdo->rollBack(); $errors[]='DB error: '.$e->getMessage(); error_log('CSV import: '.$e->getMessage()); }
            }
        }
    }
    @unlink($file['tmp_name']??'');
}

$PAGE_TITLE='Import CSV — Admin'; $PAGE_HEADING='Import CSV Data';
$PAGE_SUBHEADING='Upload Argos enrollment or faculty/course exports'; $ACTIVE_NAV='import';
require_once __DIR__ . '/../includes/header.php';
?>
<?php foreach($errors as $e): ?><div class="alert alert--error" role="alert"><?=htmlspecialchars($e)?></div><?php endforeach; ?>
<?php if($result): ?>
<div class="alert alert--success" role="status">
  <strong>Import complete</strong> — <?=$result['total']?> rows processed.
  <ul style="margin:.5rem 0 0 1.25rem">
    <li><?=$result['counts']['created']?> user(s) created</li>
    <li><?=$result['counts']['updated']?> user(s) updated</li>
    <li><?=$result['counts']['courses']?> course(s) upserted</li>
    <?php if($result['type']==='enrollment'): ?><li><?=$result['counts']['enrollments']?> enrollment(s) processed</li><?php endif; ?>
    <?php if($result['counts']['skipped']): ?><li><?=$result['counts']['skipped']?> row(s) skipped — see warnings</li><?php endif; ?>
  </ul>
</div>
<?php if(!empty($result['row_errors'])): ?>
<div class="alert alert--warning" role="alert"><strong>Row warnings:</strong>
  <ul style="margin:.5rem 0 0 1.25rem"><?php foreach($result['row_errors'] as $re): ?><li><?=htmlspecialchars($re)?></li><?php endforeach; ?></ul>
</div>
<?php endif; endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:2rem">
  <div class="card">
    <div class="card__header">Upload CSV File</div>
    <div class="card__body">
      <form method="post" action="/admin/import_csv.php" enctype="multipart/form-data" aria-label="CSV import">
        <div class="form-group">
          <label class="form-label" for="csv_file">CSV File <span class="required" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
          <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv,text/csv,text/plain" aria-required="true" required>
          <p class="form-hint">File type (enrollment vs faculty/course) is detected from column headers.</p>
        </div>
        <button type="submit" class="btn btn--primary">Import</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card__header">Expected Column Headers</div>
    <div class="card__body">
      <p style="font-weight:600;margin-bottom:.4rem;font-size:.9rem">Enrollment CSV</p>
      <code style="display:block;background:var(--gray-50);padding:.6rem;border-radius:var(--radius);font-size:.78rem;margin-bottom:1rem;overflow-x:auto;white-space:nowrap">student_email, student_first, student_last, crn, course_name, section, term</code>
      <p style="font-weight:600;margin-bottom:.4rem;font-size:.9rem">Faculty/Course CSV</p>
      <code style="display:block;background:var(--gray-50);padding:.6rem;border-radius:var(--radius);font-size:.78rem;overflow-x:auto;white-space:nowrap">faculty_email, faculty_first, faculty_last, crn, course_name, section, term</code>
      <p class="form-hint" style="margin-top:.75rem">Column order does not matter. Header row required. <strong>term</strong> format: Spring2025, Fall2025, Summer2025.</p>
    </div>
  </div>
</div>
<div class="card">
  <div class="card__header">Sample CSV Templates</div>
  <div class="card__body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div>
        <p style="font-weight:600;margin-bottom:.4rem;font-size:.9rem">Enrollment CSV</p>
        <pre style="background:var(--gray-50);padding:.75rem;border-radius:var(--radius);font-size:.75rem;overflow-x:auto">student_email,student_first,student_last,crn,course_name,section,term
jsmith@siue.edu,John,Smith,10425,CS 425,001,Spring2025
mjones@siue.edu,Mary,Jones,10425,CS 425,001,Spring2025</pre>
      </div>
      <div>
        <p style="font-weight:600;margin-bottom:.4rem;font-size:.9rem">Faculty/Course CSV</p>
        <pre style="background:var(--gray-50);padding:.75rem;border-radius:var(--radius);font-size:.75rem;overflow-x:auto">faculty_email,faculty_first,faculty_last,crn,course_name,section,term
pfranke@siue.edu,Patricia,Franke,10425,CS 425,001,Spring2025
jlittle@siue.edu,Jhen,Little,10390,CS 390,001,Spring2025</pre>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
