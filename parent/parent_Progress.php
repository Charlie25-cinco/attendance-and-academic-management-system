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

$current_role = 'parent';
$current_page = 'progress';
$page_title = 'Student Progress';

$children = [];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
$stats = ['gwa' => 0, 'subjects' => 0, 'attendance_rate' => 0];
$attendance = ['present' => 0, 'absent' => 0, 'late' => 0];
$attendanceRows = [];
$activityRows = [];
$approvalRows = [];
$gradeTrendRows = [];
$studentClasses = [];
$alerts = [];
$progressRows = [];
$approvalMetaByTerm = [];

$attendanceFrom = trim((string)($_GET['att_from'] ?? ''));
$attendanceTo = trim((string)($_GET['att_to'] ?? ''));
$activityFrom = trim((string)($_GET['act_from'] ?? ''));
$activityTo = trim((string)($_GET['act_to'] ?? ''));
$activityClassId = (int)($_GET['act_class_id'] ?? 0);

function normalizeDateValue($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    return $value;
}

function normalizeDateRange(&$from, &$to) {
    $from = normalizeDateValue($from);
    $to = normalizeDateValue($to);
    if ($from !== '' && $to !== '' && $from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }
}

normalizeDateRange($attendanceFrom, $attendanceTo);
normalizeDateRange($activityFrom, $activityTo);

function parentOwnsStudent(array $children, $studentId) {
    foreach ($children as $child) {
        if ((int)$child['id'] === (int)$studentId) return true;
    }
    return false;
}

