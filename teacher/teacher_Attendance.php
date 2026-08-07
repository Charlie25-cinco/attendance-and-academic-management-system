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
$students = [];
$selectedClassId = 0;
$canEditSelectedClass = false;
$editBlockedReason = '';
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'rate' => 0];
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
$selectedSex = strtolower(trim((string)($_GET['sex'] ?? '')));
if (!in_array($selectedSex, ['male', 'female'], true)) {
    $selectedSex = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

if ($db) {
    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule
                               FROM class_subjects cs
                               JOIN classes c ON c.id = cs.class_id
                               WHERE cs.teacher_id = ?
                               AND c.status = 'active'
                               AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                               GROUP BY c.id, c.class_name, c.grade_level, c.section
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $selectedClassId = (int)($_GET['class_id'] ?? $classes[0]['id']);
        $classIds = array_map('intval', array_column($classes, 'id'));
        if (!in_array($selectedClassId, $classIds, true)) {
            $selectedClassId = (int)$classes[0]['id'];
        }

        $selectedClass = null;
        foreach ($classes as $class) {
            if ((int)$class['id'] === $selectedClassId) {
                $selectedClass = $class;
                break;
            }
        }

        $canEditSelectedClass = $selectedClass !== null && ScheduleParser::hasScheduleOnDate($selectedClass['schedule'] ?? '', $selectedDate);
        if (!$canEditSelectedClass) {
            $editBlockedReason = 'This class has no schedule on the selected date.';
        }
        $students = tEnrollFetchStudentsWithAttendance($db, $selectedClassId, $selectedDate, $selectedSex);

        $summary['total'] = count($students);
        foreach ($students as $s) {
            $st = (string)($s['attendance_status'] ?? '');
            if (isset($summary[$st])) {
                $summary[$st]++;
            }
        }
        if ($summary['total'] > 0) {
            $summary['rate'] = round((($summary['present'] + $summary['late']) / $summary['total']) * 100, 1);
        }
    }
}

