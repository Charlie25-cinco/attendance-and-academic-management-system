<?php

require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$db = (new Database())->getConnection();
$counts = ['pending' => 0, 'submitted_admin' => 0, 'approved' => 0, 'rejected' => 0];
$pendingRows = [];
$decisionRows = [];
$loadError = '';

try {
    $query = new \BshsAms\Grade\PrincipalReportCardQuery($db);
    $counts = $query->statusCounts();
    $pendingRows = $query->find(['submitted_admin'], [], 5);
    $decisionRows = $query->find(['approved', 'rejected'], [], 5);
} catch (Throwable $e) {
    error_log('Principal dashboard load failed.');
    $loadError = 'The dashboard could not be loaded. Confirm that the current database schema is installed.';
}

$principalStats = [
    [
        'label' => 'Pending endorsement',
        'description' => 'Adviser submissions ready for review',
        'count' => $counts['submitted_admin'],
        'href' => 'principal_Pending.php',
        'icon' => 'bi-journal-check',
        'tone' => 'warning',
    ],
    [
        'label' => 'Awaiting Admin',
        'description' => 'Endorsed cards waiting for release',
        'count' => $counts['pending'],
        'href' => 'principal_Endorsed.php',
        'icon' => 'bi-send-check',
        'tone' => 'info',
    ],
    [
        'label' => 'Admin released',
        'description' => 'Cards visible to families',
        'count' => $counts['approved'],
        'href' => 'principal_Released.php',
        'icon' => 'bi-patch-check',
        'tone' => 'success',
    ],
    [
        'label' => 'Returned / withdrawn',
        'description' => 'Records requiring follow-up',
        'count' => $counts['rejected'],
        'href' => 'principal_History.php',
        'icon' => 'bi-arrow-counterclockwise',
        'tone' => 'danger',
    ],
];

$principalShortcuts = [
    ['label' => 'Subject grades', 'description' => 'Verify teacher submissions', 'href' => 'principal_Subject_Grades.php', 'icon' => 'bi-clipboard-check'],
    ['label' => 'Academic monitoring', 'description' => 'Review school-wide progress', 'href' => 'principal_Academic_Monitoring.php', 'icon' => 'bi-bar-chart-line'],
    ['label' => 'Attendance monitoring', 'description' => 'Check attendance patterns', 'href' => 'principal_Attendance_Monitoring.php', 'icon' => 'bi-calendar2-check'],
    ['label' => 'Activity logs', 'description' => 'Review recorded actions', 'href' => 'principal_Activity_Logs.php', 'icon' => 'bi-activity'],
];

