<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Edit Class Page
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$classId = $_GET['id'] ?? '';
$hasTrack = false;

if (empty($classId) || !$db) {
    header("Location: admin_Classes.php");
    exit();
}

// Fetch class details
$hasSubjectCategory = dbHasColumn($db, 'classes', 'subject_category');
$subjectCategorySelect = $hasSubjectCategory ? ", subject_category" : ", NULL AS subject_category";
$hasTrack = dbHasColumn($db, 'classes', 'track');
$trackSelect = $hasTrack ? ", track" : ", NULL AS track";
$hasCurriculum = dbHasColumn($db, 'classes', 'curriculum');
$curriculumSelect = $hasCurriculum ? ", curriculum" : ", NULL AS curriculum";
$hasProgram = dbHasColumn($db, 'classes', 'program');
$programSelect = $hasProgram ? ", program" : ", NULL AS program";
$classQuery = "SELECT id, class_name, grade_level, section, teacher_id, schedule, room, ww_weight, pt_weight, assessment_weight, status
               $subjectCategorySelect
               $trackSelect
               $curriculumSelect
               $programSelect
               FROM classes
               WHERE id = ? AND status = 'active'";
$stmt = $db->prepare($classQuery);
$stmt->execute([$classId]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: admin_Classes.php");
    exit();
}

