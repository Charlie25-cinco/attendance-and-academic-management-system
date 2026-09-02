<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$statsStmt = $db->query("SELECT
    SUM(CASE WHEN role = 'student' AND status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN role = 'student' AND status = 'inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN role = 'student' AND status = 'pending' THEN 1 ELSE 0 END) as pending,
    COUNT(*) as total
FROM users WHERE role = 'student'");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$sectionsStmt = $db->query("SELECT DISTINCT section FROM users WHERE role = 'student' AND section IS NOT NULL AND section <> '' ORDER BY section");
$sections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);

$current_role = 'admin';
$current_page = 'enrollments';
$page_title = 'Enrollments';

// Handle download of CSV template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="SF1_Student_Import_Template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['LRN','Last Name','First Name','Middle Name','Sex (Male/Female)','Grade Level (11 or 12)','Section','Track/Program','Date of Birth (YYYY-MM-DD)','Address','Contact Number (Parent)','Parent/Guardian Name']);
    fputcsv($out, ['123456789012','Dela Cruz','Juan','Reyes','Male','11','HUMILITY','academic','2007-05-15','Balingasag, MisOr','09XXXXXXXXX','Maria Dela Cruz']);
    fputcsv($out, ['123456789013','Santos','Maria','Lopez','Female','12','PATIENCE','techpro','2006-08-22','Balingasag, MisOr','09XXXXXXXXX','Jose Santos']);
    fclose($out);
    exit();
}
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

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(59,130,246,0.1);">
                            <i class="bi bi-people" style="color: #3b82f6;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['total'] ?? 0); ?></h3>
                            <p>Total Students</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(16,185,129,0.1);">
                            <i class="bi bi-person-check" style="color: #10b981;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['active'] ?? 0); ?></h3>
                            <p>Active</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(239,68,68,0.1);">
                            <i class="bi bi-person-x" style="color: #ef4444;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['inactive'] ?? 0); ?></h3>
                            <p>Archived</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(245,158,11,0.1);">
                            <i class="bi bi-person-up" style="color: #f59e0b;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['pending'] ?? 0); ?></h3>
                            <p>Pending</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form id="filterForm" class="row g-2 align-items-end app-responsive-filter-form" onsubmit="return loadStudents()" data-skip-loader="true">
                        <div class="col-md-3">
                            <label class="form-label small" for="filterSearch">Search</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" id="filterSearch" class="form-control form-control-sm" placeholder="Name, LRN, or Code" autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="clearFilterSearchBtn" style="display:none;" title="Clear"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterGradeLevel">Grade Level</label>
                            <select name="grade_level" id="filterGradeLevel" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Grades</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterSection">Section</label>
                            <select name="section" id="filterSection" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterTrack">Track</label>
                            <select name="track" id="filterTrack" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Tracks</option>
                                <option value="academic">Academic</option>
                                <option value="techpro">TechPro</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterStatus">Status</label>
                            <select name="status" id="filterStatus" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Archived</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary-custom btn-sm w-100"><i class="bi bi-funnel"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Students Table -->
            <div class="content-card">
                    <div class="content-card-header">
                    <h5 class="content-card-title">Enrolled Students</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" onclick="openImportModal()">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import SF1
                        </button>
                        <button class="btn btn-primary-custom btn-sm" onclick="openAddModal()">
                            <i class="bi bi-person-plus me-1"></i>Add Student
                        </button>
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>LRN</th>
                                    <th>Name</th>
                                    <th>Grade</th>
                                    <th>Section</th>
                                    <th>Program</th>
                                    <th>Enrolled Classes</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted small" id="paginationInfo"></div>
                        <nav><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/modals/enrollment_modals.php'; ?>

    <script>
        function ucfirst(str) { return (str || '').charAt(0).toUpperCase() + (str || '').slice(1); }

        let currentPage = 1;
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
        let sectionsCache = {};
        let studentsRequestController = null;
        let studentsRequestSeq = 0;

        document.addEventListener('DOMContentLoaded', () => {
            loadStudents();
            loadSectionsCache();

            let searchDebounceTimer = null;
            const searchEl = document.getElementById('filterSearch');
            const clearSearchBtn = document.getElementById('clearFilterSearchBtn');
            if (searchEl) {
                searchEl.addEventListener('input', () => {
                    const val = searchEl.value.trim();
                    if (clearSearchBtn) clearSearchBtn.style.display = val ? 'block' : 'none';
                    clearTimeout(searchDebounceTimer);
                    searchDebounceTimer = setTimeout(() => loadStudents(1), 300);
                });
            }
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', () => {
                    if (searchEl) {
                        searchEl.value = '';
                        searchEl.focus();
                        clearSearchBtn.style.display = 'none';
                        loadStudents(1);
                    }
                });
            }
        });

        function loadStudents(page) {
            if (page) currentPage = page;
            if (!page) currentPage = 1;
            const form = document.getElementById('filterForm');
            const fd = new FormData(form);
            const params = new URLSearchParams(fd);
            params.set('action', 'list');
            if (currentPage) params.set('page', currentPage);

            const requestSeq = ++studentsRequestSeq;
            if (studentsRequestController) {
                studentsRequestController.abort();
            }
            studentsRequestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const requestTimeout = setTimeout(() => {
                if (studentsRequestController) {
                    studentsRequestController.abort();
                }
            }, 15000);
            document.getElementById('studentsTableBody').innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>';

            const fetchOptions = studentsRequestController ? { signal: studentsRequestController.signal } : {};
            fetch('admin_Enrollments_Action.php?' + params.toString(), fetchOptions)
                .then(async r => {
                    if (!r.ok) {
                        const text = await r.text();
                        console.error('AJAX status:', r.status, 'body:', text.substring(0, 500));
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(data => {
                    clearTimeout(requestTimeout);
                    if (requestSeq !== studentsRequestSeq) {
                        return;
                    }
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load students', 'danger');
                        return;
                    }
                    renderTable(data.students || []);
                    renderPagination(data);
                    document.getElementById('paginationInfo').textContent =
                        data.students.length > 0
                            ? `Showing ${((data.page - 1) * 15) + 1} to ${Math.min(data.page * 15, data.total)} of ${data.total} students`
                            : 'No students found';
                })
                .catch(err => {
                    clearTimeout(requestTimeout);
                    if (err.name === 'AbortError') {
                        if (requestSeq === studentsRequestSeq) {
                            document.getElementById('studentsTableBody').innerHTML =
                                '<tr><td colspan="8" class="text-center text-muted py-4">The filter took too long. Please try again.</td></tr>';
                            document.getElementById('paginationInfo').textContent = '';
                        }
                        return;
                    }
                    console.error(err);
                    document.getElementById('studentsTableBody').innerHTML =
                        '<tr><td colspan="8" class="text-center text-muted py-4">Error loading students.</td></tr>';
                    document.getElementById('paginationInfo').textContent = '';
                    showNotification('Error loading students', 'danger');
                });
            return false;
        }

        function renderTable(students) {
            const tbody = document.getElementById('studentsTableBody');
            if (!students.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No students found</td></tr>';
                return;
            }
            tbody.innerHTML = students.map(s => `
                <tr>
                    <td><span class="text-muted small">${escHtml(s.lrn || '—')}</span></td>
                    <td>
                        <div class="user-list-item" style="padding:0;border:none;">
                            <div class="user-avatar-small" style="background:var(--warning-color);width:32px;height:32px;font-size:12px;">
                                ${((s.first_name || '').charAt(0)) + ((s.last_name || '').charAt(0))}
                            </div>
                            <div class="user-list-info">
                                <h5>${escHtml(s.first_name + ' ' + s.last_name)}</h5>
                                <p>${escHtml(s.reference_code || '')}</p>
                            </div>
                        </div>
                    </td>
                    <td>${s.grade_level ? 'Grade ' + s.grade_level : '—'}</td>
                    <td>${escHtml(s.section || '—')}</td>
                    <td>${programLabel(s) ? `<span class="track-badge track-${s.track || 'academic'}">${escHtml(programLabel(s))}</span>` : '—'}</td>
                    <td>${s.enrolled_classes ? `<span class="badge bg-info text-dark">${escHtml(s.enrolled_classes)}</span>` : '<span class="text-muted">None</span>'}</td>
                    <td><span class="status-badge status-${s.status}">${ucfirst(s.status)}</span></td>
                    <td>
                        <div class="d-flex gap-2">
                            <button class="material-btn js-view-student" data-id="${s.id}" title="View" style="color:var(--secondary-color);"><i class="bi bi-eye"></i></button>
                            <button class="material-btn js-edit-student" data-id="${s.id}" title="Edit" style="color:var(--warning-color);"><i class="bi bi-pencil"></i></button>
                            <button class="material-btn js-toggle-student-status" data-id="${s.id}" data-status="${s.status}" title="${s.status === 'active' ? 'Archive' : 'Activate'}" style="color:${s.status === 'active' ? 'var(--warning-color)' : 'var(--accent-color)'};">
                                <i class="bi bi-${s.status === 'active' ? 'toggle-off' : 'toggle-on'}"></i>
                            </button>
                            <button class="material-btn js-delete-student" data-id="${s.id}" title="Delete" style="color:var(--danger-color);"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `).join('');

            // Bind events
            tbody.querySelectorAll('.js-view-student').forEach(btn => {
                btn.addEventListener('click', () => viewStudent(parseInt(btn.dataset.id)));
            });
            tbody.querySelectorAll('.js-edit-student').forEach(btn => {
                btn.addEventListener('click', () => editStudent(parseInt(btn.dataset.id)));
            });
            tbody.querySelectorAll('.js-toggle-student-status').forEach(btn => {
                btn.addEventListener('click', () => toggleStudentStatus(parseInt(btn.dataset.id), btn.dataset.status));
            });
            tbody.querySelectorAll('.js-delete-student').forEach(btn => {
                btn.addEventListener('click', () => deleteStudent(parseInt(btn.dataset.id)));
            });
        }

        function renderPagination(data) {
            const ul = document.getElementById('pagination');
            if (data.total_pages <= 1) { ul.innerHTML = ''; return; }
            let html = '';
            for (let i = 1; i <= data.total_pages; i++) {
                html += `<li class="page-item${i === data.page ? ' active' : ''}">
                    <button class="page-link" onclick="loadStudents(${i})">${i}</button>
                </li>`;
            }
            ul.innerHTML = html;
        }

        function ucfirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }


        function capitalizeWords(input) {
            input.value = input.value.replace(/\b\w/g, c => c.toUpperCase());
        }

        // Section dropdown helpers
        function loadSectionsCache() {
            return fetch('admin_Sections_Action.php?action=list')
                .then(r => r.json())
                .then(data => {
                    sectionsCache = {};
                    if (data.success && data.sections) {
                        data.sections.forEach(s => {
                            const key = s.grade_level + '_' + s.track;
                            if (!sectionsCache[key]) sectionsCache[key] = [];
                            const suffix = s.program === 'academic_strengthened' ? ' - Academic Track (Strengthened)' : (s.program === 'technical_professional' ? ' - Technical Professional' : '');
                            sectionsCache[key].push({ value: s.name, label: s.name + suffix, program: s.program || '' });
                        });
                    }
                    return sectionsCache;
                })
                .catch(() => { sectionsCache = {}; });
        }

        function getSectionsForGrade(grade) {
            if (!grade) return [];
            const all = [];
            Object.keys(sectionsCache).forEach(key => {
                if (key.startsWith(String(grade) + '_')) {
                    all.push(...sectionsCache[key]);
                }
            });
            return all;
        }

        function deriveTrackFromSection(grade, sectionValue) {
            if (!grade || !sectionValue) return 'academic';
            for (const key in sectionsCache) {
                if (key.startsWith(String(grade) + '_')) {
                    if (sectionsCache[key].some(s => s.value === sectionValue)) {
                        return key.split('_')[1] || 'academic';
                    }
                }
            }
            for (const key in sectionsCache) {
                if (sectionsCache[key].some(s => s.value === sectionValue)) {
                    return key.split('_')[1] || 'academic';
                }
            }
            return 'academic';
        }

        function updateAddSections() {
            const grade = document.getElementById('addGradeLevel').value;
            const sel = document.getElementById('addSection');
            const list = getSectionsForGrade(grade);
            sel.innerHTML = '<option value="">' + (list.length ? 'Select Section' : 'No sections available') + '</option>';
            sel.disabled = !list.length;
            if (!grade) {
                sel.innerHTML = '<option value="">Select Grade First</option>';
                sel.disabled = true;
            }
            list.forEach(s => {
                sel.innerHTML += '<option value="' + escHtml(s.value) + '">' + escHtml(s.label) + '</option>';
            });
        }

        function updateEditSections() {
            const grade = document.getElementById('editGradeLevel').value;
            const sel = document.getElementById('editSection');
            const prevVal = sel.value;
            const list = getSectionsForGrade(grade);
            sel.innerHTML = '<option value="">' + (list.length ? 'Select Section' : 'No sections available') + '</option>';
            sel.disabled = !list.length;
            if (!grade) {
                sel.innerHTML = '<option value="">Select Grade First</option>';
                sel.disabled = true;
            }
            list.forEach(s => {
                sel.innerHTML += '<option value="' + escHtml(s.value) + '">' + escHtml(s.label) + '</option>';
            });
            if (prevVal) sel.value = prevVal;
        }

        // CRUD
        function openAddModal() {
            document.getElementById('addStudentForm').reset();
            document.getElementById('addSection').innerHTML = '<option value="">Select Grade First</option>';
            document.getElementById('addSection').disabled = true;
            loadSectionsCache().then(() => {
                const grade = document.getElementById('addGradeLevel').value;
                if (grade) updateAddSections();
            });
            const modalEl = document.getElementById('addStudentModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }

        function createStudent() {
            const form = document.getElementById('addStudentForm');
            const fd = new FormData(form);
            const lrn = (fd.get('lrn') || '').trim();
            const gradeLevel = fd.get('grade_level');
            const section = fd.get('section');

            if (!fd.get('first_name') || !fd.get('last_name')) {
                showNotification('First and last name are required', 'warning'); return;
            }
            if (!fd.get('sex')) {
                showNotification('Please select sex', 'warning'); return;
            }
            if (!lrn) {
                showNotification('LRN is required', 'warning'); return;
            }
            if (!/^\d{12}$/.test(lrn)) {
                showNotification('LRN must be exactly 12 digits', 'warning'); return;
            }
            if (!gradeLevel) {
                showNotification('Please select grade level', 'warning'); return;
            }
            if (!section) {
                showNotification('Please select section', 'warning'); return;
            }

            // Auto-derive track from selected section with safe fallback
            const derivedTrack = deriveTrackFromSection(gradeLevel, section) || 'academic';
            fd.set('track', derivedTrack);

            const btn = document.getElementById('createStudentBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';
            }

            appendCsrfToFormData(fd);
            fetch('admin_Enrollments_Action.php?action=create', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Student created', 'success');
                    const modalEl = document.getElementById('addStudentModal');
                    if (modalEl) {
                        const inst = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                        if (inst) inst.hide();
                    }
                    loadStudents(1);
                } else {
                    showNotification(data.message || 'Failed to create student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error creating student', 'danger');
                console.error(err);
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Create & Enroll';
                }
            });
        }

        function editStudent(id) {
            fetch('admin_Enrollments_Action.php?action=get&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load student', 'danger');
                        return;
                    }
                    const s = data.student;
                    document.getElementById('editUserId').value = s.id;
                    const refCodeInput = document.getElementById('editReferenceCode');
                    if (refCodeInput) {
                        refCodeInput.value = s.reference_code || '';
                    }
                    document.getElementById('editFirstName').value = s.first_name || '';
                    document.getElementById('editMiddleName').value = s.middle_name || '';
                    document.getElementById('editLastName').value = s.last_name || '';
                    document.getElementById('editSex').value = (s.sex || '').toLowerCase();
                    document.getElementById('editLrn').value = s.lrn || '';
                    document.getElementById('editGradeLevel').value = s.grade_level || '';
                    updateEditSections();
                    if (s.section) {
                        document.getElementById('editSection').value = s.section;
                    }
                    const modalEl = document.getElementById('editStudentModal');
                    const modal = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                })
                .catch(err => {
                    showNotification('Error loading student', 'danger');
                    console.error(err);
                });
        }

        function updateStudent() {
            const form = document.getElementById('editStudentForm');
            const fd = new FormData(form);
            const lrn = (fd.get('lrn') || '').trim();
            const gradeLevel = fd.get('grade_level');
            const section = fd.get('section');

            if (!fd.get('first_name') || !fd.get('last_name')) {
                showNotification('First and last name are required', 'warning'); return;
            }
            if (!fd.get('sex')) {
                showNotification('Please select sex', 'warning'); return;
            }
            if (!lrn || !/^\d{12}$/.test(lrn)) {
                showNotification('LRN must be exactly 12 digits', 'warning'); return;
            }
            if (!gradeLevel || !section) {
                showNotification('Grade level and section are required', 'warning'); return;
            }

            // Auto-derive track from selected section with safe fallback
            const derivedTrack = deriveTrackFromSection(gradeLevel, section) || 'academic';
            fd.set('track', derivedTrack);

            const btn = document.getElementById('updateStudentBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
            }

            appendCsrfToFormData(fd);
            fetch('admin_Enrollments_Action.php?action=update', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification('Student updated', 'success');
                    const modalEl = document.getElementById('editStudentModal');
                    if (modalEl) {
                        const inst = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                        if (inst) inst.hide();
                    }
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to update student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error updating student', 'danger');
                console.error(err);
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'Save Changes';
                }
            });
        }

        function viewStudent(id) {
            fetch('admin_Enrollments_Action.php?action=get&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load student', 'danger');
                        return;
                    }
                    const s = data.student;
                    const fullName = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
                    const initials = ((s.first_name || 'U')[0] + (s.last_name || 'N')[0]).toUpperCase();

                    document.getElementById('viewStudentAvatar').textContent = initials;
                    document.getElementById('viewFullName').textContent = fullName;
                    document.getElementById('viewLrn').textContent = s.lrn || 'N/A';
                    document.getElementById('viewRefCode').textContent = s.reference_code || 'N/A';
                    document.getElementById('viewEmail').textContent = s.email || 'N/A';
                    document.getElementById('viewSex').textContent = s.sex ? ucfirst(s.sex) : 'N/A';
                    document.getElementById('viewGradeLevel').textContent = s.grade_level ? 'Grade ' + s.grade_level : 'N/A';
                    document.getElementById('viewSection').textContent = s.section || 'N/A';
                    document.getElementById('viewTrack').textContent = programLabel(s) || 'N/A';

                    const statusEl = document.getElementById('viewStatus');
                    statusEl.textContent = ucfirst(s.status);
                    statusEl.className = 'status-badge status-' + s.status;

                    const classesEl = document.getElementById('viewEnrolledClasses');
                    if (s.enrolled_classes && s.enrolled_classes.length) {
                        classesEl.innerHTML = s.enrolled_classes.map(ec =>
                            `<span class="badge bg-info text-dark me-1 mb-1 d-inline-block">${escHtml(ec.class_name)}${ec.enrollment_status !== 'enrolled' ? ' (' + ucfirst(ec.enrollment_status) + ')' : ''}</span>`
                        ).join('');
                    } else {
                        classesEl.innerHTML = '<span class="text-muted">Not enrolled in any class</span>';
                    }

                    new bootstrap.Modal(document.getElementById('viewStudentModal')).show();
                })
                .catch(err => {
                    showNotification('Error loading student', 'danger');
                    console.error(err);
                });
        }

        function toggleStudentStatus(id, currentStatus) {
            const nextStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const label = nextStatus === 'active' ? 'activate' : 'archive';
            const runToggleStudentStatus = function() {
                const params = new URLSearchParams();
                params.set('user_id', String(id));
                params.set('status', nextStatus);
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Enrollments_Action.php?action=toggle_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Status updated', 'success');
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to update status', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error updating status', 'danger');
                console.error(err);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runToggleStudentStatus();
                return;
            }
            showAppConfirm({
                title: `${label.charAt(0).toUpperCase() + label.slice(1)} this student?`,
                subtitle: 'This updates the selected student account status.',
                message: `You are about to <strong>${label}</strong> this student account.`,
                confirmText: label === 'activate' ? 'Activate student' : 'Archive student',
                cancelText: 'Cancel',
                tone: nextStatus === 'active' ? 'primary' : 'danger',
                icon: nextStatus === 'active' ? 'bi-person-check-fill' : 'bi-person-dash-fill'
            }).then((confirmed) => {
                if (confirmed) runToggleStudentStatus();
            });
        }

        function deleteStudent(id) {
            const runArchiveStudent = function() {
                const params = new URLSearchParams();
                params.set('id', String(id));
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Enrollments_Action.php?action=delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Student archived', 'success');
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to archive student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error archiving student', 'danger');
                console.error(err);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runArchiveStudent();
                return;
            }
            showAppConfirm({
                title: 'Archive this student?',
                subtitle: 'The student record will be moved out of the active list.',
                message: 'You can restore the student later from the appropriate admin workflow if needed.',
                confirmText: 'Archive student',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'bi-archive-fill'
            }).then((confirmed) => {
                if (confirmed) runArchiveStudent();
            });
        }

        // -------------------------------------------------------------
        // SF1 TWO-STAGE IMPORT & EDITABLE PREVIEW JAVASCRIPT
        // -------------------------------------------------------------
        let previewState = {
            students: [],
            header: {},
            fileName: ''
        };

        function openImportModal() {
            const form = document.getElementById('importForm');
            if (form) form.reset();

            previewState.students = [];
            previewState.header = {};
            previewState.fileName = '';

            document.getElementById('importUploadStage').style.display = '';
            document.getElementById('importPreviewStage').style.display = 'none';
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';

            document.getElementById('uploadStageButtons').style.setProperty('display', 'flex', 'important');
            document.getElementById('previewStageButtons').style.setProperty('display', 'none', 'important');
            document.getElementById('resultsStageButtons').style.setProperty('display', 'none', 'important');

            const previewBtn = document.getElementById('previewBtn');
            if (previewBtn) {
                previewBtn.disabled = false;
                previewBtn.innerHTML = '<i class="bi bi-eye me-1"></i>Preview & Verify Data';
            }

            document.getElementById('importModalSubtitle').textContent = 'Upload, review, edit, and verify official DepEd SF1 learner data before committing to the system.';
            new bootstrap.Modal(document.getElementById('importModal')).show();
        }

        function backToUploadStage() {
            document.getElementById('importUploadStage').style.display = '';
            document.getElementById('importPreviewStage').style.display = 'none';
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';

            document.getElementById('uploadStageButtons').style.setProperty('display', 'flex', 'important');
            document.getElementById('previewStageButtons').style.setProperty('display', 'none', 'important');
            document.getElementById('resultsStageButtons').style.setProperty('display', 'none', 'important');
            document.getElementById('importModalSubtitle').textContent = 'Upload, review, edit, and verify official DepEd SF1 learner data before committing to the system.';
        }

        function previewImport() {
            const fileInput = document.getElementById('sf1File');
            const file = fileInput.files[0];
            if (!file) {
                showNotification('Please select an SF1 file to preview.', 'warning');
                return;
            }
            const ext = file.name.toLowerCase().split('.').pop();
            if (!['csv', 'xlsx'].includes(ext)) {
                showNotification('Only CSV and XLSX files are supported.', 'warning');
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                showNotification('File too large. Max 10MB.', 'warning');
                return;
            }

            const btn = document.getElementById('previewBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Reading & Parsing SF1...';

            const formData = new FormData(document.getElementById('importForm'));
            formData.set('action', 'preview');

            fetch('admin_SF1_Import_Action.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showNotification(data.message || 'Failed to parse SF1 file.', 'danger');
                        return;
                    }

                    previewState.students = data.students || [];
                    previewState.header = data.header || {};
                    previewState.fileName = data.file_name || file.name;

                    if (previewState.students.length === 0) {
                        showNotification('No student records found in the uploaded file.', 'warning');
                        return;
                    }

                    // Populate metadata controls
                    document.getElementById('previewFileName').textContent = previewState.fileName;
                    document.getElementById('previewGradeLevel').value = previewState.header.grade_level || '11';
                    document.getElementById('previewSection').value = previewState.header.section || '';
                    document.getElementById('previewTrack').value = previewState.header.track || 'academic';
                    document.getElementById('previewSchoolYear').value = previewState.header.school_year || document.getElementById('importAcademicYear').value;

                    // Switch view to Stage 2
                    document.getElementById('importUploadStage').style.display = 'none';
                    document.getElementById('importPreviewStage').style.display = '';
                    document.getElementById('uploadStageButtons').style.setProperty('display', 'none', 'important');
                    document.getElementById('previewStageButtons').style.setProperty('display', 'flex', 'important');
                    document.getElementById('importModalSubtitle').textContent = 'Review and edit learner details directly below. Click Confirm & Import when ready.';

                    renderPreviewTable();
                    recalculateStats();
                })
                .catch(err => {
                    showNotification('Error processing SF1 file preview.', 'danger');
                    console.error(err);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-eye me-1"></i>Preview & Verify Data';
                });
        }

        function renderPreviewTable() {
            const tbody = document.getElementById('previewTableBody');
            if (!tbody) return;

            const filter = (document.getElementById('previewSearchFilter')?.value || '').toLowerCase().trim();

            let html = '';
            previewState.students.forEach((s, idx) => {
                const fullName = `${s.first_name} ${s.middle_name} ${s.last_name} ${s.name_extension}`.toLowerCase();
                const lrn = (s.lrn || '').toLowerCase();
                if (filter && !fullName.includes(filter) && !lrn.includes(filter)) {
                    return;
                }

                const isValidLrn = s.lrn && s.lrn.length === 12 && /^\d+$/.test(s.lrn);
                let statusBadge = '<span class="badge bg-success">New</span>';
                if (s.is_existing) {
                    statusBadge = '<span class="badge bg-warning text-dark" title="LRN matches existing student: ' + escHtml(s.existing_name || '') + '">In DB</span>';
                } else if (!isValidLrn) {
                    statusBadge = '<span class="badge bg-danger" title="LRN must be 12 digits">Invalid LRN</span>';
                }

                html += `<tr data-index="${idx}" class="${s.is_existing ? 'table-warning-subtle' : ''}">
                    <td class="text-center fw-bold text-muted">${idx + 1}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <input type="text" class="form-control form-control-sm font-monospace ${!isValidLrn ? 'is-invalid' : ''}" 
                            value="${escHtml(s.lrn || '')}" maxlength="12" 
                            oninput="updateStudentField(${idx}, 'lrn', this.value)" placeholder="12-digit LRN">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.last_name || '')}" 
                            oninput="updateStudentField(${idx}, 'last_name', this.value)" placeholder="Last Name">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.first_name || '')}" 
                            oninput="updateStudentField(${idx}, 'first_name', this.value)" placeholder="First Name">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.middle_name || '')}" 
                            oninput="updateStudentField(${idx}, 'middle_name', this.value)" placeholder="Middle Name">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" style="width: 55px;" 
                            value="${escHtml(s.name_extension || '')}" 
                            oninput="updateStudentField(${idx}, 'name_extension', this.value)" placeholder="Jr/III">
                    </td>
                    <td>
                        <select class="form-select form-select-sm" onchange="updateStudentField(${idx}, 'sex', this.value)">
                            <option value="male" ${s.sex === 'male' ? 'selected' : ''}>Male</option>
                            <option value="female" ${s.sex === 'female' ? 'selected' : ''}>Female</option>
                        </select>
                    </td>
                    <td>
                        <input type="date" class="form-control form-control-sm" 
                            value="${escHtml(s.birthdate || '')}" 
                            onchange="updateStudentField(${idx}, 'birthdate', this.value)">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.house_street || '')}" 
                            oninput="updateStudentField(${idx}, 'house_street', this.value)" placeholder="Street">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.barangay || '')}" 
                            oninput="updateStudentField(${idx}, 'barangay', this.value)" placeholder="Barangay">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.municipality || '')}" 
                            oninput="updateStudentField(${idx}, 'municipality', this.value)" placeholder="Municipality">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.province || '')}" 
                            oninput="updateStudentField(${idx}, 'province', this.value)" placeholder="Province">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.contact_number || '')}" 
                            oninput="updateStudentField(${idx}, 'contact_number', this.value)" placeholder="09xxxxxxxxx">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" 
                            value="${escHtml(s.parent_name || '')}" 
                            oninput="updateStudentField(${idx}, 'parent_name', this.value)" placeholder="Parent/Guardian">
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger p-1" onclick="removePreviewRow(${idx})" title="Exclude this learner">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>`;
            });

            if (previewState.students.length === 0) {
                html = '<tr><td colspan="16" class="text-center text-muted py-4">No learners in list. Click "Add Learner Row" to add one.</td></tr>';
            }

            tbody.innerHTML = html;
        }

        function updateStudentField(idx, field, value) {
            if (!previewState.students[idx]) return;
            previewState.students[idx][field] = value;
            if (field === 'sex' || field === 'lrn') {
                recalculateStats();
            }
        }

        function addPreviewRow() {
            const defaultGrade = parseInt(document.getElementById('previewGradeLevel').value) || 11;
            const defaultSection = document.getElementById('previewSection').value || '';
            const defaultTrack = document.getElementById('previewTrack').value || 'academic';

            previewState.students.unshift({
                temp_id: Date.now(),
                lrn: '',
                last_name: '',
                first_name: '',
                middle_name: '',
                name_extension: '',
                sex: 'male',
                grade_level: defaultGrade,
                section: defaultSection,
                track: defaultTrack,
                birthdate: '',
                house_street: '',
                barangay: '',
                municipality: 'Balingasag',
                province: 'Misamis Oriental',
                contact_number: '',
                parent_name: '',
                is_existing: false,
                is_valid_lrn: false
            });

            renderPreviewTable();
            recalculateStats();
        }

        function removePreviewRow(idx) {
            previewState.students.splice(idx, 1);
            renderPreviewTable();
            recalculateStats();
        }

        function filterPreviewRows() {
            renderPreviewTable();
        }

        function syncPreviewMetadata() {
            const grade = parseInt(document.getElementById('previewGradeLevel').value) || 11;
            const section = document.getElementById('previewSection').value || '';
            const track = document.getElementById('previewTrack').value || 'academic';

            previewState.students.forEach(s => {
                s.grade_level = grade;
                s.section = section;
                s.track = track;
            });
        }

        function recalculateStats() {
            let total = previewState.students.length;
            let male = 0;
            let female = 0;
            let existing = 0;
            let invalid = 0;

            previewState.students.forEach(s => {
                if (s.sex === 'female') female++;
                else male++;

                if (s.is_existing) existing++;
                const isLrnValid = s.lrn && s.lrn.length === 12 && /^\d+$/.test(s.lrn);
                if (!isLrnValid) invalid++;
            });

            document.getElementById('previewStatTotal').textContent = total;
            document.getElementById('previewStatMale').textContent = male;
            document.getElementById('previewStatFemale').textContent = female;
            document.getElementById('previewStatNew').textContent = total - existing;
            document.getElementById('previewStatExisting').textContent = existing;
            document.getElementById('previewStatInvalid').textContent = invalid;
            document.getElementById('commitCount').textContent = total;
        }

        function commitImport() {
            if (previewState.students.length === 0) {
                showNotification('No learners available to import.', 'warning');
                return;
            }

            // Sync latest metadata
            const grade = parseInt(document.getElementById('previewGradeLevel').value) || 11;
            const section = (document.getElementById('previewSection').value || '').trim();
            const track = document.getElementById('previewTrack').value || 'academic';
            const academicYear = (document.getElementById('previewSchoolYear').value || '').trim();

            if (!section) {
                showNotification('Please provide a Section Name before importing.', 'warning');
                document.getElementById('previewSection').focus();
                return;
            }

            // Apply section & grade to all rows
            previewState.students.forEach(s => {
                if (!s.section) s.section = section;
                if (!s.grade_level) s.grade_level = grade;
                if (!s.track) s.track = track;
            });

            const btn = document.getElementById('commitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing to Database...';

            document.getElementById('importPreviewStage').style.display = 'none';
            document.getElementById('importProgress').style.display = '';
            document.getElementById('importProgressBar').style.width = '40%';
            document.getElementById('importStatus').textContent = 'Creating student records and parent accounts...';

            const csrfToken = document.getElementById('importCsrfToken').value;

            const postData = new URLSearchParams();
            postData.set('action', 'commit');
            postData.set('csrf_token', csrfToken);
            postData.set('academic_year', academicYear);
            postData.set('students', JSON.stringify(previewState.students));

            fetch('admin_SF1_Import_Action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: postData.toString()
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('importProgressBar').style.width = '100%';
                document.getElementById('importProgressBar').classList.remove('progress-bar-animated');
                document.getElementById('importStatus').textContent = 'Import Complete.';

                document.getElementById('previewStageButtons').style.setProperty('display', 'none', 'important');
                document.getElementById('resultsStageButtons').style.setProperty('display', 'flex', 'important');
                document.getElementById('importResults').style.display = '';

                const created = data.created || 0;
                const skipped = data.skipped || 0;
                const errors = data.errors || 0;
                const parentsCreated = data.parents_created || 0;
                const parentsLinked = data.parents_linked || 0;
                const sectionsCreated = data.sections_created || 0;

                let summaryBadges = '<span class="badge bg-success fs-6"><i class="bi bi-person-plus me-1"></i>' + created + ' Students Created</span>';
                if (sectionsCreated > 0) {
                    summaryBadges += '<span class="badge bg-primary fs-6"><i class="bi bi-diagram-3 me-1"></i>' + sectionsCreated + ' Sections Created</span>';
                }
                if (parentsCreated > 0 || parentsLinked > 0) {
                    summaryBadges += '<span class="badge bg-info text-dark fs-6"><i class="bi bi-people me-1"></i>' + parentsCreated + ' Parents Created (' + parentsLinked + ' Linked)</span>';
                }
                if (skipped > 0) {
                    summaryBadges += '<span class="badge bg-warning text-dark fs-6"><i class="bi bi-skip-forward me-1"></i>' + skipped + ' Skipped (Duplicate LRN)</span>';
                }
                if (errors > 0) {
                    summaryBadges += '<span class="badge bg-danger fs-6"><i class="bi bi-exclamation-triangle me-1"></i>' + errors + ' Errors</span>';
                }

                document.getElementById('importSummary').innerHTML = '<div class="d-flex gap-2 flex-wrap">' + summaryBadges + '</div>';

                const log = document.getElementById('importLog');
                const rows = data.rows || [];
                if (rows.length === 0) {
                    log.innerHTML = '<p class="text-muted">No row details available.</p>';
                } else {
                    log.innerHTML = '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>#</th><th>Name</th><th>LRN</th><th>Status</th><th>Details</th></tr></thead><tbody>' +
                        rows.map(r => '<tr class="' + (r.status === 'created' ? 'table-success' : r.status === 'skipped' ? 'table-warning' : 'table-danger') + '">' +
                            '<td>' + r.row + '</td><td>' + escHtml(r.name || '') + '</td><td>' + escHtml(r.lrn || '') + '</td>' +
                            '<td><span class="badge bg-' + (r.status === 'created' ? 'success' : r.status === 'skipped' ? 'warning text-dark' : 'danger') + '">' + r.status + '</span></td>' +
                            '<td>' + escHtml(r.message || '') + '</td></tr>').join('') +
                        '</tbody></table>';
                }

                if (data.success) {
                    showNotification(created + ' learner(s) successfully registered!', 'success');
                    loadStudents(1);
                } else {
                    showNotification(data.message || 'Import finished with errors.', 'warning');
                }
            })
            .catch(err => {
                document.getElementById('importStatus').textContent = 'Error during import.';
                showNotification('Import failed. Please try again.', 'danger');
                console.error(err);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm & Import Learners';
            });
        }
    </script>
<?php include '../includes/footer.php'; ?>
