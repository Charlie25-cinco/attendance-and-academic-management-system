<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/Login.php");
    exit();
}

$db = (new Database())->getConnection();
$parentId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'parent';
$current_page = 'announcements';
$page_title = 'Announcements';

$children = [];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
$announcements = [];

if ($db && $parentId > 0) {
    $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                  FROM parent_students ps
                                  JOIN users u ON u.id = ps.student_id
                                  WHERE ps.parent_id = ? AND u.role = 'student' AND u.status IN ('active', 'pending')
                                  ORDER BY u.last_name, u.first_name");
    $childrenStmt->execute([$parentId]);
    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($children)) {
        $validIds = array_map('intval', array_column($children, 'id'));
        if (!in_array($selectedStudentId, $validIds, true)) {
            $selectedStudentId = (int)$children[0]['id'];
        }
        foreach ($children as $child) {
            if ((int)$child['id'] === $selectedStudentId) {
                $selectedStudent = $child;
                break;
            }
        }
    }

    if ($selectedStudentId > 0) {
        $classStmt = $db->prepare("SELECT class_id
                                   FROM enrollments
                                   WHERE student_id = ? AND status = 'enrolled'");
        $classStmt->execute([$selectedStudentId]);
        $classIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($classIds)) {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $classAnnStmt = $db->prepare("SELECT ca.title, ca.content, ca.created_at, c.class_name,
                                          'class' AS source, 'class' AS category
                                          FROM class_announcements ca
                                          JOIN classes c ON c.id = ca.class_id
                                          WHERE ca.class_id IN ($ph) AND ca.status = 'active'
                                          ORDER BY ca.created_at DESC
                                          LIMIT 20");
            $classAnnStmt->execute($classIds);
            $announcements = $classAnnStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $schoolAnn = $db->query("SELECT title, content, created_at, category, 'school' AS source
                                 FROM announcements
                                 WHERE status = 'active'
                                 ORDER BY created_at DESC
                                 LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($schoolAnn as $sa) {
            $announcements[] = [
                'title' => $sa['title'],
                'content' => $sa['content'],
                'created_at' => $sa['created_at'],
                'class_name' => 'School',
                'source' => $sa['source'],
                'category' => $sa['category'] ?: 'general'
            ];
        }

        usort($announcements, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        $announcements = array_slice($announcements, 0, 30);
    }
}

$selectedStudentName = $selectedStudent ? trim((string)($selectedStudent['first_name'] ?? '') . ' ' . (string)($selectedStudent['last_name'] ?? '')) : 'No student selected';
$selectedStudentRef = trim((string)($selectedStudent['reference_code'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Balingasag Senior High School</title>
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
        <section class="parent-hero parent-hero-compact mb-4">
            <div class="parent-hero-grid">
                <div class="parent-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-megaphone"></i><span>Parent Announcements</span></div>
                    <h4 class="mb-2">Stay updated on school and class notices.</h4>
                    <p class="text-muted mb-3">Filter updates for the selected child and quickly search across school-wide notices and class-specific announcements.</p>
                    <div class="parent-chip-row">
                        <span class="parent-chip"><i class="bi bi-person"></i><?php echo htmlspecialchars($selectedStudentName); ?></span>
                        <?php if ($selectedStudentRef !== ''): ?>
                            <span class="parent-chip"><i class="bi bi-upc-scan"></i><?php echo htmlspecialchars($selectedStudentRef); ?></span>
                        <?php endif; ?>
                        <span class="parent-chip"><i class="bi bi-bell"></i><?php echo number_format(count($announcements)); ?> updates loaded</span>
                    </div>
                </div>
                <div class="parent-hero-side">
                    <div class="parent-summary-panel">
                        <div class="parent-summary-grid">
                            <div class="parent-summary-stat">
                                <span class="parent-summary-label">Linked Students</span>
                                <strong class="parent-summary-value"><?php echo number_format(count($children)); ?></strong>
                            </div>
                            <div class="parent-summary-stat">
                                <span class="parent-summary-label">Visible Updates</span>
                                <strong class="parent-summary-value"><?php echo number_format(count($announcements)); ?></strong>
                            </div>
                        </div>
                        <p class="parent-summary-note">Use the student switcher and filters below to isolate the notices that matter right now.</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="content-card mb-4">
            <div class="content-card-header"><h5 class="content-card-title">Select Student</h5></div>
            <div class="content-card-body">
                <?php if (empty($children)): ?>
                    <div class="text-muted">No linked student found. Ask admin to link your account.</div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 parent-switcher">
                        <?php foreach ($children as $child): ?>
                            <a href="?student_id=<?php echo (int)$child['id']; ?>" class="btn <?php echo ((int)$child['id'] === $selectedStudentId) ? 'btn-primary-custom' : 'btn-outline-secondary'; ?>">
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                <?php if (!empty($child['reference_code'])): ?>
                                    <small class="ms-1">(<?php echo htmlspecialchars((string)$child['reference_code']); ?>)</small>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedStudent): ?>
        <div class="content-card">
            <div class="content-card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h5 class="content-card-title mb-0">Latest Updates</h5>
                <div class="app-announcement-toolbar">
                    <input type="text" id="announcementSearch" class="form-control form-control-sm app-announcement-search" placeholder="Search announcements">
                    <select id="announcementSourceFilter" class="form-select form-select-sm app-announcement-select">
                        <option value="all">All Sources</option>
                        <option value="school">School</option>
                        <option value="class">Class</option>
                    </select>
                    <select id="announcementCategoryFilter" class="form-select form-select-sm app-announcement-select">
                        <option value="all">All Categories</option>
                        <option value="general">General</option>
                        <option value="urgent">Urgent</option>
                        <option value="event">Event</option>
                        <option value="academic">Academic</option>
                        <option value="class">Class</option>
                    </select>
                </div>
            </div>
            <div class="content-card-body">
                <div id="announcementList" class="row g-4">
                    <?php if (empty($announcements)): ?>
                        <div class="col-12"><div class="text-center text-muted py-3">No announcements available.</div></div>
                    <?php else: ?>
                        <?php foreach ($announcements as $a): ?>
                            <?php
                                $source = ($a['source'] ?? 'school') === 'class' ? 'class' : 'school';
                                $normalizedCategory = in_array(($a['category'] ?? 'general'), ['general', 'urgent', 'event', 'academic'], true) ? $a['category'] : 'general';
                                $filterCategory = $source === 'class' ? 'class' : $normalizedCategory;
                            ?>
                            <div class="col-md-6">
                                <div class="announcement-card info"
                                     data-source="<?php echo htmlspecialchars($source); ?>"
                                     data-category="<?php echo htmlspecialchars($filterCategory); ?>"
                                     data-title="<?php echo htmlspecialchars($a['title']); ?>"
                                     data-content="<?php echo htmlspecialchars($a['content']); ?>"
                                     data-class="<?php echo htmlspecialchars($a['class_name']); ?>">
                                    <div class="announcement-header">
                                        <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                        <span class="announcement-badge info"><?php echo htmlspecialchars($a['class_name']); ?></span>
                                    </div>
                                    <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                                    <div class="announcement-meta">
                                        <span><i class="bi bi-tag"></i> <?php echo ucfirst(htmlspecialchars($filterCategory)); ?></span>
                                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div id="announcementEmptyState" class="text-center text-muted py-3 d-none">No announcements match your filter.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="../assets/js/main.js"></script>
<script>
function normalizeFilterValue(value){return (value||'').toString().trim().toLowerCase();}
function filterParentAnnouncements(){
    const list=document.getElementById('announcementList');
    if(!list)return;
    const search=normalizeFilterValue(document.getElementById('announcementSearch')?.value);
    const source=normalizeFilterValue(document.getElementById('announcementSourceFilter')?.value||'all');
    const category=normalizeFilterValue(document.getElementById('announcementCategoryFilter')?.value||'all');
    const cards=list.querySelectorAll('.announcement-card');
    let visible=0;
    cards.forEach(card=>{
        const c=normalizeFilterValue(card.dataset.category||'');
        const s=normalizeFilterValue(card.dataset.source||'');
        const title=normalizeFilterValue(card.dataset.title||'');
        const content=normalizeFilterValue(card.dataset.content||'');
        const cls=normalizeFilterValue(card.dataset.class||'');
        const matchesSource=(source==='all'||s===source);
        const matchesCategory=(category==='all'||c===category);
        const matchesSearch=(search===''||title.includes(search)||content.includes(search)||cls.includes(search));
        const show=matchesSource&&matchesCategory&&matchesSearch;
        const wrapper=card.closest('.col-md-6');
        if(wrapper){wrapper.style.display=show?'':'none';}
        if(show)visible++;
    });
    const emptyState=document.getElementById('announcementEmptyState');
    if(emptyState){emptyState.classList.toggle('d-none',visible>0);}
}
document.getElementById('announcementSearch')?.addEventListener('input', filterParentAnnouncements);
document.getElementById('announcementSourceFilter')?.addEventListener('change', filterParentAnnouncements);
document.getElementById('announcementCategoryFilter')?.addEventListener('change', filterParentAnnouncements);
</script>
</body>
</html>
