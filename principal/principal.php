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
        <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="principal-heading">
            <div class="admin-hero-grid">
                <div class="admin-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-patch-check"></i><span>Academic verification authority</span></div>
                    <h1 class="h4 mb-2" id="principal-heading">Principal monitoring dashboard</h1>
                    <p class="text-muted mb-0">Verify subject grades, endorse Adviser report cards, and monitor final Admin releases.</p>
                </div>
                <div class="admin-hero-side principal-workflow" aria-label="Approval workflow">
                    <span>Teacher</span><i class="bi bi-arrow-right"></i><strong>Principal</strong><i class="bi bi-arrow-right"></i><span>Adviser</span><i class="bi bi-arrow-right"></i><strong>Principal</strong><i class="bi bi-arrow-right"></i><span>Admin</span>
                </div>
            </div>
        </section>

        <?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>

        <div class="row g-3 mb-4" aria-label="Report-card totals">
            <div class="col-12 col-sm-6 col-xl-3"><a class="content-card principal-stat-card" href="principal_Pending.php"><span>Pending endorsement<small class="d-block text-muted mt-1">Open Adviser submissions <i class="bi bi-arrow-right"></i></small></span><strong><?php echo number_format($counts['submitted_admin']); ?></strong></a></div>
            <div class="col-12 col-sm-6 col-xl-3"><a class="content-card principal-stat-card" href="principal_Endorsed.php"><span>Awaiting Admin<small class="d-block text-muted mt-1">Monitor endorsed cards <i class="bi bi-arrow-right"></i></small></span><strong><?php echo number_format($counts['pending']); ?></strong></a></div>
            <div class="col-12 col-sm-6 col-xl-3"><a class="content-card principal-stat-card" href="principal_Released.php"><span>Admin released<small class="d-block text-muted mt-1">Review family-visible cards <i class="bi bi-arrow-right"></i></small></span><strong><?php echo number_format($counts['approved']); ?></strong></a></div>
            <div class="col-12 col-sm-6 col-xl-3"><a class="content-card principal-stat-card" href="principal_History.php"><span>Returned / withdrawn<small class="d-block text-muted mt-1">Review decision history <i class="bi bi-arrow-right"></i></small></span><strong><?php echo number_format($counts['rejected']); ?></strong></a></div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-7">
                <section class="content-card h-100" aria-labelledby="pending-preview-heading">
                    <div class="content-card-header d-flex justify-content-between align-items-center gap-3"><h2 class="content-card-title" id="pending-preview-heading">Next for review</h2><a class="btn btn-primary btn-sm" href="principal_Pending.php">Open queue</a></div>
                    <div class="content-card-body">
                        <div class="table-responsive">
                            <table class="table custom-table align-middle mb-0">
                                <thead><tr><th scope="col">Learner</th><th scope="col">Class</th><th scope="col">Verified</th><th scope="col">Submitted</th></tr></thead>
                                <tbody>
                                <?php if (!$pendingRows): ?><tr><td colspan="4"><div class="empty-state py-4"><i class="bi bi-check2-circle"></i><p class="mb-0">Nothing is waiting for review.</p></div></td></tr><?php endif; ?>
                                <?php foreach ($pendingRows as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['reference_code']); ?></small></td>
                                        <td>Grade <?php echo (int)$row['grade_level']; ?><small class="d-block text-muted"><?php echo htmlspecialchars((string)$row['section']); ?></small></td>
                                        <td><strong><?php echo (int)$row['verified_count']; ?>/<?php echo (int)$row['subject_count']; ?></strong></td>
                                        <td><small><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$row['submitted_at']))); ?></small></td>
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
                    <div class="content-card-header d-flex justify-content-between align-items-center gap-3"><h2 class="content-card-title" id="recent-decisions-heading">Recent decisions</h2><a class="btn btn-outline-primary btn-sm" href="principal_History.php">View history</a></div>
                    <div class="content-card-body">
                        <?php if (!$decisionRows): ?><div class="empty-state py-4"><i class="bi bi-clock-history"></i><p class="mb-0">No decisions have been recorded.</p></div><?php endif; ?>
                        <div class="d-grid gap-3">
                        <?php foreach ($decisionRows as $row):
                            $isReleased = $row['status'] === 'approved';
                        ?>
                            <div class="d-flex align-items-start justify-content-between gap-3 border-bottom pb-3">
                                <div><strong class="d-block"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong><small class="text-muted">Grade <?php echo (int)$row['grade_level']; ?> &middot; <?php echo htmlspecialchars((string)$row['section']); ?></small></div>
                                <div class="text-end"><span class="badge text-bg-<?php echo $isReleased ? 'success' : 'danger'; ?>"><?php echo $isReleased ? 'Released' : 'Returned'; ?></span><small class="d-block text-muted mt-1"><?php echo htmlspecialchars(date('M j, Y', strtotime((string)($row['reviewed_at'] ?: $row['submitted_at'])))); ?></small></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
