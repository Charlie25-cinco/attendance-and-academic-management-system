<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();

$tc = 'term';
$quarterCheck = $db->query("SHOW COLUMNS FROM grades LIKE 'quarter'");
if ((bool)$quarterCheck->fetch(PDO::FETCH_ASSOC)) {
    $termCols = $db->query("SHOW COLUMNS FROM grades LIKE 'term'");
    $tc = (bool)$termCols->fetch(PDO::FETCH_ASSOC) ? 'term' : 'quarter';
}



$selectedStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($selectedStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
    $selectedStatus = 'pending';
}
$rows = [];
$reportCardRows = [];
$subjectGradeGroups = [];
$subjectStatusCounts = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
$reportCardStatusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

function normalizeSectionKey($value) {
    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
        return '';
    }
    $base = strtolower(trim((string)preg_replace('/\s*\(.*$/', '', $raw)));
    return $base !== '' ? $base : $raw;
}

if ($db) {
    $where = [];
    $params = [];
    if ($selectedStatus !== 'all') {
        $where[] = "ga.status = ?";
        $params[] = $selectedStatus === 'pending' ? 'submitted' : ($selectedStatus === 'approved' ? 'admin_verified' : $selectedStatus);
    }
    $sql = "SELECT ga.id AS approval_id, ga.status, ga.submitted_at, ga.reviewed_at, ga.remarks,
                   g.id AS grade_id, g.semester, g.{$tc} AS term_period, g.academic_year, g.final_grade,
                   u.reference_code, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                   c.class_name, c.grade_level, c.section,
                   CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
            FROM grade_approvals ga
            JOIN grades g ON g.id = ga.grade_id
            JOIN users u ON u.id = g.student_id
            JOIN class_subjects cs ON cs.id = g.class_subject_id
            JOIN classes c ON c.id = cs.class_id
            LEFT JOIN users t ON t.id = g.recorded_by";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY
              CASE ga.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
              ga.submitted_at DESC,
              ga.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $key = implode('|', [
            (string)($row['grade_level'] ?? ''),
            normalizeSectionKey($row['section'] ?? ''),
            (string)($row['academic_year'] ?? ''),
            (string)($row['semester'] ?? ''),
        ]);
        if (!isset($subjectGradeGroups[$key])) {
            $subjectGradeGroups[$key] = [
                'grade_level' => (int)($row['grade_level'] ?? 0),
                'section' => (string)($row['section'] ?? ''),
                'academic_year' => (string)($row['academic_year'] ?? ''),
                'semester' => $row['semester'],
                'submitted_at' => (string)($row['submitted_at'] ?? ''),
                'teacher_names' => [],
                'class_names' => [],
                'submitted_count' => 0,
                'verified_count' => 0,
                'rejected_count' => 0,
            ];
        }
        $teacherName = trim((string)($row['teacher_name'] ?? ''));
        $className = trim((string)($row['class_name'] ?? ''));
        if ($teacherName !== '' && !in_array($teacherName, $subjectGradeGroups[$key]['teacher_names'], true)) {
            $subjectGradeGroups[$key]['teacher_names'][] = $teacherName;
        }
        if ($className !== '' && !in_array($className, $subjectGradeGroups[$key]['class_names'], true)) {
            $subjectGradeGroups[$key]['class_names'][] = $className;
        }
        $status = strtolower((string)($row['status'] ?? ''));
        if ($status === 'submitted') {
            $subjectGradeGroups[$key]['submitted_count']++;
            $subjectStatusCounts['pending']++;
        } elseif ($status === 'admin_verified') {
            $subjectGradeGroups[$key]['verified_count']++;
            $subjectStatusCounts['verified']++;
        } elseif ($status === 'rejected') {
            $subjectGradeGroups[$key]['rejected_count']++;
            $subjectStatusCounts['rejected']++;
        }
    }

    $rcSql = "SELECT rc.academic_year,
                     rc.semester,
                     s.grade_level,
                     s.section,
                     CONCAT(t.first_name, ' ', t.last_name) AS adviser_name,
                     MAX(rc.submitted_at) AS submitted_at,
                     COUNT(*) AS student_count,
                     SUM(CASE WHEN rc.status IN ('pending','submitted_admin') THEN 1 ELSE 0 END) AS pending_count,
                     SUM(CASE WHEN rc.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                     SUM(CASE WHEN rc.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
              FROM report_card_approvals rc
              JOIN users s ON s.id = rc.student_id
              LEFT JOIN users t ON t.id = rc.advisory_teacher_id
              WHERE s.role = 'student'
              GROUP BY rc.academic_year, rc.semester, s.grade_level, s.section, rc.advisory_teacher_id, t.first_name, t.last_name";
    if ($selectedStatus === 'pending') {
        $rcSql .= " HAVING pending_count > 0";
    } elseif ($selectedStatus === 'approved') {
        $rcSql .= " HAVING pending_count = 0 AND rejected_count = 0 AND approved_count > 0";
    } elseif ($selectedStatus === 'rejected') {
        $rcSql .= " HAVING pending_count = 0 AND approved_count = 0 AND rejected_count > 0";
    }
    $rcSql .= " ORDER BY
               CASE WHEN pending_count > 0 THEN 0 WHEN rejected_count > 0 THEN 1 ELSE 2 END,
               submitted_at DESC";
    $rcStmt = $db->prepare($rcSql);
    $rcStmt->execute();
    $reportCardRows = $rcStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reportCardRows as $row) {
        $reportCardStatusCounts['pending'] += (int)($row['pending_count'] ?? 0);
        $reportCardStatusCounts['approved'] += (int)($row['approved_count'] ?? 0);
        $reportCardStatusCounts['rejected'] += (int)($row['rejected_count'] ?? 0);
    }

}

