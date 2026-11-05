<?php
/**
 * SQLite connection + helpers
 * Auto-creates / upgrades schema when tables or columns are missing.
 */
define('DB_PATH', __DIR__ . '/../database/evaluation.db');

define('WEIGHT_HEAD', 0.30);
define('WEIGHT_PEER', 0.20);
define('WEIGHT_STUDENT', 0.50);

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $needsSchema = false;

    $hasUsers = (bool) $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
    )->fetchColumn();

    if (!$hasUsers) {
        $needsSchema = true;
    } else {
        // Old schema detection: instructors without user_id
        $cols = $pdo->query('PRAGMA table_info(instructors)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('user_id', $names, true)) {
            $needsSchema = true;
        }
    }

    if ($needsSchema) {
        // Drop everything and rebuild from schema.sql
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $t) . '"');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException('Schema file not found: ' . $schemaFile);
        }
        $pdo->exec(file_get_contents($schemaFile));

        $hash = password_hash('head123', PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ? WHERE username = ?')
            ->execute([$hash, 'head']);

        // Ensure head exists even if INSERT OR IGNORE was skipped
        $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE username='head'")->fetchColumn();
        if (!(int)$exists) {
            $pdo->prepare(
                'INSERT INTO users (username, password, full_name, role, email) VALUES (?,?,?,?,?)'
            )->execute(['head', $hash, 'Department Head', 'head', 'head@ambo.edu.et']);
        }
    }

    return $pdo;
}

function getCriteriaGrouped(): array
{
    $db = getDB();
    $rows = $db->query('SELECT * FROM criteria ORDER BY sort_order')->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $cat = $row['category'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = ['name' => $row['category_name'], 'items' => []];
        }
        $grouped[$cat]['items'][] = $row;
    }
    return $grouped;
}

function calculateScore(array $ratings): array
{
    $sum = 0;
    $count = 0;
    foreach ($ratings as $r) {
        if ($r !== '' && $r !== null && is_numeric($r)) {
            $sum += (int) $r;
            $count++;
        }
    }
    $avg = $count > 0 ? round($sum / $count, 2) : 0;
    return ['total' => $sum, 'average' => $avg, 'count' => $count];
}

/**
 * Final weighted score for an instructor
 * Head 30% + Peer 20% + Student 50% (renormalize if some missing)
 */
function getWeightedScore(int $instructorId, ?string $academicYear = null): array
{
    $db = getDB();
    $params = [$instructorId];
    $yearSql = '';
    if ($academicYear) {
        $yearSql = ' AND academic_year = ?';
        $params[] = $academicYear;
    }

    $stmt = $db->prepare("
        SELECT evaluator_type, AVG(average_rating) AS avg_rating, COUNT(*) AS cnt
        FROM evaluations
        WHERE instructor_id = ? $yearSql
        GROUP BY evaluator_type
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $avgs = ['head' => null, 'peer' => null, 'student' => null];
    $counts = ['head' => 0, 'peer' => 0, 'student' => 0];
    foreach ($rows as $r) {
        $avgs[$r['evaluator_type']] = (float) $r['avg_rating'];
        $counts[$r['evaluator_type']] = (int) $r['cnt'];
    }

    $weightMap = [
        'head'    => WEIGHT_HEAD,
        'peer'    => WEIGHT_PEER,
        'student' => WEIGHT_STUDENT,
    ];
    $activeWeight = 0;
    $weightedSum = 0;
    foreach ($avgs as $type => $avg) {
        if ($avg !== null) {
            $weightedSum += $avg * $weightMap[$type];
            $activeWeight += $weightMap[$type];
        }
    }

    $final = $activeWeight > 0 ? round($weightedSum / $activeWeight, 2) : null;

    return [
        'head_avg'      => $avgs['head'],
        'peer_avg'      => $avgs['peer'],
        'student_avg'   => $avgs['student'],
        'head_count'    => $counts['head'],
        'peer_count'    => $counts['peer'],
        'student_count' => $counts['student'],
        'final_score'   => $final,
        'weights'       => $weightMap,
    ];
}
