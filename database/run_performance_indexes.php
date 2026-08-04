<?php
require_once __DIR__ . '/../functions/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../functions/app-helpers.php';

if (php_sapi_name() !== 'cli') {
    appRequireRole('admin');
}

$dbObj = new Database();
$db = $dbObj->getConnection();
if (!$db instanceof PDO) {
    die("Database connection failed. Check config/db.php configuration.\n");
}

$indexesToApply = [
    [
        'table' => 'users',
        'name' => 'idx_users_role_status_grade',
        'columns' => ['role', 'status', 'grade_level', 'section']
    ],
    [
        'table' => 'classes',
        'name' => 'idx_classes_grade_section_status',
        'columns' => ['grade_level', 'section', 'status', 'teacher_id']
    ],
    [
        'table' => 'enrollments',
        'name' => 'idx_enrollments_student_class_ay',
        'columns' => ['student_id', 'class_id', 'academic_year', 'status']
    ],
    [
        'table' => 'attendance',
        'name' => 'idx_attendance_student_date_status',
        'columns' => ['student_id', 'date', 'status']
    ],
    [
        'table' => 'grades',
        'name' => 'idx_grades_student_cs_term_ay',
        'columns' => ['student_id', 'class_subject_id', 'academic_year', 'term']
    ],
    [
        'table' => 'grade_items',
        'name' => 'idx_grade_items_class_term',
        'columns' => ['class_id', 'term', 'academic_year']
    ],
    [
        'table' => 'grade_item_scores',
        'name' => 'idx_gis_item_student',
        'columns' => ['grade_item_id', 'student_id']
    ]
];

$results = [];

foreach ($indexesToApply as $def) {
    $table = $def['table'];
    $name = $def['name'];
    $cols = implode(', ', $def['columns']);

    if (!SchemaCache::hasTable($db, $table)) {
        $results[] = [
            'status' => 'skip',
            'message' => "Table '{$table}' does not exist."
        ];
        continue;
    }

    $stmt = $db->prepare("SHOW INDEX FROM {$table} WHERE Key_name = :name");
    $stmt->execute(['name' => $name]);
    $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $results[] = [
            'status' => 'ok',
            'message' => "Index '{$name}' already exists on '{$table}'."
        ];
    } else {
        try {
            $sql = "ALTER TABLE {$table} ADD INDEX {$name} ({$cols})";
            $db->exec($sql);
            $results[] = [
                'status' => 'success',
                'message' => "Created index '{$name}' on '{$table}' ({$cols})."
            ];
        } catch (Throwable $e) {
            $results[] = [
                'status' => 'error',
                'message' => "Failed creating index '{$name}' on '{$table}': " . $e->getMessage()
            ];
        }
    }
}

if (php_sapi_name() === 'cli') {
    foreach ($results as $res) {
        echo "[" . strtoupper($res['status']) . "] " . $res['message'] . "\n";
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Index Migration - BSHS AMS</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <?php echo pwaHeadHtml(); ?>
</head>
<body class="bg-light p-4">
    <div class="container" style="max-width: 800px;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Database Performance Index Migration</h4>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">Optimizing API query execution times and database performance...</p>
                <div class="list-group">
                    <?php foreach ($results as $res): ?>
                        <?php
                        $badgeClass = match ($res['status']) {
                            'success' => 'bg-success',
                            'ok' => 'bg-info',
                            'skip' => 'bg-warning text-dark',
                            default => 'bg-danger'
                        };
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?php echo htmlspecialchars($res['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="badge <?php echo $badgeClass; ?> rounded-pill">
                                <?php echo strtoupper($res['status']); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4">
                    <a href="../admin/dashboard.php" class="btn btn-outline-primary">Return to Admin Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