$current_role = 'admin';
$current_page = 'grade_approvals';
$page_title = 'Grade Approvals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-1">Grade Approvals</h4>
                <p class="text-muted mb-0">Review, verify, and officially release submitted grades and advisory report cards.</p>
            </div>
        </div>

        <!-- DepEd Grade Approval Pipeline Progress Guide -->
        <div class="content-card mb-4 bg-light border">
            <div class="content-card-body p-3">
                <h6 class="fw-bold mb-2 text-primary d-flex align-items-center"><i class="bi bi-diagram-3-fill me-2"></i>DepEd Grade Approval & Publication Pipeline</h6>
                <div class="row g-2 text-center small">
                    <div class="col-md-3">
                        <div class="p-2 rounded bg-white border shadow-sm h-100">
                            <span class="badge bg-primary mb-1">Step 1</span>
                            <div class="fw-semibold">Subject Teachers</div>
                            <div class="text-muted" style="font-size: 11px;">Encode scores & submit subject grades to Admin</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 rounded bg-white border border-primary shadow-sm h-100">
                            <span class="badge bg-info text-dark mb-1">Step 2: Admin Action</span>
                            <div class="fw-semibold">Admin Verification</div>
                            <div class="text-muted" style="font-size: 11px;">Verify subject grades to unlock section report card compilation</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 rounded bg-white border shadow-sm h-100">
                            <span class="badge bg-warning text-dark mb-1">Step 3</span>
                            <div class="fw-semibold">Class Advisers</div>
                            <div class="text-muted" style="font-size: 11px;">Review compiled section report cards & submit to Admin</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 rounded bg-white border border-success shadow-sm h-100">
                            <span class="badge bg-success mb-1">Step 4: Final Release</span>
                            <div class="fw-semibold">Admin Approval & SMS</div>
                            <div class="text-muted" style="font-size: 11px;">Final release unlocks student/parent viewing & triggers SMS</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="content-card-title mb-0">Subject Grade Submissions</h5>
                    <small class="text-muted">Subject teacher submissions waiting for admin verification before adviser review.</small>
                </div>
                <form method="GET" class="d-flex gap-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="pending" <?php echo $selectedStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $selectedStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $selectedStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                    <button class="btn btn-secondary-custom btn-sm" type="submit">Filter</button>
                </form>
            </div>
            <div class="content-card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-warning text-dark">Pending: <?php echo number_format((int)$subjectStatusCounts['pending']); ?></span>
                    <span class="badge bg-success">Verified: <?php echo number_format((int)$subjectStatusCounts['verified']); ?></span>
                    <span class="badge bg-danger">Rejected: <?php echo number_format((int)$subjectStatusCounts['rejected']); ?></span>
                </div>
                <div class="row g-4">
                    <?php if (empty($subjectGradeGroups)): ?>
                        <div class="col-12 text-center text-muted py-4">No subject grade submissions found.</div>
                    <?php else: ?>
                        <?php
                        $gradients = [
                            'linear-gradient(135deg, var(--primary-color), var(--secondary-color))',
                            'linear-gradient(135deg, var(--accent-color), #059669)',
                            'linear-gradient(135deg, var(--warning-color), #d97706)',
                            'linear-gradient(135deg, var(--danger-color), #dc2626)',
                            'linear-gradient(135deg, #8b5cf6, #7c3aed)',
                            'linear-gradient(135deg, #ec4899, #db2777)'
                        ];
                        ?>
                        <?php foreach (array_values($subjectGradeGroups) as $index => $row): ?>
                            <?php
                            $gradient = $gradients[$index % count($gradients)];
                            $submittedCount = (int)($row['submitted_count'] ?? 0);
                            $verifiedCount = (int)($row['verified_count'] ?? 0);
                            $rejectedCount = (int)($row['rejected_count'] ?? 0);
                            $status = $submittedCount > 0 ? 'pending' : ($rejectedCount > 0 && $verifiedCount === 0 ? 'rejected' : 'approved');
                            $badge = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning');
                            $semDisplay = $row['semester'] !== null && $row['semester'] !== '' ? (string)$row['semester'] : 'Full Year';
                            ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="class-card admin-approval-card h-100">
                                    <div class="class-card-header" style="background: <?php echo $gradient; ?>">
                                        <div class="class-card-icon"><i class="bi bi-journal-check"></i></div>
                                    </div>
                                    <div class="class-card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h4 class="class-card-title mb-0"><?php echo 'Grade ' . (int)$row['grade_level'] . ' - ' . htmlspecialchars((string)$row['section']); ?></h4>
                                            <span class="badge bg-<?php echo $badge; ?>"><?php echo $status === 'approved' ? 'Verified' : ucfirst($status); ?></span>
                                        </div>
                                        <p class="class-card-subtitle mb-2"><?php echo htmlspecialchars((string)$row['academic_year'] . ' / ' . $semDisplay); ?></p>
                                        <p class="text-muted small mb-1"><i class="bi bi-person-workspace me-1"></i><?php echo htmlspecialchars(implode(', ', array_slice($row['teacher_names'], 0, 2)) ?: 'N/A'); ?><?php echo count($row['teacher_names']) > 2 ? ' +' . (count($row['teacher_names']) - 2) : ''; ?></p>
                                        <p class="text-muted small mb-2"><i class="bi bi-book me-1"></i><?php echo htmlspecialchars(implode(', ', array_slice($row['class_names'], 0, 2)) ?: 'N/A'); ?><?php echo count($row['class_names']) > 2 ? ' +' . (count($row['class_names']) - 2) : ''; ?></p>
                                        <div class="class-card-stats mb-3">
                                            <span class="class-card-stat"><i class="bi bi-hourglass-split"></i> <?php echo number_format($submittedCount); ?> Pending</span>
                                            <span class="class-card-stat"><i class="bi bi-check2-circle"></i> <?php echo number_format($verifiedCount); ?> Verified</span>
                                            <span class="class-card-stat"><i class="bi bi-x-circle"></i> <?php echo number_format($rejectedCount); ?> Rejected</span>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a class="btn btn-sm btn-primary-custom"
                                                href="admin_Grade_Approvals_Detail.php?tab=grades&grade_level=<?php echo (int)$row['grade_level']; ?>&section=<?php echo urlencode((string)$row['section']); ?>&academic_year=<?php echo urlencode((string)$row['academic_year']); ?>&semester=<?php echo urlencode((string)($row['semester'] ?? '')); ?>&status=<?php echo urlencode($selectedStatus); ?>">
                                                View Subject Grades
                                            </a>
                                            <?php if ($submittedCount > 0): ?>
                                                <button class="btn btn-sm btn-success" type="button" onclick="reviewGradeApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'admin_verified')">Verify for Adviser</button>
                                                <button class="btn btn-sm btn-danger" type="button" onclick="reviewGradeApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'rejected')">Reject</button>
                                            <?php elseif ($verifiedCount > 0): ?>
                                                <button class="btn btn-sm btn-outline-danger" type="button" onclick="returnGradeApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')">Return as Rejected for Teacher Edit</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="content-card mt-4">
            <div class="content-card-header">
                <h5 class="content-card-title mb-0">Report Card Submissions</h5>
                <small class="text-muted">Adviser submissions waiting for final admin approval.</small>
            </div>
            <div class="content-card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-warning text-dark">Pending: <?php echo number_format((int)$reportCardStatusCounts['pending']); ?></span>
                    <span class="badge bg-success">Final Approved: <?php echo number_format((int)$reportCardStatusCounts['approved']); ?></span>
                    <span class="badge bg-danger">Rejected: <?php echo number_format((int)$reportCardStatusCounts['rejected']); ?></span>
                </div>
                <div class="row g-4">
                    <?php if (empty($reportCardRows)): ?>
                        <div class="col-12 text-center text-muted py-4">No report card submissions found.</div>
                    <?php else: ?>
                        <?php
                        $gradients = [
                            'linear-gradient(135deg, var(--primary-color), var(--secondary-color))',
                            'linear-gradient(135deg, var(--accent-color), #059669)',
                            'linear-gradient(135deg, var(--warning-color), #d97706)',
                            'linear-gradient(135deg, var(--danger-color), #dc2626)',
                            'linear-gradient(135deg, #8b5cf6, #7c3aed)',
                            'linear-gradient(135deg, #ec4899, #db2777)'
                        ];
                        ?>
                        <?php foreach ($reportCardRows as $index => $row): ?>
                            <?php
                            $gradient = $gradients[$index % count($gradients)];
                            $pendingCount = (int)($row['pending_count'] ?? 0);
                            $approvedCount = (int)($row['approved_count'] ?? 0);
                            $rejectedCount = (int)($row['rejected_count'] ?? 0);
                            $status = $pendingCount > 0 ? 'pending' : ($approvedCount > 0 && $rejectedCount === 0 ? 'approved' : 'rejected');
                            $badge = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning');
                            $statusLabel = $status === 'approved' ? 'Final Approved' : ucfirst($status);
                            ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="class-card admin-approval-card h-100">
                                    <div class="class-card-header" style="background: <?php echo $gradient; ?>">
                                        <div class="class-card-icon"><i class="bi bi-folder-check"></i></div>
                                    </div>
                                    <div class="class-card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h4 class="class-card-title mb-0"><?php echo 'Grade ' . (int)$row['grade_level'] . ' - ' . htmlspecialchars((string)$row['section']); ?></h4>
                                            <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                        </div>
                                        <p class="class-card-subtitle mb-2"><?php
                                            $semDisplay = $row['semester'] !== null ? (string)$row['semester'] : 'Full Year';
                                            echo htmlspecialchars((string)$row['academic_year'] . ' / ' . $semDisplay);
                                        ?></p>
                                        <p class="text-muted small mb-1"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars((string)($row['adviser_name'] ?: 'N/A')); ?></p>
                                        <div class="class-card-stats mb-3">
                                            <span class="class-card-stat"><i class="bi bi-people"></i> <?php echo number_format((int)$row['student_count']); ?> Students</span>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                        <a class="btn btn-sm btn-primary-custom"
                                                href="admin_Grade_Approvals_Detail.php?grade_level=<?php echo (int)$row['grade_level']; ?>&section=<?php echo urlencode((string)$row['section']); ?>&academic_year=<?php echo urlencode((string)$row['academic_year']); ?>&semester=<?php echo urlencode((string)$row['semester'] ?? ''); ?>&status=<?php echo urlencode($selectedStatus); ?>">
                                            View
                                        </a>
                                        <?php if ($status === 'pending'): ?>
                                            <button class="btn btn-sm btn-success" type="button" onclick="reviewReportCardApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'approved')">Final Approve</button>
                                            <button class="btn btn-sm btn-danger" type="button" onclick="reviewReportCardApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'rejected')">Reject</button>
                                        <?php elseif ($status === 'approved'): ?>
                                            <button class="btn btn-sm btn-outline-danger" type="button" onclick="returnReleasedReportCards(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>')">Return Released Grades</button>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function reviewGradeApprovalBatch(gradeLevel, section, academicYear, semester, status) {
    const formData = new FormData();
    formData.append('grade_level', String(gradeLevel));
    formData.append('section', section);
    formData.append('academic_year', academicYear);
    formData.append('semester', semester || '');
    formData.append('status', status);
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=review_grade_batch', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to update subject grade submissions', 'danger');
                return;
            }
            showNotification(d.message || 'Subject grade submissions updated', 'success');
            setTimeout(() => location.reload(), 800);
        })
        .catch(() => showNotification('Error updating subject grade submissions', 'danger'));
}

