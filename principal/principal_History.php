<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$db = (new Database())->getConnection();
$allowedDecisions = ['', 'report_card.approve', 'report_card.reject', 'report_card.withdraw'];
$decision = trim((string)($_GET['decision'] ?? ''));
$filters = [
    'academic_year' => trim((string)($_GET['academic_year'] ?? '')),
    'grade_level' => in_array((int)($_GET['grade_level'] ?? 0), [11, 12], true) ? (int)$_GET['grade_level'] : 0,
    'section' => trim((string)($_GET['section'] ?? '')),
    'search' => trim((string)($_GET['search'] ?? '')),
    'decision' => in_array($decision, $allowedDecisions, true) ? $decision : '',
];
$events = $years = $sections = [];
$loadError = '';
try {
    $query = new \BshsAms\Grade\PrincipalReportCardQuery($db);
    $events = $query->decisionHistory($filters);
    $years = $query->academicYears();
    $sections = $query->sections();
} catch (Throwable $e) {
    error_log('Principal decision history load failed.');
    $loadError = 'The Principal decision history could not be loaded.';
}
$decisionCounts = ['report_card.approve' => 0, 'report_card.reject' => 0, 'report_card.withdraw' => 0];
foreach ($events as $event) {
    if (array_key_exists($event['action_name'], $decisionCounts)) {
        $decisionCounts[$event['action_name']]++;
    }
}
$decisionMeta = [
    'report_card.approve' => ['Released', 'success', 'bi-check2-circle'],
    'report_card.reject' => ['Returned', 'danger', 'bi-arrow-return-left'],
    'report_card.withdraw' => ['Withdrawn', 'warning', 'bi-slash-circle'],
];
$current_role = 'principal';
$current_page = 'history';
$page_title = 'Principal Decision History';
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
    <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="history-heading"><div class="admin-hero-grid"><div class="admin-hero-main"><div class="welcome-role-chip"><i class="bi bi-clock-history"></i><span>Read-only audit trail</span></div><h1 class="h4 mb-2" id="history-heading">Principal decision history</h1><p class="text-muted mb-0">Review every recorded report-card release, return, and withdrawal in chronological order.</p></div><div class="admin-hero-side"><i class="bi bi-lock fs-3"></i><span>Records cannot be edited here</span></div></div></section>
    <div class="row g-3 mb-4" aria-label="Decision totals for current filters">
        <div class="col-4"><div class="content-card p-3 h-100"><small class="text-muted d-block">Released</small><strong class="fs-3 text-success"><?php echo $decisionCounts['report_card.approve']; ?></strong></div></div>
        <div class="col-4"><div class="content-card p-3 h-100"><small class="text-muted d-block">Returned</small><strong class="fs-3 text-danger"><?php echo $decisionCounts['report_card.reject']; ?></strong></div></div>
        <div class="col-4"><div class="content-card p-3 h-100"><small class="text-muted d-block">Withdrawn</small><strong class="fs-3 text-warning"><?php echo $decisionCounts['report_card.withdraw']; ?></strong></div></div>
    </div>
    <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
    <section class="content-card" aria-labelledby="timeline-heading">
        <div class="content-card-header"><h2 class="content-card-title" id="timeline-heading">Decision timeline</h2></div>
        <div class="content-card-body">
            <form method="get" class="row g-3 align-items-end app-responsive-filter-form mb-4">
                <div class="col-12 col-lg-3"><label class="form-label" for="history-search">Student</label><input class="form-control" id="history-search" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Name or reference"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="history-decision">Decision</label><select class="form-select" id="history-decision" name="decision"><option value="">All decisions</option><?php foreach ($decisionMeta as $value => $meta): ?><option value="<?php echo $value; ?>" <?php echo $filters['decision'] === $value ? 'selected' : ''; ?>><?php echo $meta[0]; ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="history-year">School year</label><select class="form-select" id="history-year" name="academic_year"><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?php echo htmlspecialchars((string)$year); ?>" <?php echo $filters['academic_year'] === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$year); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="history-grade">Grade</label><select class="form-select" id="history-grade" name="grade_level"><option value="0">All grades</option><option value="11" <?php echo $filters['grade_level'] === 11 ? 'selected' : ''; ?>>11</option><option value="12" <?php echo $filters['grade_level'] === 12 ? 'selected' : ''; ?>>12</option></select></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="history-section">Section</label><select class="form-select" id="history-section" name="section"><option value="">All sections</option><?php foreach ($sections as $section): ?><option value="<?php echo htmlspecialchars((string)$section); ?>" <?php echo strcasecmp($filters['section'], (string)$section) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$section); ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-1 d-grid"><button class="btn btn-primary" type="submit" aria-label="Apply decision history filters"><i class="bi bi-funnel"></i><span class="d-lg-none ms-1">Apply</span></button></div>
            </form>
            <div class="d-grid gap-3">
            <?php if (!$events): ?><div class="empty-state py-5"><i class="bi bi-clock-history"></i><p class="mb-0">No Principal decisions match these filters.</p></div><?php endif; ?>
            <?php foreach ($events as $event): $meta = $decisionMeta[$event['action_name']] ?? ['Decision', 'secondary', 'bi-circle']; ?>
                <article class="border rounded-3 p-3"><div class="d-flex align-items-start gap-3"><span class="principal-history-icon rounded-circle bg-<?php echo $meta[1]; ?>-subtle text-<?php echo $meta[1]; ?> d-inline-flex align-items-center justify-content-center flex-shrink-0"><i class="bi <?php echo $meta[2]; ?>"></i></span><div class="flex-grow-1"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h3 class="h6 mb-1"><?php echo $meta[0]; ?>: <?php echo htmlspecialchars($event['first_name'] . ' ' . $event['last_name']); ?></h3><small class="text-muted"><?php echo htmlspecialchars((string)$event['reference_code']); ?> &middot; Grade <?php echo (int)$event['grade_level']; ?> <?php echo htmlspecialchars((string)$event['section']); ?></small></div><time class="text-muted" datetime="<?php echo htmlspecialchars((string)$event['decision_at']); ?>"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$event['decision_at']))); ?></time></div><div class="row g-2 mt-2"><div class="col-12 col-md-4"><small class="text-muted d-block">Academic period</small><?php echo htmlspecialchars((string)$event['academic_year']); ?> &middot; <?php echo htmlspecialchars((string)($event['semester'] ?: 'Final')); ?></div><div class="col-12 col-md-4"><small class="text-muted d-block">Reviewed by</small><?php echo htmlspecialchars((string)($event['reviewer_name'] ?: 'Principal')); ?></div><div class="col-12 col-md-4"><small class="text-muted d-block">Remarks</small><?php echo htmlspecialchars((string)($event['decision_remarks'] ?: 'No retained remarks for this event')); ?></div></div></div></div></article>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
