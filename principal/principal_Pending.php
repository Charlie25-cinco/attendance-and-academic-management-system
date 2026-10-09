<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$db = (new Database())->getConnection();
$filters = [
    'academic_year' => trim((string)($_GET['academic_year'] ?? '')),
    'semester' => trim((string)($_GET['semester'] ?? '')),
    'grade_level' => in_array((int)($_GET['grade_level'] ?? 0), [11, 12], true) ? (int)$_GET['grade_level'] : 0,
    'section' => trim((string)($_GET['section'] ?? '')),
    'search' => trim((string)($_GET['search'] ?? '')),
];
$rows = $years = $sections = [];
$loadError = '';
try {
    $query = new \BshsAms\Grade\PrincipalReportCardQuery($db);
    $rows = $query->find(['submitted_admin'], $filters);
    $years = $query->academicYears();
    $sections = $query->sections();
} catch (Throwable $e) {
    error_log('Principal pending queue load failed.');
    $loadError = 'The pending review queue could not be loaded.';
}
$readyCount = count(array_filter($rows, static fn(array $row): bool => (int)$row['subject_count'] > 0 && (int)$row['subject_count'] === (int)$row['verified_count']));
$current_role = 'principal';
$current_page = 'pending';
$page_title = 'Pending Report Card Review';
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>"><link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
    <?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main-content">
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="page-content">
    <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="pending-heading">
        <div class="admin-hero-grid"><div class="admin-hero-main"><div class="welcome-role-chip"><i class="bi bi-hourglass-split"></i><span>Endorsement queue</span></div><h1 class="h4 mb-2" id="pending-heading">Pending report-card endorsement</h1><p class="text-muted mb-0">Confirm that every subject is Principal-verified before endorsing the report card to Admin.</p></div><div class="admin-hero-side"><a class="btn btn-light" href="principal.php"><i class="bi bi-grid me-1"></i> Dashboard</a></div></div>
    </section>
    <div class="row g-3 mb-4" aria-label="Pending queue summary">
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><small class="text-muted d-block">Matching submissions</small><strong class="fs-3"><?php echo count($rows); ?></strong></div></div>
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><small class="text-muted d-block">Ready to endorse</small><strong class="fs-3 text-success"><?php echo $readyCount; ?></strong></div></div>
        <div class="col-12 col-lg-6"><div class="content-card p-3 h-100 d-flex align-items-center gap-3"><i class="bi bi-shield-check fs-2 text-primary"></i><div><strong class="d-block">Endorsement safeguard</strong><small class="text-muted">Admin cannot release a report card until the Principal endorses it.</small></div></div></div>
    </div>
    <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
    <section class="content-card" aria-labelledby="queue-heading">
        <div class="content-card-header"><h2 class="content-card-title" id="queue-heading">Awaiting your decision</h2></div>
        <div class="content-card-body">
            <form method="get" class="row g-3 align-items-end app-responsive-filter-form mb-4">
                <div class="col-12 col-lg-3"><label class="form-label" for="pending-search">Student</label><input class="form-control" id="pending-search" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Name or reference"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="pending-year">School year</label><select class="form-select" id="pending-year" name="academic_year"><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo $filters['academic_year'] === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$year); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="pending-term">Term</label><input class="form-control" id="pending-term" name="semester" value="<?php echo htmlspecialchars($filters['semester']); ?>" placeholder="All terms"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="pending-grade">Grade</label><select class="form-select" id="pending-grade" name="grade_level"><option value="0">All grades</option><option value="11" <?php echo $filters['grade_level'] === 11 ? 'selected' : ''; ?>>11</option><option value="12" <?php echo $filters['grade_level'] === 12 ? 'selected' : ''; ?>>12</option></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="pending-section">Section</label><select class="form-select" id="pending-section" name="section"><option value="">All sections</option><?php foreach ($sections as $section): ?><option value="<?php echo htmlspecialchars((string)$section); ?>" <?php echo strcasecmp($filters['section'], (string)$section) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$section); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Apply pending queue filters"><i class="bi bi-funnel"></i><span class="d-lg-none ms-1">Apply</span></button></div>
            </form>
            <div class="principal-action-bar mb-3" id="pending-action-bar" hidden><span><strong id="pending-selected-count">0</strong> selected</span><div class="d-flex gap-2"><button class="btn btn-success btn-sm" type="button" data-decision="approve"><i class="bi bi-check2-circle"></i> Endorse to Admin</button><button class="btn btn-outline-danger btn-sm" type="button" data-decision="reject"><i class="bi bi-arrow-return-left"></i> Return</button></div></div>
            <div class="d-grid gap-3">
            <?php if (!$rows): ?><div class="empty-state py-5"><i class="bi bi-inbox"></i><p class="mb-0">No report cards are waiting for Principal review.</p></div><?php endif; ?>
            <?php foreach ($rows as $row): $ready = (int)$row['subject_count'] > 0 && (int)$row['verified_count'] === (int)$row['subject_count']; ?>
                <article class="border rounded-3 p-3" aria-label="Pending report card for <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>">
                    <div class="d-flex align-items-start gap-3"><input class="form-check-input mt-1 report-card-check" type="checkbox" value="<?php echo (int)$row['id']; ?>" aria-label="Select <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>"><div class="flex-grow-1"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h3 class="h6 mb-1"><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></h3><small class="text-muted"><?php echo htmlspecialchars((string)$row['reference_code']); ?> &middot; Grade <?php echo (int)$row['grade_level']; ?> <?php echo htmlspecialchars((string)$row['section']); ?></small></div><span class="badge text-bg-<?php echo $ready ? 'success' : 'warning'; ?>"><?php echo $ready ? 'Ready to endorse' : 'Verification incomplete'; ?></span></div><hr><div class="row g-2"><div class="col-6 col-md-3"><small class="text-muted d-block">Period</small><strong><?php echo htmlspecialchars((string)$row['academic_year']); ?></strong><small class="d-block"><?php echo htmlspecialchars((string)($row['semester'] ?: 'Final')); ?></small></div><div class="col-6 col-md-3"><small class="text-muted d-block">Principal-verified subjects</small><strong><?php echo (int)$row['verified_count']; ?> / <?php echo (int)$row['subject_count']; ?></strong></div><div class="col-6 col-md-3"><small class="text-muted d-block">Adviser</small><strong><?php echo htmlspecialchars((string)$row['adviser_name']); ?></strong></div><div class="col-6 col-md-3"><small class="text-muted d-block">Submitted</small><strong><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$row['submitted_at']))); ?></strong></div></div></div></div>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
