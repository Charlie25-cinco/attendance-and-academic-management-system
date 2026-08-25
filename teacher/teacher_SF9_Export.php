<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['teacher', 'admin'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

http_response_code(410);
echo '<p style="padding:20px;font-family:Arial,sans-serif;">SF9 export has been removed from this system.</p>';
exit();

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'];

$classId = (int)($_GET['class_id'] ?? 0);
$studentId = (int)($_GET['student_id'] ?? 0);
$academicYear = trim($_GET['ay'] ?? date('Y') . '-' . (date('Y') + 1));
if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) $academicYear = date('Y') . '-' . (date('Y') + 1);
$gs = SshsGradeCalculator::gradingSystem($academicYear);
$termLabels = SshsGradeCalculator::validTerms($gs);
$export = $_GET['export'] ?? '';

$tc = 'term';
$has_quarter = dbHasColumn($db, 'grades', 'quarter');
if ($has_quarter) {
    $has_term = dbHasColumn($db, 'grades', 'term');
    $tc = $has_term ? 'term' : 'quarter';
}

if ($studentId === 0 && $classId > 0) {
    $classStmt = $db->prepare("SELECT c.*, u.first_name as t_first, u.last_name as t_last FROM classes c LEFT JOIN users u ON c.teacher_id = u.id WHERE c.id = ?" . ($role === 'teacher' ? " AND c.teacher_id = ?" : ""));
    $params = $role === 'teacher' ? [$classId, $teacherId] : [$classId];
    $classStmt->execute($params);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) { echo '<p style="padding:20px">Class not found or access denied.</p>'; exit(); }

    $stuStmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.reference_code FROM users u INNER JOIN enrollments e ON e.student_id = u.id WHERE e.class_id = ? AND u.role = 'student' ORDER BY u.last_name, u.first_name");
    $stuStmt->execute([$classId]);
    $stuList = $stuStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>SF9 - Select Student</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <style>
        body{font-family:system-ui,-apple-system,sans-serif;padding:24px;background:#f8fafc;color:#1e293b;}
        .sf9-card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:24px;max-width:720px;margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,0.05);}
        .back{display:inline-flex;align-items:center;gap:6px;color:#475569;text-decoration:none;font-size:14px;margin-bottom:16px;}
        .back:hover{color:#0f172a;}
        .search-wrap{position:relative;margin-bottom:16px;max-width:440px;}
        .search-wrap input{width:100%;box-sizing:border-box;padding:9px 36px 9px 34px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;outline:none;transition:border-color .15s;}
        .search-wrap input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.15);}
        .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;}
        .clear-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;font-size:14px;padding:2px 4px;display:none;}
        .clear-btn:hover{color:#475569;}
        .student-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;background:#fff;transition:background-color .15s;}
        .student-item:hover{background:#f1f5f9;}
        .student-link{color:#1e293b;text-decoration:none;font-weight:500;font-size:14px;flex-grow:1;}
        .dl-btn{display:inline-flex;align-items:center;gap:4px;background:#10b981;color:#fff;padding:4px 10px;border-radius:4px;text-decoration:none;font-size:12px;font-weight:600;transition:background-color .15s;}
        .dl-btn:hover{background:#059669;}
        mark{background:#fef08a;padding:0;font-weight:600;}
    </style>
    </head><body>
    <div class="sf9-card">
        <a class="back" href="javascript:history.back()"><i class="bi bi-arrow-left"></i> Back</a>
        <h2 style="margin:0 0 6px;font-size:20px;font-weight:700;">SF9 Progress Report Card — Select Student</h2>
        <p style="color:#64748b;font-size:13px;margin:0 0 16px;">Class: <strong><?php echo htmlspecialchars((string)($class['class_name'] ?? '')); ?></strong> (Grade <?php echo htmlspecialchars((string)($class['grade_level'] ?? '')); ?> - <?php echo htmlspecialchars((string)($class['section'] ?? '')); ?>)</p>
        <div class="search-wrap">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="sf9StudentSearch" placeholder="Search student name or reference code..." autocomplete="off">
            <button type="button" class="clear-btn" id="sf9ClearBtn" title="Clear"><i class="bi bi-x-circle-fill"></i></button>
        </div>
        <div id="sf9StudentList">
        <?php foreach ($stuList as $s): ?>
            <div class="student-item" data-search="<?php echo htmlspecialchars(strtolower($s['last_name'] . ' ' . $s['first_name'] . ' ' . ($s['reference_code'] ?? ''))); ?>" data-name="<?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?>" data-code="<?php echo htmlspecialchars((string)($s['reference_code'] ?? '')); ?>">
                <a class="student-link" href="?class_id=<?php echo $classId; ?>&student_id=<?php echo $s['id']; ?>&ay=<?php echo urlencode($academicYear); ?>">
                    <span class="s-name"><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></span>
                    <small style="color:#64748b;font-weight:400;margin-left:6px;" class="s-code">(<?php echo htmlspecialchars((string)($s['reference_code'] ?? '')); ?>)</small>
                </a>
                <a class="dl-btn" href="?class_id=<?php echo $classId; ?>&student_id=<?php echo $s['id']; ?>&ay=<?php echo urlencode($academicYear); ?>&export=xlsx"><i class="bi bi-file-earmark-excel"></i> XLSX</a>
            </div>
        <?php endforeach; ?>
        <?php if (empty($stuList)): ?><p style="color:#94a3b8;font-size:14px;text-align:center;padding:24px 0;">No enrolled students found.</p><?php endif; ?>
        </div>
    </div>
    <script>
    const searchInput = document.getElementById('sf9StudentSearch');
    const clearBtn = document.getElementById('sf9ClearBtn');
    const items = document.querySelectorAll('#sf9StudentList .student-item');

    function highlightText(text, query) {
        if (!query) return text;
        const reg = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(reg, '<mark>$1</mark>');
    }

    function runFilter() {
        const q = (searchInput?.value || '').trim().toLowerCase();
        if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';
        items.forEach(function(item) {
            const rawSearch = item.getAttribute('data-search') || '';
            const match = !q || rawSearch.includes(q);
            item.style.display = match ? 'flex' : 'none';
            const nameEl = item.querySelector('.s-name');
            const codeEl = item.querySelector('.s-code');
            const rawName = item.getAttribute('data-name') || '';
            const rawCode = item.getAttribute('data-code') || '';
            if (nameEl) nameEl.innerHTML = match && q ? highlightText(rawName, q) : rawName;
            if (codeEl) codeEl.innerHTML = '(' + (match && q ? highlightText(rawCode, q) : rawCode) + ')';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', runFilter);
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
                runFilter();
            }
        });
    }
    </script>
    </body></html>
    <?php
    exit();
}

if ($studentId === 0) {
    if ($classId > 0) { echo '<p style="padding:20px">Please select a student from the list above.</p>'; exit(); }
    echo '<p style="padding:20px">Please provide student_id and class_id parameters.</p>';
    exit();
}

if ($export === 'xlsx') {
    $stuStmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stuStmt->execute([$studentId]);
    $student = $stuStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) { echo '<p style="padding:20px">Student not found.</p>'; exit(); }

    $enrolledStmt = $db->prepare("
        SELECT c.id as class_id, c.class_name, c.grade_level, c.section, c.subject_category,
               u.first_name as t_first, u.last_name as t_last
        FROM enrollments e
        INNER JOIN classes c ON c.id = e.class_id
        LEFT JOIN users u ON u.id = c.teacher_id
        WHERE e.student_id = ? AND e.academic_year = ?
        ORDER BY c.class_name
    ");
    $enrolledStmt->execute([$studentId, $academicYear]);
    $enrolledClasses = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

    $gradesByClass = [];
    foreach ($enrolledClasses as $cls) {
        $gStmt = $db->prepare("
            SELECT g.{$tc} AS term, g.final_grade
            FROM grades g
            INNER JOIN class_subjects cs ON cs.id = g.class_subject_id AND cs.class_id = ?
            WHERE g.student_id = ? AND g.academic_year = ?
            ORDER BY g.{$tc}
        ");
        $gStmt->execute([$cls['class_id'], $studentId, $academicYear]);
        $gradeRows = $gStmt->fetchAll(PDO::FETCH_ASSOC);
        $byTerm = [];
        foreach ($gradeRows as $g) { $byTerm[$g['term']] = $g['final_grade']; }
        $gradesByClass[$cls['class_id']] = $byTerm;
    }

    $attStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE student_id = ? AND academic_year = ? GROUP BY status");
    $attStmt->execute([$studentId, $academicYear]);
    $attendance = [];
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $attendance[$r['status']] = (int)$r['cnt']; }

    $exporter = new Sf9Exporter();
    $exporter->setStudent($student);
    $exporter->setEnrolledClasses($enrolledClasses);
    $exporter->setGradesByClass($gradesByClass);
    $exporter->setAcademicYear($academicYear);
    $exporter->setAttendance($attendance);
    $filename = 'SF9_' . $student['last_name'] . '_' . $student['first_name'] . '_' . $academicYear . '.xlsx';
    $exporter->outputToBrowser($filename);
}

$stuStmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stuStmt->execute([$studentId]);
$student = $stuStmt->fetch(PDO::FETCH_ASSOC);
if (!$student) { echo '<p style="padding:20px">Student not found.</p>'; exit(); }

$enrolledStmt = $db->prepare("
    SELECT c.id as class_id, c.class_name, c.grade_level, c.section, c.subject_category,
           u.first_name as t_first, u.last_name as t_last
    FROM enrollments e
    INNER JOIN classes c ON c.id = e.class_id
    LEFT JOIN users u ON u.id = c.teacher_id
    WHERE e.student_id = ? AND e.academic_year = ?
    ORDER BY c.class_name
");
$enrolledStmt->execute([$studentId, $academicYear]);
$enrolledClasses = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

$gradesByClass = [];
foreach ($enrolledClasses as $cls) {
    $gStmt = $db->prepare("
        SELECT g.{$tc} AS term, g.final_grade
        FROM grades g
        INNER JOIN class_subjects cs ON cs.id = g.class_subject_id AND cs.class_id = ?
        WHERE g.student_id = ? AND g.academic_year = ?
        ORDER BY g.{$tc}
    ");
    $gStmt->execute([$cls['class_id'], $studentId, $academicYear]);
    $gradeRows = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    $byTerm = [];
    foreach ($gradeRows as $g) { $byTerm[$g['term']] = $g['final_grade']; }
    $gradesByClass[$cls['class_id']] = $byTerm;
}

$attStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE student_id = ? AND academic_year = ? GROUP BY status");
$attStmt->execute([$studentId, $academicYear]);
$attSummary = [];
foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $attSummary[$r['status']] = (int)$r['cnt']; }
$daysPresent = ($attSummary['present'] ?? 0) + ($attSummary['late'] ?? 0);
$daysAbsent = $attSummary['absent'] ?? 0;
$daysLate = $attSummary['late'] ?? 0;

$studentName = $student['last_name'] . ', ' . $student['first_name'] . ($student['middle_name'] ? ' ' . $student['middle_name'] : '');
$termHeaderCount = count($termLabels);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SF9 - Report Card - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 10px; color: #000; background: #fff; }
.page { width: 216mm; min-height: 279mm; padding: 10mm 10mm 10mm 14mm; }
.school-header { text-align: center; margin-bottom: 6px; }
.school-name { font-size: 13px; font-weight: bold; text-transform: uppercase; }
.school-sub { font-size: 10px; }
.form-title { text-align: center; font-size: 11px; font-weight: bold; text-transform: uppercase; margin: 6px 0 2px; border: 2px solid #000; padding: 3px 0; }
.learner-info { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 12px; margin: 6px 0; border: 1px solid #000; padding: 6px; }
.info-item { display: flex; align-items: flex-end; font-size: 9px; }
.info-label { font-weight: bold; white-space: nowrap; margin-right: 4px; }
.info-value { border-bottom: 1px solid #000; flex: 1; }
table { border-collapse: collapse; width: 100%; margin-top: 8px; }
th, td { border: 1px solid #000; padding: 2px 4px; text-align: center; vertical-align: middle; font-size: 9px; }
th { background: #ddd; font-weight: bold; font-size: 8.5px; }
.subject-col { text-align: left; font-size: 9px; }
.term-col { width: 40px; }
.avg-col { width: 45px; font-weight: bold; }
.remarks-col { width: 80px; font-size: 8px; }
.promoted { color: #155724; font-weight: bold; }
.retained { color: #721c24; font-weight: bold; }
.att-box { border: 1px solid #000; padding: 6px; margin-top: 8px; display: flex; gap: 16px; font-size: 9.5px; }
.att-item { flex: 1; text-align: center; }
.att-item strong { display: block; font-size: 16px; font-weight: bold; }
.signature-row { display: flex; gap: 20px; margin-top: 20px; }
.sig-block { flex: 1; }
.sig-line { border-bottom: 1px solid #000; margin-bottom: 2px; height: 28px; }
.sig-label { font-size: 8px; text-align: center; }
.no-print { background: #1a237e; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; margin: 8px; display: inline-block; text-decoration: none; }
@media print { .no-print { display: none !important; } }
.legend-box { font-size: 7.5px; margin-top: 6px; padding: 4px; border: 1px solid #ccc; display: flex; gap: 8px; flex-wrap: wrap; }
.footer-note { font-size: 7px; margin-top: 8px; color: #555; }
</style>
</head>
<body>
<div style="margin:8px;" class="no-print">
    <button class="no-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
    <a class="no-print" href="?class_id=<?php echo $classId; ?>&student_id=<?php echo $studentId; ?>&ay=<?php echo urlencode($academicYear); ?>&export=xlsx" style="background:#2e7d32;color:#fff;padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:12px;text-decoration:none;">&#8595; Download XLSX</a>
    <a class="no-print" href="javascript:history.back()">&#8592; Back</a>
</div>

<div class="page">
    <div class="school-header">
        <div class="school-name">Republic of the Philippines &nbsp;•&nbsp; Department of Education</div>
        <div class="school-name">Balingasag Senior High School</div>
        <div class="school-sub">Balingasag, Misamis Oriental</div>
    </div>

    <div class="form-title">School Form 9 (SF9) &nbsp;•&nbsp; Learner's Progress Report Card</div>

    <div class="learner-info">
        <div class="info-item"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($studentName); ?></span></div>
        <div class="info-item"><span class="info-label">LRN:</span><span class="info-value"><?php echo htmlspecialchars($student['lrn'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Grade Level:</span><span class="info-value"><?php echo htmlspecialchars($student['grade_level'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Section:</span><span class="info-value"><?php echo htmlspecialchars($student['section'] ?? ''); ?></span></div>
        <div class="info-item"><span class="info-label">Sex:</span><span class="info-value"><?php echo htmlspecialchars(ucfirst($student['sex'] ?? '')); ?></span></div>
        <div class="info-item"><span class="info-label">Academic Year:</span><span class="info-value"><?php echo htmlspecialchars($academicYear); ?></span></div>
        <div class="info-item"><span class="info-label">School:</span><span class="info-value">Balingasag Senior High School</span></div>
        <div class="info-item"><span class="info-label">Date Printed:</span><span class="info-value"><?php echo date('F j, Y'); ?></span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" class="subject-col">Learning Area / Subject</th>
                <th colspan="<?php echo $termHeaderCount; ?>">Term Grades</th>
                <th rowspan="2" class="avg-col">Final<br>Grade</th>
                <th rowspan="2" class="remarks-col">Level of Proficiency</th>
                <th rowspan="2" class="remarks-col">Remarks</th>
            </tr>
            <tr>
                <?php foreach ($termLabels as $tl): ?>
                    <th class="term-col"><?php echo htmlspecialchars(SshsGradeCalculator::termLabel($tl)); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $overallSum = 0; $overallCount = 0;
        $processedECMK = false;
        $ecGrades = []; $mkGrades = [];
        $ecClassId = null; $mkClassId = null;

        foreach ($enrolledClasses as $cls) {
            $subjectKey = strtolower(trim($cls['class_name'] ?? ''));
            $combinedKey = SshsGradeCalculator::combinedSubjectKey($subjectKey);
            if ($combinedKey === 'ec') {
                $ecGrades = $gradesByClass[$cls['class_id']] ?? [];
                $ecClassId = $cls;
            } elseif ($combinedKey === 'mk') {
                $mkGrades = $gradesByClass[$cls['class_id']] ?? [];
                $mkClassId = $cls;
            }
        }

        if ($ecClassId !== null || $mkClassId !== null) {
            $displayName = SshsGradeCalculator::combinedDisplayName();
            $combinedGrades = [];
            foreach ($termLabels as $tl) {
                $ec = $ecGrades[$tl] ?? null;
                $mk = $mkGrades[$tl] ?? null;
                $combinedGrades[$tl] = SshsGradeCalculator::combineGrades($ec, $mk);
            }
            $cat = $ecClassId['subject_category'] ?? ($mkClassId['subject_category'] ?? 'core');
            $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
            $termVals = [];
            foreach ($termLabels as $tl) {
                $termVals[] = $combinedGrades[$tl] ?? null;
            }
            $effectiveGrades = array_slice($termVals, 0, $termCount);
            $avg = SshsGradeCalculator::finalGrade($effectiveGrades);
            if ($avg !== null) { $overallSum += $avg; $overallCount++; }
            $promoted = $avg !== null && $avg >= 75;
        ?>
            <tr>
                <td class="subject-col"><?php echo htmlspecialchars($displayName); ?></td>
                <?php foreach ($termLabels as $i => $tl): ?>
                    <td class="term-col"><?php
                        $g = $termVals[$i] ?? null;
                        echo $g !== null ? number_format($g, 0) : '—';
                    ?></td>
                <?php endforeach; ?>
                <td class="avg-col"><?php echo $avg !== null ? number_format($avg, 0) : '—'; ?></td>
                <td style="font-size:8px;"><?php echo SshsGradeCalculator::sf9Level($avg); ?></td>
                <td class="<?php echo $avg !== null ? ($promoted ? 'promoted' : 'retained') : ''; ?>"><?php echo $avg !== null ? ($promoted ? 'Passed' : 'Failed') : '—'; ?></td>
            </tr>
        <?php
            $processedECMK = true;
        }

        foreach ($enrolledClasses as $cls) {
            $subjectKey = strtolower(trim($cls['class_name'] ?? ''));
            if (SshsGradeCalculator::combinedSubjectKey($subjectKey) !== '') continue;

            $byTerm = $gradesByClass[$cls['class_id']] ?? [];
            $cat = $cls['subject_category'] ?? 'core';
            $termCount = SshsGradeCalculator::subjectTermCount($cat, $gs);
            $termVals = [];
            foreach ($termLabels as $tl) {
                $termVals[] = $byTerm[$tl] ?? null;
            }
            $effectiveGrades = array_slice($termVals, 0, $termCount);
            $avg = SshsGradeCalculator::finalGrade($effectiveGrades);
            if ($avg !== null) { $overallSum += $avg; $overallCount++; }
            $promoted = $avg !== null && $avg >= 75;
        ?>
        <tr>
            <td class="subject-col"><?php echo htmlspecialchars($cls['class_name']); ?></td>
            <?php foreach ($termLabels as $i => $tl): ?>
                <td class="term-col"><?php
                    $g = $termVals[$i] ?? null;
                    echo $g !== null ? number_format($g, 0) : '—';
                ?></td>
            <?php endforeach; ?>
            <td class="avg-col"><?php echo $avg !== null ? number_format($avg, 0) : '—'; ?></td>
            <td style="font-size:8px;"><?php echo SshsGradeCalculator::sf9Level($avg); ?></td>
            <td class="<?php echo $avg !== null ? ($promoted ? 'promoted' : 'retained') : ''; ?>"><?php echo $avg !== null ? ($promoted ? 'Passed' : 'Failed') : '—'; ?></td>
        </tr>
        <?php } ?>
        <tr style="background:#eee;font-weight:bold;">
            <td class="subject-col"><strong>General Average</strong></td>
            <td colspan="<?php echo $termHeaderCount; ?>"></td>
            <td class="avg-col">
                <?php $gpa = $overallCount > 0 ? $overallSum / $overallCount : null; echo $gpa !== null ? number_format($gpa, 2) : '—'; ?>
            </td>
            <td style="font-size:8px;"><?php echo SshsGradeCalculator::sf9Level($gpa); ?></td>
            <td class="<?php echo $gpa !== null ? ($gpa >= 75 ? 'promoted' : 'retained') : ''; ?>">
                <?php echo $gpa !== null ? ($gpa >= 75 ? '<strong>PROMOTED</strong>' : '<strong>RETAINED</strong>') : '—'; ?>
            </td>
        </tr>
        </tbody>
    </table>

    <div class="legend-box">
        <strong>Level of Proficiency:</strong>
        <span>O = Outstanding (90-100)</span>
        <span>VS = Very Satisfactory (85-89)</span>
        <span>S = Satisfactory (80-84)</span>
        <span>FS = Fairly Satisfactory (75-79)</span>
        <span>DNME = Did Not Meet Expectations (Below 75)</span>
    </div>

    <div class="att-box">
        <div class="att-item"><strong><?php echo $daysPresent; ?></strong>Days Present</div>
        <div class="att-item"><strong><?php echo $daysAbsent; ?></strong>Days Absent</div>
        <div class="att-item"><strong><?php echo $daysLate; ?></strong>Days Tardy</div>
    </div>

    <div class="signature-row">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Adviser's Signature over Printed Name</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Parent/Guardian Signature &amp; Date Received</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Principal's Signature over Printed Name</div>
        </div>
    </div>
    <div class="footer-note">
        <?php echo $gs === '4_quarter'
            ? 'Per DepEd Order No. 8, s. 2015 | DM 74, s. 2025 (SSHS)'
            : 'Per DepEd Order No. 8, s. 2015 | DO 009, s. 2026 (Three-Term Calendar) | DM 12, s. 2026 (SSHS)'; ?>
        &nbsp;|&nbsp; BSHS-AMS Generated on <?php echo date('F j, Y \a\t g:i A'); ?>
    </div>
</div>
</body>
</html>