$current_role = 'principal';
$current_page = 'dashboard';
$page_title = 'Principal Dashboard';
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
        <section class="admin-hero admin-hero-compact principal-dashboard-hero mb-4" aria-labelledby="principal-heading">
            <div class="admin-hero-grid">
                <div class="admin-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-patch-check"></i><span>Academic verification authority</span></div>
                    <div>
                        <h1 class="h4 mb-2" id="principal-heading">Principal monitoring dashboard</h1>
                        <p class="text-muted mb-0">Focus on records that need review, then monitor each endorsement through final Admin release.</p>
                    </div>
                    <div class="principal-hero-actions" aria-label="Primary dashboard actions">
                        <a class="btn btn-primary btn-sm" href="principal_Pending.php"><i class="bi bi-inbox me-1"></i>Review queue</a>
                        <a class="btn btn-outline-primary btn-sm" href="principal_Subject_Grades.php"><i class="bi bi-clipboard-check me-1"></i>Verify subject grades</a>
                    </div>
                </div>
                <div class="admin-hero-side principal-workflow-shell">
                    <span class="principal-workflow-label">Approval path</span>
                    <ol class="principal-workflow" aria-label="Grade and report-card approval workflow">
                        <li><span class="principal-workflow-step">1</span><span>Teacher<small>Submits grades</small></span></li>
                        <li class="is-principal"><span class="principal-workflow-step">2</span><span>Principal<small>Verifies</small></span></li>
                        <li><span class="principal-workflow-step">3</span><span>Adviser<small>Compiles cards</small></span></li>
                        <li class="is-principal"><span class="principal-workflow-step">4</span><span>Principal<small>Endorses</small></span></li>
                        <li><span class="principal-workflow-step">5</span><span>Admin<small>Releases</small></span></li>
                    </ol>
                </div>
            </div>
        </section>

        <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>

        <div class="row g-3 mb-4 principal-metric-grid" aria-label="Report-card workflow totals">
            <?php foreach ($principalStats as $stat): ?>
                <div class="col-12 col-sm-6 col-xl-3">
                    <a class="content-card principal-stat-card principal-stat-card-<?php echo htmlspecialchars($stat['tone']); ?>" href="<?php echo htmlspecialchars($stat['href']); ?>">
                        <span class="principal-stat-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($stat['icon']); ?>"></i></span>
                        <span class="principal-stat-copy">
                            <span class="principal-stat-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                            <small><?php echo htmlspecialchars($stat['description']); ?></small>
                        </span>
                        <strong><?php echo number_format((int)$stat['count']); ?></strong>
                        <i class="bi bi-arrow-up-right principal-stat-arrow" aria-hidden="true"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <nav class="principal-quick-links mb-4" aria-label="Principal monitoring shortcuts">
            <?php foreach ($principalShortcuts as $shortcut): ?>
                <a class="principal-quick-link" href="<?php echo htmlspecialchars($shortcut['href']); ?>">
                    <span class="principal-quick-link-icon" aria-hidden="true"><i class="bi <?php echo htmlspecialchars($shortcut['icon']); ?>"></i></span>
                    <span><strong><?php echo htmlspecialchars($shortcut['label']); ?></strong><small><?php echo htmlspecialchars($shortcut['description']); ?></small></span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-xl-7">
                <section class="content-card h-100" aria-labelledby="pending-preview-heading">
                    <div class="content-card-header d-flex flex-wrap justify-content-between align-items-center gap-3"><div><h2 class="content-card-title" id="pending-preview-heading">Next for review</h2><small class="text-muted">The five most recent Adviser submissions</small></div><a class="btn btn-primary btn-sm" href="principal_Pending.php">Open full queue</a></div>
                    <div class="content-card-body">
                        <div class="table-responsive">
                            <table class="table custom-table align-middle mb-0">
                                <caption class="visually-hidden">Most recent report cards waiting for Principal endorsement</caption>
                                <thead><tr><th scope="col">Learner</th><th scope="col">Class</th><th scope="col">Readiness</th><th scope="col">Submitted</th></tr></thead>
                                <tbody>
                                <?php if (!$pendingRows): ?><tr><td colspan="4"><div class="empty-state py-4"><i class="bi bi-check2-circle"></i><p class="mb-0">Nothing is waiting for review.</p></div></td></tr><?php endif; ?>
                                <?php foreach ($pendingRows as $row):
                                    $subjectCount = (int)$row['subject_count'];
                                    $verifiedCount = (int)$row['verified_count'];
                                    $isReady = $subjectCount > 0 && $verifiedCount >= $subjectCount;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['reference_code']); ?></small></td>
                                        <td>Grade <?php echo (int)$row['grade_level']; ?><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['section']); ?></small></td>
                                        <td><span class="principal-readiness <?php echo $isReady ? 'is-ready' : 'is-pending'; ?>"><i class="bi <?php echo $isReady ? 'bi-check-circle-fill' : 'bi-hourglass-split'; ?>"></i><?php echo $verifiedCount; ?>/<?php echo $subjectCount; ?> verified</span></td>
                                        <td><time datetime="<?php echo htmlspecialchars((string)$row['submitted_at']); ?>"><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$row['submitted_at']))); ?></time></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-12 col-xl-5">
                <section class="content-card h-100" aria-labelledby="recent-decisions-heading">
                    <div class="content-card-header d-flex flex-wrap justify-content-between align-items-center gap-3"><div><h2 class="content-card-title" id="recent-decisions-heading">Recent decisions</h2><small class="text-muted">Latest released or returned records</small></div><a class="btn btn-outline-primary btn-sm" href="principal_History.php">View history</a></div>
                    <div class="content-card-body">
                        <?php if (!$decisionRows): ?><div class="empty-state py-4"><i class="bi bi-clock-history"></i><p class="mb-0">No decisions have been recorded.</p></div><?php endif; ?>
                        <div class="principal-decision-list">
                        <?php foreach ($decisionRows as $row):
                            $isReleased = $row['status'] === 'approved';
                        ?>
                            <article class="principal-decision-item">
                                <span class="principal-decision-icon <?php echo $isReleased ? 'is-released' : 'is-returned'; ?>" aria-hidden="true"><i class="bi <?php echo $isReleased ? 'bi-check2' : 'bi-arrow-return-left'; ?>"></i></span>
                                <div class="principal-decision-copy"><strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong><small>Grade <?php echo (int)$row['grade_level']; ?> &middot; <?php echo htmlspecialchars((string)$row['section']); ?></small></div>
                                <div class="principal-decision-meta"><span class="badge text-bg-<?php echo $isReleased ? 'success' : 'danger'; ?>"><?php echo $isReleased ? 'Released' : 'Returned'; ?></span><time datetime="<?php echo htmlspecialchars((string)($row['reviewed_at'] ?: $row['submitted_at'])); ?>"><?php echo htmlspecialchars(date('M j, Y', strtotime((string)($row['reviewed_at'] ?: $row['submitted_at'])))); ?></time></div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
