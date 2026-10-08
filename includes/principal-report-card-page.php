<?php

if (!isset($principalPage) || !is_array($principalPage)) {
    throw new LogicException('Principal page configuration is required.');
}

$db = (new Database())->getConnection();
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
$loadError = '';

try {
    $query = new \BshsAms\Grade\PrincipalReportCardQuery($db);
    $years = $query->academicYears();
    $sections = $query->sections();
    $rows = $query->find($principalPage['statuses'], [
        'academic_year' => $academicYear,
        'semester' => $semester,
        'grade_level' => $gradeLevel,
        'section' => $section,
        'search' => $search,
    ]);
} catch (Throwable $e) {
    error_log('Principal report-card page load failed.');
    $loadError = 'Report cards could not be loaded. Confirm that the current database schema is installed.';
}

$current_role = 'principal';
$current_page = (string)$principalPage['id'];
$page_title = (string)$principalPage['title'];
$actions = $principalPage['actions'] ?? [];
$hasActions = is_array($actions) && $actions !== [];
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
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/header.php'; ?>
    <div class="page-content">
        <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="principal-page-heading">
            <div class="admin-hero-grid">
                <div class="admin-hero-main">
                    <div class="welcome-role-chip"><i class="bi <?php echo htmlspecialchars((string)$principalPage['icon']); ?>"></i><span>Final release authority</span></div>
                    <h1 class="h4 mb-2" id="principal-page-heading"><?php echo htmlspecialchars((string)$principalPage['heading']); ?></h1>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars((string)$principalPage['description']); ?></p>
                </div>
                <div class="admin-hero-side principal-workflow" aria-label="Approval workflow">
                    <span>Teacher</span><i class="bi bi-arrow-right"></i><span>Admin</span><i class="bi bi-arrow-right"></i><span>Adviser</span><i class="bi bi-arrow-right"></i><strong>Principal</strong>
                </div>
            </div>
        </section>

        <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
        <section class="content-card" aria-labelledby="report-cards-heading">
            <div class="content-card-header"><h2 class="content-card-title" id="report-cards-heading"><?php echo htmlspecialchars((string)$principalPage['table_heading']); ?></h2></div>
            <div class="content-card-body">
                <form method="get" class="row g-3 align-items-end app-responsive-filter-form mb-4">
                    <div class="col-12 col-lg-3"><label class="form-label" for="principal-search">Student</label><input class="form-control" id="principal-search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or reference"></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-year">School year</label><select class="form-select" id="principal-year" name="academic_year"><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo $academicYear === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$year); ?></option><?php endforeach; ?></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-semester">Term</label><input class="form-control" id="principal-semester" name="semester" value="<?php echo htmlspecialchars($semester); ?>" placeholder="All terms"></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-grade">Grade</label><select class="form-select" id="principal-grade" name="grade_level"><option value="0">All grades</option><option value="11" <?php echo $gradeLevel === 11 ? 'selected' : ''; ?>>11</option><option value="12" <?php echo $gradeLevel === 12 ? 'selected' : ''; ?>>12</option></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="principal-section">Section</label><select class="form-select" id="principal-section" name="section"><option value="">All sections</option><?php foreach ($sections as $sectionOption): ?><option value="<?php echo htmlspecialchars((string)$sectionOption); ?>" <?php echo strcasecmp($section, (string)$sectionOption) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$sectionOption); ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 col-lg-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Apply filters"><i class="bi bi-funnel"></i><span class="d-lg-none ms-1">Apply</span></button></div>
                </form>

                <?php if ($hasActions): ?>
                <div class="principal-action-bar mb-3" id="principal-action-bar" hidden>
                    <span><strong id="selected-count">0</strong> selected</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (in_array('approve', $actions, true)): ?><button class="btn btn-success btn-sm" type="button" data-decision="approve"><i class="bi bi-check2-circle"></i> Release</button><?php endif; ?>
                        <?php if (in_array('reject', $actions, true)): ?><button class="btn btn-outline-danger btn-sm" type="button" data-decision="reject"><i class="bi bi-arrow-return-left"></i> Return</button><?php endif; ?>
                        <?php if (in_array('withdraw', $actions, true)): ?><button class="btn btn-outline-warning btn-sm" type="button" data-decision="withdraw"><i class="bi bi-slash-circle"></i> Withdraw release</button><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table custom-table align-middle">
                        <thead><tr><?php if ($hasActions): ?><th scope="col"><span class="visually-hidden">Select</span></th><?php endif; ?><th scope="col">Learner</th><th scope="col">Class</th><th scope="col">Period</th><th scope="col">Verified subjects</th><th scope="col">Status</th><th scope="col">Submitted / reviewed</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="<?php echo $hasActions ? 7 : 6; ?>"><div class="empty-state py-4"><i class="bi bi-inbox"></i><p class="mb-0"><?php echo htmlspecialchars((string)$principalPage['empty']); ?></p></div></td></tr><?php endif; ?>
                        <?php foreach ($rows as $row):
                            $statusLabel = $row['status'] === 'submitted_admin' ? 'Pending Principal' : ($row['status'] === 'approved' ? 'Released' : 'Returned / withdrawn');
                            $badge = $row['status'] === 'submitted_admin' ? 'warning' : ($row['status'] === 'approved' ? 'success' : 'danger');
                        ?>
                            <tr>
                                <?php if ($hasActions): ?><td><input class="form-check-input report-card-check" type="checkbox" value="<?php echo (int)$row['id']; ?>" data-status="<?php echo htmlspecialchars((string)$row['status']); ?>" aria-label="Select report card for <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>"></td><?php endif; ?>
                                <td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['reference_code']); ?> &middot; Adviser: <?php echo htmlspecialchars((string)$row['adviser_name']); ?></small></td>
                                <td>Grade <?php echo (int)$row['grade_level']; ?><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['section']); ?></small></td>
                                <td><?php echo htmlspecialchars((string)$row['academic_year']); ?><small class="d-block text-muted"><?php echo htmlspecialchars((string)($row['semester'] ?: 'Final')); ?></small></td>
                                <td><strong><?php echo (int)$row['verified_count']; ?>/<?php echo (int)$row['subject_count']; ?></strong><?php if ((int)$row['verified_count'] !== (int)$row['subject_count']): ?><small class="d-block text-danger">Verification incomplete</small><?php endif; ?></td>
                                <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo $statusLabel; ?></span><?php if ($row['remarks']): ?><small class="d-block text-muted mt-1"><?php echo htmlspecialchars((string)$row['remarks']); ?></small><?php endif; ?></td>
                                <td><small><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['submitted_at']))); ?></small><?php if ($row['reviewed_at']): ?><small class="d-block text-muted">Reviewed <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$row['reviewed_at']))); ?><?php echo $row['reviewer_name'] ? ' by ' . htmlspecialchars((string)$row['reviewer_name']) : ''; ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<?php if ($hasActions): ?>
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
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
