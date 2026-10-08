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
    $rows = $query->find(['approved'], $filters);
    $years = $query->academicYears();
    $sections = $query->sections();
} catch (Throwable $e) {
    error_log('Principal release register load failed.');
    $loadError = 'The released report-card register could not be loaded.';
}
$current_role = 'principal';
$current_page = 'released';
$page_title = 'Released Report Cards';
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
    <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="released-heading"><div class="admin-hero-grid"><div class="admin-hero-main"><div class="welcome-role-chip"><i class="bi bi-patch-check"></i><span>Official release register</span></div><h1 class="h4 mb-2" id="released-heading">Released report cards</h1><p class="text-muted mb-0">These report cards are currently visible in the Student and Parent portals.</p></div><div class="admin-hero-side text-center"><strong class="d-block fs-2"><?php echo count($rows); ?></strong><span>matching released cards</span></div></div></section>
    <div class="alert alert-info d-flex align-items-start gap-3" role="note"><i class="bi bi-eye fs-4"></i><div><strong class="d-block">Family-visible records</strong>Withdrawing a release immediately hides the report card from Student and Parent portals until it passes review again.</div></div>
    <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
    <section class="content-card" aria-labelledby="register-heading">
        <div class="content-card-header d-flex justify-content-between align-items-center gap-3"><h2 class="content-card-title" id="register-heading">Release register</h2><a class="btn btn-outline-primary btn-sm" href="principal_History.php"><i class="bi bi-clock-history me-1"></i> Decision history</a></div>
        <div class="content-card-body">
            <form method="get" class="row g-3 align-items-end app-responsive-filter-form mb-4">
                <div class="col-12 col-lg-3"><label class="form-label" for="released-search">Student</label><input class="form-control" id="released-search" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Name or reference"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="released-year">School year</label><select class="form-select" id="released-year" name="academic_year"><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo $filters['academic_year'] === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$year); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="released-term">Term</label><input class="form-control" id="released-term" name="semester" value="<?php echo htmlspecialchars($filters['semester']); ?>" placeholder="All terms"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="released-grade">Grade</label><select class="form-select" id="released-grade" name="grade_level"><option value="0">All grades</option><option value="11" <?php echo $filters['grade_level'] === 11 ? 'selected' : ''; ?>>11</option><option value="12" <?php echo $filters['grade_level'] === 12 ? 'selected' : ''; ?>>12</option></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="released-section">Section</label><select class="form-select" id="released-section" name="section"><option value="">All sections</option><?php foreach ($sections as $section): ?><option value="<?php echo htmlspecialchars((string)$section); ?>" <?php echo strcasecmp($filters['section'], (string)$section) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$section); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Apply release register filters"><i class="bi bi-search"></i><span class="d-lg-none ms-1">Search</span></button></div>
            </form>
            <div class="principal-action-bar mb-3" id="released-action-bar" hidden><span><strong id="released-selected-count">0</strong> selected for withdrawal</span><button class="btn btn-warning btn-sm" type="button" id="open-withdrawal"><i class="bi bi-slash-circle"></i> Withdraw release</button></div>
            <div class="table-responsive"><table class="table custom-table align-middle"><thead><tr><th scope="col"><span class="visually-hidden">Select</span></th><th scope="col">Learner</th><th scope="col">Released period</th><th scope="col">Released by</th><th scope="col">Release date</th><th scope="col">Visibility</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="6"><div class="empty-state py-5"><i class="bi bi-journal-x"></i><p class="mb-0">No released report cards match these filters.</p></div></td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr><td><input class="form-check-input released-card-check" type="checkbox" value="<?php echo (int)$row['id']; ?>" aria-label="Select released report card for <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>"></td><td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['reference_code']); ?> &middot; Grade <?php echo (int)$row['grade_level']; ?> <?php echo htmlspecialchars((string)$row['section']); ?></small></td><td><?php echo htmlspecialchars((string)$row['academic_year']); ?><small class="d-block text-muted"><?php echo htmlspecialchars((string)($row['semester'] ?: 'Final')); ?></small></td><td><?php echo htmlspecialchars((string)($row['reviewer_name'] ?: 'Principal')); ?></td><td><?php echo $row['reviewed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['reviewed_at']))) : 'Not recorded'; ?></td><td><span class="badge text-bg-success"><i class="bi bi-eye me-1"></i>Visible to family</span></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </section>
</div>
</main>
<div class="modal fade" id="withdrawalModal" tabindex="-1" aria-labelledby="withdrawal-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="withdrawal-form"><div class="modal-header"><h2 class="modal-title fs-5" id="withdrawal-title">Withdraw released report cards</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p id="withdrawal-copy"></p><div class="alert alert-warning">Selected report cards will stop appearing in Student and Parent portals.</div><label class="form-label" for="withdrawal-remarks">Reason for withdrawal</label><textarea class="form-control" id="withdrawal-remarks" maxlength="255" rows="3" required></textarea><div class="alert alert-danger mt-3 mb-0" id="withdrawal-error" role="alert" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning" id="withdrawal-submit">Confirm withdrawal</button></div></form></div></div>
<script>
window.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('.released-card-check')]; const bar = document.getElementById('released-action-bar'); const count = document.getElementById('released-selected-count'); const modal = new bootstrap.Modal(document.getElementById('withdrawalModal')); const selected = () => boxes.filter(box => box.checked); const refresh = () => { count.textContent = selected().length; bar.hidden = selected().length === 0; }; boxes.forEach(box => box.addEventListener('change', refresh));
    document.getElementById('open-withdrawal').addEventListener('click', () => { const total = selected().length; if (!total) return; document.getElementById('withdrawal-copy').textContent = `You are withdrawing ${total} released report card${total === 1 ? '' : 's'}.`; modal.show(); });
    document.getElementById('withdrawal-form').addEventListener('submit', async event => { event.preventDefault(); const remarks = document.getElementById('withdrawal-remarks').value.trim(); const error = document.getElementById('withdrawal-error'); const submit = document.getElementById('withdrawal-submit'); if (!remarks) { error.textContent = 'Enter a reason for the withdrawal.'; error.hidden = false; return; } submit.disabled = true; const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrfToken); ?>, decision: 'withdraw', remarks, report_card_ids: JSON.stringify(selected().map(box => box.value)) }); try { const response = await fetch('principal_Action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body }); const payload = await response.json(); if (!response.ok || !payload.success) throw new Error(payload.message || 'The release could not be withdrawn.'); window.location.reload(); } catch (requestError) { error.textContent = requestError.message; error.hidden = false; submit.disabled = false; } });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
