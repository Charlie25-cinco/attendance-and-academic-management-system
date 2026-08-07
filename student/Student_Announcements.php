<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$studentId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'student';
$current_page = 'announcements';
$page_title = 'Announcements';

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$classes = [];
$announcements = [];

if ($db && $studentId > 0) {
    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$studentId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $classIds = array_map('intval', array_column($classes, 'id'));
        if ($selectedClassId > 0 && !in_array($selectedClassId, $classIds, true)) {
            $selectedClassId = 0;
        }

        $where = ["ca.status = 'active'", "ca.class_id IN (" . implode(',', array_fill(0, count($classIds), '?')) . ")"];
        $params = $classIds;
        if ($selectedClassId > 0) {
            $where[] = "ca.class_id = ?";
            $params[] = $selectedClassId;
        }

        $classAnnStmt = $db->prepare("SELECT ca.id AS notification_id, ca.title, ca.content, ca.created_at,
                                      c.class_name, c.grade_level, c.section, 'class' AS source, 'info' AS category
                                      FROM class_announcements ca
                                      JOIN classes c ON c.id = ca.class_id
                                      WHERE " . implode(" AND ", $where) . "
                                      ORDER BY ca.created_at DESC");
        $classAnnStmt->execute($params);
        $classAnnouncements = $classAnnStmt->fetchAll(PDO::FETCH_ASSOC);
        $announcements = $classAnnouncements;
    }

    $schoolStmt = $db->query("SELECT id AS notification_id, title, content, category, created_at, 'school' AS source
                              FROM announcements
                              WHERE status = 'active'
                              ORDER BY created_at DESC");
    $schoolAnnouncements = $schoolStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($schoolAnnouncements as $sa) {
        $announcements[] = [
            'notification_id' => $sa['notification_id'],
            'title' => $sa['title'],
            'content' => $sa['content'],
            'created_at' => $sa['created_at'],
            'class_name' => 'School',
            'grade_level' => '',
            'section' => '',
            'source' => $sa['source'],
            'category' => $sa['category'] ?: 'general'
        ];
    }

    usort($announcements, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
}

$selectedClassLabel = 'All Classes';
foreach ($classes as $class) {
    if ((int)$class['id'] === $selectedClassId) {
        $selectedClassLabel = (string)$class['class_name'];
        break;
    }
}
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
        <section class="student-hero student-hero-compact mb-4">
            <div class="student-hero-grid">
                <div class="student-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-megaphone"></i><span>Student Announcements</span></div>
                    <h4 class="mb-2">See school and class notices in one feed.</h4>
                    <p class="text-muted mb-3">Browse updates tied to your enrolled classes, then narrow the list by source, category, or keyword.</p>
                    <div class="student-chip-row">
                        <span class="student-chip"><i class="bi bi-journal-text"></i><?php echo htmlspecialchars($selectedClassLabel); ?></span>
                        <span class="student-chip"><i class="bi bi-bell"></i><?php echo number_format(count($announcements)); ?> updates loaded</span>
                    </div>
                </div>
                <div class="student-hero-side">
                    <div class="student-summary-panel">
                        <div class="student-summary-grid">
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Classes</span>
                                <strong class="student-summary-value"><?php echo number_format(count($classes)); ?></strong>
                            </div>
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Visible Updates</span>
                                <strong class="student-summary-value"><?php echo number_format(count($announcements)); ?></strong>
                            </div>
                        </div>
                        <p class="student-summary-note">Use the filters below to focus on a specific subject feed or announcement type.</p>
                    </div>
                </div>
            </div>
        </section>
        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Announcements</h5>
                <div class="app-announcement-toolbar">
                    <form method="GET" class="student-inline-form">
                        <select name="class_id" class="form-select form-select-sm app-announcement-select" onchange="this.form.submit()">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$class['id'] === $selectedClassId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
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
                <?php if (empty($announcements)): ?>
                    <div class="text-center text-muted py-3">No announcements available.</div>
                <?php else: ?>
                    <div id="announcementList">
                    <?php foreach ($announcements as $a): ?>
                        <?php
                            $normalizedCategory = in_array($a['category'], ['general', 'urgent', 'event', 'academic'], true) ? $a['category'] : 'general';
                            $source = ($a['source'] ?? 'school') === 'class' ? 'class' : 'school';
                            $filterCategory = $source === 'class' ? 'class' : $normalizedCategory;
                        ?>
                        <div class="announcement-card <?php echo htmlspecialchars($normalizedCategory); ?> mb-3"
                             id="notification-<?php echo htmlspecialchars($source); ?>-<?php echo (int)$a['notification_id']; ?>"
                             data-source="<?php echo htmlspecialchars($source); ?>"
                             data-category="<?php echo htmlspecialchars($filterCategory); ?>"
                             data-title="<?php echo htmlspecialchars($a['title']); ?>"
                             data-content="<?php echo htmlspecialchars($a['content']); ?>"
                             data-class="<?php echo htmlspecialchars($a['class_name']); ?>">
                            <div class="announcement-header">
                                <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                <span class="announcement-badge <?php echo htmlspecialchars($normalizedCategory); ?>">
                                    <?php echo htmlspecialchars($a['class_name']); ?>
                                </span>
                            </div>
                            <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                            <div class="announcement-meta">
                                <span><i class="bi bi-tag"></i> <?php echo ucfirst(htmlspecialchars($filterCategory)); ?></span>
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
function filterStudentAnnouncements(){
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
        card.style.display=show?'':'none';
        if(show)visible++;
    });
    const emptyState=document.getElementById('announcementEmptyState');
    if(emptyState){emptyState.classList.toggle('d-none',visible>0);}
}
document.getElementById('announcementSearch')?.addEventListener('input', filterStudentAnnouncements);
document.getElementById('announcementSourceFilter')?.addEventListener('change', filterStudentAnnouncements);
document.getElementById('announcementCategoryFilter')?.addEventListener('change', filterStudentAnnouncements);
</script>
</body>
</html>
