<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Teacher - SF5 Report on Promotion and Level of Proficiency (Printable + XLSX)
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['teacher', 'admin'], true)) {
    header("Location: ../auth/Login.php");
    exit();
}

http_response_code(410);
echo '<p style="padding:20px;font-family:Arial,sans-serif;">SF5 export has been removed from this system.</p>';
exit();

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'];

$classId = (int)($_GET['class_id'] ?? 0);
$quarter = strtoupper(trim($_GET['quarter'] ?? 'Term1'));
if (!in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4', 'Term1', 'Term2', 'Term3'], true)) $quarter = 'Term1';
$academicYear = trim($_GET['ay'] ?? date('Y') . '-' . (date('Y') + 1));
if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) $academicYear = date('Y') . '-' . (date('Y') + 1);
$gs = SshsGradeCalculator::gradingSystem($academicYear);
$export = $_GET['export'] ?? '';

// Get class info
$classStmt = $db->prepare("SELECT c.*, u.first_name as t_first, u.last_name as t_last FROM classes c LEFT JOIN users u ON c.teacher_id = u.id WHERE c.id = ?" . ($role === 'teacher' ? " AND c.teacher_id = ?" : ""));
$params = $role === 'teacher' ? [$classId, $teacherId] : [$classId];
$classStmt->execute($params);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);
if (!$class) { echo '<p style="padding:20px">Class not found or access denied.</p>'; exit(); }

