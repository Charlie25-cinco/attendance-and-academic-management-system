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
$teacherId = (int)($_SESSION['user_id'] ?? 0);

$formatSchedule = function ($schedule) {
    $schedule = trim((string)$schedule);
    if ($schedule === '') {
        return 'No schedule set';
    }
    $segments = array_filter(array_map('trim', preg_split('/\s*;\s*/', $schedule)));
    $formatted = [];
    foreach ($segments as $segment) {
        if (preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            $days = preg_replace('/\s*\/\s*/', ', ', trim($m[1]));
            $days = preg_replace('/\s*,\s*/', ', ', $days);
            $time = preg_replace('/\s+/', ' ', trim($m[2]));
            $formatted[] = $days . ' | ' . $time;
        } else {
            $formatted[] = $segment;
        }
    }
    return implode(' ; ', $formatted);
};

$classes = [];
$materials = [];
$classAnnouncements = [];

if ($db) {
    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule, c.room,
                               (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'enrolled') AS student_count
                               FROM class_subjects cs
                               JOIN classes c ON c.id = cs.class_id
                               WHERE cs.teacher_id = ? AND c.status = 'active'
                               GROUP BY c.id
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    $matStmt = $db->prepare("SELECT m.id, m.title, m.file_name, m.file_type, m.file_size, m.created_at, c.class_name, cs.class_id
                             FROM materials m
                             LEFT JOIN class_subjects cs ON m.class_subject_id = cs.id
                             LEFT JOIN classes c ON c.id = cs.class_id
                             WHERE m.uploaded_by = ?
                             ORDER BY m.created_at DESC
                             LIMIT 20");
    $matStmt->execute([$teacherId]);
    $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

    $annStmt = $db->prepare("SELECT ca.id, ca.class_id, ca.title, ca.content, ca.created_at,
                             c.class_name, c.grade_level, c.section
                             FROM class_announcements ca
                             JOIN classes c ON c.id = ca.class_id
                             WHERE ca.posted_by = ? AND ca.status = 'active'
                             ORDER BY ca.created_at DESC
                             LIMIT 20");
    $annStmt->execute([$teacherId]);
    $classAnnouncements = $annStmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_role = 'teacher';
$current_page = 'classes';
$page_title = 'Classes';
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
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <div class="teacher-hero mb-4">
            <div class="teacher-hero-grid">
                <div class="teacher-hero-copy">
                    <div class="app-detail-kicker mb-2">Classroom Workspace</div>
                    <h4 class="mb-2">My Classes</h4>
                    <p class="mb-0">Manage assigned classes, organize learning materials, track grade activities, and post class announcements from one teacher workspace.</p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip"><i class="bi bi-journal-bookmark"></i><?php echo number_format(count($classes)); ?> assigned classes</span>
                        <span class="teacher-chip"><i class="bi bi-file-earmark-text"></i><?php echo number_format(count($materials)); ?> materials</span>
                        <span class="teacher-chip"><i class="bi bi-megaphone"></i><?php echo number_format(count($classAnnouncements)); ?> class announcements</span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Total Students</span>
                            <span class="teacher-summary-value"><?php echo number_format(array_sum(array_map(fn($class) => (int)($class['student_count'] ?? 0), $classes))); ?></span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Active Sections</span>
                            <span class="teacher-summary-value"><?php echo number_format(count($classes)); ?></span>
                        </div>
                    </div>
                    <p class="teacher-summary-note">This page connects your class roster, material uploads, activities, and class communication in one place.</p>
                </div>
            </div>
        </div>
        <div class="teacher-overview-grid mb-4">
            <div class="teacher-overview-card">
                <strong><?php echo number_format(count($classes)); ?></strong>
                <span>Assigned Classes</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format(count($materials)); ?></strong>
                <span>Uploaded Materials</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format(count($classAnnouncements)); ?></strong>
                <span>Recent Announcements</span>
            </div>
        </div>
        <nav class="teacher-workspace-nav mb-4" aria-label="Class workspace sections">
            <a href="#classes-section" class="teacher-workspace-link active">
                <i class="bi bi-journal-bookmark"></i>
                <span>Classes</span>
            </a>
            <a href="#materials-section" class="teacher-workspace-link">
                <i class="bi bi-file-earmark-text"></i>
                <span>Materials</span>
            </a>
            <a href="#activities-section" class="teacher-workspace-link">
                <i class="bi bi-clipboard-data"></i>
                <span>Activities</span>
            </a>
            <a href="#announcements-section" class="teacher-workspace-link">
                <i class="bi bi-megaphone"></i>
                <span>Announcements</span>
            </a>
        </nav>
        <div class="row g-4">
            <div class="col-lg-6" id="classes-section">
                <div class="content-card">
                    <div class="content-card-header">
                        <div>
                            <h5 class="content-card-title mb-1">My Classes</h5>
                            <div class="teacher-section-note">Review schedule, room, and enrolled student count for each assigned subject.</div>
                        </div>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($classes)): ?>
                            <div class="text-center text-muted py-3">No assigned classes yet.</div>
                        <?php else: ?>
                            <?php foreach ($classes as $index => $class): ?>
                                <div class="class-card mb-3">
                                    <div class="class-card-header" style="height: 80px; background: <?php echo $index % 2 === 0 ? 'linear-gradient(135deg, var(--primary-color), var(--secondary-color))' : 'linear-gradient(135deg, var(--accent-color), #059669)'; ?>;">
                                        <div class="class-card-icon" style="width: 45px; height: 45px; font-size: 20px; bottom: -22px;"><i class="bi bi-journal-bookmark"></i></div>
                                    </div>
                                    <div class="class-card-body" style="padding-top: 30px;">
                                        <h4 class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></h4>
                                        <p class="class-card-subtitle">Grade <?php echo (int)$class['grade_level']; ?> - <?php echo htmlspecialchars($class['section']); ?></p>
                                        <p class="text-muted small mb-2"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($formatSchedule($class['schedule'] ?? '')); ?></p>
                                        <?php if (!empty($class['room'])): ?>
                                            <p class="text-muted small mb-2"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($class['room']); ?></p>
                                        <?php endif; ?>
                                            <div class="class-card-stats">
                                                <span class="class-card-stat"><i class="bi bi-people"></i> <?php echo (int)$class['student_count']; ?> Students</span>
                                            </div>
                                            <div class="mt-3">
                                                <button class="btn btn-sm btn-outline-primary" type="button" onclick="openClassStudents(<?php echo (int)$class['id']; ?>, '<?php echo htmlspecialchars($class['class_name'], ENT_QUOTES); ?>')">
                                                    <i class="bi bi-people me-1"></i>View Students
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" id="materials-section">
                <div class="content-card">
                    <div class="content-card-header">
                        <div>
                            <h5 class="content-card-title mb-1">Learning Materials</h5>
                            <div class="teacher-section-note">Upload and maintain files linked to your classes.</div>
                        </div>
                        <button class="btn btn-sm btn-primary-custom" type="button" data-bs-toggle="modal" data-bs-target="#uploadMaterialModal"><i class="bi bi-plus-lg me-1"></i>Upload</button>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($materials)): ?>
                            <div class="text-center text-muted py-3">No uploaded materials yet.</div>
                        <?php else: ?>
                            <?php foreach ($materials as $material): ?>
                                <div class="material-card mb-3">
                                    <div class="material-icon doc"><i class="bi bi-file-earmark-text"></i></div>
                                    <div class="material-info">
                                        <div class="material-title"><?php echo htmlspecialchars($material['title']); ?></div>
                                        <div class="material-meta"><?php echo htmlspecialchars(strtoupper($material['file_type'] ?: 'FILE')); ?><?php if (!empty($material['class_name'])): ?> - <?php echo htmlspecialchars($material['class_name']); ?><?php endif; ?> - <?php echo date('M d, Y', strtotime($material['created_at'])); ?></div>
                                    </div>
                                    <div class="material-actions">
                                        <a class="material-btn" title="Download" href="teacher_Action.php?action=download_material&id=<?php echo (int)$material['id']; ?>"><i class="bi bi-download"></i></a>
                                        <button class="material-btn" title="Edit" onclick="openEditMaterialModal(<?php echo (int)$material['id']; ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="material-btn" title="Delete" onclick='deleteMaterial(<?php echo (int)$material['id']; ?>, <?php echo json_encode($material["title"]); ?>)'><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2" id="activities-section">
            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header">
                        <div>
                            <h5 class="content-card-title mb-1">Grade Activities</h5>
                            <div class="teacher-section-note">Filter by class, review activities, and record scores from the same panel.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <select id="gradeItemClassFilter" class="form-select form-select-sm" style="width: auto;">
                                <option value="">All My Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-secondary-custom" onclick="loadGradeItems()"><i class="bi bi-arrow-clockwise"></i></button>
                            <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createGradeItemModal"><i class="bi bi-plus-lg me-1"></i>New Activity</button>
                        </div>
                    </div>
                    <div id="gradeActivitiesCollapse" class="collapse show">
                        <div class="content-card-body" id="gradeItemsContainer">
                            <div class="text-center text-muted py-3">Loading grade activities...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2" id="announcements-section">
            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header">
                        <div>
                            <h5 class="content-card-title mb-1">Class Announcements</h5>
                            <div class="teacher-section-note">Publish quick updates and filter announcements by class when needed.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <select id="announcementClassFilter" class="form-select form-select-sm" style="width: auto;">
                                <option value="">All My Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-secondary-custom" onclick="loadClassAnnouncements()"><i class="bi bi-arrow-clockwise"></i></button>
                            <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createClassAnnouncementModal">
                                <i class="bi bi-plus-lg me-1"></i>New
                            </button>
                        </div>
                    </div>
                    <div id="classAnnouncementsCollapse" class="collapse show">
                        <div class="content-card-body" id="classAnnouncementsContainer">
                            <?php if (empty($classAnnouncements)): ?>
                                <div class="text-center text-muted py-3">No class announcements yet.</div>
                            <?php else: ?>
                                <?php foreach ($classAnnouncements as $a): ?>
                                    <div class="announcement-card mb-3" data-announcement-id="<?php echo (int)$a['id']; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="announcement-header">
                                                    <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                                    <span class="announcement-badge info">
                                                        <?php echo htmlspecialchars($a['class_name'] . ' (G' . $a['grade_level'] . ' - ' . $a['section'] . ')'); ?>
                                                    </span>
                                                </div>
                                                <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                                                <div class="announcement-meta">
                                                    <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                                                </div>
                                            </div>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteClassAnnouncement(<?php echo (int)$a['id']; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="uploadMaterialModal" tabindex="-1" aria-labelledby="uploadMaterialModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content app-modal-content">
        <form id="uploadMaterialForm" enctype="multipart/form-data" onsubmit="uploadMaterial(event)">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-folder-plus"></i>Learning Materials</div>
                    <h5 class="modal-title mb-0" id="uploadMaterialModalTitle">Upload Material</h5>
                    <p class="app-modal-subtitle">Add a file to one of your classes.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div class="app-modal-stack">
                    <div class="app-modal-panel">
                        <div class="app-modal-panel-title"><i class="bi bi-journal-text"></i>Material Details</div>
                        <div class="mb-3">
                            <label class="form-label" for="materialTitle">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="materialTitle" class="form-control" maxlength="200" required>
                        </div>
                        <div>
                            <label class="form-label" for="materialClassId">Class <span class="text-danger">*</span></label>
                            <select name="class_id" id="materialClassId" class="form-select" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="app-modal-panel">
                        <div class="app-modal-panel-title"><i class="bi bi-file-earmark-arrow-up"></i>File</div>
                        <div class="material-drop-zone" id="materialDropZone" tabindex="0">
                            <i class="bi bi-cloud-arrow-up material-drop-icon" aria-hidden="true"></i>
                            <div class="material-drop-title">Drop or paste a file here</div>
                            <div class="material-drop-copy">Choose File opens the Documents folder on supported browsers.</div>
                            <button class="btn btn-sm btn-primary-custom" type="button" id="chooseMaterialFileBtn"><i class="bi bi-folder2-open me-1"></i>Choose File</button>
                            <label class="visually-hidden" for="materialFileInput">Standard material file picker</label>
                            <input type="file" name="material_file" id="materialFileInput" hidden>
                            <div class="material-file-status text-muted" id="materialFileStatus" role="status" aria-live="polite">PDF, Office document, text, JPG, or PNG up to 10 MB.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer app-modal-footer">
                <button class="btn btn-secondary" type="button" id="clearMaterialUploadBtn"><i class="bi bi-x-lg me-1"></i>Clear</button>
                <button class="btn btn-primary" id="uploadMaterialBtn" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i>Upload File</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="createClassAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-megaphone"></i>Announcements</div>
                <h5 class="modal-title mb-0">Post Class Announcement</h5>
                <p class="app-modal-subtitle">Send a quick update to your class.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body app-modal-body">
            <form id="createClassAnnouncementForm">
                <div class="app-modal-stack">
                    <div class="app-modal-panel">
                        <div class="app-modal-panel-title"><i class="bi bi-broadcast"></i>Announcement Setup</div>
                        <div class="mb-3">
                            <label class="form-label">Class <span class="text-danger">*</span></label>
                            <select name="class_id" class="form-select" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo (int)$class['id']; ?>">
                                        <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea name="content" rows="4" class="form-control" required></textarea>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer app-modal-footer">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="createClassAnnouncementBtn" type="button" onclick="createClassAnnouncement()">Post Announcement</button>
        </div>
    </div></div>
</div>

<div class="modal fade" id="editMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Edit</div>
                <h5 class="modal-title mb-0">Edit Material</h5>
                <p class="app-modal-subtitle">Update the title or class before saving changes.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body app-modal-body">
            <form id="editMaterialForm">
                <input type="hidden" name="material_id" id="editMaterialId">
                <div class="mb-3"><label class="form-label">Title <span class="text-danger">*</span></label><input type="text" name="title" id="editMaterialTitle" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Class <span class="text-danger">*</span></label><select name="class_id" id="editMaterialClassId" class="form-select" required><option value="">Select class</option><?php foreach ($classes as $class): ?><option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?></option><?php endforeach; ?></select></div>
            </form>
        </div>
        <div class="modal-footer app-modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="updateMaterialBtn" type="button" onclick="updateMaterial()">Save Changes</button></div>
    </div></div>
</div>

<div class="modal fade" id="createGradeItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-clipboard-data"></i>Grades</div>
                <h5 class="modal-title mb-0">Create Grade Activity</h5>
                <p class="app-modal-subtitle">Set up a new assessment for your class.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body app-modal-body">
            <form id="createGradeItemForm">
                <div class="mb-3"><label class="form-label">Class <span class="text-danger">*</span></label><select name="class_id" class="form-select" required><option value="">Select class</option><?php foreach ($classes as $class): ?><option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">Activity Title <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" placeholder="e.g. Quiz 1, Performance Task 2" required></div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">Component <span class="text-danger">*</span></label><select name="component" class="form-select" required><option value="WW">Written Work</option><option value="PT">Performance Task</option><option value="ASSESSMENT">Assessment</option></select></div>
                    <div class="col-md-6 mb-3"><label class="form-label">Total Score <span class="text-danger">*</span></label><input type="number" name="total_score" class="form-control" min="0.01" step="0.01" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Activity Date</label><input type="date" name="activity_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
            </form>
        </div>
        <div class="modal-footer app-modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="createGradeItemBtn" type="button" onclick="createGradeItem()">Create</button></div>
    </div></div>
</div>

<div class="modal fade" id="recordScoresModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-clipboard-check"></i>Scores</div>
                <h5 class="modal-title mb-0">Record Scores</h5>
                <p class="app-modal-subtitle">Record student activity scores.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body app-modal-body">
            <div class="mb-1 fw-semibold" id="recordScoresSubject"></div>
            <div class="mb-2 text-muted small" id="recordScoresMeta"></div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="recordScoresTreatZero">
                <label class="form-check-label" for="recordScoresTreatZero">Treat blank scores as 0</label>
            </div>
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead><tr><th>Student</th><th>Score</th></tr></thead>
                    <tbody id="recordScoresBody"><tr><td colspan="2" class="text-center text-muted py-3">Loading...</td></tr></tbody>
                </table>
            </div>
            <input type="hidden" id="recordScoresItemId">
        </div>
        <div class="modal-footer app-modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="saveScoresBtn" type="button" onclick="saveGradeItemScores()">Save Scores</button></div>
    </div></div>
</div>

<div class="modal fade" id="classStudentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content app-modal-content">
        <div class="modal-header app-modal-header">
            <div>
                <div class="app-modal-kicker"><i class="bi bi-people"></i>Students</div>
                <h5 class="modal-title mb-0" id="classStudentsTitle">Class Students</h5>
                <p class="app-modal-subtitle mb-0">Enrolled students in this class.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body app-modal-body">
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="classStudentsSearch" placeholder="Search students by name or reference code...">
                <button class="btn btn-outline-secondary" type="button" id="clearClassStudentsSearchBtn" title="Clear" style="display: none;"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="table-responsive">
                <table class="table custom-table">
                    <thead><tr><th>#</th><th>Student</th><th>Reference</th></tr></thead>
                    <tbody id="classStudentsBody"><tr><td colspan="3" class="text-center text-muted py-3">Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

<div class="modal fade" id="finishGradeItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content app-modal-danger">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-check2-circle"></i>Finish</div>
                    <h5 class="modal-title mb-0">Finish Grade Activity</h5>
                    <p class="app-modal-subtitle">This will move the activity to archive.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <p class="mb-2">Are you sure you want to finish this grade activity?</p>
                <div class="small text-muted" id="finishGradeItemName"></div>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>This will move the activity to archive.
                </div>
            </div>
            <div class="modal-footer app-modal-footer">
                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning" id="confirmFinishGradeItemBtn" type="button" onclick="confirmFinishGradeItem()">
                    <i class="bi bi-check2-circle me-1"></i>Finish Activity
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/offlineStorage.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/networkSync.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
<?php include '../includes/delete_modal.php'; ?>
<script>
let pendingDeleteAction = '';
let pendingFinishGradeItemId = 0;
const uploadMaterialModalEl = document.getElementById('uploadMaterialModal');
const createClassAnnouncementModalEl = document.getElementById('createClassAnnouncementModal');
const editMaterialModalEl = document.getElementById('editMaterialModal');
const createGradeItemModalEl = document.getElementById('createGradeItemModal');
const recordScoresModalEl = document.getElementById('recordScoresModal');
const finishGradeItemModalEl = document.getElementById('finishGradeItemModal');
const uploadMaterialModal = uploadMaterialModalEl ? new bootstrap.Modal(uploadMaterialModalEl) : null;
const createClassAnnouncementModal = createClassAnnouncementModalEl ? new bootstrap.Modal(createClassAnnouncementModalEl) : null;
const editMaterialModal = editMaterialModalEl ? new bootstrap.Modal(editMaterialModalEl) : null;
const createGradeItemModal = createGradeItemModalEl ? new bootstrap.Modal(createGradeItemModalEl) : null;
const recordScoresModal = recordScoresModalEl ? new bootstrap.Modal(recordScoresModalEl) : null;
const finishGradeItemModal = finishGradeItemModalEl ? new bootstrap.Modal(finishGradeItemModalEl) : null;
const classStudentsModalEl = document.getElementById('classStudentsModal');
const classStudentsModal = classStudentsModalEl ? new bootstrap.Modal(classStudentsModalEl) : null;
const materialDropZone = document.getElementById('materialDropZone');
const materialFileInput = document.getElementById('materialFileInput');
const materialFileStatus = document.getElementById('materialFileStatus');
const chooseMaterialFileBtn = document.getElementById('chooseMaterialFileBtn');
const csrfToken = (window.APP_CSRF_TOKEN || '').toString();

function setBusy(btn, busyText, idleText, isBusy) {
    if (!btn) return;
    btn.disabled = !!isBusy;
    btn.textContent = isBusy ? busyText : idleText;
}

function resetUploadMaterialForm(){const form=document.getElementById('uploadMaterialForm');if(form)form.reset();if(materialFileInput)materialFileInput.setCustomValidity('');if(materialDropZone)materialDropZone.classList.remove('is-dragging');if(materialFileStatus){materialFileStatus.textContent='PDF, Office document, text, JPG, or PNG up to 10 MB.';materialFileStatus.className='material-file-status text-muted';}}
function resetCreateAnnouncementModal(){const form=document.getElementById('createClassAnnouncementForm');if(form)form.reset();}
function resetEditMaterialModal(){const form=document.getElementById('editMaterialForm');if(form)form.reset();const idEl=document.getElementById('editMaterialId');if(idEl)idEl.value='';}
function resetCreateGradeItemModal(){const form=document.getElementById('createGradeItemForm');if(form)form.reset();const dateEl=form?form.querySelector('input[name=\"activity_date\"]'):null;if(dateEl)dateEl.value='<?php echo date('Y-m-d'); ?>';}
function resetRecordScoresModal(){const idEl=document.getElementById('recordScoresItemId');const metaEl=document.getElementById('recordScoresMeta');const subjectEl=document.getElementById('recordScoresSubject');const bodyEl=document.getElementById('recordScoresBody');const zeroEl=document.getElementById('recordScoresTreatZero');if(idEl)idEl.value='';if(metaEl)metaEl.textContent='';if(subjectEl)subjectEl.textContent='';if(zeroEl)zeroEl.checked=true;if(bodyEl)bodyEl.innerHTML='<tr><td colspan=\"2\" class=\"text-center text-muted py-3\">Loading...</td></tr>';}
function resetFinishGradeItemModal(){pendingFinishGradeItemId=0;const nameEl=document.getElementById('finishGradeItemName');if(nameEl)nameEl.textContent='';}
function resetClassStudentsModal(){const titleEl=document.getElementById('classStudentsTitle');const searchEl=document.getElementById('classStudentsSearch');const bodyEl=document.getElementById('classStudentsBody');if(titleEl)titleEl.textContent='Class Students';if(searchEl)searchEl.value='';if(bodyEl)bodyEl.innerHTML='<tr><td colspan=\"3\" class=\"text-center text-muted py-3\">Loading...</td></tr>';}
async function fetchMaterialJson(url,options){const response=await fetch(url,options);const text=await response.text();let data=null;try{data=JSON.parse(text);}catch(error){if(response.status===413)throw new Error('The selected file exceeds the server upload limit');throw new Error('The server returned an invalid material response');}if(!response.ok||!data.success)throw new Error(data.message||'Material request failed');return data;}
function validateMaterialFile(){if(!materialFileInput||!materialFileStatus)return false;const file=materialFileInput.files&&materialFileInput.files[0];materialFileInput.setCustomValidity('');if(!file){materialFileStatus.textContent='PDF, Office document, text, JPG, or PNG up to 10 MB.';materialFileStatus.className='material-file-status text-muted';return false;}const extension=(file.name.split('.').pop()||'').toLowerCase();const allowed=['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','jpg','jpeg','png'];let error='';if(!allowed.includes(extension))error='This file type is not supported.';else if(file.size<=0)error='The selected file is empty.';else if(file.size>10*1024*1024)error='The selected file exceeds the 10 MB limit.';if(error){materialFileInput.setCustomValidity(error);materialFileStatus.textContent=error;materialFileStatus.className='material-file-status text-danger';return false;}const size=file.size>=1024*1024?(file.size/(1024*1024)).toFixed(1)+' MB':Math.max(1,Math.round(file.size/1024))+' KB';materialFileStatus.textContent=file.name+' - '+size;materialFileStatus.className='material-file-status text-success';return true;}
function assignMaterialFile(file){if(!file||!materialFileInput)return false;try{const transfer=new DataTransfer();transfer.items.add(file);materialFileInput.files=transfer.files;return validateMaterialFile();}catch(error){materialFileStatus.textContent='This browser cannot attach the selected file. Try the standard picker.';materialFileStatus.className='material-file-status text-danger';return false;}}
async function chooseMaterialFile(){if(!materialFileInput)return;if(typeof window.showOpenFilePicker!=='function'){materialFileInput.click();return;}try{const handles=await window.showOpenFilePicker({startIn:'documents',multiple:false,excludeAcceptAllOption:false,types:[{description:'Learning materials',accept:{'application/pdf':['.pdf'],'application/msword':['.doc'],'application/vnd.openxmlformats-officedocument.wordprocessingml.document':['.docx'],'application/vnd.ms-powerpoint':['.ppt'],'application/vnd.openxmlformats-officedocument.presentationml.presentation':['.pptx'],'application/vnd.ms-excel':['.xls'],'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':['.xlsx'],'text/plain':['.txt'],'image/jpeg':['.jpg','.jpeg'],'image/png':['.png']}}]});const file=await handles[0]?.getFile();if(file)assignMaterialFile(file);}catch(error){if(error?.name==='AbortError')return;materialFileStatus.textContent='The Documents picker could not open. Drop or paste the file here instead.';materialFileStatus.className='material-file-status text-danger';}}

if(materialFileInput)materialFileInput.addEventListener('change',validateMaterialFile);
if(chooseMaterialFileBtn)chooseMaterialFileBtn.addEventListener('click',chooseMaterialFile);
document.getElementById('clearMaterialUploadBtn')?.addEventListener('click',resetUploadMaterialForm);
if(materialDropZone){['dragenter','dragover'].forEach(type=>materialDropZone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();materialDropZone.classList.add('is-dragging');}));['dragleave','drop'].forEach(type=>materialDropZone.addEventListener(type,event=>{event.preventDefault();event.stopPropagation();materialDropZone.classList.remove('is-dragging');}));materialDropZone.addEventListener('drop',event=>assignMaterialFile(event.dataTransfer?.files?.[0]));materialDropZone.addEventListener('paste',event=>{const file=Array.from(event.clipboardData?.files||[])[0]||Array.from(event.clipboardData?.items||[]).find(item=>item.kind==='file')?.getAsFile();if(file){event.preventDefault();assignMaterialFile(file);}});}
if(uploadMaterialModalEl){uploadMaterialModalEl.addEventListener('show.bs.modal',resetUploadMaterialForm);uploadMaterialModalEl.addEventListener('shown.bs.modal',()=>document.getElementById('materialTitle')?.focus({preventScroll:true}));uploadMaterialModalEl.addEventListener('hidden.bs.modal',resetUploadMaterialForm);}
if(createClassAnnouncementModalEl){createClassAnnouncementModalEl.addEventListener('show.bs.modal',resetCreateAnnouncementModal);createClassAnnouncementModalEl.addEventListener('hidden.bs.modal',resetCreateAnnouncementModal);}
if(editMaterialModalEl){editMaterialModalEl.addEventListener('hidden.bs.modal',resetEditMaterialModal);}
if(createGradeItemModalEl){createGradeItemModalEl.addEventListener('show.bs.modal',resetCreateGradeItemModal);createGradeItemModalEl.addEventListener('hidden.bs.modal',resetCreateGradeItemModal);}
if(recordScoresModalEl){recordScoresModalEl.addEventListener('hidden.bs.modal',resetRecordScoresModal);}
if(finishGradeItemModalEl){finishGradeItemModalEl.addEventListener('hidden.bs.modal',resetFinishGradeItemModal);}
if(classStudentsModalEl){classStudentsModalEl.addEventListener('hidden.bs.modal',resetClassStudentsModal);}
function uploadMaterial(event){if(event)event.preventDefault();const form=document.getElementById('uploadMaterialForm');if(!form||!form.reportValidity()||!validateMaterialFile())return;const fd=new FormData(form);const uploadBtn=document.getElementById('uploadMaterialBtn');appendCsrfToFormData(fd);setBusy(uploadBtn,'Uploading...','Upload File',true);fetchMaterialJson('teacher_Action.php?action=upload_material',{method:'POST',body:fd}).then(d=>{showNotification(d.message||'Material uploaded successfully','success');if(uploadMaterialModal)uploadMaterialModal.hide();setTimeout(()=>location.reload(),700);}).catch(error=>showNotification(error.message||'Error uploading material','danger')).finally(()=>setBusy(uploadBtn,'Uploading...','Upload File',false));}
function openEditMaterialModal(materialId){resetEditMaterialModal();fetch('teacher_Action.php?action=get_material&id='+encodeURIComponent(materialId)).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load material','danger');return;}const m=d.material;document.getElementById('editMaterialId').value=m.id;document.getElementById('editMaterialTitle').value=m.title||'';document.getElementById('editMaterialClassId').value=m.class_id||'';if(editMaterialModal)editMaterialModal.show();}).catch(()=>showNotification('Error loading material data','danger'));}
function updateMaterial(){const form=document.getElementById('editMaterialForm');const fd=new FormData(form);const id=(fd.get('material_id')||'').toString().trim();const title=(fd.get('title')||'').toString().trim();const classId=(fd.get('class_id')||'').toString().trim();const updateBtn=document.getElementById('updateMaterialBtn');if(!id||!title||!classId){showNotification('Please complete all fields','warning');return;}appendCsrfToFormData(fd);setBusy(updateBtn,'Saving...','Save Changes',true);fetchMaterialJson('teacher_Action.php?action=update_material',{method:'POST',body:fd}).then(d=>{showNotification(d.message||'Material updated successfully','success');if(editMaterialModal)editMaterialModal.hide();setTimeout(()=>location.reload(),700);}).catch(error=>showNotification(error.message||'Error updating material','danger')).finally(()=>setBusy(updateBtn,'Saving...','Save Changes',false));}
function deleteMaterial(materialId,title){pendingDeleteAction='material';showDeleteModal(materialId,title,'material');}
function createClassAnnouncement(){const form=document.getElementById('createClassAnnouncementForm');const fd=new FormData(form);const classId=(fd.get('class_id')||'').toString().trim();const title=(fd.get('title')||'').toString().trim();const content=(fd.get('content')||'').toString().trim();const createBtn=document.getElementById('createClassAnnouncementBtn');if(!classId||!title||!content){showNotification('Please complete all fields','warning');return;}appendCsrfToFormData(fd);setBusy(createBtn,'Posting...','Post Announcement',true);fetch('teacher_Action.php?action=create_class_announcement',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Announcement posted','success');if(createClassAnnouncementModal)createClassAnnouncementModal.hide();loadClassAnnouncements();}else{showNotification(d.message||'Failed to post announcement','danger');}}).catch(()=>showNotification('Error posting announcement','danger')).finally(()=>setBusy(createBtn,'Posting...','Post Announcement',false));}
function loadClassAnnouncements(){const classId=document.getElementById('announcementClassFilter')?.value||'';const url='teacher_Action.php?action=fetch_class_announcements'+(classId?('&class_id='+encodeURIComponent(classId)):'');fetch(url).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load announcements','danger');return;}renderClassAnnouncements(d.announcements||[]);}).catch(()=>showNotification('Error loading announcements','danger'));}
function renderClassAnnouncements(items){const container=document.getElementById('classAnnouncementsContainer');if(!container)return;if(!items||items.length===0){container.innerHTML='<div class="text-center text-muted py-3">No class announcements yet.</div>';return;}container.innerHTML=items.map(a=>`<div class="announcement-card mb-3" data-announcement-id="${a.id}"><div class="d-flex justify-content-between align-items-start"><div class="flex-grow-1"><div class="announcement-header"><h6 class="announcement-title">${escapeHtml(a.title)}</h6><span class="announcement-badge info">${escapeHtml(`${a.class_name} (G${a.grade_level} - ${a.section})`)}</span></div><p class="announcement-content">${escapeHtml(a.content)}</p><div class="announcement-meta"><span><i class="bi bi-clock"></i> ${new Date(a.created_at).toLocaleString()}</span></div></div><button class="btn btn-sm btn-outline-danger" onclick="deleteClassAnnouncement(${a.id})"><i class="bi bi-trash"></i></button></div></div>`).join('');}
function deleteClassAnnouncement(id){pendingDeleteAction='announcement';showDeleteModal(id,'Class Announcement','announcement');}
let classStudentsCache = [];
function openClassStudents(classId, className){resetClassStudentsModal();const titleEl=document.getElementById('classStudentsTitle');if(titleEl){titleEl.textContent=className?`${className} - Students`:'Class Students';}if(classStudentsModal)classStudentsModal.show();fetch('teacher_Action.php?action=fetch_class_students&class_id='+encodeURIComponent(classId)).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load students','danger');const body=document.getElementById('classStudentsBody');if(body)body.innerHTML='<tr><td colspan=\"3\" class=\"text-center text-muted py-3\">No students found.</td></tr>';return;}classStudentsCache=d.students||[];renderClassStudents(classStudentsCache);}).catch(()=>{showNotification('Error loading students','danger');const body=document.getElementById('classStudentsBody');if(body)body.innerHTML='<tr><td colspan=\"3\" class=\"text-center text-muted py-3\">Failed to load students.</td></tr>';});}
function renderClassStudents(students){const body=document.getElementById('classStudentsBody');if(!body)return;if(!students||students.length===0){body.innerHTML='<tr><td colspan=\"3\" class=\"text-center text-muted py-3\">No students found.</td></tr>';return;}body.innerHTML=students.map((s,i)=>`<tr><td>${i+1}</td><td>${escapeHtml(`${s.last_name}, ${s.first_name}`)}</td><td>${escapeHtml(s.reference_code||'')}</td></tr>`).join('');}
const classStudentsSearchEl=document.getElementById('classStudentsSearch');
const clearClassStudentsSearchBtn=document.getElementById('clearClassStudentsSearchBtn');
if(classStudentsSearchEl){
    classStudentsSearchEl.addEventListener('input',()=>{
        const q=classStudentsSearchEl.value.trim().toLowerCase();
        if(clearClassStudentsSearchBtn) clearClassStudentsSearchBtn.style.display = q ? 'block' : 'none';
        if(!q){renderClassStudents(classStudentsCache);return;}
        const filtered=classStudentsCache.filter(s=>`${s.last_name} ${s.first_name} ${s.reference_code||''}`.toLowerCase().includes(q));
        renderClassStudents(filtered);
    });
}
if(clearClassStudentsSearchBtn){
    clearClassStudentsSearchBtn.addEventListener('click', ()=>{
        if(classStudentsSearchEl){
            classStudentsSearchEl.value = '';
            classStudentsSearchEl.focus();
            if(clearClassStudentsSearchBtn) clearClassStudentsSearchBtn.style.display = 'none';
            renderClassStudents(classStudentsCache);
        }
    });
}
function createGradeItem(){
    const form=document.getElementById('createGradeItemForm');
    const fd=new FormData(form);
    const classId=(fd.get('class_id')||'').toString().trim();
    const title=(fd.get('title')||'').toString().trim();
    const totalScore=parseFloat((fd.get('total_score')||'').toString());
    const component=(fd.get('component')||'WW').toString().trim();
    const actDate=(fd.get('activity_date')||new Date().toISOString().slice(0,10)).toString().trim();
    const createBtn=document.getElementById('createGradeItemBtn');
    if(!classId||!title||!Number.isFinite(totalScore)||totalScore<=0){
        showNotification('Please complete all required fields','warning');
        return;
    }

    if(!navigator.onLine){
        if(window.bshsOfflineStorage){
            const localRec = {
                local_id: 'act_' + classId + '_' + Date.now(),
                class_id: parseInt(classId, 10),
                title: title,
                component: component,
                total_score: totalScore,
                activity_date: actDate,
                scores: [],
                saved_at: new Date().toISOString(),
                sync_status: 'pending'
            };
            window.bshsOfflineStorage.saveActivityLocally(localRec).then(()=>{
                showNotification('Grade activity created locally (Offline)', 'success');
                if(createGradeItemModal) createGradeItemModal.hide();
                loadGradeItems();
            });
        }
        return;
    }

    setBusy(createBtn,'Creating...','Create',true);
    fetch('teacher_Action.php?action=create_grade_item',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Grade activity created','success');if(createGradeItemModal)createGradeItemModal.hide();loadGradeItems();}else{showNotification(d.message||'Failed to create grade activity','danger');}}).catch(()=>showNotification('Error creating grade activity','danger')).finally(()=>setBusy(createBtn,'Creating...','Create',false));
}
function loadGradeItems(){
    const classId=document.getElementById('gradeItemClassFilter')?.value||'';
    if(!navigator.onLine){
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getAllLocalActivities().then(items => {
                const filtered = classId ? items.filter(i => String(i.class_id) === String(classId)) : items;
                renderGradeItems(filtered.map(i => ({
                    id: i.local_id || i.id,
                    title: i.title,
                    class_name: 'Class ' + i.class_id,
                    grade_level: '',
                    section: '',
                    component: i.component,
                    total_score: i.total_score,
                    activity_date: i.activity_date,
                    score_count: (i.scores || []).length
                })));
            });
        }
        return;
    }
    const url='teacher_Action.php?action=fetch_grade_items&status=active'+(classId?('&class_id='+encodeURIComponent(classId)):'');
    fetch(url).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load grade activities','danger');return;}renderGradeItems(d.items||[]);}).catch(()=>{
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getAllLocalActivities().then(items => {
                const filtered = classId ? items.filter(i => String(i.class_id) === String(classId)) : items;
                renderGradeItems(filtered.map(i => ({
                    id: i.local_id || i.id,
                    title: i.title,
                    class_name: 'Class ' + i.class_id,
                    grade_level: '',
                    section: '',
                    component: i.component,
                    total_score: i.total_score,
                    activity_date: i.activity_date,
                    score_count: (i.scores || []).length
                })));
            });
        }
    });
}
function renderGradeItems(items){const container=document.getElementById('gradeItemsContainer');if(!container)return;if(!items||items.length===0){container.innerHTML='<div class="text-center text-muted py-3">No active grade activities yet.</div>';return;}container.innerHTML=items.map(i=>`<div class="announcement-card mb-3"><div class="d-flex justify-content-between align-items-start"><div class="flex-grow-1"><div class="announcement-header"><h6 class="announcement-title">${escapeHtml(i.title)}</h6><span class="announcement-badge info">${escapeHtml(`${i.class_name} (G${i.grade_level} - ${i.section})`)}</span></div><p class="announcement-content mb-2"><strong>Component:</strong> ${escapeHtml(i.component)} | <strong>Total Score:</strong> ${escapeHtml(i.total_score)} | <strong>Date:</strong> ${escapeHtml(i.activity_date)}</p><div class="announcement-meta"><span><i class="bi bi-pencil-square"></i> ${escapeHtml(i.score_count)} score record(s)</span></div></div><div class="d-flex gap-2"><button class="btn btn-sm btn-secondary-custom" onclick="openRecordScores('${i.id}')"><i class="bi bi-list-check me-1"></i>Record</button><button class="btn btn-sm btn-outline-warning" onclick="finishGradeItem('${i.id}', '${escapeHtml(i.title)}')"><i class="bi bi-check2-circle me-1"></i>Finish</button><button class="btn btn-sm btn-outline-danger" onclick="deleteGradeItem('${i.id}', '${escapeHtml(i.title)}')"><i class="bi bi-trash me-1"></i>Delete</button></div></div></div>`).join('');}
function openRecordScores(id){
    resetRecordScoresModal();
    if(!navigator.onLine || String(id).startsWith('act_')){
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getAllLocalActivities().then(items => {
                const act = items.find(i => String(i.local_id || i.id) === String(id));
                if(act){
                    document.getElementById('recordScoresItemId').value = act.local_id || act.id;
                    const metaParts = [act.title, act.component, 'Total: ' + act.total_score, 'Date: ' + act.activity_date];
                    document.getElementById('recordScoresMeta').textContent = metaParts.join(' | ');
                    window.bshsOfflineStorage.getClassRoster(act.class_id).then(students => {
                        const body = document.getElementById('recordScoresBody');
                        const total = parseFloat(act.total_score || 0);
                        const scoreMap = {};
                        (act.scores || []).forEach(s => { scoreMap[s.student_id] = s.score; });
                        body.innerHTML = (students || []).map(s => `<tr data-student-id="${s.id}"><td><div class="fw-semibold">${escapeHtml(`${s.first_name} ${s.last_name}`)}</div><div class="small text-muted">${escapeHtml(s.reference_code||'')}</div></td><td><input type="number" class="form-control form-control-sm item-score" min="0" max="${total}" step="0.01" value="${scoreMap[s.id] ?? ''}" placeholder="0 - ${total}"></td></tr>`).join('');
                        if(recordScoresModal) recordScoresModal.show();
                    });
                }
            });
        }
        return;
    }
    fetch('teacher_Action.php?action=fetch_grade_item_students&grade_item_id='+encodeURIComponent(id)).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load scores form','danger');return;}document.getElementById('recordScoresItemId').value=id;const subjectName=d.item.class_name?`${d.item.class_name}${(d.item.grade_level&&d.item.section)?` (G${d.item.grade_level} - ${d.item.section})`:''}`:'';const subjectEl=document.getElementById('recordScoresSubject');if(subjectEl){subjectEl.textContent=subjectName?`Subject: ${subjectName}`:'';}const metaParts=[];if(d.item.title)metaParts.push(d.item.title);if(d.item.component)metaParts.push(d.item.component);metaParts.push(`Total: ${d.item.total_score}`);metaParts.push(`Date: ${d.item.activity_date}`);document.getElementById('recordScoresMeta').textContent=metaParts.join(' | ');const body=document.getElementById('recordScoresBody');const total=parseFloat(d.item.total_score||0);body.innerHTML=(d.students||[]).map(s=>`<tr data-student-id="${s.id}"><td><div class="fw-semibold">${escapeHtml(`${s.first_name} ${s.last_name}`)}</div><div class="small text-muted">${escapeHtml(s.reference_code||'')}</div></td><td><input type="number" class="form-control form-control-sm item-score" min="0" max="${total}" step="0.01" value="${s.score??''}" placeholder="0 - ${total}"></td></tr>`).join('');if(recordScoresModal)recordScoresModal.show();}).catch(()=>showNotification('Error loading scores form','danger'));
}
function saveGradeItemScores(){
    const saveBtn=document.getElementById('saveScoresBtn');
    const gradeItemId=document.getElementById('recordScoresItemId')?.value||'';
    if(!gradeItemId){showNotification('Invalid grade activity','warning');return;}
    const rows=document.querySelectorAll('#recordScoresBody tr[data-student-id]');
    const treatZero=!!document.getElementById('recordScoresTreatZero')?.checked;
    const scores=Array.from(rows).map(r=>{let val=r.querySelector('.item-score')?.value??'';if(treatZero&&(val===''||val===null))val='0';return {student_id:parseInt(r.dataset.studentId,10),score:val};});

    if(!navigator.onLine || String(gradeItemId).startsWith('act_')){
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getAllLocalActivities().then(items => {
                const act = items.find(i => String(i.local_id || i.id) === String(gradeItemId));
                if(act){
                    act.scores = scores;
                    act.saved_at = new Date().toISOString();
                    act.sync_status = 'pending';
                    window.bshsOfflineStorage.saveActivityLocally(act).then(()=>{
                        showNotification('Scores saved locally (Offline)', 'success');
                        if(recordScoresModal) recordScoresModal.hide();
                        loadGradeItems();
                    });
                }
            });
        }
        return;
    }

    setBusy(saveBtn,'Saving...','Save Scores',true);
    fetch(withCsrfUrl('teacher_Action.php?action=save_grade_item_scores'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({grade_item_id:parseInt(gradeItemId,10),scores:scores})}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Scores saved successfully. Finish the activity to include it in Grade Entry.','success');if(recordScoresModal)recordScoresModal.hide();loadGradeItems();}else{showNotification(d.message||'Failed to save scores','danger');}}).catch(()=>showNotification('Error saving scores','danger')).finally(()=>setBusy(saveBtn,'Saving...','Save Scores',false));
}
function finishGradeItem(id,title){pendingFinishGradeItemId=parseInt(id,10)||0;const nameEl=document.getElementById('finishGradeItemName');if(nameEl)nameEl.textContent=title?`Activity: ${title}`:'Activity: Grade Activity';if(!pendingFinishGradeItemId){showNotification('Invalid grade activity','warning');return;}if(finishGradeItemModal)finishGradeItemModal.show();}
function confirmFinishGradeItem(){if(!pendingFinishGradeItemId){showNotification('Invalid grade activity','warning');return;}const btn=document.getElementById('confirmFinishGradeItemBtn');setBusy(btn,'Finishing...','Finish Activity',true);const fd=new FormData();fd.append('grade_item_id',pendingFinishGradeItemId);appendCsrfToFormData(fd);fetch('teacher_Action.php?action=finish_grade_item',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Grade activity finished and moved to archive','success');if(finishGradeItemModal)finishGradeItemModal.hide();loadGradeItems();}else{showNotification(d.message||'Failed to finish grade activity','danger');}}).catch(()=>showNotification('Error finishing grade activity','danger')).finally(()=>setBusy(btn,'Finishing...','Finish Activity',false));}
function deleteGradeItem(id,title){pendingDeleteAction='grade_activity';showDeleteModal(id,title,'grade activity');}
function handleDelete(id){
    if(pendingDeleteAction==='material'){
        fetch(withCsrfUrl('teacher_Action.php?action=delete_material&id='+encodeURIComponent(id))).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Material deleted successfully','success');setTimeout(()=>location.reload(),700);}else{showNotification(d.message||'Failed to delete material','danger');}}).catch(()=>showNotification('Error deleting material','danger'));
        return;
    }
    if(pendingDeleteAction==='announcement'){
        fetch(withCsrfUrl('teacher_Action.php?action=delete_class_announcement&id='+encodeURIComponent(id))).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Announcement deleted','success');loadClassAnnouncements();}else{showNotification(d.message||'Failed to delete announcement','danger');}}).catch(()=>showNotification('Error deleting announcement','danger'));
        return;
    }
    if(pendingDeleteAction==='grade_activity'){
        const fd=new FormData();
        fd.append('grade_item_id',id);
        appendCsrfToFormData(fd);
        fetch('teacher_Action.php?action=delete_grade_item',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Activity deleted','success');loadGradeItems();}else{showNotification(d.message||'Failed to delete activity','danger');}}).catch(()=>showNotification('Error deleting activity','danger'));
        return;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.bshsOfflineStorage && navigator.onLine) {
        window.bshsOfflineStorage.saveTeacherSession({
            teacher_id: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
            role: 'teacher',
            first_name: <?php echo json_encode($_SESSION['first_name'] ?? ''); ?>,
            last_name: <?php echo json_encode($_SESSION['last_name'] ?? ''); ?>,
            email: <?php echo json_encode($_SESSION['email'] ?? ''); ?>,
            reference_code: <?php echo json_encode($_SESSION['reference_code'] ?? ''); ?>
        });
        setTimeout(function () {
            if (typeof window.bshsOfflineStorage.bootstrapOnline === 'function') {
                window.bshsOfflineStorage.bootstrapOnline();
            }
        }, 500);
    }


    if (!navigator.onLine && window.bshsOfflineStorage) {
        window.bshsOfflineStorage.getClasses().then(cachedClasses => {
            if (cachedClasses && cachedClasses.length > 0) {
                const sel = document.getElementById('activityClassId');
                if (sel && sel.options.length <= 1) {
                    sel.innerHTML = '<option value="">Select Subject Class</option>' + cachedClasses.map(c => {
                        const label = (c.name || 'Class') + (c.subject_title ? ' - ' + c.subject_title : '');
                        return `<option value="${c.id}">${escapeHtml(label)}</option>`;
                    }).join('');
                }
            }
        });
    }

    loadGradeItems();
});
document.addEventListener('DOMContentLoaded',function(){
    const links=Array.from(document.querySelectorAll('.teacher-workspace-link'));
    const sections=links.map(link=>document.querySelector(link.getAttribute('href'))).filter(Boolean);
    const setActive=function(id){links.forEach(link=>link.classList.toggle('active',link.getAttribute('href')==='#'+id));};
    links.forEach(function(link){
        link.addEventListener('click',function(event){
            const target=document.querySelector(link.getAttribute('href'));
            if(!target)return;
            event.preventDefault();
            target.scrollIntoView({behavior:'smooth',block:'start'});
            setActive(target.id);
            history.replaceState(null,'','#'+target.id);
        });
    });
    if('IntersectionObserver' in window&&sections.length){
        const observer=new IntersectionObserver(function(entries){
            const visible=entries.filter(entry=>entry.isIntersecting).sort((a,b)=>b.intersectionRatio-a.intersectionRatio)[0];
            if(visible&&visible.target&&visible.target.id)setActive(visible.target.id);
        },{rootMargin:'-120px 0px -55% 0px',threshold:[0.1,0.35,0.6]});
        sections.forEach(section=>observer.observe(section));
    }
});
</script>
</body>
</html>
