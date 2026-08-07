<?php
$__teacherReportsAction = (string)($_GET['action'] ?? '');
$__teacherReportsNotesAction = in_array($__teacherReportsAction, ['save_note', 'list_notes', 'update_note', 'delete_note'], true);
if ($__teacherReportsNotesAction) {
    ob_start();
}
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

function teacherReportsJsonExit(array $payload, int $statusCode = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    teacherReportsJsonExit([
        'ok' => false,
        'message' => 'Your session expired or you are not signed in as a teacher. Please log in again.'
    ], 403);
}

require_once __DIR__ . '/teacher_Reports_Helper.php';
$db = (new Database())->getConnection();
if (!$db) {
    teacherReportsJsonExit([
        'ok' => false,
        'message' => 'Database connection failed while loading report notes.'
    ], 500);
}

$teacherId = (int)($_SESSION['user_id'] ?? 0);
$action = $__teacherReportsAction;
ensureReportNotesTables($db);
unset($__teacherReportsAction, $__teacherReportsNotesAction);

function requirePostAndCsrfOrExit() {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'message' => 'Method not allowed.'
        ]);
        exit();
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Invalid CSRF token.'
        ]);
        exit();
    }
}

function handleReportNotesError(Throwable $e): void {
    error_log('Teacher report notes action failed: ' . $e->getMessage());
    teacherReportsJsonExit([
        'ok' => false,
        'message' => 'Report notes could not be loaded. Please try again or contact the administrator.'
    ], 500);
}

if (in_array($action, ['save_note', 'list_notes', 'update_note', 'delete_note'], true)) {
    try {
        if ($action === 'save_note') {
            handleSaveNote($db, $teacherId);
        }
        if ($action === 'list_notes') {
            handleListNotes($db, $teacherId);
        }
        if ($action === 'update_note') {
            handleUpdateNote($db, $teacherId);
        }
        if ($action === 'delete_note') {
            handleDeleteNote($db, $teacherId);
        }
    } catch (Throwable $e) {
        handleReportNotesError($e);
    }
}

if ($action !== 'export') {
    http_response_code(400);
    echo 'Invalid action';
    exit();
}

$type = trim((string)($_GET['type'] ?? 'top_attendance'));
$allowedTypes = ['top_attendance', 'class_summary', 'at_risk'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'top_attendance';
}

$academicYear = trim((string)($_GET['academic_year'] ?? trhCurrentAcademicYear()));
$mode = trim((string)($_GET['mode'] ?? 'subject'));
$mode = in_array($mode, ['subject', 'advisory'], true) ? $mode : 'subject';
$scope = trim((string)($_GET['scope'] ?? 'semester'));
$semester = trim((string)($_GET['semester'] ?? 'S1'));
$classId = (int)($_GET['class_id'] ?? 0);
$sex = strtolower(trim((string)($_GET['sex'] ?? '')));
if (!in_array($sex, ['male', 'female'], true)) {
    $sex = '';
}
$topN = (int)($_GET['top_n'] ?? 10);

if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
    $academicYear = trhCurrentAcademicYear();
}
if (!in_array($scope, ['semester', 'yearly'], true)) {
    $scope = 'semester';
}
if (!in_array($semester, ['S1', 'S2'], true)) {
    $semester = 'S1';
}
$semesterNo = ($semester === 'S2') ? 2 : 1;
if ($topN < 1 || $topN > 100) {
    $topN = 10;
}

[$dateFrom, $dateTo] = trhPeriodBounds($academicYear, $scope, $semester);
$hasAttendanceTerms = false;

$hasAttendanceTerms = trhHasAttendanceTerms($db);
$advisoryInfo = trhFetchAdvisoryInfo($db, $teacherId);
[$where, $params] = trhBuildAttendanceScope(
    $teacherId,
    $mode,
    $advisoryInfo,
    $hasAttendanceTerms,
    $academicYear,
    $scope,
    $semesterNo,
    $dateFrom,
    $dateTo,
    $classId,
    $sex
);

$headers = [];
$rows = [];

