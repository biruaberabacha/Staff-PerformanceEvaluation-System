<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$db = getDB();
$pageTitle = 'Dashboard';

$stats = [];
if ($user['role'] === 'head') {
    $stats['instructors'] = $db->query("SELECT COUNT(*) FROM users WHERE role='instructor' AND is_active=1")->fetchColumn();
    $stats['students']    = $db->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
    $stats['evaluations'] = $db->query('SELECT COUNT(*) FROM evaluations')->fetchColumn();
    $stats['groups']      = $db->query('SELECT COUNT(*) FROM peer_groups')->fetchColumn();
    $stats['courses']     = $db->query('SELECT COUNT(*) FROM courses')->fetchColumn();
} elseif ($user['role'] === 'instructor') {
    $iid = $user['instructor_id'] ?? 0;
    $stats['to_evaluate'] = 0;
    if ($iid) {
        // peers in same groups excluding self, not yet evaluated
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT i.id)
            FROM peer_group_members me
            JOIN peer_group_members other ON other.group_id = me.group_id AND other.instructor_id != me.instructor_id
            JOIN instructors i ON i.id = other.instructor_id
            WHERE me.instructor_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM evaluations e
                  WHERE e.instructor_id = other.instructor_id
                    AND e.evaluator_user_id = ?
                    AND e.evaluator_type = 'peer'
              )
        ");
        $stmt->execute([$iid, $user['id']]);
        $stats['to_evaluate'] = (int)$stmt->fetchColumn();
    }
    $stats['submitted'] = $db->prepare("SELECT COUNT(*) FROM evaluations WHERE evaluator_user_id = ?");
    $stats['submitted']->execute([$user['id']]);
    $stats['submitted'] = $stats['submitted']->fetchColumn();
} else { // student
    $batch = $user['batch'] ?? '';
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT ca.instructor_id)
        FROM course_assignments ca
        WHERE ca.batch = ?
          AND NOT EXISTS (
              SELECT 1 FROM evaluations e
              WHERE e.instructor_id = ca.instructor_id
                AND e.evaluator_user_id = ?
                AND e.evaluator_type = 'student'
                AND e.course_id = ca.course_id
          )
    ");
    $stmt->execute([$batch, $user['id']]);
    $stats['to_evaluate'] = (int)$stmt->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM evaluations WHERE evaluator_user_id = ?");
    $st->execute([$user['id']]);
    $stats['submitted'] = $st->fetchColumn();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <h4>Welcome, <?= htmlspecialchars($user['full_name']) ?></h4>
    <p class="text-muted mb-0">Role: <strong><?= ucfirst($user['role']) ?></strong>
        <?php if ($user['role'] === 'student' && !empty($user['batch'])): ?>
            · Batch: <?= htmlspecialchars($user['batch']) ?>
        <?php endif; ?>
    </p>
</div>

<?php if ($user['role'] === 'head'): ?>
<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card stat-card"><div class="card-body py-3"><div class="text-muted small">Instructors</div><div class="fs-3 fw-bold"><?= $stats['instructors'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card stat-card"><div class="card-body py-3"><div class="text-muted small">Students</div><div class="fs-3 fw-bold"><?= $stats['students'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card stat-card"><div class="card-body py-3"><div class="text-muted small">Evaluations</div><div class="fs-3 fw-bold"><?= $stats['evaluations'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body py-3"><div class="text-muted small">Peer Groups</div><div class="fs-3 fw-bold"><?= $stats['groups'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card stat-card"><div class="card-body py-3"><div class="text-muted small">Courses</div><div class="fs-3 fw-bold"><?= $stats['courses'] ?></div></div></div></div>
</div>
<div class="alert alert-info">
    <strong>Workflow for Head:</strong>
    1) Create Instructor & Student accounts →
    2) Create Peer Groups & add instructors →
    3) Create Courses & assign to instructors (with student batch) →
    4) Evaluate instructors (Head weight 30%) →
    5) Generate Final Weighted Report
</div>
<div class="d-flex flex-wrap gap-2">
    <a href="users.php" class="btn btn-primary"><i class="bi bi-people"></i> Manage Users</a>
    <a href="peer_groups.php" class="btn btn-outline-primary"><i class="bi bi-diagram-3"></i> Peer Groups</a>
    <a href="courses.php" class="btn btn-outline-primary"><i class="bi bi-journal-bookmark"></i> Courses & Assignments</a>
    <a href="evaluate.php" class="btn btn-success"><i class="bi bi-pencil-square"></i> Evaluate as Head</a>
    <a href="progress.php" class="btn btn-outline-warning"><i class="bi bi-clipboard-check"></i> Progress</a>
    <a href="report.php" class="btn btn-warning"><i class="bi bi-bar-chart"></i> Final Report</a>
</div>

<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Pending to Evaluate</div>
                <div class="fs-2 fw-bold text-warning"><?= $stats['to_evaluate'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Submitted by You</div>
                <div class="fs-2 fw-bold text-success"><?= $stats['submitted'] ?></div>
            </div>
        </div>
    </div>
</div>
<a href="evaluate.php" class="btn btn-primary btn-lg">
    <i class="bi bi-pencil-square"></i>
    <?= $user['role'] === 'instructor' ? 'Peer Evaluate Colleagues' : 'Evaluate Your Teachers' ?>
</a>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
