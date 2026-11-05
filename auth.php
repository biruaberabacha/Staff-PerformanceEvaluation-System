<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void
{
    if (!currentUser()) {
        header('Location: login.php');
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Access denied.'];
        header('Location: index.php');
        exit;
    }
}

function login(string $username, string $password): bool
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        // Attach instructor_id or student_id if applicable
        if ($user['role'] === 'instructor') {
            $ins = $db->prepare('SELECT id FROM instructors WHERE user_id = ?');
            $ins->execute([$user['id']]);
            $user['instructor_id'] = $ins->fetchColumn() ?: null;
        } elseif ($user['role'] === 'student') {
            $st = $db->prepare('SELECT id, batch FROM students WHERE user_id = ?');
            $st->execute([$user['id']]);
            $s = $st->fetch();
            $user['student_id'] = $s['id'] ?? null;
            $user['batch'] = $s['batch'] ?? null;
        }
        unset($user['password']);
        $_SESSION['user'] = $user;
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
