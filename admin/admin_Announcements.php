<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Announcements Page
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$announcements = [];

$categoryFilter = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? 'active';
$search = trim($_GET['search'] ?? '');

if ($db) {
    $where = [];
    $params = [];

    if ($categoryFilter !== '') {
        $where[] = 'a.category = :category';
        $params[':category'] = $categoryFilter;
    }

    if ($statusFilter !== '') {
        $where[] = 'a.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($search !== '') {
        $where[] = '(a.title LIKE :search OR a.content LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $query = "SELECT a.id, a.title, a.content, a.category, a.status, a.views, a.created_at, a.updated_at,
              CONCAT(u.first_name, ' ', u.last_name) as posted_by_name
              FROM announcements a
              LEFT JOIN users u ON a.posted_by = u.id
              $whereClause
              ORDER BY a.created_at DESC";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_role = 'admin';
$current_page = 'announcements';
$page_title = 'Announcements';
$visibleAnnouncementCount = count($announcements);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
    <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <section class="admin-hero admin-hero-compact mb-4">
                <div class="admin-hero-grid">
                    <div class="admin-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-megaphone"></i><span>Admin Announcements</span></div>
                        <h4 class="mb-2">Manage school-wide communication from one workspace.</h4>
                        <p class="text-muted mb-3">Create new notices, filter existing posts, and keep urgent, event, and academic announcements organized for the whole school.</p>
                        <div class="admin-hero-metrics">
                            <div class="admin-hero-metric">
                                <span>Visible Posts</span>
                                <strong><?php echo number_format($visibleAnnouncementCount); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Current Status</span>
                                <strong><?php echo htmlspecialchars($statusFilter !== '' ? ucfirst($statusFilter) : 'All'); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Current Category</span>
                                <strong><?php echo htmlspecialchars($categoryFilter !== '' ? ucfirst($categoryFilter) : 'All'); ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="admin-hero-side">
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Quick action</span>
                            <strong>Publish an update</strong>
                            <small>Use the create button to send a new school-wide announcement immediately.</small>
                        </div>
                        <div class="admin-action-strip">
                            <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                                <i class="bi bi-plus-lg me-2"></i>New Announcement
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">All Announcements</h5>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 mb-4 admin-report-filter-form">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Search title/content..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <option value="general" <?php echo $categoryFilter === 'general' ? 'selected' : ''; ?>>General</option>
                                <option value="urgent" <?php echo $categoryFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="event" <?php echo $categoryFilter === 'event' ? 'selected' : ''; ?>>Event</option>
                                <option value="academic" <?php echo $categoryFilter === 'academic' ? 'selected' : ''; ?>>Academic</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary-custom w-100" type="submit"><i class="bi bi-funnel me-2"></i>Filter</button>
                        </div>
                    </form>

                    <?php if (empty($announcements)): ?>
                        <div class="text-center text-muted py-4">No announcements found.</div>
                    <?php endif; ?>

                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-card <?php echo htmlspecialchars($announcement['category']); ?> mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="announcement-header">
                                        <h6 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h6>
                                        <span class="announcement-badge <?php echo htmlspecialchars($announcement['category']); ?>">
                                            <?php echo ucfirst($announcement['category']); ?>
                                        </span>
                                    </div>
                                    <p class="announcement-content"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    <div class="announcement-meta">
                                        <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($announcement['posted_by_name'] ?: 'Unknown'); ?></span>
                                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($announcement['created_at'])); ?></span>
                                        <span><i class="bi bi-eye"></i> <?php echo (int)$announcement['views']; ?> views</span>
                                        <span class="status-badge status-<?php echo htmlspecialchars($announcement['status']); ?>"><?php echo ucfirst($announcement['status']); ?></span>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-link" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="#" onclick="editAnnouncement(<?php echo (int)$announcement['id']; ?>); return false;">
                                                <i class="bi bi-pencil me-2"></i>Edit
                                            </a>
                                        </li>
                                        <?php if ($announcement['status'] === 'active'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="archiveAnnouncement(<?php echo (int)$announcement['id']; ?>); return false;">
                                                    <i class="bi bi-archive me-2"></i>Archive
                                                </a>
                                            </li>
                                        <?php else: ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="restoreAnnouncement(<?php echo (int)$announcement['id']; ?>); return false;">
                                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restore
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="#" onclick='deleteAnnouncement(<?php echo (int)$announcement['id']; ?>, <?php echo json_encode($announcement["title"]); ?>); return false;'>
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-megaphone"></i>Announcements</div>
                        <h5 class="modal-title mb-0">Create Announcement</h5>
                        <p class="app-modal-subtitle">Share school-wide updates with the community.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="createAnnouncementForm">
                        <div class="app-modal-stack">
                            <div class="app-modal-note">
                                <i class="bi bi-info-circle-fill"></i>
                                <div>
                                    <strong>Publishing tip</strong>
                                    <p>Use a clear title and short first sentence so urgent posts remain easy to scan from the dashboard and public website.</p>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-pencil-square"></i>Announcement Details</div>
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Title <span class="text-danger">*</span></label>
                                        <input type="text" name="title" class="form-control" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Category</label>
                                        <select name="category" class="form-select">
                                            <option value="general">General</option>
                                            <option value="urgent">Urgent</option>
                                            <option value="event">Event</option>
                                            <option value="academic">Academic</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Content <span class="text-danger">*</span></label>
                                    <textarea name="content" rows="5" class="form-control" required></textarea>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-globe2"></i>Publishing Options</div>
                                <p class="app-modal-panel-copy">Choose whether this post stays inside the system or is also shown on the public school website.</p>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="show_on_website" id="createShowOnWebsite" value="1">
                                    <label class="form-check-label" for="createShowOnWebsite">
                                        <i class="bi bi-globe me-1"></i>Post to School Website
                                    </label>
                                    <div class="form-text">This announcement will appear on the public school website.</div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="button" id="createAnnouncementBtn" onclick="createAnnouncement()">Post</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Edit</div>
                        <h5 class="modal-title mb-0">Edit Announcement</h5>
                        <p class="app-modal-subtitle">Update the message before republishing.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="editAnnouncementForm">
                        <input type="hidden" name="id" id="editAnnouncementId">
                        <div class="app-modal-stack">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-pencil"></i>Edit Message</div>
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Title <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="editAnnouncementTitle" class="form-control" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Category</label>
                                        <select name="category" id="editAnnouncementCategory" class="form-select">
                                            <option value="general">General</option>
                                            <option value="urgent">Urgent</option>
                                            <option value="event">Event</option>
                                            <option value="academic">Academic</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Content <span class="text-danger">*</span></label>
                                    <textarea name="content" id="editAnnouncementContent" rows="5" class="form-control" required></textarea>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-broadcast"></i>Visibility</div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="editShowOnWebsite" value="1">
                                    <label class="form-check-label" for="editShowOnWebsite">
                                        <i class="bi bi-globe me-1"></i>Post to School Website
                                    </label>
                                    <div class="form-text">This announcement will appear on the public school website.</div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="button" id="updateAnnouncementBtn" onclick="updateAnnouncement()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/delete_modal.php'; ?>

    <script>
        const createAnnouncementModalEl = document.getElementById('createAnnouncementModal');
        const editAnnouncementModalEl = document.getElementById('editAnnouncementModal');
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
        let createAnnouncementModal = null;
        let editAnnouncementModal = null;

        function resetCreateAnnouncementModal() {
            const form = document.getElementById('createAnnouncementForm');
            if (form) form.reset();
        }

        function resetEditAnnouncementModal() {
            const form = document.getElementById('editAnnouncementForm');
            if (form) form.reset();
            const idField = document.getElementById('editAnnouncementId');
            if (idField) idField.value = '';
        }

        function initializeAnnouncementModals() {
            if (createAnnouncementModalEl) {
                createAnnouncementModal = bootstrap.Modal.getOrCreateInstance(createAnnouncementModalEl);
                createAnnouncementModalEl.addEventListener('show.bs.modal', resetCreateAnnouncementModal);
                createAnnouncementModalEl.addEventListener('hidden.bs.modal', resetCreateAnnouncementModal);
            }
            if (editAnnouncementModalEl) {
                editAnnouncementModal = bootstrap.Modal.getOrCreateInstance(editAnnouncementModalEl);
                editAnnouncementModalEl.addEventListener('hidden.bs.modal', resetEditAnnouncementModal);
            }
        }

        document.addEventListener('DOMContentLoaded', initializeAnnouncementModals);

        function createAnnouncement() {
            const form = document.getElementById('createAnnouncementForm');
            const formData = new FormData(form);
            const title = (formData.get('title') || '').toString().trim();
            const content = (formData.get('content') || '').toString().trim();
            const createBtn = document.getElementById('createAnnouncementBtn');

            if (!title || !content) {
                showNotification('Title and content are required', 'warning');
                return;
            }

            formData.set('title', title);
            formData.set('content', content);
            appendCsrfToFormData(formData);
            if (createBtn) {
                createBtn.disabled = true;
                createBtn.textContent = 'Posting...';
            }

            fetch('admin_Announcements_Action.php?action=create', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    createAnnouncementModal.hide();
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to create announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error creating announcement', 'danger'))
            .finally(() => {
                if (createBtn) {
                    createBtn.disabled = false;
                    createBtn.textContent = 'Post';
                }
            });
        }

        function editAnnouncement(id) {
            resetEditAnnouncementModal();
            fetch('admin_Announcements_Action.php?action=get&id=' + id)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    showNotification(data.message || 'Failed to load announcement', 'danger');
                    return;
                }

                const a = data.announcement;
                document.getElementById('editAnnouncementId').value = a.id;
                document.getElementById('editAnnouncementTitle').value = a.title;
                document.getElementById('editAnnouncementCategory').value = a.category;
                document.getElementById('editAnnouncementContent').value = a.content;
                const websiteChk = document.getElementById('editShowOnWebsite');
                if (websiteChk) websiteChk.checked = parseInt(a.show_on_website || 0) === 1;
                editAnnouncementModal.show();
            })
            .catch(() => showNotification('Error loading announcement', 'danger'));
        }

        function updateAnnouncement() {
            const form = document.getElementById('editAnnouncementForm');
            const formData = new FormData(form);
            const id = (formData.get('id') || '').toString().trim();
            const title = (formData.get('title') || '').toString().trim();
            const content = (formData.get('content') || '').toString().trim();
            const updateBtn = document.getElementById('updateAnnouncementBtn');

            if (!id || !title || !content) {
                showNotification('Title and content are required', 'warning');
                return;
            }

            formData.set('title', title);
            formData.set('content', content);
            const websiteChk = document.getElementById('editShowOnWebsite');
            formData.set('show_on_website', (websiteChk && websiteChk.checked) ? '1' : '0');
            appendCsrfToFormData(formData);
            if (updateBtn) {
                updateBtn.disabled = true;
                updateBtn.textContent = 'Saving...';
            }

            fetch('admin_Announcements_Action.php?action=update', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    editAnnouncementModal.hide();
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to update announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error updating announcement', 'danger'))
            .finally(() => {
                if (updateBtn) {
                    updateBtn.disabled = false;
                    updateBtn.textContent = 'Save Changes';
                }
            });
        }

        function archiveAnnouncement(id) {
            fetch(withCsrfUrl('admin_Announcements_Action.php?action=archive&id=' + id))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to archive announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error archiving announcement', 'danger'));
        }

        function restoreAnnouncement(id) {
            fetch(withCsrfUrl('admin_Announcements_Action.php?action=restore&id=' + id))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to restore announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error restoring announcement', 'danger'));
        }

        function deleteAnnouncement(id, title) {
            showDeleteModal(id, title, 'announcement');
        }

        function handleDelete(id) {
            fetch(withCsrfUrl('admin_Announcements_Action.php?action=delete&id=' + id))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to delete announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error deleting announcement', 'danger'));
        }
    </script>
<?php include '../includes/footer.php'; ?>






