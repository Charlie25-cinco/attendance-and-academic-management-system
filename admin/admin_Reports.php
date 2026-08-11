<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Reports Page
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$current_role = 'admin';
$current_page = 'reports';
$page_title = 'Reports';

$selectedType = $_GET['type'] ?? 'attendance';
$allowedTypes = ['attendance', 'top_attendance', 'class_summary', 'at_risk', 'grades', 'enrollment', 'teachers', 'classes'];
if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'attendance';
}
$dateFrom = normalizeDate($_GET['date_from'] ?? '');
$dateTo = normalizeDate($_GET['date_to'] ?? '');
$classId = normalizeInt($_GET['class_id'] ?? '');
$gradeLevel = normalizeInt($_GET['grade_level'] ?? '');
$section = normalizeText($_GET['section'] ?? '');
$status = normalizeText($_GET['status'] ?? '');
$topN = normalizeInt($_GET['top_n'] ?? 10);
$topN = $topN >= 1 && $topN <= 100 ? $topN : 10;
$statusOptions = getStatusOptions($selectedType);
if ($status !== '' && !in_array($status, $statusOptions, true)) {
    $status = '';
}

$previewHeaders = [];
$previewRows = [];
$classesForFilter = [];
$reportAcademicYears = [];
$reportSections = [];
$hasGradeQuarter = dbHasColumn($db, 'grades', 'quarter');
$tc = 'term';