function ensureReportCardApprovalsTableForParent($db) {
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

if ($db && $parentId > 0) {
    ensureReportCardApprovalsTableForParent($db);
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
    }

    if ($selectedStudentId > 0) {
        $has_quarter = dbHasColumn($db, 'grades', 'quarter');
        $hasQuarter = $has_quarter;
        $tc = 'term';
        if ($hasQuarter) {
            $has_term = dbHasColumn($db, 'grades', 'term');
            $tc = $has_term ? 'term' : 'quarter';
        }

        $metaStmt = $db->prepare("SELECT rc.academic_year, rc.semester, rc.status, rc.remarks, rc.reviewed_at,
                                         CONCAT(r.first_name, ' ', r.last_name) AS reviewed_by_name,
                                         CONCAT(t.first_name, ' ', t.last_name) AS adviser_name
                                  FROM report_card_approvals rc
                                  LEFT JOIN users r ON r.id = rc.reviewed_by
                                  LEFT JOIN users t ON t.id = rc.advisory_teacher_id
                                  WHERE rc.student_id = ?
                                  ORDER BY rc.academic_year DESC, rc.semester DESC");
        $metaStmt->execute([$selectedStudentId]);
        foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $meta) {
            $key = trim((string)$meta['academic_year']) . '|' . trim((string)$meta['semester']);
            $approvalMetaByTerm[$key] = [
                'status' => trim((string)($meta['status'] ?? '')),
                'remarks' => trim((string)($meta['remarks'] ?? '')),
                'reviewed_by_name' => trim((string)($meta['reviewed_by_name'] ?? '')),
                'reviewed_at' => trim((string)($meta['reviewed_at'] ?? '')),
                'adviser_name' => trim((string)($meta['adviser_name'] ?? ''))
            ];
            $approvalRows[] = $meta;
        }

        $attParams = [$selectedStudentId];
        $attWhere = "WHERE student_id = ?";
        if ($attendanceFrom !== '') {
            $attWhere .= " AND date >= ?";
            $attParams[] = $attendanceFrom;
        }
        if ($attendanceTo !== '') {
            $attWhere .= " AND date <= ?";
            $attParams[] = $attendanceTo;
        }
        $attStmt = $db->prepare("SELECT
                                 COUNT(CASE WHEN status = 'present' THEN 1 END) AS present_count,
                                 COUNT(CASE WHEN status = 'absent' THEN 1 END) AS absent_count,
                                 COUNT(CASE WHEN status = 'late' THEN 1 END) AS late_count,
                                 COUNT(*) AS total_count
                                 FROM attendance
                                 $attWhere");
        $attStmt->execute($attParams);
        $attRow = $attStmt->fetch(PDO::FETCH_ASSOC);
        $present = (int)($attRow['present_count'] ?? 0);
        $absent = (int)($attRow['absent_count'] ?? 0);
        $late = (int)($attRow['late_count'] ?? 0);
        $total = (int)($attRow['total_count'] ?? 0);
        $attendance['present'] = $present;
        $attendance['absent'] = $absent;
        $attendance['late'] = $late;
        if ($total > 0) {
            $stats['attendance_rate'] = round((($present + $late) / $total) * 100, 1);
        }

        $attRowsStmt = $db->prepare("SELECT a.date, a.status, a.time_in, a.remarks,
                                            c.class_name, c.grade_level, c.section
                                     FROM attendance a
                                     LEFT JOIN classes c ON c.id = a.class_id
                                     $attWhere
                                     ORDER BY a.date DESC, a.id DESC
                                     LIMIT 50");
        $attRowsStmt->execute($attParams);
        $attendanceRows = $attRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $subjectStmt = $db->prepare("SELECT COUNT(DISTINCT class_id) FROM enrollments WHERE student_id = ? AND status = 'enrolled'");
        $subjectStmt->execute([$selectedStudentId]);
        $stats['subjects'] = (int)$subjectStmt->fetchColumn();

        $classListStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                                       FROM enrollments e
                                       JOIN classes c ON c.id = e.class_id
                                       WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                                       ORDER BY c.class_name ASC");
        $classListStmt->execute([$selectedStudentId]);
        $studentClasses = $classListStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($hasQuarter) {
            $gwaStmt = $db->prepare("SELECT AVG(x.semester_grade)
                                     FROM (
                                        SELECT class_subject_id, semester, academic_year, AVG(final_grade) AS semester_grade
                                        FROM grades
                                        WHERE student_id = ? AND final_grade IS NOT NULL AND {$tc} IN ('Q1', 'Q2')
                                        AND EXISTS (
                                            SELECT 1 FROM report_card_approvals rc
                                            WHERE rc.student_id = grades.student_id
                                            AND rc.academic_year = grades.academic_year
                                            AND rc.semester <=> grades.semester
                                            AND rc.status = 'approved'
                                        )
                                        GROUP BY class_subject_id, semester, academic_year
                                     ) x");
            $gwaStmt->execute([$selectedStudentId]);
            $stats['gwa'] = round((float)($gwaStmt->fetchColumn() ?: 0), 2);

            $rowsStmt = $db->prepare("SELECT c.class_name, x.academic_year, x.semester,
                                      x.ww_avg, x.assessment_avg, x.pt_avg, x.semester_grade
                                      FROM (
                                        SELECT class_subject_id, academic_year, semester,
                                               ROUND(AVG(quiz_score), 2) AS ww_avg,
                                               ROUND(AVG(exam_score), 2) AS assessment_avg,
                                               ROUND(AVG(activity_score), 2) AS pt_avg,
                                               ROUND(AVG(final_grade), 2) AS semester_grade
                                        FROM grades
                                        WHERE student_id = ? AND {$tc} IN ('Q1', 'Q2')
                                        AND EXISTS (
                                            SELECT 1 FROM report_card_approvals rc
                                            WHERE rc.student_id = grades.student_id
                                            AND rc.academic_year = grades.academic_year
                                            AND rc.semester <=> grades.semester
                                            AND rc.status = 'approved'
                                        )
                                        GROUP BY class_subject_id, academic_year, semester
                                      ) x
                                      JOIN class_subjects cs ON cs.id = x.class_subject_id
                                      JOIN classes c ON c.id = cs.class_id
                                      ORDER BY x.academic_year DESC, x.semester DESC, c.class_name ASC");
            $rowsStmt->execute([$selectedStudentId]);

            $trendStmt = $db->prepare("SELECT academic_year, semester, AVG(semester_grade) AS term_gwa
                                       FROM (
                                            SELECT class_subject_id, academic_year, semester, AVG(final_grade) AS semester_grade
                                            FROM grades
                                        WHERE student_id = ? AND final_grade IS NOT NULL AND {$tc} IN ('Q1', 'Q2')
                                            AND EXISTS (
                                                SELECT 1 FROM report_card_approvals rc
                                                WHERE rc.student_id = grades.student_id
                                                AND rc.academic_year = grades.academic_year
                                                AND rc.semester <=> grades.semester
                                                AND rc.status = 'approved'
                                            )
                                            GROUP BY class_subject_id, academic_year, semester
                                       ) x
                                       GROUP BY academic_year, semester
                                       ORDER BY academic_year ASC, semester ASC");
            $trendStmt->execute([$selectedStudentId]);
            $gradeTrendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $gwaStmt = $db->prepare("SELECT AVG(final_grade)
                                     FROM grades
                                     WHERE student_id = ? AND final_grade IS NOT NULL
                                     AND EXISTS (
                                        SELECT 1 FROM report_card_approvals rc
                                        WHERE rc.student_id = grades.student_id
                                        AND rc.academic_year = grades.academic_year
                                        AND rc.semester <=> grades.semester
                                        AND rc.status = 'approved'
                                     )");
            $gwaStmt->execute([$selectedStudentId]);
            $stats['gwa'] = round((float)($gwaStmt->fetchColumn() ?: 0), 2);

            $rowsStmt = $db->prepare("SELECT c.class_name, g.academic_year, g.semester,
                                      ROUND(AVG(g.quiz_score), 2) AS ww_avg,
                                      ROUND(AVG(g.exam_score), 2) AS assessment_avg,
                                      ROUND(AVG(g.activity_score), 2) AS pt_avg,
                                      ROUND(AVG(g.final_grade), 2) AS semester_grade
                                      FROM grades g
                                      JOIN class_subjects cs ON cs.id = g.class_subject_id
                                      JOIN classes c ON c.id = cs.class_id
                                      WHERE g.student_id = ?
                                      AND EXISTS (
                                        SELECT 1 FROM report_card_approvals rc
                                        WHERE rc.student_id = g.student_id
                                        AND rc.academic_year = g.academic_year
                                        AND rc.semester <=> g.semester
                                        AND rc.status = 'approved'
                                      )
                                      GROUP BY g.class_subject_id, g.academic_year, g.semester
                                      ORDER BY g.academic_year DESC, g.semester DESC, c.class_name ASC");
            $rowsStmt->execute([$selectedStudentId]);

            $trendStmt = $db->prepare("SELECT academic_year, semester, AVG(final_grade) AS term_gwa
                                       FROM grades
                                       WHERE student_id = ? AND final_grade IS NOT NULL
                                       AND EXISTS (
                                            SELECT 1 FROM report_card_approvals rc
                                            WHERE rc.student_id = grades.student_id
                                            AND rc.academic_year = grades.academic_year
                                            AND rc.semester <=> grades.semester
                                            AND rc.status = 'approved'
                                       )
                                       GROUP BY academic_year, semester
                                       ORDER BY academic_year ASC, semester ASC");
            $trendStmt->execute([$selectedStudentId]);
            $gradeTrendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $progressRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $activityParams = [$selectedStudentId];
        $activityWhere = "WHERE gis.student_id = ?";
        if ($activityClassId > 0) {
            $activityWhere .= " AND gi.class_id = ?";
            $activityParams[] = $activityClassId;
        }
        if ($activityFrom !== '') {
            $activityWhere .= " AND gi.activity_date >= ?";
            $activityParams[] = $activityFrom;
        }
        if ($activityTo !== '') {
            $activityWhere .= " AND gi.activity_date <= ?";
            $activityParams[] = $activityTo;
        }
        $activityStmt = $db->prepare("SELECT gi.id, gi.title, gi.component, gi.activity_date, gi.total_score,
                                             gis.score, c.class_name, c.grade_level, c.section
                                      FROM grade_item_scores gis
                                      JOIN grade_items gi ON gi.id = gis.grade_item_id
                                      JOIN classes c ON c.id = gi.class_id
                                      $activityWhere
                                      ORDER BY gi.activity_date DESC, gi.id DESC
                                      LIMIT 60");
        $activityStmt->execute($activityParams);
        $activityRows = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($total >= 10 && $stats['attendance_rate'] > 0 && $stats['attendance_rate'] < 90) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Attendance below target',
                'message' => 'Attendance rate is ' . $stats['attendance_rate'] . '%. Consider checking recent absences.'
            ];
        }
        $lowGrade = null;
        foreach ($progressRows as $row) {
            $fg = $row['semester_grade'] !== null ? (float)$row['semester_grade'] : null;
            if ($fg !== null && ($lowGrade === null || $fg < $lowGrade)) {
                $lowGrade = $fg;
            }
        }
        if ($lowGrade !== null && $lowGrade < 75) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Low semester grade detected',
                'message' => 'Lowest semester grade is ' . number_format($lowGrade, 2) . '.'
            ];
        }
        if (empty($progressRows)) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'No approved grades yet',
                'message' => 'Grades will appear once the report card is approved by admin.'
            ];
        }
    }
}

if ($db && $parentId > 0 && ($_GET['action'] ?? '') === 'export') {
    $exportStudentId = (int)($_GET['student_id'] ?? 0);
    if ($exportStudentId <= 0 || !parentOwnsStudent($children, $exportStudentId)) {
        header("Location: Parent_Progress.php");
        exit();
    }
    $type = trim((string)($_GET['type'] ?? ''));
    $filenameSuffix = date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    if ($type === 'attendance') {
        $exportFrom = trim((string)($_GET['att_from'] ?? ''));
        $exportTo = trim((string)($_GET['att_to'] ?? ''));
        normalizeDateRange($exportFrom, $exportTo);
        $params = [$exportStudentId];
        $where = "WHERE a.student_id = ?";
        if ($exportFrom !== '') { $where .= " AND a.date >= ?"; $params[] = $exportFrom; }
        if ($exportTo !== '') { $where .= " AND a.date <= ?"; $params[] = $exportTo; }
        $stmt = $db->prepare("SELECT a.date, c.class_name, c.grade_level, c.section, a.status, a.time_in, a.remarks
                              FROM attendance a
                              LEFT JOIN classes c ON c.id = a.class_id
                              $where
                              ORDER BY a.date DESC, a.id DESC");
        $stmt->execute($params);
        header('Content-Disposition: attachment; filename=attendance_' . $filenameSuffix . '.csv');
        fputcsv($out, ['Date', 'Class', 'Grade', 'Section', 'Status', 'Time In', 'Remarks']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, [
                $row['date'],
                $row['class_name'] ?? '',
                $row['grade_level'] ?? '',
                $row['section'] ?? '',
                $row['status'],
                $row['time_in'] ?? '',
                $row['remarks'] ?? ''
            ]);
        }
        fclose($out);
        exit();
    }
    if ($type === 'grades') {
        $qCheck2 = $db->query("SHOW COLUMNS FROM grades LIKE 'quarter'");
        $hasQuarter = (bool)$qCheck2->fetch(PDO::FETCH_ASSOC);
        $tc2 = 'term';
        if ($hasQuarter) {
            $tCols2 = $db->query("SHOW COLUMNS FROM grades LIKE 'term'");
            $tc2 = (bool)$tCols2->fetch(PDO::FETCH_ASSOC) ? 'term' : 'quarter';
        }
        if ($hasQuarter) {
            $stmt = $db->prepare("SELECT c.class_name, x.academic_year, x.semester,
                                          x.ww_avg, x.assessment_avg, x.pt_avg, x.semester_grade
                                   FROM (
                                        SELECT class_subject_id, academic_year, semester,
                                               ROUND(AVG(quiz_score), 2) AS ww_avg,
                                               ROUND(AVG(exam_score), 2) AS assessment_avg,
                                               ROUND(AVG(activity_score), 2) AS pt_avg,
                                               ROUND(AVG(final_grade), 2) AS semester_grade
                                        FROM grades
                                         WHERE student_id = ? AND {$tc2} IN ('Q1', 'Q2')
                                         AND EXISTS (
                                             SELECT 1 FROM report_card_approvals rc
                                             WHERE rc.student_id = grades.student_id
                                             AND rc.academic_year = grades.academic_year
                                             AND rc.semester <=> grades.semester
                                             AND rc.status = 'approved'
                                         )
                                         GROUP BY class_subject_id, academic_year, semester
                                    ) x
                                    JOIN class_subjects cs ON cs.id = x.class_subject_id
                                    JOIN classes c ON c.id = cs.class_id
                                    ORDER BY x.academic_year DESC, x.semester DESC, c.class_name ASC");
            $stmt->execute([$exportStudentId]);
        } else {
            $stmt = $db->prepare("SELECT c.class_name, g.academic_year, g.semester,
                                          ROUND(AVG(g.quiz_score), 2) AS ww_avg,
                                          ROUND(AVG(g.exam_score), 2) AS assessment_avg,
                                          ROUND(AVG(g.activity_score), 2) AS pt_avg,
                                          ROUND(AVG(g.final_grade), 2) AS semester_grade
                                   FROM grades g
                                   JOIN class_subjects cs ON cs.id = g.class_subject_id
                                   JOIN classes c ON c.id = cs.class_id
                                   WHERE g.student_id = ?
                                   AND EXISTS (
                                        SELECT 1 FROM report_card_approvals rc
                                        WHERE rc.student_id = g.student_id
                                        AND rc.academic_year = g.academic_year
                                        AND rc.semester <=> g.semester
                                        AND rc.status = 'approved'
                                   )
                                   GROUP BY g.class_subject_id, g.academic_year, g.semester
                                   ORDER BY g.academic_year DESC, g.semester DESC, c.class_name ASC");
            $stmt->execute([$exportStudentId]);
        }
        header('Content-Disposition: attachment; filename=grades_' . $filenameSuffix . '.csv');
        fputcsv($out, ['Subject', 'Academic Year', 'Semester', 'Written Work', 'Assessment', 'Performance Task', 'Semester Grade']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, [
                $row['class_name'],
                $row['academic_year'],
                $row['semester'],
                $row['ww_avg'],
                $row['assessment_avg'],
                $row['pt_avg'],
                $row['semester_grade']
            ]);
        }
        fclose($out);
        exit();
    }
    if ($type === 'activities') {
        $exportFrom = trim((string)($_GET['act_from'] ?? ''));
        $exportTo = trim((string)($_GET['act_to'] ?? ''));
        $exportClassId = (int)($_GET['act_class_id'] ?? 0);
        normalizeDateRange($exportFrom, $exportTo);
        $params = [$exportStudentId];
        $where = "WHERE gis.student_id = ?";
        if ($exportClassId > 0) { $where .= " AND gi.class_id = ?"; $params[] = $exportClassId; }
        if ($exportFrom !== '') { $where .= " AND gi.activity_date >= ?"; $params[] = $exportFrom; }
        if ($exportTo !== '') { $where .= " AND gi.activity_date <= ?"; $params[] = $exportTo; }
        $stmt = $db->prepare("SELECT gi.activity_date, c.class_name, c.grade_level, c.section, gi.title, gi.component, gis.score, gi.total_score
                              FROM grade_item_scores gis
                              JOIN grade_items gi ON gi.id = gis.grade_item_id
                              JOIN classes c ON c.id = gi.class_id
                              $where
                              ORDER BY gi.activity_date DESC, gi.id DESC");
        $stmt->execute($params);
        header('Content-Disposition: attachment; filename=activities_' . $filenameSuffix . '.csv');
        fputcsv($out, ['Date', 'Class', 'Grade', 'Section', 'Activity', 'Component', 'Score', 'Total']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, [
                $row['activity_date'],
                $row['class_name'],
                $row['grade_level'],
                $row['section'],
                $row['title'],
                $row['component'],
                $row['score'],
                $row['total_score']
            ]);
        }
        fclose($out);
        exit();
    }
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
                <h4 class="mb-1">Student Progress</h4>
                <p class="text-muted mb-0">Monitor semester grades and performance progress for your child.</p>
            </div>
        </div>

        <div class="content-card mb-4">
            <div class="content-card-header"><h5 class="content-card-title">Select Student</h5></div>
            <div class="content-card-body">
                <?php if (empty($children)): ?>
                    <div class="text-muted">No linked student found. Ask admin to link your account.</div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($children as $child): ?>
                            <a href="?student_id=<?php echo (int)$child['id']; ?>" class="btn <?php echo ((int)$child['id'] === $selectedStudentId) ? 'btn-primary-custom' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                <small class="ms-1">(<?php echo htmlspecialchars($child['reference_code']); ?>)</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedStudent): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="card-title">Present</div><div class="card-value"><?php echo (int)$attendance['present']; ?></div><div class="text-muted small">On-time attendance</div></div></div>
                <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon red"><i class="bi bi-x-circle-fill"></i></div><div class="card-title">Absent</div><div class="card-value"><?php echo (int)$attendance['absent']; ?></div><div class="text-muted small">Missed class days</div></div></div>
                <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon orange"><i class="bi bi-clock-fill"></i></div><div class="card-title">Late</div><div class="card-value"><?php echo (int)$attendance['late']; ?></div><div class="text-muted small">Late arrivals</div></div></div>
                <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon blue"><i class="bi bi-graph-up-arrow"></i></div><div class="card-title">Current GWA</div><div class="card-value"><?php echo $stats['gwa'] > 0 ? $stats['gwa'] : 'N/A'; ?></div><div class="text-muted small">Approved grades only</div></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Grade Trend</h5>
                        </div>
                        <div class="content-card-body">
                            <canvas id="gradeTrendChart" height="120"></canvas>
                            <?php if (empty($gradeTrendRows)): ?>
                                <div class="text-center text-muted py-3">No trend data yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="content-card h-100">
                        <div class="content-card-header"><h5 class="content-card-title">Alerts</h5></div>
                        <div class="content-card-body">
                            <?php if (empty($alerts)): ?>
                                <div class="text-muted">No alerts at the moment.</div>
                            <?php else: ?>
                                <?php foreach ($alerts as $alert): ?>
                                    <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> mb-2">
                                        <strong><?php echo htmlspecialchars($alert['title']); ?></strong><br>
                                        <span class="small"><?php echo htmlspecialchars($alert['message']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="content-card-title mb-0">Attendance Summary</h5>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-secondary-custom" href="?student_id=<?php echo (int)$selectedStudentId; ?>&action=export&type=attendance&att_from=<?php echo urlencode($attendanceFrom); ?>&att_to=<?php echo urlencode($attendanceTo); ?>">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                    </div>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 align-items-end mb-3">
                        <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudentId; ?>">
                        <div class="col-md-4">
                            <label class="form-label">Date From</label>
                            <input type="date" name="att_from" class="form-control" value="<?php echo htmlspecialchars($attendanceFrom); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date To</label>
                            <input type="date" name="att_to" class="form-control" value="<?php echo htmlspecialchars($attendanceTo); ?>">
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button class="btn btn-primary-custom w-100" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
                            <a class="btn btn-secondary-custom w-100" href="?student_id=<?php echo (int)$selectedStudentId; ?>">Clear</a>
                        </div>
                    </form>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Attendance Rate</div><div class="card-value"><?php echo $stats['attendance_rate'] > 0 ? $stats['attendance_rate'] . '%' : 'N/A'; ?></div><div class="text-muted small">Present + Late</div></div></div>
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Total Records</div><div class="card-value"><?php echo (int)($attendance['present'] + $attendance['absent'] + $attendance['late']); ?></div><div class="text-muted small">Within filter range</div></div></div>
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Subjects Enrolled</div><div class="card-value"><?php echo (int)$stats['subjects']; ?></div><div class="text-muted small">Active classes</div></div></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Class</th>
                                    <th>Status</th>
                                    <th>Time In</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($attendanceRows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">No attendance records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($attendanceRows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['date']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['class_name']); ?><?php if (!empty($row['grade_level'])): ?> <span class="text-muted small">(G<?php echo (int)$row['grade_level']; ?> - <?php echo htmlspecialchars((string)$row['section']); ?>)</span><?php endif; ?></td>
                                            <td><span class="badge bg-<?php echo $row['status'] === 'present' ? 'success' : ($row['status'] === 'late' ? 'warning text-dark' : 'danger'); ?>"><?php echo htmlspecialchars(ucfirst((string)$row['status'])); ?></span></td>
                                            <td>
                                                <?php
                                                    $timeIn = trim((string)($row['time_in'] ?? ''));
                                                    echo $timeIn !== '' ? date('h:i A', strtotime($timeIn)) : '-';
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string)($row['remarks'] ?? '-')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="content-card-title mb-0">Report Card Approvals & Remarks</h5>
                </div>
                <div class="content-card-body">
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                    <th>Reviewed By</th>
                                    <th>Adviser</th>
                                    <th>Remarks</th>
                                    <th>Reviewed At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($approvalRows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-3">No report card submissions yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($approvalRows as $row): ?>
                                        <?php
                                            $status = trim((string)$row['status']);
                                            $badge = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning text-dark');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['academic_year']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['semester']); ?></td>
                                            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                            <td><?php echo htmlspecialchars((string)($row['reviewed_by_name'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($row['adviser_name'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($row['remarks'] ?? '')); ?></td>
                                            <td><?php echo !empty($row['reviewed_at']) ? date('M d, Y h:i A', strtotime((string)$row['reviewed_at'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="content-card-title mb-0">Activity Scores</h5>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-secondary-custom" href="?student_id=<?php echo (int)$selectedStudentId; ?>&action=export&type=activities&act_class_id=<?php echo (int)$activityClassId; ?>&act_from=<?php echo urlencode($activityFrom); ?>&act_to=<?php echo urlencode($activityTo); ?>">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                    </div>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 align-items-end mb-3">
                        <input type="hidden" name="student_id" value="<?php echo (int)$selectedStudentId; ?>">
                        <div class="col-md-4">
                            <label class="form-label">Class</label>
                            <select name="act_class_id" class="form-select">
                                <option value="">All Classes</option>
                                <?php foreach ($studentClasses as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$activityClassId === (int)$c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class_name'] . ' (G' . $c['grade_level'] . ' - ' . $c['section'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="act_from" class="form-control" value="<?php echo htmlspecialchars($activityFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="act_to" class="form-control" value="<?php echo htmlspecialchars($activityTo); ?>">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button class="btn btn-primary-custom w-100" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table custom-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Class</th>
                                    <th>Activity</th>
                                    <th>Component</th>
                                    <th>Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($activityRows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">No activity scores found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($activityRows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['activity_date']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['class_name']); ?> <span class="text-muted small">(G<?php echo (int)$row['grade_level']; ?> - <?php echo htmlspecialchars((string)$row['section']); ?>)</span></td>
                                            <td><?php echo htmlspecialchars((string)$row['title']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['component']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['score']); ?> / <?php echo htmlspecialchars((string)$row['total_score']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="content-card-title mb-0">Semester Grade Breakdown</h5>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-secondary-custom" href="?student_id=<?php echo (int)$selectedStudentId; ?>&action=export&type=grades">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                    </div>
                </div>
                <div class="content-card-body">
                    <?php if (empty($progressRows)): ?>
                        <div class="text-center text-muted py-4">No recorded grades yet.</div>
                    <?php else: ?>
                        <?php foreach ($progressRows as $row): ?>
                            <?php
                                $termKey = trim((string)$row['academic_year']) . '|' . trim((string)$row['semester']);
                                $termMeta = $approvalMetaByTerm[$termKey] ?? ['reviewed_by_name' => '', 'reviewed_at' => '', 'status' => '', 'remarks' => '', 'adviser_name' => ''];
                                $approvedBy = $termMeta['reviewed_by_name'] !== '' ? $termMeta['reviewed_by_name'] : 'Admin';
                                $approvedAt = $termMeta['reviewed_at'] !== '' ? date('M d, Y h:i A', strtotime($termMeta['reviewed_at'])) : 'N/A';
                                $statusLabel = $termMeta['status'] !== '' ? ucfirst((string)$termMeta['status']) : 'Pending';
                                $statusBadge = $termMeta['status'] === 'approved' ? 'success' : ($termMeta['status'] === 'rejected' ? 'danger' : 'warning text-dark');
                                $remarks = $termMeta['remarks'] ?? '';
                                $adviserName = $termMeta['adviser_name'] ?? '';
                                $ww = $row['ww_avg'] !== null ? (float)$row['ww_avg'] : null;
                                $as = $row['assessment_avg'] !== null ? (float)$row['assessment_avg'] : null;
                                $pt = $row['pt_avg'] !== null ? (float)$row['pt_avg'] : null;
                                $fg = $row['semester_grade'] !== null ? (float)$row['semester_grade'] : null;
                            ?>
                            <div class="grade-card mb-3">
                                <div class="grade-header">
                                    <span class="grade-subject"><?php echo htmlspecialchars($row['class_name']); ?></span>
                                    <span class="grade-score good"><?php echo $fg !== null ? number_format($fg, 2) : 'N/A'; ?></span>
                                </div>
                                <div class="text-muted small mb-2">
                                    <?php echo htmlspecialchars((string)$row['academic_year'] . ' / ' . (string)$row['semester']); ?>
                                    | <span class="badge bg-<?php echo $statusBadge; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                    | Approved by <?php echo htmlspecialchars($approvedBy); ?> on <?php echo htmlspecialchars($approvedAt); ?>
                                    <?php if ($adviserName !== ''): ?> | Adviser: <?php echo htmlspecialchars($adviserName); ?><?php endif; ?>
                                    <?php if ($remarks !== ''): ?> | Remarks: <?php echo htmlspecialchars($remarks); ?><?php endif; ?>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small"><span>Written Work</span><span><?php echo $ww !== null ? number_format($ww, 2) : 'N/A'; ?></span></div>
                                    <div class="progress" style="height:8px;"><div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $ww !== null ? max(0, min(100, $ww)) : 0; ?>%"></div></div>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small"><span>Assessment</span><span><?php echo $as !== null ? number_format($as, 2) : 'N/A'; ?></span></div>
                                    <div class="progress" style="height:8px;"><div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $as !== null ? max(0, min(100, $as)) : 0; ?>%"></div></div>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small"><span>Performance Task</span><span><?php echo $pt !== null ? number_format($pt, 2) : 'N/A'; ?></span></div>
                                    <div class="progress" style="height:8px;"><div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $pt !== null ? max(0, min(100, $pt)) : 0; ?>%"></div></div>
                                </div>
                                <div>
                                    <div class="d-flex justify-content-between small fw-semibold"><span>Semester Grade</span><span><?php echo $fg !== null ? number_format($fg, 2) : 'N/A'; ?></span></div>
                                    <div class="progress" style="height:10px;"><div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $fg !== null ? max(0, min(100, $fg)) : 0; ?>%"></div></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../assets/js/main.js"></script>
<script>
    (function() {
        const trendRows = <?php echo json_encode($gradeTrendRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const canvas = document.getElementById('gradeTrendChart');
        if (!canvas || typeof Chart === 'undefined' || !Array.isArray(trendRows) || trendRows.length === 0) return;
        const labels = trendRows.map(r => `${r.academic_year} ${r.semester}`);
        const data = trendRows.map(r => Number(r.term_gwa || 0));
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Term GWA',
                    data: data,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#1d4ed8'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `GWA: ${ctx.parsed.y}` } }
                },
                scales: {
                    y: { suggestedMin: 60, suggestedMax: 100 }
                }
            }
        });
    })();
</script>
</body>
</html>
