<?php
ob_start();
$__teacherReportsAction = (string)($_GET['action'] ?? '');
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
unset($__teacherReportsAction);

function requirePostAndCsrfOrExit() {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Invalid CSRF token.'
        ], 403);
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
        ensureReportNotesTables($db);
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
    teacherReportsJsonExit([
        'ok' => false,
        'message' => 'Invalid action.'
    ], 400);
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

$aggregateRows = aggregateAttendanceReportRows($db, $type, $where, $params, $topN);
$aggregateTable = aggregateAttendanceReportTable($type, $aggregateRows);
$headers = $aggregateTable['headers'];
$rows = $aggregateTable['rows'];

$filename = 'teacher_' . $type . '_' . str_replace('-', '_', $academicYear) . '_' . date('Ymd_His') . '.csv';

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit();

function handleSaveNote($db, $teacherId) {
    requirePostAndCsrfOrExit();

    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'top_attendance', 'class_summary', 'at_risk'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($title === '' || $content === '') {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Title and content are required.'
        ], 422);
    }

    $stmt = $db->prepare("INSERT INTO teacher_report_notes (teacher_id, report_type, title, content) VALUES (?, ?, ?, ?)");
    $ok = $stmt->execute([$teacherId, $reportType, $title, $content]);

    if (!$ok) {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Failed to save report note.'
        ], 500);
    }

    teacherReportsJsonExit([
        'ok' => true,
        'message' => 'Report note saved.'
    ]);
}

function handleListNotes($db, $teacherId) {
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

    teacherReportsJsonExit([
        'ok' => true,
        'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function handleUpdateNote($db, $teacherId) {
    requirePostAndCsrfOrExit();

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'top_attendance', 'class_summary', 'at_risk'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($noteId <= 0 || $title === '' || $content === '') {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Note ID, title and content are required.'
        ], 422);
    }

    $stmt = $db->prepare("UPDATE teacher_report_notes
                          SET report_type = ?, title = ?, content = ?
                          WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$reportType, $title, $content, $noteId, $teacherId]);

    teacherReportsJsonExit([
        'ok' => true,
        'message' => 'Report note updated.'
    ]);
}

function handleDeleteNote($db, $teacherId) {
    requirePostAndCsrfOrExit();

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    if ($noteId <= 0) {
        teacherReportsJsonExit([
            'ok' => false,
            'message' => 'Invalid note ID.'
        ], 422);
    }

    $stmt = $db->prepare("DELETE FROM teacher_report_notes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$noteId, $teacherId]);

    teacherReportsJsonExit([
        'ok' => true,
        'message' => 'Report note deleted.'
    ]);
}
?>
