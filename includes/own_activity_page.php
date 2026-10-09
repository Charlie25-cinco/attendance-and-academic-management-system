<?php

use BshsAms\Audit\OwnActivityLogQuery;

if (!isset($activityRole, $activityClearUrl) || !in_array($activityRole, ['teacher', 'student', 'parent'], true)) {
    throw new LogicException('Own activity page configuration is invalid.');
}

$db = (new Database())->getConnection();
$activityResult = [
    'rows' => [],
    'total' => 0,
    'page' => 1,
    'total_pages' => 1,
    'filters' => ['search' => '', 'date_from' => '', 'date_to' => ''],
];
$activityLoadError = '';

try {
    $activityResult = (new OwnActivityLogQuery($db))->search(
        (int)($_SESSION['user_id'] ?? 0),
        $activityRole,
        $_GET
    );
} catch (Throwable $e) {
    error_log('Own activity log load failed.');
    $activityLoadError = 'Your activity history could not be loaded right now.';
}

$activityFilters = $activityResult['filters'];
$activityPageUrl = static function (int $page) use ($activityFilters, $activityClearUrl): string {
    $query = array_filter([
        'search' => $activityFilters['search'],
        'date_from' => $activityFilters['date_from'],
        'date_to' => $activityFilters['date_to'],
        'page' => $page,
    ], static fn ($value): bool => $value !== '');

    return $activityClearUrl . '?' . http_build_query($query);
};

$current_role = $activityRole;
$current_page = 'activity_logs';
$page_title = 'My Activity';
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
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/header.php'; ?>
    <div class="page-content">
        <section class="admin-hero admin-hero-compact mb-4" aria-labelledby="my-activity-heading">
            <div class="admin-hero-main">
                <div class="welcome-role-chip"><i class="bi bi-clock-history"></i><span>Private account history</span></div>
                <h1 class="h4 mb-2" id="my-activity-heading">My Activity</h1>
                <p class="text-muted mb-0">Review actions recorded for your account. Other users' activity and login diagnostics are not available here.</p>
            </div>
        </section>

        <section class="content-card mb-4" aria-labelledby="activity-filter-heading">
            <div class="content-card-header"><h2 class="content-card-title" id="activity-filter-heading">Filter activity</h2></div>
            <div class="content-card-body">
                <form method="get" class="row g-3 align-items-end app-responsive-filter-form">
                    <div class="col-12 col-lg-5">
                        <label class="form-label" for="activity-search">Action or target</label>
                        <input class="form-control" id="activity-search" name="search" maxlength="100" value="<?php echo htmlspecialchars($activityFilters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Example: attendance or report card">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="activity-from">From</label>
                        <input class="form-control" type="date" id="activity-from" name="date_from" value="<?php echo htmlspecialchars($activityFilters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="activity-to">To</label>
                        <input class="form-control" type="date" id="activity-to" name="date_to" value="<?php echo htmlspecialchars($activityFilters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6 col-lg-2 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                    </div>
                    <div class="col-6 col-lg-1 d-grid">
                        <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($activityClearUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Clear activity filters">Clear</a>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($activityLoadError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($activityLoadError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="content-card" aria-labelledby="activity-list-heading">
            <div class="content-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="content-card-title" id="activity-list-heading">Recorded actions</h2>
                <span class="badge bg-light text-dark"><?php echo (int)$activityResult['total']; ?> result<?php echo (int)$activityResult['total'] === 1 ? '' : 's'; ?></span>
            </div>
            <div class="content-card-body">
                <div class="table-responsive">
                    <table class="table custom-table align-middle mb-0">
                        <thead><tr><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Details</th><th scope="col">Date</th></tr></thead>
                        <tbody>
                        <?php if ($activityResult['rows'] === []): ?>
                            <tr><td colspan="4"><div class="empty-state py-4">No activity matches these filters.</div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($activityResult['rows'] as $activityRow): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars(ucwords(str_replace(['.', '_'], ' ', (string)$activityRow['action_name'])), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$activityRow['target_type'])), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($activityRow['target_id'])): ?><span class="text-muted">#<?php echo (int)$activityRow['target_id']; ?></span><?php endif; ?>
                                </td>
                                <td><small><?php echo htmlspecialchars(OwnActivityLogQuery::formatDetails($activityRow['details_json']), ENT_QUOTES, 'UTF-8'); ?></small></td>
                                <td><time datetime="<?php echo htmlspecialchars((string)$activityRow['created_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$activityRow['created_at'])), ENT_QUOTES, 'UTF-8'); ?></time></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ((int)$activityResult['total_pages'] > 1): ?>
                    <nav class="mt-4" aria-label="Activity history pages">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo (int)$activityResult['page'] <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($activityPageUrl(max(1, (int)$activityResult['page'] - 1)), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page <?php echo (int)$activityResult['page']; ?> of <?php echo (int)$activityResult['total_pages']; ?></span></li>
                            <li class="page-item <?php echo (int)$activityResult['page'] >= (int)$activityResult['total_pages'] ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($activityPageUrl(min((int)$activityResult['total_pages'], (int)$activityResult['page'] + 1)), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