// Get students and their grades for this class
$tc = 'term';
$has_quarter = dbHasColumn($db, 'grades', 'quarter');
if ($has_quarter) {
    $has_term = dbHasColumn($db, 'grades', 'term');
    $tc = $has_term ? 'term' : 'quarter';
}
$stuStmt = $db->prepare("
    SELECT u.id, u.first_name, u.middle_name, u.last_name, u.reference_code, u.lrn, u.sex,
           g.final_grade, g.{$tc} AS period
    FROM users u
    INNER JOIN enrollments e ON e.student_id = u.id AND e.class_id = ?
    LEFT JOIN class_subjects cs ON cs.class_id = ?
    LEFT JOIN grades g ON g.student_id = u.id AND g.class_subject_id = cs.id AND g.{$tc} = ? AND g.academic_year = ?
    WHERE u.role = 'student' AND u.status = 'active'
    ORDER BY u.last_name, u.first_name
");
$stuStmt->execute([$classId, $classId, $quarter, $academicYear]);
$students = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

$teacherName = trim(($class['t_first'] ?? '') . ' ' . ($class['t_last'] ?? ''));

function proficiencyLevel($grade) {
    if ($grade === null) return 'N/A';
    if ($grade >= 90) return 'Outstanding';
    if ($grade >= 85) return 'Very Satisfactory';
    if ($grade >= 80) return 'Satisfactory';
    if ($grade >= 75) return 'Fairly Satisfactory';
    return 'Did Not Meet Expectations';
}
function promotionStatus($grade) {
    if ($grade === null) return 'No Grade';
    return $grade >= 75 ? 'Promoted' : 'Retained/For Remediation';
}

// Handle XLSX export
if ($export === 'xlsx') {
    $exporter = new Sf5Exporter();
    $exporter->setClass($class);
    $exporter->setStudents($students);
    $exporter->setTerm($quarter);
    $exporter->setAcademicYear($academicYear);
    $exporter->setTeacherName($teacherName);
    $filename = 'SF5_' . $class['class_name'] . '_' . $quarter . '_' . $academicYear . '.xlsx';
    $exporter->outputToBrowser($filename);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SF5 - Promotion Report - <?php echo htmlspecialchars($class['class_name']); ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 10px; color: #000; background: #fff; }
.page { width: 210mm; min-height: 297mm; padding: 12mm 12mm 12mm 18mm; }
.school-header { text-align: center; margin-bottom: 6px; }
.school-header .school-name { font-size: 13px; font-weight: bold; text-transform: uppercase; }
.school-header .school-sub { font-size: 10px; }
.form-title { text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; margin: 6px 0 2px; border-top: 2px solid #000; border-bottom: 1px solid #000; padding: 2px 0; }
.meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; margin: 6px 0; }
.meta-item { display: flex; align-items: flex-end; font-size: 9.5px; }
.meta-label { font-weight: bold; white-space: nowrap; margin-right: 4px; }
.meta-value { border-bottom: 1px solid #000; flex: 1; }
table { border-collapse: collapse; width: 100%; margin-top: 8px; }
th, td { border: 1px solid #000; padding: 3px 4px; text-align: center; vertical-align: middle; font-size: 9px; }
th { background: #eee; font-weight: bold; font-size: 8.5px; }
.name-col { text-align: left; width: 160px; }
.grade-col { width: 50px; }
.level-col { width: 120px; font-size: 8px; }
.status-col { width: 100px; font-size: 8px; }
.promoted { color: #155724; }
.retained { color: #721c24; }
.summary-box { border: 1px solid #000; padding: 6px; margin-top: 12px; display: flex; gap: 16px; font-size: 9.5px; }
.summary-item { flex: 1; text-align: center; }
.summary-item strong { display: block; font-size: 14px; }
.signature-row { display: flex; gap: 24px; margin-top: 24px; }
.sig-block { flex: 1; }
.sig-line { border-bottom: 1px solid #000; margin-bottom: 2px; height: 28px; }
.sig-label { font-size: 8px; text-align: center; }
.no-print { background: #1a237e; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; margin: 8px; display: inline-block; text-decoration: none; }
@media print { .no-print { display: none !important; } }
.footer-note { font-size: 7.5px; margin-top: 12px; color: #555; }
</style>
</head>
<body>
<div style="margin:8px;" class="no-print">
    <button class="no-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
    <a class="no-print" href="?class_id=<?php echo $classId; ?>&quarter=<?php echo $quarter; ?>&ay=<?php echo urlencode($academicYear); ?>&export=xlsx" style="background:#2e7d32;color:#fff;padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:12px;text-decoration:none;">&#8595; Download XLSX</a>
    <a class="no-print" href="javascript:history.back()">&#8592; Back</a>
    &nbsp;Period:
    <select onchange="location.href=location.href.replace(/quarter=[^&]+/, 'quarter='+this.value)">
        <?php if ($gs === '4_quarter'): ?>
        <option value="Q1" <?php echo $quarter==='Q1'?'selected':''; ?>>Quarter 1</option>
        <option value="Q2" <?php echo $quarter==='Q2'?'selected':''; ?>>Quarter 2</option>
        <option value="Q3" <?php echo $quarter==='Q3'?'selected':''; ?>>Quarter 3</option>
        <option value="Q4" <?php echo $quarter==='Q4'?'selected':''; ?>>Quarter 4</option>
        <?php else: ?>
        <option value="Term1" <?php echo $quarter==='Term1'?'selected':''; ?>>Term 1</option>
        <option value="Term2" <?php echo $quarter==='Term2'?'selected':''; ?>>Term 2</option>
        <option value="Term3" <?php echo $quarter==='Term3'?'selected':''; ?>>Term 3</option>
        <?php endif; ?>
    </select>
</div>

<div class="page">
    <div class="school-header">
        <div class="school-name">Republic of the Philippines &nbsp;•&nbsp; Department of Education</div>
        <div class="school-name">Balingasag Senior High School</div>
        <div class="school-sub">Balingasag, Misamis Oriental</div>
    </div>

    <div class="form-title">School Form 5 (SF5) &nbsp;•&nbsp; Report on Promotion and Level of Proficiency</div>

    <div class="meta-grid">
        <div class="meta-item"><span class="meta-label">Subject/Class:</span><span class="meta-value"><?php echo htmlspecialchars($class['class_name']); ?></span></div>
        <div class="meta-item"><span class="meta-label">Grade &amp; Section:</span><span class="meta-value">Grade <?php echo $class['grade_level']; ?> - <?php echo htmlspecialchars($class['section']); ?></span></div>
        <div class="meta-item"><span class="meta-label">Period:</span><span class="meta-value"><?php echo htmlspecialchars(SshsGradeCalculator::termLabel($quarter)); ?></span></div>
        <div class="meta-item"><span class="meta-label">Academic Year:</span><span class="meta-value"><?php echo htmlspecialchars($academicYear); ?></span></div>
        <div class="meta-item"><span class="meta-label">Teacher:</span><span class="meta-value"><?php echo htmlspecialchars($teacherName); ?></span></div>
        <div class="meta-item"><span class="meta-label">Date Prepared:</span><span class="meta-value"><?php echo date('F j, Y'); ?></span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:24px;">No.</th>
                <th class="name-col">Name of Learner<br><small>(Last Name, First Name, MI)</small></th>
                <th style="width:16px;font-size:7px;">Sex</th>
                <th style="width:60px;">LRN</th>
                <th class="grade-col">Final<br>Grade</th>
                <th class="level-col">Level of Proficiency</th>
                <th class="status-col">Promotion Status</th>
                <th style="width:60px;font-size:8px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $promoted = 0; $retained = 0; $noGrade = 0;
        foreach ($students as $idx => $s):
            $fg = $s['final_grade'] !== null ? (float)$s['final_grade'] : null;
            $level = proficiencyLevel($fg);
            $status = promotionStatus($fg);
            $isPromoted = $fg !== null && $fg >= 75;
            $hasGrade = $fg !== null;
            if (!$hasGrade) $noGrade++;
            elseif ($isPromoted) $promoted++;
            else $retained++;
        ?>
        <tr style="<?php echo $idx % 2 === 0 ? '' : 'background:#fafafa;'; ?>">
            <td><?php echo $idx + 1; ?></td>
            <td class="name-col"><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ($s['middle_name'] ? ' ' . substr($s['middle_name'],0,1) . '.' : '')); ?></td>
            <td><?php echo $s['sex'] === 'male' ? 'M' : ($s['sex'] === 'female' ? 'F' : ''); ?></td>
            <td style="font-size:8px;"><?php echo htmlspecialchars($s['lrn'] ?? ''); ?></td>
            <td class="grade-col"><strong><?php echo $fg !== null ? number_format($fg, 0) : '—'; ?></strong></td>
            <td class="level-col"><?php echo $level; ?></td>
            <td class="status-col <?php echo $isPromoted ? 'promoted' : ($hasGrade ? 'retained' : ''); ?>">
                <strong><?php echo $status; ?></strong>
            </td>
            <td></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="summary-item"><strong><?php echo count($students); ?></strong>Total Enrolled</div>
        <div class="summary-item promoted"><strong><?php echo $promoted; ?></strong>Promoted</div>
        <div class="summary-item retained"><strong><?php echo $retained; ?></strong>For Remediation</div>
        <div class="summary-item"><strong><?php echo $noGrade; ?></strong>No Grade Recorded</div>
        <div class="summary-item"><strong><?php echo count($students) > 0 ? number_format($promoted / count($students) * 100, 1) : '0'; ?>%</strong>Promotion Rate</div>
    </div>

    <div class="signature-row">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Prepared by: Subject Teacher</div>
            <div class="sig-label" style="margin-top:4px;"><?php echo htmlspecialchars($teacherName); ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Noted by: School Principal</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Date</div>
        </div>
    </div>
    <div class="footer-note">
        Per DepEd Order No. 8, s. 2015 &nbsp;|&nbsp; Proficiency Levels: 90-100 Outstanding | 85-89 Very Satisfactory | 80-84 Satisfactory | 75-79 Fairly Satisfactory | Below 75 Did Not Meet Expectations<br>
        BSHS-AMS Generated on <?php echo date('F j, Y \a\t g:i A'); ?>
    </div>
</div>
</body>
</html>
