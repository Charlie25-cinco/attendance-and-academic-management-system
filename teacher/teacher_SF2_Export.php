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

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'];

$classId = (int)($_GET['class_id'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$export = trim((string)($_GET['export'] ?? 'xlsx'));

if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}
if ($year < 2020 || $year > 2099) {
    $year = (int)date('Y');
}
if (!in_array($export, ['csv', 'xlsx'], true)) {
    $export = 'xlsx';
}

$academicYearStart = $month >= 6 ? $year : $year - 1;
$sf2AcademicYear = $academicYearStart . '-' . ($academicYearStart + 1);

$classStmt = $db->prepare(
    "SELECT c.*, u.first_name, u.last_name
     FROM classes c
     LEFT JOIN users u ON c.teacher_id = u.id
     WHERE c.id = ?" . ($role === 'teacher' ? " AND (c.teacher_id = ? OR EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.class_id = c.id AND cs.teacher_id = ?))" : "")
);
$params = $role === 'teacher' ? [$classId, $teacherId, $teacherId] : [$classId];
$classStmt->execute($params);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
    exit();
}

$stuStmt = $db->prepare(
    "SELECT u.id, u.first_name, u.middle_name, u.last_name, u.reference_code, u.lrn, u.sex
     FROM users u
     INNER JOIN enrollments e ON e.student_id = u.id
     WHERE e.class_id = ?
     AND (
         e.academic_year = ?
         OR e.academic_year = (SELECT e2.academic_year FROM enrollments e2 WHERE e2.class_id = ? AND e2.academic_year IS NOT NULL AND TRIM(e2.academic_year) <> '' LIMIT 1)
         OR e.academic_year IS NULL
         OR TRIM(e.academic_year) = ''
     )
     AND COALESCE(e.status, 'enrolled') = 'enrolled'
     AND u.role = 'student'
     AND u.status = 'active'
     ORDER BY u.last_name, u.first_name"
);
$stuStmt->execute([$classId, $sf2AcademicYear, $classId]);
$students = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

$startDate = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

$attStmt = $db->prepare("SELECT student_id, date, status, remarks FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? ORDER BY date");
$attStmt->execute([$classId, $startDate, $endDate]);
$attendance = [];
foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
    $attendance[$record['student_id']][$record['date']] = [
        'status' => $record['status'],
        'remarks' => $record['remarks'] ?? ''
    ];
}

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$teacherName = trim(($class['first_name'] ?? '') . ' ' . ($class['last_name'] ?? ''));
$schoolSettings = getSchoolSettings($db);
foreach (['school_name', 'school_id', 'district', 'division'] as $settingKey) {
    if (trim((string)($schoolSettings[$settingKey] ?? '')) !== '') {
        $class[$settingKey] = $schoolSettings[$settingKey];
    }
}

if ($export === 'csv') {
    $filename = 'SF2_' . ($class['class_name'] ?? 'Class') . '_' . $monthName . '_' . $year . '.csv';
    $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'SF2.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Present', 'Absent', 'Tardy']);
    foreach ($students as $student) {
        $present = 0;
        $absent = 0;
        $tardy = 0;
        foreach ($attendance[(int)$student['id']] ?? [] as $entry) {
            $status = is_array($entry) ? strtolower((string)($entry['status'] ?? '')) : strtolower((string)$entry);
            if ($status === 'present') {
                $present++;
            } elseif ($status === 'absent' || $status === 'cutting') {
                $absent++;
            } elseif ($status === 'late' || $status === 'tardy') {
                $tardy++;
            }
        }
        fputcsv($out, [
            $student['lrn'] ?? '',
            $student['last_name'] ?? '',
            $student['first_name'] ?? '',
            $student['middle_name'] ?? '',
            $student['sex'] ?? '',
            $present,
            $absent,
            $tardy,
        ]);
    }
    fclose($out);
    exit();
}

$exporter = new Sf2Exporter();
$exporter->setClass($class);
$exporter->setStudents($students);
$exporter->setAttendance($attendance);
$exporter->setMonth($month);
$exporter->setYear($year);
$exporter->setTeacherName($teacherName);

$filename = 'SF2_' . ($class['class_name'] ?? 'Class') . '_' . $monthName . '_' . $year . '.xlsx';
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'SF2.xlsx';
try {
    $exporter->outputToBrowser($filename);
} catch (Throwable $e) {
    error_log('Teacher SF2 XLSX export failed: ' . $e->getMessage());
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'SF2 XLSX export failed. Please try CSV or contact the administrator.'
    ]);
    exit();
}
