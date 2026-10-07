<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$db = (new Database())->getConnection();
$selectedStatus = strtolower(trim((string)($_GET['status'] ?? 'submitted_admin')));
if (!in_array($selectedStatus, ['all', 'submitted_admin', 'approved', 'rejected'], true)) {
    $selectedStatus = 'submitted_admin';
}
$academicYear = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$gradeLevel = (int)($_GET['grade_level'] ?? 0);
if (!in_array($gradeLevel, [0, 11, 12], true)) {
    $gradeLevel = 0;
}
$section = trim((string)($_GET['section'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$rows = [];
$years = [];
$sections = [];
$counts = ['submitted_admin' => 0, 'approved' => 0, 'rejected' => 0];
$loadError = '';

try {
    $countStmt = $db->query("SELECT status, COUNT(*) total FROM report_card_approvals GROUP BY status");
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
        if (array_key_exists($countRow['status'], $counts)) {
            $counts[$countRow['status']] = (int)$countRow['total'];
        }
    }
    $years = $db->query("SELECT DISTINCT academic_year FROM report_card_approvals ORDER BY academic_year DESC")
        ->fetchAll(PDO::FETCH_COLUMN);
    $sections = $db->query("SELECT DISTINCT s.section FROM report_card_approvals rc JOIN users s ON s.id = rc.student_id WHERE COALESCE(s.section, '') <> '' ORDER BY s.section")
        ->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];
    if ($selectedStatus !== 'all') {
        $where[] = 'rc.status = ?';
        $params[] = $selectedStatus;
    }
    if ($academicYear !== '') {
        $where[] = 'rc.academic_year = ?';
        $params[] = $academicYear;
    }
    if ($semester !== '') {
        $where[] = 'rc.semester = ?';
        $params[] = $semester;
    }
    if ($gradeLevel > 0) {
        $where[] = 's.grade_level = ?';
        $params[] = $gradeLevel;
    }
    if ($section !== '') {
        $where[] = 'LOWER(TRIM(s.section)) = LOWER(TRIM(?))';
        $params[] = $section;
    }
    if ($search !== '') {
        $where[] = "(s.reference_code LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.section LIKE ?)";
        $needle = '%' . $search . '%';
        array_push($params, $needle, $needle, $needle, $needle);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT rc.id, rc.student_id, rc.academic_year, rc.semester, rc.status,
                   rc.submitted_at, rc.reviewed_at, rc.remarks,
                   s.reference_code, s.first_name, s.last_name, s.grade_level, s.section,
                   CONCAT(a.first_name, ' ', a.last_name) adviser_name,
                   CONCAT(r.first_name, ' ', r.last_name) reviewer_name,
                   (SELECT COUNT(*) FROM grades g
                    JOIN class_subjects cs ON cs.id = g.class_subject_id
                    JOIN classes c ON c.id = cs.class_id
                    WHERE g.student_id = rc.student_id AND g.academic_year = rc.academic_year
                      AND (COALESCE(rc.semester, '') = '' OR g.semester = rc.semester)
                      AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory') subject_count,
                   (SELECT COUNT(*) FROM grades g
                    JOIN class_subjects cs ON cs.id = g.class_subject_id
                    JOIN classes c ON c.id = cs.class_id
                    JOIN grade_approvals ga ON ga.grade_id = g.id AND ga.status = 'admin_verified'
                    WHERE g.student_id = rc.student_id AND g.academic_year = rc.academic_year
                      AND (COALESCE(rc.semester, '') = '' OR g.semester = rc.semester)
                      AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory') verified_count
            FROM report_card_approvals rc
            JOIN users s ON s.id = rc.student_id
            JOIN users a ON a.id = rc.advisory_teacher_id
            LEFT JOIN users r ON r.id = rc.reviewed_by
            $whereSql
            ORDER BY CASE rc.status WHEN 'submitted_admin' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                     rc.submitted_at DESC, rc.id DESC
            LIMIT 250";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Principal dashboard load failed.');
    $loadError = 'Report cards could not be loaded. Confirm that the v1.0.0 database upgrade has been applied.';
}

$current_role = 'principal';
$current_page = $selectedStatus === 'approved' ? 'released' : ($selectedStatus === 'submitted_admin' ? 'pending' : ($selectedStatus === 'all' || $selectedStatus === 'rejected' ? 'history' : 'dashboard'));
$page_title = 'Principal Report Card Review';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
    <?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-content">
        <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="principal-heading">
            <div class="admin-hero-grid">
                <div class="admin-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-patch-check"></i><span>Final release authority</span></div>
                    <h1 class="h4 mb-2" id="principal-heading">Principal report card review</h1>
                    <p class="text-muted mb-0">Review adviser submissions after Admin verification. Released cards become visible to students and parents.</p>
                </div>
                <div class="admin-hero-side principal-workflow" aria-label="Approval workflow">
                    <span>Subject Teacher</span><i class="bi bi-arrow-right"></i><span>Admin</span><i class="bi bi-arrow-right"></i><span>Adviser</span><i class="bi bi-arrow-right"></i><strong>Principal</strong>
                </div>
            </div>
        </section>

        <div class="row g-3 mb-4" aria-label="Report card totals">
            <div class="col-12 col-md-4"><a class="content-card principal-stat-card" href="principal.php?status=submitted_admin#report-cards"><span>Pending review</span><strong><?php echo number_format($counts['submitted_admin']); ?></strong></a></div>
            <div class="col-12 col-md-4"><a class="content-card principal-stat-card" href="principal.php?status=approved#report-cards"><span>Released</span><strong><?php echo number_format($counts['approved']); ?></strong></a></div>
            <div class="col-12 col-md-4"><a class="content-card principal-stat-card" href="principal.php?status=rejected#report-cards"><span>Returned / withdrawn</span><strong><?php echo number_format($counts['rejected']); ?></strong></a></div>
        </div>

        <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
        <section class="content-card" id="report-cards" aria-labelledby="report-cards-heading">
            <div class="content-card-header"><h2 class="content-card-title" id="report-cards-heading">Report cards</h2></div>
            <div class="content-card-body">
                <form method="get" class="row g-3 align-items-end app-responsive-filter-form mb-4">
                    <div class="col-12 col-lg-3"><label class="form-label" for="principal-search">Student</label><input class="form-control" id="principal-search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or reference"></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-status">Status</label><select class="form-select" id="principal-status" name="status"><option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All</option><option value="submitted_admin" <?php echo $selectedStatus === 'submitted_admin' ? 'selected' : ''; ?>>Pending</option><option value="approved" <?php echo $selectedStatus === 'approved' ? 'selected' : ''; ?>>Released</option><option value="rejected" <?php echo $selectedStatus === 'rejected' ? 'selected' : ''; ?>>Returned</option></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-year">School year</label><select class="form-select" id="principal-year" name="academic_year"><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?php echo htmlspecialchars($year); ?>" <?php echo $academicYear === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option><?php endforeach; ?></select></div>
                    <div class="col-6 col-lg-1"><label class="form-label" for="principal-semester">Term</label><input class="form-control" id="principal-semester" name="semester" value="<?php echo htmlspecialchars($semester); ?>" placeholder="All"></div>
                    <div class="col-6 col-lg-1"><label class="form-label" for="principal-grade">Grade</label><select class="form-select" id="principal-grade" name="grade_level"><option value="0">All</option><option value="11" <?php echo $gradeLevel === 11 ? 'selected' : ''; ?>>11</option><option value="12" <?php echo $gradeLevel === 12 ? 'selected' : ''; ?>>12</option></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-section">Section</label><select class="form-select" id="principal-section" name="section"><option value="">All sections</option><?php foreach ($sections as $sectionOption): ?><option value="<?php echo htmlspecialchars($sectionOption); ?>" <?php echo strcasecmp($section, (string)$sectionOption) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($sectionOption); ?></option><?php endforeach; ?></select></div>
                    <div class="col-6 col-lg-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Apply filters"><i class="bi bi-funnel"></i><span class="d-lg-none ms-1">Apply</span></button></div>
                </form>

                <div class="principal-action-bar mb-3" id="principal-action-bar" hidden>
                    <span><strong id="selected-count">0</strong> selected</span>
                    <div class="d-flex gap-2 flex-wrap"><button class="btn btn-success btn-sm" type="button" data-decision="approve"><i class="bi bi-check2-circle"></i> Release</button><button class="btn btn-outline-danger btn-sm" type="button" data-decision="reject"><i class="bi bi-arrow-return-left"></i> Return</button><button class="btn btn-outline-warning btn-sm" type="button" data-decision="withdraw"><i class="bi bi-slash-circle"></i> Withdraw release</button></div>
                </div>

                <div class="table-responsive">
                    <table class="table custom-table align-middle">
                        <thead><tr><th scope="col"><span class="visually-hidden">Select</span></th><th scope="col">Learner</th><th scope="col">Class</th><th scope="col">Period</th><th scope="col">Verified subjects</th><th scope="col">Status</th><th scope="col">Submitted / reviewed</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="7"><div class="empty-state py-4"><i class="bi bi-inbox"></i><p class="mb-0">No report cards match these filters.</p></div></td></tr><?php endif; ?>
                        <?php foreach ($rows as $row):
                            $eligible = in_array($row['status'], ['submitted_admin', 'approved'], true);
                            $statusLabel = $row['status'] === 'submitted_admin' ? 'Pending Principal' : ($row['status'] === 'approved' ? 'Released' : 'Returned');
                            $badge = $row['status'] === 'submitted_admin' ? 'warning' : ($row['status'] === 'approved' ? 'success' : 'danger');
                        ?>
                            <tr>
                                <td><?php if ($eligible): ?><input class="form-check-input report-card-check" type="checkbox" value="<?php echo (int)$row['id']; ?>" data-status="<?php echo htmlspecialchars($row['status']); ?>" aria-label="Select report card for <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>"><?php endif; ?></td>
                                <td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars($row['reference_code']); ?> · Adviser: <?php echo htmlspecialchars($row['adviser_name']); ?></small></td>
                                <td>Grade <?php echo (int)$row['grade_level']; ?><small class="d-block text-muted"><?php echo htmlspecialchars($row['section']); ?></small></td>
                                <td><?php echo htmlspecialchars($row['academic_year']); ?><small class="d-block text-muted"><?php echo htmlspecialchars($row['semester'] ?: 'Final'); ?></small></td>
                                <td><strong><?php echo (int)$row['verified_count']; ?>/<?php echo (int)$row['subject_count']; ?></strong><?php if ((int)$row['verified_count'] !== (int)$row['subject_count']): ?><small class="d-block text-danger">Verification incomplete</small><?php endif; ?></td>
                                <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo $statusLabel; ?></span><?php if ($row['remarks']): ?><small class="d-block text-muted mt-1"><?php echo htmlspecialchars($row['remarks']); ?></small><?php endif; ?></td>
                                <td><small><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['submitted_at']))); ?></small><?php if ($row['reviewed_at']): ?><small class="d-block text-muted">Reviewed <?php echo htmlspecialchars(date('M j, Y', strtotime($row['reviewed_at']))); ?><?php echo $row['reviewer_name'] ? ' by ' . htmlspecialchars($row['reviewer_name']) : ''; ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<div class="modal fade" id="decisionModal" tabindex="-1" aria-labelledby="decision-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="decision-form"><div class="modal-header"><h2 class="modal-title fs-5" id="decision-title">Confirm decision</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="decision-copy"></p><label class="form-label" for="decision-remarks">Remarks <span id="remarks-required" class="text-danger"></span></label><textarea class="form-control" id="decision-remarks" maxlength="255" rows="3" placeholder="Reason for returning or withdrawing"></textarea><div class="form-text">Remarks are required when returning or withdrawing a report card.</div><div class="alert alert-danger mt-3 mb-0" id="decision-error" role="alert" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="decision-submit">Confirm</button></div></form></div>
</div>

<script>
window.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('.report-card-check')];
    const bar = document.getElementById('principal-action-bar');
    const count = document.getElementById('selected-count');
    const modalElement = document.getElementById('decisionModal');
    const modal = modalElement ? new bootstrap.Modal(modalElement) : null;
    let decision = '';
    const selected = () => boxes.filter(box => box.checked);
    const refresh = () => { const total = selected().length; count.textContent = total; bar.hidden = total === 0; };
    boxes.forEach(box => box.addEventListener('change', refresh));
    document.querySelectorAll('[data-decision]').forEach(button => button.addEventListener('click', () => {
        const chosen = selected();
        if (!chosen.length) return;
        decision = button.dataset.decision;
        const allowed = decision === 'withdraw' ? chosen.every(box => box.dataset.status === 'approved') : chosen.every(box => box.dataset.status === 'submitted_admin');
        const error = document.getElementById('decision-error');
        error.hidden = allowed;
        error.textContent = allowed ? '' : (decision === 'withdraw' ? 'Only released cards can be withdrawn.' : 'Only pending cards can be released or returned.');
        document.getElementById('decision-title').textContent = decision === 'approve' ? 'Release report cards' : (decision === 'reject' ? 'Return report cards' : 'Withdraw released cards');
        document.getElementById('decision-copy').textContent = `This decision applies to ${chosen.length} selected report card${chosen.length === 1 ? '' : 's'}.`;
        document.getElementById('remarks-required').textContent = decision === 'approve' ? '(optional)' : '(required)';
        document.getElementById('decision-submit').disabled = !allowed;
        modal.show();
    }));
    document.getElementById('decision-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = document.getElementById('decision-submit');
        const error = document.getElementById('decision-error');
        const remarks = document.getElementById('decision-remarks').value.trim();
        if (decision !== 'approve' && !remarks) { error.textContent = 'Enter a reason before continuing.'; error.hidden = false; return; }
        submit.disabled = true;
        const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrfToken); ?>, decision, remarks, report_card_ids: JSON.stringify(selected().map(box => box.value)) });
        try {
            const response = await fetch('principal_Action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || 'The decision could not be saved.');
            window.location.reload();
        } catch (requestError) { error.textContent = requestError.message; error.hidden = false; submit.disabled = false; }
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
