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

require_once __DIR__ . '/teacher_Enrollment_Helper.php';
$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);

$classes = [];
$gradeStudents = [];
$selectedGradeClassId = 0;
$todayTs = time();
$todayYear = (int)date('Y', $todayTs);
$todayMonth = (int)date('n', $todayTs);
$defaultStartYear = ($todayMonth >= 6) ? $todayYear : ($todayYear - 1);
$defaultAcademicYear = $defaultStartYear . '-' . ($defaultStartYear + 1);
$defaultTerm = 'Term1';
if ($todayMonth >= 10 && $todayMonth <= 12) {
    $defaultTerm = 'Term2';
} elseif ($todayMonth >= 1 && $todayMonth <= 3) {
    $defaultTerm = 'Term3';
}

$selectedTerm = $_GET['term'] ?? ($_GET['quarter'] ?? $defaultTerm);
$selectedAcademicYear = $_GET['academic_year'] ?? $defaultAcademicYear;
$selectedSex = strtolower(trim((string)($_GET['sex'] ?? '')));
if (!in_array($selectedSex, ['male', 'female'], true)) {
    $selectedSex = '';
}
$gs = SshsGradeCalculator::gradingSystem($selectedAcademicYear);
$selectedClassWeights = ['ww_weight' => 25, 'pt_weight' => 50, 'assessment_weight' => 25];
$hasGradeTerm = false;
$hasRawColumns = false;

