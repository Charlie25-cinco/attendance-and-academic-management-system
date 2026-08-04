<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../functions/app-helpers.php';

if (php_sapi_name() !== 'cli') {
    appRequireRole('admin');
}

$db = Database::getInstance();

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
        $results[] = "[SKIP] Table {$table} does not exist.";
        continue;
    }

    $stmt = $db->prepare("SHOW INDEX FROM {$table} WHERE Key_name = :name");
    $stmt->execute(['name' => $name]);
    $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $results[] = "[OK] Index {$name} already exists on {$table}.";
    } else {
        try {
            $sql = "ALTER TABLE {$table} ADD INDEX {$name} ({$cols})";
            $db->exec($sql);
            $results[] = "[SUCCESS] Created index {$name} on {$table} ({$cols}).";
        } catch (Throwable $e) {
            $results[] = "[ERROR] Failed creating index {$name} on {$table}: " . $e->getMessage();
        }
    }
}

if (php_sapi_name() === 'cli') {
    echo implode("\n", $results) . "\n";
} else {
    header('Content-Type: text/plain');
    echo implode("\n", $results) . "\n";
}
