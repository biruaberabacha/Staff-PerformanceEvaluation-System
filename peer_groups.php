<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('head');
$db = getDB();
$pageTitle = 'Peer Groups';
$user = currentUser();

// Create group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
    $name = trim($_POST['group_name'] ?? '');
    $year = trim($_POST['academic_year'] ?? '');
    if ($name === '') {
        flash('danger', 'Group name required.');
    } else {
        $db->prepare('INSERT INTO peer_groups (group_name, academic_year, created_by) VALUES (?,?,?)')
           ->execute([$name, $year, $user['id']]);
        flash('success', 'Peer group created.');
    }
    header('Location: peer_groups.php');
    exit;
}

// Add member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_member') {
    $gid = (int)$_POST['group_id'];
    $iid = (int)$_POST['instructor_id'];
    try {
        $db->prepare('INSERT INTO peer_group_members (group_id, instructor_id) VALUES (?,?)')->execute([$gid, $iid]);
        flash('success', 'Instructor added to group.');
    } catch (Exception $e) {
        flash('danger', 'Already in group or invalid.');
    }
    header('Location: peer_groups.php');
    exit;
}

// Remove member
if (isset($_GET['remove_member'])) {
    $db->prepare('DELETE FROM peer_group_members WHERE id = ?')->execute([(int)$_GET['remove_member']]);
    flash('success', 'Member removed.');
    header('Location: peer_groups.php');
    exit;
}

// Delete group
if (isset($_GET['delete_group'])) {
    $db->prepare('DELETE FROM peer_groups WHERE id = ?')->execute([(int)$_GET['delete_group']]);
    flash('success', 'Group deleted.');
    header('Location: peer_groups.php');
    exit;
}

$groups = $db->query('SELECT * FROM peer_groups ORDER BY created_at DESC')->fetchAll();
$instructors = $db->query("
    SELECT i.id, u.full_name, i.title
    FROM instructors i JOIN users u ON u.id = i.user_id
    WHERE u.is_active = 1 ORDER BY u.full_name
")->fetchAll();

// members per group
$members = [];
foreach ($groups as $g) {
    $stmt = $db->prepare("
        SELECT pgm.id AS membership_id, i.id AS instructor_id, u.full_name, i.title
        FROM peer_group_members pgm
        JOIN instructors i ON i.id = pgm.instructor_id
        JOIN users u ON u.id = i.user_id
        WHERE pgm.group_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$g['id']]);
    $members[$g['id']] = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3"></i> Peer Groups</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-lg"></i> Create Group
    </button>
</div>

<p class="text-muted">Instructors in the same peer group evaluate each other (Peer weight = 20%).</p>

<?php if (!$groups): ?>
    <div class="alert alert-secondary">No peer groups yet. Create one and add instructors.</div>
<?php endif; ?>

<div class="row g-4">
<?php foreach ($groups as $g): ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= htmlspecialchars($g['group_name']) ?></strong>
                    <?php if ($g['academic_year']): ?>
                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars($g['academic_year']) ?></span>
                    <?php endif; ?>
                </div>
                <a href="?delete_group=<?= $g['id'] ?>" class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Delete this group?')">Delete</a>
            </div>
            <div class="card-body">
                <h6 class="text-muted">Members (<?= count($members[$g['id']]) ?>)</h6>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($members[$g['id']] as $m): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <?= htmlspecialchars(trim($m['title'] . ' ' . $m['full_name'])) ?>
                            <a href="?remove_member=<?= $m['membership_id'] ?>" class="btn btn-sm btn-outline-danger">Remove</a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$members[$g['id']]): ?>
                        <li class="list-group-item text-muted px-0">No members yet</li>
                    <?php endif; ?>
                </ul>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                    <div class="col">
                        <select name="instructor_id" class="form-select form-select-sm" required>
                            <option value="">Add instructor…</option>
                            <?php foreach ($instructors as $ins): ?>
                                <option value="<?= $ins['id'] ?>">
                                    <?= htmlspecialchars(trim($ins['title'] . ' ' . $ins['full_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_group">
            <div class="modal-header">
                <h5 class="modal-title">Create Peer Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Group Name <span class="text-danger">*</span></label>
                    <input type="text" name="group_name" class="form-control" required placeholder="e.g. Group 1 / Year-1 Peers">
                </div>
                <div class="mb-3">
                    <label class="form-label">Academic Year</label>
                    <input type="text" name="academic_year" class="form-control" placeholder="2025/26">
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
