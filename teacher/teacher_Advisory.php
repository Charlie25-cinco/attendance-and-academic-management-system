<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/teacher_Reports_Helper.php';

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);

$selectedAcademicYear = trim((string)($_GET['academic_year'] ?? trhCurrentAcademicYear()));
if (!preg_match('/^\d{4}-\d{4}$/', $selectedAcademicYear)) {
    $selectedAcademicYear = trhCurrentAcademicYear();
}
$gs = SshsGradeCalculator::gradingSystem($selectedAcademicYear);
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

$advisoryInfo = null;
$students = [];
$selectedStudent = null;
$subjectRows = [];
$attendanceSummary = ['present' => 0, 'absent' => 0, 'late' => 0, 'attendance_rate' => 0];
$reportSummary = ['subject_count' => 0, 'gwa' => null];
$hasGradeApprovals = false;
$reportCardSectionStatus = '';
$termLabels = SshsGradeCalculator::validTerms($gs);


if ($db) {
    $advisoryInfo = trhFetchAdvisoryInfo($db, $teacherId);
    $hasGradeApprovals = (bool)$db->query("SHOW TABLES LIKE 'grade_approvals'")->fetch(PDO::FETCH_NUM);

    if ($advisoryInfo) {
        $studentStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                     FROM users u
                                     WHERE u.role = 'student'
                                     AND u.status IN ('active', 'pending')
                                     AND u.grade_level = ?
                                     AND " . sectionMatchSql('u.section') . "
                                     ORDER BY u.last_name, u.first_name");
        $studentStmt->execute([
            (int)$advisoryInfo['grade_level'],
            (string)$advisoryInfo['section'],
            (string)$advisoryInfo['section']
        ]);
        $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

        $tc = 'term';
        $quarterCheck = $db->query("SHOW COLUMNS FROM grades LIKE 'quarter'");
        if ((bool)$quarterCheck->fetch(PDO::FETCH_ASSOC)) {
            $termCols = $db->query("SHOW COLUMNS FROM grades LIKE 'term'");
            $tc = (bool)$termCols->fetch(PDO::FETCH_ASSOC) ? 'term' : 'quarter';
        }

        if (!empty($students)) {
            $studentIds = array_map('intval', array_column($students, 'id'));
            if (!in_array($selectedStudentId, $studentIds, true)) {
                $selectedStudentId = (int)$students[0]['id'];
            }
            foreach ($students as $s) {
                if ((int)$s['id'] === $selectedStudentId) {
                    $selectedStudent = $s;
                    break;
                }
            }

            $termCaseSql = [];
            foreach ($termLabels as $tl) {
                $termCaseSql[] = "MAX(CASE WHEN g.{$tc} = '" . $tl . "' THEN g.final_grade END) AS `{$tl}`";
            }
            $termCaseStr = implode(",\n", $termCaseSql);

            $approvalSelects = '';
            $approvalJoins = '';
            if ($hasGradeApprovals) {
                $approvalParts = [];
                foreach ($termLabels as $tl) {
                    $approvalParts[] = "GROUP_CONCAT(CASE WHEN g.{$tc} = '{$tl}' THEN COALESCE(ga.status, 'approved') END) AS `{$tl}_app`";
                }
                $approvalSelects = ',' . implode(',', $approvalParts);
                $approvalJoins = "LEFT JOIN grade_approvals ga ON ga.grade_id = g.id";
            }

            $gradeStmt = $db->prepare("SELECT c.id, c.class_name, c.subject_category,
                                              {$termCaseStr}
                                              {$approvalSelects}
                                       FROM classes c
                                       LEFT JOIN class_subjects cs ON cs.class_id = c.id
                                       LEFT JOIN grades g ON g.class_subject_id = cs.id
                                                         AND g.student_id = ?
                                                         AND g.academic_year = ?
                                       {$approvalJoins}
                                       WHERE c.status = 'active'
                                       AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                       AND c.grade_level = ?
                                       AND " . sectionMatchSql('c.section') . "
                                       GROUP BY c.id, c.class_name, c.subject_category
                                       ORDER BY c.class_name");
            $gradeStmt->execute([
                $selectedStudentId,
                (string)$selectedAcademicYear,
                (int)$advisoryInfo['grade_level'],
                (string)$advisoryInfo['section'],
                (string)$advisoryInfo['section']
            ]);

            $gwaSum = 0.0;
            $gwaCount = 0;
            foreach ($gradeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $termGrades = [];
                $approvalStatuses = [];
                foreach ($termLabels as $tl) {
                    $gradeVal = $row[$tl] ?? null;
                    $termGrades[] = $gradeVal !== null ? (float)$gradeVal : null;
                    if ($hasGradeApprovals) {
                        $appStatus = strtolower(trim((string)($row[$tl . '_app'] ?? 'approved')));
                        $approvalStatuses[] = $appStatus;
                    }
                }
                $cat = $row['subject_category'] ?? 'core';
                $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
                $effectiveGrades = array_slice($termGrades, 0, $termCount);
                $finalGrade = SshsGradeCalculator::finalGrade($effectiveGrades);

                if ($finalGrade !== null) {
                    $gwaSum += $finalGrade;
                    $gwaCount++;
                }

                $overallStatus = 'Approved';
                if ($hasGradeApprovals && !empty($approvalStatuses)) {
                    if (in_array('pending', $approvalStatuses, true)) {
                        $overallStatus = 'Pending';
                    } elseif (in_array('rejected', $approvalStatuses, true)) {
                        $overallStatus = 'Rejected';
                    }
                }

                $subjectRows[] = [
                    'subject' => (string)$row['class_name'],
                    'term_grades' => $termGrades,
                    'final_grade' => $finalGrade,
                    'remarks' => $finalGrade === null ? 'N/A' : ($finalGrade >= 75 ? 'Passed' : 'Failed'),
                    'approval_status' => $overallStatus
                ];
            }
            $reportSummary['subject_count'] = count($subjectRows);
            $reportSummary['gwa'] = $gwaCount > 0 ? round($gwaSum / $gwaCount, 2) : null;

            $rcStmt = $db->prepare("SELECT
                                        COUNT(*) AS total_rows,
                                        SUM(CASE WHEN rc.status IN ('pending','submitted_admin') THEN 1 ELSE 0 END) AS pending_count,
                                        SUM(CASE WHEN rc.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                                        SUM(CASE WHEN rc.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                                    FROM report_card_approvals rc
                                    JOIN users s ON s.id = rc.student_id
                                    WHERE s.role = 'student'
                                    AND s.status IN ('active', 'pending')
                                    AND s.grade_level = ?
                                    AND " . sectionMatchSql('s.section') . "
                                    AND rc.academic_year = ?
                                    AND rc.advisory_teacher_id = ?");
            $rcStmt->execute([
                (int)$advisoryInfo['grade_level'],
                (string)$advisoryInfo['section'],
                (string)$advisoryInfo['section'],
                (string)$selectedAcademicYear,
                (int)$teacherId
            ]);
            $rcStatusRow = $rcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalRows = (int)($rcStatusRow['total_rows'] ?? 0);
            $pendingRows = (int)($rcStatusRow['pending_count'] ?? 0);
            $approvedRows = (int)($rcStatusRow['approved_count'] ?? 0);
            $rejectedRows = (int)($rcStatusRow['rejected_count'] ?? 0);
            if ($totalRows <= 0) {
                $reportCardSectionStatus = '';
            } elseif ($pendingRows > 0 && $approvedRows === 0 && $rejectedRows === 0) {
                $reportCardSectionStatus = 'pending';
            } elseif ($approvedRows === $totalRows) {
                $reportCardSectionStatus = 'approved';
            } elseif ($rejectedRows === $totalRows) {
                $reportCardSectionStatus = 'rejected';
            } else {
                $reportCardSectionStatus = 'mixed';
            }

            $hasAttendanceTerms = trhHasAttendanceTerms($db);
            if ($gs === '4_quarter') {
                [$dateFrom, $dateTo] = trhPeriodBounds($selectedAcademicYear, 'yearly');
            } else {
                [$dateFrom, $dateTo] = trhPeriodBounds($selectedAcademicYear, 'yearly');
            }
            $attendanceStmt = $db->prepare("SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                                                   SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                                                   SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count
                                            FROM attendance a
                                            JOIN classes c ON c.id = a.class_id
                                            WHERE a.student_id = ?
                                            AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                            AND c.grade_level = ?
                                            AND " . sectionMatchSql('c.section') . "
                                            AND a.academic_year = ?");
            $attendanceStmt->execute([
                $selectedStudentId,
                (int)$advisoryInfo['grade_level'],
                (string)$advisoryInfo['section'],
                (string)$advisoryInfo['section'],
                (string)$selectedAcademicYear
            ]);
            $att = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $attendanceSummary['present'] = (int)($att['present_count'] ?? 0);
            $attendanceSummary['absent'] = (int)($att['absent_count'] ?? 0);
            $attendanceSummary['late'] = (int)($att['late_count'] ?? 0);
            $totalAttendance = $attendanceSummary['present'] + $attendanceSummary['absent'] + $attendanceSummary['late'];
            $attendanceSummary['attendance_rate'] = $totalAttendance > 0
                ? round(($attendanceSummary['present'] / $totalAttendance) * 100, 2)
                : 0;
        }
    }
}

$current_role = 'teacher';
$current_page = 'advisory';
$page_title = 'Report Card';
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Advisory Report Card</h4>
                <p class="text-muted mb-0">Review and generate advisory-based report cards per student and term.</p>
            </div>
        </div>
        <?php if ($advisoryInfo): ?>
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="content-card-title">My Advisory Class</h5>
                </div>
                <div class="content-card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="fw-semibold">Section: G<?php echo (int)$advisoryInfo['grade_level']; ?> - <?php echo htmlspecialchars((string)$advisoryInfo['section']); ?></div>
                        <div class="text-muted small"><?php echo number_format(count($students)); ?> Students</div>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#advisoryStudentsModal" <?php echo empty($students) ? 'disabled' : ''; ?>>
                            <i class="bi bi-people me-1"></i>View Students
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Report Card</h5>
            </div>
            <div class="content-card-body">
                <?php if (!$advisoryInfo): ?>
                    <div class="alert alert-warning mb-0">No advisory section is assigned to this teacher account.</div>
                <?php elseif (empty($students)): ?>
                    <div class="alert alert-warning mb-0">No students found in your advisory section.</div>
                <?php else: ?>
                    <div class="alert alert-light border mb-3">
                        Section: <strong><?php echo 'G' . (int)$advisoryInfo['grade_level'] . ' - ' . htmlspecialchars((string)$advisoryInfo['section']); ?></strong>
                        | Grading System: <strong><?php echo $gs === '4_quarter' ? '4-Quarter' : '3-Term'; ?></strong>
                    </div>
                    <div class="alert alert-info mb-3">
                        Submitted report cards are routed to <strong>Admin Grade Approvals</strong> first. Students can view grades only after admin approval.
                    </div>

                    <form method="GET" class="row g-3 align-items-end mb-3 app-responsive-filter-form" id="advisoryReportCardForm">
                        <div class="col-md-3">
                            <label class="form-label">Search Student</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="reportCardStudentSearch" class="form-control" placeholder="Type name or LRN..." oninput="filterAdvisoryStudentDropdown(this.value)">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Student</label>
                            <select name="student_id" id="advisoryStudentSelect" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($students as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($s['last_name'] . ' ' . $s['first_name'] . ' ' . ($s['reference_code'] ?? ''))); ?>" <?php echo ((int)$s['id'] === $selectedStudentId) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(trim(((string)$s['last_name']) . ', ' . ((string)$s['first_name'])) . (!empty($s['reference_code']) ? ' (' . $s['reference_code'] . ')' : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Academic Year</label>
                            <input type="text" name="academic_year" class="form-control" value="<?php echo htmlspecialchars($selectedAcademicYear); ?>" placeholder="YYYY-YYYY" onchange="this.form.submit()">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-secondary-custom w-100"><i class="bi bi-arrow-clockwise me-1"></i>Load</button>
                        </div>
                    </form>

                    <?php if ($selectedStudent): ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Student</div><div class="card-value" style="font-size:1.2rem;"><?php echo htmlspecialchars(trim(((string)$selectedStudent['first_name']) . ' ' . ((string)$selectedStudent['last_name']))); ?></div><div class="text-muted"><?php echo htmlspecialchars((string)$selectedStudent['reference_code']); ?></div></div></div>
                            <div class="col-md-4"><div class="dashboard-card"><div class="card-title">Subjects</div><div class="card-value"><?php echo number_format((int)$reportSummary['subject_count']); ?></div></div></div>
                            <div class="col-md-4"><div class="dashboard-card"><div class="card-title">GWA</div><div class="card-value"><?php echo $reportSummary['gwa'] !== null ? number_format((float)$reportSummary['gwa'], 2) : 'N/A'; ?></div></div></div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <?php
                                $rcBadge = $reportCardSectionStatus === 'approved' ? 'success'
                                    : ($reportCardSectionStatus === 'rejected' ? 'danger'
                                    : ($reportCardSectionStatus === 'pending' ? 'warning text-dark'
                                    : ($reportCardSectionStatus === 'mixed' ? 'info text-dark' : 'secondary')));
                                if ($reportCardSectionStatus === '') {
                                    $rcText = 'Not Yet Submitted';
                                } elseif ($reportCardSectionStatus === 'pending') {
                                    $rcText = 'Pending Admin Review';
                                } elseif ($reportCardSectionStatus === 'mixed') {
                                    $rcText = 'Partially Reviewed';
                                } else {
                                    $rcText = ucfirst($reportCardSectionStatus);
                                }
                            ?>
                            <span class="badge bg-<?php echo $rcBadge; ?>">Report Card Submission: <?php echo htmlspecialchars($rcText); ?></span>
                            <?php if (in_array($reportCardSectionStatus, ['', 'rejected', 'mixed'], true)): ?>
                                <button class="btn btn-primary-custom btn-sm" type="button" onclick="submitReportCardToAdmin('<?php echo htmlspecialchars($selectedAcademicYear, ENT_QUOTES, 'UTF-8'); ?>')">
                                    <i class="bi bi-send-check me-1"></i>Submit Report Cards to Admin
                                </button>
                            <?php elseif ($reportCardSectionStatus === 'pending'): ?>
                                <button class="btn btn-warning btn-sm" type="button" onclick="recallReportCard('<?php echo htmlspecialchars($selectedAcademicYear, ENT_QUOTES, 'UTF-8'); ?>')">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Recall Report Cards
                                </button>
                            <?php endif; ?>
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
                                        <th>Approval</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($subjectRows)): ?>
                                        <tr><td colspan="<?php echo 3 + count($termLabels); ?>" class="text-center text-muted py-4">No subject grades found for selected student and academic year.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($subjectRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$row['subject']); ?></td>
                                                <?php foreach ($row['term_grades'] as $tg): ?>
                                                    <td><?php echo $tg !== null ? htmlspecialchars(number_format($tg, 2)) : '—'; ?></td>
                                                <?php endforeach; ?>
                                                <td><?php echo $row['final_grade'] !== null ? htmlspecialchars(number_format((float)$row['final_grade'], 2)) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars((string)$row['remarks']); ?></td>
                                                <td>
                                                    <?php
                                                    $s = strtolower((string)$row['approval_status']);
                                                    $badge = $s === 'approved' ? 'success' : ($s === 'rejected' ? 'danger' : 'warning');
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars((string)$row['approval_status']); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-3"><div class="dashboard-card"><div class="card-title">Present</div><div class="card-value"><?php echo number_format((int)$attendanceSummary['present']); ?></div></div></div>
                            <div class="col-md-3"><div class="dashboard-card"><div class="card-title">Absent</div><div class="card-value"><?php echo number_format((int)$attendanceSummary['absent']); ?></div></div></div>
                            <div class="col-md-3"><div class="dashboard-card"><div class="card-title">Late</div><div class="card-value"><?php echo number_format((int)$attendanceSummary['late']); ?></div></div></div>
                            <div class="col-md-3"><div class="dashboard-card"><div class="card-title">Attendance Rate</div><div class="card-value"><?php echo number_format((float)$attendanceSummary['attendance_rate'], 2); ?>%</div></div></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="advisoryStudentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-people"></i>Advisory</div>
                <h5 class="modal-title mb-0">Advisory Students</h5>
                <p class="app-modal-subtitle mb-0">List of students in your advisory section.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body app-modal-body">
            <div class="mb-3">
                <input type="text" class="form-control" id="advisoryStudentsSearch" placeholder="Search students...">
            </div>
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead><tr><th>#</th><th>Student</th><th>Reference</th></tr></thead>
                    <tbody id="advisoryStudentsBody">
                        <?php if (empty($students)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No students found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $i => $s): ?>
                                <tr data-student-row>
                                    <td><?php echo (int)($i + 1); ?></td>
                                    <td><?php echo htmlspecialchars(trim(((string)$s['last_name']) . ', ' . ((string)$s['first_name']))); ?></td>
                                    <td><?php echo htmlspecialchars((string)$s['reference_code']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
<script>
function filterAdvisoryStudentDropdown(query) {
    const q = (query || '').toLowerCase().trim();
    const select = document.getElementById('advisoryStudentSelect');
    if (!select) return;
    const options = select.options;
    for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        const s = (opt.getAttribute('data-search') || opt.textContent || '').toLowerCase();
        if (!q || s.includes(q)) {
            opt.hidden = false;
            opt.disabled = false;
        } else {
            opt.hidden = true;
            opt.disabled = true;
        }
    }
}
function submitReportCardToAdmin(academicYear){
    const fd=new FormData();
    fd.append('academic_year',academicYear);
    if(window.APP_CSRF_TOKEN)fd.append('csrf_token',window.APP_CSRF_TOKEN);
    fetch('teacher_Action.php?action=submit_report_card',{method:'POST',body:fd})
      .then(r=>r.json())
      .then(d=>{if(d.success){showNotification(d.message||'Report card submitted to admin','success');setTimeout(()=>location.reload(),700);}else{showNotification(d.message||'Failed to submit report card','danger');}})
      .catch(()=>showNotification('Error submitting report card','danger'));
}
function recallReportCard(academicYear){
    const fd=new FormData();
    fd.append('academic_year',academicYear);
    if(window.APP_CSRF_TOKEN)fd.append('csrf_token',window.APP_CSRF_TOKEN);
    fetch('teacher_Action.php?action=recall_report_card',{method:'POST',body:fd})
      .then(r=>r.json())
      .then(d=>{if(d.success){showNotification(d.message||'Report cards recalled','success');setTimeout(()=>location.reload(),700);}else{showNotification(d.message||'Failed to recall report cards','danger');}})
      .catch(()=>showNotification('Error recalling report cards','danger'));
}
document.addEventListener('DOMContentLoaded', () => {
    const searchEl = document.getElementById('advisoryStudentsSearch');
    if (!searchEl) return;
    searchEl.addEventListener('input', () => {
        const q = searchEl.value.trim().toLowerCase();
        document.querySelectorAll('#advisoryStudentsBody tr[data-student-row]').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    });
});
</script>
</body>
</html>
