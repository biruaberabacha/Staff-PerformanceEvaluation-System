<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Final Weighted Report';

$year = trim($_GET['year'] ?? '');

$instructors = $db->query("
    SELECT i.id, u.full_name, i.title
    FROM instructors i JOIN users u ON u.id = i.user_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$report = [];
foreach ($instructors as $ins) {
    $score = getWeightedScore($ins['id'], $year ?: null);
    $report[] = [
        'instructor' => $ins,
        'score' => $score,
    ];
}

usort($report, function ($a, $b) {
    $fa = $a['score']['final_score'] ?? -1;
    $fb = $b['score']['final_score'] ?? -1;
    return $fb <=> $fa;
});

/** Convert 0–5 average to 0–100 percent */
function toPercent($avg) {
    if ($avg === null) return null;
    return round(((float)$avg / 5) * 100, 1);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="mb-0"><i class="bi bi-bar-chart"></i> Final Weighted Performance Report</h4>
    <a href="letter.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-envelope-paper"></i> Result Letter</a>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="alert alert-primary">
    <strong>Weighting formula (result out of 100%):</strong><br>
    Final % =
    (Head Average × <strong>30%</strong>) +
    (Peer Average × <strong>20%</strong>) +
    (Student Average × <strong>50%</strong>),
    then scaled from 0–5 → <strong>0–100%</strong>
    <br>
    <small class="text-muted">
        Example: if weighted average on 0–5 scale is 4.20 → Final = 4.20 ÷ 5 × 100 = <strong>84%</strong>.
        Missing components are renormalized using available data only.
    </small>
</div>

<form method="get" class="row g-2 mb-4 no-print">
    <div class="col-auto">
        <input type="text" name="year" class="form-control form-control-sm" placeholder="Filter academic year" value="<?= htmlspecialchars($year) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        <a href="report.php" class="btn btn-sm btn-outline-secondary">All</a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Instructor</th>
                    <th class="text-center">Head %<br><small class="text-muted">weight 30%</small></th>
                    <th class="text-center">Peer %<br><small class="text-muted">weight 20%</small></th>
                    <th class="text-center">Student %<br><small class="text-muted">weight 50%</small></th>
                    <th class="text-center">Final Score<br><small class="text-muted">out of 100%</small></th>
                    <th class="text-center">Counts<br><small>H / P / S</small></th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
            <?php $rank = 1; foreach ($report as $row):
                $s = $row['score'];
                $ins = $row['instructor'];
                $headPct = toPercent($s['head_avg']);
                $peerPct = toPercent($s['peer_avg']);
                $stuPct  = toPercent($s['student_avg']);
                $finalPct = toPercent($s['final_score']);
            ?>
                <tr>
                    <td><?= $rank++ ?></td>
                    <td><?= htmlspecialchars(trim($ins['title'] . ' ' . $ins['full_name'])) ?></td>
                    <td class="text-center">
                        <?php if ($headPct !== null): ?>
                            <span class="badge bg-primary"><?= number_format($headPct, 1) ?>%</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($peerPct !== null): ?>
                            <span class="badge bg-info text-dark"><?= number_format($peerPct, 1) ?>%</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($stuPct !== null): ?>
                            <span class="badge bg-success"><?= number_format($stuPct, 1) ?>%</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($finalPct !== null): ?>
                            <span class="fs-5 fw-bold text-<?= $finalPct >= 80 ? 'success' : ($finalPct >= 60 ? 'warning' : 'danger') ?>">
                                <?= number_format($finalPct, 1) ?>%
                            </span>
                        <?php else: ?>
                            <span class="text-muted">No data</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center small text-muted">
                        <?= $s['head_count'] ?> / <?= $s['peer_count'] ?> / <?= $s['student_count'] ?>
                    </td>
                    <td class="text-center no-print">
                        <a href="letter.php?instructor_id=<?= (int)$ins['id'] ?>&year=<?= urlencode($year) ?>"
                           class="btn btn-sm btn-outline-primary">Letter</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$report): ?>
                <tr><td colspan="8" class="text-center text-muted">No instructors found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3">
    Counts: number of evaluations (Head / Peer / Student).
    Component % columns are each component’s average converted to percent (avg÷5×100).
    <strong>Final Score</strong> applies the 30% / 20% / 50% weights, then converts to <strong>out of 100%</strong>.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
