<?php
ob_start();
$__adminReportsAction = (string)($_GET['action'] ?? '');
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin Reports Action Handler

function adminReportsJsonExit(array $payload, int $statusCode = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    adminReportsJsonExit([
        'ok' => false,
        'message' => 'Your session expired or you are not signed in as an admin. Please log in again.'
    ], 403);
}


$database = new Database();
$db = $database->getConnection();

if (!$db) {
    adminReportsJsonExit([
        'ok' => false,
        'message' => 'Database connection failed while loading report notes.'
    ], 500);
}

$action = $__adminReportsAction;
unset($__adminReportsAction);
$type = $_GET['type'] ?? '';
$dateFrom = normalizeDate($_GET['date_from'] ?? '');
$dateTo = normalizeDate($_GET['date_to'] ?? '');
$classId = normalizeInt($_GET['class_id'] ?? '');
$gradeLevel = normalizeInt($_GET['grade_level'] ?? '');
$section = normalizeText($_GET['section'] ?? '');
$status = normalizeText($_GET['status'] ?? '');
$topN = normalizeInt($_GET['top_n'] ?? 10);
$topN = $topN >= 1 && $topN <= 100 ? $topN : 10;

function requirePostAndCsrfOrExit() {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Invalid CSRF token.'
        ], 403);
    }
}

function handleReportNotesError(Throwable $e): void {
    error_log('Admin report notes action failed: ' . $e->getMessage());
    adminReportsJsonExit([
        'ok' => false,
        'message' => 'Report notes could not be loaded. Please try again or contact the administrator.'
    ], 500);
}

if (in_array($action, ['save_note', 'list_notes', 'update_note', 'delete_note'], true)) {
    try {
        ensureReportNotesTables($db);
        if ($action === 'save_note') {
            handleSaveNote($db);
        }
        if ($action === 'list_notes') {
            handleListNotes($db);
        }
        if ($action === 'delete_note') {
            handleDeleteNote($db);
        }
        if ($action === 'update_note') {
            handleUpdateNote($db);
        }
    } catch (Throwable $e) {
        handleReportNotesError($e);
    }
}

if ($action !== 'export') {
    adminReportsJsonExit([
        'ok' => false,
        'message' => 'Invalid action.'
    ], 400);
}

$allowedTypes = ['attendance', 'top_attendance', 'class_summary', 'at_risk', 'grades', 'enrollment', 'teachers', 'classes'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo 'Invalid report type';
    exit();
}

$filters = [
    'class_id' => $classId,
    'grade_level' => $gradeLevel,
    'section' => $section,
    'status' => $status
];

if (in_array($type, aggregateAttendanceReportTypes(), true)) {
    [$where, $params] = aggregateAttendanceAdminScope($db, [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'class_id' => $classId,
        'grade_level' => $gradeLevel,
        'section' => $section,
        'status' => $status
    ]);
    $aggregateRows = aggregateAttendanceReportRows($db, $type, $where, $params, $topN);
    $aggregateTable = aggregateAttendanceReportTable($type, $aggregateRows);
    $filename = 'admin_' . $type . '_' . date('Ymd_His') . '.csv';
    $headers = $aggregateTable['headers'];
    $rows = $aggregateTable['rows'];
} else {
    [$filename, $headers, $rows] = buildReportData($db, $type, $dateFrom, $dateTo, $filters);
}

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit();

function handleSaveNote($db) {
    requirePostAndCsrfOrExit();

    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'attendance', 'top_attendance', 'class_summary', 'at_risk', 'grades', 'enrollment', 'teachers', 'classes'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($title === '' || $content === '') {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Title and content are required.'
        ], 422);
    }

    $stmt = $db->prepare("INSERT INTO admin_report_notes (admin_id, report_type, title, content) VALUES (?, ?, ?, ?)");
    $ok = $stmt->execute([(int)$_SESSION['user_id'], $reportType, $title, $content]);

    if (!$ok) {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Failed to save report note.'
        ], 500);
    }

    adminReportsJsonExit([
        'ok' => true,
        'message' => 'Report note saved.'
    ]);
}

function handleListNotes($db) {
    $reportType = normalizeText($_GET['report_type'] ?? '');
    $params = [(int)$_SESSION['user_id']];
    $where = "admin_id = ?";

    if ($reportType !== '') {
        $allowedTypes = ['general', 'attendance', 'top_attendance', 'class_summary', 'at_risk', 'grades', 'enrollment', 'teachers', 'classes'];
        if (!in_array($reportType, $allowedTypes, true)) {
            $reportType = '';
        }
    }

    if ($reportType !== '') {
        $where .= " AND report_type = ?";
        $params[] = $reportType;
    }

    $sql = "SELECT id, report_type, title, content, created_at, updated_at
            FROM admin_report_notes
            WHERE $where
            ORDER BY created_at DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    adminReportsJsonExit([
        'ok' => true,
        'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function handleDeleteNote($db) {
    requirePostAndCsrfOrExit();

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    if ($noteId <= 0) {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Invalid note ID.'
        ], 422);
    }

    $stmt = $db->prepare("DELETE FROM admin_report_notes WHERE id = ? AND admin_id = ?");
    $stmt->execute([$noteId, (int)$_SESSION['user_id']]);

    adminReportsJsonExit([
        'ok' => true,
        'message' => 'Report note deleted.'
    ]);
}

