<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'principal') {
    header('Location: ../auth/login.php');
    exit;
}

$db = (new Database())->getConnection();
$gradeCounts = ['submitted' => 0, 'admin_verified' => 0, 'rejected' => 0];
$cardCounts = ['submitted_admin' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$recent = [];
$loadError = '';
try {
    foreach ($db->query("SELECT status, COUNT(*) total FROM grade_approvals WHERE status IN ('submitted','admin_verified','rejected') GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gradeCounts[(string)$row['status']] = (int)$row['total'];
    }
    foreach ($db->query("SELECT status, COUNT(*) total FROM report_card_approvals GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists((string)$row['status'], $cardCounts)) {
            $cardCounts[(string)$row['status']] = (int)$row['total'];
        }
    }
    $recent = $db->query("SELECT al.action_name, al.target_type, al.created_at, u.first_name, u.last_name, u.role
                           FROM activity_logs al
                           LEFT JOIN users u ON u.id = al.actor_user_id
                           WHERE al.target_type IN ('grade_approval','report_card')
                           ORDER BY al.created_at DESC, al.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Principal academic monitoring load failed.');
    $loadError = 'Academic monitoring could not be loaded.';
}

$current_role = 'principal';
$current_page = 'academic_monitoring';
$page_title = 'Academic Monitoring';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars($page_title); ?> - Balingasag Senior High School</title><link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet"><link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>"><link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>"><link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>"><?php echo pwaHeadHtml(); ?></head><body>
<?php include __DIR__ . '/../includes/sidebar.php'; ?><main class="main-content"><?php include __DIR__ . '/../includes/header.php'; ?><div class="page-content">
<section class="admin-hero admin-hero-compact mb-4" aria-labelledby="academic-monitoring-heading"><div class="admin-hero-main"><div class="welcome-role-chip"><i class="bi bi-bar-chart-line"></i><span>Read-only oversight</span></div><h1 class="h4 mb-2" id="academic-monitoring-heading">Academic workflow monitoring</h1><p class="text-muted mb-0">Track subject-grade verification, report-card endorsement, and final Admin release across the school.</p></div></section>
<?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>
<section class="content-card mb-4" aria-labelledby="subject-pipeline-heading"><div class="content-card-header"><h2 class="content-card-title" id="subject-pipeline-heading">Subject-grade pipeline</h2></div><div class="content-card-body"><div class="row g-3"><div class="col-12 col-md-4"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Awaiting Principal</small><strong class="fs-3 text-warning"><?php echo number_format($gradeCounts['submitted']); ?></strong></div></div><div class="col-12 col-md-4"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Principal verified</small><strong class="fs-3 text-success"><?php echo number_format($gradeCounts['admin_verified']); ?></strong></div></div><div class="col-12 col-md-4"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Returned for correction</small><strong class="fs-3 text-danger"><?php echo number_format($gradeCounts['rejected']); ?></strong></div></div></div></div></section>
<section class="content-card mb-4" aria-labelledby="card-pipeline-heading"><div class="content-card-header"><h2 class="content-card-title" id="card-pipeline-heading">Report-card pipeline</h2></div><div class="content-card-body"><div class="row g-3"><div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Awaiting endorsement</small><strong class="fs-3 text-warning"><?php echo number_format($cardCounts['submitted_admin']); ?></strong></div></div><div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Awaiting Admin</small><strong class="fs-3 text-info"><?php echo number_format($cardCounts['pending']); ?></strong></div></div><div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Admin released</small><strong class="fs-3 text-success"><?php echo number_format($cardCounts['approved']); ?></strong></div></div><div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><small class="text-muted d-block">Returned / withdrawn</small><strong class="fs-3 text-danger"><?php echo number_format($cardCounts['rejected']); ?></strong></div></div></div></div></section>
<section class="content-card" aria-labelledby="recent-academic-heading"><div class="content-card-header"><h2 class="content-card-title" id="recent-academic-heading">Recent academic activity</h2></div><div class="content-card-body"><div class="table-responsive"><table class="table custom-table align-middle mb-0"><thead><tr><th>Actor</th><th>Action</th><th>Record</th><th>Date</th></tr></thead><tbody><?php if (!$recent): ?><tr><td colspan="4"><div class="empty-state py-4">No academic activity has been recorded.</div></td></tr><?php endif; ?><?php foreach ($recent as $row): ?><tr><td><strong><?php echo htmlspecialchars(trim((string)$row['first_name'] . ' ' . (string)$row['last_name']) ?: 'System'); ?></strong><small class="d-block text-muted"><?php echo htmlspecialchars(ucfirst((string)($row['role'] ?: 'system'))); ?></small></td><td><?php echo htmlspecialchars(ucwords(str_replace(['.', '_'], ' ', (string)$row['action_name']))); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['target_type']))); ?></td><td><time datetime="<?php echo htmlspecialchars((string)$row['created_at']); ?>"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['created_at']))); ?></time></td></tr><?php endforeach; ?></tbody></table></div></div></section>
</div></main><?php include __DIR__ . '/../includes/footer.php'; ?>
