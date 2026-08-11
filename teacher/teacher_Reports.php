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

require_once __DIR__ . '/teacher_Reports_Helper.php';
$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'teacher';
$current_page = 'reports';
$page_title = 'Reports';

$selectedType = $_GET['type'] ?? 'top_attendance';
$allowedTypes = ['top_attendance', 'class_summary', 'at_risk'];
if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'top_attendance';
}

$selectedAcademicYear = trim((string)($_GET['academic_year'] ?? trhCurrentAcademicYear()));
$selectedMode = trim((string)($_GET['mode'] ?? 'subject'));
$selectedMode = in_array($selectedMode, ['subject', 'advisory'], true) ? $selectedMode : 'subject';
$scope = trim((string)($_GET['scope'] ?? 'semester')); // semester|yearly
$selectedSemester = trim((string)($_GET['semester'] ?? 'S1'));
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$selectedSex = strtolower(trim((string)($_GET['sex'] ?? '')));
if (!in_array($selectedSex, ['male', 'female'], true)) {
    $selectedSex = '';
}
$topN = (int)($_GET['top_n'] ?? 10);
if (!preg_match('/^\d{4}-\d{4}$/', $selectedAcademicYear)) {
    $selectedAcademicYear = trhCurrentAcademicYear();
}
if (!in_array($scope, ['semester', 'yearly'], true)) {
    $scope = 'semester';
}
if (!in_array($selectedSemester, ['S1', 'S2'], true)) {
    $selectedSemester = 'S1';
}
$selectedSemesterNo = ($selectedSemester === 'S2') ? 2 : 1;
if ($topN < 1 || $topN > 100) {
    $topN = 10;
}

function teacherReportUrl($type, $mode, $academicYear, $scope, $semester, $classId, $topN, $sex) {
    return 'teacher_Reports.php?' . http_build_query([
        'type' => $type,
        'mode' => $mode,
        'academic_year' => $academicYear,
        'scope' => $scope,
        'semester' => $semester,
        'class_id' => $classId,
        'top_n' => $topN,
        'sex' => $sex
    ]);
}

function teacherExportUrl($type, $mode, $academicYear, $scope, $semester, $classId, $topN, $sex) {
    return 'teacher_Reports_Action.php?' . http_build_query([
        'action' => 'export',
        'type' => $type,
        'mode' => $mode,
        'academic_year' => $academicYear,
        'scope' => $scope,
        'semester' => $semester,
        'class_id' => $classId,
        'top_n' => $topN,
        'sex' => $sex
    ]);
}

[$dateFrom, $dateTo] = trhPeriodBounds($selectedAcademicYear, $scope, $selectedSemester);
$hasAttendanceTerms = false;

$kpis = [
    'attendance_records' => 0,
    'unique_students' => 0,
    'avg_rate' => 0,
    'classes' => 0
];
$classesForFilter = [];
$previewHeaders = [];
$previewRows = [];