</main>
<div class="modal fade" id="pendingDecisionModal" tabindex="-1" aria-labelledby="pending-decision-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="pending-decision-form"><div class="modal-header"><h2 class="modal-title fs-5" id="pending-decision-title">Confirm decision</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="pending-decision-copy"></p><label class="form-label" for="pending-remarks">Remarks <span id="pending-remarks-required" class="text-danger"></span></label><textarea class="form-control" id="pending-remarks" maxlength="255" rows="3"></textarea><div class="form-text">Remarks are required when returning a report card.</div><div class="alert alert-danger mt-3 mb-0" id="pending-decision-error" role="alert" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="pending-decision-submit">Confirm</button></div></form></div></div>
<script>
window.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('.report-card-check')]; const bar = document.getElementById('pending-action-bar'); const count = document.getElementById('pending-selected-count'); const modal = new bootstrap.Modal(document.getElementById('pendingDecisionModal')); let decision = '';
    const selected = () => boxes.filter(box => box.checked); const refresh = () => { count.textContent = selected().length; bar.hidden = selected().length === 0; }; boxes.forEach(box => box.addEventListener('change', refresh));
    document.querySelectorAll('[data-decision]').forEach(button => button.addEventListener('click', () => { decision = button.dataset.decision; const total = selected().length; if (!total) return; document.getElementById('pending-decision-title').textContent = decision === 'approve' ? 'Endorse report cards to Admin' : 'Return report cards'; document.getElementById('pending-decision-copy').textContent = `This decision applies to ${total} selected report card${total === 1 ? '' : 's'}.`; document.getElementById('pending-remarks-required').textContent = decision === 'approve' ? '(optional)' : '(required)'; modal.show(); }));
    document.getElementById('pending-decision-form').addEventListener('submit', async event => { event.preventDefault(); const error = document.getElementById('pending-decision-error'); const submit = document.getElementById('pending-decision-submit'); const remarks = document.getElementById('pending-remarks').value.trim(); if (decision === 'reject' && !remarks) { error.textContent = 'Enter a reason before returning the report card.'; error.hidden = false; return; } submit.disabled = true; const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrfToken); ?>, decision, remarks, report_card_ids: JSON.stringify(selected().map(box => box.value)) }); try { const response = await fetch('principal_Action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body }); const payload = await response.json(); if (!response.ok || !payload.success) throw new Error(payload.message || 'The decision could not be saved.'); window.location.reload(); } catch (requestError) { error.textContent = requestError.message; error.hidden = false; submit.disabled = false; } });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