if ($type === 'top_attendance') {
    $headers = ['Rank', 'Student', 'Reference', 'Present', 'Late', 'Absent', 'Total', 'Rate'];
    $sql = "SELECT CONCAT(u.first_name, ' ', u.last_name) AS student_name, u.reference_code,
            SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) AS late_count,
            (SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) + FLOOR(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) / 3)) AS absent_count,
            COUNT(*) AS total_records,
            MOD(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END), 3) AS effective_late_count,
            ROUND(((SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) + MOD(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END), 3)) / COUNT(*)) * 100, 2) AS attendance_rate
            FROM attendance a
            JOIN users u ON u.id = a.student_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.id, u.first_name, u.last_name, u.reference_code
            HAVING total_records > 0
            ORDER BY attendance_rate DESC, total_records DESC, u.last_name, u.first_name
            LIMIT " . (int)$topN;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($data as $i => $r) {
        $rows[] = [$i + 1, $r['student_name'], $r['reference_code'], $r['present_count'], $r['effective_late_count'], $r['absent_count'], $r['total_records'], $r['attendance_rate'] . '%'];
    }
} elseif ($type === 'class_summary') {
    $headers = ['Class', 'Present', 'Late', 'Absent', 'Total', 'Rate'];
    $sql = "SELECT c.class_name,
            SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) AS late_count,
            (SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) + FLOOR(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) / 3)) AS absent_count,
            COUNT(*) AS total_records,
            MOD(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END), 3) AS effective_late_count,
            ROUND(((SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) + MOD(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END), 3)) / COUNT(*)) * 100, 2) AS attendance_rate
            FROM attendance a
            JOIN classes c ON c.id = a.class_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.id, c.class_name
            ORDER BY c.class_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [$r['class_name'], $r['present_count'], $r['effective_late_count'], $r['absent_count'], $r['total_records'], $r['attendance_rate'] . '%'];
    }
} else {
    $headers = ['Student', 'Reference', 'Absent', 'Total', 'Rate'];
    $sql = "SELECT CONCAT(u.first_name, ' ', u.last_name) AS student_name, u.reference_code,
            (SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) + FLOOR(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) / 3)) AS absent_count,
            COUNT(*) AS total_records,
            ROUND(((SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) + MOD(SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END), 3)) / COUNT(*)) * 100, 2) AS attendance_rate
            FROM attendance a
            JOIN users u ON u.id = a.student_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.id, u.first_name, u.last_name, u.reference_code
            HAVING total_records > 0
            ORDER BY absent_count DESC, attendance_rate ASC, u.last_name, u.first_name
            LIMIT " . (int)$topN;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [$r['student_name'], $r['reference_code'], $r['absent_count'], $r['total_records'], $r['attendance_rate'] . '%'];
    }
}

$filename = 'teacher_' . $type . '_' . str_replace('-', '_', $academicYear) . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit();

function normalizeText($value) {
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string)$value);
}

function normalizeInt($value) {
    if (!is_scalar($value)) {
        return 0;
    }
    $value = trim((string)$value);
    return ctype_digit($value) ? (int)$value : 0;
}

function handleSaveNote($db, $teacherId) {
    requirePostAndCsrfOrExit();
    header('Content-Type: application/json');

    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'top_attendance', 'class_summary', 'at_risk'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($title === '' || $content === '') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'Title and content are required.'
        ]);
        exit();
    }

    $stmt = $db->prepare("INSERT INTO teacher_report_notes (teacher_id, report_type, title, content) VALUES (?, ?, ?, ?)");
    $ok = $stmt->execute([$teacherId, $reportType, $title, $content]);

    if (!$ok) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Failed to save report note.'
        ]);
        exit();
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Report note saved.'
    ]);
    exit();
}

function handleListNotes($db, $teacherId) {
    header('Content-Type: application/json');

    $reportType = normalizeText($_GET['report_type'] ?? '');
    $params = [$teacherId];
    $where = "teacher_id = ?";

    if ($reportType !== '') {
        $allowedTypes = ['general', 'top_attendance', 'class_summary', 'at_risk'];
        if (!in_array($reportType, $allowedTypes, true)) {
            $reportType = '';
        }
    }

    if ($reportType !== '') {
        $where .= " AND report_type = ?";
        $params[] = $reportType;
    }

    $sql = "SELECT id, report_type, title, content, created_at, updated_at
            FROM teacher_report_notes
            WHERE $where
            ORDER BY created_at DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok' => true,
        'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit();
}

function handleUpdateNote($db, $teacherId) {
    requirePostAndCsrfOrExit();
    header('Content-Type: application/json');

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'top_attendance', 'class_summary', 'at_risk'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($noteId <= 0 || $title === '' || $content === '') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'Note ID, title and content are required.'
        ]);
        exit();
    }

    $stmt = $db->prepare("UPDATE teacher_report_notes
                          SET report_type = ?, title = ?, content = ?
                          WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$reportType, $title, $content, $noteId, $teacherId]);

    echo json_encode([
        'ok' => true,
        'message' => 'Report note updated.'
    ]);
    exit();
}

function handleDeleteNote($db, $teacherId) {
    requirePostAndCsrfOrExit();
    header('Content-Type: application/json');

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    if ($noteId <= 0) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'Invalid note ID.'
        ]);
        exit();
    }

    $stmt = $db->prepare("DELETE FROM teacher_report_notes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$noteId, $teacherId]);

    echo json_encode([
        'ok' => true,
        'message' => 'Report note deleted.'
    ]);
    exit();
}
?>