if ($db) {
    $has_quarter = dbHasColumn($db, 'grades', 'quarter');
    if ($has_quarter) {
        $has_term = dbHasColumn($db, 'grades', 'term');
        $tc = $has_term ? 'term' : 'quarter';
    }

    $classesForFilter = $db->query("SELECT id, class_name, grade_level, section, track FROM classes WHERE status = 'active' ORDER BY grade_level, section, class_name")->fetchAll(PDO::FETCH_ASSOC);
    $reportAcademicYears = $db->query("SELECT DISTINCT academic_year FROM enrollments WHERE academic_year IS NOT NULL AND TRIM(academic_year) <> '' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $reportSections = $db->query("SELECT DISTINCT section FROM users WHERE role = 'student' AND section IS NOT NULL AND TRIM(section) <> '' ORDER BY section ASC")->fetchAll(PDO::FETCH_COLUMN);

    $filters = [
        'class_id' => $classId,
        'grade_level' => $gradeLevel,
        'section' => $section,
        'status' => $status
    ];

    switch ($selectedType) {
        case 'top_attendance':
        case 'class_summary':
        case 'at_risk':
            [$aggregateWhere, $aggregateParams] = aggregateAttendanceAdminScope($db, [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'class_id' => $classId,
                'grade_level' => $gradeLevel,
                'section' => $section,
                'status' => $status
            ]);
            $aggregateRows = aggregateAttendanceReportRows($db, $selectedType, $aggregateWhere, $aggregateParams, $topN);
            $aggregateTable = aggregateAttendanceReportTable($selectedType, $aggregateRows);
            $previewHeaders = $aggregateTable['headers'];
            $previewRows = $aggregateTable['rows'];
            break;

        case 'attendance':
            $previewHeaders = ['Date', 'Class', 'Student', 'Status', 'Time In'];
            $where = [];
            $params = [];
            appendDateFilter('a.date', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('attendance', $where, $params, $filters);
            $query = "SELECT a.date, c.class_name, CONCAT(u.first_name, ' ', u.last_name) AS student_name, a.status, a.time_in
                      FROM attendance a
                      JOIN classes c ON a.class_id = c.id
                      JOIN users u ON a.student_id = u.id";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= " ORDER BY a.date DESC, a.id DESC LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'grades':
            $previewHeaders = ['Academic Year', 'Semester', 'Student', 'Class', 'Semester Grade'];
            $where = [];
            $params = [];
            if ($hasGradeQuarter) {
                appendDateFilter('sg.recorded_at', $where, $params, $dateFrom, $dateTo);
                appendAdvancedFilters('grades', $where, $params, $filters);
                $query = "SELECT sg.academic_year, sg.semester,
                          CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                          c.class_name, sg.semester_grade
                          FROM (
                            SELECT g.student_id, g.class_subject_id, g.academic_year, g.semester,
                                   ROUND(AVG(g.final_grade), 2) AS semester_grade,
                                   MAX(g.created_at) AS recorded_at
                            FROM grades g
                            WHERE g.{$tc} IN ('Q1', 'Q2')
                            GROUP BY g.student_id, g.class_subject_id, g.academic_year, g.semester
                          ) sg
                          JOIN users u ON sg.student_id = u.id
                          JOIN class_subjects cs ON sg.class_subject_id = cs.id
                          JOIN classes c ON cs.class_id = c.id";
                if (!empty($where)) {
                    $query .= " WHERE " . implode(" AND ", $where);
                }
                $query .= " ORDER BY sg.academic_year DESC, sg.semester DESC, student_name ASC LIMIT 20";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            } else {
                appendDateFilter('g.created_at', $where, $params, $dateFrom, $dateTo);
                appendAdvancedFilters('grades', $where, $params, $filters);
                $query = "SELECT g.academic_year, g.semester, CONCAT(u.first_name, ' ', u.last_name) AS student_name, c.class_name, g.final_grade
                          FROM grades g
                          JOIN users u ON g.student_id = u.id
                          JOIN class_subjects cs ON g.class_subject_id = cs.id
                          JOIN classes c ON cs.class_id = c.id";
                if (!empty($where)) {
                    $query .= " WHERE " . implode(" AND ", $where);
                }
                $query .= " ORDER BY g.id DESC LIMIT 20";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            }
            break;

        case 'enrollment':
            $previewHeaders = ['Academic Year', 'Semester', 'Student', 'Class', 'Status'];
            $where = [];
            $params = [];
            appendDateFilter('e.enrolled_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('enrollment', $where, $params, $filters);
            $query = "SELECT e.academic_year, e.semester, CONCAT(u.first_name, ' ', u.last_name) AS student_name, c.class_name, e.status
                      FROM enrollments e
                      JOIN users u ON e.student_id = u.id
                      JOIN classes c ON e.class_id = c.id";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= " ORDER BY e.id DESC LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'teachers':
            $previewHeaders = ['Reference Code', 'Teacher', 'Email', 'Status', 'Assigned Classes'];
            $where = ["u.role = 'teacher'"];
            $params = [];
            appendDateFilter('u.created_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('teachers', $where, $params, $filters);
            $query = "SELECT u.reference_code, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name, u.email, u.status,
                      COUNT(DISTINCT cs.class_id) AS assigned_classes
                      FROM users u
                      LEFT JOIN class_subjects cs ON cs.teacher_id = u.id
                      WHERE " . implode(" AND ", $where) . "
                      GROUP BY u.id
                      ORDER BY teacher_name
                      LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            break;

        case 'classes':
            $previewHeaders = ['Class', 'Grade', 'Section', 'Status', 'Students'];
            $where = [];
            $params = [];
            appendDateFilter('c.created_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('classes', $where, $params, $filters);
            $query = "SELECT c.class_name, c.grade_level, c.section, c.status,
                      COUNT(DISTINCT e.student_id) AS students
                      FROM classes c
                      LEFT JOIN enrollments e ON e.class_id = c.id AND e.status = 'enrolled'";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= " GROUP BY c.id
                        ORDER BY c.grade_level, c.section, c.class_name
                        LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $previewRows = $stmt->fetchAll(PDO::FETCH_NUM);
            break;
    }
}

function appendDateFilter($column, &$where, &$params, $dateFrom, $dateTo) {
    if ($dateFrom !== '') {
        $where[] = "DATE($column) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE($column) <= ?";
        $params[] = $dateTo;
    }
}

function appendAdvancedFilters($type, &$where, &$params, $filters) {
    $classId = $filters['class_id'] ?? 0;
    $gradeLevel = $filters['grade_level'] ?? 0;
    $section = $filters['section'] ?? '';
    $status = $filters['status'] ?? '';

    if ($type === 'attendance') {
        if ($classId > 0) {
            $where[] = "a.class_id = ?";
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = "c.grade_level = ?";
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = "c.section = ?";
            $params[] = $section;
        }
        if ($status !== '') {
            $where[] = "a.status = ?";
            $params[] = $status;
        }
        return;
    }

    if ($type === 'grades') {
        if ($classId > 0) {
            $where[] = "cs.class_id = ?";
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = "c.grade_level = ?";
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = "c.section = ?";
            $params[] = $section;
        }
        return;
    }

    if ($type === 'enrollment') {
        if ($classId > 0) {
            $where[] = "e.class_id = ?";
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = "c.grade_level = ?";
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = "c.section = ?";
            $params[] = $section;
        }
        if ($status !== '') {
            $where[] = "e.status = ?";
            $params[] = $status;
        }
        return;
    }

    if ($type === 'teachers') {
        if ($classId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM class_subjects x WHERE x.teacher_id = u.id AND x.class_id = ?)";
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = "EXISTS (
                        SELECT 1
                        FROM class_subjects x
                        JOIN classes xc ON xc.id = x.class_id
                        WHERE x.teacher_id = u.id AND xc.grade_level = ?
                       )";
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = "EXISTS (
                        SELECT 1
                        FROM class_subjects x
                        JOIN classes xc ON xc.id = x.class_id
                        WHERE x.teacher_id = u.id AND xc.section = ?
                       )";
            $params[] = $section;
        }
        if ($status !== '') {
            $where[] = "u.status = ?";
            $params[] = $status;
        }
        return;
    }

    if ($type === 'classes') {
        if ($classId > 0) {
            $where[] = "c.id = ?";
            $params[] = $classId;
        }
        if ($gradeLevel > 0) {
            $where[] = "c.grade_level = ?";
            $params[] = $gradeLevel;
        }
        if ($section !== '') {
            $where[] = "c.section = ?";
            $params[] = $section;
        }
        if ($status !== '') {
            $where[] = "c.status = ?";
            $params[] = $status;
        }
    }
}

function buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN = 10) {
    $params = [];
    if ($dateFrom !== '') {
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $params['date_to'] = $dateTo;
    }
    if ($classId > 0) {
        $params['class_id'] = $classId;
    }
    if ($gradeLevel > 0) {
        $params['grade_level'] = $gradeLevel;
    }
    if ($section !== '') {
        $params['section'] = $section;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($topN >= 1 && $topN <= 100) {
        $params['top_n'] = $topN;
    }
    return $params;
}

function getStatusOptions($type) {
    if ($type === 'attendance') {
        return ['present', 'absent', 'late'];
    }
    if ($type === 'enrollment') {
        return ['enrolled', 'dropped', 'completed'];
    }
    if ($type === 'teachers') {
        return ['active', 'inactive', 'pending'];
    }
    if ($type === 'classes') {
        return ['active', 'inactive'];
    }
    return [];
}

function exportUrl($type, $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN = 10) {
    $params = array_merge(['action' => 'export', 'type' => $type], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN));
    return "admin_Reports_Action.php?" . http_build_query($params);
}

$typeLabels = [
    'attendance' => 'Attendance',
    'top_attendance' => 'Top Attendance',
    'class_summary' => 'Class Summary',
    'at_risk' => 'At-risk Attendance',
    'grades' => 'Grades',
    'enrollment' => 'Enrollment',
    'teachers' => 'Teachers',
    'classes' => 'Classes'
];
$selectedTypeLabel = $typeLabels[$selectedType] ?? 'Attendance';
$appliedFilterCount = count(buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN));
$previewCount = count($previewRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <section class="admin-hero admin-hero-compact mb-4">
                <div class="admin-hero-grid">
                    <div class="admin-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-bar-chart-line"></i><span>Admin Reports</span></div>
                        <h4 class="mb-2">Analyze school activity with cleaner exports and previews.</h4>
                        <p class="text-muted mb-3">Build focused attendance, grades, enrollment, teacher, and class reports with reusable filters and quick CSV exports.</p>
                        <div class="admin-hero-metrics">
                            <div class="admin-hero-metric">
                                <span>Selected report</span>
                                <strong><?php echo htmlspecialchars($selectedTypeLabel); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Preview rows</span>
                                <strong><?php echo number_format($previewCount); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Active filters</span>
                                <strong><?php echo number_format($appliedFilterCount); ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="admin-hero-side">
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Fast export</span>
                            <strong><?php echo htmlspecialchars($selectedTypeLabel); ?> CSV</strong>
                            <small>Use the preview header actions to export the current filtered view.</small>
                        </div>
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Coverage</span>
                            <strong>5 report types</strong>
                            <small>Attendance, grades, enrollment, teachers, and classes</small>
                        </div>
                    </div>
                </div>
            </section>

            <div class="content-card mb-4" id="sf1-export">
                <div class="content-card-body">
                    <form method="GET" action="admin_Reports.php" id="adminReportFilterForm" class="row g-3 align-items-end admin-report-filter-form app-responsive-filter-form">
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>">
                        <?php if (in_array($selectedType, ['top_attendance', 'at_risk'], true)): ?>
                            <div class="col-md-2">
                                <label class="form-label">Top N</label>
                                <input type="number" class="form-control" name="top_n" min="1" max="100" value="<?php echo (int)$topN; ?>">
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <div class="admin-filter-copy">
                                <strong>Filter the current report view</strong>
                                <small>Keep the preview and CSV export aligned by applying date, class, section, and status filters here.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary-custom w-100" id="adminReportApplyBtn" type="submit">Apply</button>
                        </div>
                        <div class="col-md-2">
                            <a class="btn btn-secondary-custom w-100" href="admin_Reports.php?type=<?php echo urlencode($selectedType); ?>">Clear</a>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" class="form-select">
                                <option value="">All Classes</option>
                                <?php foreach ($classesForFilter as $classItem): ?>
                                    <option value="<?php echo (int)$classItem['id']; ?>" <?php echo $classId === (int)$classItem['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($classItem['class_name'] . ' (G' . $classItem['grade_level'] . ' - ' . $classItem['section'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Grade</label>
                            <select name="grade_level" class="form-select">
                                <option value="">All</option>
                                <option value="11" <?php echo $gradeLevel === 11 ? 'selected' : ''; ?>>11</option>
                                <option value="12" <?php echo $gradeLevel === 12 ? 'selected' : ''; ?>>12</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Section</label>
                            <select name="section" id="sectionFilter" class="form-select" data-current="<?php echo htmlspecialchars($section); ?>">
                                <option value="">All</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($statusOptions as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $status === $opt ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="content-card-title">DepEd Form Exports</h5>
                </div>
                <div class="content-card-body">
                    <div class="row g-4">
                        <div class="col-12 col-xl-6">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                    <div>
                                        <h6 class="fw-bold mb-1">SF1 School Register</h6>
                                        <p class="text-muted small mb-0">Export official learner records by grade, section, and school year.</p>
                                    </div>
                                    <i class="bi bi-file-earmark-spreadsheet fs-4 text-success"></i>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small">Grade</label>
                                        <select id="reportSf1Grade" class="form-select form-select-sm">
                                            <option value="">Grade</option>
                                            <option value="11">11</option>
                                            <option value="12">12</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small">Track</label>
                                        <select id="reportSf1Track" class="form-select form-select-sm">
                                            <option value="">All</option>
                                            <option value="academic">Academic</option>
                                            <option value="techpro">TechPro</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Section</label>
                                        <select id="reportSf1Section" class="form-select form-select-sm">
                                            <option value="">Section</option>
                                            <?php foreach ($reportSections as $sectionItem): ?>
                                                <option value="<?php echo htmlspecialchars((string)$sectionItem); ?>"><?php echo htmlspecialchars((string)$sectionItem); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small">Academic Year</label>
                                        <select id="reportSf1AcademicYear" class="form-select form-select-sm">
                                            <?php if (empty($reportAcademicYears)): ?>
                                                <option value="<?php echo htmlspecialchars(currentAcademicYear()); ?>"><?php echo htmlspecialchars(currentAcademicYear()); ?></option>
                                            <?php else: ?>
                                                <?php foreach ($reportAcademicYears as $sy): ?>
                                                    <option value="<?php echo htmlspecialchars((string)$sy); ?>" <?php echo (string)$sy === currentAcademicYear() ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$sy); ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-success flex-fill" type="button" data-skip-loader="true" onclick="exportReportSf1('xlsx')"><i class="bi bi-filetype-xlsx me-1"></i>XLSX</button>
                                    <button class="btn btn-sm btn-outline-success flex-fill" type="button" data-skip-loader="true" onclick="exportReportSf1('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                    <div>
                                        <h6 class="fw-bold mb-1">SF2 Daily Attendance</h6>
                                        <p class="text-muted small mb-0">Export monthly attendance by class.</p>
                                    </div>
                                    <i class="bi bi-calendar-check fs-4 text-success"></i>
                                </div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small">Class</label>
                                        <select id="reportSf2Class" class="form-select form-select-sm">
                                            <option value="">Select class</option>
                                            <?php foreach ($classesForFilter as $classItem): ?>
                                                <option value="<?php echo (int)$classItem['id']; ?>">
                                                    <?php echo htmlspecialchars('G' . (int)$classItem['grade_level'] . ' - ' . (string)$classItem['section'] . ' / ' . (string)$classItem['class_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small">Month</label>
                                        <select id="reportSf2Month" class="form-select form-select-sm">
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?php echo $m; ?>" <?php echo $m === (int)date('n') ? 'selected' : ''; ?>><?php echo date('M', mktime(0, 0, 0, $m, 1)); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small">Year</label>
                                        <select id="reportSf2Year" class="form-select form-select-sm">
                                            <?php for ($y = (int)date('Y') - 1; $y <= (int)date('Y') + 1; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo $y === (int)date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-success flex-fill" type="button" data-skip-loader="true" onclick="exportReportSf2('xlsx')"><i class="bi bi-filetype-xlsx me-1"></i>XLSX</button>
                                    <button class="btn btn-sm btn-outline-success flex-fill" type="button" data-skip-loader="true" onclick="exportReportSf2('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'attendance' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);"><i class="bi bi-calendar-check"></i></div>
                        <h5 class="report-title">Attendance Report</h5>
                        <p class="report-desc">Daily attendance records by class and student</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'attendance'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('attendance', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'top_attendance' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);"><i class="bi bi-trophy"></i></div>
                        <h5 class="report-title">Top Attendance</h5>
                        <p class="report-desc">Rank students by effective attendance rate.</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'top_attendance'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('top_attendance', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'class_summary' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);"><i class="bi bi-collection"></i></div>
                        <h5 class="report-title">Class Summary</h5>
                        <p class="report-desc">Summarize attendance by class.</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'class_summary'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('class_summary', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'at_risk' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(239, 68, 68, 0.1); color: #dc2626;"><i class="bi bi-exclamation-triangle"></i></div>
                        <h5 class="report-title">At-risk Attendance</h5>
                        <p class="report-desc">Show students with the highest effective absences.</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'at_risk'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('at_risk', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'grades' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);"><i class="bi bi-file-earmark-text"></i></div>
                        <h5 class="report-title">Grade Report</h5>
                        <p class="report-desc">Quiz, exam, activity, and final grade records</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'grades'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('grades', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'enrollment' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning-color);"><i class="bi bi-people"></i></div>
                        <h5 class="report-title">Enrollment Report</h5>
                        <p class="report-desc">Enrollment status and class assignment list</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'enrollment'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('enrollment', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'teachers' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;"><i class="bi bi-person-workspace"></i></div>
                        <h5 class="report-title">Teacher Report</h5>
                        <p class="report-desc">Teacher directory and current workload summary</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'teachers'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('teachers', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="report-card <?php echo $selectedType === 'classes' ? 'admin-report-card-active' : ''; ?>">
                        <div class="report-icon" style="background: rgba(236, 72, 153, 0.1); color: #ec4899;"><i class="bi bi-journal-bookmark"></i></div>
                        <h5 class="report-title">Class Report</h5>
                        <p class="report-desc">Class list with section and enrollment counts</p>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-primary flex-fill" href="admin_Reports.php?<?php echo http_build_query(array_merge(['type' => 'classes'], buildFilterParams($dateFrom, $dateTo, $classId, $gradeLevel, $section, $status, $topN))); ?>">Preview</a>
                            <a class="btn btn-sm btn-primary-custom flex-fill" href="<?php echo exportUrl('classes', $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card mt-4">
                <div class="content-card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="content-card-title mb-1">Preview: <?php echo ucfirst($selectedType); ?> Report</h5>
                        <small class="text-muted"><?php echo number_format($previewCount); ?> row<?php echo $previewCount === 1 ? '' : 's'; ?> loaded for the current filter set.</small>
                    </div>
                    <a class="btn btn-sm btn-primary-custom" href="<?php echo exportUrl($selectedType, $dateFrom, $dateTo, $classId, $gradeLevel, $section, $status); ?>">
                        <i class="bi bi-download me-1"></i>Download CSV
                    </a>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <?php foreach ($previewHeaders as $header): ?>
                                        <th><?php echo htmlspecialchars($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($previewRows)): ?>
                                    <tr>
                                        <td colspan="<?php echo max(1, count($previewHeaders)); ?>" class="text-center text-muted py-3">
                                            No data available for this report.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($previewRows as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $value): ?>
                                                <td><?php echo htmlspecialchars((string)$value); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="content-card mt-4">
                <div class="content-card-header d-flex justify-content-between align-items-center">
                    <h5 class="content-card-title mb-0">Write Report Note</h5>
                    <small class="text-muted">Admin-only journal for generated reports</small>
                </div>
                <div class="content-card-body">
                    <form id="adminReportNoteForm" class="row g-3 admin-report-note-form">
                        <input type="hidden" name="note_id" value="">
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="report_type" id="adminReportNoteType">
                                <option value="general">General</option>
                                <option value="attendance">Attendance</option>
                                <option value="top_attendance">Top Attendance</option>
                                <option value="class_summary">Class Summary</option>
                                <option value="at_risk">At-risk Attendance</option>
                                <option value="grades">Grades</option>
                                <option value="enrollment">Enrollment</option>
                                <option value="teachers">Teachers</option>
                                <option value="classes">Classes</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" maxlength="200" placeholder="Ex. Monthly attendance observation">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Content</label>
                            <textarea class="form-control" name="content" rows="4" maxlength="5000" placeholder="Write your report note here..."></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary-custom d-none" id="adminReportNoteCancelBtn">Cancel Edit</button>
                            <button type="submit" class="btn btn-primary-custom" id="adminReportNoteSaveBtn">
                                <i class="bi bi-save me-1"></i>Save Report Note
                            </button>
                        </div>
                    </form>

                    <hr>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Saved Report Notes</h6>
                        <select class="form-select form-select-sm" id="adminReportNoteFilter" style="max-width: 220px;">
                            <option value="">All Types</option>
                            <option value="general">General</option>
                            <option value="attendance">Attendance</option>
                            <option value="top_attendance">Top Attendance</option>
                            <option value="class_summary">Class Summary</option>
                            <option value="at_risk">At-risk Attendance</option>
                            <option value="grades">Grades</option>
                            <option value="enrollment">Enrollment</option>
                            <option value="teachers">Teachers</option>
                            <option value="classes">Classes</option>
                        </select>
                    </div>

                    <div id="adminReportNoteList" class="d-flex flex-column gap-3"></div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('adminReportFilterForm')?.addEventListener('submit', function () {
            const applyBtn = document.getElementById('adminReportApplyBtn');
            if (applyBtn) {
                applyBtn.disabled = true;
                applyBtn.textContent = 'Applying...';
            }
        });

        function exportReportSf1(format) {
            const gradeLevel = document.getElementById('reportSf1Grade')?.value || '';
            const track = document.getElementById('reportSf1Track')?.value || '';
            const section = document.getElementById('reportSf1Section')?.value || '';
            const academicYear = document.getElementById('reportSf1AcademicYear')?.value || '';
            if (!gradeLevel) { showNotification('Select a grade level for SF1 export.', 'warning'); return; }
            if (!section) { showNotification('Select a section for SF1 export.', 'warning'); return; }
            const params = new URLSearchParams({
                action: 'export_sf1',
                grade_level: gradeLevel,
                section: section,
                track: track,
                academic_year: academicYear,
                format: format
            });
            window.APP_SUPPRESS_NEXT_UNLOAD_PROGRESS = true;
            window.location.href = 'admin_Enrollments_Action.php?' + params.toString();
        }

        function exportReportSf2(format) {
            const classId = document.getElementById('reportSf2Class')?.value || '';
            const month = document.getElementById('reportSf2Month')?.value || '';
            const year = document.getElementById('reportSf2Year')?.value || '';
            if (!classId) { showNotification('Select a class for SF2 export.', 'warning'); return; }
            const params = new URLSearchParams({
                action: 'export_sf2',
                class_id: classId,
                month: month,
                year: year,
                format: format
            });
            window.APP_SUPPRESS_NEXT_UNLOAD_PROGRESS = true;
            window.location.href = 'admin_Classes_Action.php?' + params.toString();
        }

        (async function initSectionFilter() {
            const gradeSelect = document.querySelector('select[name="grade_level"]');
            const sectionSelect = document.getElementById('sectionFilter');
            if (!gradeSelect || !sectionSelect) return;

            let sectionsCache = [];
            try {
                const res = await fetch('admin_Sections_Action.php?action=list');
                const data = await res.json();
                if (data.success && data.sections) sectionsCache = data.sections;
            } catch (_) {}

            function populateSections() {
                const selectedGrade = gradeSelect.value;
                const currentSection = sectionSelect.dataset.current || '';
                sectionSelect.innerHTML = '<option value="">All</option>';

                const filtered = selectedGrade
                    ? sectionsCache.filter(s => s.grade_level === selectedGrade)
                    : sectionsCache;

                const seen = new Set();
                filtered.forEach(s => {
                    if (seen.has(s.name)) return;
                    seen.add(s.name);
                    const option = document.createElement('option');
                    option.value = s.name;
                    option.textContent = s.name;
                    if (s.name === currentSection) option.selected = true;
                    sectionSelect.appendChild(option);
                });
            }

            gradeSelect.addEventListener('change', function () {
                sectionSelect.dataset.current = '';
                populateSections();
            });

            populateSections();
        })();

        (function initAdminReportNotes() {
            const form = document.getElementById('adminReportNoteForm');
            const saveBtn = document.getElementById('adminReportNoteSaveBtn');
            const cancelBtn = document.getElementById('adminReportNoteCancelBtn');
            const listWrap = document.getElementById('adminReportNoteList');
            const typeFilter = document.getElementById('adminReportNoteFilter');
            if (!form || !saveBtn || !cancelBtn || !listWrap || !typeFilter) return;

            let notesCache = [];
            const csrfToken = (window.APP_CSRF_TOKEN || '').toString();

            function appendCsrf(fd) {
                if (fd && csrfToken) fd.set('csrf_token', csrfToken);
                return fd;
            }

            async function readJsonResponse(res, fallbackMessage) {
                const text = await res.text();
                try {
                    return text ? JSON.parse(text) : { ok: false, message: fallbackMessage };
                } catch (err) {
                    console.error('Report notes returned a non-JSON response.', {
                        status: res.status,
                        contentType: res.headers.get('content-type') || '',
                        body: text.slice(0, 500)
                    });
                    return { ok: false, message: fallbackMessage };
                }
            }

            function typeBadge(type) {
                const label = type ? type.toUpperCase() : 'GENERAL';
                return '<span class="badge bg-light text-dark border">' + esc(label) + '</span>';
            }

            function setCreateMode() {
                form.note_id.value = '';
                form.reset();
                cancelBtn.classList.add('d-none');
                saveBtn.innerHTML = '<i class="bi bi-save me-1"></i>Save Report Note';
            }

            function setEditMode(note) {
                form.note_id.value = String(note.id);
                form.report_type.value = note.report_type || 'general';
                form.title.value = note.title || '';
                form.content.value = note.content || '';
                cancelBtn.classList.remove('d-none');
                saveBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Update Report Note';
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            async function loadNotes() {
                listWrap.innerHTML = '<div class="text-muted">Loading...</div>';
                const qs = typeFilter.value ? ('&report_type=' + encodeURIComponent(typeFilter.value)) : '';

                try {
                    const res = await fetch('admin_Reports_Action.php?action=list_notes' + qs, {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await readJsonResponse(res, 'Report notes returned an invalid response.');
                    if (!res.ok || !data.ok) {
                        listWrap.innerHTML = '<div class="text-danger">' + esc(data.message || 'Failed to load notes.') + '</div>';
                        return;
                    }

                    const notes = Array.isArray(data.notes) ? data.notes : [];
                    notesCache = notes;
                    if (!notes.length) {
                        listWrap.innerHTML = '<div class="text-muted">No saved report notes yet.</div>';
                        return;
                    }

                    listWrap.innerHTML = notes.map(function (note) {
                        return (
                            '<div class="border rounded p-3">' +
                                '<div class="d-flex justify-content-between align-items-start gap-3 mb-2">' +
                                    '<div>' +
                                        '<h6 class="mb-1">' + esc(note.title) + '</h6>' +
                                        '<div class="d-flex align-items-center gap-2">' +
                                            typeBadge(note.report_type) +
                                            '<small class="text-muted">' + esc(note.created_at) + '</small>' +
                                        '</div>' +
                                    '</div>' +
                                    '<div class="d-flex gap-1">' +
                                        '<button type="button" class="btn btn-sm btn-outline-primary admin-note-edit-btn" data-id="' + Number(note.id) + '">' +
                                            '<i class="bi bi-pencil"></i>' +
                                        '</button>' +
                                        '<button type="button" class="btn btn-sm btn-outline-danger admin-note-delete-btn" data-id="' + Number(note.id) + '">' +
                                            '<i class="bi bi-trash"></i>' +
                                        '</button>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="text-muted" style="white-space: pre-wrap;">' + esc(note.content) + '</div>' +
                            '</div>'
                        );
                    }).join('');
                } catch (err) {
                    listWrap.innerHTML = '<div class="text-danger">' + esc(err.message || 'Error loading notes.') + '</div>';
                }
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const fd = new FormData(form);
                appendCsrf(fd);
                if (!String(fd.get('title') || '').trim() || !String(fd.get('content') || '').trim()) {
                    showNotification('Title and content are required.', 'warning');
                    return;
                }

                const isEdit = !!String(form.note_id.value || '').trim();
                saveBtn.disabled = true;
                saveBtn.innerHTML = isEdit
                    ? '<i class="bi bi-hourglass-split me-1"></i>Updating...'
                    : '<i class="bi bi-hourglass-split me-1"></i>Saving...';

                try {
                    const action = isEdit ? 'update_note' : 'save_note';
                    const res = await fetch('admin_Reports_Action.php?action=' + action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: fd
                    });
                    const data = await readJsonResponse(res, isEdit ? 'Report note update returned an invalid response.' : 'Report note save returned an invalid response.');
                    if (!res.ok || !data.ok) {
                        showNotification((data && data.message) ? data.message : (isEdit ? 'Failed to update note.' : 'Failed to save note.'), 'danger');
                        return;
                    }
                    setCreateMode();
                    await loadNotes();
                    showNotification(isEdit ? 'Report note updated.' : 'Report note saved.', 'success');
                } catch (err) {
                    showNotification(isEdit ? 'Error updating note.' : 'Error saving note.', 'danger');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = String(form.note_id.value || '').trim()
                        ? '<i class="bi bi-pencil-square me-1"></i>Update Report Note'
                        : '<i class="bi bi-save me-1"></i>Save Report Note';
                }
            });

            listWrap.addEventListener('click', async function (e) {
                const editBtn = e.target.closest('.admin-note-edit-btn');
                if (editBtn) {
                    const noteId = Number(editBtn.getAttribute('data-id') || 0);
                    const note = notesCache.find(function (n) { return Number(n.id) === noteId; });
                    if (note) {
                        setEditMode(note);
                    }
                    return;
                }

                const btn = e.target.closest('.admin-note-delete-btn');
                if (!btn) return;

                const noteId = Number(btn.getAttribute('data-id') || 0);
                if (!noteId) return;

                const fd = new FormData();
                fd.append('note_id', String(noteId));
                appendCsrf(fd);

                btn.disabled = true;
                try {
                    const res = await fetch('admin_Reports_Action.php?action=delete_note', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: fd
                    });
                    const data = await readJsonResponse(res, 'Report note delete returned an invalid response.');
                    if (!res.ok || !data.ok) {
                        btn.disabled = false;
                        showNotification((data && data.message) ? data.message : 'Failed to delete note.', 'danger');
                        return;
                    }
                    await loadNotes();
                } catch (err) {
                    btn.disabled = false;
                }
            });

            typeFilter.addEventListener('change', function () {
                loadNotes();
            });

            cancelBtn.addEventListener('click', function () {
                setCreateMode();
            });

            loadNotes();
        })();
    </script>
<?php include '../includes/footer.php'; ?>




