<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
if ($user['role'] === 'head') {
    header('Location: report.php');
    exit;
}
$db = getDB();
$pageTitle = 'My Evaluations';

$stmt = $db->prepare("
    SELECT e.id, e.evaluator_type, e.average_rating, e.total_score, e.evaluation_date,
           u.full_name AS instructor_name, i.title, c.course_code, c.course_title
    FROM evaluations e
    JOIN instructors i ON i.id = e.instructor_id
    JOIN users u ON u.id = i.user_id
    LEFT JOIN courses c ON c.id = e.course_id
    WHERE e.evaluator_user_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$user['id']]);
$list = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-list-check"></i> My Submitted Evaluations</h4>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Instructor</th>
                    <th>Course</th>
                    <th>Type</th>
                    <th>Avg</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars(trim($r['title'].' '.$r['instructor_name'])) ?></td>
                    <td><?= $r['course_code'] ? htmlspecialchars($r['course_code']) : '—' ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($r['evaluator_type']) ?></span></td>
                    <td><span class="badge bg-<?= $r['average_rating'] >= 4 ? 'success' : ($r['average_rating'] >= 3 ? 'warning' : 'danger') ?>"><?= number_format($r['average_rating'], 2) ?></span></td>
                    <td><?= htmlspecialchars($r['evaluation_date']) ?></td>
                    <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <tr><td colspan="7" class="text-muted text-center">No evaluations submitted yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
