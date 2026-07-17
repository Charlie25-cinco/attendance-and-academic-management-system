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

function ensureGradeApprovalsTableForAdmin($db) {
    static $ready = false; if ($ready) return; $ready = true;
    $db->exec("CREATE TABLE IF NOT EXISTS grade_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_id INT NOT NULL,
        status ENUM('pending','submitted','admin_verified','rejected','approved') DEFAULT 'pending',
        submitted_by INT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        remarks VARCHAR(255) NULL,
        UNIQUE KEY uq_grade_approval_grade (grade_id),
        KEY idx_grade_approval_status (status),
        FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

function ensureReportCardApprovalsTableForAdmin($db) {
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

$selectedStatus = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($selectedStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
    $selectedStatus = 'pending';
}
$rows = [];
$reportCardRows = [];

function normalizeSectionKey($value) {
    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
        return '';
    }
    $base = strtolower(trim((string)preg_replace('/\s*\(.*$/', '', $raw)));
    return $base !== '' ? $base : $raw;
}

if ($db) {
    ensureGradeApprovalsTableForAdmin($db);
    ensureReportCardApprovalsTableForAdmin($db);
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
                <h4 class="mb-1">Grade Approvals</h4>
                <p class="text-muted mb-0">Review, approve, or reject submitted grades before student release.</p>
            </div>
        </div>
        <div class="content-card">
            <div class="content-card-header d-flex justify-content-between align-items-center">
                <div></div>
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
                            ?>
                            <div class="col-lg-4 col-md-6">
                                <div class="class-card h-100">
                                    <div class="class-card-header" style="background: <?php echo $gradient; ?>">
                                        <div class="class-card-icon"><i class="bi bi-folder-check"></i></div>
                                    </div>
                                    <div class="class-card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h4 class="class-card-title mb-0"><?php echo 'Grade ' . (int)$row['grade_level'] . ' - ' . htmlspecialchars((string)$row['section']); ?></h4>
                                            <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($status); ?></span>
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
                                            <button class="btn btn-sm btn-success" type="button" onclick="reviewReportCardApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'approved')">Approve</button>
                                            <button class="btn btn-sm btn-danger" type="button" onclick="reviewReportCardApprovalBatch(<?php echo (int)$row['grade_level']; ?>, '<?php echo htmlspecialchars((string)$row['section'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)$row['academic_year'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>', 'rejected')">Reject</button>
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
</script>
<?php include '../includes/footer.php'; ?>







