<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$announcements = [];

if ($db) {
    $stmt = $db->query("SELECT a.title, a.content, a.category, a.created_at, a.views,
                        CONCAT(u.first_name, ' ', u.last_name) AS posted_by_name
                        FROM announcements a
                        LEFT JOIN users u ON u.id = a.posted_by
                        WHERE a.status = 'active'
                        ORDER BY a.created_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_role = 'teacher';
$current_page = 'announcements';
$page_title = 'Announcements';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <div class="teacher-hero mb-4">
            <div class="teacher-hero-grid">
                <div class="teacher-hero-copy">
                    <div class="app-detail-kicker mb-2">School Notices</div>
                    <h4 class="mb-2">Announcements</h4>
                    <p class="mb-0">Stay updated with official school announcements, browse by category, and quickly narrow the list to the notices that matter to your classes.</p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip"><i class="bi bi-megaphone"></i><?php echo number_format(count($announcements)); ?> active notices</span>
                        <span class="teacher-chip"><i class="bi bi-filter-circle"></i>Category and search filters ready</span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Visible Announcements</span>
                            <span class="teacher-summary-value"><?php echo number_format(count($announcements)); ?></span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Workspace</span>
                            <span class="teacher-summary-value">Read</span>
                        </div>
                    </div>
                    <p class="teacher-summary-note">Use this page as the full teacher-facing school notice board, separate from your class-specific announcements.</p>
                </div>
            </div>
        </div>
        <div class="teacher-overview-grid mb-4">
            <div class="teacher-overview-card">
                <strong><?php echo number_format(count($announcements)); ?></strong>
                <span>Total Active Notices</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format(count(array_filter($announcements, fn($a) => ($a['category'] ?? '') === 'urgent'))); ?></strong>
                <span>Urgent Notices</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format(array_sum(array_map(fn($a) => (int)($a['views'] ?? 0), $announcements))); ?></strong>
                <span>Total Views</span>
            </div>
        </div>
        <div class="content-card">
            <div class="content-card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <h5 class="content-card-title mb-1">School Announcements</h5>
                    <div class="teacher-section-note">Filter by category or search by title and content to narrow the current notice board.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <input type="text" id="announcementSearch" class="form-control form-control-sm" style="width: 220px;" placeholder="Search announcements">
                    <select id="announcementCategoryFilter" class="form-select form-select-sm" style="width: 160px;">
                        <option value="all">All Categories</option>
                        <option value="general">General</option>
                        <option value="urgent">Urgent</option>
                        <option value="event">Event</option>
                        <option value="academic">Academic</option>
                    </select>
                </div>
            </div>
            <div class="content-card-body">
                <?php if (empty($announcements)): ?>
                    <div class="text-center text-muted py-3">No active announcements.</div>
                <?php else: ?>
                    <div id="announcementList">
                    <?php foreach ($announcements as $a): ?>
                        <div class="announcement-card <?php echo htmlspecialchars($a['category']); ?> mb-3"
                             data-category="<?php echo htmlspecialchars($a['category']); ?>"
                             data-title="<?php echo htmlspecialchars($a['title']); ?>"
                             data-content="<?php echo htmlspecialchars($a['content']); ?>">
                            <div class="announcement-header">
                                <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                <span class="announcement-badge <?php echo htmlspecialchars($a['category']); ?>"><?php echo ucfirst($a['category']); ?></span>
                            </div>
                            <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                            <div class="announcement-meta">
                                <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($a['posted_by_name'] ?: 'Unknown'); ?></span>
                                <span><i class="bi bi-eye"></i> <?php echo (int)$a['views']; ?> views</span>
                                <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <div id="announcementEmptyState" class="text-center text-muted py-3 d-none">No announcements match your filter.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="../assets/js/main.js"></script>
<script>
function normalizeFilterValue(value){return (value||'').toString().trim().toLowerCase();}
function filterTeacherAnnouncements(){
    const list=document.getElementById('announcementList');
    if(!list)return;
    const search=normalizeFilterValue(document.getElementById('announcementSearch')?.value);
    const category=normalizeFilterValue(document.getElementById('announcementCategoryFilter')?.value||'all');
    const cards=list.querySelectorAll('.announcement-card');
    let visible=0;
    cards.forEach(card=>{
        const c=normalizeFilterValue(card.dataset.category||'');
        const title=normalizeFilterValue(card.dataset.title||'');
        const content=normalizeFilterValue(card.dataset.content||'');
        const matchesCategory=(category==='all'||c===category);
        const matchesSearch=(search===''||title.includes(search)||content.includes(search));
        const show=matchesCategory&&matchesSearch;
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    const emptyState=document.getElementById('announcementEmptyState');
    if(emptyState){emptyState.classList.toggle('d-none',visible>0);}
}
document.getElementById('announcementSearch')?.addEventListener('input', filterTeacherAnnouncements);
document.getElementById('announcementCategoryFilter')?.addEventListener('change', filterTeacherAnnouncements);
</script>
</body>
</html>
