<?php
require_once __DIR__ . '/../includes/db.php';
$pageTitle = 'All Evaluations';
$db = getDB();

// Simple filters
$where = [];
$params = [];
if (!empty($_GET['instructor_id'])) {
    $where[] = 'e.instructor_id = ?';
    $params[] = (int)$_GET['instructor_id'];
}
if (!empty($_GET['type'])) {
    $where[] = 'e.evaluator_type = ?';
    $params[] = $_GET['type'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT e.id, e.evaluator_name, e.evaluator_type, e.average_rating, e.total_score,
           e.evaluation_date, e.course_title, e.course_code,
           i.full_name AS instructor_name
    FROM evaluations e
    JOIN instructors i ON i.id = e.instructor_id
    $whereSql
    ORDER BY e.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$instructors = $db->query('SELECT id, full_name FROM instructors ORDER BY full_name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Evaluations</h4>
    <a href="evaluate.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Evaluation</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Instructor</label>
                <select name="instructor_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($instructors as $i): ?>
                        <option value="<?= $i['id'] ?>" <?= ($_GET['instructor_id'] ?? '') == $i['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($i['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Evaluator Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['Student','Department Head','Peer'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($_GET['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2">
                <a href="evaluations.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($list)): ?>
            <p class="text-muted p-4 mb-0">No evaluations found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Instructor</th>
                            <th>Course</th>
                            <th>Evaluator</th>
                            <th>Type</th>
                            <th>Total</th>
                            <th>Average</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $r): ?>
                            <tr>
                                <td><?= $r['id'] ?></td>
                                <td><?= htmlspecialchars($r['instructor_name']) ?></td>
                                <td>
                                    <?= htmlspecialchars($r['course_title'] ?: '—') ?>
                                    <?php if ($r['course_code']): ?>
                                        <small class="text-muted">(<?= htmlspecialchars($r['course_code']) ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['evaluator_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($r['evaluator_type']) ?></span></td>
                                <td><?= (int)$r['total_score'] ?></td>
                                <td>
                                    <span class="badge bg-<?= $r['average_rating'] >= 4 ? 'success' : ($r['average_rating'] >= 3 ? 'warning' : 'danger') ?>">
                                        <?= number_format($r['average_rating'], 2) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($r['evaluation_date']) ?></td>
                                <td>
                                    <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