if ($db) {
    $hasAttendanceTerms = trhHasAttendanceTerms($db);
    $advisoryInfo = trhFetchAdvisoryInfo($db, $teacherId);
    $classesForFilter = trhFetchClassesForFilter($db, $teacherId, $selectedMode, $advisoryInfo);
    $advisoryClassId = is_array($advisoryInfo) && ($advisoryInfo['id'] ?? 0) > 0 ? (int)$advisoryInfo['id'] : 0;
    $kpis['classes'] = count($classesForFilter);

    if (!empty($classesForFilter)) {
        $allowedIds = array_map('intval', array_column($classesForFilter, 'id'));
        if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedIds, true)) {
            $selectedClassId = 0;
        }

        [$where, $params] = trhBuildAttendanceScope(
            $teacherId,
            $selectedMode,
            $advisoryInfo,
            $hasAttendanceTerms,
            $selectedAcademicYear,
            $scope,
            $selectedSemesterNo,
            $dateFrom,
            $dateTo,
            $selectedClassId,
            $selectedSex
        );

        $kpiQuery = "SELECT COUNT(*) AS attendance_records, COUNT(DISTINCT a.student_id) AS unique_students,
                    SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_count,
                    SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) AS late_count
                    FROM attendance a
                    WHERE " . implode(' AND ', $where);
        $kpiStmt = $db->prepare($kpiQuery);
        $kpiStmt->execute($params);
        $kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpis['attendance_records'] = (int)($kpiRow['attendance_records'] ?? 0);
        $kpis['unique_students'] = (int)($kpiRow['unique_students'] ?? 0);
        $kpiPresent = (int)($kpiRow['present_count'] ?? 0);
        $kpiLate = (int)($kpiRow['late_count'] ?? 0);
        $kpiEffectiveLate = $kpiLate % 3;
        $kpis['avg_rate'] = $kpis['attendance_records'] > 0
            ? round((($kpiPresent + $kpiEffectiveLate) / $kpis['attendance_records']) * 100, 2)
            : 0;

        $aggregateRows = aggregateAttendanceReportRows($db, $selectedType, $where, $params, $topN);
        $aggregateTable = aggregateAttendanceReportTable($selectedType, $aggregateRows);
        $previewHeaders = $aggregateTable['headers'];
        $previewRows = $aggregateTable['rows'];
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
        <div class="teacher-hero mb-4">
            <div class="teacher-hero-grid">
                <div class="teacher-hero-copy">
                    <div class="app-detail-kicker mb-2">Reporting Workspace</div>
                    <h4 class="mb-2">Reports</h4>
                    <p class="mb-0">Generate attendance insights and review preview data before downloading report exports.</p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip"><i class="bi bi-journal-bookmark"></i><?php echo number_format((int)$kpis['classes']); ?> classes in scope</span>
                        <span class="teacher-chip"><i class="bi bi-people-fill"></i><?php echo number_format((int)$kpis['unique_students']); ?> students covered</span>
                        <span class="teacher-chip"><i class="bi bi-calendar-range"></i><?php echo htmlspecialchars($dateFrom . ' to ' . $dateTo); ?></span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Attendance Records</span>
                            <span class="teacher-summary-value"><?php echo number_format((int)$kpis['attendance_records']); ?></span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Average Rate</span>
                            <span class="teacher-summary-value"><?php echo number_format((float)$kpis['avg_rate'], 2); ?>%</span>
                        </div>
                    </div>
                    <p class="teacher-summary-note">Switch between subject and advisory scope, then export only after the preview matches the intended academic period.</p>
                </div>
            </div>
        </div>

        <div class="content-card mb-4">
            <div class="content-card-body">
                <form method="GET" action="teacher_Reports.php" id="teacherReportFilterForm" class="row g-3 align-items-end app-filter-panel app-responsive-filter-form">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($selectedType); ?>">
                    <input type="hidden" name="semester" id="semesterHiddenInput" value="<?php echo htmlspecialchars($selectedSemester); ?>">
                    <div class="col-md-2">
                        <label class="form-label">View</label>
                        <select name="mode" class="form-select">
                            <option value="subject" <?php echo $selectedMode === 'subject' ? 'selected' : ''; ?>>Subject</option>
                            <option value="advisory" <?php echo $selectedMode === 'advisory' ? 'selected' : ''; ?>>Advisory</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Academic Year</label>
                        <input type="text" class="form-control" name="academic_year" value="<?php echo htmlspecialchars($selectedAcademicYear); ?>" placeholder="YYYY-YYYY">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Scope</label>
                        <select name="scope" id="scopeSelect" class="form-select">
                            <option value="semester" <?php echo $scope === 'semester' ? 'selected' : ''; ?>>Semester</option>
                            <option value="yearly" <?php echo $scope === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select id="semesterSelect" class="form-select" <?php echo $scope === 'yearly' ? 'disabled' : ''; ?>>
                            <option value="S1" <?php echo $selectedSemester === 'S1' ? 'selected' : ''; ?>>S1</option>
                            <option value="S2" <?php echo $selectedSemester === 'S2' ? 'selected' : ''; ?>>S2</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-select">
                            <option value="0">All My Classes</option>
                            <?php foreach ($classesForFilter as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo $selectedClassId === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Sex</label>
                        <select name="sex" class="form-select">
                            <option value="" <?php echo $selectedSex === '' ? 'selected' : ''; ?>>All</option>
                            <option value="male" <?php echo $selectedSex === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $selectedSex === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="w-100"></div>
                    <div class="col-md-2">
                        <label class="form-label">Top</label>
                        <input type="number" min="1" max="100" name="top_n" class="form-control" value="<?php echo (int)$topN; ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary-custom w-100" id="teacherReportApplyBtn" type="submit">Apply</button>
                    </div>
                    <div class="col-md-2">
                        <a class="btn btn-secondary-custom w-100" href="teacher_Reports.php?type=<?php echo urlencode($selectedType); ?>&mode=<?php echo urlencode($selectedMode); ?>">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="teacher-overview-grid mb-4">
            <div class="teacher-overview-card">
                <strong><?php echo number_format((int)$kpis['attendance_records']); ?></strong>
                <span>Total Attendance Records</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format((int)$kpis['unique_students']); ?></strong>
                <span>Unique Students</span>
            </div>
            <div class="teacher-overview-card">
                <strong><?php echo number_format((float)$kpis['avg_rate'], 2); ?>%</strong>
                <span>Average Attendance Rate</span>
            </div>
        </div>

        <div class="content-card mb-4">
            <div class="content-card-header"><h5 class="content-card-title">DepEd Form Exports</h5></div>
            <div class="content-card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-5">
                        <label class="form-label small">Class</label>
                        <select id="reportTeacherSf2Class" class="form-select">
                            <option value="">Select class</option>
                            <?php foreach ($classesForFilter as $classItem): ?>
                                <option value="<?php echo (int)$classItem['id']; ?>">
                                    <?php echo htmlspecialchars('G' . (int)$classItem['grade_level'] . ' - ' . (string)$classItem['section'] . ' / ' . (string)$classItem['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small">Month</label>
                        <select id="reportTeacherSf2Month" class="form-select">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === (int)date('n') ? 'selected' : ''; ?>><?php echo date('M', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label small">Year</label>
                        <select id="reportTeacherSf2Year" class="form-select">
                            <?php for ($y = (int)date('Y') - 1; $y <= (int)date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === (int)date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-3">
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-success flex-fill" type="button" onclick="exportTeacherReportSf2('xlsx')"><i class="bi bi-filetype-xlsx me-1"></i>XLSX</button>
                            <button class="btn btn-outline-success flex-fill" type="button" onclick="exportTeacherReportSf2('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        </div>
                    </div>
                </div>
                <?php if (empty($classesForFilter)): ?>
                    <div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle me-2"></i>SF2 export will appear once you have assigned classes.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="report-card">
                    <div class="report-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);"><i class="bi bi-trophy"></i></div>
                    <h5 class="report-title">Top Attendance</h5>
                    <p class="report-desc">Best attendance ranking for awards</p>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary-custom w-100" href="<?php echo teacherExportUrl('top_attendance', $selectedMode, $selectedAcademicYear, $scope, $selectedSemester, $selectedClassId, $topN, $selectedSex); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="report-card">
                    <div class="report-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);"><i class="bi bi-journal-text"></i></div>
                    <h5 class="report-title">Class Summary</h5>
                    <p class="report-desc">Attendance summary per class</p>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary-custom w-100" href="<?php echo teacherExportUrl('class_summary', $selectedMode, $selectedAcademicYear, $scope, $selectedSemester, $selectedClassId, $topN, $selectedSex); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="report-card">
                    <div class="report-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger-color);"><i class="bi bi-exclamation-triangle"></i></div>
                    <h5 class="report-title">At-Risk Attendance</h5>
                    <p class="report-desc">Students with highest absences</p>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary-custom w-100" href="<?php echo teacherExportUrl('at_risk', $selectedMode, $selectedAcademicYear, $scope, $selectedSemester, $selectedClassId, $topN, $selectedSex); ?>"><i class="bi bi-download me-1"></i>CSV</a>
                    </div>
                </div>
            </div>

        </div>

        <div class="content-card mt-4">
            <div class="content-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="content-card-title mb-1">Preview: <?php echo ucwords(str_replace('_', ' ', $selectedType)); ?></h5>
                    <div class="teacher-section-note">Validate the current preview before downloading the export file.</div>
                </div>
                <a class="btn btn-sm btn-primary-custom" href="<?php echo teacherExportUrl($selectedType, $selectedMode, $selectedAcademicYear, $scope, $selectedSemester, $selectedClassId, $topN, $selectedSex); ?>">
                    <i class="bi bi-download me-1"></i>Download CSV
                </a>
            </div>
            <div class="content-card-body">
                <div class="mb-2 text-muted small">Date Range: <?php echo htmlspecialchars($dateFrom . ' to ' . $dateTo); ?></div>
                <div class="table-container">
                    <table class="custom-table">
                        <thead><tr><?php foreach ($previewHeaders as $h): ?><th><?php echo htmlspecialchars($h); ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php if (empty($previewRows)): ?>
                                <tr><td colspan="<?php echo max(1, count($previewHeaders)); ?>" class="text-center text-muted py-3">No data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($previewRows as $r): ?>
                                    <tr><?php foreach ($r as $v): ?><td><?php echo htmlspecialchars((string)$v); ?></td><?php endforeach; ?></tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="content-card mt-4">
            <div class="content-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="content-card-title mb-1">Write Report Note</h5>
                    <div class="teacher-section-note">Capture follow-up insights or attendance observations for your own reporting workflow.</div>
                </div>
                <small class="text-muted">Teacher-only report notes</small>
            </div>
            <div class="content-card-body">
                <form id="teacherReportNoteForm" class="row g-3">
                    <input type="hidden" name="note_id" value="">
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="report_type">
                            <option value="general">General</option>
                            <option value="top_attendance">Top Attendance</option>
                            <option value="class_summary">Class Summary</option>
                            <option value="at_risk">At-Risk Attendance</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" name="title" maxlength="200" placeholder="Ex. Semester attendance award summary">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Content</label>
                        <textarea class="form-control" name="content" rows="4" maxlength="5000" placeholder="Write your report note here..."></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-secondary-custom d-none" id="teacherReportNoteCancelBtn">Cancel Edit</button>
                        <button type="submit" class="btn btn-primary-custom" id="teacherReportNoteSaveBtn">
                            <i class="bi bi-save me-1"></i>Save Report Note
                        </button>
                    </div>
                </form>

                <hr>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Saved Report Notes</h6>
                    <select class="form-select form-select-sm" id="teacherReportNoteFilter" style="max-width: 220px;">
                        <option value="">All Types</option>
                        <option value="general">General</option>
                        <option value="top_attendance">Top Attendance</option>
                        <option value="class_summary">Class Summary</option>
                        <option value="at_risk">At-Risk Attendance</option>
                    </select>
                </div>

                <div id="teacherReportNoteList" class="d-flex flex-column gap-3"></div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
<script>
document.getElementById('scopeSelect')?.addEventListener('change', function () {
    const sem = document.getElementById('semesterSelect');
    const semHidden = document.getElementById('semesterHiddenInput');
    if (!sem) return;
    sem.disabled = this.value === 'yearly';
    if (semHidden) {
        semHidden.value = sem.value || 'S1';
    }
});

document.getElementById('semesterSelect')?.addEventListener('change', function () {
    const semHidden = document.getElementById('semesterHiddenInput');
    if (semHidden) {
        semHidden.value = this.value || 'S1';
    }
});

document.getElementById('teacherReportFilterForm')?.addEventListener('submit', function () {
    const sem = document.getElementById('semesterSelect');
    const semHidden = document.getElementById('semesterHiddenInput');
    const applyBtn = document.getElementById('teacherReportApplyBtn');

    if (semHidden && sem) {
        semHidden.value = sem.value || 'S1';
    }

    if (applyBtn) {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying...';
    }
});

function exportTeacherReportSf2(format) {
    const classId = document.getElementById('reportTeacherSf2Class')?.value || '';
    const month = document.getElementById('reportTeacherSf2Month')?.value || '';
    const year = document.getElementById('reportTeacherSf2Year')?.value || '';
    if (!classId) {
        showNotification('Select a class for SF2 export', 'warning');
        return;
    }
    const params = new URLSearchParams({
        class_id: classId,
        month: month,
        year: year,
        export: format
    });
    window.location.href = 'teacher_SF2_Export.php?' + params.toString();
}

(function initTeacherReportNotes() {
    const form = document.getElementById('teacherReportNoteForm');
    const saveBtn = document.getElementById('teacherReportNoteSaveBtn');
    const cancelBtn = document.getElementById('teacherReportNoteCancelBtn');
    const listWrap = document.getElementById('teacherReportNoteList');
    const typeFilter = document.getElementById('teacherReportNoteFilter');
    if (!form || !saveBtn || !cancelBtn || !listWrap || !typeFilter) return;
    const csrfToken = (window.APP_CSRF_TOKEN || '').toString();

    let notesCache = [];

    async function readJsonResponse(res, fallbackMessage) {
        const text = await res.text();
        try {
            const parsed = text ? JSON.parse(text) : null;
            if (parsed && typeof parsed === 'object') {
                if (parsed.ok === undefined && parsed.success !== undefined) {
                    parsed.ok = Boolean(parsed.success);
                }
                return parsed;
            }
            return { ok: false, message: fallbackMessage };
        } catch (err) {
            console.error('Report notes returned a non-JSON response.', {
                status: res.status,
                contentType: res.headers.get('content-type') || '',
                body: text.slice(0, 500)
            });
            const snippet = text.replace(/<[^>]*>/g, '').trim().slice(0, 120);
            return { ok: false, message: snippet || fallbackMessage };
        }
    }

    function appendCsrf(fd) {
        if (fd && csrfToken) fd.set('csrf_token', csrfToken);
        return fd;
    }


    function typeBadge(type) {
        const labels = {
            general: 'GENERAL',
            top_attendance: 'TOP ATTENDANCE',
            class_summary: 'CLASS SUMMARY',
            at_risk: 'AT-RISK'
        };
        const label = labels[type] || 'GENERAL';
        return '<span class="badge bg-light text-dark border">' + esc(label) + '</span>';
    }

    function setCreateMode() {
        form.note_id.value = '';
        form.reset();
        cancelBtn.classList.add('d-none');
        saveBtn.innerHTML = '<i class="bi bi-save me-1"></i>Save Report Note';
    }

    function setEditMode(note) {
        form.note_id.value = String(note.id);
        form.report_type.value = note.report_type || 'general';
        form.title.value = note.title || '';
        form.content.value = note.content || '';
        cancelBtn.classList.remove('d-none');
        saveBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Update Report Note';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    async function loadNotes() {
        listWrap.innerHTML = '<div class="text-muted">Loading...</div>';
        const qs = typeFilter.value ? ('&report_type=' + encodeURIComponent(typeFilter.value)) : '';

        try {
            const res = await fetch('teacher_Reports_Action.php?action=list_notes' + qs, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await readJsonResponse(res, 'Report notes returned an invalid response.');
            if (!res.ok || !data.ok) {
                listWrap.innerHTML = '<div class="text-danger">' + esc(data.message || 'Failed to load notes.') + '</div>';
                return;
            }

            const notes = Array.isArray(data.notes) ? data.notes : [];
            notesCache = notes;
            if (!notes.length) {
                listWrap.innerHTML = '<div class="text-muted">No saved report notes yet.</div>';
                return;
            }

            listWrap.innerHTML = notes.map(function (note) {
                return (
                    '<div class="border rounded p-3">' +
                        '<div class="d-flex justify-content-between align-items-start gap-3 mb-2">' +
                            '<div>' +
                                '<h6 class="mb-1">' + esc(note.title) + '</h6>' +
                                '<div class="d-flex align-items-center gap-2">' +
                                    typeBadge(note.report_type) +
                                    '<small class="text-muted">' + esc(note.created_at) + '</small>' +
                                '</div>' +
                            '</div>' +
                            '<div class="d-flex gap-1">' +
                                '<button type="button" class="btn btn-sm btn-outline-secondary teacher-note-print-btn" data-id="' + Number(note.id) + '">' +
                                    '<i class="bi bi-printer"></i>' +
                                '</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-primary teacher-note-edit-btn" data-id="' + Number(note.id) + '">' +
                                    '<i class="bi bi-pencil"></i>' +
                                '</button>' +
                                '<button type="button" class="btn btn-sm btn-outline-danger teacher-note-delete-btn" data-id="' + Number(note.id) + '">' +
                                    '<i class="bi bi-trash"></i>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="text-muted" style="white-space: pre-wrap;">' + esc(note.content) + '</div>' +
                    '</div>'
                );
            }).join('');
        } catch (err) {
            listWrap.innerHTML = '<div class="text-danger">' + esc(err.message || 'Error loading notes.') + '</div>';
        }
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(form);
        appendCsrf(fd);
        if (!String(fd.get('title') || '').trim() || !String(fd.get('content') || '').trim()) {
            showNotification('Title and content are required.', 'warning');
            return;
        }

        const isEdit = !!String(form.note_id.value || '').trim();
        saveBtn.disabled = true;
        saveBtn.innerHTML = isEdit
            ? '<i class="bi bi-hourglass-split me-1"></i>Updating...'
            : '<i class="bi bi-hourglass-split me-1"></i>Saving...';

        try {
            const action = isEdit ? 'update_note' : 'save_note';
            const res = await fetch('teacher_Reports_Action.php?action=' + action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd
            });
            const data = await readJsonResponse(res, isEdit ? 'Report note update returned an invalid response.' : 'Report note save returned an invalid response.');
            if (!res.ok || !data.ok) {
                showNotification((data && data.message) ? data.message : (isEdit ? 'Failed to update note.' : 'Failed to save note.'), 'danger');
                return;
            }
            setCreateMode();
            await loadNotes();
            showNotification(isEdit ? 'Report note updated.' : 'Report note saved.', 'success');
        } catch (err) {
            showNotification(isEdit ? 'Error updating note.' : 'Error saving note.', 'danger');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = String(form.note_id.value || '').trim()
                ? '<i class="bi bi-pencil-square me-1"></i>Update Report Note'
                : '<i class="bi bi-save me-1"></i>Save Report Note';
        }
    });

    listWrap.addEventListener('click', async function (e) {
        const printBtn = e.target.closest('.teacher-note-print-btn');
        if (printBtn) {
            const noteId = Number(printBtn.getAttribute('data-id') || 0);
            const note = notesCache.find(function (n) { return Number(n.id) === noteId; });
            if (!note) return;

            const popup = window.open('', '_blank', 'width=900,height=700');
            if (!popup) return;

            const title = esc(note.title || '');
            const reportType = esc((note.report_type || 'general').replace(/_/g, ' ').toUpperCase());
            const createdAt = esc(note.created_at || '');
            const content = esc(note.content || '').replace(/\n/g, '<br>');

            popup.document.write(
                '<!doctype html><html><head><meta charset="utf-8"><title>Teacher Report Note</title>' +
                '<style>body{font-family:Arial,sans-serif;padding:24px;color:#111}h2{margin:0 0 4px}small{color:#666}hr{margin:16px 0}p{line-height:1.5}</style>' +
                '</head><body>' +
                '<h2>' + title + '</h2>' +
                '<small>Type: ' + reportType + ' | Created: ' + createdAt + '</small>' +
                '<hr><p>' + content + '</p>' +
                '</body></html>'
            );
            popup.document.close();
            popup.focus();
            popup.print();
            return;
        }

        const editBtn = e.target.closest('.teacher-note-edit-btn');
        if (editBtn) {
            const noteId = Number(editBtn.getAttribute('data-id') || 0);
            const note = notesCache.find(function (n) { return Number(n.id) === noteId; });
            if (note) {
                setEditMode(note);
            }
            return;
        }

        const btn = e.target.closest('.teacher-note-delete-btn');
        if (!btn) return;

        const noteId = Number(btn.getAttribute('data-id') || 0);
        if (!noteId) return;

        const fd = new FormData();
        fd.append('note_id', String(noteId));
        appendCsrf(fd);

        btn.disabled = true;
        try {
            const res = await fetch('teacher_Reports_Action.php?action=delete_note', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd
            });
            const data = await readJsonResponse(res, 'Report note delete returned an invalid response.');
            if (!res.ok || !data.ok) {
                btn.disabled = false;
                showNotification((data && data.message) ? data.message : 'Failed to delete note.', 'danger');
                return;
            }
            await loadNotes();
        } catch (err) {
            btn.disabled = false;
        }
    });

    typeFilter.addEventListener('change', function () {
        loadNotes();
    });

    cancelBtn.addEventListener('click', function () {
        setCreateMode();
    });

    loadNotes();
})();
</script>
</body>
</html>
