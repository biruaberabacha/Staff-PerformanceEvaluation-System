<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Manage Users';
$user = currentUser();

// ---------- Download sample CSV ----------
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_sample.csv"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['role', 'full_name', 'username', 'password', 'email', 'title', 'batch']);
    fputcsv($out, ['instructor', 'Dr. Karthikeyan', 'karthikeyan', 'pass123', 'karthi@ambo.edu.et', 'Dr.', '']);
    fputcsv($out, ['instructor', 'Bacha Umeta', 'bacha', 'pass123', 'bacha@ambo.edu.et', 'Mr.', '']);
    fputcsv($out, ['student', 'Abebe Kebede', 'abebe', 'pass123', 'abebe@student.ambo.edu.et', '', '2023']);
    fputcsv($out, ['student', 'Tigist Alemu', 'tigist', 'pass123', 'tigist@student.ambo.edu.et', '', '2023']);
    fputcsv($out, ['student', 'Solomon Desta', 'solomon', 'pass123', 'solomon@student.ambo.edu.et', '', '2024']);
    fclose($out);
    exit;
}

// ---------- Bulk upload CSV / Excel(CSV) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_upload') {
    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        flash('danger', 'Please select a CSV file to upload.');
        header('Location: users.php');
        exit;
    }

    $tmp = $_FILES['csv_file']['tmp_name'];
    $name = strtolower($_FILES['csv_file']['name'] ?? '');
    $ext = pathinfo($name, PATHINFO_EXTENSION);

    if (!in_array($ext, ['csv', 'txt'], true)) {
        flash('danger', 'Only CSV files are supported. Save your Excel file as CSV (Comma delimited) and upload again.');
        header('Location: users.php');
        exit;
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) {
        flash('danger', 'Could not read uploaded file.');
        header('Location: users.php');
        exit;
    }

    // Skip BOM
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        flash('danger', 'Empty file.');
        header('Location: users.php');
        exit;
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $header = str_getcsv($first);
    $header = array_map(function ($h) {
        return strtolower(trim($h));
    }, $header);

    $required = ['role', 'full_name', 'username', 'password'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($fh);
            flash('danger', "Missing required column: {$col}. Download the sample CSV for the correct format.");
            header('Location: users.php');
            exit;
        }
    }

    $idx = array_flip($header);
    $created = 0;
    $skipped = 0;
    $errors = [];
    $lineNo = 1;

    $insertUser = $db->prepare(
        'INSERT INTO users (username, password, full_name, role, email, created_by) VALUES (?,?,?,?,?,?)'
    );
    $insertIns = $db->prepare('INSERT INTO instructors (user_id, title) VALUES (?,?)');
    $insertStu = $db->prepare('INSERT INTO students (user_id, batch) VALUES (?,?)');

    while (($row = fgetcsv($fh)) !== false) {
        $lineNo++;
        if (count($row) === 1 && trim($row[0]) === '') continue;

        $role     = strtolower(trim($row[$idx['role']] ?? ''));
        $fullName = trim($row[$idx['full_name']] ?? '');
        $username = trim($row[$idx['username']] ?? '');
        $password = trim($row[$idx['password']] ?? '');
        $email    = trim($row[$idx['email']] ?? '');
        $title    = trim($row[$idx['title']] ?? '');
        $batch    = trim($row[$idx['batch']] ?? '');

        if ($role === '' && $fullName === '' && $username === '') continue;

        if (!in_array($role, ['instructor', 'student'], true)) {
            $errors[] = "Line $lineNo: role must be instructor or student.";
            $skipped++;
            continue;
        }
        if ($fullName === '' || $username === '' || $password === '') {
            $errors[] = "Line $lineNo: full_name, username and password are required.";
            $skipped++;
            continue;
        }
        if ($role === 'student' && $batch === '') {
            $errors[] = "Line $lineNo: batch is required for students.";
            $skipped++;
            continue;
        }

        // Duplicate username?
        $chk = $db->prepare('SELECT id FROM users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $errors[] = "Line $lineNo: username '$username' already exists (skipped).";
            $skipped++;
            continue;
        }

        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insertUser->execute([$username, $hash, $fullName, $role, $email, $user['id']]);
            $uid = $db->lastInsertId();
            if ($role === 'instructor') {
                $insertIns->execute([$uid, $title]);
            } else {
                $insertStu->execute([$uid, $batch]);
            }
            $db->commit();
            $created++;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Line $lineNo: " . $e->getMessage();
            $skipped++;
        }
    }
    fclose($fh);

    $msg = "Bulk upload finished: $created created, $skipped skipped.";
    if ($errors) {
        $msg .= ' Details: ' . implode(' | ', array_slice($errors, 0, 8));
        if (count($errors) > 8) $msg .= ' …';
        flash('warning', $msg);
    } else {
        flash('success', $msg);
    }
    header('Location: users.php');
    exit;
}

