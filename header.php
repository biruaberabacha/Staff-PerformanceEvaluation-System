<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Teacher Evaluation') ?> | Ambo University</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            <i class="bi bi-mortarboard-fill me-2"></i>Ambo Univ. Evaluation
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a></li>

                <?php if ($user['role'] === 'head'): ?>
                    <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Users</a></li>
                    <li class="nav-item"><a class="nav-link" href="peer_groups.php"><i class="bi bi-diagram-3"></i> Peer Groups</a></li>
                    <li class="nav-item"><a class="nav-link" href="courses.php"><i class="bi bi-journal-bookmark"></i> Courses</a></li>
                    <li class="nav-item"><a class="nav-link" href="evaluate.php"><i class="bi bi-pencil-square"></i> Evaluate</a></li>
                    <li class="nav-item"><a class="nav-link" href="progress.php"><i class="bi bi-clipboard-check"></i> Progress</a></li>
                    <li class="nav-item"><a class="nav-link" href="report.php"><i class="bi bi-bar-chart"></i> Final Report</a></li>
                    <li class="nav-item"><a class="nav-link" href="letter.php"><i class="bi bi-envelope-paper"></i> Result Letter</a></li>
                <?php elseif ($user['role'] === 'instructor'): ?>
                    <li class="nav-item"><a class="nav-link" href="evaluate.php"><i class="bi bi-pencil-square"></i> Peer Evaluate</a></li>
                    <li class="nav-item"><a class="nav-link" href="my_evaluations.php"><i class="bi bi-list-check"></i> My Evaluations</a></li>
                <?php elseif ($user['role'] === 'student'): ?>
                    <li class="nav-item"><a class="nav-link" href="evaluate.php"><i class="bi bi-pencil-square"></i> Evaluate Teacher</a></li>
                    <li class="nav-item"><a class="nav-link" href="my_evaluations.php"><i class="bi bi-list-check"></i> My Submissions</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($user['full_name']) ?>
                        <span class="badge bg-light text-dark ms-1"><?= ucfirst($user['role']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container py-4">
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
