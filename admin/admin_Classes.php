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
$availableTeachers = [];

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

    $teacherStmt = $db->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY last_name ASC, first_name ASC");
    if ($teacherStmt) {
        $availableTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $availableSubjects = [];
    if (dbHasTable($db, 'subjects')) {
        $subjectRows = $db->query("SELECT id, subject_name, subject_code, subject_category, track, grade_level
                                   FROM subjects
                                   ORDER BY subject_name ASC");
        if ($subjectRows) {
            $availableSubjects = $subjectRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    // Presentation-layer grouping: group section-specific records by subject and grade-level identity
    $groupedClasses = [];
    foreach ($classes as $classRow) {
        $normName = strtolower(trim((string)($classRow['class_name'] ?? '')));
        $normGrade = (string)($classRow['grade_level'] ?? '');
        $normCategory = strtolower(trim((string)($classRow['subject_category'] ?? 'core')));
        $normTrack = strtolower(trim((string)($classRow['track'] ?? 'academic')));
        $normProgram = strtolower(trim((string)($classRow['program'] ?? '')));
        $normStatus = strtolower(trim((string)($classRow['status'] ?? 'active')));

        $groupKey = implode('|', [
            $normName,
            $normGrade,
            $normCategory,
            $normTrack,
            $normProgram,
            $normStatus,
        ]);

        if (!isset($groupedClasses[$groupKey])) {
            $groupedClasses[$groupKey] = [
                'group_key' => $groupKey,
                'class_name' => $classRow['class_name'],
                'grade_level' => $classRow['grade_level'],
                'subject_category' => $classRow['subject_category'] ?? null,
                'track' => $classRow['track'] ?? null,
                'program' => $classRow['program'] ?? null,
                'curriculum' => $classRow['curriculum'] ?? null,
                'status' => $classRow['status'] ?? 'active',
                'sections' => [],
                'total_students' => 0,
                'teachers' => [],
                'schedules' => [],
                'rooms' => [],
                'primary_id' => (int)$classRow['id'],
            ];
        }

        $teacherName = trim((string)($classRow['teacher_name'] ?? ''));
        if ($teacherName !== '' && !in_array($teacherName, $groupedClasses[$groupKey]['teachers'], true)) {
            $groupedClasses[$groupKey]['teachers'][] = $teacherName;
        }

        $sched = trim((string)($classRow['schedule'] ?? ''));
        if ($sched !== '' && $sched !== 'No schedule set' && $sched !== 'TBA' && !in_array($sched, $groupedClasses[$groupKey]['schedules'], true)) {
            $groupedClasses[$groupKey]['schedules'][] = $sched;
        }

        $room = trim((string)($classRow['room'] ?? ''));
        if ($room !== '' && $room !== 'No room assigned' && $room !== 'TBA' && !in_array($room, $groupedClasses[$groupKey]['rooms'], true)) {
            $groupedClasses[$groupKey]['rooms'][] = $room;
        }

        $groupedClasses[$groupKey]['sections'][] = [
            'id' => (int)$classRow['id'],
            'section' => $classRow['section'],
            'student_count' => (int)($classRow['student_count'] ?? 0),
            'schedule' => $classRow['schedule'] ?: 'No schedule set',
            'room' => $classRow['room'] ?: 'No room assigned',
            'teacher_name' => $classRow['teacher_name'] ?: 'No Teacher Assigned',
            'teacher_id' => $classRow['teacher_id'] ?? null,
            'status' => $classRow['status'] ?? 'active',
        ];
        $groupedClasses[$groupKey]['total_students'] += (int)($classRow['student_count'] ?? 0);
    }
    $groupedClasses = array_values($groupedClasses);
} else {
    $groupedClasses = [];
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
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
    <style>
        .schedule-row .input-group .form-control { min-width: 56px; }
        .schedule-row .input-group-text { min-width: 18px; justify-content: center; font-weight: 700; }
        .schedule-header-row .form-label { min-height: 20px; font-weight: 600; }
        .schedule-row .form-control,
        .schedule-row .form-select { height: 42px; }
        .schedule-time-group { flex-wrap: nowrap; }
        .schedule-time-group .form-control { flex: 0 0 56px; width: 56px; text-align: center; font-weight: 600; }
        .schedule-time-group .form-select { flex: 0 0 76px; width: 76px; min-width: 76px; text-align: center; font-weight: 700; padding-left: 8px; padding-right: 24px; }
        .schedule-time-group .input-group-text { flex: 0 0 18px; width: 18px; }
        .schedule-row .schedule-remove { height: 42px; padding: 0; }
        @media (max-width: 575.98px) {
            .schedule-row { row-gap: 10px; }
            .schedule-row .input-group .form-control { min-width: 64px; }
        }
        #addClassModal .modal-dialog { max-width: 980px; width: 95vw; }
        #addClassModal .modal-body { overflow-x: auto; }
        .app-section-pill { background-color: var(--ui-surface, #ffffff); border-color: var(--ui-border-soft, #e8edf3); color: var(--ui-text, #172033); }
        .app-section-pill .form-check-label { color: inherit; }
        body.dark-mode .app-section-pill { background-color: #182230 !important; border-color: #344054 !important; color: #f2f4f7 !important; }
        body.dark-mode #sectionSchedTabs { background-color: #111b2a !important; border-color: #293548 !important; }
        body.dark-mode #sectionSchedTabContent { background-color: #182230 !important; border-color: #293548 !important; color: #f2f4f7; }
        .class-card-grouped { border: 1px solid rgba(var(--primary-rgb, 37, 99, 235), 0.25); }
        .app-group-section-item { transition: all 0.2s ease; border-color: #e5e7eb; }
        .app-group-section-item:hover { border-color: var(--primary-color, #2563eb) !important; box-shadow: 0 4px 12px rgba(0,0,0,0.06) !important; }
        body.dark-mode .app-group-section-item { background-color: #182230 !important; border-color: #344054 !important; color: #f2f4f7 !important; }
        body.dark-mode .app-group-section-item h6 { color: #f2f4f7 !important; }
        body.dark-mode .app-group-meta-bar { background-color: #182230 !important; border-color: #344054 !important; color: #f2f4f7 !important; }
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
                    <form method="GET" class="row g-3 align-items-end app-responsive-filter-form">
                        <div class="col-12 col-lg-3 col-xl-4">
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
                        <div class="col-12 col-lg-3 col-xl-2 d-flex gap-2 app-filter-actions-col">
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
                if (empty($groupedClasses)):
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
                foreach ($groupedClasses as $index => $group): 
                    $gradient = $gradients[$index % count($gradients)];
                    $isMultiSection = count($group['sections']) > 1;
                    $sectionCount = count($group['sections']);
                    $sectionNames = implode(', ', array_column($group['sections'], 'section'));
                    $singleSection = $group['sections'][0];

                    // Determine teacher summary
                    if (!$isMultiSection) {
                        $teacherSummary = $singleSection['teacher_name'];
                    } elseif (count($group['teachers']) === 1) {
                        $teacherSummary = $group['teachers'][0];
                    } elseif (count($group['teachers']) > 1) {
                        $teacherSummary = 'Multiple Teachers (' . count($group['teachers']) . ')';
                    } else {
                        $teacherSummary = 'No Teacher Assigned';
                    }

                    // Determine schedule summary
                    if (!$isMultiSection) {
                        $scheduleSummary = $singleSection['schedule'];
                    } elseif (count($group['schedules']) === 1) {
                        $scheduleSummary = $group['schedules'][0];
                    } elseif (count($group['schedules']) > 1) {
                        $scheduleSummary = 'Varies by section (' . $sectionCount . ' schedules)';
                    } else {
                        $scheduleSummary = 'No schedule set';
                    }

                    // Determine room summary
                    if (!$isMultiSection) {
                        $roomSummary = $singleSection['room'];
                    } elseif (count($group['rooms']) === 1) {
                        $roomSummary = $group['rooms'][0];
                    } elseif (count($group['rooms']) > 1) {
                        $roomSummary = 'Varies by section';
                    } else {
                        $roomSummary = 'No room assigned';
                    }
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="class-card <?php echo $isMultiSection ? 'class-card-grouped' : ''; ?>">
                        <div class="class-card-header" style="background: <?php echo $gradient; ?>; <?php echo $isMultiSection ? 'cursor: pointer;' : ''; ?>" <?php echo $isMultiSection ? 'onclick="openGroupedClassModal(' . $index . ')"' : ''; ?>>
                            <div class="class-card-icon"><i class="bi bi-journal-bookmark"></i></div>
                        </div>
                        <div class="class-card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <h4 class="class-card-title mb-0 <?php echo $isMultiSection ? 'cursor-pointer text-primary' : ''; ?>" <?php echo $isMultiSection ? 'style="cursor: pointer;" onclick="openGroupedClassModal(' . $index . ')"' : ''; ?>>
                                    <?php echo htmlspecialchars($group['class_name']); ?>
                                </h4>
                                <?php if ($isMultiSection): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold flex-shrink-0" style="cursor: pointer;" onclick="openGroupedClassModal(<?php echo $index; ?>)">
                                        <i class="bi bi-collection me-1"></i><?php echo $sectionCount; ?> Sections
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!$isMultiSection): ?>
                                <p class="class-card-subtitle">Grade <?php echo htmlspecialchars($group['grade_level']); ?> - <?php echo htmlspecialchars($singleSection['section']); ?></p>
                            <?php else: ?>
                                <p class="class-card-subtitle text-primary fw-medium" style="cursor: pointer;" onclick="openGroupedClassModal(<?php echo $index; ?>)">
                                    Grade <?php echo htmlspecialchars($group['grade_level']); ?> · Sections: <?php echo htmlspecialchars($sectionNames); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($group['subject_category'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-tags me-1"></i><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $group['subject_category']))); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($group['track'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars(ucfirst($group['track'])); ?> Track</p>
                            <?php endif; ?>
                            <?php if (!empty($group['program'])): ?>
                                <p class="text-muted small mb-1"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars(strengthenedShsProgramLabel($group['program'])); ?></p>
                            <?php endif; ?>
                            <p class="text-muted small mb-1"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($teacherSummary); ?></p>
                            <p class="text-muted small mb-2"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($scheduleSummary); ?></p>
                            <p class="text-muted small mb-3"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($roomSummary); ?></p>
                            
                            <div class="class-card-stats mb-3">
                                <?php if (!$isMultiSection): ?>
                                    <span class="class-card-stat"><i class="bi bi-people"></i> <?php echo $group['total_students']; ?> Students</span>
                                <?php else: ?>
                                    <span class="class-card-stat"><i class="bi bi-people"></i> Total: <?php echo $group['total_students']; ?> Students</span>
                                    <span class="class-card-stat"><i class="bi bi-collection"></i> <?php echo $sectionCount; ?> Sections</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!$isMultiSection): ?>
                                <div class="d-flex gap-2 mb-2">
                                    <button class="btn btn-sm btn-primary-custom flex-fill" onclick="viewClass(<?php echo $singleSection['id']; ?>)">View</button>
                                    <button class="btn btn-sm btn-secondary-custom flex-fill" onclick="editClass(<?php echo $singleSection['id']; ?>)">Edit</button>
                                    <button class="btn btn-sm btn-danger" onclick='deleteClass(<?php echo (int)$singleSection["id"]; ?>, <?php echo json_encode((string)$group["class_name"]); ?>)'><i class="bi bi-trash"></i></button>
                                </div>
                            <?php else: ?>
                                <div class="d-flex gap-2 mb-2">
                                    <button class="btn btn-sm btn-primary-custom w-100" onclick="openGroupedClassModal(<?php echo $index; ?>)">
                                        <i class="bi bi-collection me-1"></i>View & Manage Sections (<?php echo $sectionCount; ?>)
                                    </button>
                                </div>
                            <?php endif; ?>
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
                                        <input type="text" id="addSubjectNameInput" name="class_name" class="form-control" placeholder="Enter or select subject name" list="registeredSubjectsList" required autocomplete="off">
                                        <datalist id="registeredSubjectsList">
                                            <?php foreach ($availableSubjects as $subj): ?>
                                                <option value="<?php echo htmlspecialchars($subj['subject_name']); ?>" data-category="<?php echo htmlspecialchars($subj['subject_category'] ?? ''); ?>" data-track="<?php echo htmlspecialchars($subj['track'] ?? ''); ?>" data-grade="<?php echo htmlspecialchars((string)($subj['grade_level'] ?? '')); ?>"><?php echo htmlspecialchars($subj['subject_code'] . ' - ' . $subj['subject_name']); ?></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted">Type custom name or choose from standard DepEd subjects to auto-fill category.</small>
                                    </div>
                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">Subject Category <span class="text-danger">*</span></label>
                                        <select name="subject_category" id="subjectCategorySelect" class="form-select" onchange="onSubjectCategoryChange(this)" required>
                                            <option value="">Select Category</option>
                                            <option value="core">Core Subject</option>
                                            <option value="academic_elective">Academic Elective / Specialized</option>
                                            <option value="research">Research / Innovation</option>
                                            <option value="work_immersion">Work Immersion / Culminating</option>
                                            <option value="techpro_elective">TechPro Elective</option>
                                            <option value="tvl_immersion">Apprenticeship / TechPro Immersion</option>
                                            <option value="field_experience_elective">Field Experience / Sports & Arts</option>
                                        </select>
                                        <small class="text-muted">Auto-configures default weights per DepEd Order No. 8, s. 2015.</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="gradeLevel" class="form-select" required onchange="updateSections()">
                                            <option value="">Select Grade Level</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Track</label>
                                        <select name="track" id="classTrack" class="form-select" onchange="updateSections()">
                                            <option value="">All / Select Track</option>
                                            <option value="academic">Academic</option>
                                            <option value="techpro">TechPro</option>
                                        </select>
                                        <small class="text-muted">Grade 11 Academic maps to Academic Track; TechPro maps to Technical Professional.</small>
                                    </div>
                                </div>
                                <div class="row mb-1">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label mb-0 fw-semibold">Target Section(s) <span class="text-danger">*</span></label>
                                            <div id="sectionSelectAllWrap" style="display: none;">
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="selectAllSectionsBtn" style="font-size: 11px;" onclick="toggleAllSections()">Select All</button>
                                            </div>
                                        </div>
                                        <div id="sectionCheckboxesContainer" class="p-3 border rounded bg-light d-flex flex-wrap gap-2 align-items-center" style="min-height: 50px;">
                                            <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Please select Grade Level first to view sections.</span>
                                        </div>
                                        <small class="text-muted d-block mt-1">Check one or multiple sections to create this subject class for all of them at once.</small>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12 mb-0">
                                        <label class="form-label">Assigned Teacher</label>
                                        <select name="teacher_id" id="teacherSelect" class="form-select">
                                            <option value="">-- No Teacher Assigned --</option>
                                            <?php foreach ($availableTeachers as $t): ?>
                                                <option value="<?php echo (int)$t['id']; ?>">
                                                    <?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name'] . ($t['email'] ? ' (' . $t['email'] . ')' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Select an active teacher to assign to this subject class (optional).</small>
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <div class="app-modal-panel-title mb-0"><i class="bi bi-calendar-week"></i>Schedule and Room</div>
                                    <div class="btn-group btn-group-sm" role="group" id="scheduleModeGroup">
                                        <input type="radio" class="btn-check" name="schedule_mode" id="schedModePerSection" value="per_section" autocomplete="off" checked onchange="onScheduleModeChange()">
                                        <label class="btn btn-outline-primary" for="schedModePerSection" title="Configure individual schedule and room per selected section"><i class="bi bi-collection me-1"></i>Per Section</label>

                                        <input type="radio" class="btn-check" name="schedule_mode" id="schedModeUniform" value="uniform" autocomplete="off" onchange="onScheduleModeChange()">
                                        <label class="btn btn-outline-secondary" for="schedModeUniform" title="Use the same schedule for all selected sections"><i class="bi bi-intersect me-1"></i>Same for All</label>

                                        <input type="radio" class="btn-check" name="schedule_mode" id="schedModeTba" value="tba" autocomplete="off" onchange="onScheduleModeChange()">
                                        <label class="btn btn-outline-secondary" for="schedModeTba" title="Mark schedule as TBA and configure later"><i class="bi bi-clock-history me-1"></i>Set Later (TBA)</label>
                                    </div>
                                </div>

                                <!-- Set Later (TBA) Container -->
                                <div id="schedTbaContainer" class="p-3 border rounded bg-light text-center" style="display: none;">
                                    <div class="text-muted"><i class="bi bi-clock-history text-primary fs-3 d-block mb-1"></i>Classes will be created with schedule marked as <strong>TBA</strong>. You can configure individual section schedules anytime later in <em>Edit Class</em>.</div>
                                    <div class="mt-2">
                                        <label class="form-label small text-muted">Default Room (Optional)</label>
                                        <input type="text" id="addRoomTbaInput" class="form-control form-control-sm text-center mx-auto" style="max-width: 250px;" placeholder="e.g., Room 101 or TBA">
                                    </div>
                                </div>

                                <!-- Uniform Schedule Container (Single schedule used for all selected sections) -->
                                <div id="schedUniformContainer" style="display: none;">
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

                                <!-- Per-Section Schedule Container (Dedicated tabs per selected section) -->
                                <div id="schedPerSectionContainer">
                                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                        <ul class="nav nav-pills nav-fill p-1 bg-light rounded border flex-grow-1" id="sectionSchedTabs" role="tablist" style="gap: 4px;">
                                            <!-- Dynamic section tabs -->
                                        </ul>
                                    </div>
                                    <div class="d-flex justify-content-end mb-2 gap-2">
                                        <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 11px;" onclick="copyFirstSectionScheduleToAll()" title="Copy first section time slots to all sections">
                                            <i class="bi bi-copy me-1"></i>Copy 1st Tab to All
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 11px;" onclick="staggerSectionSchedules()" title="Shift start and end hours by +1 hour across sections">
                                            <i class="bi bi-clock-history me-1"></i>Stagger Times (+1 hr)
                                        </button>
                                    </div>
                                    <div class="tab-content border rounded p-3 bg-white" id="sectionSchedTabContent">
                                        <!-- Dynamic tab panes per section -->
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="app-modal-panel-title mb-0"><i class="bi bi-calculator"></i>Grading Weights</div>
                                    <span id="weightSumBadge" class="badge bg-success">Total: 100%</span>
                                </div>
                                <p class="app-modal-panel-copy">Auto-configured based on the selected category, or customize percentage weights manually. Combined total must equal 100%.</p>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Written Work (WW %)</label>
                                        <input type="number" name="ww_weight" class="form-control" value="25" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Performance Task (PT %)</label>
                                        <input type="number" name="pt_weight" class="form-control" value="50" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted">Assessment (QA %)</label>
                                        <input type="number" name="assessment_weight" class="form-control" value="25" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
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

    <!-- Grouped Class Sections Modal -->
    <div class="modal fade" id="classGroupSectionsModal" tabindex="-1" aria-labelledby="groupModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-collection"></i>Grouped Subject Class</div>
                        <h5 class="modal-title mb-0" id="groupModalTitle">Class Sections</h5>
                        <p class="app-modal-subtitle" id="groupModalSubtitle">Select a section to view details, update settings, or manage grades and attendance.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded border app-group-meta-bar">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary px-2 py-1" id="groupModalSectionCountBadge">0 Sections</span>
                            <span class="badge bg-secondary-subtle text-dark border px-2 py-1" id="groupModalTotalStudentsBadge">0 Total Students</span>
                        </div>
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Each section maintains a separate database record</small>
                    </div>
                    <div id="groupModalSectionsList" class="d-flex flex-column gap-2">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
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

            const container = document.getElementById('sectionCheckboxesContainer');
            if (container) {
                container.innerHTML = '<span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Please select Grade Level first to view sections.</span>';
            }
            const toggleWrap = document.getElementById('sectionSelectAllWrap');
            if (toggleWrap) toggleWrap.style.display = 'none';

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
            const gradeLevel = document.getElementById('gradeLevel')?.value;
            const track = document.getElementById('classTrack')?.value;
            const container = document.getElementById('sectionCheckboxesContainer');
            const toggleWrap = document.getElementById('sectionSelectAllWrap');
            if (!container) return;

            container.innerHTML = '';

            if (gradeLevel) {
                const list = getSectionsFor(gradeLevel, track);
                if (list.length) {
                    if (toggleWrap) toggleWrap.style.display = 'block';
                    list.forEach((section, idx) => {
                        const pill = document.createElement('div');
                        pill.className = 'form-check form-check-inline m-0 border px-3 py-1 rounded shadow-sm app-section-pill';
                        pill.innerHTML = `
                            <input class="form-check-input section-checkbox" type="checkbox" name="sections[]" value="${section.value}" id="sec_cb_${idx}" onchange="renderSectionScheduleTabs()">
                            <label class="form-check-label fw-medium ms-1" for="sec_cb_${idx}" style="cursor: pointer;">
                                ${section.label}
                            </label>
                        `;
                        container.appendChild(pill);
                    });
                    if (list.length === 1) {
                        const singleCb = container.querySelector('.section-checkbox');
                        if (singleCb) singleCb.checked = true;
                    }
                    const btn = document.getElementById('selectAllSectionsBtn');
                    if (btn) btn.textContent = 'Select All';
                } else {
                    if (toggleWrap) toggleWrap.style.display = 'none';
                    container.innerHTML = '<span class="text-muted small"><i class="bi bi-exclamation-circle me-1"></i>No sections found for this Grade & Track.</span>';
                }
            } else {
                if (toggleWrap) toggleWrap.style.display = 'none';
                container.innerHTML = '<span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Please select Grade Level first to view sections.</span>';
            }

            renderSectionScheduleTabs();
        }

        function toggleAllSections() {
            const checkboxes = document.querySelectorAll('.section-checkbox');
            if (!checkboxes.length) return;
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
            const btn = document.getElementById('selectAllSectionsBtn');
            if (btn) btn.textContent = allChecked ? 'Select All' : 'Deselect All';
            renderSectionScheduleTabs();
        }

        document.addEventListener('DOMContentLoaded', function () {
            hydrateSectionsCache(serverSections);
            loadSectionsCache();
        });
        
        const weightPresets = {
            core: { ww: 25, pt: 50, qa: 25 },
            academic_elective: { ww: 25, pt: 45, qa: 30 },
            research: { ww: 40, pt: 60, qa: 0 },
            work_immersion: { ww: 20, pt: 60, qa: 20 },
            techpro_elective: { ww: 20, pt: 60, qa: 20 },
            tvl_immersion: { ww: 20, pt: 80, qa: 0 },
            field_experience_elective: { ww: 15, pt: 65, qa: 20 },
        };

        function onSubjectCategoryChange(select) {
            const category = select?.value;
            const preset = weightPresets[category];
            const form = select?.closest('form');
            if (preset && form) {
                const wwInput = form.querySelector('[name="ww_weight"]');
                const ptInput = form.querySelector('[name="pt_weight"]');
                const qaInput = form.querySelector('[name="assessment_weight"]');
                if (wwInput) wwInput.value = preset.ww;
                if (ptInput) ptInput.value = preset.pt;
                if (qaInput) qaInput.value = preset.qa;
            }
            if (form) {
                updateWeightTotal(form);
            }
        }

        function updateWeightTotal(form) {
            if (!form) return;
            const ww = parseFloat(form.querySelector('[name="ww_weight"]')?.value || '0') || 0;
            const pt = parseFloat(form.querySelector('[name="pt_weight"]')?.value || '0') || 0;
            const qa = parseFloat(form.querySelector('[name="assessment_weight"]')?.value || '0') || 0;
            const total = Math.round((ww + pt + qa) * 100) / 100;
            const badge = form.querySelector('#weightSumBadge') || document.getElementById('weightSumBadge');
            if (badge) {
                if (Math.abs(total - 100) < 0.01) {
                    badge.className = 'badge bg-success';
                    badge.textContent = `Total: ${total}% (Valid)`;
                } else {
                    badge.className = 'badge bg-danger';
                    badge.textContent = `Total: ${total}% (Must equal 100%)`;
                }
            }
        }

        function toTitleCaseWords(value) {
            if (!value) return '';
            return value.replace(/\b\w/g, ch => ch.toUpperCase());
        }

        const registeredSubjects = <?php echo json_encode($availableSubjects ?? []); ?>;
        const addSubjectNameInput = document.getElementById('addSubjectNameInput');
        if (addSubjectNameInput) {
            addSubjectNameInput.addEventListener('input', function () {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = toTitleCaseWords(this.value);
                this.setSelectionRange(start, end);
            });
            addSubjectNameInput.addEventListener('change', function () {
                const val = (this.value || '').trim().toLowerCase();
                if (!val || !Array.isArray(registeredSubjects)) return;
                const match = registeredSubjects.find(s => (s.subject_name || '').toLowerCase() === val || (s.subject_code || '').toLowerCase() === val);
                if (match) {
                    this.value = match.subject_name;
                    const catSelect = document.getElementById('subjectCategorySelect');
                    if (catSelect && match.subject_category) {
                        catSelect.value = match.subject_category;
                        onSubjectCategoryChange(catSelect);
                    }
                    const trackSelect = document.getElementById('classTrack');
                    if (trackSelect && match.track) {
                        trackSelect.value = match.track;
                        updateSections();
                    }
                    const gradeSelect = document.getElementById('gradeLevel');
                    if (gradeSelect && match.grade_level && !gradeSelect.value) {
                        gradeSelect.value = String(match.grade_level);
                        updateSections();
                    }
                }
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

        function onScheduleModeChange() {
            const mode = document.querySelector('input[name="schedule_mode"]:checked')?.value || 'per_section';
            const perSecWrap = document.getElementById('schedPerSectionContainer');
            const uniformWrap = document.getElementById('schedUniformContainer');
            const tbaWrap = document.getElementById('schedTbaContainer');

            if (perSecWrap) perSecWrap.style.display = mode === 'per_section' ? 'block' : 'none';
            if (uniformWrap) uniformWrap.style.display = mode === 'uniform' ? 'block' : 'none';
            if (tbaWrap) tbaWrap.style.display = mode === 'tba' ? 'block' : 'none';

            if (mode === 'per_section') {
                renderSectionScheduleTabs();
            }
        }

        function getCleanSectionId(sectionName) {
            return 'sec_' + String(sectionName || '').replace(/[^a-zA-Z0-9_-]/g, '_');
        }

        function renderSectionScheduleTabs() {
            const checkedBoxes = Array.from(document.querySelectorAll('.section-checkbox:checked'));
            const tabsContainer = document.getElementById('sectionSchedTabs');
            const contentContainer = document.getElementById('sectionSchedTabContent');
            if (!tabsContainer || !contentContainer) return;

            if (checkedBoxes.length === 0) {
                tabsContainer.innerHTML = '<li class="nav-item"><span class="nav-link disabled text-muted small">No sections selected</span></li>';
                contentContainer.innerHTML = '<div class="text-muted small text-center py-3"><i class="bi bi-info-circle me-1"></i>Check at least one target section above to configure its schedule.</div>';
                return;
            }

            const activeTabSection = tabsContainer.querySelector('.nav-link.active')?.dataset?.section || checkedBoxes[0].value;
            tabsContainer.innerHTML = '';

            checkedBoxes.forEach((cb, index) => {
                const secName = cb.value;
                const cleanId = getCleanSectionId(secName);
                const isActive = (secName === activeTabSection) || (index === 0 && !checkedBoxes.some(c => c.value === activeTabSection));

                const li = document.createElement('li');
                li.className = 'nav-item';
                li.innerHTML = `
                    <button class="nav-link py-1 px-3 ${isActive ? 'active' : ''}" id="tab-btn-${cleanId}" data-bs-toggle="pill" data-bs-target="#tab-pane-${cleanId}" type="button" role="tab" data-section="${escapeHtml(secName)}">
                        <i class="bi bi-person-video3 me-1"></i>${escapeHtml(secName)}
                    </button>
                `;
                tabsContainer.appendChild(li);

                let existingPane = document.getElementById(`tab-pane-${cleanId}`);
                if (!existingPane) {
                    const pane = document.createElement('div');
                    pane.className = `tab-pane fade ${isActive ? 'show active' : ''}`;
                    pane.id = `tab-pane-${cleanId}`;
                    pane.setAttribute('role', 'tabpanel');
                    pane.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-semibold text-primary"><i class="bi bi-calendar3 me-1"></i>Schedule for Section ${escapeHtml(secName)}</h6>
                        </div>
                        <div class="row g-2 align-items-end schedule-header-row">
                            <div class="col-12 col-sm-3"><div class="form-label small text-muted mb-0">Day</div></div>
                            <div class="col-12 col-sm-4"><div class="form-label small text-muted mb-0">Start</div></div>
                            <div class="col-12 col-sm-4"><div class="form-label small text-muted mb-0">End</div></div>
                            <div class="col-12 col-sm-1"></div>
                        </div>
                        <div id="secScheduleRows_${cleanId}" class="d-flex flex-column gap-2 mt-1 sec-schedule-rows" data-section="${escapeHtml(secName)}"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addSectionScheduleRow('${escapeHtml(secName)}')">
                            <i class="bi bi-plus-circle me-1"></i>Add time slot for ${escapeHtml(secName)}
                        </button>
                        <div class="mt-3 mb-0">
                            <label class="form-label">Room for Section ${escapeHtml(secName)}</label>
                            <input type="text" id="secRoomInput_${cleanId}" class="form-control form-control-sm sec-room-input" data-section="${escapeHtml(secName)}" placeholder="e.g., Room 101">
                        </div>
                    `;
                    contentContainer.appendChild(pane);
                    addSectionScheduleRow(secName);
                } else {
                    existingPane.className = `tab-pane fade ${isActive ? 'show active' : ''}`;
                }
            });

            // Clean up panes for unchecked sections
            Array.from(contentContainer.children).forEach(pane => {
                const paneId = pane.id;
                const stillChecked = checkedBoxes.some(cb => `tab-pane-${getCleanSectionId(cb.value)}` === paneId);
                if (!stillChecked) {
                    pane.remove();
                }
            });
        }

        function addSectionScheduleRow(secName, row = {}) {
            const cleanId = getCleanSectionId(secName);
            const container = document.getElementById(`secScheduleRows_${cleanId}`);
            if (!container) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'row g-2 align-items-center schedule-row';
            wrapper.innerHTML = `
                <div class="col-12 col-sm-3">
                    <select class="form-select form-select-sm schedule-day" data-field="day">
                        <option value="">Select</option>
                        <option value="Mon">Mon</option>
                        <option value="Tue">Tue</option>
                        <option value="Wed">Wed</option>
                        <option value="Thu">Thu</option>
                        <option value="Fri">Fri</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="input-group input-group-sm schedule-time-group">
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="start_hour" placeholder="HH">
                        <span class="input-group-text">:</span>
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="start_min" placeholder="MM">
                        <select class="form-select schedule-time" data-field="start_ampm" style="min-width: 76px; max-width: 76px;">
                            <option value="AM">AM</option>
                            <option value="PM">PM</option>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="input-group input-group-sm schedule-time-group">
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="end_hour" placeholder="HH">
                        <span class="input-group-text">:</span>
                        <input type="text" inputmode="numeric" pattern="\\d*" maxlength="2" class="form-control schedule-time" data-field="end_min" placeholder="MM">
                        <select class="form-select schedule-time" data-field="end_ampm" style="min-width: 76px; max-width: 76px;">
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

        function collectRowsFromContainer(container) {
            if (!container) return [];
            const rows = [];
            container.querySelectorAll('.schedule-row').forEach(row => {
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

        function copyFirstSectionScheduleToAll() {
            const checkedBoxes = Array.from(document.querySelectorAll('.section-checkbox:checked'));
            if (checkedBoxes.length < 2) {
                showNotification('Select at least 2 sections to copy schedule across them', 'info');
                return;
            }
            const firstSec = checkedBoxes[0].value;
            const firstContainer = document.getElementById(`secScheduleRows_${getCleanSectionId(firstSec)}`);
            const firstRoom = document.getElementById(`secRoomInput_${getCleanSectionId(firstSec)}`)?.value || '';
            const firstRows = collectRowsFromContainer(firstContainer);

            if (firstRows.length === 0) {
                showNotification(`Please fill in at least one schedule row for ${firstSec} first`, 'warning');
                return;
            }

            checkedBoxes.slice(1).forEach(cb => {
                const secName = cb.value;
                const cleanId = getCleanSectionId(secName);
                const targetCont = document.getElementById(`secScheduleRows_${cleanId}`);
                if (targetCont) {
                    targetCont.innerHTML = '';
                    firstRows.forEach(r => addSectionScheduleRow(secName, r));
                }
                const roomInp = document.getElementById(`secRoomInput_${cleanId}`);
                if (roomInp && !roomInp.value) {
                    roomInp.value = firstRoom;
                }
            });

            showNotification(`Schedule from ${firstSec} copied to all sections`, 'success');
        }

        function staggerSectionSchedules() {
            const checkedBoxes = Array.from(document.querySelectorAll('.section-checkbox:checked'));
            if (checkedBoxes.length < 2) {
                showNotification('Select at least 2 sections to stagger schedules', 'info');
                return;
            }
            const firstSec = checkedBoxes[0].value;
            const firstContainer = document.getElementById(`secScheduleRows_${getCleanSectionId(firstSec)}`);
            const firstRoom = document.getElementById(`secRoomInput_${getCleanSectionId(firstSec)}`)?.value || '';
            const firstRows = collectRowsFromContainer(firstContainer);

            if (firstRows.length === 0) {
                showNotification(`Please set schedule rows for ${firstSec} first`, 'warning');
                return;
            }

            checkedBoxes.forEach((cb, index) => {
                if (index === 0) return;
                const secName = cb.value;
                const cleanId = getCleanSectionId(secName);
                const targetCont = document.getElementById(`secScheduleRows_${cleanId}`);
                if (targetCont) {
                    targetCont.innerHTML = '';
                    firstRows.forEach(r => {
                        const shift = (h, ampm, offset) => {
                            let hour24 = parseInt(h || '0', 10);
                            if (ampm === 'PM' && hour24 !== 12) hour24 += 12;
                            if (ampm === 'AM' && hour24 === 12) hour24 = 0;
                            hour24 = (hour24 + offset) % 24;
                            const newAmPm = hour24 >= 12 ? 'PM' : 'AM';
                            let newHour = hour24 % 12;
                            if (newHour === 0) newHour = 12;
                            return { hour: String(newHour), ampm: newAmPm };
                        };

                        const sShift = shift(r.start_hour, r.start_ampm, index);
                        const eShift = shift(r.end_hour, r.end_ampm, index);

                        addSectionScheduleRow(secName, {
                            day: r.day,
                            start_hour: sShift.hour,
                            start_min: r.start_min,
                            start_ampm: sShift.ampm,
                            end_hour: eShift.hour,
                            end_min: r.end_min,
                            end_ampm: eShift.ampm
                        });
                    });
                }
                const roomInp = document.getElementById(`secRoomInput_${cleanId}`);
                if (roomInp && !roomInp.value) {
                    roomInp.value = firstRoom;
                }
            });

            showNotification('Section schedules staggered by +' + 1 + ' hour per section', 'success');
        }

        function collectPerSectionSchedules(sections) {
            const map = {};
            sections.forEach(secName => {
                const cleanId = getCleanSectionId(secName);
                const container = document.getElementById(`secScheduleRows_${cleanId}`);
                const roomInput = document.getElementById(`secRoomInput_${cleanId}`);
                map[secName] = {
                    schedule_rows: collectRowsFromContainer(container),
                    room: (roomInput?.value || '').trim()
                };
            });
            return map;
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
                        <select class="form-select schedule-time" data-field="start_ampm" style="min-width: 76px; max-width: 76px;">
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
                        <select class="form-select schedule-time" data-field="end_ampm" style="min-width: 76px; max-width: 76px;">
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

        if (addClassModalEl) {
            addClassModalEl.addEventListener('input', function (event) {
                sanitizeScheduleNumericInput(event.target);
            });
        }

        function collectScheduleRows() {
            return collectRowsFromContainer(document.getElementById('scheduleRows'));
        }

        const groupedClassesData = <?php echo json_encode($groupedClasses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function openGroupedClassModal(index) {
            const group = groupedClassesData[index];
            if (!group) return;

            const modalTitle = document.getElementById('groupModalTitle');
            const modalSubtitle = document.getElementById('groupModalSubtitle');
            const countBadge = document.getElementById('groupModalSectionCountBadge');
            const studentBadge = document.getElementById('groupModalTotalStudentsBadge');
            const sectionsList = document.getElementById('groupModalSectionsList');

            if (modalTitle) modalTitle.textContent = group.class_name;
            if (modalSubtitle) {
                let sub = `Grade ${group.grade_level}`;
                if (group.subject_category) {
                    sub += ` · ${group.subject_category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}`;
                }
                if (group.track) {
                    sub += ` · ${group.track.charAt(0).toUpperCase() + group.track.slice(1)} Track`;
                }
                modalSubtitle.textContent = sub;
            }
            if (countBadge) {
                countBadge.textContent = `${group.sections.length} Sections`;
            }
            if (studentBadge) {
                studentBadge.textContent = `${group.total_students} Total Students`;
            }

            if (sectionsList) {
                sectionsList.innerHTML = '';
                group.sections.forEach(sec => {
                    const row = document.createElement('div');
                    row.className = 'p-3 border rounded bg-white d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm app-group-section-item';
                    row.innerHTML = `
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; font-size: 18px;">
                                <i class="bi bi-person-video3"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">Section: ${escapeHtml(sec.section)}</h6>
                                <div class="small text-muted d-flex flex-wrap gap-2">
                                    <span><i class="bi bi-people me-1"></i><strong>${sec.student_count}</strong> students</span>
                                    <span><i class="bi bi-person me-1"></i>${escapeHtml(sec.teacher_name)}</span>
                                    <span><i class="bi bi-clock me-1"></i>${escapeHtml(sec.schedule)}</span>
                                    <span><i class="bi bi-geo-alt me-1"></i>${escapeHtml(sec.room)}</span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-shrink-0">
                            <a href="admin_Class_Detail.php?id=${sec.id}" class="btn btn-sm btn-primary-custom" title="View Class Details for Section ${escapeHtml(sec.section)}">
                                <i class="bi bi-eye me-1"></i>View Details
                            </a>
                            <a href="admin_Class_Edit.php?id=${sec.id}" class="btn btn-sm btn-outline-secondary" title="Edit Class for Section ${escapeHtml(sec.section)}">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSectionFromGroupModal(${sec.id}, '${escapeHtml(group.class_name)}', '${escapeHtml(sec.section)}')" title="Delete Class for Section ${escapeHtml(sec.section)}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    `;
                    sectionsList.appendChild(row);
                });
            }

            const modalEl = document.getElementById('classGroupSectionsModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.show();
            }
        }

        function deleteSectionFromGroupModal(id, className, sectionName) {
            const modalEl = document.getElementById('classGroupSectionsModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            deleteClass(id, `${className} (Section ${sectionName})`);
        }

        window.openGroupedClassModal = openGroupedClassModal;
        window.deleteSectionFromGroupModal = deleteSectionFromGroupModal;

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
            
            const checkedSections = Array.from(document.querySelectorAll('.section-checkbox:checked')).map(cb => cb.value.trim()).filter(Boolean);

            if (!className || !gradeLevel || checkedSections.length === 0) {
                showNotification('Subject name, grade level, and at least one target section are required', 'warning');
                return;
            }

            formData.set('class_name', className);
            formData.delete('sections[]');
            checkedSections.forEach(sec => formData.append('sections[]', sec));
            formData.set('section', checkedSections[0]);

            const ww = parseFloat(formData.get('ww_weight') || '0');
            const pt = parseFloat(formData.get('pt_weight') || '0');
            const qa = parseFloat(formData.get('assessment_weight') || '0');
            if (Math.abs((ww + pt + qa) - 100) > 0.01) {
                showNotification('Grading weights must total 100%', 'warning');
                return;
            }

            const scheduleMode = document.querySelector('input[name="schedule_mode"]:checked')?.value || 'per_section';
            formData.set('schedule_mode', scheduleMode);

            if (scheduleMode === 'tba') {
                formData.set('room', document.getElementById('addRoomTbaInput')?.value?.trim() || 'TBA');
                formData.delete('schedule_rows');
                formData.delete('section_schedules');
            } else if (scheduleMode === 'uniform') {
                const scheduleRows = collectScheduleRows();
                if (scheduleRows.length === 0) {
                    showNotification('Please add at least one schedule row for uniform schedule', 'warning');
                    return;
                }
                formData.set('schedule_rows', JSON.stringify(scheduleRows));
                formData.set('room', document.getElementById('addRoomInput')?.value?.trim() || '');
            } else {
                // per_section mode
                const perSecMap = collectPerSectionSchedules(checkedSections);
                let missingSec = '';
                for (const sec of checkedSections) {
                    if (!perSecMap[sec] || !perSecMap[sec].schedule_rows || perSecMap[sec].schedule_rows.length === 0) {
                        missingSec = sec;
                        break;
                    }
                }
                if (missingSec) {
                    showNotification(`Please add at least one schedule row for section "${missingSec}" (or choose TBA mode)`, 'warning');
                    return;
                }
                formData.set('section_schedules', JSON.stringify(perSecMap));
            }

            if (createClassBtn) {
                createClassBtn.disabled = true;
                createClassBtn.textContent = checkedSections.length > 1 ? `Creating (${checkedSections.length} Sections)...` : 'Creating Class...';
            }
            
            fetch('admin_Classes_Action.php?action=create', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Class created successfully', 'success');
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








