<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/Login.php");
    exit();
}

$db = (new Database())->getConnection();

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'report_cards')));
if (!in_array($activeTab, ['grades', 'report_cards'], true)) {
    $activeTab = 'report_cards';
}
$gradeLevel = (int)($_GET['grade_level'] ?? 0);
$section = trim((string)($_GET['section'] ?? ''));
$academicYear = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$selectedStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
if (!in_array($selectedStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
    $selectedStatus = 'all';
}

$gs = SshsGradeCalculator::gradingSystem($academicYear);
$tc = 'term';
$has_quarter = dbHasColumn($db, 'grades', 'quarter');
if ($has_quarter) {
    $has_term = dbHasColumn($db, 'grades', 'term');
    $tc = $has_term ? 'term' : 'quarter';
}

$isFourQuarter = ($gs === '4_quarter');
$termLabels = SshsGradeCalculator::validTerms($gs);

if ($gradeLevel <= 0 || $section === '' || $academicYear === '') {
    header("Location: Admin_Grade_Approvals.php?status=" . urlencode($selectedStatus));
    exit();
}

if ($isFourQuarter && !in_array($semester, ['S1', 'S2'], true)) {
    header("Location: Admin_Grade_Approvals.php?status=" . urlencode($selectedStatus));
    exit();
}

$q1Header = '';
$q2Header = '';
$semesterNo = 0;
if ($isFourQuarter) {
    $q1Header = $semester === 'S2' ? 'Q3' : 'Q1';
    $q2Header = $semester === 'S2' ? 'Q4' : 'Q2';
    $semesterNo = $semester === 'S2' ? 2 : 1;
}

$studentMap = [];
$subjectRows = [];
$approvalQueue = [];
$summary = ['subjects' => 0, 'gwa' => null];
$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'rate' => 0];

