<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Evaluation Progress';

$year = trim($_GET['year'] ?? '');

/**
 * Status helper: no / partially / done
 */
function statusBadge(int $done, int $expected): array
{
    if ($expected <= 0) {
        // nothing assigned/expected
        if ($done > 0) {
            return ['label' => 'Done', 'class' => 'success', 'detail' => (string)$done];
        }
        return ['label' => 'No', 'class' => 'secondary', 'detail' => '0'];
    }
    if ($done <= 0) {
        return ['label' => 'No', 'class' => 'danger', 'detail' => "0 / $expected"];
    }
    if ($done >= $expected) {
        return ['label' => 'Done', 'class' => 'success', 'detail' => "$done / $expected"];
    }
    return ['label' => 'Partially', 'class' => 'warning', 'detail' => "$done / $expected"];
}

$instructors = $db->query("
    SELECT i.id, u.full_name, i.title
    FROM instructors i
    JOIN users u ON u.id = i.user_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$rows = [];
foreach ($instructors as $ins) {
    $iid = (int)$ins['id'];

    // ---- Head expected = 1 if we consider head should evaluate; done = count of head evals
    $headDoneStmt = $db->prepare("
        SELECT COUNT(*) FROM evaluations
        WHERE instructor_id = ? AND evaluator_type = 'head'
        " . ($year !== '' ? ' AND academic_year = ?' : '')
    );
    $headDoneStmt->execute($year !== '' ? [$iid, $year] : [$iid]);
    $headDone = (int)$headDoneStmt->fetchColumn();
    $headExpected = 1; // one department-head evaluation expected
    $headStatus = statusBadge($headDone, $headExpected);

    // ---- Peer: expected = other members in same peer groups
    $peerExpStmt = $db->prepare("
        SELECT COUNT(DISTINCT other.instructor_id)
        FROM peer_group_members me
        JOIN peer_group_members other
          ON other.group_id = me.group_id AND other.instructor_id != me.instructor_id
        JOIN instructors i2 ON i2.id = other.instructor_id
        JOIN users u2 ON u2.id = i2.user_id AND u2.is_active = 1
        WHERE me.instructor_id = ?
    ");
    $peerExpStmt->execute([$iid]);
    $peerExpected = (int)$peerExpStmt->fetchColumn();

    $peerDoneStmt = $db->prepare("
        SELECT COUNT(*) FROM evaluations
        WHERE instructor_id = ? AND evaluator_type = 'peer'
        " . ($year !== '' ? ' AND academic_year = ?' : '')
    );
    $peerDoneStmt->execute($year !== '' ? [$iid, $year] : [$iid]);
    $peerDone = (int)$peerDoneStmt->fetchColumn();
    $peerStatus = statusBadge($peerDone, $peerExpected);

    // ---- Students: expected = active students whose batch matches any course assignment of this instructor
    $stuExpStmt = $db->prepare("
        SELECT COUNT(DISTINCT s.user_id)
        FROM course_assignments ca
        JOIN students s ON s.batch = ca.batch
        JOIN users u ON u.id = s.user_id AND u.is_active = 1
        WHERE ca.instructor_id = ?
    ");
    $stuExpStmt->execute([$iid]);
    $stuExpected = (int)$stuExpStmt->fetchColumn();

    $stuDoneStmt = $db->prepare("
        SELECT COUNT(DISTINCT evaluator_user_id) FROM evaluations
        WHERE instructor_id = ? AND evaluator_type = 'student'
        " . ($year !== '' ? ' AND academic_year = ?' : '')
    );
    $stuDoneStmt->execute($year !== '' ? [$iid, $year] : [$iid]);
    $stuDone = (int)$stuDoneStmt->fetchColumn();
    $stuStatus = statusBadge($stuDone, $stuExpected);

    // Overall: Done only if all three components that have expected > 0 are Done
    $components = [];
    $components[] = $headStatus['label'];
    if ($peerExpected > 0) $components[] = $peerStatus['label'];
    if ($stuExpected > 0) $components[] = $stuStatus['label'];

    if (in_array('No', $components, true) && !in_array('Done', $components, true) && !in_array('Partially', $components, true)) {
        $overall = ['label' => 'No', 'class' => 'danger'];
    } elseif (!in_array('No', $components, true) && !in_array('Partially', $components, true)) {
        $overall = ['label' => 'Done', 'class' => 'success'];
    } elseif (in_array('Done', $components, true) || in_array('Partially', $components, true)) {
        // mix of done/partial/no
        if (!in_array('No', $components, true) && !in_array('Partially', $components, true)) {
            $overall = ['label' => 'Done', 'class' => 'success'];
        } elseif (count(array_unique($components)) === 1 && $components[0] === 'No') {
            $overall = ['label' => 'No', 'class' => 'danger'];
        } else {
            $overall = ['label' => 'Partially', 'class' => 'warning'];
        }
    } else {
        $overall = ['label' => 'Partially', 'class' => 'warning'];
    }

    // Who evaluated (names)
    $whoStmt = $db->prepare("
        SELECT e.evaluator_type, u.full_name, e.evaluation_date, c.course_code
        FROM evaluations e
        JOIN users u ON u.id = e.evaluator_user_id
        LEFT JOIN courses c ON c.id = e.course_id
        WHERE e.instructor_id = ?
        " . ($year !== '' ? ' AND e.academic_year = ?' : '') . "
        ORDER BY e.evaluator_type, u.full_name
    ");
    $whoStmt->execute($year !== '' ? [$iid, $year] : [$iid]);
    $who = $whoStmt->fetchAll();

    $rows[] = [
        'instructor' => $ins,
        'head' => $headStatus,
        'peer' => $peerStatus,
        'student' => $stuStatus,
        'overall' => $overall,
        'who' => $who,
        'peer_expected' => $peerExpected,
        'stu_expected' => $stuExpected,
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-clipboard-check"></i> Evaluation Progress</h4>
    <form method="get" class="d-flex gap-2">
        <input type="text" name="year" class="form-control form-control-sm" placeholder="Academic year" value="<?= htmlspecialchars($year) ?>">
        <button class="btn btn-sm btn-primary">Filter</button>
        <a href="progress.php" class="btn btn-sm btn-outline-secondary">All</a>
    </form>
</div>

<div class="alert alert-secondary small">
    <strong>Status meaning:</strong>
    <span class="badge bg-danger">No</span> no evaluations yet &nbsp;
    <span class="badge bg-warning text-dark">Partially</span> some but not all expected evaluators &nbsp;
    <span class="badge bg-success">Done</span> all expected evaluations received
    <br>
    <strong>Expected:</strong> Head = 1 · Peer = other members in same peer group · Students = active students in assigned course batches
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Instructor</th>
                    <th class="text-center">Head (30%)</th>
                    <th class="text-center">Peer (20%)</th>
                    <th class="text-center">Students (50%)</th>
                    <th class="text-center">Overall</th>
                    <th>Who evaluated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $ins = $r['instructor'];
            ?>
                <tr>
                    <td><?= htmlspecialchars(trim($ins['title'] . ' ' . $ins['full_name'])) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['head']['class'] ?>"><?= $r['head']['label'] ?></span>
                        <div class="small text-muted"><?= htmlspecialchars($r['head']['detail']) ?></div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['peer']['class'] ?>"><?= $r['peer']['label'] ?></span>
                        <div class="small text-muted"><?= htmlspecialchars($r['peer']['detail']) ?></div>
                        <?php if ($r['peer_expected'] === 0): ?>
                            <div class="small text-muted">No peer group</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['student']['class'] ?>"><?= $r['student']['label'] ?></span>
                        <div class="small text-muted"><?= htmlspecialchars($r['student']['detail']) ?></div>
                        <?php if ($r['stu_expected'] === 0): ?>
                            <div class="small text-muted">No course/batch</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['overall']['class'] ?> fs-6"><?= $r['overall']['label'] ?></span>
                    </td>
                    <td class="small">
                        <?php if (!$r['who']): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($r['who'] as $w): ?>
                                    <li>
                                        <span class="text-capitalize"><?= htmlspecialchars($w['evaluator_type']) ?></span>:
                                        <?= htmlspecialchars($w['full_name']) ?>
                                        <?php if ($w['course_code']): ?>
                                            <span class="text-muted">(<?= htmlspecialchars($w['course_code']) ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <a href="letter.php?instructor_id=<?= (int)$ins['id'] ?>&year=<?= urlencode($year) ?>" class="btn btn-sm btn-outline-primary">Letter</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted">No instructors found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
