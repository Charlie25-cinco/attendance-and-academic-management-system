<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$parentId = (int)($_SESSION['user_id'] ?? 0);

function prcCurrentAcademicYear() {
    $year = (int)date('Y');
    $month = (int)date('n');
    $start = $month >= 6 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}

function prcCurrentSemester() {
    $month = (int)date('n');
    return ($month >= 12 || $month <= 5) ? 'S2' : 'S1';
}

function prcPeriodBounds($academicYear, $semester) {
    preg_match('/^(\d{4})-(\d{4})$/', (string)$academicYear, $m);
    $startYear = (int)($m[1] ?? date('Y'));
    $endYear = (int)($m[2] ?? ($startYear + 1));
    if ($semester === 'S2') {
        return [$startYear . '-12-01', $endYear . '-05-31'];
    }
    return [$startYear . '-06-01', $startYear . '-11-30'];
}

$children = [];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
$linkNotice = '';

$selectedSemester = $_GET['semester'] ?? prcCurrentSemester();
if (!in_array($selectedSemester, ['S1', 'S2'], true)) {
    $selectedSemester = prcCurrentSemester();
}
$selectedAcademicYear = trim((string)($_GET['academic_year'] ?? prcCurrentAcademicYear()));
if (!preg_match('/^\d{4}-\d{4}$/', $selectedAcademicYear)) {
    $selectedAcademicYear = prcCurrentAcademicYear();
}
$semesterNo = $selectedSemester === 'S2' ? 2 : 1;
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

$studentInfo = ['name' => '', 'reference_code' => '', 'grade_level' => '', 'section' => ''];
$subjectRows = [];
$summary = ['subjects' => 0, 'gwa' => null];
$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'rate' => 0];
$classIds = [];

$reportCardApproved = false;
$reportCardPendingMessage = '';
$reportCardReviewedBy = '';
$reportCardReviewedAt = '';