if ($db) {
    $studentStmt = $db->prepare("SELECT u.id, u.reference_code, CONCAT(u.first_name, ' ', u.last_name) AS student_name
                                 FROM users u
                                 WHERE u.role = 'student'
                                   AND u.status IN ('active', 'pending')
                                   AND u.grade_level = ?
                                   AND (
                                     LOWER(TRIM(COALESCE(u.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                     OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(u.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                                   )
                                 ORDER BY u.last_name, u.first_name");
    $studentStmt->execute([$gradeLevel, $section, $section]);
    foreach ($studentStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
        $sid = (int)($st['id'] ?? 0);
        if ($sid <= 0) continue;
        $studentMap[$sid] = [
            'id' => $sid,
            'name' => (string)($st['student_name'] ?? ''),
            'reference_code' => (string)($st['reference_code'] ?? '')
        ];
    }

    if ($selectedStudentId <= 0 && !empty($studentMap)) {
        $selectedStudentId = (int)array_key_first($studentMap);
    }

    if ($selectedStudentId > 0) {
        if ($isFourQuarter) {
            $subjectStmt = $db->prepare("SELECT
                                            c.id AS class_id,
                                            c.class_name,
                                            c.subject_category,
                                            MAX(CASE WHEN g.{$tc} = '{$q1Header}' THEN g.final_grade END) AS q1_grade,
                                            MAX(CASE WHEN g.{$tc} = '{$q2Header}' THEN g.final_grade END) AS q2_grade,
                                            MAX(CASE WHEN g.{$tc} = '{$q1Header}' THEN COALESCE(ga.status, 'pending') END) AS q1_status,
                                            MAX(CASE WHEN g.{$tc} = '{$q2Header}' THEN COALESCE(ga.status, 'pending') END) AS q2_status
                                         FROM classes c
                                         LEFT JOIN class_subjects cs ON cs.id = c.class_id
                                         LEFT JOIN grades g ON g.class_subject_id = cs.id
                                                           AND g.student_id = ?
                                                           AND g.academic_year = ?
                                                           AND g.semester = ?
                                         LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                                         WHERE c.status = 'active'
                                           AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                           AND c.grade_level = ?
                                           AND (
                                             LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                             OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                                           )
                                         GROUP BY c.id, c.class_name, c.subject_category
                                         ORDER BY c.class_name");
            $subjectStmt->execute([$selectedStudentId, $academicYear, $semester, $gradeLevel, $section, $section]);
            foreach ($subjectStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $q1 = $row['q1_grade'] !== null ? (float)$row['q1_grade'] : null;
                $q2 = $row['q2_grade'] !== null ? (float)$row['q2_grade'] : null;
                $q1Status = strtolower(trim((string)($row['q1_status'] ?? 'approved')));
                $q2Status = strtolower(trim((string)($row['q2_status'] ?? 'approved')));
                $semFinal = null;
                if ($q1 !== null && $q2 !== null) {
                    $semFinal = round(($q1 + $q2) / 2, 2);
                } elseif ($q1 !== null) {
                    $semFinal = $q1;
                } elseif ($q2 !== null) {
                    $semFinal = $q2;
                }
                $subjectRows[] = [
                    'subject' => (string)$row['class_name'],
                    'terms' => ['q1' => $q1, 'q2' => $q2],
                    'final' => $semFinal,
                    'remarks' => $semFinal === null ? 'N/A' : ($semFinal >= 75 ? 'Passed' : 'Failed'),
                    'approval_status' => ($q1Status === 'pending' || $q2Status === 'pending') ? 'Pending'
                        : (($q1Status === 'rejected' || $q2Status === 'rejected') ? 'Rejected' : 'Approved')
                ];
            }
        } else {
            $subjectStmt = $db->prepare("SELECT
                                            c.id AS class_id,
                                            c.class_name,
                                            c.subject_category,
                                            g.{$tc},
                                            g.final_grade,
                                             COALESCE(ga.status, 'pending') AS approval_status
                                          FROM classes c
                                          LEFT JOIN class_subjects cs ON cs.class_id = c.id
                                          LEFT JOIN grades g ON g.class_subject_id = cs.id
                                                            AND g.student_id = ?
                                                            AND g.academic_year = ?
                                          LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                                         WHERE c.status = 'active'
                                           AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                           AND c.grade_level = ?
                                           AND (
                                             LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                             OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                                           )
                                         ORDER BY c.class_name, g.{$tc}");
            $subjectStmt->execute([$selectedStudentId, $academicYear, $gradeLevel, $section, $section]);
            $rawRows = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
            $grouped = [];
            foreach ($rawRows as $r) {
                $cn = $r['class_name'];
                if (!isset($grouped[$cn])) {
                    $grouped[$cn] = ['class_name' => $cn, 'subject_category' => $r['subject_category'], 'grades' => []];
                }
                if ($r[$tc] !== null) {
                    $grouped[$cn]['grades'][$r[$tc]] = [
                        'final_grade' => $r['final_grade'],
                        'approval_status' => strtolower(trim((string)$r['approval_status']))
                    ];
                }
            }

            $processedECMK = false;
            foreach ($grouped as $cn => &$g) {
                $subjectKey = strtolower(trim($cn));
                $combinedKey = SshsGradeCalculator::combinedSubjectKey($subjectKey);
                if ($combinedKey !== '') {
                    if ($processedECMK) continue;
                    $processedECMK = true;
                }
            }
            unset($g);

            $processedECMK = false;
            foreach ($grouped as $cn => $gInfo) {
                $subjectKey = strtolower(trim($cn));
                $combinedKey = SshsGradeCalculator::combinedSubjectKey($subjectKey);
                if ($combinedKey !== '') {
                    if ($processedECMK) continue;
                    $processedECMK = true;
                    $ecGrades = []; $mkGrades = [];
                    foreach ($grouped as $cn2 => $g2) {
                        $sk2 = strtolower(trim($cn2));
                        $ck2 = SshsGradeCalculator::combinedSubjectKey($sk2);
                        if ($ck2 === 'ec') $ecGrades = $g2['grades'];
                        if ($ck2 === 'mk') $mkGrades = $g2['grades'];
                    }
                    $displayName = SshsGradeCalculator::combinedDisplayName();
                    $cat = $gInfo['subject_category'] ?? 'core';
                    $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
                    $termVals = [];
                    $combinedStatuses = [];
                    foreach ($termLabels as $tl) {
                        $ecG = $ecGrades[$tl]['final_grade'] ?? null;
                        $mkG = $mkGrades[$tl]['final_grade'] ?? null;
                        $termVals[$tl] = SshsGradeCalculator::combineGrades($ecG, $mkG);
                        $ecS = $ecGrades[$tl]['approval_status'] ?? 'approved';
                        $mkS = $mkGrades[$tl]['approval_status'] ?? 'approved';
                        $combinedStatuses[$tl] = ($ecS === 'pending' || $mkS === 'pending') ? 'Pending'
                            : (($ecS === 'rejected' || $mkS === 'rejected') ? 'Rejected' : 'Approved');
                    }
                    $effective = array_slice(array_values($termVals), 0, $termCount);
                    $avg = SshsGradeCalculator::finalGrade($effective);
                    $subjectRows[] = [
                        'subject' => $displayName,
                        'terms' => $termVals,
                        'term_statuses' => $combinedStatuses,
                        'final' => $avg,
                        'remarks' => $avg === null ? 'N/A' : ($avg >= 75 ? 'Passed' : 'Failed'),
                        'approval_status' => in_array('Pending', $combinedStatuses, true) ? 'Pending'
                            : (in_array('Rejected', $combinedStatuses, true) ? 'Rejected' : 'Approved')
                    ];
                    continue;
                }

                $cat = $gInfo['subject_category'] ?? 'core';
                $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
                $termVals = [];
                $termStatuses = [];
                foreach ($termLabels as $tl) {
                    $tg = $gInfo['grades'][$tl] ?? null;
                    $termVals[$tl] = $tg !== null ? (float)$tg['final_grade'] : null;
                    $termStatuses[$tl] = $tg !== null ? ucfirst($tg['approval_status']) : '—';
                }
                $effective = array_slice(array_values($termVals), 0, $termCount);
                $avg = SshsGradeCalculator::finalGrade($effective);
                $statuses = array_values($termStatuses);
                $hasPending = in_array('Pending', $statuses, true);
                $hasRejected = in_array('Rejected', $statuses, true);
                $subjectRows[] = [
                    'subject' => $cn,
                    'terms' => $termVals,
                    'term_statuses' => $termStatuses,
                    'final' => $avg,
                    'remarks' => $avg === null ? 'N/A' : ($avg >= 75 ? 'Passed' : 'Failed'),
                    'approval_status' => $hasPending ? 'Pending' : ($hasRejected ? 'Rejected' : 'Approved')
                ];
            }
        }

        $gwaSum = 0.0;
        $gwaCount = 0;
        foreach ($subjectRows as $row) {
            if ($row['final'] !== null) {
                $gwaSum += (float)$row['final'];
                $gwaCount++;
            }
        }
        $summary['subjects'] = count($subjectRows);
        $summary['gwa'] = $gwaCount > 0 ? round($gwaSum / $gwaCount, 2) : null;

        if ($selectedStudentId > 0) {
            $queueSql = "SELECT ga.id AS approval_id, ga.status, ga.submitted_at,
                                c.class_name, g.{$tc} AS term_period, g.final_grade,
                                CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
                         FROM grade_approvals ga
                         JOIN grades g ON g.id = ga.grade_id
                         JOIN class_subjects cs ON cs.id = g.class_subject_id
                         JOIN classes c ON c.id = cs.class_id
                         LEFT JOIN users t ON t.id = g.recorded_by
                         WHERE g.student_id = ?
                           AND g.academic_year = ?"
                         . ($isFourQuarter ? " AND g.semester = ?" : " AND g.semester IS NULL")
                         . " AND c.grade_level = ?
                           AND (
                             LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                             OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                           )";
            $queueParams = $isFourQuarter
                ? [$selectedStudentId, $academicYear, $semester, $gradeLevel, $section, $section]
                : [$selectedStudentId, $academicYear, $gradeLevel, $section, $section];
            $queueStatusMap = ['pending' => 'submitted', 'approved' => 'admin_verified', 'rejected' => 'rejected'];
            $mappedStatus = $queueStatusMap[$selectedStatus] ?? null;
            if ($mappedStatus !== null) {
                $queueSql .= " AND ga.status = ?";
                $queueParams[] = $mappedStatus;
            }
            $queueSql .= " ORDER BY c.class_name, g.{$tc}";
            $queueStmt = $db->prepare($queueSql);
            $queueStmt->execute($queueParams);
            $approvalQueue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

            $attSql = "SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                              SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                              SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count
                       FROM attendance a
                       JOIN classes c ON c.id = a.class_id
                       WHERE a.student_id = ?
                         AND a.academic_year = ?"
                       . ($isFourQuarter ? " AND a.semester = ?" : "")
                       . " AND c.grade_level = ?
                         AND (
                           LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                           OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE(c.section, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1)))
                         )";
            $attParams = $isFourQuarter
                ? [$selectedStudentId, $academicYear, $semesterNo, $gradeLevel, $section, $section]
                : [$selectedStudentId, $academicYear, $gradeLevel, $section, $section];
            $attendanceStmt = $db->prepare($attSql);
            $attendanceStmt->execute($attParams);
            $att = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $attendance['present'] = (int)($att['present_count'] ?? 0);
            $attendance['absent'] = (int)($att['absent_count'] ?? 0);
            $attendance['late'] = (int)($att['late_count'] ?? 0);
            $totalAttendance = $attendance['present'] + $attendance['absent'] + $attendance['late'];
            $attendance['rate'] = $totalAttendance > 0 ? round(($attendance['present'] / $totalAttendance) * 100, 2) : 0;
        }
    }
}

$semDisplay = $isFourQuarter ? $semester : 'Full Year';
$current_role = 'admin';
$current_page = 'grade_approvals';
$page_title = 'Grade Approval Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Balingasag Senior High School</title>
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
        <a href="Admin_Grade_Approvals.php?tab=<?php echo urlencode($activeTab); ?>&status=<?php echo urlencode($selectedStatus); ?>" class="btn btn-link text-decoration-none mb-3">
            <i class="bi bi-arrow-left me-2"></i>Back to Grade Approvals
        </a>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><?php echo $activeTab === 'grades' ? 'Grade-Level View' : 'Report Card View'; ?> - Grade <?php echo (int)$gradeLevel; ?> - <?php echo htmlspecialchars($section); ?></h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($academicYear . ' / ' . $semDisplay); ?>
                    <span class="badge bg-<?php echo $isFourQuarter ? 'secondary' : 'primary'; ?> ms-2"><?php echo $isFourQuarter ? '4-Quarter' : '3-Term'; ?></span>
                </p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success" type="button"
                    onclick="reviewBatch(<?php echo (int)$gradeLevel; ?>, '<?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $isFourQuarter ? htmlspecialchars($semester, ENT_QUOTES, 'UTF-8') : ''; ?>', '<?php echo $activeTab === 'grades' ? 'admin_verified' : 'approved'; ?>', '<?php echo $activeTab === 'grades' ? 'review_grade_batch' : 'review_report_card_batch'; ?>')">
                    <i class="bi bi-check-circle me-1"></i>Approve Section
                </button>
                <button class="btn btn-danger" type="button"
                    onclick="reviewBatch(<?php echo (int)$gradeLevel; ?>, '<?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $isFourQuarter ? htmlspecialchars($semester, ENT_QUOTES, 'UTF-8') : ''; ?>', 'rejected', '<?php echo $activeTab === 'grades' ? 'review_grade_batch' : 'review_report_card_batch'; ?>')">
                    <i class="bi bi-x-circle me-1"></i>Reject Section
                </button>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header"><h5 class="content-card-title mb-0"><?php echo $activeTab === 'grades' ? 'Subject Grades' : 'Student Report Card'; ?></h5></div>
            <div class="content-card-body">
                <form method="GET" class="row g-3 align-items-end mb-3">
                    <input type="hidden" name="grade_level" value="<?php echo (int)$gradeLevel; ?>">
                    <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academicYear); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($selectedStatus); ?>">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                    <div class="col-md-4">
                        <label class="form-label">Student</label>
                        <select name="student_id" class="form-select">
                            <?php if (empty($studentMap)): ?>
                                <option value="">No students</option>
                            <?php else: ?>
                                <?php foreach ($studentMap as $student): ?>
                                    <option value="<?php echo (int)$student['id']; ?>" <?php echo ((int)$student['id'] === $selectedStudentId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name'] . ' (' . $student['reference_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-secondary-custom w-100"><i class="bi bi-arrow-clockwise me-1"></i>Load</button>
                    </div>
                </form>

                <?php if ($selectedStudentId > 0 && isset($studentMap[$selectedStudentId])): ?>
                    <div class="alert alert-light border mb-3">
                        Student: <strong><?php echo htmlspecialchars($studentMap[$selectedStudentId]['name']); ?></strong>
                        | Reference: <strong><?php echo htmlspecialchars($studentMap[$selectedStudentId]['reference_code']); ?></strong>
                    </div>

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
                                    <?php if ($isFourQuarter): ?>
                                        <th><?php echo htmlspecialchars($q1Header); ?></th>
                                        <th><?php echo htmlspecialchars($q2Header); ?></th>
                                        <th>Semester Final</th>
                                    <?php else: ?>
                                        <?php foreach ($termLabels as $tl): ?>
                                            <th><?php echo htmlspecialchars(SshsGradeCalculator::termLabel($tl)); ?></th>
                                        <?php endforeach; ?>
                                        <th>Final Grade</th>
                                    <?php endif; ?>
                                    <th>Remarks</th>
                                    <th>Approval</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($subjectRows)): ?>
                                <tr><td colspan="<?php echo $isFourQuarter ? 6 : (4 + count($termLabels)); ?>" class="text-center text-muted py-4">No report card records found for selected student.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subjectRows as $row): ?>
                                    <?php
                                    $s = strtolower((string)$row['approval_status']);
                                    $badge = $s === 'approved' ? 'success' : ($s === 'rejected' ? 'danger' : 'warning');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$row['subject']); ?></td>
                                        <?php if ($isFourQuarter): ?>
                                            <td><?php echo $row['terms']['q1'] !== null ? htmlspecialchars(number_format((float)$row['terms']['q1'], 2)) : 'N/A'; ?></td>
                                            <td><?php echo $row['terms']['q2'] !== null ? htmlspecialchars(number_format((float)$row['terms']['q2'], 2)) : 'N/A'; ?></td>
                                            <td><?php echo $row['final'] !== null ? htmlspecialchars(number_format((float)$row['final'], 2)) : 'N/A'; ?></td>
                                        <?php else: ?>
                                            <?php foreach ($termLabels as $tl): ?>
                                                <td><?php
                                                    $tg = $row['terms'][$tl] ?? null;
                                                    echo $tg !== null ? htmlspecialchars(number_format((float)$tg, 2)) : '—';
                                                ?></td>
                                            <?php endforeach; ?>
                                            <td><?php echo $row['final'] !== null ? htmlspecialchars(number_format((float)$row['final'], 2)) : 'N/A'; ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars((string)$row['remarks']); ?></td>
                                        <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars((string)$row['approval_status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Present</div><div class="card-value"><?php echo number_format((int)$attendance['present']); ?></div></div></div>
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Absent</div><div class="card-value"><?php echo number_format((int)$attendance['absent']); ?></div></div></div>
                        <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Late</div><div class="card-value"><?php echo number_format((int)$attendance['late']); ?></div></div></div>
                    </div>

                    <div class="content-card border">
                        <div class="content-card-header"><h6 class="content-card-title mb-0">Grade Approval Queue (Selected Student)</h6></div>
                        <div class="content-card-body">
                            <div class="table-container">
                                <table class="custom-table">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th><?php echo $isFourQuarter ? 'Quarter' : 'Term'; ?></th>
                                            <th>Grade</th>
                                            <th>Submitted By</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($approvalQueue)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">No grade approval records for selected student.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($approvalQueue as $queue): ?>
                                            <?php
                                            $qs = strtolower((string)$queue['status']);
                                            $badgeMap = ['approved' => 'success', 'admin_verified' => 'success', 'rejected' => 'danger', 'submitted' => 'warning', 'pending' => 'secondary'];
                                            $qBadge = $badgeMap[$qs] ?? 'warning';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$queue['class_name']); ?></td>
                                                <td><?php echo htmlspecialchars((string)$queue['term_period']); ?></td>
                                                <td><?php echo $queue['final_grade'] !== null ? htmlspecialchars(number_format((float)$queue['final_grade'], 2)) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars((string)($queue['teacher_name'] ?: 'N/A')); ?></td>
                                                <td><span class="badge bg-<?php echo $qBadge; ?>"><?php echo ucfirst($qs); ?></span></td>
                                                <td>
                                                    <?php if ($qs === 'submitted'): ?>
                                                        <div class="d-flex gap-1">
                                                            <button class="btn btn-sm btn-success" onclick="reviewApproval(<?php echo (int)$queue['approval_id']; ?>, 'admin_verified')">Verify</button>
                                                            <button class="btn btn-sm btn-danger" onclick="reviewApproval(<?php echo (int)$queue['approval_id']; ?>, 'rejected')">Reject</button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Reviewed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">No students with grade records found for this section and term.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
async function reviewApproval(approvalId, status) {
    const confirmed = window.showAppConfirm
        ? await window.showAppConfirm({
            title: 'Update approval status?',
            subtitle: 'This will update the grade approval record immediately.',
            message: 'The selected submission will be marked as ' + status.replace('_', ' ') + '.',
            confirmText: 'Confirm',
            cancelText: 'Cancel',
            tone: 'primary',
            icon: 'bi-check-circle-fill'
        })
        : window.confirm('Are you sure you want to continue?');
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('approval_id', String(approvalId));
    formData.append('status', status);
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=review', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to update approval', 'danger');
                return;
            }
            showNotification(d.message || 'Grade approval updated', 'success');
            setTimeout(() => window.location.reload(), 700);
        })
        .catch(() => showNotification('Error updating approval', 'danger'));
}

