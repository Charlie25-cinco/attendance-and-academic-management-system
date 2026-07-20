<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Manage Classes Page
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$classes = [];
$search = trim((string)($_GET['search'] ?? ''));
$gradeFilter = trim((string)($_GET['grade_level'] ?? ''));
$sectionFilter = trim((string)($_GET['section'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'active'));
$availableSections = [];
$classFormSections = [];

if (!in_array($gradeFilter, ['11', '12'], true)) {
    $gradeFilter = '';
}
if (!in_array($statusFilter, ['active', 'inactive', ''], true)) {
    $statusFilter = 'active';
}

$hasTrack = false;
if ($db) {
    $hasSubjectCategory = dbHasColumn($db, 'classes', 'subject_category');
    $hasTrack = dbHasColumn($db, 'classes', 'track');
    $hasCurriculum = dbHasColumn($db, 'classes', 'curriculum');
    $hasProgram = dbHasColumn($db, 'classes', 'program');

    if (dbHasTable($db, 'sections')) {
        $formSectionRows = $db->query("SELECT id, name, grade_level, track, curriculum, program
                                       FROM sections
                                       ORDER BY grade_level ASC, track ASC, name ASC");
        foreach (($formSectionRows ? $formSectionRows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $classFormSections[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
                'grade_level' => (string)($row['grade_level'] ?? ''),
                'track' => trim((string)($row['track'] ?? '')),
                'curriculum' => trim((string)($row['curriculum'] ?? '')),
                'program' => trim((string)($row['program'] ?? '')),
            ];
        }
    }

    $sectionRows = $db->query("SELECT DISTINCT grade_level, section
                               FROM classes
                               WHERE TRIM(COALESCE(section, '')) <> ''
                               ORDER BY grade_level ASC, section ASC");
    foreach (($sectionRows ? $sectionRows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $gradeKey = (string)($row['grade_level'] ?? '');
        $sectionValue = trim((string)($row['section'] ?? ''));
        if ($gradeKey === '' || $sectionValue === '') {
            continue;
        }
        if (!isset($availableSections[$gradeKey])) {
            $availableSections[$gradeKey] = [];
        }
        if (!in_array($sectionValue, $availableSections[$gradeKey], true)) {
            $availableSections[$gradeKey][] = $sectionValue;
        }
    }

    if ($sectionFilter !== '') {
        $validSections = [];
        if ($gradeFilter !== '' && isset($availableSections[$gradeFilter])) {
            $validSections = $availableSections[$gradeFilter];
        } else {
            foreach ($availableSections as $sections) {
                foreach ($sections as $section) {
                    if (!in_array($section, $validSections, true)) {
                        $validSections[] = $section;
                    }
                }
            }
        }
        if (!in_array($sectionFilter, $validSections, true)) {
            $sectionFilter = '';
        }
    }

    // Fetch classes with student count and assigned teacher
    $subjectCategorySelect = $hasSubjectCategory ? ", c.subject_category" : ", NULL AS subject_category";
    $trackSelect = $hasTrack ? ", c.track" : ", NULL AS track";
    $curriculumSelect = $hasCurriculum ? ", c.curriculum" : ", NULL AS curriculum";
    $programSelect = $hasProgram ? ", c.program" : ", NULL AS program";
    $where = [];
    $params = [];
    if ($statusFilter !== '') {
        $where[] = "c.status = :status";
        $params[':status'] = $statusFilter;
    }
    if ($gradeFilter !== '') {
        $where[] = "c.grade_level = :grade_level";
        $params[':grade_level'] = $gradeFilter;
    }
    if ($sectionFilter !== '') {
        $where[] = "LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(:section))";
        $params[':section'] = $sectionFilter;
    }
    if ($search !== '') {
        $where[] = "(c.class_name LIKE :search OR COALESCE(c.room, '') LIKE :search OR COALESCE(c.schedule, '') LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "SELECT c.*,
              (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND status = 'enrolled') as student_count,
              (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ')
               FROM class_subjects cs 
               JOIN users u ON cs.teacher_id = u.id 
               WHERE cs.class_id = c.id AND u.status IN ('active', 'pending')) as teacher_name
              $subjectCategorySelect
              $trackSelect
              $curriculumSelect
              $programSelect
              FROM classes c
              $whereClause
              ORDER BY c.grade_level, c.section";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_role = 'admin';
$current_page = 'classes';
$page_title = 'Manage Classes';
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
    <style>
        .schedule-row .input-group .form-control { min-width: 56px; }
        .schedule-row .input-group-text { min-width: 18px; justify-content: center; }
        .schedule-header-row .form-label { min-height: 20px; }
        .schedule-row .form-control,
        .schedule-row .form-select { height: 42px; }
        .schedule-time-group { flex-wrap: nowrap; }
        .schedule-time-group .form-control { flex: 0 0 56px; width: 56px; }
        .schedule-time-group .form-select { flex: 0 0 70px; width: 70px; }
        .schedule-time-group .input-group-text { flex: 0 0 18px; width: 18px; }
        .schedule-row .schedule-remove { height: 42px; padding: 0; }
        @media (max-width: 575.98px) {
            .schedule-row { row-gap: 10px; }
            .schedule-row .input-group .form-control { min-width: 64px; }
        }
        #addClassModal .modal-dialog { max-width: 980px; width: 95vw; }
        #addClassModal .modal-body { overflow-x: auto; }
    </style>
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Manage Classes</h4>
                    <p class="text-muted mb-0">Create subject classes, set schedules, and manage grade-level sections.</p>
                </div>
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addClassModal">
                    <i class="bi bi-plus-lg me-2"></i>Add New Class
                </button>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-12 col-lg-4">
                            <label for="classSearch" class="form-label">Search</label>
                            <input
                                type="text"
                                id="classSearch"
                                name="search"
                                class="form-control"
                                placeholder="Search subject, room, or schedule"
                                value="<?php echo htmlspecialchars($search); ?>"
                            >
                        </div>
                        <div class="col-6 col-lg-2">
                            <label for="gradeFilter" class="form-label">Grade</label>
                            <select id="gradeFilter" name="grade_level" class="form-select">
                                <option value="">All Grades</option>
                                <option value="11" <?php echo $gradeFilter === '11' ? 'selected' : ''; ?>>Grade 11</option>
                                <option value="12" <?php echo $gradeFilter === '12' ? 'selected' : ''; ?>>Grade 12</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label for="sectionFilter" class="form-label">Section</label>
                            <select id="sectionFilter" name="section" class="form-select">
                                <option value="">All Sections</option>
                                <?php
                                $sectionOptions = [];
                                if ($gradeFilter !== '' && isset($availableSections[$gradeFilter])) {
                                    $sectionOptions = $availableSections[$gradeFilter];
                                } else {
                                    foreach ($availableSections as $sections) {
                                        foreach ($sections as $sectionOption) {
                                            if (!in_array($sectionOption, $sectionOptions, true)) {
                                                $sectionOptions[] = $sectionOption;
                                            }
                                        }
                                    }
                                }
                                foreach ($sectionOptions as $sectionOption):
                                ?>
                                    <option value="<?php echo htmlspecialchars($sectionOption); ?>" <?php echo $sectionFilter === $sectionOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sectionOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label for="statusFilter" class="form-label">Status</label>
                            <select id="statusFilter" name="status" class="form-select">
                                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary-custom flex-fill">
                                <i class="bi bi-funnel me-1"></i>Apply
                            </button>
                            <a href="admin_Classes.php" class="btn btn-outline-secondary flex-fill">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Classes Grid -->
            <div class="row g-4">
                <?php 
                $gradients = [
                    'linear-gradient(135deg, var(--primary-color), var(--secondary-color))',
                    'linear-gradient(135deg, var(--accent-color), #059669)',
                    'linear-gradient(135deg, var(--warning-color), #d97706)',
                    'linear-gradient(135deg, var(--danger-color), #dc2626)',
                    'linear-gradient(135deg, #8b5cf6, #7c3aed)',
                    'linear-gradient(135deg, #ec4899, #db2777)'
                ];
                if (empty($classes)):
                ?>
                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-body text-center py-5">
                            <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                            <h5 class="mb-2">No classes found</h5>
                            <p class="text-muted mb-0">Try adjusting your search or filter selections.</p>
                        </div>
                    </div>
                </div>
                <?php
                endif;
                foreach ($classes as $index => $class): 
                    $gradient = $gradients[$index % count($gradients)];
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="class-card">
                        <div class="class-card-header" style="background: <?php echo $gradient; ?>">
                            <div class="class-card-icon"><i class="bi bi-journal-bookmark"></i></div>
                        </div>
                        <div class="class-card-body">
                            <h4 class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></h4>
                            <p class="class-card-subtitle">Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['section']); ?></p>
                            <?php if (!empty($class['subject_category'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-tags me-1"></i><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $class['subject_category']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($class['track'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars(ucfirst($class['track'])); ?> Track</p>
                            <?php endif; ?>
                            <?php if (!empty($class['program'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars(strengthenedShsProgramLabel($class['program'])); ?></p>
                            <?php endif; ?>
                            <p class="text-muted small mb-1"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($class['teacher_name'] ?: 'No Teacher Assigned'); ?></p>
                            <p class="text-muted small mb-2"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($class['schedule'] ?: 'No schedule set'); ?></p>
                            <p class="text-muted small mb-3"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($class['room'] ?: 'No room assigned'); ?></p>
                            <div class="class-card-stats mb-3">
                                <span class="class-card-stat"><i class="bi bi-people"></i> <?php echo $class['student_count']; ?> Students</span>
                            </div>
                            <div class="d-flex gap-2 mb-2">
                                <button class="btn btn-sm btn-primary-custom flex-fill" onclick="viewClass(<?php echo $class['id']; ?>)">View</button>
                                <button class="btn btn-sm btn-secondary-custom flex-fill" onclick="editClass(<?php echo $class['id']; ?>)">Edit</button>
                                <button class="btn btn-sm btn-danger" onclick='deleteClass(<?php echo (int)$class['id']; ?>, <?php echo json_encode((string)$class["class_name"]); ?>)'><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-journal-bookmark"></i>Classes</div>
                        <h5 class="modal-title mb-0">Add New Class</h5>
                        <p class="app-modal-subtitle">Create a subject class and assign grade section details.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="addClassForm">
                        <div class="app-modal-stack">
                            <div class="app-modal-note">
                                <i class="bi bi-diagram-3-fill"></i>
                                <div>
                                    <strong>Section-aware setup</strong>
                                    <p>For Grade 11, track maps to the Strengthened SHS program used by enrollment, SF2, and grading workflows.</p>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-journal-text"></i>Class Identity</div>
                                <div class="row">
                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">Subject Name <span class="text-danger">*</span></label>
                                        <input type="text" id="addSubjectNameInput" name="class_name" class="form-control" placeholder="Enter subject name" required>
                                    </div>
                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">Subject Category <span class="text-danger">*</span></label>
                                        <select name="subject_category" class="form-select" onchange="onSubjectCategoryChange(this)">
                                            <option value="">Select Category</option>
                                            <option value="core">Core Subject</option>
                                            <option value="academic_elective">Academic Elective</option>
                                            <option value="techpro_elective">TechPro Elective</option>
                                            <option value="work_immersion">Work Immersion</option>
                                            <option value="field_experience_elective">Field Experience / Sports</option>
                                        </select>
                                        <small class="text-muted">Determines default grading weights per DM 74, s. 2025.</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Track</label>
                                        <select name="track" id="classTrack" class="form-select" onchange="updateSections()">
                                            <option value="">Select Track</option>
                                            <option value="academic">Academic</option>
                                            <option value="techpro">TechPro</option>
                                        </select>
                                        <small class="text-muted">Grade 11 Academic maps to Academic Track (Strengthened); TechPro maps to Technical Professional.</small>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="gradeLevel" class="form-select" required onchange="updateSections()">
                                            <option value="">Select</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-0">
                                        <label class="form-label">Section <span class="text-danger">*</span></label>
                                        <select name="section" id="sectionSelect" class="form-select" required disabled>
                                            <option value="">Select Grade Level First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-calendar-week"></i>Schedule and Room</div>
                                <div class="row g-2 align-items-end schedule-header-row">
                                    <div class="col-12 col-sm-3">
                                        <div class="form-label small text-muted mb-0">Day</div>
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <div class="form-label small text-muted mb-0">Start</div>
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <div class="form-label small text-muted mb-0">End</div>
                                    </div>
                                    <div class="col-12 col-sm-1"></div>
                                </div>
                                <div id="scheduleRows" class="d-flex flex-column gap-2 mt-1"></div>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addScheduleRow()">
                                    <i class="bi bi-plus-circle me-1"></i>Add time slot
                                </button>
                                <small class="text-muted d-block mt-2">Add one row per day and time range, like Mon 7:00-8:00 or Tue 8:00-9:00.</small>
                                <div class="mt-3 mb-0">
                                    <label class="form-label">Room</label>
                                    <input type="text" id="addRoomInput" name="room" class="form-control" placeholder="e.g., Room 101">
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-calculator"></i>Grading Weights</div>
                                <p class="app-modal-panel-copy">These weights are used in class grade computation. The combined total must remain 100%.</p>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Written Work</label>
                                        <input type="number" name="ww_weight" class="form-control" value="25" min="0" max="100" step="0.01" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Performance Task</label>
                                        <input type="number" name="pt_weight" class="form-control" value="50" min="0" max="100" step="0.01" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Assessment</label>
                                        <input type="number" name="assessment_weight" class="form-control" value="25" min="0" max="100" step="0.01" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="createClassBtn" onclick="saveClass()">Create Class</button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/delete_modal.php'; ?>

    <script>
        let sectionsCache = {};
        const serverSections = <?php echo json_encode($classFormSections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const addClassModalEl = document.getElementById('addClassModal');
        let addClassModal = null;

        function getAddClassModal() {
            if (!addClassModalEl || !window.bootstrap?.Modal) {
                return null;
            }
            addClassModal = addClassModal || bootstrap.Modal.getOrCreateInstance(addClassModalEl);
            return addClassModal;
        }

        function hydrateSectionsCache(sections) {
            const nextCache = {};
            (sections || []).forEach(section => {
                const name = (section.name || '').toString().trim();
                const gradeLevel = (section.grade_level || '').toString().trim();
                const track = (section.track || '').toString().trim();

                if (!name || !gradeLevel) {
                    return;
                }

                const key = gradeLevel + '_' + track;
                if (!nextCache[key]) {
                    nextCache[key] = [];
                }

                const suffix = section.program === 'academic_strengthened'
                    ? ' - Academic Track (Strengthened)'
                    : (section.program === 'technical_professional' ? ' - Technical Professional' : '');
                nextCache[key].push({ value: name, label: name + suffix });
            });

            sectionsCache = nextCache;
            return sectionsCache;
        }

        function loadSectionsCache() {
            return fetch('admin_Sections_Action.php?action=list')
                .then(response => response.json())
                .then(data => {
                    if (data.success && Array.isArray(data.sections)) {
                        return hydrateSectionsCache(data.sections);
                    }
                    return sectionsCache;
                })
                .catch(() => sectionsCache);
        }

        function getSectionsFor(grade, track) {
            if (track) return sectionsCache[grade + '_' + track] || [];
            const all = [];
            Object.keys(sectionsCache).forEach(key => {
                if (key.startsWith(grade + '_')) {
                    all.push(...sectionsCache[key]);
                }
            });
            return all;
        }
        function resetAddClassModal() {
            const form = document.getElementById('addClassForm');
            if (form) form.reset();

            const sectionSelect = document.getElementById('sectionSelect');
            if (sectionSelect) {
                sectionSelect.disabled = true;
                sectionSelect.innerHTML = '<option value="">Select Grade & Track First</option>';
            }

            const scheduleRows = document.getElementById('scheduleRows');
            if (scheduleRows) {
                scheduleRows.innerHTML = '';
                addScheduleRow();
            }
        }

        if (addClassModalEl) {
            addClassModalEl.addEventListener('show.bs.modal', function() {
                resetAddClassModal();
                hydrateSectionsCache(serverSections);
                loadSectionsCache().then(updateSections);
            });
            addClassModalEl.addEventListener('hidden.bs.modal', resetAddClassModal);
        }
        
        function updateSections() {
            const gradeLevel = document.getElementById('gradeLevel').value;
            const track = document.getElementById('classTrack').value;
            const sectionSelect = document.getElementById('sectionSelect');

            sectionSelect.innerHTML = '';

            if (gradeLevel) {
                const list = getSectionsFor(gradeLevel, track);
                sectionSelect.disabled = !list.length;
                if (list.length) {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    list.forEach(section => {
                        const option = document.createElement('option');
                        option.value = section.value;
                        option.textContent = section.label;
                        sectionSelect.appendChild(option);
                    });
                } else {
                    sectionSelect.innerHTML = '<option value="">No sections available</option>';
                }
            } else {
                sectionSelect.disabled = true;
                sectionSelect.innerHTML = '<option value="">Select Grade & Track First</option>';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            hydrateSectionsCache(serverSections);
            loadSectionsCache();
        });
        
        const weightPresets = {
            core: { ww: 25, pt: 50, qa: 25 },
            academic_elective: { ww: 25, pt: 45, qa: 30 },
            techpro_elective: { ww: 20, pt: 60, qa: 20 },
            work_immersion: { ww: 20, pt: 80, qa: 0 },
            field_experience_elective: { ww: 15, pt: 65, qa: 20 },
        };

        function onSubjectCategoryChange(select) {
            const category = select?.value;
            const preset = weightPresets[category];
            if (preset) {
                const form = select.closest('form');
                if (form) {
                    form.querySelector('[name="ww_weight"]').value = preset.ww;
                    form.querySelector('[name="pt_weight"]').value = preset.pt;
                    form.querySelector('[name="assessment_weight"]').value = preset.qa;
                }
            }
        }

        function toTitleCaseWords(value) {
            if (!value) return '';
            return value.toLowerCase().replace(/\b\w/g, ch => ch.toUpperCase());
        }

        const addSubjectNameInput = document.getElementById('addSubjectNameInput');
        if (addSubjectNameInput) {
            addSubjectNameInput.addEventListener('input', function () {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = toTitleCaseWords(this.value);
                this.setSelectionRange(start, end);
            });
        }

        const addRoomInput = document.getElementById('addRoomInput');
        if (addRoomInput) {
            addRoomInput.addEventListener('input', function () {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = toTitleCaseWords(this.value);
                this.setSelectionRange(start, end);
            });
        }

        function addScheduleRow(row = {}) {
            const container = document.getElementById('scheduleRows');
            if (!container) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'row g-2 align-items-center schedule-row';
            wrapper.innerHTML = `
                <div class="col-12 col-sm-3">
                    <select class="form-select schedule-day" data-field="day">
                        <option value="">Select</option>
                        <option value="Mon">Mon</option>
                        <option value="Tue">Tue</option>
                        <option value="Wed">Wed</option>
                        <option value="Thu">Thu</option>
                        <option value="Fri">Fri</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="input-group schedule-time-group">
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="start_hour" placeholder="HH">
                        <span class="input-group-text">:</span>
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="start_min" placeholder="MM">
                        <select class="form-select schedule-time" data-field="start_ampm" style="max-width: 70px;">
                            <option value="AM">AM</option>
                            <option value="PM">PM</option>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="input-group schedule-time-group">
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="end_hour" placeholder="HH">
                        <span class="input-group-text">:</span>
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="end_min" placeholder="MM">
                        <select class="form-select schedule-time" data-field="end_ampm" style="max-width: 70px;">
                            <option value="AM">AM</option>
                            <option value="PM">PM</option>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-sm-1 d-grid">
                    <button type="button" class="btn btn-outline-danger btn-sm schedule-remove" title="Remove" onclick="removeScheduleRow(this)">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            `;
            container.appendChild(wrapper);

            const setValue = (selector, value) => {
                const el = wrapper.querySelector(selector);
                if (el && value !== undefined && value !== null && value !== '') {
                    el.value = value;
                }
            };
            setValue('[data-field="day"]', row.day);
            setValue('[data-field="start_hour"]', row.start_hour);
            setValue('[data-field="start_min"]', row.start_min);
            setValue('[data-field="start_ampm"]', row.start_ampm);
            setValue('[data-field="end_hour"]', row.end_hour);
            setValue('[data-field="end_min"]', row.end_min);
            setValue('[data-field="end_ampm"]', row.end_ampm);
        }

        function removeScheduleRow(button) {
            const row = button?.closest('.schedule-row');
            if (row) {
                row.remove();
            }
        }

        function sanitizeScheduleNumericInput(target) {
            if (!target || !target.dataset) return;
            const field = target.dataset.field || '';
            if (!field.includes('hour') && !field.includes('min')) return;
            const raw = String(target.value || '');
            let cleaned = raw.replace(/\D/g, '');
            if (cleaned.length > 2) cleaned = cleaned.slice(0, 2);
            target.value = cleaned;
        }

        const scheduleRowsEl = document.getElementById('scheduleRows');
        if (scheduleRowsEl) {
            scheduleRowsEl.addEventListener('input', function (event) {
                sanitizeScheduleNumericInput(event.target);
            });
        }

        function collectScheduleRows() {
            const rows = [];
            document.querySelectorAll('#scheduleRows .schedule-row').forEach(row => {
                const day = row.querySelector('[data-field="day"]')?.value?.trim() || '';
                const startHour = row.querySelector('[data-field="start_hour"]')?.value?.trim() || '';
                const startMin = row.querySelector('[data-field="start_min"]')?.value?.trim() || '';
                const startAmPm = row.querySelector('[data-field="start_ampm"]')?.value || 'AM';
                const endHour = row.querySelector('[data-field="end_hour"]')?.value?.trim() || '';
                const endMin = row.querySelector('[data-field="end_min"]')?.value?.trim() || '';
                const endAmPm = row.querySelector('[data-field="end_ampm"]')?.value || 'AM';

                if (!day && !startHour && !endHour) {
                    return;
                }
                rows.push({
                    day,
                    start_hour: startHour,
                    start_min: startMin || '00',
                    start_ampm: startAmPm,
                    end_hour: endHour,
                    end_min: endMin || '00',
                    end_ampm: endAmPm
                });
            });
            return rows;
        }
        function viewClass(id) {
            window.location.href = 'admin_Class_Detail.php?id=' + id;
        }
        
        function editClass(id) {
            window.location.href = 'admin_Class_Edit.php?id=' + id;
        }
        
        // Delete class - uses reusable modal
        function deleteClass(id, className) {
            showDeleteModal(id, className || 'Class', 'class');
        }
        
        // Handle delete confirmation - called by reusable modal
        function handleDelete(id) {
            const formData = new FormData();
            formData.append('id', String(id));
            if (window.APP_CSRF_TOKEN) {
                formData.append('csrf_token', window.APP_CSRF_TOKEN);
            }

            fetch('admin_Classes_Action.php?action=delete', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Class deleted successfully', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(data.message || 'Failed to delete class', 'danger');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting class', 'danger');
                    console.error(error);
                });
        }
        
        function saveClass() {
            const form = document.getElementById('addClassForm');
            const formData = new FormData(form);
            const createClassBtn = document.getElementById('createClassBtn');
            const className = toTitleCaseWords((formData.get('class_name') || '').toString().trim());
            const gradeLevel = (formData.get('grade_level') || '').toString().trim();
            const section = (formData.get('section') || '').toString().trim();

            if (!className || !gradeLevel || !section) {
                showNotification('Subject name, grade level, and section are required', 'warning');
                return;
            }

            formData.set('class_name', className);
            formData.set('section', section);
            const ww = parseFloat(formData.get('ww_weight') || '0');
            const pt = parseFloat(formData.get('pt_weight') || '0');
            const qa = parseFloat(formData.get('assessment_weight') || '0');
            if (Math.abs((ww + pt + qa) - 100) > 0.01) {
                showNotification('Grading weights must total 100%', 'warning');
                return;
            }

            const scheduleRows = collectScheduleRows();
            if (scheduleRows.length === 0) {
                showNotification('Please add at least one schedule row', 'warning');
                return;
            }
            formData.append('schedule_rows', JSON.stringify(scheduleRows));

            if (createClassBtn) {
                createClassBtn.disabled = true;
                createClassBtn.textContent = 'Creating...';
            }
            
            fetch('admin_Classes_Action.php?action=create', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Class created successfully', 'success');
                    const modal = getAddClassModal();
                    if (modal) modal.hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to create class', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error creating class', 'danger');
                console.error(error);
            })
            .finally(() => {
                if (createClassBtn) {
                    createClassBtn.disabled = false;
                    createClassBtn.textContent = 'Create Class';
                }
            });
        }
    </script>
<?php include '../includes/footer.php'; ?>