function returnGradeApprovalBatch(gradeLevel, section, academicYear, semester) {
    const formData = new FormData();
    formData.append('grade_level', String(gradeLevel));
    formData.append('section', section);
    formData.append('academic_year', academicYear);
    formData.append('semester', semester || '');
    formData.append('remarks', 'Returned to teacher for correction.');
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=return_grade_batch', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to return verified grades', 'danger');
                return;
            }
            showNotification(d.message || 'Grades returned to teacher. Teacher can submit again.', 'success');
            setTimeout(() => location.reload(), 800);
        })
        .catch(() => showNotification('Error returning verified grades', 'danger'));
}

function reviewApproval(approvalId, status) {
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
function reviewReportCardApprovalBatch(gradeLevel, section, academicYear, semester, status) {
    const formData = new FormData();
    formData.append('grade_level', String(gradeLevel));
    formData.append('section', section);
    formData.append('academic_year', academicYear);
    formData.append('semester', semester);
    formData.append('status', status);
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=review_report_card_batch', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to update report card approval', 'danger');
                return;
            }
            showNotification(d.message || 'Report card approval updated', 'success');
            setTimeout(() => window.location.reload(), 700);
        })
        .catch(() => showNotification('Error updating report card approval', 'danger'));
}

function returnReleasedReportCards(gradeLevel, section, academicYear, semester) {
    const formData = new FormData();
    formData.append('grade_level', String(gradeLevel));
    formData.append('section', section);
    formData.append('academic_year', academicYear);
    formData.append('semester', semester || '');
    formData.append('remarks', 'Returned after final release for correction.');
    if (window.APP_CSRF_TOKEN) formData.append('csrf_token', window.APP_CSRF_TOKEN);
    fetch('admin_Grade_Approvals_Action.php?action=return_released_report_card_batch', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showNotification(d.message || 'Failed to return released grades', 'danger');
                return;
            }
            showNotification(d.message || 'Released grades returned for correction', 'success');
            setTimeout(() => window.location.reload(), 700);
        })
        .catch(() => showNotification('Error returning released grades', 'danger'));
}

window.addEventListener('ams:notificationReceived', () => {
    // Live update approval cards when new submissions or reviews arrive
    setTimeout(() => window.location.reload(), 1200);
});
</script>
<?php include '../includes/footer.php'; ?>







