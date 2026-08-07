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
$archives = [];
$gradeArchives = [];
$teacherId = (int)($_SESSION['user_id'] ?? 0);

if ($db) {
    $stmt = $db->query("SELECT a.title, a.content, a.category, a.updated_at,
                        CONCAT(u.first_name, ' ', u.last_name) AS posted_by_name
                        FROM announcements a
                        LEFT JOIN users u ON u.id = a.posted_by
                        WHERE a.status = 'archived'
                        ORDER BY a.updated_at DESC");
    $archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasGradeItems = dbHasTable($db, 'grade_items');
    if ($hasGradeItems && $teacherId > 0) {
        $gStmt = $db->prepare("SELECT gi.id, gi.title, gi.component, gi.total_score, gi.activity_date, gi.finished_at, c.class_name, c.grade_level, c.section
                               FROM grade_items gi
                               JOIN classes c ON c.id = gi.class_id
                               WHERE gi.teacher_id = ? AND gi.status = 'finished'
                               ORDER BY gi.finished_at DESC, gi.activity_date DESC");
        $gStmt->execute([$teacherId]);
        $gradeArchives = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    }

}

$current_role = 'teacher';
$current_page = 'archives';
$page_title = 'Archives';
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">Archives</h4>
                <p class="text-muted mb-0">Browse archived announcements and completed grade activities.</p>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header"><h5 class="content-card-title">Archived Announcements</h5></div>
            <div class="content-card-body">
                <?php if (empty($archives)): ?>
                    <div class="text-center text-muted py-3">No archived announcements.</div>
                <?php else: ?>
                    <?php foreach ($archives as $a): ?>
                        <div class="announcement-card <?php echo htmlspecialchars($a['category']); ?> mb-3">
                            <div class="announcement-header">
                                <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                <span class="announcement-badge <?php echo htmlspecialchars($a['category']); ?>"><?php echo ucfirst($a['category']); ?></span>
                            </div>
                            <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                            <div class="announcement-meta">
                                <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($a['posted_by_name'] ?: 'Unknown'); ?></span>
                                <span><i class="bi bi-clock-history"></i> Archived <?php echo date('M d, Y h:i A', strtotime($a['updated_at'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card mt-4">
            <div class="content-card-header"><h5 class="content-card-title">Archived Grade Activities</h5></div>
            <div class="content-card-body">
                <?php if (empty($gradeArchives)): ?>
                    <div class="text-center text-muted py-3">No archived grade activities.</div>
                <?php else: ?>
                    <?php foreach ($gradeArchives as $g): ?>
                        <div class="announcement-card info mb-3">
                            <div class="announcement-header">
                                <h6 class="announcement-title"><?php echo htmlspecialchars($g['title']); ?></h6>
                                <span class="announcement-badge info"><?php echo htmlspecialchars($g['class_name'] . ' (G' . $g['grade_level'] . ' - ' . $g['section'] . ')'); ?></span>
                            </div>
                            <p class="announcement-content">
                                <strong>Component:</strong> <?php echo htmlspecialchars($g['component']); ?> |
                                <strong>Total Score:</strong> <?php echo htmlspecialchars($g['total_score']); ?> |
                                <strong>Activity Date:</strong> <?php echo htmlspecialchars($g['activity_date']); ?>
                            </p>
                            <div class="announcement-meta">
                                <span><i class="bi bi-clock-history"></i> Archived <?php echo $g['finished_at'] ? date('M d, Y h:i A', strtotime($g['finished_at'])) : 'N/A'; ?></span>
                                <button class="btn btn-sm btn-outline-success ms-auto" type="button" onclick="restoreGradeItem(<?php echo (int)$g['id']; ?>)">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
<script>
function restoreGradeItem(id) {
    if (!id) return;
    fetch('teacher_Action.php?action=restore_grade_item', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: id })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showNotification(d.message || 'Grade activity restored', 'success');
            setTimeout(() => window.location.reload(), 600);
        } else {
            showNotification(d.message || 'Failed to restore activity', 'danger');
        }
    })
    .catch(() => showNotification('Failed to restore activity', 'danger'));
}
</script>
</body>
</html>
