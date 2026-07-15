<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/Login.php");
    exit();
}

$db = (new Database())->getConnection();
$studentId = (int)($_SESSION['user_id'] ?? 0);

function srcCurrentAcademicYear() {
    $year = (int)date('Y');
    $month = (int)date('n');
    $start = $month >= 6 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}

function srcCurrentSemester() {
    $month = (int)date('n');
    return ($month >= 12 || $month <= 5) ? 'S2' : 'S1';
}

function srcPeriodBounds($academicYear, $semester, $gradingSystem = '4_quarter') {
    preg_match('/^(\d{4})-(\d{4})$/', (string)$academicYear, $m);
    $startYear = (int)($m[1] ?? date('Y'));
    $endYear = (int)($m[2] ?? ($startYear + 1));
    if ($gradingSystem === '3_term') {
        return [$startYear . '-06-01', $endYear . '-05-31'];
    }
    if ($semester === 'S2') {
        return [$startYear . '-12-01', $endYear . '-05-31'];
    }
    return [$startYear . '-06-01', $startYear . '-11-30'];
}

$selectedSemester = $_GET['semester'] ?? srcCurrentSemester();
if (!in_array($selectedSemester, ['S1', 'S2'], true)) {
    $selectedSemester = srcCurrentSemester();
}
$selectedAcademicYear = trim((string)($_GET['academic_year'] ?? srcCurrentAcademicYear()));
if (!preg_match('/^\d{4}-\d{4}$/', $selectedAcademicYear)) {
    $selectedAcademicYear = srcCurrentAcademicYear();
}
$gs = SshsGradeCalculator::gradingSystem($selectedAcademicYear);
$isQuarterSystem = ($gs === '4_quarter');
if (!$isQuarterSystem) {
    $termLabels = SshsGradeCalculator::validTerms($gs);
    $selectedSemesterForQuery = null;
    $semesterCondition = '';
} else {
    $termLabels = ($selectedSemester === 'S2') ? ['Q3', 'Q4'] : ['Q1', 'Q2'];
    $selectedSemesterForQuery = $selectedSemester;
    $semesterCondition = 'AND g.semester = ?';
}
$semesterNo = $selectedSemester === 'S2' ? 2 : 1;

$studentInfo = [
    'name' => trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? '')),
    'reference_code' => trim((string)($_SESSION['reference_code'] ?? '')),
    'grade_level' => '',
    'section' => ''
];
$subjectRows = [];
$summary = ['subjects' => 0, 'gwa' => null];
$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'rate' => 0];
$classIds = [];
$hasGradeApprovals = false;
$reportCardApproved = false;
$reportCardPendingMessage = '';
$reportCardReviewedBy = '';
$reportCardReviewedAt = '';