$availableTeachers = [];
$teacherStmt = $db->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY last_name ASC, first_name ASC");
if ($teacherStmt) {
    $availableTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$assignedTeacherId = (int)($class['teacher_id'] ?? 0);
if ($assignedTeacherId <= 0) {
    $csCheck = $db->prepare("SELECT teacher_id FROM class_subjects WHERE class_id = ? LIMIT 1");
    $csCheck->execute([(int)$classId]);
    $assignedTeacherId = (int)$csCheck->fetchColumn();
}

$scheduleRows = [];
$hasScheduleTable = dbHasTable($db, 'class_schedules');
if ($hasScheduleTable) {
    $scheduleStmt = $db->prepare("SELECT day, start_time, end_time
                                  FROM class_schedules
                                  WHERE class_id = ?
                                  ORDER BY FIELD(day, 'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time");
    $scheduleStmt->execute([(int)$classId]);
    $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_role = 'admin';
$current_page = 'classes';
$page_title = 'Edit Class - ' . $class['class_name'];
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
    </style>
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <!-- Back Button -->
            <a href="admin_Class_Detail.php?id=<?php echo $classId; ?>" class="btn btn-link text-decoration-none mb-3">
                <i class="bi bi-arrow-left me-2"></i>Back to Class Details
            </a>

            <!-- Page Header -->
            <div class="mb-4">
                <h4 class="mb-1">Edit Class</h4>
                <p class="text-muted mb-0">Update class information</p>
            </div>

            <!-- Edit Form -->
            <div class="content-card">
                <div class="content-card-body">
                    <form id="editClassForm">
                        <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Class Name <span class="text-danger">*</span></label>
                            <input type="text" name="class_name" class="form-control" value="<?php echo htmlspecialchars($class['class_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject Category <span class="text-danger">*</span></label>
                            <select name="subject_category" class="form-select" onchange="onSubjectCategoryChange(this)" required>
                                <option value="">Select Category</option>
                                <option value="core" <?php echo (($class['subject_category'] ?? '') === 'core') ? 'selected' : ''; ?>>Core Subject</option>
                                <option value="academic_elective" <?php echo (($class['subject_category'] ?? '') === 'academic_elective') ? 'selected' : ''; ?>>Academic Elective / Specialized</option>
                                <option value="research" <?php echo (($class['subject_category'] ?? '') === 'research') ? 'selected' : ''; ?>>Research / Innovation</option>
                                <option value="work_immersion" <?php echo (($class['subject_category'] ?? '') === 'work_immersion') ? 'selected' : ''; ?>>Work Immersion / Culminating</option>
                                <option value="techpro_elective" <?php echo (($class['subject_category'] ?? '') === 'techpro_elective') ? 'selected' : ''; ?>>TechPro Elective</option>
                                <option value="tvl_immersion" <?php echo (($class['subject_category'] ?? '') === 'tvl_immersion') ? 'selected' : ''; ?>>Apprenticeship / TechPro Immersion</option>
                                <option value="field_experience_elective" <?php echo (($class['subject_category'] ?? '') === 'field_experience_elective') ? 'selected' : ''; ?>>Field Experience / Sports & Arts</option>
                            </select>
                            <small class="text-muted">Auto-configures default weights per DepEd Order No. 8, s. 2015.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Track</label>
                            <select name="track" id="classTrack" class="form-select" onchange="updateSections()">
                                <option value="">Select Track</option>
                                <option value="academic" <?php echo (($class['track'] ?? '') === 'academic') ? 'selected' : ''; ?>>Academic Track (Strengthened)</option>
                                <option value="techpro" <?php echo (($class['track'] ?? '') === 'techpro') ? 'selected' : ''; ?>>Technical Professional</option>
                            </select>
                            <small class="text-muted">For Grade 11, this maps to the Strengthened SHS program.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                                <select name="grade_level" id="gradeLevel" class="form-select" required onchange="updateSections()">
                                    <option value="">Select</option>
                                    <option value="11" <?php echo $class['grade_level'] == '11' ? 'selected' : ''; ?>>Grade 11</option>
                                    <option value="12" <?php echo $class['grade_level'] == '12' ? 'selected' : ''; ?>>Grade 12</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Section <span class="text-danger">*</span></label>
                                <select name="section" id="sectionSelect" class="form-select" required>
                                    <option value="">Select Grade & Track First</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assigned Teacher</label>
                            <select name="teacher_id" id="editTeacherSelect" class="form-select">
                                <option value="">-- No Teacher Assigned --</option>
                                <?php foreach ($availableTeachers as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $assignedTeacherId === (int)$t['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['last_name'] . ', ' . $t['first_name'] . ($t['email'] ? ' (' . $t['email'] . ')' : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select an active teacher to assign to this subject class (optional).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Schedule</label>
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
                            <small class="text-muted d-block mt-2">Add one row per day/time (e.g., Mon 7:00-8:00, Tue 8:00-9:00).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Room</label>
                            <input type="text" id="editRoomInput" name="room" class="form-control" value="<?php echo htmlspecialchars($class['room']); ?>" placeholder="e.g., Room 101">
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">Grading Weights (%)</label>
                                <span id="weightSumBadge" class="badge bg-success">Total: 100%</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-4">
                                    <label class="form-label small text-muted">Written Work (WW %)</label>
                                    <input type="number" name="ww_weight" class="form-control" value="<?php echo htmlspecialchars($class['ww_weight'] ?? '25'); ?>" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small text-muted">Performance Task (PT %)</label>
                                    <input type="number" name="pt_weight" class="form-control" value="<?php echo htmlspecialchars($class['pt_weight'] ?? '50'); ?>" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small text-muted">Assessment (QA %)</label>
                                    <input type="number" name="assessment_weight" class="form-control" value="<?php echo htmlspecialchars($class['assessment_weight'] ?? '25'); ?>" min="0" max="100" step="0.01" required oninput="updateWeightTotal(this.form)">
                                </div>
                            </div>
                            <small class="text-muted">Auto-configured or customize manually. Total must equal 100%.</small>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='admin_Class_Detail.php?id=<?php echo $classId; ?>'">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="updateClass()">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        let sectionsCache = {};

        function loadSectionsCache() {
            return fetch('admin_Sections_Action.php?action=list')
                .then(r => r.json())
                .then(data => {
                    sectionsCache = {};
                    if (data.success && data.sections) {
                        data.sections.forEach(s => {
                            const key = s.grade_level + '_' + (s.track || '');
                            if (!sectionsCache[key]) sectionsCache[key] = [];
                            const suffix = s.program === 'academic_strengthened' ? ' - Academic Track (Strengthened)' : (s.program === 'technical_professional' ? ' - Technical Professional' : '');
                            sectionsCache[key].push({ value: s.name, label: s.name + suffix });
                        });
                    }
                    return sectionsCache;
                })
                .catch(() => { sectionsCache = {}; });
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

        function autoCapitalizeFirstLetter(value) {
            if (!value) return '';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

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

        const editRoomInput = document.getElementById('editRoomInput');
        if (editRoomInput) {
            editRoomInput.addEventListener('input', function () {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = autoCapitalizeFirstLetter(this.value);
                this.setSelectionRange(start, end);
            });
        }
        
        // Current section and schedule from database
        const currentSection = '<?php echo $class['section']; ?>';
        const currentTrack = '<?php echo $class['track'] ?? ''; ?>';
        const currentSchedule = <?php echo json_encode((string)($class['schedule'] ?? '')); ?>;
        const scheduleRowsData = <?php echo json_encode($scheduleRows); ?>;
        
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
                        if (section.value === currentSection) {
                            option.selected = true;
                        }
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

        function parseTimeToParts(timeStr) {
            if (!timeStr) return null;
            const match = timeStr.trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
            if (!match) return null;
            let hour = parseInt(match[1], 10);
            const min = match[2];
            let ampm = 'AM';
            if (hour === 0) {
                hour = 12;
                ampm = 'AM';
            } else if (hour === 12) {
                ampm = 'PM';
            } else if (hour > 12) {
                hour -= 12;
                ampm = 'PM';
            }
            return { hour: String(hour), min: String(min), ampm };
        }

        function parseScheduleStringToRows(schedule) {
            if (!schedule) return [];
            const segments = schedule.split(';').map(s => s.trim()).filter(Boolean);
            const rows = [];
            segments.forEach(segment => {
                const match = segment.match(/^(.+?)\s+(\d{1,2}:\d{2}\s*(AM|PM))\s*-\s*(\d{1,2}:\d{2}\s*(AM|PM))$/i);
                if (!match) return;
                const daysPart = match[1];
                const start = match[2];
                const end = match[4];
                const dayTokens = daysPart.split(/[\/,]+/).map(d => d.trim()).filter(Boolean);
                dayTokens.forEach(day => {
                    const abbr = day.slice(0, 3);
                    const startParts = start.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
                    const endParts = end.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
                    if (!startParts || !endParts) return;
                    rows.push({
                        day: abbr.charAt(0).toUpperCase() + abbr.slice(1).toLowerCase(),
                        start_hour: startParts[1],
                        start_min: startParts[2],
                        start_ampm: startParts[3].toUpperCase(),
                        end_hour: endParts[1],
                        end_min: endParts[2],
                        end_ampm: endParts[3].toUpperCase()
                    });
                });
            });
            return rows;
        }

        function populateScheduleFields() {
            const container = document.getElementById('scheduleRows');
            if (container) {
                container.innerHTML = '';
            }

            if (Array.isArray(scheduleRowsData) && scheduleRowsData.length > 0) {
                scheduleRowsData.forEach(row => {
                    const startParts = parseTimeToParts(row.start_time);
                    const endParts = parseTimeToParts(row.end_time);
                    addScheduleRow({
                        day: row.day,
                        start_hour: startParts?.hour,
                        start_min: startParts?.min,
                        start_ampm: startParts?.ampm,
                        end_hour: endParts?.hour,
                        end_min: endParts?.min,
                        end_ampm: endParts?.ampm
                    });
                });
                return;
            }

            const fallbackRows = parseScheduleStringToRows(currentSchedule);
            if (fallbackRows.length > 0) {
                fallbackRows.forEach(r => addScheduleRow(r));
                return;
            }

            addScheduleRow();
        }

        // Initialize fields on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSectionsCache().then(() => {
                updateSections();
                buildSchedule();
            });
        });
        
        function updateClass() {
            const form = document.getElementById('editClassForm');
            const formData = new FormData(form);
            const ww = parseFloat(formData.get('ww_weight') || '0');
            const pt = parseFloat(formData.get('pt_weight') || '0');
            const qa = parseFloat(formData.get('assessment_weight') || '0');
            if (Math.abs((ww + pt + qa) - 100) > 0.01) {
                showNotification('Grading weights must total 100%', 'warning');
                return;
            }
            
            const scheduleRows = [];
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
                scheduleRows.push({
                    day,
                    start_hour: startHour,
                    start_min: startMin || '00',
                    start_ampm: startAmPm,
                    end_hour: endHour,
                    end_min: endMin || '00',
                    end_ampm: endAmPm
                });
            });

            if (scheduleRows.length === 0) {
                showNotification('Please add at least one schedule row', 'warning');
                return;
            }
            formData.append('schedule_rows', JSON.stringify(scheduleRows));
            
            fetch('admin_Classes_Action.php?action=update', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Class updated successfully', 'success');
                    setTimeout(() => window.location.href = 'admin_Class_Detail.php?id=<?php echo $classId; ?>', 1000);
                } else {
                    showNotification(data.message || 'Failed to update class', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error updating class', 'danger');
                console.error(error);
            });
        }
    </script>
<?php include '../includes/footer.php'; ?>