async function reviewBatch(gradeLevel, section, academicYear, semester, status, batchAction) {
    const isApprove = status === 'approved' || status === 'admin_verified';
    const periodLabel = semester ? `Semester ${semester}` : 'Full Year';
    const label = batchAction === 'review_grade_batch' ? 'grade submissions' : 'report cards';
    const confirmed = window.showAppConfirm
        ? await window.showAppConfirm({
            title: isApprove ? `Approve ${label}?` : `Reject ${label}?`,
            subtitle: `Grade ${gradeLevel} - ${section} • ${academicYear} • ${periodLabel}`,
            message: isApprove
                ? `All submitted ${label} in this batch will be approved.`
                : `All submitted ${label} in this batch will be rejected.`,
            confirmText: isApprove ? 'Approve Batch' : 'Reject Batch',
            cancelText: 'Cancel',
            tone: isApprove ? 'primary' : 'danger',
            icon: isApprove ? 'bi-check2-square' : 'bi-x-octagon-fill'
        })
        : window.confirm('Are you sure you want to continue?');
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('grade_level', String(gradeLevel));
    formData.append('section', section);
    formData.append('academic_year', academicYear);
    formData.append('semester', semester);
    formData.append('status', status);
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=' + batchAction, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to update batch', 'danger');
                return;
            }
            showNotification(d.message || 'Batch updated', 'success');
            setTimeout(() => window.location.reload(), 700);
        })
        .catch(() => showNotification('Error updating batch', 'danger'));
}
</script>
<?php include '../includes/footer.php'; ?>



