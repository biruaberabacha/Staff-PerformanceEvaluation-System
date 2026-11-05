<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$db = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
$eval = $db->prepare("
    SELECT e.*, u.full_name AS evaluator_name,
           iu.full_name AS instructor_name, i.title, i.department, i.school, i.university,
           c.course_code, c.course_title
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_user_id
    JOIN instructors i ON i.id = e.instructor_id
    JOIN users iu ON iu.id = i.user_id
    LEFT JOIN courses c ON c.id = e.course_id
    WHERE e.id = ?
");
$eval->execute([$id]);
$eval = $eval->fetch();

if (!$eval) {
    flash('danger', 'Evaluation not found.');
    header('Location: index.php');
    exit;
}

// Access: head sees all; others only own submissions
if ($user['role'] !== 'head' && $eval['evaluator_user_id'] != $user['id']) {
    flash('danger', 'Access denied.');
    header('Location: index.php');
    exit;
}

$ratings = $db->prepare("
    SELECT r.criterion_id, r.rating, c.category, c.category_name, c.criterion_text
    FROM evaluation_ratings r JOIN criteria c ON c.id = r.criterion_id
    WHERE r.evaluation_id = ? ORDER BY c.sort_order
");
$ratings->execute([$id]);
$ratings = $ratings->fetchAll();
$grouped = [];
foreach ($ratings as $r) {
    $cat = $r['category'];
    if (!isset($grouped[$cat])) $grouped[$cat] = ['name' => $r['category_name'], 'items' => []];
    $grouped[$cat]['items'][] = $r;
}

$pageTitle = 'Evaluation #' . $id;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="mb-0">Evaluation #<?= $id ?></h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Print</button>
        <a href="index.php" class="btn btn-outline-primary btn-sm">Back</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-primary text-white text-center">
        <h5 class="mb-0"><?= htmlspecialchars($eval['university']) ?></h5>
        <div>TEACHERS’ PERFORMANCE EVALUATION FORM</div>
        <small><?= htmlspecialchars($eval['school']) ?> – <?= htmlspecialchars($eval['department']) ?></small>
    </div>
    <div class="card-body">
        <div class="row mb-3 g-2">
            <div class="col-md-6"><strong>Instructor:</strong> <?= htmlspecialchars(trim($eval['title'].' '.$eval['instructor_name'])) ?></div>
            <div class="col-md-3"><strong>Academic Year:</strong> <?= htmlspecialchars($eval['academic_year'] ?: '—') ?></div>
            <div class="col-md-3"><strong>Semester:</strong> <?= htmlspecialchars($eval['semester'] ?: '—') ?></div>
            <?php if ($eval['course_code']): ?>
            <div class="col-md-6"><strong>Course:</strong> <?= htmlspecialchars($eval['course_code'].' – '.$eval['course_title']) ?></div>
            <?php endif; ?>
            <div class="col-md-3"><strong>Evaluator Type:</strong>
                <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($eval['evaluator_type'])) ?></span>
            </div>
            <div class="col-md-3"><strong>Evaluator:</strong> <?= htmlspecialchars($eval['evaluator_name']) ?></div>
            <div class="col-md-3"><strong>Date:</strong> <?= htmlspecialchars($eval['evaluation_date']) ?></div>
        </div>

        <?php foreach ($grouped as $cat => $group): ?>
            <table class="table table-bordered table-sm criteria-table mb-3">
                <thead>
                    <tr class="category-header"><th colspan="3"><?= $cat ?>. <?= htmlspecialchars($group['name']) ?></th></tr>
                    <tr><th style="width:50px">No.</th><th>Criteria</th><th style="width:80px" class="text-center">Rating</th></tr>
                </thead>
                <tbody>
                <?php foreach ($group['items'] as $item): ?>
                    <tr>
                        <td class="text-center"><?= $item['criterion_id'] ?></td>
                        <td><?= htmlspecialchars($item['criterion_text']) ?></td>
                        <td class="text-center fw-bold">
                            <?php $r = (int)$item['rating']; $cls = $r >= 4 ? 'success' : ($r >= 3 ? 'warning' : 'danger'); ?>
                            <span class="badge bg-<?= $cls ?>"><?= $r ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <div class="row mb-3">
            <div class="col-md-4"><div class="p-3 bg-light rounded"><strong>Total:</strong> <span class="fs-4 text-primary"><?= (int)$eval['total_score'] ?></span> / 80</div></div>
            <div class="col-md-4"><div class="p-3 bg-light rounded"><strong>Average:</strong> <span class="fs-4 text-success"><?= number_format($eval['average_rating'], 2) ?></span></div></div>
        </div>

        <div class="mb-3">
            <h6>Strengths</h6>
            <div class="border rounded p-3 bg-white"><?= nl2br(htmlspecialchars($eval['strengths'] ?: '—')) ?></div>
        </div>
        <div class="mb-3">
            <h6>Areas for Improvement</h6>
            <div class="border rounded p-3 bg-white"><?= nl2br(htmlspecialchars($eval['improvements'] ?: '—')) ?></div>
        </div>
        <div class="pt-3 border-top">
            <strong>Evaluator:</strong> <?= htmlspecialchars($eval['evaluator_name']) ?>
            &nbsp;&nbsp; <strong>Date:</strong> <?= htmlspecialchars($eval['evaluation_date']) ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