if ($db) {
    $has_term = dbHasColumn($db, 'grades', 'term');
    $hasGradeTerm = $has_term;
    if (!$hasGradeTerm) {
        $has_quarter = dbHasColumn($db, 'grades', 'quarter');
        $hasGradeTerm = $has_quarter;
    }
    $rawRequired = ['ww_raw_score', 'ww_total_score', 'pt_raw_score', 'pt_total_score', 'assessment_raw_score', 'assessment_total_score'];
    $hasRawColumns = true;
    foreach ($rawRequired as $col) {
        if (!dbHasColumn($db, 'grades', $col)) {
            $hasRawColumns = false;
            break;
        }
    }

    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section,
                               COALESCE(c.ww_weight, 25) AS ww_weight,
                               COALESCE(c.pt_weight, 50) AS pt_weight,
                               COALESCE(c.assessment_weight, 25) AS assessment_weight
                               FROM class_subjects cs
                               JOIN classes c ON c.id = cs.class_id
                               WHERE cs.teacher_id = ? AND c.status = 'active'
                               AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                               GROUP BY c.id, c.class_name, c.grade_level, c.section, c.ww_weight, c.pt_weight, c.assessment_weight
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $selectedGradeClassId = (int)($_GET['grade_class_id'] ?? $classes[0]['id']);
        $ids = array_map('intval', array_column($classes, 'id'));
        if (!in_array($selectedGradeClassId, $ids, true)) {
            $selectedGradeClassId = (int)$classes[0]['id'];
        }
        foreach ($classes as $class) {
            if ((int)$class['id'] === $selectedGradeClassId) {
                $selectedClassWeights = [
                    'ww_weight' => (float)$class['ww_weight'],
                    'pt_weight' => (float)$class['pt_weight'],
                    'assessment_weight' => (float)$class['assessment_weight']
                ];
                break;
            }
        }

        if ($hasGradeTerm) {
            $tc = 'term';
            $has_quarter = dbHasColumn($db, 'grades', 'quarter');
            if ($has_quarter) {
                $has_term = dbHasColumn($db, 'grades', 'term');
                $tc = $has_term ? 'term' : 'quarter';
            }
            $classSubjectSubquery = "(SELECT id FROM class_subjects WHERE teacher_id = ? AND class_id = ? LIMIT 1)";
            $rawSelect = $hasRawColumns
                ? "g.ww_raw_score, g.ww_total_score, g.pt_raw_score, g.pt_total_score, g.assessment_raw_score, g.assessment_total_score,"
                : "NULL AS ww_raw_score, NULL AS ww_total_score, NULL AS pt_raw_score, NULL AS pt_total_score, NULL AS assessment_raw_score, NULL AS assessment_total_score,";
            $gradeStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name,
                                       $rawSelect
                                       g.quiz_score AS ww_score,
                                       g.activity_score AS pt_score,
                                       g.exam_score AS assessment_score,
                                       g.final_grade AS quarter_final_grade,
                                       NULL AS semester_final_grade
                                       FROM users u
                                       " . tEnrollActiveUsersJoinSql() . "
                                       LEFT JOIN grades g ON g.student_id = u.id
                                                        AND g.class_subject_id = $classSubjectSubquery
                                                        AND g.{$tc} = ?
                                                        AND g.academic_year = ?
                                       WHERE (? = '' OR LOWER(COALESCE(u.sex, '')) = ?)
                                       ORDER BY u.last_name, u.first_name");
            $gradeStmt->execute([
                $selectedGradeClassId, $selectedGradeClassId,
                $teacherId, $selectedGradeClassId,
                $selectedTerm, $selectedAcademicYear,
                $selectedSex, $selectedSex
            ]);
            $gradeStudents = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$current_role = 'teacher';
$current_page = 'grades';
$page_title = 'Grade Entry';
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
                    <div class="app-detail-kicker mb-2">Subject Scoring</div>
                    <h4 class="mb-2">Grade Entry</h4>
                    <p class="mb-0">Load a class by term, review computed percentages and weighted values, then submit only after the grade table is complete and ready for approval.</p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip" id="heroClassCount"><i class="bi bi-journal-bookmark"></i><?php echo number_format(count($classes)); ?> subject classes</span>
                        <span class="teacher-chip" id="heroAyChip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($selectedAcademicYear); ?></span>
                        <span class="teacher-chip" id="heroTermChip"><i class="bi bi-diagram-3"></i><?php echo htmlspecialchars($selectedTerm); ?></span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Loaded Students</span>
                            <span class="teacher-summary-value" id="heroStudentCount"><?php echo number_format(count($gradeStudents)); ?></span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Grading System</span>
                            <span class="teacher-summary-value"><?php echo htmlspecialchars($gs === '4_quarter' ? '4Q' : '3T'); ?></span>
                        </div>
                    </div>
                    <p class="teacher-summary-note">Use ECR preview for export validation, but keep in mind it does not overwrite the saved grade records.</p>
                </div>
            </div>
        </div>
        <div class="content-card">
            <div class="content-card-header">
                <div>
                    <h5 class="content-card-title mb-1">Grade Entry</h5>
                    <div class="teacher-section-note">Choose the working class, then review grading weights and the current approval state before submission.</div>
                </div>
            </div>
            <div class="content-card-body">
                <div class="row g-3 mb-3 app-filter-panel">
                    <div class="col-md-5">
                        <label class="form-label">Class</label>
                        <select id="gradeClassSelect" class="form-select">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$class['id'] === $selectedGradeClassId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Term</label>
                        <select id="termSelect" class="form-select">
                            <option value="Term1" <?php echo $selectedTerm === 'Term1' ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term2" <?php echo $selectedTerm === 'Term2' ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term3" <?php echo $selectedTerm === 'Term3' ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Academic Year</label>
                        <input type="text" id="academicYearInput" class="form-control" value="<?php echo htmlspecialchars($selectedAcademicYear); ?>" placeholder="YYYY-YYYY">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Sex</label>
                        <select id="gradeSexFilter" class="form-select">
                            <option value="" <?php echo $selectedSex === '' ? 'selected' : ''; ?>>All</option>
                            <option value="male" <?php echo $selectedSex === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $selectedSex === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-secondary-custom w-100" id="loadGradesBtn" onclick="loadGradesData()"><i class="bi bi-arrow-clockwise me-1"></i>Load</button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button class="btn btn-primary-custom" id="ecrPreviewBtn" onclick="openEcrPreview()"><i class="bi bi-file-earmark-excel me-1"></i>ECR</button>
                        <button class="btn btn-outline-secondary" id="exportCsvBtn" onclick="exportGradesCsv()" title="Export grades as CSV"><i class="bi bi-download me-1"></i>CSV</button>
                    </div>
                </div>
                <div class="teacher-kpi-grid mb-3">
                    <div class="teacher-kpi-card">
                        <span>Written Work Weight</span>
                        <strong><?php echo htmlspecialchars((string)$selectedClassWeights['ww_weight']); ?>%</strong>
                    </div>
                    <div class="teacher-kpi-card">
                        <span>Performance Task Weight</span>
                        <strong><?php echo htmlspecialchars((string)$selectedClassWeights['pt_weight']); ?>%</strong>
                    </div>
                    <div class="teacher-kpi-card">
                        <span>Assessment Weight</span>
                        <strong><?php echo htmlspecialchars((string)$selectedClassWeights['assessment_weight']); ?>%</strong>
                    </div>
                </div>
                <div class="alert alert-light border mb-3" id="gradeWeightsInfo">
                    Grading Weights: WW <?php echo htmlspecialchars((string)$selectedClassWeights['ww_weight']); ?>% |
                    PT <?php echo htmlspecialchars((string)$selectedClassWeights['pt_weight']); ?>% |
                    Assessment <?php echo htmlspecialchars((string)$selectedClassWeights['assessment_weight']); ?>%
                </div>
                <?php if (!$hasGradeTerm): ?>
                    <div class="alert alert-warning mb-3">
                        Grade term support is not yet enabled in your database. Run the 005 migration first.
                    </div>
                <?php endif; ?>
                <?php if (!$hasRawColumns): ?>
                    <div class="alert alert-info mb-3">
                        Raw score tracking columns are not enabled yet. You can encode score/total now, but only computed percentages will be saved.
                    </div>
                <?php endif; ?>
                <div class="alert alert-warning mb-3" id="ecrTemplateWarning" style="display:none;">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>ECR notice:</strong> <span id="ecrTemplateWarningText">Checking the ECR template status.</span>
                </div>

                <div class="table-container">
                    <table class="custom-table">
                        <thead><tr><th>Student</th><th>Written Work (PS% | Weighted)</th><th>Performance Task (PS% | Weighted)</th><th>Assessment (PS% | Weighted)</th><th>Quarter Grade</th><th>Status</th><th>Approval</th></tr></thead>
                        <tbody id="gradesBody">
                        <?php if (empty($gradeStudents)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No students found for selected class.</td></tr>
                        <?php else: ?>
                            <?php foreach ($gradeStudents as $g): ?>
                                <tr data-student-id="<?php echo (int)$g['id']; ?>">
                                    <td>
                                        <div class="teacher-entity">
                                            <strong><?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?></strong>
                                            <small><?php echo !empty($g['reference_code']) ? htmlspecialchars((string)$g['reference_code']) : 'No reference code'; ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="grade-ww-percent"><?php echo $g['ww_score'] !== null ? htmlspecialchars($g['ww_score']) : 'N/A'; ?></span>
                                        <?php if ($g['ww_score'] !== null): ?>
                                            <small class="text-muted d-block">| <?php echo htmlspecialchars(number_format(((float)$g['ww_score'] * (float)$selectedClassWeights['ww_weight']) / 100, 2)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="grade-pt-percent"><?php echo $g['pt_score'] !== null ? htmlspecialchars($g['pt_score']) : 'N/A'; ?></span>
                                        <?php if ($g['pt_score'] !== null): ?>
                                            <small class="text-muted d-block">| <?php echo htmlspecialchars(number_format(((float)$g['pt_score'] * (float)$selectedClassWeights['pt_weight']) / 100, 2)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="grade-assessment-percent"><?php echo $g['assessment_score'] !== null ? htmlspecialchars($g['assessment_score']) : 'N/A'; ?></span>
                                        <?php if ($g['assessment_score'] !== null): ?>
                                            <small class="text-muted d-block">| <?php echo htmlspecialchars(number_format(((float)$g['assessment_score'] * (float)$selectedClassWeights['assessment_weight']) / 100, 2)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="fw-semibold grade-final"><?php echo $g['quarter_final_grade'] !== null ? htmlspecialchars($g['quarter_final_grade']) : 'N/A'; ?></span></td>
                                    <td>
                                        <?php
                                            $qg = $g['quarter_final_grade'] !== null ? (float)$g['quarter_final_grade'] : null;
                                            $statusLabel = $qg === null ? 'No Grade' : ($qg >= 75 ? 'Passed' : 'Failed');
                                            $statusBadge = $qg === null ? 'secondary' : ($qg >= 75 ? 'success' : 'danger');
                                        ?>
                                        <span class="teacher-status-pill <?php echo $qg === null ? 'teacher-status-draft' : ($qg >= 75 ? 'teacher-status-approved' : 'teacher-status-rejected'); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                    </td>
                                    <td><span class="badge approval-status-badge" data-student-id="<?php echo (int)$g['id']; ?>">—</span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="teacher-action-bar justify-content-end mt-3" id="submitButtonGroup">
                    <span class="text-muted small me-auto" id="gradeSubmitLockNote" style="display:none;">
                        Resubmitting verified grades starts a correction and hides the released report card until approval.
                    </span>
                    <button class="btn btn-primary-custom" id="submitGradesBtn" type="button" onclick="submitGrades()">
                        <i class="bi bi-send-check me-2"></i>Submit to Admin
                    </button>
                    <button class="btn btn-outline-warning" id="recallGradesBtn" type="button" onclick="recallGrades()" style="display:none;">
                        <i class="bi bi-arrow-return-left me-2"></i>Recall Submission
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ECR Preview Modal -->
<div class="modal fade" id="ecrPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-file-earmark-excel"></i> ECR Preview</div>
                    <h5 class="modal-title mb-0">Review and edit scores before export</h5>
                    <p class="app-modal-subtitle mb-0">Changes here affect the exported file only and do not overwrite saved grades.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div class="app-modal-panel mb-3">
                    <div class="app-modal-panel-title"><i class="bi bi-info-circle"></i>Preview Guidance</div>
                    <p class="app-modal-panel-copy mb-0">Use this view to verify rows, scores, and export formatting before downloading the final ECR workbook.</p>
                </div>
                <div class="alert alert-warning d-none" id="ecrPreviewLimitNote"></div>
                <div class="table-container">
                    <table class="custom-table" id="ecrPreviewTable">
                        <thead id="ecrPreviewHead"></thead>
                        <tbody id="ecrPreviewBody"></tbody>
                    </table>
                </div>
                <div id="ecrPreviewLoading" class="text-center py-4 d-none">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="mb-0">Loading preview...</p>
                </div>
            </div>
            <div class="modal-footer app-modal-footer">
                <div class="me-auto d-flex align-items-center gap-2 flex-wrap">
                    <input type="file" class="form-control form-control-sm" id="ecrImportInput" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="ecrImportBtn" onclick="importEcrFile()">
                        <i class="bi bi-upload me-1"></i>Import ECR
                    </button>
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary-custom" id="ecrPreviewDownloadBtn" onclick="downloadEcrFromPreview()">
                    <i class="bi bi-download me-2"></i>Download ECR (.xlsx)
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/offlineStorage.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/networkSync.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
<script>
let gradingWeights = {
    ww_weight: <?php echo json_encode((float)$selectedClassWeights['ww_weight']); ?>,
    pt_weight: <?php echo json_encode((float)$selectedClassWeights['pt_weight']); ?>,
    assessment_weight: <?php echo json_encode((float)$selectedClassWeights['assessment_weight']); ?>
};
const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
const ecrApiBase = '../api/index.php';
let ecrPreviewModal = null;
let ecrPreviewData = null;
let ecrPreviewItems = [];

function ecrApiUrl(route, params = null) {
    const query = new URLSearchParams(params || {});
    query.set('route', route);
    return ecrApiBase + '?' + query.toString();
}

function describeEcrTemplateCheck(data) {
    const diagnostics = data?.diagnostics || {};
    const uploaded = diagnostics.uploaded || data?.uploaded_template || {};
    const bundled = diagnostics.bundled || {};
    if (data?.export_fallback_available || diagnostics.zip_available === false) {
        return 'Official ECR template editing is unavailable on this server, so downloads will use the generated .xlsx ECR format.';
    }
    const parts = [];
    if (data?.message) parts.push(data.message);
    if (uploaded.exists) {
        parts.push('Uploaded: ' + (uploaded.message || 'found'));
    }
    if (bundled.exists) {
        parts.push('Bundled: ' + (bundled.message || 'found'));
    }
    if (diagnostics.zip_available === false) {
        parts.push('PHP Zip extension is not enabled.');
    }
    return parts.join(' ');
}

function updateWeightsInfo(){const el=document.getElementById('gradeWeightsInfo');if(!el)return;el.textContent=`Grading Weights: WW ${gradingWeights.ww_weight}% | PT ${gradingWeights.pt_weight}% | Assessment ${gradingWeights.assessment_weight}%`;}
function approvalBadge(status){
  const map={submitted:['warning text-dark','Pending Admin'],admin_verified:['success','Admin Verified'],approved:['info','Approved'],rejected:['danger','Rejected'],pending:['secondary','Not Submitted']};
  const m=map[status]||['secondary','Unknown'];
  return '<span class="badge bg-'+m[0]+'">'+m[1]+'</span>';
}
function updateSubmitButtons(students){
  const submitBtn=document.getElementById('submitGradesBtn');
  const recallBtn=document.getElementById('recallGradesBtn');
  const lockNote=document.getElementById('gradeSubmitLockNote');
  if(!students||students.length===0){if(submitBtn)submitBtn.style.display='';if(recallBtn)recallBtn.style.display='none';if(lockNote)lockNote.style.display='none';return;}
  const anySubmitted=students.some(function(s){return s.approval_status==='submitted';});
  const anyAdminVerified=students.some(function(s){return s.approval_status==='admin_verified';});
  if(anySubmitted&&!anyAdminVerified){if(submitBtn)submitBtn.style.display='none';if(recallBtn)recallBtn.style.display='';if(lockNote)lockNote.style.display='none';}
  else if(anyAdminVerified){if(submitBtn)submitBtn.style.display='';if(recallBtn)recallBtn.style.display='none';if(lockNote)lockNote.style.display='';}
  else{if(submitBtn)submitBtn.style.display='';if(recallBtn)recallBtn.style.display='none';if(lockNote)lockNote.style.display='none';}
}
function renderGrades(students){const tbody=document.getElementById('gradesBody');if(!students||students.length===0){tbody.innerHTML='<tr><td colspan="7" class="text-center text-muted py-4">No students found for selected class.</td></tr>';updateSubmitButtons([]);return;}tbody.innerHTML=students.map(s=>{const ww=(s.ww_score!==null&&s.ww_score!==undefined&&s.ww_score!=='')?parseFloat(s.ww_score):null;const pt=(s.pt_score!==null&&s.pt_score!==undefined&&s.pt_score!=='')?parseFloat(s.pt_score):null;const as=(s.assessment_score!==null&&s.assessment_score!==undefined&&s.assessment_score!=='')?parseFloat(s.assessment_score):null;const qg=(s.quarter_final_grade!==null&&s.quarter_final_grade!==undefined&&s.quarter_final_grade!=='')?parseFloat(s.quarter_final_grade):null;const statusLabel=qg===null?'No Grade':(qg>=75?'Passed':'Failed');const statusBadge=qg===null?'bg-secondary':(qg>=75?'bg-success':'bg-danger');const wwW=ww===null?'':`<small class="text-muted d-block">| ${((ww*gradingWeights.ww_weight)/100).toFixed(2)}</small>`;const ptW=pt===null?'':`<small class="text-muted d-block">| ${((pt*gradingWeights.pt_weight)/100).toFixed(2)}</small>`;const asW=as===null?'':`<small class="text-muted d-block">| ${((as*gradingWeights.assessment_weight)/100).toFixed(2)}</small>`;const appBadge=approvalBadge(s.approval_status||'pending');return '<tr data-student-id="'+s.id+'"><td>'+escapeHtml(s.first_name+' '+s.last_name)+'</td><td><span class="grade-ww-percent">'+(ww===null?'N/A':ww)+'</span>'+wwW+'</td><td><span class="grade-pt-percent">'+(pt===null?'N/A':pt)+'</span>'+ptW+'</td><td><span class="grade-assessment-percent">'+(as===null?'N/A':as)+'</span>'+asW+'</td><td><span class="fw-semibold grade-final">'+(qg===null?'N/A':qg)+'</span></td><td><span class="badge '+statusBadge+'">'+statusLabel+'</span></td><td>'+appBadge+'</td></tr>';}).join('');updateSubmitButtons(students);}
function loadGradesData(isUserAction = true){
    const classId=document.getElementById('gradeClassSelect')?.value;
    const term=document.getElementById('termSelect')?.value;
    const ay=document.getElementById('academicYearInput')?.value.trim();
    const sex=(document.getElementById('gradeSexFilter')?.value||'').trim().toLowerCase();
    if(!classId||!term||!ay){
        if(isUserAction){showNotification('Class, term, and academic year are required','warning');}
        return;
    }
    let url=`teacher_Action.php?action=fetch_grades&class_id=${encodeURIComponent(classId)}&term=${encodeURIComponent(term)}&academic_year=${encodeURIComponent(ay)}`;
    if(sex==='male'||sex==='female'){url+=`&sex=${encodeURIComponent(sex)}`;}

    const loadBtn = document.getElementById('loadGradesBtn');
    const loadIcon = loadBtn?.querySelector('i');
    if (loadBtn && isUserAction) {
        loadBtn.disabled = true;
        if (loadIcon) loadIcon.classList.add('spin');
    }

    if(!navigator.onLine){
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getClassRoster(classId).then(students => {
                if(students && students.length > 0){
                    renderGrades(students);
                    if(isUserAction){showNotification('Loaded offline class roster from device storage', 'info');}
                    return;
                }
                if(isUserAction){showNotification('No offline roster stored for this class', 'warning');}
            }).finally(() => {
                if (loadBtn) {
                    loadBtn.disabled = false;
                    if (loadIcon) loadIcon.classList.remove('spin');
                }
            });
        }
        return;
    }

    fetch(url).then(r=>r.json()).then(d=>{
        if(!d.success){
            if(isUserAction){showNotification(d.message||'Failed to load grades','danger');}
            return;
        }
        if(d.weights){
            gradingWeights.ww_weight=parseFloat(d.weights.ww_weight||25);
            gradingWeights.pt_weight=parseFloat(d.weights.pt_weight||50);
            gradingWeights.assessment_weight=parseFloat(d.weights.assessment_weight||25);
        }
        updateWeightsInfo();
        const students = d.students || [];
        renderGrades(students);

        // Dynamically update hero chips and summary values
        const heroTermEl = document.getElementById('heroTermChip');
        if (heroTermEl) {
            heroTermEl.innerHTML = '<i class="bi bi-diagram-3"></i>' + escapeHtml(term);
        }
        const heroAyEl = document.getElementById('heroAyChip');
        if (heroAyEl) {
            heroAyEl.innerHTML = '<i class="bi bi-calendar3"></i>' + escapeHtml(ay);
        }
        const heroCountEl = document.getElementById('heroStudentCount');
        if (heroCountEl) {
            heroCountEl.textContent = students.length;
        }

        // Sync browser URL parameters without reloading
        try {
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('grade_class_id', classId);
            newUrl.searchParams.set('term', term);
            newUrl.searchParams.set('academic_year', ay);
            if (sex) { newUrl.searchParams.set('sex', sex); } else { newUrl.searchParams.delete('sex'); }
            window.history.replaceState({}, '', newUrl.toString());
        } catch (e) {}

        if(isUserAction){
            const termDisplay = term === 'Term1' ? 'Term 1' : (term === 'Term2' ? 'Term 2' : (term === 'Term3' ? 'Term 3' : term));
            showNotification('Loaded ' + students.length + ' student records for ' + termDisplay, 'success');
        }
    }).catch(()=>{
        if(window.bshsOfflineStorage){
            window.bshsOfflineStorage.getClassRoster(classId).then(students => {
                if(students && students.length > 0){
                    renderGrades(students);
                    if(isUserAction){showNotification('Loaded offline class roster from device storage', 'info');}
                    return;
                }
            });
        }
        if(isUserAction){showNotification('Error loading grades','danger');}
    }).finally(()=>{
        if (loadBtn) {
            loadBtn.disabled = false;
            if (loadIcon) loadIcon.classList.remove('spin');
        }
    });
}
function submitGrades(){const classId=parseInt(document.getElementById('gradeClassSelect')?.value||'0',10);const term=(document.getElementById('termSelect')?.value||'').toString().trim();const academicYear=(document.getElementById('academicYearInput')?.value||'').toString().trim();if(!classId||!term||!academicYear){showNotification('Class, term, and academic year are required','warning');return;}const rows=Array.from(document.querySelectorAll('#gradesBody tr[data-student-id]'));if(rows.length===0){showNotification('No students to submit','warning');return;}const records=[];let invalidCount=0;rows.forEach(row=>{const studentId=parseInt(row.getAttribute('data-student-id')||'0',10);const wwText=(row.querySelector('.grade-ww-percent')?.textContent||'').trim();const ptText=(row.querySelector('.grade-pt-percent')?.textContent||'').trim();const asText=(row.querySelector('.grade-assessment-percent')?.textContent||'').trim();const toNum=v=>{if(!v||v.toUpperCase()==='N/A')return null;const n=parseFloat(v);return Number.isFinite(n)?n:null;};const ww=toNum(wwText);const pt=toNum(ptText);const assessment=toNum(asText);if(studentId>0){if(ww===null&&pt===null&&assessment===null){invalidCount++;}records.push({student_id:studentId,ww_score:ww,pt_score:pt,assessment_score:assessment});}});if(records.length===0){showNotification('No valid student records found','warning');return;}if(invalidCount>0){showNotification('Cannot submit yet. '+invalidCount+' student(s) have no computed scores.','warning');return;}const btn=document.getElementById('submitGradesBtn');if(btn){btn.disabled=true;btn.innerHTML='<span class=\"spinner-border spinner-border-sm me-2\"></span>Submitting...';}fetch('teacher_Action.php?action=submit_grades',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({class_id:classId,term:term,academic_year:academicYear,records:records})}).then(r=>r.json()).then(d=>{if(d.success){if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-send-check me-2\"></i>Submit to Admin';}showNotification(d.message||'Grades submitted','success');setTimeout(function(){loadGradesData(false);},700);}else{showNotification(d.message||'Failed to submit grades','danger');if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-send-check me-2\"></i>Submit to Admin';}}}).catch(function(){showNotification('Error submitting grades','danger');if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-send-check me-2\"></i>Submit to Admin';}});}
function recallGrades(){const classId=document.getElementById('gradeClassSelect')?.value;const term=document.getElementById('termSelect')?.value;const academicYear=document.getElementById('academicYearInput')?.value.trim();if(!classId||!term||!academicYear){showNotification('Class, term, and academic year are required','warning');return;}const runRecall=function(){const btn=document.getElementById('recallGradesBtn');if(btn){btn.disabled=true;btn.innerHTML='<span class=\"spinner-border spinner-border-sm me-2\"></span>Recalling...';}return fetch('teacher_Action.php?action=recall_grades',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({class_id:classId,term:term,academic_year:academicYear})}).then(r=>r.json()).then(d=>{if(d.success){if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-arrow-return-left me-2\"></i>Recall Submission';}showNotification(d.message||'Grades recalled','success');loadGradesData(false);}else{showNotification(d.message||'Failed','danger');if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-arrow-return-left me-2\"></i>Recall Submission';}}}).catch(function(){showNotification('Error','danger');if(btn){btn.disabled=false;btn.innerHTML='<i class=\"bi bi-arrow-return-left me-2\"></i>Recall Submission';}});};if(typeof showAppConfirm!=='function'){runRecall();return;}showAppConfirm({title:'Recall submitted grades?',subtitle:'Teachers can edit and resubmit after recall.',message:'This will pull the current grade submission back into your editable state.',confirmText:'Recall grades',cancelText:'Cancel',tone:'danger',icon:'bi-arrow-counterclockwise'}).then((confirmed)=>{if(confirmed)runRecall();});}
function exportGradesCsv(){const classId=document.getElementById('gradeClassSelect')?.value;const term=document.getElementById('termSelect')?.value||'Term1';const academicYear=document.getElementById('academicYearInput')?.value.trim();const sex=document.getElementById('gradeSexFilter')?.value||'';if(!classId||!academicYear){showNotification('Please select a class and academic year','warning');return;}const isFourQuarter=academicYear<='2025-2026';const semester=isFourQuarter?(term==='Term1'||term==='Term2'?'S1':'S2'):'';let url='teacher_Action.php?action=export_grades&class_id='+encodeURIComponent(classId)+'&academic_year='+encodeURIComponent(academicYear)+'&term='+encodeURIComponent(term);if(isFourQuarter){url+='&semester='+encodeURIComponent(semester);}if(sex){url+='&sex='+encodeURIComponent(sex);}window.open(url,'_blank');}

document.addEventListener('DOMContentLoaded',()=>{
    if (window.bshsOfflineStorage && navigator.onLine) {
        window.bshsOfflineStorage.saveTeacherSession({
            teacher_id: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
            role: 'teacher',
            first_name: <?php echo json_encode($_SESSION['first_name'] ?? ''); ?>,
            last_name: <?php echo json_encode($_SESSION['last_name'] ?? ''); ?>,
            email: <?php echo json_encode($_SESSION['email'] ?? ''); ?>,
            reference_code: <?php echo json_encode($_SESSION['reference_code'] ?? ''); ?>
        });
    }

    if (!navigator.onLine) {
        if (window.bshsOfflineStorage) {
            window.bshsOfflineStorage.getClasses().then(cachedClasses => {
                if (cachedClasses && cachedClasses.length > 0) {
                    const sel = document.getElementById('gradeClassSelect');
                    if (sel && sel.options.length <= 1) {
                        sel.innerHTML = cachedClasses.map(c => `<option value="${c.id}">${escapeHtml(c.name || '')} (Grade ${c.grade_level || ''} - ${escapeHtml(c.section || '')})</option>`).join('');
                    }
                    loadGradesData(false);
                }
            });
        }
    } else {
        const classEl=document.getElementById('gradeClassSelect');
        if(classEl&&classEl.value){loadGradesData(false);}
    }
});
document.addEventListener('DOMContentLoaded', () => {
    const ecrPreviewBtn = document.getElementById('ecrPreviewBtn');
    const warningEl = document.getElementById('ecrTemplateWarning');
    const warningTextEl = document.getElementById('ecrTemplateWarningText');
    if (!ecrPreviewBtn) return;
    ecrPreviewBtn.disabled = false;
    fetch(ecrApiUrl('ecr-template'))
        .then(async (res) => {
            if (!res.ok) throw new Error('Unable to verify ECR template right now. You can still open ECR and retry.');
            let data = null;
            try { data = await res.json(); } catch (e) {}
            if (data && data.exists === true) {
                ecrPreviewBtn.disabled = false;
                if (warningEl) warningEl.style.display = 'none';
                return;
            }
            const diagnostics = data?.diagnostics || {};
            const hasAnyTemplateFile = !!(
                data?.uploaded_template?.exists ||
                diagnostics?.uploaded?.exists ||
                diagnostics?.bundled?.exists
            );
            const notice = describeEcrTemplateCheck(data) || 'A compatible Strengthened SHS three-term ECR .xlsx template is required.';
            ecrPreviewBtn.disabled = false;
            ecrPreviewBtn.title = notice;
            if (warningTextEl) {
                warningTextEl.textContent = notice;
            }
            if (warningEl) {
                warningEl.style.display = (hasAnyTemplateFile || data?.export_fallback_available) ? 'block' : 'none';
                warningEl.classList.toggle('alert-warning', !data?.export_fallback_available);
                warningEl.classList.toggle('alert-info', !!data?.export_fallback_available);
            }
        })
        .catch((err) => {
            ecrPreviewBtn.disabled = false;
            ecrPreviewBtn.title = err?.message || 'Unable to verify ECR template right now. Click to retry.';
            if (warningTextEl) {
                warningTextEl.textContent = ecrPreviewBtn.title;
            }
            if (warningEl) {
                warningEl.classList.add('alert-warning');
                warningEl.classList.remove('alert-info');
                warningEl.style.display = 'block';
            }
        });
});

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('ecrPreviewModal');
    if (modalEl) {
        ecrPreviewModal = new bootstrap.Modal(modalEl);
    }
});

function openEcrPreview() {
    const classId = document.getElementById('gradeClassSelect')?.value;
    const term = document.getElementById('termSelect')?.value || 'Term1';
    const academicYear = document.getElementById('academicYearInput')?.value?.trim() || '';

    if (!classId || !academicYear) {
        showNotification('Please select a class and academic year', 'warning');
        return;
    }

    if (ecrPreviewModal) {
        ecrPreviewModal.show();
    }

    const head = document.getElementById('ecrPreviewHead');
    const body = document.getElementById('ecrPreviewBody');
    const loading = document.getElementById('ecrPreviewLoading');
    const note = document.getElementById('ecrPreviewLimitNote');
    if (head) head.innerHTML = '';
    if (body) body.innerHTML = '';
    if (note) { note.classList.add('d-none'); note.textContent = ''; }
    if (loading) loading.classList.remove('d-none');

    const params = new URLSearchParams({
        class_id: classId,
        term: term,
        academic_year: academicYear
    });

    fetch(ecrApiUrl('ecr-export-preview', params))
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                showNotification(d?.message || 'Failed to load ECR preview', 'danger');
                return;
            }
            ecrPreviewData = d;
            buildEcrPreviewTable(d);
            const noteParts = [];
            if (d.truncated) {
                const parts = [];
                if (d.truncated.ww) parts.push('Written Work');
                if (d.truncated.pt) parts.push('Performance Task');
                if (d.truncated.qa) parts.push('Quarterly Assessment');
                if (parts.length > 0) {
                    noteParts.push('Only the first 10 WW, first 10 PT, and first 3 Assessment items are included. Extra items were omitted: ' + parts.join(', ') + '.');
                }
            }
            const hasActive = ['ww','pt','qa'].some(key => (d.items?.[key] || []).some(item => String(item.status || '').toLowerCase() !== 'finished'));
            if (hasActive) {
                noteParts.push('Active activities are included in this ECR download for presentation/export consistency.');
            }
            if (note && noteParts.length > 0) {
                note.textContent = noteParts.join(' ');
                note.classList.remove('d-none');
            }
        })
        .catch(() => {
            showNotification('Preview failed. Downloading ECR directly instead.', 'warning');
            downloadEcrFromPreview();
        })
        .finally(() => {
            if (loading) loading.classList.add('d-none');
        });
}

function buildEcrPreviewTable(data) {
    const head = document.getElementById('ecrPreviewHead');
    const body = document.getElementById('ecrPreviewBody');
    if (!head || !body) return;

    const items = [];
    (data.items?.ww || []).forEach((item, i) => items.push({ ...item, label: `WW${i + 1}` }));
    (data.items?.pt || []).forEach((item, i) => items.push({ ...item, label: `PT${i + 1}` }));
    (data.items?.qa || []).forEach((item, i) => items.push({ ...item, label: `QA${i + 1}` }));

    ecrPreviewItems = items;

    const headerCells = ['<th>Student</th>']
        .concat(items.map(item => {
            const hps = item.total_score !== null && item.total_score !== undefined ? Number(item.total_score) : '';
            const title = item.title ? escapeHtml(String(item.title)) : 'Untitled';
            const status = String(item.status || '').toLowerCase();
            const statusLine = status && status !== 'finished'
                ? `<div class=\"text-warning small\">Active</div>`
                : '';
            return `<th>${escapeHtml(item.label)}<div class=\"text-muted small\">${title}</div><div class=\"text-muted small\">HPS ${escapeHtml(String(hps))}</div>${statusLine}</th>`;
        }))
        .join('');
    head.innerHTML = `<tr>${headerCells}</tr>`;

    const scores = data.scores || {};
    const rows = (data.students || []).map(student => {
        const cells = [];
        const name = escapeHtml(student.name || `${student.last_name || ''}, ${student.first_name || ''}`.trim());
        cells.push(`<td><div class=\"fw-semibold\">${name}</div><div class=\"text-muted small\">${escapeHtml(student.lrn || '')}</div></td>`);
        items.forEach(item => {
            const sMap = scores[String(student.id)] || scores[student.id] || {};
            const raw = sMap[String(item.id)] ?? sMap[item.id] ?? '';
            const value = raw !== null && raw !== undefined ? raw : '';
            const max = item.total_score !== null && item.total_score !== undefined ? ` max=\"${escapeHtml(String(item.total_score))}\"` : '';
            cells.push(`<td><input type=\"number\" step=\"0.01\" min=\"0\"${max} class=\"form-control form-control-sm ecr-score-input\" data-student-id=\"${student.id}\" data-item-id=\"${item.id}\" value=\"${escapeHtml(String(value))}\" disabled></td>`);
        });
        return `<tr>${cells.join('')}</tr>`;
    });
    body.innerHTML = rows.join('');
}

function downloadEcrFromPreview() {
    const classId = document.getElementById('gradeClassSelect')?.value;
    const term = document.getElementById('termSelect')?.value || 'Term1';
    const academicYear = document.getElementById('academicYearInput')?.value?.trim() || '';

    if (!classId || !academicYear) {
        showNotification('Please select a class and academic year', 'warning');
        return;
    }

    const btn = document.getElementById('ecrPreviewDownloadBtn');
    const oldHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparing...';
    }

    const params = new URLSearchParams({
        class_id: classId,
        term: term,
        academic_year: academicYear,
        format: 'xlsx'
    });
    let frame = document.getElementById('ecrDownloadFrame');
    if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'ecrDownloadFrame';
        frame.name = 'ecrDownloadFrame';
        frame.style.display = 'none';
        document.body.appendChild(frame);
    }
    frame.src = ecrApiUrl('ecr-export', params);

    setTimeout(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = oldHtml || '<i class="bi bi-download me-2"></i>Download ECR (.xlsx)';
        }
    }, 2500);
}

async function importEcrFile() {
    const fileInput = document.getElementById('ecrImportInput');
    const btn = document.getElementById('ecrImportBtn');
    const classId = document.getElementById('gradeClassSelect')?.value;
    const term = document.getElementById('termSelect')?.value || 'Term1';
    const academicYear = document.getElementById('academicYearInput')?.value?.trim() || '';

    if (!classId || !academicYear) {
        showNotification('Please select a class and academic year', 'warning');
        return;
    }
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showNotification('Please choose an ECR .xlsx file to import', 'warning');
        return;
    }

    const file = fileInput.files[0];
    const lowerName = file.name.toLowerCase();
    if (!lowerName.endsWith('.xlsx')) {
        showNotification('Invalid file type. Please upload an .xlsx ECR file', 'warning');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';
    }

    try {
        const uploadData = new FormData();
        uploadData.append('ecr_file', file);
        const uploadRes = await fetch(ecrApiUrl('ecr-upload'), {
            method: 'POST',
            body: uploadData
        });
        const uploadJson = await uploadRes.json();
        if (!uploadJson || !uploadJson.ok || !uploadJson.file_key) {
            throw new Error(uploadJson?.message || 'Failed to upload ECR file');
        }

        const importRes = await fetch(ecrApiUrl('ecr-import'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                file_key: uploadJson.file_key,
                class_id: classId,
                academic_year: academicYear,
                term: term,
                mode: 'merge'
            })
        });
        const importJson = await importRes.json();
        if (importJson && importJson.ok) {
            showNotification(importJson.message || 'ECR imported successfully', 'success');
            if (ecrPreviewModal) ecrPreviewModal.hide();
            loadGradesData();
        } else {
            throw new Error(importJson?.message || 'Failed to import ECR');
        }
    } catch (err) {
        showNotification(err.message || 'ECR import failed', 'danger');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Import ECR';
        }
    }
}
</script>
</body>
</html>