function ensureReportCardApprovalsTableForStudent($db) {
    static $ready = false; if ($ready) return; $ready = true;
    $db->exec("CREATE TABLE IF NOT EXISTS report_card_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        semester VARCHAR(5) NULL,
        advisory_teacher_id INT NOT NULL,
        status ENUM('pending','rejected','submitted_admin','approved') DEFAULT 'pending',
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        remarks VARCHAR(255) NULL,
        UNIQUE KEY uq_report_card_term (student_id, academic_year, semester),
        KEY idx_report_card_status (status),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (advisory_teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

if ($db && $studentId > 0) {
    ensureReportCardApprovalsTableForStudent($db);
    $has_quarter = dbHasColumn($db, 'grades', 'quarter');
    $hasQuarter = $has_quarter;
    $tc = 'term';
    if ($hasQuarter) {
        $has_term = dbHasColumn($db, 'grades', 'term');
        $tc = $has_term ? 'term' : 'quarter';
    }
    $hasGradeApprovals = dbHasTable($db, 'grade_approvals');
    $hasAttendanceYear = dbHasColumn($db, 'attendance', 'academic_year');
    $hasAttendanceSemester = dbHasColumn($db, 'attendance', 'semester');
    $hasAttendanceTerms = $hasAttendanceYear && $hasAttendanceSemester;

    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.student_id = ?
                               AND COALESCE(e.status, 'enrolled') = 'enrolled'
                               AND c.status = 'active'
                               ORDER BY c.class_name");
    $classStmt->execute([$studentId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $studentInfo['grade_level'] = (string)$classes[0]['grade_level'];
        $studentInfo['section'] = (string)$classes[0]['section'];
        $classIds = array_map('intval', array_column($classes, 'id'));
    }

    $rcSemCondition = $isQuarterSystem ? 'AND rc.semester = ?' : 'AND rc.semester IS NULL';
    $rcStatusStmt = $db->prepare("SELECT rc.status, rc.reviewed_at,
                                         CONCAT(r.first_name, ' ', r.last_name) AS reviewed_by_name
                                  FROM report_card_approvals
                                  rc
                                  LEFT JOIN users r ON r.id = rc.reviewed_by
                                  WHERE student_id = ? AND academic_year = ?
                                  {$rcSemCondition}
                                  LIMIT 1");
    $rcParams = [$studentId, $selectedAcademicYear];
    if ($isQuarterSystem) {
        $rcParams[] = $selectedSemester;
    }
    $rcStatusStmt->execute($rcParams);
    $rcMeta = $rcStatusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $rcStatus = strtolower(trim((string)($rcMeta['status'] ?? '')));
    $reportCardApproved = ($rcStatus === 'approved');
    $reportCardReviewedBy = trim((string)($rcMeta['reviewed_by_name'] ?? ''));
    $reportCardReviewedAt = trim((string)($rcMeta['reviewed_at'] ?? ''));
    if (!$reportCardApproved) {
        $reportCardPendingMessage = $rcStatus === 'pending'
            ? 'Your report card is submitted and awaiting admin approval.'
            : ($rcStatus === 'rejected'
                ? 'Your report card submission was rejected and is being corrected by your adviser.'
                : 'Your report card is not yet submitted by your adviser.');
    }

    $reportRows = [];
    if (!empty($classIds) && $reportCardApproved) {
        $termCaseSql = [];
        foreach ($termLabels as $tl) {
            $termCaseSql[] = "MAX(CASE WHEN g.{$tc} = '{$tl}' THEN g.final_grade END) AS `{$tl}`";
        }
        $termCaseStr = implode(",\n", $termCaseSql);

        $gradeStmt = $db->prepare("SELECT c.id, c.class_name, c.subject_category,
                                          {$termCaseStr}
                                   FROM classes c
                                   JOIN enrollments e ON e.class_id = c.id
                                                     AND e.student_id = ?
                                                     AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                   LEFT JOIN class_subjects cs ON cs.class_id = c.id
                                   LEFT JOIN grades g ON g.class_subject_id = cs.id
                                                     AND g.student_id = ?
                                                     AND g.academic_year = ?
                                                     {$semesterCondition}
                                   WHERE c.status = 'active'
                                   GROUP BY c.id, c.class_name, c.subject_category
                                   ORDER BY c.class_name");
        $gradeParams = [$studentId, $studentId, $selectedAcademicYear];
        if ($isQuarterSystem) {
            $gradeParams[] = $selectedSemesterForQuery;
        }
        $gradeStmt->execute($gradeParams);
        $reportRows = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $gwaSum = 0.0;
    $gwaCount = 0;
    foreach ($reportRows as $row) {
        $termGrades = [];
        foreach ($termLabels as $tl) {
            $gv = $row[$tl] ?? null;
            $termGrades[] = $gv !== null ? (float)$gv : null;
        }
        $cat = $row['subject_category'] ?? 'core';
        $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
        $semFinal = SshsGradeCalculator::finalGrade(array_slice($termGrades, 0, $termCount));

        if ($semFinal !== null) {
            $gwaSum += $semFinal;
            $gwaCount++;
        }

        $subjectRows[] = [
            'subject' => (string)$row['class_name'],
            'term_grades' => $termGrades,
            'semester_final' => $semFinal,
            'remarks' => $semFinal === null ? 'N/A' : ($semFinal >= 75 ? 'Passed' : 'Failed')
        ];
    }
    $summary['subjects'] = count($subjectRows);
    $summary['gwa'] = $gwaCount > 0 ? round($gwaSum / $gwaCount, 2) : null;

    if ($reportCardApproved) {
        $attendanceClassIds = array_values(array_unique(array_map('intval', array_column($reportRows, 'id'))));
        if (empty($attendanceClassIds)) {
            $attendanceClassIds = $classIds;
        }
        [$dateFrom, $dateTo] = srcPeriodBounds($selectedAcademicYear, $selectedSemester, $gs);

        $attSql = "SELECT SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                          SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                          SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late_count
                   FROM attendance
                   WHERE student_id = ?";
        $attParams = [$studentId];
        if (!empty($attendanceClassIds)) {
            $placeholders = implode(',', array_fill(0, count($attendanceClassIds), '?'));
            $attSql .= " AND class_id IN ($placeholders)";
            $attParams = array_merge($attParams, $attendanceClassIds);
        }
        if ($hasAttendanceTerms && $isQuarterSystem) {
            $attSql .= " AND academic_year = ? AND semester = ?";
            $attParams[] = $selectedAcademicYear;
            $attParams[] = $semesterNo;
        } elseif ($hasAttendanceYear) {
            $attSql .= " AND academic_year = ?";
            $attParams[] = $selectedAcademicYear;
        } else {
            $attSql .= " AND date BETWEEN ? AND ?";
            $attParams[] = $dateFrom;
            $attParams[] = $dateTo;
        }

        $attStmt = $db->prepare($attSql);
        $attStmt->execute($attParams);
        $attRow = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $attendance['present'] = (int)($attRow['present_count'] ?? 0);
        $attendance['absent'] = (int)($attRow['absent_count'] ?? 0);
        $attendance['late'] = (int)($attRow['late_count'] ?? 0);
        $totalAtt = $attendance['present'] + $attendance['absent'] + $attendance['late'];
        $attendance['rate'] = $totalAtt > 0 ? round(($attendance['present'] / $totalAtt) * 100, 2) : 0;
    }
}

$current_role = 'student';
$current_page = 'report_card';
$page_title = 'Report Card';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Report Card</h4>
                <p class="text-muted mb-0">Review your released grades and generated report card details.</p>
            </div>
        </div>
        <div class="content-card mb-4">
            <div class="content-card-header"><h5 class="content-card-title">Report Card</h5></div>
            <div class="content-card-body">
                <form method="GET" class="row g-3 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label"><?php echo $isQuarterSystem ? 'Semester' : 'Period'; ?></label>
                        <?php if ($isQuarterSystem): ?>
                            <select id="studentReportSemesterSelect" name="semester" class="form-select">
                                <option value="S1" <?php echo $selectedSemester === 'S1' ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="S2" <?php echo $selectedSemester === 'S2' ? 'selected' : ''; ?>>Semester 2</option>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-control" value="Full Year" disabled>
                            <input type="hidden" name="semester" value="">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Academic Year</label>
                        <input type="text" name="academic_year" class="form-control" value="<?php echo htmlspecialchars($selectedAcademicYear); ?>" placeholder="YYYY-YYYY">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-secondary-custom w-100"><i class="bi bi-arrow-clockwise me-1"></i>Load</button>
                    </div>
                </form>

                <div class="alert alert-light border">
                    Student: <strong><?php echo htmlspecialchars($studentInfo['name'] ?: 'N/A'); ?></strong>
                    <?php if ($studentInfo['reference_code'] !== ''): ?>
                        | Reference: <strong><?php echo htmlspecialchars($studentInfo['reference_code']); ?></strong>
                    <?php endif; ?>
                    <?php if ($studentInfo['grade_level'] !== '' || $studentInfo['section'] !== ''): ?>
                        | Section: <strong>G<?php echo htmlspecialchars($studentInfo['grade_level']); ?> - <?php echo htmlspecialchars($studentInfo['section']); ?></strong>
                    <?php endif; ?>
                </div>
                <?php if (!$reportCardApproved): ?>
                    <div class="alert alert-warning mb-3"><?php echo htmlspecialchars($reportCardPendingMessage); ?></div>
                <?php elseif ($reportCardReviewedAt !== ''): ?>
                    <div class="alert alert-success mb-3">
                        Approved by <strong><?php echo htmlspecialchars($reportCardReviewedBy !== '' ? $reportCardReviewedBy : 'Admin'); ?></strong>
                        on <strong><?php echo htmlspecialchars((string)date('M d, Y h:i A', strtotime($reportCardReviewedAt))); ?></strong>.
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Subjects</div><div class="card-value"><?php echo number_format((int)$summary['subjects']); ?></div></div></div>
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">GWA</div><div class="card-value"><?php echo $summary['gwa'] !== null ? number_format((float)$summary['gwa'], 2) : 'N/A'; ?></div></div></div>
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Attendance Rate</div><div class="card-value"><?php echo number_format((float)$attendance['rate'], 2); ?>%</div></div></div>
                </div>

                <div class="table-container mb-3">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <?php foreach ($termLabels as $tl): ?>
                                    <th><?php echo htmlspecialchars(SshsGradeCalculator::termLabel($tl)); ?></th>
                                <?php endforeach; ?>
                                <th>Final Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subjectRows)): ?>
                                <tr><td colspan="<?php echo 3 + count($termLabels); ?>" class="text-center text-muted py-4">No report card records found for selected term.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subjectRows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$row['subject']); ?></td>
                                        <?php foreach ($termLabels as $idx => $tl): ?>
                                            <?php $val = $row['term_grades'][$idx] ?? null; ?>
                                            <td><?php echo $val !== null ? htmlspecialchars(number_format((float)$val, 2)) : 'N/A'; ?></td>
                                        <?php endforeach; ?>
                                        <td><strong><?php echo $row['semester_final'] !== null ? htmlspecialchars(number_format((float)$row['semester_final'], 2)) : 'N/A'; ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)$row['remarks']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row g-3">
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Present</div><div class="card-value"><?php echo number_format((int)$attendance['present']); ?></div></div></div>
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Absent</div><div class="card-value"><?php echo number_format((int)$attendance['absent']); ?></div></div></div>
                    <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Late</div><div class="card-value"><?php echo number_format((int)$attendance['late']); ?></div></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
