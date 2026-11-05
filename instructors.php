<?php
require_once __DIR__ . '/../includes/db.php';
$pageTitle = 'Instructors';
$db = getDB();

$errors = [];

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $department = trim($_POST['department'] ?? 'IT Department');
    $email = trim($_POST['email'] ?? '');

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }

    if (empty($errors)) {
        if ($action === 'add') {
            $stmt = $db->prepare('INSERT INTO instructors (full_name, title, department, email) VALUES (?, ?, ?, ?)');
            $stmt->execute([$full_name, $title, $department, $email]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Instructor added.'];
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare('UPDATE instructors SET full_name=?, title=?, department=?, email=? WHERE id=?');
            $stmt->execute([$full_name, $title, $department, $email, $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Instructor updated.'];
        }
        header('Location: instructors.php');
        exit;
    }
}

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare('DELETE FROM instructors WHERE id = ?')->execute([$id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Instructor deleted.'];
    header('Location: instructors.php');
    exit;
}

$list = $db->query("
    SELECT i.*,
           (SELECT COUNT(*) FROM evaluations e WHERE e.instructor_id = i.id) AS eval_count,
           (SELECT ROUND(AVG(average_rating), 2) FROM evaluations e WHERE e.instructor_id = i.id) AS avg_rating
    FROM instructors i
    ORDER BY i.full_name
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Instructors</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg"></i> Add Instructor
    </button>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Evaluations</th>
                        <th>Avg Rating</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $i): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(trim($i['title'] . ' ' . $i['full_name'])) ?>
                            </td>
                            <td><?= htmlspecialchars($i['department']) ?></td>
                            <td><?= htmlspecialchars($i['email'] ?: '—') ?></td>
                            <td><?= (int)$i['eval_count'] ?></td>
                            <td>
                                <?php if ($i['avg_rating'] !== null): ?>
                                    <span class="badge bg-<?= $i['avg_rating'] >= 4 ? 'success' : ($i['avg_rating'] >= 3 ? 'warning' : 'danger') ?>">
                                        <?= number_format($i['avg_rating'], 2) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <button class="btn btn-sm btn-outline-primary edit-btn"
                                        data-id="<?= $i['id'] ?>"
                                        data-name="<?= htmlspecialchars($i['full_name']) ?>"
                                        data-title="<?= htmlspecialchars($i['title']) ?>"
                                        data-dept="<?= htmlspecialchars($i['department']) ?>"
                                        data-email="<?= htmlspecialchars($i['email']) ?>">
                                    Edit
                                </button>
                                <a href="?delete=<?= $i['id'] ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this instructor and all related evaluations?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add Instructor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" placeholder="Dr. / Mr. / Mrs.">
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Department</label>
                    <input type="text" name="department" class="form-control" value="IT Department">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="modal-header">
                <h5 class="modal-title">Edit Instructor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" id="editTitle" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" id="editName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Department</label>
                    <input type="text" name="department" id="editDept" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="editEmail" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('editId').value = btn.dataset.id;
        document.getElementById('editName').value = btn.dataset.name;
        document.getElementById('editTitle').value = btn.dataset.title;
        document.getElementById('editDept').value = btn.dataset.dept;
        document.getElementById('editEmail').value = btn.dataset.email;
        new bootstrap.Modal(document.getElementById('editModal')).show();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