// ---------- Single add / edit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $batch = trim($_POST['batch'] ?? '');

    if (!in_array($role, ['instructor', 'student'], true)) {
        flash('danger', 'Invalid role.');
    } elseif ($username === '' || $full_name === '') {
        flash('danger', 'Username and full name are required.');
    } else {
        try {
            $db->beginTransaction();
            if ($action === 'add') {
                if ($password === '') throw new Exception('Password is required for new users.');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (username, password, full_name, role, email, created_by) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$username, $hash, $full_name, $role, $email, $user['id']]);
                $uid = $db->lastInsertId();
                if ($role === 'instructor') {
                    $db->prepare('INSERT INTO instructors (user_id, title) VALUES (?,?)')->execute([$uid, $title]);
                } else {
                    $db->prepare('INSERT INTO students (user_id, batch) VALUES (?,?)')->execute([$uid, $batch]);
                }
                flash('success', ucfirst($role) . ' account created.');
            } elseif ($action === 'edit') {
                $id = (int)$_POST['id'];
                $db->prepare('UPDATE users SET full_name=?, email=? WHERE id=? AND role IN ("instructor","student")')
                   ->execute([$full_name, $email, $id]);
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $id]);
                }
                if ($role === 'instructor') {
                    $db->prepare('UPDATE instructors SET title=? WHERE user_id=?')->execute([$title, $id]);
                } else {
                    $db->prepare('UPDATE students SET batch=? WHERE user_id=?')->execute([$batch, $id]);
                }
                flash('success', 'User updated.');
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', $e->getMessage());
        }
    }
    header('Location: users.php');
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    if ($id !== 1) {
        $db->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role IN ("instructor","student")')->execute([$id]);
        flash('success', 'User status updated.');
    }
    header('Location: users.php');
    exit;
}

$instructors = $db->query("
    SELECT u.id, u.username, u.full_name, u.email, u.is_active, i.title, i.id AS instructor_id
    FROM users u JOIN instructors i ON i.user_id = u.id
    ORDER BY u.full_name
")->fetchAll();

$students = $db->query("
    SELECT u.id, u.username, u.full_name, u.email, u.is_active, s.batch, s.id AS student_id
    FROM users u JOIN students s ON s.user_id = u.id
    ORDER BY u.full_name
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-people"></i> Manage Users</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?download_sample=1" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download"></i> Download Sample CSV
        </a>
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload"></i> Upload CSV
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-lg"></i> Create Account
        </button>
    </div>
</div>

<div class="alert alert-secondary small">
    <strong>Bulk upload format (CSV):</strong>
    columns <code>role, full_name, username, password, email, title, batch</code><br>
    • <code>role</code> = <code>instructor</code> or <code>student</code><br>
    • For <strong>instructor</strong>: use <code>title</code> (e.g. Dr.), leave <code>batch</code> empty<br>
    • For <strong>student</strong>: <code>batch</code> is required (e.g. 2023), leave <code>title</code> empty<br>
    • In Excel: File → Save As → <strong>CSV (Comma delimited)</strong>, then upload that file
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-ins">Instructors (<?= count($instructors) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stu">Students (<?= count($students) ?>)</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-ins">
        <div class="card"><div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Username</th><th>Title</th><th>Email</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($instructors as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><code><?= htmlspecialchars($r['username']) ?></code></td>
                        <td><?= htmlspecialchars($r['title']) ?></td>
                        <td><?= htmlspecialchars($r['email'] ?: '—') ?></td>
                        <td>
                            <span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td>
                            <a href="?toggle=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$instructors): ?><tr><td colspan="6" class="text-muted text-center">No instructors yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="tab-pane fade" id="tab-stu">
        <div class="card"><div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Username</th><th>Batch</th><th>Email</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($students as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><code><?= htmlspecialchars($r['username']) ?></code></td>
                        <td><?= htmlspecialchars($r['batch'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['email'] ?: '—') ?></td>
                        <td>
                            <span class="badge bg-<?= $r['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td>
                            <a href="?toggle=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?><tr><td colspan="6" class="text-muted text-center">No students yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<!-- Bulk Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="bulk_upload">
            <div class="modal-header">
                <h5 class="modal-title">Upload Users (CSV)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Download the <a href="?download_sample=1">sample CSV</a> first.
                    If you use Excel, save as <strong>CSV (Comma delimited)</strong> before uploading.
                </p>
                <div class="mb-3">
                    <label class="form-label">CSV file</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="small">
                    <strong>Required columns:</strong> role, full_name, username, password<br>
                    <strong>Optional:</strong> email, title (instructors), batch (students – required for students)
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload &amp; Create Accounts</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Create Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" id="roleSelect" class="form-select" required>
                        <option value="instructor">Instructor</option>
                        <option value="student">Student</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="mb-3" id="titleField">
                    <label class="form-label">Title (Dr./Mr./Mrs.)</label>
                    <input type="text" name="title" class="form-control">
                </div>
                <div class="mb-3 d-none" id="batchField">
                    <label class="form-label">Batch <span class="text-danger">*</span></label>
                    <input type="text" name="batch" class="form-control" placeholder="e.g. 2023">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('roleSelect').addEventListener('change', function() {
    const isStu = this.value === 'student';
    document.getElementById('titleField').classList.toggle('d-none', isStu);
    document.getElementById('batchField').classList.toggle('d-none', !isStu);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
