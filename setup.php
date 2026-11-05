<?php
/**
 * Database setup / repair / reset
 * Open: http://localhost/teacher_evaluation/public/setup.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbPath     = __DIR__ . '/../database/evaluation.db';
$schemaPath = __DIR__ . '/../database/schema.sql';
$action     = $_GET['action'] ?? '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>DB Setup</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem}';
echo 'pre{background:#f5f5f5;padding:1rem;border-radius:8px;overflow:auto}';
echo '.ok{color:#0a0}.err{color:#c00}.btn{display:inline-block;margin:.25rem .5rem .25rem 0;padding:.5rem 1rem;';
echo 'background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;border:0;cursor:pointer;font-size:1rem}';
echo '.btn-danger{background:#dc3545}.btn-secondary{background:#6c757d}</style></head><body>';
echo '<h1>Teacher Evaluation – Database Setup</h1>';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo '<p class="err"><strong>ERROR:</strong> PDO SQLite is not enabled.</p>';
    echo '<p>In XAMPP <code>php.ini</code> enable:</p><pre>extension=pdo_sqlite
extension=sqlite3</pre>';
    echo '<p>Then restart Apache.</p></body></html>';
    exit;
}

if (!file_exists($schemaPath)) {
    echo '<p class="err">schema.sql not found at: ' . h($schemaPath) . '</p></body></html>';
    exit;
}

// ---- RESET: delete DB and recreate ----
if ($action === 'reset') {
    if (file_exists($dbPath)) {
        @unlink($dbPath);
        @unlink($dbPath . '-journal');
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
    }
    echo '<p class="ok">Old database file removed.</p>';
    $action = 'init';
}

try {
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();

    // Detect old schema (instructors without user_id)
    $needsRebuild = false;
    if ($hasUsers) {
        $cols = $pdo->query("PRAGMA table_info(instructors)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        if (!in_array('user_id', $colNames, true)) {
            $needsRebuild = true;
            echo '<p class="err">Old schema detected (instructors.user_id missing). Rebuilding...</p>';
        }
    } else {
        $needsRebuild = true;
    }

    if ($needsRebuild || $action === 'init') {
        if ($hasUsers) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                $pdo->exec("DROP TABLE IF EXISTS \"$t\"");
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            echo '<p>Dropped old tables.</p>';
        }

        $schema = file_get_contents($schemaPath);
        $pdo->exec($schema);
        echo '<p class="ok">Schema applied successfully.</p>';

        $hash = password_hash('head123', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ? WHERE username = ?')->execute([$hash, 'head']);
        $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE username='head'")->fetchColumn();
        if (!$exists) {
            $pdo->prepare('INSERT INTO users (username, password, full_name, role, email) VALUES (?,?,?,?,?)')
                ->execute(['head', $hash, 'Department Head', 'head', 'head@ambo.edu.et']);
        }
        echo '<p class="ok">Head account ready: <code>head</code> / <code>head123</code></p>';
    } else {
        $hash = password_hash('head123', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ? WHERE username = ?')->execute([$hash, 'head']);
        echo '<p class="ok">Database schema is up to date. Head password reset to <code>head123</code>.</p>';
    }

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $userCount = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $critCount = $pdo->query('SELECT COUNT(*) FROM criteria')->fetchColumn();
    $insCols = $pdo->query("PRAGMA table_info(instructors)")->fetchAll(PDO::FETCH_ASSOC);
    $insColNames = array_column($insCols, 'name');

    echo '<h3>Status</h3><pre>';
    echo 'DB file: ' . $dbPath . "\n";
    echo 'Tables: ' . implode(', ', $tables) . "\n";
    echo 'Users: ' . $userCount . "\n";
    echo 'Criteria: ' . $critCount . "\n";
    echo 'instructors columns: ' . implode(', ', $insColNames) . "\n";
    echo 'user_id present: ' . (in_array('user_id', $insColNames, true) ? 'YES' : 'NO') . "\n";
    $head = $pdo->query("SELECT username, role FROM users WHERE username='head'")->fetch(PDO::FETCH_ASSOC);
    echo 'Head user: ' . ($head ? $head['username'] . ' (' . $head['role'] . ')' : 'MISSING') . "\n";
    echo '</pre>';

    if (in_array('user_id', $insColNames, true) && (int)$critCount === 16) {
        echo '<p class="ok"><strong>SUCCESS – database is ready.</strong></p>';
        echo '<p><a class="btn" href="login.php">Go to Login</a></p>';
        echo '<p>Login: <code>head</code> / <code>head123</code></p>';
    } else {
        echo '<p class="err">Schema still incomplete. Click Reset below.</p>';
    }

} catch (Throwable $e) {
    echo '<p class="err"><strong>ERROR:</strong> ' . h($e->getMessage()) . '</p>';
    echo '<pre>' . h($e->getTraceAsString()) . '</pre>';
}

echo '<hr>';
echo '<p><strong>If you still see column errors, force a full reset:</strong></p>';
echo '<p><a class="btn btn-danger" href="?action=reset" onclick="return confirm(\'This deletes ALL data and recreates empty tables. Continue?\')">Reset Database (delete all data)</a></p>';
echo '<p><a class="btn btn-secondary" href="?action=init">Re-run setup</a></p>';
echo '</body></html>';