if ($db && $parentId > 0) {
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

    $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                  FROM parent_students ps
                                  JOIN users u ON u.id = ps.student_id
                                  WHERE ps.parent_id = ? AND u.role = 'student' AND u.status IN ('active', 'pending')
                                  ORDER BY u.last_name, u.first_name");
    $childrenStmt->execute([$parentId]);
    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($children)) {
        $validIds = array_map('intval', array_column($children, 'id'));
        if (!in_array($selectedStudentId, $validIds, true)) {
            $selectedStudentId = (int)$children[0]['id'];
        }
        foreach ($children as $child) {
            if ((int)$child['id'] === $selectedStudentId) {
                $selectedStudent = $child;
                break;
            }
        }
    } else {
        $selectedStudentId = 0;
        $linkNotice = 'No linked student found. Ask the admin to link your account to a student first.';
    }

    if ($selectedStudentId > 0) {
        $hasQuarter = dbHasColumn($db, 'grades', 'quarter');
        $tc = 'term';
        if ($hasQuarter) {
            $tc = dbHasColumn($db, 'grades', 'term') ? 'term' : 'quarter';
        }

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
        $classStmt->execute([$selectedStudentId]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($classes)) {
            $studentInfo['name'] = trim(($selectedStudent['first_name'] ?? '') . ' ' . ($selectedStudent['last_name'] ?? ''));
            $studentInfo['reference_code'] = trim((string)($selectedStudent['reference_code'] ?? ''));
            $studentInfo['grade_level'] = (string)$classes[0]['grade_level'];
            $studentInfo['section'] = (string)$classes[0]['section'];
            $classIds = array_map('intval', array_column($classes, 'id'));
        }

        $rcSemCondition = $semesterCondition !== ''
            ? 'AND rc.semester = ?'
            : 'AND rc.semester IS NULL';
        $rcStatusStmt = $db->prepare("SELECT rc.status, rc.reviewed_at,
                                             CONCAT(r.first_name, ' ', r.last_name) AS reviewed_by_name
                                      FROM report_card_approvals rc
                                      LEFT JOIN users r ON r.id = rc.reviewed_by
                                      WHERE student_id = ? AND academic_year = ?
                                      {$rcSemCondition}
                                      LIMIT 1");
        $rcParams = [$selectedStudentId, $selectedAcademicYear];
        if ($semesterCondition !== '') {
            $rcParams[] = $selectedSemester;
        }
        $rcStatusStmt->execute($rcParams);
        $rcMeta = $rcStatusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $rcStatus = strtolower(trim((string)($rcMeta['status'] ?? '')));
        $reportCardApproved = ($rcStatus === 'approved');
        $reportCardReviewedBy = trim((string)($rcMeta['reviewed_by_name'] ?? ''));
        $reportCardReviewedAt = trim((string)($rcMeta['reviewed_at'] ?? ''));
        if (!$reportCardApproved) {
            $reportCardPendingMessage = $rcStatus === 'submitted_admin'
                ? 'This report card is awaiting admin approval.'
                : ($rcStatus === 'rejected'
                    ? 'This report card submission was rejected and is being corrected by the adviser.'
                    : 'This report card has not been released yet.');
        }

        $reportRows = [];
        if (!empty($classIds) && $reportCardApproved) {
            $termCaseSql = [];
            foreach ($termLabels as $tl) {
                $termCaseSql[] = "MAX(CASE WHEN g.{$tc} = '{$tl}' THEN g.final_grade END) AS `{$tl}`";
            }
            $termCaseStr = implode(",\n", $termCaseSql);

            $gradeSql = "SELECT c.id, c.class_name, c.subject_category,
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
                         ORDER BY c.class_name";

            $params = [$selectedStudentId, $selectedStudentId, $selectedAcademicYear];
            if ($semesterCondition !== '') {
                $params[] = $selectedSemesterForQuery;
            }
            $gradeStmt = $db->prepare($gradeSql);
            $gradeStmt->execute($params);
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
            $effectiveGrades = array_slice($termGrades, 0, $termCount);
            $finalGrade = SshsGradeCalculator::finalGrade($effectiveGrades);

            if ($finalGrade !== null) {
                $gwaSum += $finalGrade;
                $gwaCount++;
            }

            $subjectRows[] = [
                'subject' => (string)$row['class_name'],
                'term_grades' => $termGrades,
                'final_grade' => $finalGrade,
                'remarks' => $finalGrade === null ? 'N/A' : ($finalGrade >= 75 ? 'Passed' : 'Failed')
            ];
        }
        $summary['subjects'] = count($subjectRows);
        $summary['gwa'] = $gwaCount > 0 ? round($gwaSum / $gwaCount, 2) : null;

        if ($reportCardApproved) {
            $attendanceClassIds = array_values(array_unique(array_map('intval', array_column($reportRows, 'id'))));
            if (empty($attendanceClassIds)) {
                $attendanceClassIds = $classIds;
            }
            preg_match('/^(\d{4})-(\d{4})$/', (string)$selectedAcademicYear, $m);
            $yearStart = (int)($m[1] ?? date('Y'));
            $yearEnd = (int)($m[2] ?? ($yearStart + 1));
            if ($gs === '3_term') {
                [$dateFrom, $dateTo] = [$yearStart . '-06-01', $yearEnd . '-05-31'];
            } else {
                [$dateFrom, $dateTo] = $selectedSemester === 'S2'
                    ? [$yearStart . '-12-01', $yearEnd . '-05-31']
                    : [$yearStart . '-06-01', $yearStart . '-11-30'];
            }

            $attSql = "SELECT SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                              SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                              SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late_count
                       FROM attendance
                       WHERE student_id = ?";
            $attParams = [$selectedStudentId];
            if (!empty($attendanceClassIds)) {
                $placeholders = implode(',', array_fill(0, count($attendanceClassIds), '?'));
                $attSql .= " AND class_id IN ($placeholders)";
                $attParams = array_merge($attParams, $attendanceClassIds);
            }
            if ($hasAttendanceTerms) {
                $attSql .= " AND academic_year = ? AND semester = ?";
                $attParams[] = $selectedAcademicYear;
                $attParams[] = $semesterNo;
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
}

$current_role = 'parent';
$current_page = 'report_card';
$page_title = 'Report Card';
$studentSectionLabel = trim(($studentInfo['grade_level'] !== '' ? 'G' . $studentInfo['grade_level'] : '') . ($studentInfo['section'] !== '' ? ' - ' . $studentInfo['section'] : ''), ' -');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
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
        <section class="parent-hero parent-hero-compact mb-4">
            <div class="parent-hero-grid">
                <div class="parent-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-file-earmark-text"></i><span>Parent Report Card</span></div>
                    <h4 class="mb-2">Review released report cards with family-friendly context.</h4>
                    <p class="text-muted mb-3">Switch between linked students, load a school year or semester, and review grades only after the report card has been officially approved.</p>
                    <div class="parent-chip-row">
                        <?php if ($studentInfo['name'] !== ''): ?><span class="parent-chip"><i class="bi bi-person"></i><?php echo htmlspecialchars($studentInfo['name']); ?></span><?php endif; ?>
                        <?php if ($studentSectionLabel !== ''): ?><span class="parent-chip"><i class="bi bi-mortarboard"></i><?php echo htmlspecialchars($studentSectionLabel); ?></span><?php endif; ?>
                        <span class="parent-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($selectedAcademicYear); ?></span>
                    </div>
                </div>
                <div class="parent-hero-side">
                    <div class="parent-summary-panel">
                        <div class="parent-summary-grid">
                            <div class="parent-summary-stat"><span class="parent-summary-label">Subjects</span><strong class="parent-summary-value"><?php echo number_format((int)$summary['subjects']); ?></strong></div>
                            <div class="parent-summary-stat"><span class="parent-summary-label">GWA</span><strong class="parent-summary-value"><?php echo $summary['gwa'] !== null ? number_format((float)$summary['gwa'], 2) : 'N/A'; ?></strong></div>
                        </div>
                        <p class="parent-summary-note"><?php echo $reportCardApproved ? 'This report card has been released.' : 'This report card is still waiting for final release.'; ?></p>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($linkNotice !== ''): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars($linkNotice); ?></div>
        <?php endif; ?>

        <?php if (!empty($children)): ?>
            <div class="content-card mb-4">
                <div class="content-card-header"><h5 class="content-card-title">Select Student</h5></div>
                <div class="content-card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3 parent-switcher">
                        <?php foreach ($children as $child): ?>
                            <a href="?student_id=<?php echo (int)$child['id']; ?>&semester=<?php echo urlencode($selectedSemester); ?>&academic_year=<?php echo urlencode($selectedAcademicYear); ?>"
                               class="btn btn-sm <?php echo ((int)$child['id'] === $selectedStudentId) ? 'btn-primary-custom' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedStudentId > 0): ?>
            <div class="content-card mb-4">
                <div class="content-card-header"><h5 class="content-card-title">Report Card</h5></div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 align-items-end mb-3 parent-filter-shell app-responsive-filter-form">
                        <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudentId; ?>">
                        <div class="col-md-3">
                            <label class="form-label"><?php echo $isQuarterSystem ? 'Semester' : 'Period'; ?></label>
                            <?php if ($isQuarterSystem): ?>
                                <select name="semester" class="form-select">
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
                            | Section: <strong><?php if ($studentInfo['grade_level'] !== ''): ?>G<?php echo htmlspecialchars($studentInfo['grade_level']); ?><?php endif; ?><?php if ($studentInfo['grade_level'] !== '' && $studentInfo['section'] !== ''): ?> - <?php endif; ?><?php echo htmlspecialchars((string)$studentInfo['section']); ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!$reportCardApproved && $reportCardPendingMessage !== ''): ?>
                        <div class="alert alert-warning mb-3"><?php echo htmlspecialchars($reportCardPendingMessage); ?></div>
                    <?php elseif ($reportCardApproved && $reportCardReviewedAt !== ''): ?>
                        <div class="alert alert-success mb-3">
                            Approved by <strong><?php echo htmlspecialchars($reportCardReviewedBy !== '' ? $reportCardReviewedBy : 'Admin'); ?></strong>
                            on <strong><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($reportCardReviewedAt))); ?></strong>.
                        </div>
                    <?php endif; ?>
                    <div class="alert alert-light border mb-3">
                        Grading System: <strong><?php echo $gs === '4_quarter' ? '4-Quarter' : '3-Term'; ?></strong>
                    </div>

                    <div class="parent-overview-grid mb-3">
                        <div class="parent-overview-card"><strong><?php echo number_format((int)$summary['subjects']); ?></strong><span>Subjects</span></div>
                        <div class="parent-overview-card"><strong><?php echo $summary['gwa'] !== null ? number_format((float)$summary['gwa'], 2) : 'N/A'; ?></strong><span>GWA</span></div>
                        <div class="parent-overview-card"><strong><?php echo number_format((float)$attendance['rate'], 2); ?>%</strong><span>Attendance Rate</span></div>
                        <div class="parent-overview-card"><strong><?php echo $reportCardApproved ? 'Released' : 'Pending'; ?></strong><span>Release Status</span></div>
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
                                    <tr><td colspan="<?php echo 2 + count($termLabels); ?>" class="text-center text-muted py-4">No report card records found for selected term.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($subjectRows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['subject']); ?></td>
                                            <?php foreach ($termLabels as $tl): ?>
                                                <td><?php
                                                    $idx = array_search($tl, $termLabels);
                                                    $val = ($idx !== false && isset($row['term_grades'][$idx])) ? $row['term_grades'][$idx] : null;
                                                    echo $val !== null ? htmlspecialchars(number_format((float)$val, 2)) : '—';
                                                ?></td>
                                            <?php endforeach; ?>
                                            <td><strong><?php echo $row['final_grade'] !== null ? htmlspecialchars(number_format((float)$row['final_grade'], 2)) : 'N/A'; ?></strong></td>
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
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