function handleUpdateNote($db) {
    requirePostAndCsrfOrExit();

    $noteId = normalizeInt($_POST['note_id'] ?? 0);
    $title = normalizeText($_POST['title'] ?? '');
    $content = normalizeText($_POST['content'] ?? '');
    $reportType = normalizeText($_POST['report_type'] ?? 'general');

    $allowedTypes = ['general', 'attendance', 'top_attendance', 'class_summary', 'at_risk', 'grades', 'enrollment', 'teachers', 'classes'];
    if (!in_array($reportType, $allowedTypes, true)) {
        $reportType = 'general';
    }

    if ($noteId <= 0 || $title === '' || $content === '') {
        adminReportsJsonExit([
            'ok' => false,
            'message' => 'Note ID, title and content are required.'
        ], 422);
    }

    $stmt = $db->prepare("UPDATE admin_report_notes
                          SET report_type = ?, title = ?, content = ?
                          WHERE id = ? AND admin_id = ?");
    $stmt->execute([$reportType, $title, $content, $noteId, (int)$_SESSION['user_id']]);

    adminReportsJsonExit([
        'ok' => true,
        'message' => 'Report note updated.'
    ]);
}

function appendDateFilter($column, &$where, &$params, $dateFrom, $dateTo) {
    \BshsAms\Report\ReportFilterHelper::appendDateFilter((string)$column, $where, $params, (string)$dateFrom, (string)$dateTo);
}

function appendAdvancedFilters($type, &$where, &$params, $filters) {
    \BshsAms\Report\ReportFilterHelper::appendAdvancedFilters((string)$type, $where, $params, (array)$filters);
}

function buildReportData($db, $type, $dateFrom, $dateTo, $filters) {
    static $hasGradeQuarter = null;
    static $tc = null;
    if ($hasGradeQuarter === null) {
        $has_quarter = dbHasColumn($db, 'grades', 'quarter');
        $hasGradeQuarter = $has_quarter;
        $tc = 'term';
        if ($hasGradeQuarter) {
            $has_term = dbHasColumn($db, 'grades', 'term');
            $tc = $has_term ? 'term' : 'quarter';
        }
    }

    switch ($type) {
        case 'attendance':
            $where = [];
            $params = [];
            appendDateFilter('a.date', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('attendance', $where, $params, $filters);
            $query = "SELECT a.date, c.class_name, u.reference_code,
                      CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                      a.status, a.time_in, a.remarks
                      FROM attendance a
                      JOIN classes c ON a.class_id = c.id
                      JOIN users u ON a.student_id = u.id";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= "
                      ORDER BY a.date DESC, c.class_name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return [
                'attendance_report_' . date('Ymd_His') . '.csv',
                ['Date', 'Class', 'Reference Code', 'Student', 'Status', 'Time In', 'Remarks'],
                array_map(function ($r) {
                    return [$r['date'], $r['class_name'], $r['reference_code'], $r['student_name'], $r['status'], $r['time_in'], $r['remarks']];
                }, $rows)
            ];

        case 'grades':
            $where = [];
            $params = [];
            if ($hasGradeQuarter) {
                appendDateFilter('sg.recorded_at', $where, $params, $dateFrom, $dateTo);
                appendAdvancedFilters('grades', $where, $params, $filters);
                $query = "SELECT sg.academic_year, sg.semester,
                          CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                          u.reference_code, c.class_name,
                          sg.quiz_score, sg.exam_score, sg.activity_score,
                          sg.semester_grade
                          FROM (
                            SELECT g.student_id, g.class_subject_id, g.academic_year, g.semester,
                                   ROUND(AVG(g.quiz_score), 2) AS quiz_score,
                                   ROUND(AVG(g.exam_score), 2) AS exam_score,
                                   ROUND(AVG(g.activity_score), 2) AS activity_score,
                                   ROUND(AVG(g.final_grade), 2) AS semester_grade,
                                   MAX(g.created_at) AS recorded_at
                            FROM grades g
                            WHERE g.{$tc} IN ('Q1', 'Q2')
                            GROUP BY g.student_id, g.class_subject_id, g.academic_year, g.semester
                          ) sg
                          JOIN users u ON sg.student_id = u.id
                          JOIN class_subjects cs ON sg.class_subject_id = cs.id
                          JOIN classes c ON cs.class_id = c.id";
                if (!empty($where)) {
                    $query .= " WHERE " . implode(" AND ", $where);
                }
                $query .= " ORDER BY sg.academic_year DESC, sg.semester DESC, student_name ASC";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return [
                    'grades_report_' . date('Ymd_His') . '.csv',
                    ['Academic Year', 'Semester', 'Student', 'Reference Code', 'Class', 'Written Work Avg', 'Assessment Avg', 'Performance Task Avg', 'Semester Grade'],
                    array_map(function ($r) {
                        return [$r['academic_year'], $r['semester'], $r['student_name'], $r['reference_code'], $r['class_name'], $r['quiz_score'], $r['exam_score'], $r['activity_score'], $r['semester_grade']];
                    }, $rows)
                ];
            } else {
                appendDateFilter('g.created_at', $where, $params, $dateFrom, $dateTo);
                appendAdvancedFilters('grades', $where, $params, $filters);
                $query = "SELECT g.academic_year, g.semester,
                          CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                          u.reference_code, c.class_name, g.quiz_score, g.exam_score,
                          g.activity_score, g.final_grade
                          FROM grades g
                          JOIN users u ON g.student_id = u.id
                          JOIN class_subjects cs ON g.class_subject_id = cs.id
                          JOIN classes c ON cs.class_id = c.id";
                if (!empty($where)) {
                    $query .= " WHERE " . implode(" AND ", $where);
                }
                $query .= "
                          ORDER BY g.academic_year DESC, g.semester DESC";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return [
                    'grades_report_' . date('Ymd_His') . '.csv',
                    ['Academic Year', 'Semester', 'Student', 'Reference Code', 'Class', 'Written Work', 'Assessment', 'Performance Task', 'Final Grade'],
                    array_map(function ($r) {
                        return [$r['academic_year'], $r['semester'], $r['student_name'], $r['reference_code'], $r['class_name'], $r['quiz_score'], $r['exam_score'], $r['activity_score'], $r['final_grade']];
                    }, $rows)
                ];
            }

        case 'enrollment':
            $where = [];
            $params = [];
            appendDateFilter('e.enrolled_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('enrollment', $where, $params, $filters);
            $query = "SELECT e.academic_year, e.semester, c.class_name,
                      c.grade_level, c.section, u.reference_code,
                      CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                      e.status, e.enrolled_at
                      FROM enrollments e
                      JOIN classes c ON e.class_id = c.id
                      JOIN users u ON e.student_id = u.id";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= "
                      ORDER BY e.enrolled_at DESC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return [
                'enrollment_report_' . date('Ymd_His') . '.csv',
                ['Academic Year', 'Semester', 'Class', 'Grade', 'Section', 'Reference Code', 'Student', 'Status', 'Enrolled At'],
                array_map(function ($r) {
                    return [$r['academic_year'], $r['semester'], $r['class_name'], $r['grade_level'], $r['section'], $r['reference_code'], $r['student_name'], $r['status'], $r['enrolled_at']];
                }, $rows)
            ];

        case 'teachers':
            $where = ["u.role = 'teacher'"];
            $params = [];
            appendDateFilter('u.created_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('teachers', $where, $params, $filters);
            $query = "SELECT u.reference_code, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
                      u.email, u.status, COUNT(DISTINCT cs.class_id) AS assigned_classes
                      FROM users u
                      LEFT JOIN class_subjects cs ON cs.teacher_id = u.id
                      WHERE " . implode(" AND ", $where) . "
                      GROUP BY u.id
                      ORDER BY teacher_name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return [
                'teachers_report_' . date('Ymd_His') . '.csv',
                ['Reference Code', 'Teacher', 'Email', 'Status', 'Assigned Classes'],
                array_map(function ($r) {
                    return [$r['reference_code'], $r['teacher_name'], $r['email'], $r['status'], $r['assigned_classes']];
                }, $rows)
            ];

        case 'classes':
            $where = [];
            $params = [];
            appendDateFilter('c.created_at', $where, $params, $dateFrom, $dateTo);
            appendAdvancedFilters('classes', $where, $params, $filters);
            $query = "SELECT c.class_name, c.grade_level, c.section, c.schedule, c.room, c.status,
                      COUNT(DISTINCT e.student_id) AS students,
                      COUNT(DISTINCT cs.teacher_id) AS teachers
                      FROM classes c
                      LEFT JOIN enrollments e ON e.class_id = c.id AND e.status = 'enrolled'
                      LEFT JOIN class_subjects cs ON cs.class_id = c.id";
            if (!empty($where)) {
                $query .= " WHERE " . implode(" AND ", $where);
            }
            $query .= "
                      GROUP BY c.id
                      ORDER BY c.grade_level ASC, c.section ASC, c.class_name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return [
                'classes_report_' . date('Ymd_His') . '.csv',
                ['Class', 'Grade', 'Section', 'Schedule', 'Room', 'Status', 'Students', 'Teachers'],
                array_map(function ($r) {
                    return [$r['class_name'], $r['grade_level'], $r['section'], $r['schedule'], $r['room'], $r['status'], $r['students'], $r['teachers']];
                }, $rows)
            ];
    }

    return ['report.csv', ['No data'], []];
}
?>






