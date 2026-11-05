<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Courses & Assignments';

// ---------- Download sample CSV ----------
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="courses_sample.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['course_code', 'course_title', 'semester', 'academic_year', 'batch']);
    fputcsv($out, ['IT301', 'Database Systems', 'I', '2025/26', '2023']);
    fputcsv($out, ['IT302', 'Web Programming', 'I', '2025/26', '2023']);
    fputcsv($out, ['IT401', 'Software Engineering', 'II', '2025/26', '2022']);
    fputcsv($out, ['IT205', 'Data Structures', 'I', '2025/26', '2024']);
    fclose($out);
    exit;
}

// ---------- Bulk upload courses CSV ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_upload') {
    if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        flash('danger', 'Please select a CSV file.');
        header('Location: courses.php');
        exit;
    }
    $name = strtolower($_FILES['csv_file']['name'] ?? '');
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    if (!in_array($ext, ['csv', 'txt'], true)) {
        flash('danger', 'Only CSV files are supported. In Excel use Save As → CSV (Comma delimited).');
        header('Location: courses.php');
        exit;
    }

    $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        flash('danger', 'Empty file.');
        header('Location: courses.php');
        exit;
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv($first));
    $required = ['course_code', 'course_title'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($fh);
            flash('danger', "Missing column: $col. Download the sample CSV.");
            header('Location: courses.php');
            exit;
        }
    }
    $idx = array_flip($header);
    $created = 0;
    $skipped = 0;
    $errors = [];
    $lineNo = 1;
    $ins = $db->prepare('INSERT INTO courses (course_code, course_title, semester, academic_year, batch) VALUES (?,?,?,?,?)');

    while (($row = fgetcsv($fh)) !== false) {
        $lineNo++;
        if (count($row) === 1 && trim($row[0] ?? '') === '') continue;
        $code  = trim($row[$idx['course_code']] ?? '');
        $title = trim($row[$idx['course_title']] ?? '');
        $sem   = trim($row[$idx['semester'] ?? ''] ?? '');
        $year  = trim($row[$idx['academic_year'] ?? ''] ?? '');
        $batch = trim($row[$idx['batch'] ?? ''] ?? '');
        if ($code === '' && $title === '') continue;
        if ($code === '' || $title === '') {
            $errors[] = "Line $lineNo: course_code and course_title required.";
            $skipped++;
            continue;
        }
        // Skip duplicate code+title+year roughly
        $chk = $db->prepare("SELECT id FROM courses WHERE course_code = ? AND course_title = ? AND COALESCE(academic_year, '') = ?");
        $chk->execute([$code, $title, $year]);
        if ($chk->fetch()) {
            $errors[] = "Line $lineNo: course $code already exists (skipped).";
            $skipped++;
            continue;
        }
        try {
            $ins->execute([$code, $title, $sem, $year, $batch]);
            $created++;
        } catch (Exception $e) {
            $errors[] = "Line $lineNo: " . $e->getMessage();
            $skipped++;
        }
    }
    fclose($fh);
    $msg = "Courses upload: $created created, $skipped skipped.";
    if ($errors) {
        $msg .= ' ' . implode(' | ', array_slice($errors, 0, 6));
        flash('warning', $msg);
    } else {
        flash('success', $msg);
    }
    header('Location: courses.php');
    exit;
}

// Create course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_course') {
    $code = trim($_POST['course_code'] ?? '');
    $title = trim($_POST['course_title'] ?? '');
    $sem = trim($_POST['semester'] ?? '');
    $year = trim($_POST['academic_year'] ?? '');
    $batch = trim($_POST['batch'] ?? '');
    if ($code === '' || $title === '') {
        flash('danger', 'Course code and title required.');
    } else {
        $db->prepare('INSERT INTO courses (course_code, course_title, semester, academic_year, batch) VALUES (?,?,?,?,?)')
           ->execute([$code, $title, $sem, $year, $batch]);
        flash('success', 'Course created.');
    }
    header('Location: courses.php');
    exit;
}

// Assign course to instructor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    $cid = (int)$_POST['course_id'];
    $iid = (int)$_POST['instructor_id'];
    $year = trim($_POST['academic_year'] ?? '');
    $sem = trim($_POST['semester'] ?? '');
    $batch = trim($_POST['batch'] ?? '');
    try {
        $db->prepare('INSERT INTO course_assignments (course_id, instructor_id, academic_year, semester, batch) VALUES (?,?,?,?,?)')
           ->execute([$cid, $iid, $year, $sem, $batch]);
        flash('success', 'Course assigned to instructor. Students of that batch can now evaluate.');
    } catch (Exception $e) {
        flash('danger', 'Assignment already exists or invalid.');
    }
    header('Location: courses.php');
    exit;
}

