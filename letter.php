<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Result Letter';
$head = currentUser();

function toPercent($avg) {
    if ($avg === null) return null;
    return round(((float)$avg / 5) * 100, 1);
}

function performanceLabel(?float $pct): string {
    if ($pct === null) return 'Not Available';
    if ($pct >= 90) return 'Outstanding';
    if ($pct >= 80) return 'Very Good';
    if ($pct >= 70) return 'Good';
    if ($pct >= 60) return 'Satisfactory';
    if ($pct >= 50) return 'Fair';
    return 'Needs Improvement';
}

$instructors = $db->query("
    SELECT i.id, u.full_name, i.title, i.department, i.school, i.university
    FROM instructors i JOIN users u ON u.id = i.user_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$instructorId = (int)($_GET['instructor_id'] ?? $_POST['instructor_id'] ?? 0);
$year = trim($_GET['year'] ?? $_POST['year'] ?? '');
$preview = isset($_GET['preview']) || ($_SERVER['REQUEST_METHOD'] === 'POST');

$refNo       = trim($_POST['ref_no'] ?? ('IT/Eval/' . date('Y') . '/' . str_pad((string)$instructorId, 3, '0', STR_PAD_LEFT)));
$letterDate  = trim($_POST['letter_date'] ?? date('Y-m-d'));
$deanLine    = trim($_POST['dean_line'] ?? 'Dean, School of Informatics and Electrical Engineering');
$hrmLine     = trim($_POST['hrm_line'] ?? 'Director, Human Resource Management');
$subject     = trim($_POST['subject'] ?? 'Performance Evaluation Result');
$remarks     = trim($_POST['remarks'] ?? '');
$signName    = trim($_POST['sign_name'] ?? $head['full_name']);
$signTitle   = trim($_POST['sign_title'] ?? 'Department Head, IT Department');

$selected = null;
$score = null;
$finalPct = null;
$headPct = $peerPct = $stuPct = null;

if ($instructorId > 0) {
    foreach ($instructors as $ins) {
        if ((int)$ins['id'] === $instructorId) {
            $selected = $ins;
            break;
        }
    }
    if ($selected) {
        $score = getWeightedScore($instructorId, $year ?: null);
        $headPct  = toPercent($score['head_avg']);
        $peerPct  = toPercent($score['peer_avg']);
        $stuPct   = toPercent($score['student_avg']);
        $finalPct = toPercent($score['final_score']);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="mb-0"><i class="bi bi-envelope-paper"></i> Result Letter</h4>
    <a href="report.php" class="btn btn-outline-secondary btn-sm">Back to Report</a>
</div>

<div class="row g-4">
    <div class="col-lg-4 no-print">
        <div class="card">
            <div class="card-header bg-white"><strong>Letter details</strong></div>
            <div class="card-body">
                <form method="post" action="letter.php?preview=1">
                    <div class="mb-2">
                        <label class="form-label small">Instructor <span class="text-danger">*</span></label>
                        <select name="instructor_id" class="form-select form-select-sm" required>
                            <option value="">— Select teacher —</option>
                            <?php foreach ($instructors as $ins): ?>
                                <option value="<?= $ins['id'] ?>" <?= $instructorId === (int)$ins['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim($ins['title'] . ' ' . $ins['full_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Academic Year</label>
                        <input type="text" name="year" class="form-control form-control-sm" value="<?= htmlspecialchars($year) ?>" placeholder="e.g. 2025/26">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Ref. No.</label>
                            <input type="text" name="ref_no" class="form-control form-control-sm" value="<?= htmlspecialchars($refNo) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Date</label>
                            <input type="date" name="letter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($letterDate) ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Subject</label>
                        <input type="text" name="subject" class="form-control form-control-sm" value="<?= htmlspecialchars($subject) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">CC – Dean</label>
                        <input type="text" name="dean_line" class="form-control form-control-sm" value="<?= htmlspecialchars($deanLine) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">CC – HRM</label>
                        <input type="text" name="hrm_line" class="form-control form-control-sm" value="<?= htmlspecialchars($hrmLine) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Remarks (optional)</label>
                        <textarea name="remarks" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($remarks) ?></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Signatory</label>
                            <input type="text" name="sign_name" class="form-control form-control-sm" value="<?= htmlspecialchars($signName) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Title</label>
                            <input type="text" name="sign_title" class="form-control form-control-sm" value="<?= htmlspecialchars($signTitle) ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-eye"></i> Generate / Preview
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($preview && $selected && $score): ?>
            <div class="d-flex gap-2 mb-2 no-print">
                <button onclick="window.print()" class="btn btn-success btn-sm">
                    <i class="bi bi-printer"></i> Print (1 page)
                </button>
            </div>
            <div class="card letter-paper" id="letterPrint">
                <div class="card-body letter-body">
                    <div class="text-center letter-header">
                        <div class="fw-bold"><?= htmlspecialchars($selected['university'] ?? 'AMBO UNIVERSITY') ?></div>
                        <div><?= htmlspecialchars($selected['school'] ?? 'School of Informatics and Electrical Engineering') ?></div>
                        <div><?= htmlspecialchars($selected['department'] ?? 'IT Department') ?></div>
                        <div class="text-muted">Office of the Department Head</div>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between letter-meta">
                        <div><strong>Ref. No:</strong> <?= htmlspecialchars($refNo) ?></div>
                        <div><strong>Date:</strong> <?= htmlspecialchars(date('d M Y', strtotime($letterDate))) ?></div>
                    </div>

                    <p class="mb-1"><strong>To:</strong> <?= htmlspecialchars(trim($selected['title'] . ' ' . $selected['full_name'])) ?></p>
                    <p class="mb-1"><strong>Subject:</strong> <?= htmlspecialchars($subject) ?></p>

                    <p class="mb-1">Dear <?= htmlspecialchars(trim($selected['title'] . ' ' . $selected['full_name'])) ?>,</p>

                    <p class="mb-2">
                        This letter communicates the result of your teaching performance evaluation
                        <?php if ($year): ?>for academic year <strong><?= htmlspecialchars($year) ?></strong><?php endif; ?>,
                        based on Department guidelines (Head 30%, Peer 20%, Students 50%).
                    </p>

                    <table class="letter-table">
                        <thead>
                            <tr>
                                <th>Evaluation Component</th>
                                <th class="text-center">Weight</th>
                                <th class="text-center">Score (%)</th>
                                <th class="text-center">Evaluators</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Department Head</td>
                                <td class="text-center">30%</td>
                                <td class="text-center"><?= $headPct !== null ? number_format($headPct, 1) . '%' : '—' ?></td>
                                <td class="text-center"><?= (int)$score['head_count'] ?></td>
                            </tr>
                            <tr>
                                <td>Peer</td>
                                <td class="text-center">20%</td>
                                <td class="text-center"><?= $peerPct !== null ? number_format($peerPct, 1) . '%' : '—' ?></td>
                                <td class="text-center"><?= (int)$score['peer_count'] ?></td>
                            </tr>
                            <tr>
                                <td>Students</td>
                                <td class="text-center">50%</td>
                                <td class="text-center"><?= $stuPct !== null ? number_format($stuPct, 1) . '%' : '—' ?></td>
                                <td class="text-center"><?= (int)$score['student_count'] ?></td>
                            </tr>
                            <tr class="fw-bold">
                                <td colspan="2">Final Result (out of 100%)</td>
                                <td class="text-center"><?= $finalPct !== null ? number_format($finalPct, 1) . '%' : '—' ?></td>
                                <td class="text-center"><?= $finalPct !== null ? performanceLabel($finalPct) : 'N/A' ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ($remarks !== ''): ?>
                        <p class="mb-1"><strong>Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?></p>
                    <?php endif; ?>

                    <p class="mb-2">
                        We appreciate your contribution to teaching and departmental goals.
                        Please continue strengths and address any recommendations from this evaluation.
                    </p>

                    <p class="mb-0">Sincerely,</p>
                    <div class="letter-sign">
                        <strong><?= htmlspecialchars($signName) ?></strong><br>
                        <?= htmlspecialchars($signTitle) ?><br>
                        <?= htmlspecialchars($selected['university'] ?? 'Ambo University') ?>
                    </div>

                    <p class="mb-0 mt-2"><strong>CC:</strong></p>
                    <ol class="letter-cc mb-0">
                        <li><?= htmlspecialchars($deanLine) ?></li>
                        <li><?= htmlspecialchars($hrmLine) ?></li>
                        <li>File</li>
                    </ol>
                </div>
            </div>
        <?php elseif ($preview && !$selected): ?>
            <div class="alert alert-warning">Please select a valid instructor.</div>
        <?php else: ?>
            <div class="alert alert-info">Select an instructor and generate the letter preview.</div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Screen preview */
.letter-body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.35;
    color: #000;
}
.letter-header { font-size: 11pt; line-height: 1.3; }
.letter-header .fw-bold { font-size: 13pt; }
.letter-meta { font-size: 10.5pt; margin-bottom: 0.5rem; }
.letter-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0.4rem 0 0.6rem;
    font-size: 10.5pt;
}
.letter-table th, .letter-table td {
    border: 1px solid #000;
    padding: 3px 6px;
}
.letter-table th { background: #eee; }
.letter-sign { margin-top: 0.75rem; font-size: 11pt; line-height: 1.3; }
.letter-cc { margin-left: 1.2rem; font-size: 10.5pt; }

/* Force single A4 page on print */
@media print {
    @page {
        size: A4;
        margin: 12mm 14mm;
    }
    html, body {
        background: #fff !important;
        height: auto !important;
    }
    .navbar, footer, .no-print, .alert { display: none !important; }
    main.container {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .row, .col-lg-8, .col-lg-4 {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .letter-paper {
        box-shadow: none !important;
        border: none !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .letter-paper .card-body,
    .letter-body {
        padding: 0 !important;
        font-size: 10.5pt !important;
        line-height: 1.28 !important;
    }
    .letter-header { font-size: 10pt !important; }
    .letter-header .fw-bold { font-size: 12pt !important; }
    .letter-table { font-size: 9.5pt !important; margin: 0.25rem 0 0.4rem !important; }
    .letter-table th, .letter-table td { padding: 2px 5px !important; }
    .letter-sign { margin-top: 0.45rem !important; font-size: 10pt !important; }
    .letter-cc { font-size: 9.5pt !important; }
    p { margin-bottom: 0.35rem !important; }
    hr { margin: 0.3rem 0 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
