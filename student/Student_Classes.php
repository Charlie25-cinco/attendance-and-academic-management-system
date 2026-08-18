<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$studentId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'student';
$current_page = 'classes';
$page_title = 'Classes';

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$classes = [];
$materials = [];
$gradeRows = [];
$gradeActivities = [];
$hasGradeQuarter = false;

$formatSchedule = function ($schedule) {
    $schedule = trim((string)$schedule);
    if ($schedule === '') {
        return 'No schedule set';
    }
    $segments = array_filter(array_map('trim', preg_split('/\s*;\s*/', $schedule)));
    $formatted = [];
    foreach ($segments as $segment) {
        if (preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            $days = preg_replace('/\s*\/\s*/', ', ', trim($m[1]));
            $days = preg_replace('/\s*,\s*/', ', ', $days);
            $time = preg_replace('/\s+/', ' ', trim($m[2]));
            $formatted[] = $days . ' | ' . $time;
        } else {
            $formatted[] = $segment;
        }
    }
    return implode(' ; ', $formatted);
};

if ($db && $studentId > 0) {
    $has_quarter = dbHasColumn($db, 'grades', 'quarter');
    $hasGradeQuarter = $has_quarter;
    $tc = 'term';
    if ($hasGradeQuarter) {
        $has_term = dbHasColumn($db, 'grades', 'term');
        $tc = $has_term ? 'term' : 'quarter';
    }

    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule, c.room,
                               GROUP_CONCAT(DISTINCT CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') AS teacher_names
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               LEFT JOIN class_subjects cs ON cs.class_id = c.id
                               LEFT JOIN users t ON t.id = cs.teacher_id
                               WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                               GROUP BY c.id
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$studentId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $classIds = array_map('intval', array_column($classes, 'id'));
        if ($selectedClassId <= 0 || !in_array($selectedClassId, $classIds, true)) {
            $selectedClassId = 0;
        }

        $where = ["cs.class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"];
        $params = $classIds;
        if ($selectedClassId > 0) {
            $where[] = "cs.class_id = ?";
            $params[] = $selectedClassId;
        }

        $matQuery = "SELECT m.id, m.title, m.file_type, m.file_size, m.created_at, c.class_name, c.grade_level, c.section
                     FROM materials m
                     JOIN class_subjects cs ON cs.id = m.class_subject_id
                     JOIN classes c ON c.id = cs.class_id
                     WHERE " . implode(" AND ", $where) . "
                     ORDER BY m.created_at DESC
                     LIMIT 30";
        $matStmt = $db->prepare($matQuery);
        $matStmt->execute($params);
        $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

        if (dbHasTable($db, 'grade_items') && dbHasTable($db, 'grade_item_scores')) {
            $activityWhere = ["gi.class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"];
            $activityParams = $classIds;
            if ($selectedClassId > 0) {
                $activityWhere[] = "gi.class_id = ?";
                $activityParams[] = $selectedClassId;
            }
            $activityQuery = "SELECT gi.id, gi.title, gi.component, gi.total_score, gi.activity_date, gi.status,
                                     gis.score, c.class_name, c.grade_level, c.section
                              FROM grade_items gi
                              JOIN classes c ON c.id = gi.class_id
                              LEFT JOIN grade_item_scores gis ON gis.grade_item_id = gi.id AND gis.student_id = ?
                              WHERE " . implode(" AND ", $activityWhere) . "
                              ORDER BY gi.activity_date DESC, gi.created_at DESC
                              LIMIT 40";
            $activityStmt = $db->prepare($activityQuery);
            $activityStmt->execute(array_merge([$studentId], $activityParams));
            $gradeActivities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $gradeWhere = [
            "g.student_id = ?",
            "cs.class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"
        ];
        $gradeParams = array_merge([$studentId], $classIds);
        if ($selectedClassId > 0) {
            $gradeWhere[] = "cs.class_id = ?";
            $gradeParams[] = $selectedClassId;
        }

        if ($hasGradeQuarter) {
            $gradeQuery = "SELECT c.class_name, c.grade_level, c.section,
                           AVG(x.quiz_score) AS quiz_avg,
                           AVG(x.exam_score) AS exam_avg,
                           AVG(x.activity_score) AS activity_avg,
                           AVG(x.semester_grade) AS final_avg
                           FROM (
                             SELECT g.class_subject_id, g.semester, g.academic_year,
                                    g.student_id,
                                    AVG(g.quiz_score) AS quiz_score,
                                    AVG(g.exam_score) AS exam_score,
                                    AVG(g.activity_score) AS activity_score,
                                    AVG(g.final_grade) AS semester_grade
                             FROM grades g
                              WHERE g.{$tc} IN ('Q1', 'Q2')
                             GROUP BY g.class_subject_id, g.semester, g.academic_year, g.student_id
                           ) x
                           JOIN class_subjects cs ON cs.id = x.class_subject_id
                           JOIN classes c ON c.id = cs.class_id
                           WHERE " . str_replace("g.", "x.", implode(" AND ", $gradeWhere)) . "
                           GROUP BY c.id
                           ORDER BY c.class_name";
            $gradeStmt = $db->prepare($gradeQuery);
            $gradeStmt->execute($gradeParams);
        } else {
            $gradeQuery = "SELECT c.class_name, c.grade_level, c.section,
                           AVG(g.quiz_score) AS quiz_avg,
                           AVG(g.exam_score) AS exam_avg,
                           AVG(g.activity_score) AS activity_avg,
                           AVG(g.final_grade) AS final_avg
                           FROM grades g
                           JOIN class_subjects cs ON cs.id = g.class_subject_id
                           JOIN classes c ON c.id = cs.class_id
                           WHERE " . implode(" AND ", $gradeWhere) . "
                           GROUP BY c.id
                           ORDER BY c.class_name";
            $gradeStmt = $db->prepare($gradeQuery);
            $gradeStmt->execute($gradeParams);
        }
        $gradeRows = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$selectedClassLabel = 'All Classes';
foreach ($classes as $class) {
    if ((int)$class['id'] === $selectedClassId) {
        $selectedClassLabel = (string)$class['class_name'];
        break;
    }
}

function formatFileSize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    $size = $bytes / (1024 ** $pow);
    return round($size, $pow === 0 ? 0 : 2) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <section class="student-hero student-hero-compact mb-4">
            <div class="student-hero-grid">
                <div class="student-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-journal-bookmark"></i><span>Student Classes</span></div>
                    <h4 class="mb-2">Keep your classes, materials, and progress in one view.</h4>
                    <p class="text-muted mb-3">Review enrolled subjects, schedules, classroom details, and download the latest learning materials by class.</p>
                    <div class="student-chip-row">
                        <span class="student-chip"><i class="bi bi-book"></i><?php echo number_format(count($classes)); ?> active class<?php echo count($classes) === 1 ? '' : 'es'; ?></span>
                        <span class="student-chip"><i class="bi bi-folder"></i><?php echo htmlspecialchars($selectedClassLabel); ?></span>
                    </div>
                </div>
                <div class="student-hero-side">
                    <div class="student-summary-panel">
                        <div class="student-summary-grid">
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Materials</span>
                                <strong class="student-summary-value"><?php echo number_format(count($materials)); ?></strong>
                            </div>
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Activities</span>
                                <strong class="student-summary-value"><?php echo number_format(count($gradeActivities)); ?></strong>
                            </div>
                        </div>
                        <p class="student-summary-note">Switch the class filter to narrow the materials and academic progress lists.</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="student-overview-grid mb-4">
            <div class="student-overview-card"><strong><?php echo number_format(count($classes)); ?></strong><span>Enrolled Classes</span></div>
            <div class="student-overview-card"><strong><?php echo number_format(count($materials)); ?></strong><span>Available Materials</span></div>
            <div class="student-overview-card"><strong><?php echo number_format(count($gradeActivities)); ?></strong><span>Grade Activities</span></div>
            <div class="student-overview-card"><strong><?php echo htmlspecialchars($selectedClassLabel); ?></strong><span>Selected Filter</span></div>
        </div>
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">My Enrolled Classes</h5></div>
                    <div class="content-card-body">
                        <?php if (empty($classes)): ?>
                            <div class="text-center text-muted py-3">No enrolled classes yet.</div>
                        <?php else: ?>
                            <?php foreach ($classes as $index => $class): ?>
                                <div class="class-card mb-3">
                                    <div class="class-card-header" style="height: 80px; background: <?php echo $index % 2 === 0 ? 'linear-gradient(135deg, var(--primary-color), var(--secondary-color))' : 'linear-gradient(135deg, var(--accent-color), #059669)'; ?>;">
                                        <div class="class-card-icon" style="width: 45px; height: 45px; font-size: 20px; bottom: -22px;"><i class="bi bi-journal-bookmark"></i></div>
                                    </div>
                                    <div class="class-card-body" style="padding-top: 30px;">
                                        <h4 class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></h4>
                                        <p class="class-card-subtitle"><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?><?php if (!empty($class['grade_level'])): ?>Grade <?php echo (int)$class['grade_level']; ?><?php endif; ?><?php if (!empty($class['grade_level']) && !empty($class['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($class['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></p>
                                        <p class="text-muted small mb-1"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars((string)(($class['teacher_names'] ?? 'TBA') ?: 'TBA')); ?></p>
                                        <p class="text-muted small mb-1"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($formatSchedule($class['schedule'] ?? '')); ?></p>
                                        <?php if (!empty($class['room'])): ?>
                                            <p class="text-muted small mb-0"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($class['room']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">Learning Materials</h5>
                        <form method="GET" class="student-inline-form">
                            <select name="class_id" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                <option value="0">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$class['id'] === $selectedClassId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($materials)): ?>
                            <div class="text-center text-muted py-3">No materials available.</div>
                        <?php else: ?>
                            <?php foreach ($materials as $material): ?>
                                <div class="material-card mb-3">
                                    <div class="material-icon doc"><i class="bi bi-file-earmark-text"></i></div>
                                    <div class="material-info">
                                        <div class="material-title"><?php echo htmlspecialchars($material['title']); ?></div>
                                        <div class="material-meta">
                                            <?php echo htmlspecialchars((string)$material['class_name']); ?><?php if (!empty($material['grade_level']) || !empty($material['section'])): ?> <?php echo htmlspecialchars('(' . (!empty($material['grade_level']) ? 'G' . $material['grade_level'] : '') . ((!empty($material['grade_level']) && !empty($material['section'])) ? ' - ' : '') . (!empty($material['section']) ? $material['section'] : '') . ')'); ?><?php endif; ?>
                                            | <?php echo strtoupper(htmlspecialchars($material['file_type'] ?: 'FILE')); ?>
                                            | <?php echo htmlspecialchars(formatFileSize($material['file_size'])); ?>
                                            | <?php echo date('M d, Y', strtotime($material['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="material-actions">
                                        <a class="material-btn" title="Download" href="Student_Action.php?action=download_material&id=<?php echo (int)$material['id']; ?>">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">Grade Activities</h5></div>
                    <div class="content-card-body">
                        <div class="table-container">
                            <table class="custom-table">
                                <thead><tr><th>Date</th><th>Class</th><th>Activity</th><th>Component</th><th>Status</th><th>Score</th></tr></thead>
                                <tbody>
                                <?php if (empty($gradeActivities)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No grade activities yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($gradeActivities as $activity): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$activity['activity_date']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$activity['class_name']); ?><?php if (!empty($activity['grade_level']) || !empty($activity['section'])): ?> <?php echo htmlspecialchars('(' . (!empty($activity['grade_level']) ? 'G' . $activity['grade_level'] : '') . ((!empty($activity['grade_level']) && !empty($activity['section'])) ? ' - ' : '') . (!empty($activity['section']) ? $activity['section'] : '') . ')'); ?><?php endif; ?></td>
                                            <td><?php echo htmlspecialchars((string)$activity['title']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$activity['component']); ?></td>
                                            <td><span class="badge bg-<?php echo $activity['status'] === 'finished' ? 'secondary' : 'primary'; ?>"><?php echo htmlspecialchars(ucfirst((string)$activity['status'])); ?></span></td>
                                            <td><?php echo $activity['score'] !== null ? htmlspecialchars((string)$activity['score']) . ' / ' . htmlspecialchars((string)$activity['total_score']) : 'Not recorded'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">Academic Progress by Class</h5></div>
                    <div class="content-card-body">
                        <div class="table-container">
                            <table class="custom-table">
                                <thead><tr><th>Class</th><th>WW Avg</th><th>Assessment Avg</th><th>PT Avg</th><th>Semester Avg</th></tr></thead>
                                <tbody>
                                <?php if (empty($gradeRows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No recorded grades yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($gradeRows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['class_name']); ?><?php if (!empty($row['grade_level']) || !empty($row['section'])): ?> <?php echo htmlspecialchars('(' . (!empty($row['grade_level']) ? 'G' . $row['grade_level'] : '') . ((!empty($row['grade_level']) && !empty($row['section'])) ? ' - ' : '') . (!empty($row['section']) ? $row['section'] : '') . ')'); ?><?php endif; ?></td>
                                            <td><?php echo $row['quiz_avg'] !== null ? round($row['quiz_avg'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $row['exam_avg'] !== null ? round($row['exam_avg'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $row['activity_avg'] !== null ? round($row['activity_avg'], 2) : 'N/A'; ?></td>
                                            <td><strong><?php echo $row['final_avg'] !== null ? round($row['final_avg'], 2) : 'N/A'; ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
</body>
</html>