$current_role = 'teacher';
$current_page = 'attendance';
$page_title = 'Attendance';
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
    <style>
    .teacher-status { display: inline-flex; align-items: center; gap: 8px; padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; transition: all 0.2s; }
    .teacher-status.present { background: rgba(16, 185, 129, 0.1); color: var(--accent-color); }
    .teacher-status.absent { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
    .teacher-status.late { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
    </style>
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
                    <div class="app-detail-kicker mb-2">Daily Attendance</div>
                    <h4 class="mb-2">Attendance</h4>
                    <p class="mb-0">Record daily attendance by class, switch between learners quickly, and keep present, absent, and late counts visible while you work.</p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip"><i class="bi bi-journal-bookmark"></i><?php echo number_format(count($classes)); ?> subject classes</span>
                        <span class="teacher-chip"><i class="bi bi-calendar3"></i><?php echo htmlspecialchars($selectedDate); ?></span>
                        <span class="teacher-chip"><i class="bi bi-people-fill"></i><?php echo number_format((int)$summary['total']); ?> students in view</span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Attendance Rate</span>
                            <span class="teacher-summary-value"><?php echo number_format((float)$summary['rate'], 1); ?>%</span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Edit State</span>
                            <span class="teacher-summary-value"><?php echo $canEditSelectedClass ? 'Open' : 'Locked'; ?></span>
                        </div>
                    </div>
                    <p class="teacher-summary-note"><?php echo $canEditSelectedClass ? 'This class is editable for the selected date.' : htmlspecialchars($editBlockedReason !== '' ? $editBlockedReason : 'Attendance is unavailable for the selected date.'); ?></p>
                </div>
            </div>
        </div>

        <div class="attendance-sheet">
            <div class="attendance-header">
                <div class="attendance-class-info">
                    <h3><i class="bi bi-calendar-check me-2"></i>Take Attendance</h3>
                    <p>Record attendance by subject class.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap mt-3 app-filter-panel" style="width: 100%;">
                    <button class="btn btn-outline-primary" type="button" onclick="openQrScanner()"><i class="bi bi-qr-code-scan me-1"></i>QR Scan Mode</button>
                    <div class="attendance-date-picker"><i class="bi bi-calendar3"></i><input type="date" id="attendanceDate" value="<?php echo htmlspecialchars($selectedDate); ?>"></div>
                    <select id="classSelect" class="form-select" style="width: auto; border-radius: 10px;">
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$class['id'] === $selectedClassId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' (G' . $class['grade_level'] . ' - ' . $class['section'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="sexFilter" class="form-select" style="width: auto; border-radius: 10px;">
                        <option value="" <?php echo $selectedSex === '' ? 'selected' : ''; ?>>All Sex</option>
                        <option value="male" <?php echo $selectedSex === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo $selectedSex === 'female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                    <div class="input-group" style="max-width: 260px;">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="attendanceSearch" class="form-control" placeholder="Search student or reference">
                    </div>
                    <button class="btn btn-secondary-custom" onclick="loadAttendanceData()"><i class="bi bi-arrow-clockwise me-1"></i>Load</button>
                    <button class="btn btn-outline-success" type="button" onclick="exportAttendanceSf2('xlsx')" <?php echo empty($classes) ? 'disabled' : ''; ?>><i class="bi bi-filetype-xlsx me-1"></i>SF2 XLSX</button>
                    <button class="btn btn-outline-success" type="button" onclick="exportAttendanceSf2('csv')" <?php echo empty($classes) ? 'disabled' : ''; ?>><i class="bi bi-filetype-csv me-1"></i>SF2 CSV</button>
                </div>
            </div>
            <div class="p-3 border-top border-bottom">
                <div class="teacher-kpi-grid">
                    <div class="teacher-kpi-card">
                        <span>Present</span>
                        <strong id="presentCount"><?php echo (int)$summary['present']; ?></strong>
                    </div>
                    <div class="teacher-kpi-card">
                        <span>Absent</span>
                        <strong id="absentCount"><?php echo (int)$summary['absent']; ?></strong>
                    </div>
                    <div class="teacher-kpi-card">
                        <span>Late</span>
                        <strong id="lateCount"><?php echo (int)$summary['late']; ?></strong>
                    </div>
                    <div class="teacher-kpi-card">
                        <span>Total</span>
                        <strong><?php echo (int)$summary['total']; ?></strong>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table class="custom-table attendance-table">
                    <thead><tr><th>Student</th><th>Reference Code</th><th>Status</th><th>Remarks</th></tr></thead>
                    <tbody id="attendanceBody">
                        <?php if (empty($students)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No enrolled students for this class.</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <tr data-student-id="<?php echo (int)$student['id']; ?>">
                                    <td><div class="student-info"><div class="user-avatar-small"><?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?></div><span class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div></td>
                                    <td><?php echo htmlspecialchars($student['reference_code']); ?></td>
                                    <td>
                                        <button type="button" class="teacher-status <?php echo htmlspecialchars($student['attendance_status']); ?>" data-status="<?php echo htmlspecialchars($student['attendance_status']); ?>" onclick="cycleStatus(this)">
                                            <?php if ($student['attendance_status'] === 'present'): ?><i class="bi bi-check-circle"></i> Present<?php elseif ($student['attendance_status'] === 'absent'): ?><i class="bi bi-x-circle"></i> Absent<?php else: ?><i class="bi bi-clock"></i> Late<?php endif; ?>
                                        </button>
                                    </td>
                                    <td><input type="text" class="form-control form-control-sm attendance-remarks" value="<?php echo htmlspecialchars($student['remarks']); ?>" placeholder="Add remarks..."></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="teacher-section-note">
                        Status buttons cycle through <strong>Present</strong>, <strong>Absent</strong>, and <strong>Late</strong>. Add remarks only when needed.
                    </div>
                    <button class="btn btn-primary-custom" id="submitAttendanceBtn" onclick="submitAttendance()"><i class="bi bi-save me-2"></i>Submit Attendance</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="../assets/js/networkSync.js"></script>
<script src="../assets/js/main.js"></script>
<script>
const statusCycle=['present','absent','late'];
let canEditAttendance=<?php echo $canEditSelectedClass ? 'true' : 'false'; ?>;
let editBlockedReason=<?php echo json_encode($editBlockedReason); ?>;
const csrfToken=(window.APP_CSRF_TOKEN||'').toString();
const statusTemplate={present:'<i class="bi bi-check-circle"></i> Present',absent:'<i class="bi bi-x-circle"></i> Absent',late:'<i class="bi bi-clock"></i> Late'};
function updateEditState(){const btn=document.getElementById('submitAttendanceBtn');if(!btn)return;if(canEditAttendance){btn.disabled=false;btn.title='';btn.innerHTML='<i class=\"bi bi-save me-2\"></i>Submit Attendance';}else{btn.disabled=true;btn.title=editBlockedReason||'Attendance is unavailable for this class on the selected date';btn.innerHTML='<i class=\"bi bi-eye me-2\"></i>Attendance Unavailable';}}
function cycleStatus(button){if(!canEditAttendance){return;}const current=button.dataset.status||'present';const next=statusCycle[(statusCycle.indexOf(current)+1)%statusCycle.length];button.dataset.status=next;button.classList.remove('present','absent','late');button.classList.add(next);button.innerHTML=statusTemplate[next];updateSummaryCounts();}
function updateSummaryCounts(summary){if(summary){document.getElementById('presentCount').textContent=summary.present||0;document.getElementById('absentCount').textContent=summary.absent||0;document.getElementById('lateCount').textContent=summary.late||0;return;}let p=0,a=0,l=0;document.querySelectorAll('#attendanceBody tr[data-student-id]').forEach(r=>{const s=r.querySelector('.teacher-status')?.dataset.status;if(s==='present')p++;if(s==='absent')a++;if(s==='late')l++;});document.getElementById('presentCount').textContent=p;document.getElementById('absentCount').textContent=a;document.getElementById('lateCount').textContent=l;}
function applySearchFilter(){const input=document.getElementById('attendanceSearch');const q=(input?.value||'').trim().toLowerCase();const tbody=document.getElementById('attendanceBody');if(!tbody){return;}const rows=Array.from(tbody.querySelectorAll('tr[data-student-id]'));if(rows.length===0){return;}let visible=0;rows.forEach(r=>{const name=r.querySelector('.student-name')?.textContent?.toLowerCase()||'';const code=r.children?.[1]?.textContent?.toLowerCase()||'';const match=!q||name.includes(q)||code.includes(q);r.style.display=match?'':'none';if(match){visible++;}});let emptyRow=document.getElementById('attendanceSearchEmpty');if(visible===0){if(!emptyRow){emptyRow=document.createElement('tr');emptyRow.id='attendanceSearchEmpty';emptyRow.innerHTML='<td colspan="4" class="text-center text-muted py-4">No students match your search.</td>';tbody.appendChild(emptyRow);}emptyRow.style.display='';}else if(emptyRow){emptyRow.style.display='none';}}
function renderStudents(students){const tbody=document.getElementById('attendanceBody');if(!students||students.length===0){tbody.innerHTML='<tr><td colspan="4" class="text-center text-muted py-4">No enrolled students for this class.</td></tr>';updateSummaryCounts({present:0,absent:0,late:0});return;}tbody.innerHTML=students.map(s=>{const fn=`${s.first_name} ${s.last_name}`;const inits=`${s.first_name[0]||''}${s.last_name[0]||''}`.toUpperCase();const st=s.attendance_status||'present';return `<tr data-student-id="${s.id}"><td><div class="student-info"><div class="user-avatar-small">${inits}</div><span class="student-name">${escapeHtml(fn)}</span></div></td><td>${escapeHtml(s.reference_code)}</td><td><button type="button" class="teacher-status ${st}" data-status="${st}" onclick="cycleStatus(this)">${statusTemplate[st]||statusTemplate.present}</button></td><td><input type="text" class="form-control form-control-sm attendance-remarks" value="${escapeHtml(s.remarks||'')}" placeholder="Add remarks..."></td></tr>`;}).join('');updateSummaryCounts();applySearchFilter();}
function loadAttendanceData(){const classId=(document.getElementById('classSelect')?.value||'').trim();const date=(document.getElementById('attendanceDate')?.value||'').trim();const sex=(document.getElementById('sexFilter')?.value||'').trim().toLowerCase();if(!classId){showNotification('No subject class available for this teacher account','warning');return;}if(!date){showNotification('Please select date','warning');return;}let url=`teacher_Action.php?action=fetch_students&class_id=${encodeURIComponent(classId)}&date=${encodeURIComponent(date)}&mode=subject`;if(sex==='male'||sex==='female'){url+=`&sex=${encodeURIComponent(sex)}`;}fetch(url).then(r=>r.json()).then(d=>{if(!d.success){showNotification(d.message||'Failed to load attendance data','danger');return;}canEditAttendance=!!d.can_edit;editBlockedReason=(d.message||'').trim();renderStudents(d.students||[]);updateSummaryCounts(d.summary||null);updateEditState();if(!canEditAttendance&&editBlockedReason){showNotification(editBlockedReason,'warning');}}).catch(()=>showNotification('Error loading attendance data','danger'));}
function exportAttendanceSf2(format){const classId=(document.getElementById('classSelect')?.value||'').trim();const dateValue=(document.getElementById('attendanceDate')?.value||'').trim();if(!classId){showNotification('Select a class for SF2 export','warning');return;}if(!dateValue||!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)){showNotification('Select a valid attendance date for SF2 export','warning');return;}const parts=dateValue.split('-');const params=new URLSearchParams({class_id:classId,month:String(Number(parts[1])),year:parts[0],export:format==='csv'?'csv':'xlsx'});window.location.href='teacher_SF2_Export.php?'+params.toString();}
function submitAttendance(){if(!canEditAttendance){showNotification(editBlockedReason||'Attendance is unavailable for this class on the selected date','info');return;}const classId=document.getElementById('classSelect')?.value||'';const date=document.getElementById('attendanceDate')?.value||'';const rows=document.querySelectorAll('#attendanceBody tr[data-student-id]');if(!classId||!date||rows.length===0){showNotification('Nothing to submit','warning');return;}const records=Array.from(rows).map(r=>({student_id:parseInt(r.dataset.studentId,10),status:r.querySelector('.teacher-status')?.dataset.status||'present',remarks:r.querySelector('.attendance-remarks')?.value.trim()||''}));const btn=document.getElementById('submitAttendanceBtn');btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving...';if(window.bshsOffline&&!window.bshsOffline.isOnline()){window.bshsOffline.queueAttendance(classId,date,records,csrfToken);btn.disabled=false;btn.innerHTML='<i class=\"bi bi-save me-2\"></i>Submit Attendance';updateEditState();return;}fetch(withCsrfUrl('teacher_Action.php?action=submit_attendance'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({class_id:parseInt(classId,10),date:date,mode:'subject',records:records})}).then(r=>r.json()).then(d=>{if(d.success){showNotification(d.message||'Attendance submitted successfully','success');updateSummaryCounts(d.summary||null);}else{showNotification(d.message||'Failed to submit attendance','danger');}}).catch(function(){if(window.bshsOffline){window.bshsOffline.queueAttendance(classId,date,records,csrfToken);showNotification('Network error. Attendance queued for sync when online.','warning');}else{showNotification('Error submitting attendance','danger');}}).finally(()=>{updateEditState();});}
updateEditState();
applySearchFilter();
document.getElementById('attendanceSearch')?.addEventListener('input', applySearchFilter);

// QR Scanner
let qrScannerInstance = null;
let qrScannerLibLoaded = false;

function openQrScanner() {
    const classId = document.getElementById('classSelect')?.value;
    const date = document.getElementById('attendanceDate')?.value;
    if (!classId || !date) { showNotification('Please select a class and date first.', 'warning'); return; }
    if (!canEditAttendance) { showNotification(editBlockedReason || 'Cannot edit attendance for this class/date.', 'info'); return; }

    if (!qrScannerLibLoaded) {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
        script.crossOrigin = 'anonymous';
        script.onload = () => { qrScannerLibLoaded = true; showQrModal(); };
        script.onerror = () => showNotification('QR scanner library failed to load. Check internet connection.', 'danger');
        document.head.appendChild(script);
    } else {
        showQrModal();
    }
}

function showQrModal() {
    let modal = document.getElementById('qrScannerModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'qrScannerModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-qr-code-scan"></i> QR Scanner</div>
                        <h5 class="modal-title mb-0">Scan Student QR Code</h5>
                        <p class="app-modal-subtitle">Point the camera at the student's QR code to mark attendance.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopQrScanner()"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <div id="qr-reader" style="width:100%;"></div>
                    <div id="qrScanResult" class="mt-3"></div>
                    <div class="mt-2 text-muted small text-center">
                        <i class="bi bi-info-circle me-1"></i>Student must show their QR code from the "My QR Code" section.
                    </div>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopQrScanner()">Close</button>
                </div>
            </div>
        </div>`;
        document.body.appendChild(modal);
    }

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();

    modal.addEventListener('shown.bs.modal', function startScanner() {
        modal.removeEventListener('shown.bs.modal', startScanner);
        if (typeof Html5Qrcode === 'undefined') { showNotification('QR scanner not loaded.', 'danger'); return; }
        qrScannerInstance = new Html5Qrcode('qr-reader');
        qrScannerInstance.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => handleQrScan(decodedText),
            () => {}
        ).catch(err => {
            document.getElementById('qrScanResult').innerHTML = '<div class="alert alert-warning">Camera error: ' + err + '</div>';
        });
    });

    modal.addEventListener('hide.bs.modal', function() { stopQrScanner(); }, { once: true });
}

function stopQrScanner() {
    if (qrScannerInstance) {
        qrScannerInstance.stop().catch(() => {});
        qrScannerInstance = null;
    }
}

let scannedCodes = new Set();
function handleQrScan(refCode) {
    if (scannedCodes.has(refCode)) return;

    // Find student row with matching reference code
    const rows = document.querySelectorAll('#attendanceBody tr[data-student-id]');
    let found = false;
    rows.forEach(row => {
        const codeCell = row.children[1]?.textContent?.trim();
        if (codeCell === refCode) {
            found = true;
            const btn = row.querySelector('.teacher-status');
            if (btn && btn.dataset.status !== 'present') {
                btn.dataset.status = 'present';
                btn.className = 'teacher-status present';
                btn.innerHTML = statusTemplate.present;
                updateSummaryCounts();
            }
            const name = row.querySelector('.student-name')?.textContent || refCode;
            const result = document.getElementById('qrScanResult');
            if (result) {
                result.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><strong>' +
                    escapeHtml(name) + '</strong> marked as <strong>Present</strong></div>';
            }
            row.style.background = '#d1fae5';
            setTimeout(() => { row.style.background = ''; }, 2000);
            scannedCodes.add(refCode);
        }
    });

    if (!found) {
        const result = document.getElementById('qrScanResult');
        if (result) result.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Student <strong>' + escapeHtml(refCode) + '</strong> not found in this class list.</div>';
    }
}
</script>
</body>
</html>