if (isset($_GET['delete_course'])) {
    $db->prepare('DELETE FROM courses WHERE id = ?')->execute([(int)$_GET['delete_course']]);
    flash('success', 'Course deleted.');
    header('Location: courses.php');
    exit;
}
if (isset($_GET['delete_assign'])) {
    $db->prepare('DELETE FROM course_assignments WHERE id = ?')->execute([(int)$_GET['delete_assign']]);
    flash('success', 'Assignment removed.');
    header('Location: courses.php');
    exit;
}

$courses = $db->query('SELECT * FROM courses ORDER BY course_code')->fetchAll();
$instructors = $db->query("
    SELECT i.id, u.full_name, i.title
    FROM instructors i JOIN users u ON u.id = i.user_id WHERE u.is_active=1 ORDER BY u.full_name
")->fetchAll();

$assignments = $db->query("
    SELECT ca.*, c.course_code, c.course_title, u.full_name AS instructor_name, i.title
    FROM course_assignments ca
    JOIN courses c ON c.id = ca.course_id
    JOIN instructors i ON i.id = ca.instructor_id
    JOIN users u ON u.id = i.user_id
    ORDER BY c.course_code
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-journal-bookmark"></i> Courses & Assignments</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?download_sample=1" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download"></i> Sample CSV
        </a>
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-upload"></i> Upload Courses CSV
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#courseModal">
            <i class="bi bi-plus-lg"></i> New Course
        </button>
    </div>
</div>

<div class="alert alert-secondary small">
    <strong>Course CSV columns:</strong>
    <code>course_code, course_title, semester, academic_year, batch</code>
    — only <code>course_code</code> and <code>course_title</code> are required.
    Excel: Save As → CSV (Comma delimited).
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white"><strong>Courses (<?= count($courses) ?>)</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Code</th><th>Title</th><th>Batch</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($c['course_code']) ?></code></td>
                            <td><?= htmlspecialchars($c['course_title']) ?></td>
                            <td><?= htmlspecialchars($c['batch'] ?: '—') ?></td>
                            <td><a href="?delete_course=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete course?')">×</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$courses): ?><tr><td colspan="4" class="text-muted text-center">No courses</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Assign Course to Instructor</strong></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="assign">
                    <div class="col-md-6">
                        <select name="course_id" class="form-select form-select-sm" required>
                            <option value="">Course…</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_code'] . ' – ' . $c['course_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <select name="instructor_id" class="form-select form-select-sm" required>
                            <option value="">Instructor…</option>
                            <?php foreach ($instructors as $ins): ?>
                                <option value="<?= $ins['id'] ?>"><?= htmlspecialchars(trim($ins['title'].' '.$ins['full_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><input type="text" name="academic_year" class="form-control form-control-sm" placeholder="Year"></div>
                    <div class="col-md-3"><input type="text" name="semester" class="form-control form-control-sm" placeholder="Semester"></div>
                    <div class="col-md-3"><input type="text" name="batch" class="form-control form-control-sm" placeholder="Student Batch" required></div>
                    <div class="col-md-3"><button type="submit" class="btn btn-sm btn-primary w-100">Assign</button></div>
                </form>
                <p class="small text-muted mt-2 mb-0">Students with matching <strong>batch</strong> will see this teacher for evaluation.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><strong>Current Assignments</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Course</th><th>Instructor</th><th>Year</th><th>Sem</th><th>Batch</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['course_code']) ?></td>
                            <td><?= htmlspecialchars(trim($a['title'].' '.$a['instructor_name'])) ?></td>
                            <td><?= htmlspecialchars($a['academic_year'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($a['semester'] ?: '—') ?></td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($a['batch']) ?></span></td>
                            <td><a href="?delete_assign=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger">×</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignments): ?><tr><td colspan="6" class="text-muted text-center">No assignments</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="bulk_upload">
            <div class="modal-header">
                <h5 class="modal-title">Upload Courses (CSV)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Download the <a href="?download_sample=1">sample CSV</a> first.
                    Excel → Save As → <strong>CSV (Comma delimited)</strong>.
                </p>
                <div class="mb-3">
                    <label class="form-label">CSV file</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="small">
                    Columns: <code>course_code, course_title, semester, academic_year, batch</code>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="courseModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_course">
            <div class="modal-header">
                <h5 class="modal-title">New Course</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Course Code *</label>
                    <input type="text" name="course_code" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Course Title *</label>
                    <input type="text" name="course_title" class="form-control" required></div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Semester</label>
                        <input type="text" name="semester" class="form-control"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Academic Year</label>
                        <input type="text" name="academic_year" class="form-control"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Default Batch</label>
                        <input type="text" name="batch" class="form-control"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
