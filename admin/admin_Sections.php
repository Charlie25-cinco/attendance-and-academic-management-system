<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$current_role = 'admin';
$current_page = 'sections';
$page_title = 'Sections';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Sections</h4>
                    <p class="text-muted mb-0">Manage Grade 11 Strengthened SHS sections by program while keeping Grade 12 unchanged.</p>
                </div>
                <button class="btn btn-primary-custom" onclick="openCreateModal()">
                    <i class="bi bi-plus-lg me-1"></i>Create Section
                </button>
            </div>

            <div class="row g-4" id="sectionsContainer">
                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-body text-center py-5">
                            <div class="spinner-border text-primary mb-3" role="status"></div>
                            <p class="text-muted mb-0">Loading sections...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Section Modal -->
    <div class="modal fade" id="createSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-plus-circle"></i>Section</div>
                        <h5 class="modal-title mb-0">Create Section</h5>
                        <p class="app-modal-subtitle">Add a new section for a grade level and track.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="createSectionForm">
                        <div class="mb-3">
                            <label class="form-label" for="createSectionName">Section Name <span class="text-danger">*</span></label>
                            <input type="text" id="createSectionName" class="form-control" placeholder="e.g. Diamond, STEM-A" oninput="capitalizeWords(this)" required autocomplete="off">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="createSectionGrade">Grade Level <span class="text-danger">*</span></label>
                                <select id="createSectionGrade" class="form-select" required>
                                    <option value="">Select Grade</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="createSectionTrack">Track <span class="text-danger">*</span></label>
                                <select id="createSectionTrack" class="form-select" required>
                                    <option value="">Select Track</option>
                                    <option value="academic">Academic Track (Strengthened)</option>
                                    <option value="techpro">Technical Professional</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createSection()">Create</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Section</div>
                        <h5 class="modal-title mb-0">Edit Section</h5>
                        <p class="app-modal-subtitle">Update section details.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="editSectionForm">
                        <input type="hidden" id="editSectionId">
                        <div class="mb-3">
                            <label class="form-label" for="editSectionName">Section Name <span class="text-danger">*</span></label>
                            <input type="text" id="editSectionName" class="form-control" oninput="capitalizeWords(this)" required autocomplete="off">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="editSectionGrade">Grade Level <span class="text-danger">*</span></label>
                                <select id="editSectionGrade" class="form-select" required>
                                    <option value="">Select Grade</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="editSectionTrack">Track <span class="text-danger">*</span></label>
                                <select id="editSectionTrack" class="form-select" required>
                                    <option value="">Select Track</option>
                                    <option value="academic">Academic Track (Strengthened)</option>
                                    <option value="techpro">Technical Professional</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateSection()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSectionModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-exclamation-triangle"></i>Confirm</div>
                        <h5 class="modal-title mb-0">Delete Section</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <p id="deleteSectionConfirmText">Are you sure?</p>
                    <input type="hidden" id="deleteSectionId">
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="deleteSection()">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();

        function capitalizeWords(input) {
            if (!input || !input.value) return;
            input.value = input.value.replace(/\b\w/g, char => char.toUpperCase());
        }

        document.addEventListener('DOMContentLoaded', loadSections);

        function loadSections() {
            fetch('admin_Sections_Action.php?action=list')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('sectionsContainer');
                    if (!data.success) {
                        container.innerHTML = `<div class="col-12"><div class="content-card"><div class="content-card-body text-center py-5"><p class="text-muted mb-0">Failed to load sections.</p></div></div></div>`;
                        return;
                    }
                    const sections = data.sections || [];
                    if (sections.length === 0) {
                        container.innerHTML = `<div class="col-12"><div class="content-card"><div class="content-card-body text-center py-5"><p class="text-muted mb-0">No sections yet. Click "Create Section" to add one.</p></div></div></div>`;
                        return;
                    }
                    const gradients = [
                        'linear-gradient(135deg, var(--primary-color), var(--secondary-color))',
                        'linear-gradient(135deg, var(--accent-color), #059669)',
                        'linear-gradient(135deg, var(--warning-color), #d97706)',
                        'linear-gradient(135deg, var(--danger-color), #dc2626)',
                        'linear-gradient(135deg, #0ea5e9, #0284c7)',
                        'linear-gradient(135deg, #14b8a6, #0f766e)'
                    ];
                    container.innerHTML = sections.map((s, i) => {
                        const g = gradients[i % gradients.length];
                        const trackLabel = programLabel(s);
                        return `<div class="col-md-6 col-lg-4">
                            <div class="class-card">
                                <div class="class-card-header" style="background: ${g}">
                                    <div class="class-card-icon"><i class="bi bi-diagram-3"></i></div>
                                </div>
                                <div class="class-card-body">
                                    <h4 class="class-card-title">Grade ${s.grade_level} — ${escHtml(s.name)}</h4>
                                    <p class="text-muted small mb-3"><span class="track-badge track-${s.track}">${trackLabel}</span></p>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editSection(${s.id}, '${escHtml(s.name)}', ${s.grade_level}, '${s.track}')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${s.id}, '${escHtml(s.name)}')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>`;
                    }).join('');
                })
                .catch(err => {
                    document.getElementById('sectionsContainer').innerHTML = `<div class="col-12"><div class="content-card"><div class="content-card-body text-center py-5"><p class="text-muted mb-0">Error loading sections.</p></div></div></div>`;
                    console.error(err);
                });
        }

        function openCreateModal() {
            document.getElementById('createSectionForm').reset();
            new bootstrap.Modal(document.getElementById('createSectionModal')).show();
        }

        function createSection() {
            const name = document.getElementById('createSectionName').value.trim();
            const gradeLevel = document.getElementById('createSectionGrade').value;
            const track = document.getElementById('createSectionTrack').value;

            if (!name) { showNotification('Section name is required.', 'warning'); return; }
            if (!gradeLevel) { showNotification('Please select grade level.', 'warning'); return; }
            if (!track) { showNotification('Please select track.', 'warning'); return; }

            const params = new URLSearchParams();
            params.set('action', 'create');
            params.set('name', name);
            params.set('grade_level', gradeLevel);
            params.set('track', track);
            if (csrfToken) params.set('csrf_token', csrfToken);

            fetch('admin_Sections_Action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('createSectionModal')).hide();
                    loadSections();
                } else {
                    showNotification(data.message, 'danger');
                }
            })
            .catch(err => {
                showNotification('Error creating section.', 'danger');
                console.error(err);
            });
        }

        function editSection(id, name, gradeLevel, track) {
            document.getElementById('editSectionId').value = id;
            document.getElementById('editSectionName').value = name;
            document.getElementById('editSectionGrade').value = String(gradeLevel);
            document.getElementById('editSectionTrack').value = track;
            new bootstrap.Modal(document.getElementById('editSectionModal')).show();
        }

        function updateSection() {
            const id = document.getElementById('editSectionId').value;
            const name = document.getElementById('editSectionName').value.trim();
            const gradeLevel = document.getElementById('editSectionGrade').value;
            const track = document.getElementById('editSectionTrack').value;

            if (!name) { showNotification('Section name is required.', 'warning'); return; }
            if (!gradeLevel) { showNotification('Please select grade level.', 'warning'); return; }
            if (!track) { showNotification('Please select track.', 'warning'); return; }

            const params = new URLSearchParams();
            params.set('action', 'update');
            params.set('id', id);
            params.set('name', name);
            params.set('grade_level', gradeLevel);
            params.set('track', track);
            if (csrfToken) params.set('csrf_token', csrfToken);

            fetch('admin_Sections_Action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editSectionModal')).hide();
                    loadSections();
                } else {
                    showNotification(data.message, 'danger');
                }
            })
            .catch(err => {
                showNotification('Error updating section.', 'danger');
                console.error(err);
            });
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteSectionId').value = id;
            document.getElementById('deleteSectionConfirmText').textContent = `Delete section "${name}"? This cannot be undone.`;
            new bootstrap.Modal(document.getElementById('deleteSectionModal')).show();
        }

        function deleteSection() {
            const id = document.getElementById('deleteSectionId').value;

            const params = new URLSearchParams();
            params.set('action', 'delete');
            params.set('id', id);
            if (csrfToken) params.set('csrf_token', csrfToken);

            fetch('admin_Sections_Action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('deleteSectionModal')).hide();
                    loadSections();
                } else {
                    showNotification(data.message, 'danger');
                }
            })
            .catch(err => {
                showNotification('Error deleting section.', 'danger');
                console.error(err);
            });
        }

    </script>
<?php include '../includes/footer.php'; ?>
