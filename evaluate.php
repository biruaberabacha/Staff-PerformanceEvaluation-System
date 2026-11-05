<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$db = getDB();
$pageTitle = 'Evaluate';
$criteriaGrouped = getCriteriaGrouped();

// Build list of people the current user is allowed to evaluate
$targets = []; // [ instructor_id, display_name, course_id?, course_label? ]

if ($user['role'] === 'head') {
    $rows = $db->query("
        SELECT i.id, u.full_name, i.title
        FROM instructors i JOIN users u ON u.id = i.user_id
        WHERE u.is_active = 1 ORDER BY u.full_name
    ")->fetchAll();
    foreach ($rows as $r) {
        $targets[] = [
            'instructor_id' => $r['id'],
            'name' => trim($r['title'] . ' ' . $r['full_name']),
            'course_id' => null,
            'label' => 'Head evaluation',
        ];
    }
    $evaluatorType = 'head';
} elseif ($user['role'] === 'instructor') {
    $iid = $user['instructor_id'] ?? 0;
    $stmt = $db->prepare("
        SELECT DISTINCT i.id, u.full_name, i.title
        FROM peer_group_members me
        JOIN peer_group_members other ON other.group_id = me.group_id AND other.instructor_id != me.instructor_id
        JOIN instructors i ON i.id = other.instructor_id
        JOIN users u ON u.id = i.user_id
        WHERE me.instructor_id = ? AND u.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$iid]);
    foreach ($stmt->fetchAll() as $r) {
        // skip if already evaluated
        $chk = $db->prepare("SELECT 1 FROM evaluations WHERE instructor_id=? AND evaluator_user_id=? AND evaluator_type='peer'");
        $chk->execute([$r['id'], $user['id']]);
        if ($chk->fetch()) continue;
        $targets[] = [
            'instructor_id' => $r['id'],
            'name' => trim($r['title'] . ' ' . $r['full_name']),
            'course_id' => null,
            'label' => 'Peer evaluation',
        ];
    }
    $evaluatorType = 'peer';
} else { // student
    $batch = $user['batch'] ?? '';
    $stmt = $db->prepare("
        SELECT ca.instructor_id, ca.course_id, c.course_code, c.course_title,
               u.full_name, i.title
        FROM course_assignments ca
        JOIN courses c ON c.id = ca.course_id
        JOIN instructors i ON i.id = ca.instructor_id
        JOIN users u ON u.id = i.user_id
        WHERE ca.batch = ? AND u.is_active = 1
        ORDER BY u.full_name, c.course_code
    ");
    $stmt->execute([$batch]);
    foreach ($stmt->fetchAll() as $r) {
        $chk = $db->prepare("SELECT 1 FROM evaluations WHERE instructor_id=? AND evaluator_user_id=? AND evaluator_type='student' AND course_id=?");
        $chk->execute([$r['instructor_id'], $user['id'], $r['course_id']]);
        if ($chk->fetch()) continue;
        $targets[] = [
            'instructor_id' => $r['instructor_id'],
            'name' => trim($r['title'] . ' ' . $r['full_name']),
            'course_id' => $r['course_id'],
            'label' => $r['course_code'] . ' – ' . $r['course_title'],
        ];
    }
    $evaluatorType = 'student';
}

$errors = [];
$old = $_POST ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instructor_id = (int)($_POST['instructor_id'] ?? 0);
    $course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $strengths = trim($_POST['strengths'] ?? '');
    $improvements = trim($_POST['improvements'] ?? '');
    $ratings = $_POST['rating'] ?? [];

    // Validate target is allowed
    $allowed = false;
    foreach ($targets as $t) {
        if ($t['instructor_id'] == $instructor_id &&
            (($course_id === null && $t['course_id'] === null) || $t['course_id'] == $course_id)) {
            $allowed = true;
            break;
        }
    }
    // For head, allow any (targets list may be empty after previous submits in same request - re-fetch)
    if ($user['role'] === 'head' && $instructor_id > 0) $allowed = true;

    if (!$allowed || $instructor_id <= 0) $errors[] = 'Invalid instructor selected.';
    if (count($ratings) < 16) $errors[] = 'Please rate all 16 criteria.';
    foreach ($ratings as $cid => $val) {
        if (!in_array((string)$val, ['0','1','2','3','4','5'], true)) {
            $errors[] = "Invalid rating for criterion #$cid.";
            break;
        }
    }

    // Cannot evaluate yourself
    if ($user['role'] === 'instructor' && ($user['instructor_id'] ?? 0) == $instructor_id) {
        $errors[] = 'You cannot evaluate yourself.';
    }

    if (empty($errors)) {
        $score = calculateScore($ratings);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO evaluations
                (instructor_id, evaluator_user_id, evaluator_type, course_id, academic_year, semester,
                 strengths, improvements, total_score, average_rating)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $instructor_id, $user['id'], $evaluatorType, $course_id,
                $academic_year, $semester, $strengths, $improvements,
                $score['total'], $score['average']
            ]);
            $evalId = $db->lastInsertId();
            $rStmt = $db->prepare('INSERT INTO evaluation_ratings (evaluation_id, criterion_id, rating) VALUES (?,?,?)');
            foreach ($ratings as $cid => $val) {
                $rStmt->execute([$evalId, (int)$cid, (int)$val]);
            }
            $db->commit();
            flash('success', 'Evaluation submitted successfully.');
            header('Location: view.php?id=' . $evalId);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = 'You have already submitted an evaluation for this instructor/course.';
            } else {
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">
            <i class="bi bi-pencil-square me-2"></i>
            Teachers’ Performance Evaluation Form
            <span class="badge bg-light text-dark ms-2"><?= ucfirst($evaluatorType) ?> evaluation</span>
        </h4>
    </div>
    <div class="card-body">
        <?php if (empty($targets) && $user['role'] !== 'head'): ?>
            <div class="alert alert-info mb-0">
                <?php if ($user['role'] === 'instructor'): ?>
                    No pending peer evaluations. Either you are not in a peer group, or you have already evaluated all peers.
                <?php else: ?>
                    No teachers assigned to your batch, or you have already evaluated all of them.
                <?php endif; ?>
            </div>
        <?php else: ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
        <?php endif; ?>

        <form method="post" id="evalForm">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Instructor to evaluate <span class="text-danger">*</span></label>
                    <select name="instructor_id" id="targetSelect" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php
                        // For head: simple list
                        if ($user['role'] === 'head'):
                            $allIns = $db->query("SELECT i.id, u.full_name, i.title FROM instructors i JOIN users u ON u.id=i.user_id WHERE u.is_active=1 ORDER BY u.full_name")->fetchAll();
                            foreach ($allIns as $r):
                        ?>
                            <option value="<?= $r['id'] ?>" data-course=""
                                <?= ($old['instructor_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(trim($r['title'].' '.$r['full_name'])) ?>
                            </option>
                        <?php endforeach; else:
                            foreach ($targets as $t):
                        ?>
                            <option value="<?= $t['instructor_id'] ?>"
                                    data-course="<?= $t['course_id'] ?? '' ?>"
                                <?= ($old['instructor_id'] ?? '') == $t['instructor_id'] && ($old['course_id'] ?? '') == ($t['course_id'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                                <?php if ($t['label']): ?> (<?= htmlspecialchars($t['label']) ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>
                    <input type="hidden" name="course_id" id="courseId" value="<?= htmlspecialchars($old['course_id'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Academic Year</label>
                    <input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars($old['academic_year'] ?? '2025/26') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Semester</label>
                    <input type="text" name="semester" class="form-control" value="<?= htmlspecialchars($old['semester'] ?? '') ?>">
                </div>
            </div>

            <p class="text-muted small mb-3">
                <strong>Rating Scale:</strong>
                5 = Excellent &nbsp; 4 = Very Good &nbsp; 3 = Good &nbsp; 2 = Fair &nbsp; 1 = Poor &nbsp; 0 = Unsatisfactory
                <br>Click a number for each criterion (required).
            </p>

            <?php foreach ($criteriaGrouped as $cat => $group): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered criteria-table">
                        <thead>
                            <tr class="category-header">
                                <th colspan="3"><?= $cat ?>. <?= htmlspecialchars($group['name']) ?></th>
                            </tr>
                            <tr>
                                <th style="width:50px">No.</th>
                                <th>Evaluation Criteria</th>
                                <th style="width:260px" class="text-center">Rating (0 – 5)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($group['items'] as $item): ?>
                            <tr>
                                <td class="text-center"><?= $item['id'] ?></td>
                                <td><?= htmlspecialchars($item['criterion_text']) ?></td>
                                <td>
                                    <div class="rating-group" data-cid="<?= $item['id'] ?>">
                                        <?php for ($n = 5; $n >= 0; $n--): ?>
                                            <input type="radio"
                                                   name="rating[<?= $item['id'] ?>]"
                                                   id="r<?= $item['id'] ?>_<?= $n ?>"
                                                   value="<?= $n ?>"
                                                   required
                                                   <?= (isset($old['rating'][$item['id']]) && (string)$old['rating'][$item['id']] === (string)$n) ? 'checked' : '' ?>>
                                            <label for="r<?= $item['id'] ?>_<?= $n ?>"><?= $n ?></label>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light"><div class="card-body py-2">
                        <strong>Total Score:</strong> <span id="totalScore" class="fs-4 text-primary">0</span> / 80
                    </div></div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light"><div class="card-body py-2">
                        <strong>Average:</strong> <span id="averageRating" class="fs-4 text-success">0.00</span>
                    </div></div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Strengths of the Instructor</label>
                <textarea name="strengths" class="form-control" rows="2"><?= htmlspecialchars($old['strengths'] ?? '') ?></textarea>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Areas for Improvement / Recommendations</label>
                <textarea name="improvements" class="form-control" rows="2"><?= htmlspecialchars($old['improvements'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle"></i> Submit Evaluation</button>
            <a href="index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('targetSelect')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('courseId').value = opt.dataset.course || '';
});
// init course id
(function(){
    const sel = document.getElementById('targetSelect');
    if (sel && sel.selectedIndex > 0) {
        document.getElementById('courseId').value = sel.options[sel.selectedIndex].dataset.course || '';
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
