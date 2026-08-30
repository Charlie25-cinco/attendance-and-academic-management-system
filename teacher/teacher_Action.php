<?php
use BshsAms\Storage\MaterialStorage;

$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Teacher Action Handler
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once __DIR__ . '/teacher_Enrollment_Helper.php';
require_once __DIR__ . '/../api/pushnotifications.php';

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$teacherId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

$writeActions = [
    'submit_attendance',
    'classify_qr_scan',
    'submit_grades',
    'upload_material',
    'update_material',
    'delete_material',
    'create_class_announcement',
    'delete_class_announcement',
    'create_grade_item',
    'save_grade_item_scores',
    'save_offline_activity',
    'finish_grade_item',
    'delete_grade_item',
    'restore_grade_item',
    'recall_grades',
    'submit_report_card',
    'recall_report_card'
];
if (in_array($action, $writeActions, true)) {
    requireCsrfToken();
}

if (!in_array($action, ['download_material', 'export_grades'], true)) {
    header('Content-Type: application/json');
}

$readOnlyActions = [
    'fetch_students',
    'fetch_class_students',
    'fetch_grades',
    'get_material',
    'fetch_class_announcements',
    'fetch_grade_items',
    'fetch_grade_item_students',
    'offline_bootstrap',
    'export_grades'
];
if (in_array($action, $readOnlyActions, true) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

switch ($action) {
    case 'fetch_students':
        fetchStudents($db, $teacherId);
        break;
    case 'fetch_class_students':
        fetchClassStudents($db, $teacherId);
        break;
    case 'submit_attendance':
        submitAttendance($db, $teacherId);
        break;
    case 'classify_qr_scan':
        classifyQrScan($db, $teacherId);
        break;
    case 'fetch_grades':
        fetchGrades($db, $teacherId);
        break;
    case 'submit_grades':
        submitGrades($db, $teacherId);
        break;
    case 'recall_grades':
        recallGrades($db, $teacherId);
        break;
    case 'upload_material':
        uploadMaterial($db, $teacherId);
        break;
    case 'get_material':
        getMaterial($db, $teacherId);
        break;
    case 'update_material':
        updateMaterial($db, $teacherId);
        break;
    case 'delete_material':
        deleteMaterial($db, $teacherId);
        break;
    case 'download_material':
        downloadMaterial($db, $teacherId);
        break;
    case 'export_grades':
        exportGrades($db, $teacherId);
        break;
    case 'create_class_announcement':
        createClassAnnouncement($db, $teacherId);
        break;
    case 'fetch_class_announcements':
        fetchClassAnnouncements($db, $teacherId);
        break;
    case 'delete_class_announcement':
        deleteClassAnnouncement($db, $teacherId);
        break;
    case 'create_grade_item':
        createGradeItem($db, $teacherId);
        break;
    case 'fetch_grade_items':
        fetchGradeItems($db, $teacherId);
        break;
    case 'fetch_grade_item_students':
        fetchGradeItemStudents($db, $teacherId);
        break;
    case 'save_grade_item_scores':
        saveGradeItemScores($db, $teacherId);
        break;
    case 'finish_grade_item':
        finishGradeItem($db, $teacherId);
        break;
    case 'restore_grade_item':
        restoreGradeItem($db, $teacherId);
        break;
    case 'delete_grade_item':
        deleteGradeItem($db, $teacherId);
        break;
    case 'submit_report_card':
        submitReportCard($db, $teacherId);
        break;
    case 'recall_report_card':
        recallReportCard($db, $teacherId);
        break;
    case 'offline_bootstrap':
        teacherOfflineBootstrap($db, $teacherId);
        break;
    case 'save_offline_activity':
        teacherSaveOfflineActivity($db, $teacherId);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function teacherNormalizeScheduleDays($days) {
    $valid = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $normalized = [];
    foreach ($days as $day) {
        $abbr = ucfirst(strtolower(substr(trim((string)$day), 0, 3)));
        if (in_array($abbr, $valid, true)) {
            $normalized[] = $abbr;
        }
    }
    return array_values(array_unique($normalized));
}

function teacherParseClassSchedule($scheduleText) {
    $scheduleText = trim((string)$scheduleText);
    if ($scheduleText === '') {
        return [];
    }
    $segments = preg_split('/\s*;\s*/', $scheduleText);
    $days = [];
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if (!preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            continue;
        }
        $daysText = preg_replace('/\s*\/\s*/', ',', trim($m[1]));
        $segmentDays = teacherNormalizeScheduleDays(explode(',', $daysText));
        foreach ($segmentDays as $day) {
            $days[] = $day;
        }
    }
    return array_values(array_unique($days));
}

function teacherClassHasScheduleOnDate($db, $classId, $date) {
    $stmt = $db->prepare("SELECT schedule
                          FROM classes
                          WHERE id = ?
                          AND status = 'active'
                          LIMIT 1");
    $stmt->execute([(int)$classId]);
    $schedule = $stmt->fetchColumn();
    if ($schedule === false) {
        return false;
    }

    $days = teacherParseClassSchedule($schedule);

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        return false;
    }

    return in_array($dateObj->format('D'), $days, true);
}

function teacherOwnsClass($db, $teacherId, $classId) {
    $stmt = $db->prepare("SELECT 1
                          FROM classes c
                          WHERE c.id = ?
                          AND c.status = 'active'
                          AND (
                            c.teacher_id = ?
                            OR EXISTS (
                                SELECT 1
                                FROM class_subjects cs
                                WHERE cs.class_id = c.id
                                AND cs.teacher_id = ?
                            )
                          )
                          LIMIT 1");
    $stmt->execute([$classId, $teacherId, $teacherId]);
    return (bool)$stmt->fetchColumn();
}

function getTeacherAdvisoryClassInfo($db, $teacherId) {
    $stmt = $db->prepare("SELECT id, grade_level, section
                          FROM classes
                          WHERE teacher_id = ? AND status = 'active'
                          ORDER BY id ASC
                          LIMIT 1");
    $stmt->execute([$teacherId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    // Fallback to teacher profile assignment (grade/section),
    // but no cross-section broadening.
    $profileStmt = $db->prepare("SELECT grade_level, section
                                 FROM users
                                 WHERE id = ? AND role = 'teacher'
                                 LIMIT 1");
    $profileStmt->execute([$teacherId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if ($profile && $profile['grade_level'] !== null && trim((string)$profile['section']) !== '') {
        return [
            'id' => 0,
            'grade_level' => (int)$profile['grade_level'],
            'section' => trim((string)$profile['section'])
        ];
    }
    return null;
}

function teacherCanViewClass($db, $teacherId, $classId, $mode = 'subject') {
    if ($mode === 'subject') {
        return teacherOwnsClass($db, $teacherId, $classId);
    }

    $advisory = getTeacherAdvisoryClassInfo($db, $teacherId);
    if (!$advisory) {
        return false;
    }

    $stmt = $db->prepare("SELECT 1
                          FROM classes
                          WHERE id = ?
                          AND status = 'active'
                          AND grade_level = ?
                          AND " . sectionMatchSql('section') . "
                          LIMIT 1");
    $stmt->execute([
        $classId,
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section']
    ]);
    return (bool)$stmt->fetchColumn();
}

function teacherAttendanceSupportsColumn($db, $column) {
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    $stmt = $db->query("SHOW COLUMNS FROM attendance LIKE " . $db->quote($column));
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache[$column];
}

function teacherAttendanceUpsertRecord($db, $teacherId, $classId, $date, $studentId, $status, $remarks = '') {
    $status = strtolower(trim((string)$status));
    if ($status === 'cutting') {
        $status = 'absent';
        if ($remarks === '') {
            $remarks = 'Cutting Class';
        } elseif (!str_contains($remarks, 'Cutting Class')) {
            $remarks = 'Cutting Class; ' . $remarks;
        }
    } elseif (!in_array($status, ['present', 'absent', 'late'], true)) {
        $status = 'present';
    }

    $hasAttendanceTerms = attendanceHasTermColumns($db);
    $hasTimeIn = teacherAttendanceSupportsColumn($db, 'time_in');
    $hasRemarks = teacherAttendanceSupportsColumn($db, 'remarks');
    $hasRecordedBy = teacherAttendanceSupportsColumn($db, 'recorded_by');
    $hasCreatedAt = teacherAttendanceSupportsColumn($db, 'created_at');

    $insertCols = ['student_id', 'class_id', 'date', 'status'];
    $insertVals = ['?', '?', '?', '?'];
    $updateCols = ['status'];
    $params = [$studentId, $classId, $date, $status];

    if ($hasTimeIn) {
        $timeIn = ($status === 'absent') ? null : date('H:i:s');
        $insertCols[] = 'time_in';
        $insertVals[] = '?';
        $updateCols[] = 'time_in';
        $params[] = $timeIn;
    }
    if ($hasRemarks) {
        $insertCols[] = 'remarks';
        $insertVals[] = '?';
        $updateCols[] = 'remarks';
        $params[] = trim((string)$remarks);
    }
    if ($hasAttendanceTerms) {
        [$termAcademicYear, $termSemester] = attendanceDefaultTermFromDate($date);
        $insertCols[] = 'academic_year';
        $insertVals[] = '?';
        $insertCols[] = 'semester';
        $insertVals[] = '?';
        $updateCols[] = 'academic_year';
        $updateCols[] = 'semester';
        $params[] = $termAcademicYear;
        $params[] = $termSemester;
    }
    if ($hasRecordedBy) {
        $insertCols[] = 'recorded_by';
        $insertVals[] = '?';
        $updateCols[] = 'recorded_by';
        $params[] = $teacherId;
    }
    if ($hasCreatedAt) {
        $insertCols[] = 'created_at';
        $insertVals[] = 'NOW()';
    }

    $updateSql = implode(",\n                                    ", array_map(function ($c) {
        return $c . " = ?";
    }, $updateCols));

    $upsertSql = "INSERT INTO attendance (" . implode(', ', $insertCols) . ")
                                    VALUES (" . implode(', ', $insertVals) . ")
                                    ON DUPLICATE KEY UPDATE
                                    " . $updateSql;

    $upsert = $db->prepare($upsertSql);
    $updateParams = [$status];
    if ($hasTimeIn) {
        $updateParams[] = ($status === 'absent') ? null : date('H:i:s');
    }
    if ($hasRemarks) {
        $updateParams[] = trim((string)$remarks);
    }
    if ($hasAttendanceTerms) {
        $updateParams[] = $termAcademicYear;
        $updateParams[] = $termSemester;
    }
    if ($hasRecordedBy) {
        $updateParams[] = $teacherId;
    }

    $upsert->execute(array_merge($params, $updateParams));
}

function getTeacherClassSubjectId($db, $teacherId, $classId) {
    $stmt = $db->prepare("SELECT id FROM class_subjects WHERE teacher_id = ? AND class_id = ? LIMIT 1");
    $stmt->execute([$teacherId, $classId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function teacherNotificationsTableExists($db) {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = (bool)$db->query("SHOW TABLES LIKE 'user_notifications'")->fetch(PDO::FETCH_NUM);
    return $exists;
}

function notifyMaterialUploadRecipients($db, $teacherId, $classId, $materialId, $materialTitle) {
    $classStmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([(int)$classId]);
    $classRow = $classStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $className = trim((string)($classRow['class_name'] ?? 'Class'));
    $gradeLevel = trim((string)($classRow['grade_level'] ?? ''));
    $section = trim((string)($classRow['section'] ?? ''));
    $classSubtitle = $className;
    if ($gradeLevel !== '' && $section !== '') {
        $classSubtitle .= " (G{$gradeLevel} - {$section})";
    }

    $teacherStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $teacherStmt->execute([(int)$teacherId]);
    $teacherRow = $teacherStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $teacherName = trim((string)($teacherRow['first_name'] ?? '') . ' ' . (string)($teacherRow['last_name'] ?? ''));
    if ($teacherName === '') {
        $teacherName = 'Your teacher';
    }

    $recipientIds = [];

    $studentStmt = $db->prepare("SELECT DISTINCT u.id
                                 FROM enrollments e
                                 JOIN users u ON u.id = e.student_id
                                 WHERE e.class_id = ?
                                 AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                 AND u.role = 'student'
                                 AND u.status IN ('active', 'pending')");
    $studentStmt->execute([(int)$classId]);
    $studentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));
    $recipientIds = array_merge($recipientIds, $studentIds);

    $parentStmt = $db->prepare("SELECT DISTINCT p.id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                JOIN enrollments e ON e.student_id = ps.student_id
                                WHERE e.class_id = ?
                                AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute([(int)$classId]);
    $parentIds = array_map('intval', $parentStmt->fetchAll(PDO::FETCH_COLUMN));
    $recipientIds = array_merge($recipientIds, $parentIds);

    $recipientIds = array_values(array_unique(array_filter($recipientIds, function ($id) use ($teacherId) {
        return (int)$id > 0 && (int)$id !== (int)$teacherId;
    })));

    if (empty($recipientIds)) {
        return;
    }

    $title = "New learning material: " . trim((string)$materialTitle);
    $subtitle = $classSubtitle . " - Uploaded by " . $teacherName;
    $sourceKey = 'material_upload_' . (int)$materialId;
    appDispatchNotification(
        $db,
        $recipientIds,
        $sourceKey,
        $title,
        $subtitle,
        'bi-file-earmark-arrow-up',
        'success',
        ['student' => 'Student_Classes.php', 'parent' => 'Parent_Announcements.php'],
        ['type' => 'material', 'class_id' => (int)$classId, 'material_id' => (int)$materialId]
    );
}

function classifyQrScan($db, $teacherId) {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Invalid QR scan request']);
        return;
    }

    $classId = (int)($payload['class_id'] ?? 0);
    $date = trim((string)($payload['date'] ?? ''));
    $referenceCode = trim((string)($payload['reference_code'] ?? ''));
    if ($classId <= 0 || $referenceCode === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Class, date, and student QR code are required']);
        return;
    }
    if ($date !== date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'QR attendance scanning is available only for today']);
        return;
    }
    if (!teacherOwnsClass($db, $teacherId, $classId)) {
        echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
        return;
    }

    $classStmt = $db->prepare("SELECT schedule FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
    $classStmt->execute([$classId]);
    $schedule = trim((string)($classStmt->fetchColumn() ?: ''));
    $currentMinutes = ((int)date('G') * 60) + (int)date('i');
    $status = ScheduleParser::attendanceStatusAt($schedule, $date, $currentMinutes, 15);
    if ($status === null) {
        echo json_encode(['success' => false, 'message' => 'This class has no valid schedule for today']);
        return;
    }

    $studentStmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.reference_code
                                 FROM users u
                                 JOIN enrollments e ON e.student_id = u.id
                                 WHERE e.class_id = ?
                                 AND u.reference_code = ?
                                 AND u.role = 'student'
                                 AND u.status IN ('active', 'pending')
                                 AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                 ORDER BY e.id DESC
                                 LIMIT 1");
    $studentStmt->execute([$classId, $referenceCode]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student was not found in this class']);
        return;
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'student_id' => (int)$student['id'],
        'reference_code' => (string)$student['reference_code'],
        'student_name' => trim((string)$student['first_name'] . ' ' . (string)$student['last_name']),
        'scanned_at' => date('h:i A'),
    ]);
}

function teacherGetDisplayName($db, $teacherId) {
    $teacherStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $teacherStmt->execute([(int)$teacherId]);
    $teacherRow = $teacherStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $teacherName = trim((string)($teacherRow['first_name'] ?? '') . ' ' . (string)($teacherRow['last_name'] ?? ''));
    return $teacherName !== '' ? $teacherName : 'Teacher';
}

function teacherGetClassSubtitle($db, $classId) {
    $classStmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([(int)$classId]);
    $classRow = $classStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $className = trim((string)($classRow['class_name'] ?? 'Class'));
    $gradeLevel = trim((string)($classRow['grade_level'] ?? ''));
    $section = trim((string)($classRow['section'] ?? ''));
    $classSubtitle = $className;
    if ($gradeLevel !== '' && $section !== '') {
        $classSubtitle .= " (G{$gradeLevel} - {$section})";
    }
    return $classSubtitle;
}

function notifyAttendanceParents($db, $teacherId, $classId, $date, array $records) {
    appEnsureUserNotificationsTable($db);

    $hasTimeIn = teacherAttendanceSupportsColumn($db, 'time_in');
    $recordLookup = [];
    foreach ($records as $record) {
        $sid = (int)($record['student_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $recordLookup[$sid] = $record;
    }
    $studentIds = array_values(array_unique(array_keys($recordLookup)));
    $studentIds = array_values(array_filter($studentIds, function ($id) { return $id > 0; }));
    if (empty($studentIds)) {
        return;
    }

    $timeInLookup = [];
    if ($hasTimeIn) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $timeStmt = $db->prepare("SELECT student_id, time_in
                                  FROM attendance
                                  WHERE class_id = ? AND date = ? AND student_id IN ($placeholders)");
        $timeStmt->execute(array_merge([(int)$classId, $date], $studentIds));
        foreach ($timeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $timeInLookup[(int)$row['student_id']] = (string)($row['time_in'] ?? '');
        }
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $studentStmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
    $studentStmt->execute($studentIds);
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentLookup = [];
    foreach ($studentRows as $row) {
        $studentLookup[(int)$row['id']] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    }

    $parentStmt = $db->prepare("SELECT ps.student_id, p.id AS parent_id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                WHERE ps.student_id IN ($placeholders)
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute($studentIds);
    $parentLookup = [];
    foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)$row['student_id'];
        $pid = (int)$row['parent_id'];
        if ($sid <= 0 || $pid <= 0) {
            continue;
        }
        if (!isset($parentLookup[$sid])) {
            $parentLookup[$sid] = [];
        }
        $parentLookup[$sid][] = $pid;
    }
    $studentRecipientLookup = [];
    foreach ($studentIds as $sid) {
        $studentRecipientLookup[(int)$sid] = [(int)$sid];
    }

    $teacherName = teacherGetDisplayName($db, $teacherId);
    $classSubtitle = teacherGetClassSubtitle($db, $classId);
    $eventAt = date('Y-m-d H:i:s');

    $cleanupStmt = $db->prepare("DELETE FROM user_notifications
                                 WHERE user_id = ?
                                 AND title = 'Attendance recorded'
                                 AND subtitle LIKE ?");

    foreach ($recordLookup as $studentId => $record) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) {
            continue;
        }
        $status = strtolower(trim((string)($record['status'] ?? 'present')));
        $statusLabel = ucfirst($status);
        $studentName = $studentLookup[$studentId] ?? 'Student';
        $title = 'Attendance recorded';
        $timeInRaw = $timeInLookup[$studentId] ?? '';
        $timeInLabel = $timeInRaw !== '' ? date('h:i A', strtotime($timeInRaw)) : '-';
        $subtitle = $studentName . ' marked ' . $statusLabel . ' on ' . $date . ' (Time in: ' . $timeInLabel . ') in ' . $classSubtitle . ' by ' . $teacherName;
        $sourceKey = 'attendance_' . (int)$classId . '_' . $date . '_' . $studentId;
        $cleanupPattern = $studentName . ' marked % on ' . $date . ' %';

        $recipients = array_merge($parentLookup[$studentId] ?? [], $studentRecipientLookup[$studentId] ?? []);
        foreach (array_unique($recipients) as $recipientId) {
            if ($recipientId <= 0 || $recipientId === (int)$teacherId) {
                continue;
            }
            $cleanupStmt->execute([$recipientId, $cleanupPattern]);
            appDispatchNotification(
                $db,
                [$recipientId],
                $sourceKey,
                $title,
                $subtitle,
                'bi-calendar-check',
                'info',
                ['student' => 'Student_Attendance.php', 'parent' => 'Parent_Progress.php'],
                ['type' => 'attendance', 'class_id' => (int)$classId, 'student_id' => $studentId, 'date' => $date, 'status' => $status],
                $eventAt
            );

        }
    }
}

function notifyGradeSubmissionParents($db, $teacherId, $classId, $classSubjectId, $term, $academicYear, array $records, $semester = null) {
    $studentIds = array_values(array_unique(array_map(function ($record) {
        return (int)($record['student_id'] ?? 0);
    }, $records)));
    $studentIds = array_values(array_filter($studentIds, function ($id) { return $id > 0; }));
    if (empty($studentIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $studentStmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
    $studentStmt->execute($studentIds);
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentLookup = [];
    foreach ($studentRows as $row) {
        $studentLookup[(int)$row['id']] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    }

    $parentStmt = $db->prepare("SELECT ps.student_id, p.id AS parent_id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                WHERE ps.student_id IN ($placeholders)
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute($studentIds);
    $parentLookup = [];
    foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)$row['student_id'];
        $pid = (int)$row['parent_id'];
        if ($sid <= 0 || $pid <= 0) {
            continue;
        }
        if (!isset($parentLookup[$sid])) {
            $parentLookup[$sid] = [];
        }
        $parentLookup[$sid][] = $pid;
    }
    $studentRecipientLookup = [];
    foreach ($studentIds as $sid) {
        $studentRecipientLookup[(int)$sid] = [(int)$sid];
    }

    $teacherName = teacherGetDisplayName($db, $teacherId);
    $classSubtitle = teacherGetClassSubtitle($db, $classId);
    $eventAt = date('Y-m-d H:i:s');

    foreach ($records as $record) {
        $studentId = (int)($record['student_id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }
        $studentName = $studentLookup[$studentId] ?? 'Student';
        $title = 'Grades submitted';
        $periodLabel = $term;
        $subtitle = $studentName . ' grades submitted for ' . $classSubtitle . ' (' . $periodLabel . ' ' . $academicYear . ') by ' . $teacherName;
        $sourceKey = 'grade_submit_' . (int)$classSubjectId . '_' . $term . '_' . $academicYear . '_' . $studentId;

        $recipients = array_merge($parentLookup[$studentId] ?? [], $studentRecipientLookup[$studentId] ?? []);
        foreach (array_unique($recipients) as $recipientId) {
            if ($recipientId <= 0 || $recipientId === (int)$teacherId) {
                continue;
            }
            appDispatchNotification(
                $db,
                [$recipientId],
                $sourceKey,
                $title,
                $subtitle,
                'bi-clipboard-data',
                'info',
                ['student' => 'Student_Report_Card.php', 'parent' => 'Parent_Progress.php'],
                ['type' => 'grade_submission', 'class_id' => (int)$classId, 'student_id' => $studentId],
                $eventAt
            );
        }
    }
}

function notifyGradeItemScoreParents($db, $teacherId, array $item, array $scoreRows) {
    $studentIds = array_values(array_unique(array_map(function ($row) {
        return (int)($row['student_id'] ?? 0);
    }, $scoreRows)));
    $studentIds = array_values(array_filter($studentIds, function ($id) { return $id > 0; }));
    if (empty($studentIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $studentStmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
    $studentStmt->execute($studentIds);
    $studentRows = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentLookup = [];
    foreach ($studentRows as $row) {
        $studentLookup[(int)$row['id']] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    }

    $parentStmt = $db->prepare("SELECT ps.student_id, p.id AS parent_id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                WHERE ps.student_id IN ($placeholders)
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute($studentIds);
    $parentLookup = [];
    foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)$row['student_id'];
        $pid = (int)$row['parent_id'];
        if ($sid <= 0 || $pid <= 0) {
            continue;
        }
        if (!isset($parentLookup[$sid])) {
            $parentLookup[$sid] = [];
        }
        $parentLookup[$sid][] = $pid;
    }
    $studentRecipientLookup = [];
    foreach ($studentIds as $sid) {
        $studentRecipientLookup[(int)$sid] = [(int)$sid];
    }

    $teacherName = teacherGetDisplayName($db, $teacherId);
    $classSubtitle = trim((string)($item['class_name'] ?? 'Class'));
    if ((string)($item['grade_level'] ?? '') !== '' && trim((string)($item['section'] ?? '')) !== '') {
        $classSubtitle .= ' (G' . (int)$item['grade_level'] . ' - ' . trim((string)$item['section']) . ')';
    }
    $eventAt = date('Y-m-d H:i:s');
    $title = 'Score recorded: ' . trim((string)($item['title'] ?? 'Activity'));
    $totalScore = (string)($item['total_score'] ?? '');

    foreach ($scoreRows as $row) {
        $studentId = (int)($row['student_id'] ?? 0);
        $score = $row['score'] ?? null;
        if ($studentId <= 0 || $score === null || $score === '') {
            continue;
        }
        $studentName = $studentLookup[$studentId] ?? 'Student';
        $subtitle = $studentName . ' scored ' . $score . ($totalScore !== '' ? ('/' . $totalScore) : '') . ' in ' . $classSubtitle . ' by ' . $teacherName;
        $sourceKey = 'grade_item_score_' . (int)($item['id'] ?? 0) . '_' . $studentId;

        $recipients = array_merge($parentLookup[$studentId] ?? [], $studentRecipientLookup[$studentId] ?? []);
        foreach (array_unique($recipients) as $recipientId) {
            if ($recipientId <= 0 || $recipientId === (int)$teacherId) {
                continue;
            }
            appDispatchNotification(
                $db,
                [$recipientId],
                $sourceKey,
                $title,
                $subtitle,
                'bi-clipboard-check',
                'info',
                ['student' => 'Student_Report_Card.php', 'parent' => 'Parent_Progress.php'],
                ['type' => 'grade_score', 'grade_item_id' => (int)($item['id'] ?? 0), 'student_id' => $studentId],
                $eventAt
            );
        }
    }
}

function getClassWeights($db, $classId) {
    $stmt = $db->prepare("SELECT ww_weight, pt_weight, assessment_weight FROM classes WHERE id = ? LIMIT 1");
    $stmt->execute([$classId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ww = isset($row['ww_weight']) ? (float)$row['ww_weight'] : 25.0;
    $pt = isset($row['pt_weight']) ? (float)$row['pt_weight'] : 50.0;
    $assessment = isset($row['assessment_weight']) ? (float)$row['assessment_weight'] : 25.0;

    return [
        'ww_weight' => $ww,
        'pt_weight' => $pt,
        'assessment_weight' => $assessment
    ];
}

function normalizeTerm($value) {
    return \BshsAms\Grade\SshsGradeCalculator::normalizeTerm((string)$value);
}

function gradingSystemFromYear($academicYear) {
    return SshsGradeCalculator::gradingSystem($academicYear);
}

function gradesHasTermColumn($db) {
    static $hasTerm = null;
    if ($hasTerm !== null) {
        return $hasTerm;
    }
    $stmt = $db->query("SHOW COLUMNS FROM grades LIKE 'term'");
    $hasTerm = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hasTerm) {
        $stmt = $db->query("SHOW COLUMNS FROM grades LIKE 'quarter'");
        $hasTerm = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $hasTerm;
}

function getTermColumnName($db) {
    $stmt = $db->query("SHOW COLUMNS FROM grades LIKE 'term'");
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC) ? 'term' : 'quarter';
}

function gradesHasRawComponentColumns($db) {
    static $hasRaw = null;
    if ($hasRaw !== null) {
        return $hasRaw;
    }
    $required = [
        'ww_raw_score', 'ww_total_score',
        'pt_raw_score', 'pt_total_score',
        'assessment_raw_score', 'assessment_total_score'
    ];
    foreach ($required as $col) {
        $stmt = $db->query("SHOW COLUMNS FROM grades LIKE " . $db->quote($col));
        if (!(bool)$stmt->fetch(PDO::FETCH_ASSOC)) {
            $hasRaw = false;
            return $hasRaw;
        }
    }
    $hasRaw = true;
    return $hasRaw;
}


function markGradePendingApproval($db, $gradeId, $teacherId) {
    $stmt = $db->prepare("INSERT INTO grade_approvals (grade_id, status, submitted_by, submitted_at, reviewed_by, reviewed_at, remarks)
                          VALUES (?, 'submitted', ?, NOW(), NULL, NULL, NULL)
                          ON DUPLICATE KEY UPDATE
                            status = 'submitted',
                            submitted_by = VALUES(submitted_by),
                            submitted_at = NOW(),
                            reviewed_by = NULL,
                            reviewed_at = NULL,
                            remarks = NULL");
    $stmt->execute([(int)$gradeId, (int)$teacherId]);
}

function gradeItemsTablesExist($db) {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $a = (bool)$db->query("SHOW TABLES LIKE 'grade_items'")->fetch(PDO::FETCH_NUM);
    $b = (bool)$db->query("SHOW TABLES LIKE 'grade_item_scores'")->fetch(PDO::FETCH_NUM);
    $c = (bool)$db->query("SHOW TABLES LIKE 'grade_item_score_verifications'")->fetch(PDO::FETCH_NUM);
    $exists = $a && $b && $c;
    return $exists;
}

function fetchGradeItemRecord($db, $teacherId, $gradeItemId) {
    $stmt = $db->prepare("SELECT gi.id, gi.class_id, gi.title, gi.component, gi.total_score, gi.activity_date, gi.status,
                                 c.class_name, c.grade_level, c.section
                          FROM grade_items gi
                          JOIN classes c ON c.id = gi.class_id
                          WHERE gi.id = ? AND gi.teacher_id = ?
                          LIMIT 1");
    $stmt->execute([(int)$gradeItemId, (int)$teacherId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fetchGradeItemVerificationLookup($db, $gradeItemId) {
    $stmt = $db->prepare("SELECT gv.student_id, gv.verified_at, gv.verification_method,
                                 u.first_name AS verifier_first_name, u.last_name AS verifier_last_name
                          FROM grade_item_score_verifications gv
                          LEFT JOIN users u ON u.id = gv.verified_by
                          WHERE gv.grade_item_id = ?");
    $stmt->execute([(int)$gradeItemId]);

    $lookup = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lookup[(int)$row['student_id']] = [
            'verified_at' => (string)($row['verified_at'] ?? ''),
            'verification_method' => (string)($row['verification_method'] ?? 'manual'),
            'verifier_name' => trim((string)($row['verifier_first_name'] ?? '') . ' ' . (string)($row['verifier_last_name'] ?? ''))
        ];
    }
    return $lookup;
}

function notifyGradeItemVerificationParents($db, $teacherId, array $item, array $student) {
    $teacherName = teacherGetDisplayName($db, $teacherId);

    $studentName = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
    $classSubtitle = trim((string)$item['class_name']);
    if ((string)($item['grade_level'] ?? '') !== '' && trim((string)($item['section'] ?? '')) !== '') {
        $classSubtitle .= ' (G' . (int)$item['grade_level'] . ' - ' . trim((string)$item['section']) . ')';
    }

    $parentStmt = $db->prepare("SELECT DISTINCT p.id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                WHERE ps.student_id = ?
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute([(int)$student['id']]);
    $parentIds = array_map('intval', $parentStmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($parentIds)) {
        return;
    }

    $sourceKey = 'grade_item_verification_' . (int)$item['id'] . '_' . (int)$student['id'];
    $title = 'Activity verified: ' . trim((string)$item['title']);
    $subtitle = $studentName . ' was verified by ' . $teacherName . ' in ' . $classSubtitle;
    $eventAt = date('Y-m-d H:i:s');

    $parentIds = array_values(array_filter($parentIds, function ($parentId) use ($teacherId) {
        return $parentId > 0 && $parentId !== (int)$teacherId;
    }));
    appDispatchNotification(
        $db,
        $parentIds,
        $sourceKey,
        $title,
        $subtitle,
        'bi-qr-code-scan',
        'info',
        ['parent' => 'Parent_Progress.php'],
        ['type' => 'grade_verification', 'grade_item_id' => (int)$item['id'], 'student_id' => (int)$student['id']],
        $eventAt
    );
}


function teacherHasAdvisoryStudent($db, $teacherId, $studentId) {
    $advisory = getTeacherAdvisoryClassInfo($db, $teacherId);
    if (!$advisory) {
        return false;
    }
    $stmt = $db->prepare("SELECT 1
                          FROM users u
                          WHERE u.id = ?
                          AND u.role = 'student'
                          AND u.status IN ('active', 'pending')
                          AND u.grade_level = ?
                          AND " . sectionMatchSql('u.section') . "
                          LIMIT 1");
    $stmt->execute([
        (int)$studentId,
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section']
    ]);
    return (bool)$stmt->fetchColumn();
}

function fetchTeacherAdvisoryStudents($db, $teacherId) {
    $advisory = getTeacherAdvisoryClassInfo($db, $teacherId);
    if (!$advisory) {
        return [];
    }
    $stmt = $db->prepare("SELECT u.id, u.first_name, u.last_name
                          FROM users u
                          WHERE u.role = 'student'
                          AND u.status IN ('active', 'pending')
                          AND u.grade_level = ?
                          AND " . sectionMatchSql('u.section') . "
                          ORDER BY u.last_name, u.first_name");
    $stmt->execute([
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section']
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function attendanceHasTermColumns($db) {
    static $hasTerm = null;
    if ($hasTerm !== null) {
        return $hasTerm;
    }
    $hasYear = (bool)$db->query("SHOW COLUMNS FROM attendance LIKE 'academic_year'")->fetch(PDO::FETCH_ASSOC);
    $hasSemester = (bool)$db->query("SHOW COLUMNS FROM attendance LIKE 'semester'")->fetch(PDO::FETCH_ASSOC);
    $hasTerm = $hasYear && $hasSemester;
    return $hasTerm;
}

function attendanceDefaultTermFromDate($date) {
    $ts = strtotime((string)$date);
    if ($ts === false) {
        $ts = time();
    }
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $startYear = ($month >= 6) ? $year : ($year - 1);
    $academicYear = $startYear . '-' . ($startYear + 1);
    $semester = ($month >= 12 || $month <= 5) ? 2 : 1;
    return [$academicYear, $semester];
}

function gradePeriodBounds($academicYear, $term = null, $semester = null) {
    preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m);
    $startYear = (int)($m[1] ?? date('Y'));
    $endYear = (int)($m[2] ?? ($startYear + 1));
    $gs = gradingSystemFromYear($academicYear);

    if ($gs === '4_quarter') {
        if ($semester === 'S2') {
            if ($term === 'Q1') return [$startYear . '-12-01', $endYear . '-02-28'];
            if ($term === 'Q2') return [$endYear . '-03-01', $endYear . '-05-31'];
            return [$startYear . '-12-01', $endYear . '-05-31'];
        }
        if ($term === 'Q1') return [$startYear . '-06-01', $startYear . '-08-31'];
        if ($term === 'Q2') return [$startYear . '-09-01', $startYear . '-11-30'];
        return [$startYear . '-06-01', $startYear . '-11-30'];
    }

    if ($term === 'Term1') return [$startYear . '-07-01', $startYear . '-09-30'];
    if ($term === 'Term2') return [$startYear . '-10-01', $startYear . '-12-31'];
    if ($term === 'Term3') return [$endYear . '-01-01', $endYear . '-03-31'];
    return [$startYear . '-07-01', $endYear . '-03-31'];
}

function gradePeriodFromActivityDate($activityDate): array {
    $ts = strtotime((string)$activityDate);
    if ($ts === false) {
        $ts = time();
    }
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $startYear = ($month >= 6) ? $year : ($year - 1);
    $academicYear = $startYear . '-' . ($startYear + 1);
    $gs = gradingSystemFromYear($academicYear);

    if ($gs === '4_quarter') {
        if ($month >= 12 || $month <= 5) {
            return [$academicYear, ($month <= 2 || $month === 12) ? 'Q1' : 'Q2', 'S2'];
        }
        return [$academicYear, $month <= 8 ? 'Q1' : 'Q2', 'S1'];
    }

    if ($month >= 10 && $month <= 12) {
        return [$academicYear, 'Term2', null];
    }
    if ($month >= 1 && $month <= 3) {
        return [$academicYear, 'Term3', null];
    }
    return [$academicYear, 'Term1', null];
}

function gradeActivityPeriodLockMessage($db, $teacherId, $classId, $activityDate): ?string {
    if (!gradesHasTermColumn($db)) {
        return null;
    }
    [$academicYear, $term, $semester] = gradePeriodFromActivityDate($activityDate);
    $tc = getTermColumnName($db);
    $sql = "SELECT ga.status
            FROM grade_approvals ga
            JOIN grades g ON g.id = ga.grade_id
            JOIN class_subjects cs ON cs.id = g.class_subject_id
            WHERE cs.class_id = ?
            AND cs.teacher_id = ?
            AND g.{$tc} = ?
            AND g.academic_year = ?
            AND ga.status = 'submitted'
            LIMIT 1";
    $params = [(int)$classId, (int)$teacherId, $term, $academicYear];
    if ($semester !== null) {
        $sql .= " AND g.semester = ?";
        $params[] = $semester;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $status = strtolower((string)($stmt->fetchColumn() ?: ''));
    if ($status === '') {
        return null;
    }
    return 'This grading period was already submitted to admin. Recall the submission before editing grade activities.';
}

function computeQuarterSummaryFromItems($db, $classId, $teacherId, $dateFrom, $dateTo, $weights) {
    $sql = "SELECT gis.student_id, gi.component,
            SUM(gis.score) AS score_sum,
            SUM(gi.total_score) AS total_sum
            FROM grade_items gi
            JOIN grade_item_scores gis ON gis.grade_item_id = gi.id
            WHERE gi.class_id = ?
            AND gi.status IN ('active', 'finished')
            AND COALESCE(DATE(gi.finished_at), gi.activity_date, DATE(gi.created_at)) BETWEEN ? AND ?";
    $params = [$classId, $dateFrom, $dateTo];
    if ($teacherId !== null) {
        $sql .= " AND gi.teacher_id = ?";
        $params[] = (int)$teacherId;
    }
    $sql .= " GROUP BY gis.student_id, gi.component";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $studentId = (int)$row['student_id'];
        $component = strtoupper((string)$row['component']);
        $score = (float)$row['score_sum'];
        $total = (float)$row['total_sum'];
        if ($total <= 0) {
            continue;
        }
        $percent = round(($score / $total) * 100, 2);
        if (!isset($map[$studentId])) {
            $map[$studentId] = ['ww_score' => null, 'pt_score' => null, 'assessment_score' => null, 'quarter_final_grade' => null];
        }
        if ($component === 'WW') $map[$studentId]['ww_score'] = $percent;
        if ($component === 'PT') $map[$studentId]['pt_score'] = $percent;
        if ($component === 'ASSESSMENT') $map[$studentId]['assessment_score'] = $percent;
    }

    foreach ($map as $studentId => $vals) {
        $wwWs = $vals['ww_score'] !== null ? round($vals['ww_score'] * ((float)$weights['ww_weight'] / 100), 2) : null;
        $ptWs = $vals['pt_score'] !== null ? round($vals['pt_score'] * ((float)$weights['pt_weight'] / 100), 2) : null;
        $qaWs = $vals['assessment_score'] !== null ? round($vals['assessment_score'] * ((float)$weights['assessment_weight'] / 100), 2) : null;
        if ($wwWs !== null && $ptWs !== null && $qaWs !== null) {
            $initial = round($wwWs + $ptWs + $qaWs, 2);
            $map[$studentId]['quarter_final_grade'] = transmuteQuarterlyGrade($initial);
        } else {
            $map[$studentId]['quarter_final_grade'] = null;
        }
    }
    return $map;
}

function transmuteQuarterlyGrade($initial) {
    if ($initial === null || $initial === '') {
        return null;
    }
    $initial = (float)$initial;
    if ($initial < 0) {
        return null;
    }
    $table = [
        [0, 60], [4, 61], [8, 62], [12, 63], [16, 64], [20, 65], [24, 66], [28, 67], [32, 68], [36, 69],
        [40, 70], [44, 71], [48, 72], [52, 73], [56, 74], [60, 75], [61.6, 76], [63.2, 77], [64.8, 78], [66.4, 79],
        [68, 80], [69.6, 81], [71.2, 82], [72.8, 83], [74.4, 84], [76, 85], [77.6, 86], [79.2, 87], [80.8, 88], [82.4, 89],
        [84, 90], [85.6, 91], [87.2, 92], [88.8, 93], [90.4, 94], [92, 95], [93.6, 96], [95.2, 97], [96.8, 98], [98.4, 99],
        [100, 100]
    ];
    $result = null;
    foreach ($table as $row) {
        if ($initial >= (float)$row[0]) {
            $result = (float)$row[1];
        } else {
            break;
        }
    }
    return $result;
}

function getActiveEnrollmentStudentIds($db, $classId) {
    $ids = tEnrollFetchActiveStudentIds($db, $classId);

    $fallbackStmt = $db->prepare("SELECT u.id
                                  FROM users u
                                  JOIN classes c ON c.id = ?
                                  WHERE u.role = 'student'
                                  AND u.status IN ('active', 'pending')
                                  AND u.grade_level = c.grade_level
                                  AND " . sectionMatchSql('u.section', 'c.section'));
    $fallbackStmt->execute([(int)$classId]);
    $fallbackIds = array_map('intval', $fallbackStmt->fetchAll(PDO::FETCH_COLUMN));

    return array_values(array_unique(array_merge($ids, $fallbackIds)));
}

function getActiveEnrollmentTermMap($db, $classId) {
    return tEnrollFetchActiveTermMap($db, $classId);
}

function buildStudentAttendancePayload($db, $classId, $date, $mode = 'subject', $sex = '') {
    $sex = strtolower(trim((string)$sex));
    if (!in_array($sex, ['male', 'female'], true)) {
        $sex = '';
    }

    $students = tEnrollFetchStudentsWithAttendance($db, $classId, $date, $sex);

    $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'cutting' => 0, 'total' => count($students), 'rate' => 0];
    foreach ($students as $student) {
        $status = $student['attendance_status'];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }
    if ($summary['total'] > 0) {
        $summary['rate'] = round((($summary['present'] + $summary['late']) / $summary['total']) * 100, 1);
    }

    return [$students, $summary];
}

function fetchStudents($db, $teacherId) {
    try {
        $classId = (int)($_GET['class_id'] ?? 0);
        $date = normalizeDate($_GET['date'] ?? '');
        $sex = strtolower(trim((string)($_GET['sex'] ?? '')));
        if (!in_array($sex, ['male', 'female'], true)) {
            $sex = '';
        }
        $mode = trim((string)($_GET['mode'] ?? 'subject'));
        if (!in_array($mode, ['subject', 'advisory'], true)) {
            $mode = 'subject';
        }

        if ($classId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class is required']);
            return;
        }

        if (!teacherCanViewClass($db, $teacherId, $classId, $mode)) {
            echo json_encode(['success' => false, 'message' => 'You cannot view this class in selected mode']);
            return;
        }

        $canEdit = teacherOwnsClass($db, $teacherId, $classId);
        if ($mode === 'advisory') {
            $canEdit = false;
        }
        if ($canEdit && !teacherClassHasScheduleOnDate($db, $classId, $date)) {
            $canEdit = false;
        }

        [$students, $summary] = buildStudentAttendancePayload($db, $classId, $date, $mode, $sex);
        echo json_encode([
            'success' => true,
            'students' => $students,
            'summary' => $summary,
            'can_edit' => $canEdit,
            'message' => $canEdit ? '' : 'This class has no schedule on the selected date.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function fetchClassStudents($db, $teacherId) {
    try {
        $classId = (int)($_GET['class_id'] ?? 0);
        if ($classId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class is required']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        $stmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                              FROM enrollments e
                              JOIN users u ON u.id = e.student_id
                              WHERE e.class_id = ? AND e.status = 'enrolled'
                              ORDER BY u.last_name, u.first_name");
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'students' => $students]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function submitAttendance($db, $teacherId) {
    try {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
            return;
        }

        $classId = (int)($payload['class_id'] ?? 0);
        $date = normalizeDate($payload['date'] ?? '');
        $mode = trim((string)($payload['mode'] ?? 'subject'));
        if (!in_array($mode, ['subject', 'advisory'], true)) {
            $mode = 'subject';
        }
        $records = $payload['records'] ?? [];

        if ($classId <= 0 || !is_array($records) || empty($records)) {
            echo json_encode(['success' => false, 'message' => 'Class, date, and records are required']);
            return;
        }
        if ($mode === 'advisory') {
            echo json_encode(['success' => false, 'message' => 'Advisory attendance is view-only']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }
        if (!teacherClassHasScheduleOnDate($db, $classId, $date)) {
            echo json_encode(['success' => false, 'message' => 'Cannot record attendance because this class has no schedule on the selected date']);
            return;
        }

        $enrolledIds = getActiveEnrollmentStudentIds($db, $classId);
        $enrolledLookup = array_fill_keys($enrolledIds, true);
        $validStatuses = ['present', 'absent', 'late', 'cutting'];

        $existingRecordsStmt = $db->prepare("SELECT student_id, status, remarks FROM attendance WHERE class_id = ? AND date = ?");
        $existingRecordsStmt->execute([(int)$classId, $date]);
        $existingMap = [];
        foreach ($existingRecordsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingMap[(int)$row['student_id']] = [
                'status' => strtolower(trim((string)$row['status'])),
                'remarks' => trim((string)($row['remarks'] ?? '')),
            ];
        }

        $changedRecords = [];
        $db->beginTransaction();
        foreach ($records as $record) {
            $studentId = (int)($record['student_id'] ?? 0);
            $status = strtolower(trim((string)($record['status'] ?? '')));
            $remarks = trim((string)($record['remarks'] ?? ''));

            if ($studentId <= 0 || !isset($enrolledLookup[$studentId])) {
                continue;
            }
            if (!in_array($status, $validStatuses, true)) {
                $status = 'present';
            }

            if (!isset($existingMap[$studentId])) {
                $changedRecords[] = ['student_id' => $studentId, 'status' => $status, 'remarks' => $remarks];
            } elseif ($existingMap[$studentId]['status'] !== $status || $existingMap[$studentId]['remarks'] !== $remarks) {
                $changedRecords[] = ['student_id' => $studentId, 'status' => $status, 'remarks' => $remarks];
            }

            teacherAttendanceUpsertRecord($db, $teacherId, $classId, $date, $studentId, $status, $remarks);
        }

        $db->commit();

        if (!empty($changedRecords)) {
            notifyAttendanceParents($db, $teacherId, $classId, $date, $changedRecords);
        }

        [, $summary] = buildStudentAttendancePayload($db, $classId, $date);
        echo json_encode(['success' => true, 'message' => 'Attendance saved successfully', 'summary' => $summary]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Teacher_Action submitAttendance error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function normalizeScore($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $score = (float)$value;
    if ($score < 0 || $score > 100) {
        return null;
    }
    return round($score, 2);
}

function normalizeRawScore($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $score = (float)$value;
    if ($score < 0) {
        return null;
    }
    return round($score, 2);
}

function normalizeTotalScore($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $score = (float)$value;
    if ($score <= 0) {
        return null;
    }
    return round($score, 2);
}

function computeComponentPercent($raw, $total) {
    if ($raw === null || $total === null || $total <= 0) {
        return null;
    }
    return round(($raw / $total) * 100, 2);
}

function fetchGrades($db, $teacherId) {
    try {
        $classId = (int)($_GET['class_id'] ?? 0);
        $term = normalizeTerm($_GET['term'] ?? ($_GET['quarter'] ?? 'Term1'));
        $academicYear = trim((string)($_GET['academic_year'] ?? ''));
        $sex = strtolower(trim((string)($_GET['sex'] ?? '')));
        if (!in_array($sex, ['male', 'female'], true)) {
            $sex = '';
        }
        $mode = trim((string)($_GET['mode'] ?? 'subject'));
        if (!in_array($mode, ['subject', 'advisory'], true)) {
            $mode = 'subject';
        }

        $gs = gradingSystemFromYear($academicYear);
        $semester = null;
        if ($gs === '4_quarter') {
            $semester = trim((string)($_GET['semester'] ?? ''));
            if ($semester === '') {
                $semester = (in_array($term, ['Q1', 'Q2']) ? 'S1' : 'S2');
            }
        }

        if ($classId <= 0 || $academicYear === '') {
            echo json_encode(['success' => false, 'message' => 'Class and academic year are required']);
            return;
        }
        $hasGradeApprovals = (bool)$db->query("SHOW TABLES LIKE 'grade_approvals'")->fetch(PDO::FETCH_NUM);

        if (!teacherCanViewClass($db, $teacherId, $classId, $mode)) {
            echo json_encode(['success' => false, 'message' => 'You cannot view this class in selected mode']);
            return;
        }

        $isOwnClass = teacherOwnsClass($db, $teacherId, $classId);
        $advisoryViewer = ($mode === 'advisory' && !$isOwnClass);
        if (!gradesHasTermColumn($db)) {
            echo json_encode(['success' => false, 'message' => "Database update required: add grades.term column first"]);
            return;
        }

        $tc = getTermColumnName($db);
        $weights = getClassWeights($db, $classId);
        if (gradeItemsTablesExist($db)) {
            $dateFrom = null; $dateTo = null;
            if ($gs === '4_quarter') {
                [$dateFrom, $dateTo] = gradePeriodBounds($academicYear, $term, $semester);
            } else {
                [$dateFrom, $dateTo] = gradePeriodBounds($academicYear, $term);
            }
            $itemTeacherScope = $advisoryViewer ? null : $teacherId;
            $termMap = computeQuarterSummaryFromItems($db, $classId, $itemTeacherScope, $dateFrom, $dateTo, $weights);

            $studentsStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                          FROM users u
                                          " . tEnrollActiveUsersJoinSql() . "
                                          WHERE (? = '' OR LOWER(COALESCE(u.sex, '')) = ?)
                                          ORDER BY u.last_name, u.first_name");
            $studentsStmt->execute([$classId, $classId, $sex, $sex]);
            $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

            $approvalMap = [];
            if ($hasGradeApprovals) {
                $approvalSql = "SELECT g.student_id,
                                       CASE
                                         WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                                         WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'submitted' THEN 1 ELSE 0 END) > 0 THEN 'submitted'
                                         WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'admin_verified' THEN 1 ELSE 0 END) > 0 THEN 'admin_verified'
                                         ELSE 'pending'
                                       END AS approval_status
                                FROM grades g
                                JOIN class_subjects cs ON cs.id = g.class_subject_id
                                LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                                WHERE cs.class_id = ?
                                  AND g.{$tc} = ?
                                  AND g.academic_year = ?";
                $approvalParams = [$classId, $term, $academicYear];
                if ($gs === '4_quarter') {
                    $approvalSql .= " AND g.semester = ?";
                    $approvalParams[] = $semester;
                }
                if (!$advisoryViewer) {
                    $approvalSql .= " AND cs.teacher_id = ?";
                    $approvalParams[] = $teacherId;
                }
                $approvalSql .= " GROUP BY g.student_id";
                $approvalStmt = $db->prepare($approvalSql);
                $approvalStmt->execute($approvalParams);
                foreach ($approvalStmt->fetchAll(PDO::FETCH_ASSOC) as $ap) {
                    $approvalMap[(int)$ap['student_id']] = strtolower((string)$ap['approval_status']);
                }
            }

            $rows = [];
            foreach ($students as $s) {
                $sid = (int)$s['id'];
                $cur = $termMap[$sid] ?? ['ww_score' => null, 'pt_score' => null, 'assessment_score' => null, 'quarter_final_grade' => null];
                $rows[] = [
                    'id' => $sid,
                    'reference_code' => $s['reference_code'],
                    'first_name' => $s['first_name'],
                    'last_name' => $s['last_name'],
                    'ww_raw_score' => null,
                    'ww_total_score' => null,
                    'pt_raw_score' => null,
                    'pt_total_score' => null,
                    'assessment_raw_score' => null,
                    'assessment_total_score' => null,
                    'ww_score' => $cur['ww_score'],
                    'pt_score' => $cur['pt_score'],
                    'assessment_score' => $cur['assessment_score'],
                    'quarter_final_grade' => $cur['quarter_final_grade'],
                    'semester_final_grade' => null,
                    'approval_status' => $approvalMap[$sid] ?? 'pending'
                ];
            }
            echo json_encode([
                'success' => true,
                'students' => $rows,
                'weights' => $weights,
                'term' => $term,
                'source' => 'grade_items',
                'can_edit' => !$advisoryViewer
            ]);
            return;
        }

        $hasRaw = gradesHasRawComponentColumns($db);
        $rawQuarterSelect = $hasRaw
            ? "ROUND(AVG(g.ww_raw_score), 2) AS ww_raw_score,
               ROUND(AVG(g.ww_total_score), 2) AS ww_total_score,
               ROUND(AVG(g.pt_raw_score), 2) AS pt_raw_score,
               ROUND(AVG(g.pt_total_score), 2) AS pt_total_score,
               ROUND(AVG(g.assessment_raw_score), 2) AS assessment_raw_score,
               ROUND(AVG(g.assessment_total_score), 2) AS assessment_total_score,"
            : "NULL AS ww_raw_score,
               NULL AS ww_total_score,
               NULL AS pt_raw_score,
               NULL AS pt_total_score,
               NULL AS assessment_raw_score,
               NULL AS assessment_total_score,";

        $gradeScope = "cs.class_id = ?";
        $gradeScopeParams = [$classId];
        if (!$advisoryViewer) {
            $gradeScope .= " AND cs.teacher_id = ?";
            $gradeScopeParams[] = $teacherId;
        }

        $semesterJoin = '';
        $semesterParams = [];
        if ($gs === '4_quarter' && $semester !== null) {
            $semesterJoin = " AND g.semester = ?";
            $semesterParams[] = $semester;
        }

        $query = "SELECT u.id, u.reference_code, u.first_name, u.last_name,
                  q.ww_raw_score, q.ww_total_score, q.pt_raw_score, q.pt_total_score, q.assessment_raw_score, q.assessment_total_score,
                  q.ww_score, q.pt_score, q.assessment_score, q.quarter_final_grade,
                  NULL AS semester_final_grade,
                  COALESCE(ap.approval_status, 'pending') AS approval_status
                  FROM users u
                  " . tEnrollActiveUsersJoinSql() . "
                  LEFT JOIN (
                    SELECT g.student_id,
                           $rawQuarterSelect
                           ROUND(AVG(g.quiz_score), 2) AS ww_score,
                           ROUND(AVG(g.activity_score), 2) AS pt_score,
                           ROUND(AVG(g.exam_score), 2) AS assessment_score,
                           ROUND(AVG(g.final_grade), 2) AS quarter_final_grade
                    FROM grades g
                    JOIN class_subjects cs ON cs.id = g.class_subject_id
                    WHERE $gradeScope
                    $semesterJoin
                    AND g.{$tc} = ?
                    AND g.academic_year = ?
                    GROUP BY g.student_id
                  ) q ON q.student_id = u.id
                  LEFT JOIN (
                    SELECT g.student_id,
                           CASE
                             WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                             WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'submitted' THEN 1 ELSE 0 END) > 0 THEN 'submitted'
                             WHEN SUM(CASE WHEN COALESCE(ga.status, 'pending') = 'admin_verified' THEN 1 ELSE 0 END) > 0 THEN 'admin_verified'
                             ELSE 'pending'
                           END AS approval_status
                    FROM grades g
                    JOIN class_subjects cs ON cs.id = g.class_subject_id
                    LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                    WHERE $gradeScope
                    $semesterJoin
                    AND g.{$tc} = ?
                    AND g.academic_year = ?
                    GROUP BY g.student_id
                  ) ap ON ap.student_id = u.id
                  WHERE (? = '' OR LOWER(COALESCE(u.sex, '')) = ?)
                  ORDER BY u.last_name, u.first_name";
        $params = array_merge(
            [$classId, $classId],
            $gradeScopeParams,
            $semesterParams,
            [$term, $academicYear],
            $gradeScopeParams,
            $semesterParams,
            [$term, $academicYear],
            [$sex, $sex]
        );
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['approval_status'] = strtolower((string)($row['approval_status'] ?? 'pending'));
        }
        unset($row);

        echo json_encode([
            'success' => true,
            'students' => $rows,
            'weights' => $weights,
            'term' => $term,
            'can_edit' => !$advisoryViewer
        ]);
    } catch (PDOException $e) {
        error_log("fetchGrades error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function submitGrades($db, $teacherId) {
    try {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
            return;
        }

        $classId = (int)($payload['class_id'] ?? 0);
        $term = normalizeTerm($payload['term'] ?? ($payload['quarter'] ?? 'Term1'));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $records = $payload['records'] ?? [];

        $gs = gradingSystemFromYear($academicYear);
        $semester = null;
        if ($gs === '4_quarter') {
            $semester = trim((string)($payload['semester'] ?? ''));
            if ($semester === '') {
                $semester = (in_array($term, ['Q1', 'Q2']) ? 'S1' : 'S2');
            }
        }

        if ($classId <= 0 || $academicYear === '' || !is_array($records) || empty($records)) {
            echo json_encode(['success' => false, 'message' => 'Class, academic year, and records are required']);
            return;
        }

        if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            echo json_encode(['success' => false, 'message' => 'Academic year must be in YYYY-YYYY format']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        $classSubjectId = getTeacherClassSubjectId($db, $teacherId, $classId);
        if ($classSubjectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class subject assignment not found']);
            return;
        }
        $tc = getTermColumnName($db);
        if (!gradesHasTermColumn($db)) {
            echo json_encode(['success' => false, 'message' => "Database update required: add grades.term column first"]);
            return;
        }

        $lockedSql = "SELECT ga.status
                      FROM grade_approvals ga
                      JOIN grades g ON g.id = ga.grade_id
                      JOIN class_subjects cs ON cs.id = g.class_subject_id
                      WHERE cs.class_id = ?
                      AND cs.teacher_id = ?
                      AND g.{$tc} = ?
                      AND g.academic_year = ?
                      AND ga.status = 'submitted'
                      LIMIT 1";
        $lockedParams = [$classId, $teacherId, $term, $academicYear];
        if ($gs === '4_quarter') {
            $lockedSql .= " AND g.semester = ?";
            $lockedParams[] = $semester;
        }
        $lockedStmt = $db->prepare($lockedSql);
        $lockedStmt->execute($lockedParams);
        $lockedStatus = strtolower((string)($lockedStmt->fetchColumn() ?: ''));
        if ($lockedStatus !== '') {
            echo json_encode(['success' => false, 'message' => 'These grades are already submitted to admin. Recall the submission before editing or resubmitting.']);
            return;
        }

        $weights = getClassWeights($db, $classId);
        $hasRaw = gradesHasRawComponentColumns($db);

        $enrolledStmt = $db->prepare("SELECT student_id FROM enrollments
                                      WHERE class_id = ?
                                      AND COALESCE(status, 'enrolled') NOT IN ('dropped', 'completed')");
        $enrolledStmt->execute([$classId]);
        $enrolledIds = array_map('intval', $enrolledStmt->fetchAll(PDO::FETCH_COLUMN));
        $enrolledLookup = array_fill_keys($enrolledIds, true);

        if ($gs === '4_quarter') {
            $selectStmt = $db->prepare("SELECT id FROM grades
                                        WHERE student_id = ? AND class_subject_id = ? AND semester = ? AND {$tc} = ? AND academic_year = ?
                                        ORDER BY id DESC LIMIT 1");
        } else {
            $selectStmt = $db->prepare("SELECT id FROM grades
                                        WHERE student_id = ? AND class_subject_id = ? AND {$tc} = ? AND academic_year = ?
                                        ORDER BY id DESC LIMIT 1");
        }

        if ($hasRaw) {
            if ($gs === '4_quarter') {
                $insertStmt = $db->prepare("INSERT INTO grades (student_id, class_subject_id, ww_raw_score, ww_total_score, pt_raw_score, pt_total_score, assessment_raw_score, assessment_total_score, quiz_score, exam_score, activity_score, final_grade, semester, {$tc}, academic_year, recorded_by, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $updateStmt = $db->prepare("UPDATE grades
                                            SET ww_raw_score = ?, ww_total_score = ?, pt_raw_score = ?, pt_total_score = ?, assessment_raw_score = ?, assessment_total_score = ?, quiz_score = ?, exam_score = ?, activity_score = ?, final_grade = ?, recorded_by = ?
                                            WHERE id = ?");
            } else {
                $insertStmt = $db->prepare("INSERT INTO grades (student_id, class_subject_id, ww_raw_score, ww_total_score, pt_raw_score, pt_total_score, assessment_raw_score, assessment_total_score, quiz_score, exam_score, activity_score, final_grade, {$tc}, academic_year, recorded_by, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $updateStmt = $db->prepare("UPDATE grades
                                            SET ww_raw_score = ?, ww_total_score = ?, pt_raw_score = ?, pt_total_score = ?, assessment_raw_score = ?, assessment_total_score = ?, quiz_score = ?, exam_score = ?, activity_score = ?, final_grade = ?, recorded_by = ?
                                            WHERE id = ?");
            }
        } else {
            if ($gs === '4_quarter') {
                $insertStmt = $db->prepare("INSERT INTO grades (student_id, class_subject_id, quiz_score, exam_score, activity_score, final_grade, semester, {$tc}, academic_year, recorded_by, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            } else {
                $insertStmt = $db->prepare("INSERT INTO grades (student_id, class_subject_id, quiz_score, exam_score, activity_score, final_grade, {$tc}, academic_year, recorded_by, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            }
            $updateStmt = $db->prepare("UPDATE grades
                                        SET quiz_score = ?, exam_score = ?, activity_score = ?, final_grade = ?, recorded_by = ?
                                        WHERE id = ?");
        }

        $db->beginTransaction();
        $submittedStudentIds = [];

        foreach ($records as $record) {
            $studentId = (int)($record['student_id'] ?? 0);
            if ($studentId <= 0 || !isset($enrolledLookup[$studentId])) {
                continue;
            }

            $wwRaw = normalizeRawScore($record['ww_raw_score'] ?? null);
            $wwTotal = normalizeTotalScore($record['ww_total_score'] ?? null);
            $ptRaw = normalizeRawScore($record['pt_raw_score'] ?? null);
            $ptTotal = normalizeTotalScore($record['pt_total_score'] ?? null);
            $assessmentRaw = normalizeRawScore($record['assessment_raw_score'] ?? null);
            $assessmentTotal = normalizeTotalScore($record['assessment_total_score'] ?? null);

            if ($wwRaw !== null && $wwTotal !== null && $wwRaw > $wwTotal) {
                if ($db->inTransaction()) { $db->rollBack(); }
                echo json_encode(['success' => false, 'message' => 'Written Work score cannot exceed total score']);
                return;
            }
            if ($ptRaw !== null && $ptTotal !== null && $ptRaw > $ptTotal) {
                if ($db->inTransaction()) { $db->rollBack(); }
                echo json_encode(['success' => false, 'message' => 'Performance Task score cannot exceed total score']);
                return;
            }
            if ($assessmentRaw !== null && $assessmentTotal !== null && $assessmentRaw > $assessmentTotal) {
                if ($db->inTransaction()) { $db->rollBack(); }
                echo json_encode(['success' => false, 'message' => 'Assessment score cannot exceed total score']);
                return;
            }

            $ww = computeComponentPercent($wwRaw, $wwTotal);
            $pt = computeComponentPercent($ptRaw, $ptTotal);
            $assessment = computeComponentPercent($assessmentRaw, $assessmentTotal);

            if ($ww === null) {
                $ww = normalizeScore($record['ww_score'] ?? ($record['quiz_score'] ?? null));
            }
            if ($pt === null) {
                $pt = normalizeScore($record['pt_score'] ?? ($record['activity_score'] ?? null));
            }
            if ($assessment === null) {
                $assessment = normalizeScore($record['assessment_score'] ?? ($record['exam_score'] ?? null));
            }

            $weightedTotal = 0.0;
            $weightBase = 0.0;
            if ($ww !== null) {
                $weightedTotal += $ww * (float)$weights['ww_weight'];
                $weightBase += (float)$weights['ww_weight'];
            }
            if ($pt !== null) {
                $weightedTotal += $pt * (float)$weights['pt_weight'];
                $weightBase += (float)$weights['pt_weight'];
            }
            if ($assessment !== null) {
                $weightedTotal += $assessment * (float)$weights['assessment_weight'];
                $weightBase += (float)$weights['assessment_weight'];
            }
            $final = $weightBase > 0 ? round($weightedTotal / $weightBase, 2) : null;
            $transmutedGrade = ($final !== null) ? transmuteQuarterlyGrade($final) : null;

            if ($gs === '4_quarter') {
                $selectStmt->execute([$studentId, $classSubjectId, $semester, $term, $academicYear]);
            } else {
                $selectStmt->execute([$studentId, $classSubjectId, $term, $academicYear]);
            }
            $gradeId = (int)($selectStmt->fetchColumn() ?: 0);

            if ($gradeId > 0) {
                if ($hasRaw) {
                    $updateStmt->execute([$wwRaw, $wwTotal, $ptRaw, $ptTotal, $assessmentRaw, $assessmentTotal, $ww, $assessment, $pt, $transmutedGrade, $teacherId, $gradeId]);
                } else {
                    $updateStmt->execute([$ww, $assessment, $pt, $transmutedGrade, $teacherId, $gradeId]);
                }
            } else {
                if ($hasRaw) {
                    if ($gs === '4_quarter') {
                        $insertStmt->execute([$studentId, $classSubjectId, $wwRaw, $wwTotal, $ptRaw, $ptTotal, $assessmentRaw, $assessmentTotal, $ww, $assessment, $pt, $transmutedGrade, $semester, $term, $academicYear, $teacherId]);
                    } else {
                        $insertStmt->execute([$studentId, $classSubjectId, $wwRaw, $wwTotal, $ptRaw, $ptTotal, $assessmentRaw, $assessmentTotal, $ww, $assessment, $pt, $transmutedGrade, $term, $academicYear, $teacherId]);
                    }
                } else {
                    if ($gs === '4_quarter') {
                        $insertStmt->execute([$studentId, $classSubjectId, $ww, $assessment, $pt, $transmutedGrade, $semester, $term, $academicYear, $teacherId]);
                    } else {
                        $insertStmt->execute([$studentId, $classSubjectId, $ww, $assessment, $pt, $transmutedGrade, $term, $academicYear, $teacherId]);
                    }
                }
                $gradeId = (int)$db->lastInsertId();
            }

            if ($gradeId > 0) {
                markGradePendingApproval($db, $gradeId, $teacherId);
                $submittedStudentIds[$studentId] = true;
            }
        }

        if (!empty($submittedStudentIds)) {
            $studentIds = array_keys($submittedStudentIds);
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $reportSql = "UPDATE report_card_approvals
                          SET status = 'rejected',
                              reviewed_by = NULL,
                              reviewed_at = NOW(),
                              remarks = 'Subject grade correction submitted by teacher.'
                          WHERE student_id IN ({$placeholders})
                          AND academic_year = ?
                          AND status = 'approved'";
            $reportParams = array_merge($studentIds, [$academicYear]);
            if ($gs === '4_quarter') {
                $reportSql .= " AND semester = ?";
                $reportParams[] = $semester;
            } else {
                $reportSql .= " AND (semester IS NULL OR semester = '')";
            }
            $reportStmt = $db->prepare($reportSql);
            $reportStmt->execute($reportParams);
        }

        $db->commit();
        notifyGradeSubmissionParents($db, $teacherId, $classId, $classSubjectId, $term, $academicYear, $records);
        echo json_encode(['success' => true, 'message' => 'Grades submitted to Adviser Report Card']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("submitGrades error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function recallGrades($db, $teacherId) {
    try {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
            return;
        }

        $classId = (int)($payload['class_id'] ?? 0);
        $term = normalizeTerm($payload['term'] ?? ($payload['quarter'] ?? 'Term1'));
        $academicYear = trim((string)($payload['academic_year'] ?? ''));
        $gs = gradingSystemFromYear($academicYear);
        $semester = null;
        if ($gs === '4_quarter') {
            $semester = trim((string)($payload['semester'] ?? ''));
            if ($semester === '') {
                $semester = in_array($term, ['Q1', 'Q2'], true) ? 'S1' : 'S2';
            }
        }

        if ($classId <= 0 || $academicYear === '' || !preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            echo json_encode(['success' => false, 'message' => 'Class and valid academic year are required']);
            return;
        }
        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }
        if (!gradesHasTermColumn($db)) {
            echo json_encode(['success' => false, 'message' => "Database update required: add grades.term column first"]);
            return;
        }

        $tc = getTermColumnName($db);
        $lockedSql = "SELECT COUNT(*)
                      FROM grade_approvals ga
                      JOIN grades g ON g.id = ga.grade_id
                      JOIN class_subjects cs ON cs.id = g.class_subject_id
                      WHERE cs.class_id = ?
                      AND cs.teacher_id = ?
                      AND g.{$tc} = ?
                      AND g.academic_year = ?
                      AND ga.status IN ('admin_verified','approved')";
        $lockedParams = [$classId, $teacherId, $term, $academicYear];
        if ($gs === '4_quarter') {
            $lockedSql .= " AND g.semester = ?";
            $lockedParams[] = $semester;
        }
        $lockedStmt = $db->prepare($lockedSql);
        $lockedStmt->execute($lockedParams);
        if ((int)$lockedStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'These grades were already verified by admin. Ask admin to return them as rejected before recalling or resubmitting.']);
            return;
        }

        $deleteSql = "DELETE ga
                      FROM grade_approvals ga
                      JOIN grades g ON g.id = ga.grade_id
                      JOIN class_subjects cs ON cs.id = g.class_subject_id
                      WHERE cs.class_id = ?
                      AND cs.teacher_id = ?
                      AND g.{$tc} = ?
                      AND g.academic_year = ?
                      AND ga.status IN ('pending','submitted','rejected')";
        $deleteParams = [$classId, $teacherId, $term, $academicYear];
        if ($gs === '4_quarter') {
            $deleteSql .= " AND g.semester = ?";
            $deleteParams[] = $semester;
        }
        $deleteStmt = $db->prepare($deleteSql);
        $deleteStmt->execute($deleteParams);

        if ($deleteStmt->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'No teacher grade submission is available to recall.']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Grade submission recalled. You can edit and submit again.']);
    } catch (PDOException $e) {
        error_log("recallGrades error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function uploadMaterial($db, $teacherId) {
    $targetPath = null;
    $materialSaved = false;
    try {
        $title = trim((string)($_POST['title'] ?? ''));
        $classId = (int)($_POST['class_id'] ?? 0);

        if ($title === '' || $classId <= 0 || !isset($_FILES['material_file'])) {
            echo json_encode(['success' => false, 'message' => 'Title, class, and file are required']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        $classSubjectId = getTeacherClassSubjectId($db, $teacherId, $classId);
        if ($classSubjectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class subject assignment not found']);
            return;
        }

        $file = $_FILES['material_file'];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit',
                UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again',
                UPLOAD_ERR_NO_FILE => 'Please select a file to upload',
                default => 'File upload failed before it reached storage',
            };
            echo json_encode(['success' => false, 'message' => $message]);
            return;
        }

        $maxSizeBytes = 10 * 1024 * 1024; // 10 MB
        if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSizeBytes) {
            echo json_encode(['success' => false, 'message' => 'File size must be between 1 byte and 10 MB']);
            return;
        }

        $originalName = (string)($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];
        if (!in_array($extension, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Unsupported file type']);
            return;
        }

        $allowedMimeByExt = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'txt' => ['text/plain'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png']
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($detectedMime === '' || !in_array($detectedMime, $allowedMimeByExt[$extension] ?? [], true)) {
            echo json_encode(['success' => false, 'message' => 'File content does not match allowed type']);
            return;
        }

        $storedName = MaterialStorage::createStoredName($extension);
        $targetPath = MaterialStorage::pathFor($storedName, true);

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => false, 'message' => 'Unable to move uploaded file']);
            return;
        }

        $stmt = $db->prepare("INSERT INTO materials (title, file_name, file_type, file_size, class_subject_id, uploaded_by, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$title, $storedName, $extension, (int)$file['size'], $classSubjectId, $teacherId]);
        $materialId = (int)$db->lastInsertId();
        $materialSaved = true;

        notifyMaterialUploadRecipients($db, $teacherId, $classId, $materialId, $title);

        echo json_encode(['success' => true, 'message' => 'Material uploaded successfully']);
    } catch (PDOException $e) {
        if (!$materialSaved && is_string($targetPath) && is_file($targetPath)) { @unlink($targetPath); }
        error_log('Material upload database error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    } catch (Throwable $e) {
        if (!$materialSaved && is_string($targetPath) && is_file($targetPath)) { @unlink($targetPath); }
        error_log('Material upload failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Material storage is unavailable. Please contact the administrator.']);
    }
}

function downloadMaterial($db, $teacherId) {
    try {
        $materialId = (int)($_GET['id'] ?? 0);
        if ($materialId <= 0) {
            http_response_code(400);
            echo 'Invalid material ID';
            return;
        }

        $stmt = $db->prepare("SELECT id, title, file_name, file_type
                              FROM materials
                              WHERE id = ? AND uploaded_by = ?");
        $stmt->execute([$materialId, $teacherId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$material) {
            http_response_code(404);
            echo 'Material not found';
            return;
        }

        $filePath = MaterialStorage::locate((string)$material['file_name']);
        if ($filePath === null) {
            http_response_code(404);
            echo 'File not found';
            return;
        }
        MaterialStorage::outputDownload($filePath, (string)$material['title'], (string)$material['file_type']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database error';
    } catch (Throwable $e) {
        error_log('Teacher material download failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Material download failed';
    }
}

function getMaterial($db, $teacherId) {
    try {
        $materialId = (int)($_GET['id'] ?? 0);
        if ($materialId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid material ID']);
            return;
        }

        $stmt = $db->prepare("SELECT m.id, m.title, cs.class_id
                              FROM materials m
                              LEFT JOIN class_subjects cs ON m.class_subject_id = cs.id
                              WHERE m.id = ? AND m.uploaded_by = ?");
        $stmt->execute([$materialId, $teacherId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$material) {
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            return;
        }

        echo json_encode(['success' => true, 'material' => $material]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function updateMaterial($db, $teacherId) {
    try {
        $materialId = (int)($_POST['material_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $classId = (int)($_POST['class_id'] ?? 0);

        if ($materialId <= 0 || $title === '' || $classId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Material, title, and class are required']);
            return;
        }

        $existsStmt = $db->prepare("SELECT id, file_name FROM materials WHERE id = ? AND uploaded_by = ?");
        $existsStmt->execute([$materialId, $teacherId]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        $classSubjectId = getTeacherClassSubjectId($db, $teacherId, $classId);
        if ($classSubjectId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class subject assignment not found']);
            return;
        }

        $stmt = $db->prepare("UPDATE materials
                              SET title = ?, class_subject_id = ?
                              WHERE id = ? AND uploaded_by = ?");
        $stmt->execute([$title, $classSubjectId, $materialId, $teacherId]);

        echo json_encode(['success' => true, 'message' => 'Material updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteMaterial($db, $teacherId) {
    try {
        $materialId = (int)($_GET['id'] ?? 0);
        if ($materialId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid material ID']);
            return;
        }

        $stmt = $db->prepare("SELECT file_name FROM materials WHERE id = ? AND uploaded_by = ?");
        $stmt->execute([$materialId, $teacherId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$material) {
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            return;
        }

        $deleteStmt = $db->prepare("DELETE FROM materials WHERE id = ? AND uploaded_by = ?");
        $deleteStmt->execute([$materialId, $teacherId]);

        if (!MaterialStorage::delete((string)$material['file_name'])) {
            error_log('Material file could not be deleted: ' . (string)$material['file_name']);
        }

        echo json_encode(['success' => true, 'message' => 'Material deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    } catch (Throwable $e) {
        error_log('Material deletion failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Material file cleanup failed.']);
    }
}

function exportGrades($db, $teacherId) {
    try {
        $classId = (int)($_GET['class_id'] ?? 0);
        $semester = trim((string)($_GET['semester'] ?? ''));
        $term = normalizeTerm($_GET['quarter'] ?? $_GET['term'] ?? 'Term1');
        $academicYear = trim((string)($_GET['academic_year'] ?? ''));
        $sex = strtolower(trim((string)($_GET['sex'] ?? '')));
        if (!in_array($sex, ['male', 'female'], true)) {
            $sex = '';
        }

        if ($classId <= 0 || $academicYear === '') {
            http_response_code(400);
            echo 'Missing export parameters';
            return;
        }

        $gs = gradingSystemFromYear($academicYear);
        $isFourQuarter = ($gs === '4_quarter');
        if ($isFourQuarter && $semester === '') {
            http_response_code(400);
            echo 'Semester is required for 4-quarter system';
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            http_response_code(403);
            echo 'Unauthorized class access';
            return;
        }

        $classSubjectId = getTeacherClassSubjectId($db, $teacherId, $classId);
        if ($classSubjectId <= 0) {
            http_response_code(400);
            echo 'Class subject assignment not found';
            return;
        }
        $tc = getTermColumnName($db);

        $hasRaw = gradesHasRawComponentColumns($db);
        $rawSelect = $hasRaw
            ? "g.ww_raw_score, g.ww_total_score, g.pt_raw_score, g.pt_total_score, g.assessment_raw_score, g.assessment_total_score,"
            : "NULL AS ww_raw_score, NULL AS ww_total_score, NULL AS pt_raw_score, NULL AS pt_total_score, NULL AS assessment_raw_score, NULL AS assessment_total_score,";

        $semJoin = $isFourQuarter ? "AND g.semester = ?" : "AND g.semester IS NULL";

        $sgSubquery = $isFourQuarter
            ? "SELECT student_id, class_subject_id, semester, academic_year,
                      ROUND(AVG(final_grade), 2) AS semester_final_grade
               FROM grades
               WHERE {$tc} IN ('Q1', 'Q2')
               GROUP BY student_id, class_subject_id, semester, academic_year"
            : "SELECT student_id, class_subject_id, NULL AS semester, academic_year,
                      final_grade AS semester_final_grade
               FROM grades
               WHERE {$tc} = ?
                 AND semester IS NULL
               GROUP BY student_id, class_subject_id, academic_year";

        $query = "SELECT u.reference_code, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                  $rawSelect
                  g.quiz_score AS ww_score,
                  g.activity_score AS pt_score,
                  g.exam_score AS assessment_score,
                  g.final_grade AS quarter_final_grade,
                  sg.semester_final_grade
                  FROM users u
                  " . tEnrollActiveUsersJoinSql() . "
                  LEFT JOIN grades g ON g.student_id = u.id
                                   AND g.class_subject_id = ?
                                   {$semJoin}
                                   AND g.{$tc} = ?
                                   AND g.academic_year = ?
                  LEFT JOIN ({$sgSubquery}) sg ON sg.student_id = u.id
                      AND sg.class_subject_id = ?
                      " . ($isFourQuarter ? "AND sg.semester = ?" : "AND sg.semester IS NULL") . "
                      AND sg.academic_year = ?
                  WHERE (? = '' OR LOWER(COALESCE(u.sex, '')) = ?)
                  ORDER BY u.last_name, u.first_name";
        $params = [$classId, $classId];
        if ($isFourQuarter) $params[] = $semester;
        $params[] = $classSubjectId;
        $params[] = $term;
        $params[] = $academicYear;
        if (!$isFourQuarter) $params[] = $term;
        $params[] = $classSubjectId;
        if ($isFourQuarter) $params[] = $semester;
        $params[] = $academicYear;
        $params[] = $sex;
        $params[] = $sex;

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $classStmt = $db->prepare("SELECT class_name FROM classes WHERE id = ?");
        $classStmt->execute([$classId]);
        $className = (string)($classStmt->fetchColumn() ?: 'class');
        $safeClass = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', strtolower($className));
        $filename = 'grades_' . $safeClass . '_' . ($isFourQuarter ? strtolower($semester) . '_' : '') . strtolower($term) . '_' . str_replace('-', '_', $academicYear) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        $csvHeader = $isFourQuarter
            ? ['Reference Code', 'Student', 'WW Score', 'WW Total', 'WW %', 'PT Score', 'PT Total', 'PT %', 'Assessment Score', 'Assessment Total', 'Assessment %', 'Quarter Grade', 'Semester Grade']
            : ['Reference Code', 'Student', 'WW Score', 'WW Total', 'WW %', 'PT Score', 'PT Total', 'PT %', 'Assessment Score', 'Assessment Total', 'Assessment %', 'Term Grade', 'Final Grade'];
        fputcsv($out, $csvHeader);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['reference_code'],
                $row['student_name'],
                $row['ww_raw_score'],
                $row['ww_total_score'],
                $row['ww_score'],
                $row['pt_raw_score'],
                $row['pt_total_score'],
                $row['pt_score'],
                $row['assessment_raw_score'],
                $row['assessment_total_score'],
                $row['assessment_score'],
                $row['quarter_final_grade'],
                $row['semester_final_grade']
            ]);
        }
        fclose($out);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database error';
    }
}

function createGradeItem($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
            return;
        }
        $classId = (int)($_POST['class_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $component = strtoupper(trim((string)($_POST['component'] ?? '')));
        $totalScore = (float)($_POST['total_score'] ?? 0);
        $activityDate = normalizeDate($_POST['activity_date'] ?? date('Y-m-d'));

        if ($classId <= 0 || $title === '' || !in_array($component, ['WW', 'PT', 'ASSESSMENT'], true) || $totalScore <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class, title, component, and total score are required']);
            return;
        }
        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }
        $lockMessage = gradeActivityPeriodLockMessage($db, $teacherId, $classId, $activityDate);
        if ($lockMessage !== null) {
            echo json_encode(['success' => false, 'message' => $lockMessage]);
            return;
        }

        $stmt = $db->prepare("INSERT INTO grade_items (class_id, teacher_id, title, component, total_score, activity_date, status, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
        $stmt->execute([$classId, $teacherId, $title, $component, round($totalScore, 2), $activityDate]);
        $gradeItemId = (int)$db->lastInsertId();
        appNotifyGradeActivityCreated($db, $classId, $gradeItemId, $title, $component, round($totalScore, 2));

        echo json_encode(['success' => true, 'message' => 'Grade activity created successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function fetchGradeItems($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => true, 'items' => []]);
            return;
        }
        $classId = (int)($_GET['class_id'] ?? 0);
        $status = strtolower(trim((string)($_GET['status'] ?? 'active')));
        if (!in_array($status, ['active', 'finished'], true)) {
            $status = 'active';
        }

        $where = ["gi.teacher_id = ?", "gi.status = ?"];
        $params = [$teacherId, $status];
        if ($classId > 0) {
            $where[] = "gi.class_id = ?";
            $params[] = $classId;
        }
        $query = "SELECT gi.id, gi.class_id, gi.title, gi.component, gi.total_score, gi.activity_date, gi.status, gi.finished_at, gi.created_at,
                  c.class_name, c.grade_level, c.section,
                  COUNT(gis.id) AS score_count
                  FROM grade_items gi
                  JOIN classes c ON c.id = gi.class_id
                  LEFT JOIN grade_item_scores gis ON gis.grade_item_id = gi.id
                  WHERE " . implode(' AND ', $where) . "
                  GROUP BY gi.id
                  ORDER BY gi.activity_date DESC, gi.created_at DESC
                  LIMIT 100";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function fetchGradeItemStudents($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
            return;
        }
        $gradeItemId = (int)($_GET['grade_item_id'] ?? 0);
        if ($gradeItemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid grade item']);
            return;
        }
        $item = fetchGradeItemRecord($db, $teacherId, $gradeItemId);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }

        $verificationLookup = fetchGradeItemVerificationLookup($db, $gradeItemId);
        $studentsStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name,
                                      gis.score
                                      FROM users u
                                      " . tEnrollActiveUsersJoinSql() . "
                                      LEFT JOIN grade_item_scores gis ON gis.student_id = u.id AND gis.grade_item_id = ?
                                      WHERE 1=1
                                      ORDER BY u.last_name, u.first_name");
        $studentsStmt->execute([(int)$item['class_id'], (int)$item['class_id'], $gradeItemId]);
        $students = [];
        foreach ($studentsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $studentId = (int)$row['id'];
            $verification = $verificationLookup[$studentId] ?? null;
            $row['is_verified'] = $verification !== null;
            $row['verified_at'] = $verification['verified_at'] ?? null;
            $row['verification_method'] = $verification['verification_method'] ?? null;
            $row['verifier_name'] = $verification['verifier_name'] ?? null;
            $students[] = $row;
        }
        echo json_encode(['success' => true, 'item' => $item, 'students' => $students]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function saveGradeItemScores($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
            return;
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid payload']);
            return;
        }
        $gradeItemId = (int)($payload['grade_item_id'] ?? 0);
        $scores = $payload['scores'] ?? [];
        if ($gradeItemId <= 0 || !is_array($scores)) {
            echo json_encode(['success' => false, 'message' => 'Grade activity and scores are required']);
            return;
        }

        $item = fetchGradeItemRecord($db, $teacherId, $gradeItemId);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }
        if (($item['status'] ?? '') === 'finished') {
            echo json_encode(['success' => false, 'message' => 'Grade activity is already finished']);
            return;
        }
        $lockMessage = gradeActivityPeriodLockMessage($db, $teacherId, (int)$item['class_id'], $item['activity_date'] ?? date('Y-m-d'));
        if ($lockMessage !== null) {
            echo json_encode(['success' => false, 'message' => $lockMessage]);
            return;
        }
        $total = (float)$item['total_score'];

        $enrolled = array_fill_keys(getActiveEnrollmentStudentIds($db, (int)$item['class_id']), true);

        $db->beginTransaction();
        $upsert = $db->prepare("INSERT INTO grade_item_scores (grade_item_id, student_id, score, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()");

        $notifiedScores = [];
        foreach ($scores as $row) {
            $studentId = (int)($row['student_id'] ?? 0);
            if ($studentId <= 0 || !isset($enrolled[$studentId])) {
                continue;
            }
            $scoreRaw = $row['score'] ?? null;
            if ($scoreRaw === '' || $scoreRaw === null) {
                continue;
            }
            if (!is_numeric($scoreRaw)) {
                continue;
            }
            $score = round((float)$scoreRaw, 2);
            if ($score < 0 || $score > $total) {
                if ($db->inTransaction()) $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Score must be between 0 and total score']);
                return;
            }
            $upsert->execute([$gradeItemId, $studentId, $score]);
            $notifiedScores[] = ['student_id' => $studentId, 'score' => $score];
        }
        $db->commit();
        notifyGradeItemScoreParents($db, $teacherId, $item, $notifiedScores);
        echo json_encode(['success' => true, 'message' => 'Scores saved successfully']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function finishGradeItem($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
            return;
        }
        $gradeItemId = (int)($_POST['grade_item_id'] ?? ($_GET['grade_item_id'] ?? 0));
        if ($gradeItemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid grade activity']);
            return;
        }
        $itemStmt = $db->prepare("SELECT id, class_id, status, activity_date
                                  FROM grade_items
                                  WHERE id = ? AND teacher_id = ?
                                  LIMIT 1");
        $itemStmt->execute([$gradeItemId, $teacherId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }
        if (($item['status'] ?? '') !== 'active') {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found or already finished']);
            return;
        }
        $lockMessage = gradeActivityPeriodLockMessage($db, $teacherId, (int)$item['class_id'], $item['activity_date'] ?? date('Y-m-d'));
        if ($lockMessage !== null) {
            echo json_encode(['success' => false, 'message' => $lockMessage]);
            return;
        }

        $classId = (int)($item['class_id'] ?? 0);
        $classLabel = teacherGetClassSubtitle($db, $classId);
        $enrolledIds = getActiveEnrollmentStudentIds($db, $classId);
        $enrolledCount = count($enrolledIds);

        $scoreCountStmt = $db->prepare("SELECT COUNT(DISTINCT student_id)
                                        FROM grade_item_scores
                                        WHERE grade_item_id = ?");
        $scoreCountStmt->execute([$gradeItemId]);
        $scoredCount = (int)$scoreCountStmt->fetchColumn();

        $stmt = $db->prepare("UPDATE grade_items
                              SET status = 'finished', finished_at = NOW()
                              WHERE id = ? AND teacher_id = ? AND status = 'active'");
        $stmt->execute([$gradeItemId, $teacherId]);
        if ($stmt->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found or already finished']);
            return;
        }

        $recipients = pushActiveClassRecipientIds($db, $classId);
        if (!empty($recipients)) {
            $titleText = 'Grades posted: ' . $scoredCount . ' score(s) recorded';
            $bodyText = $classLabel . ' - ' . date('M d, Y') . ' - Tap to view scores';
            appDispatchNotification(
                $db,
                $recipients,
                'grade_item_finished_' . $gradeItemId,
                $titleText,
                $bodyText,
                'bi-clipboard-check',
                'success',
                ['student' => 'Student_Classes.php', 'parent' => 'Parent_Progress.php'],
                ['type' => 'grade_activity_finished', 'class_id' => $classId, 'grade_item_id' => $gradeItemId]
            );
        }

        echo json_encode(['success' => true, 'message' => 'Grade activity finished and moved to archive']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteGradeItem($db, $teacherId) {
    try {
        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
            return;
        }
        $gradeItemId = (int)($_POST['grade_item_id'] ?? ($_GET['grade_item_id'] ?? 0));
        if ($gradeItemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid grade activity']);
            return;
        }

        $item = fetchGradeItemRecord($db, $teacherId, $gradeItemId);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }
        $lockMessage = gradeActivityPeriodLockMessage($db, $teacherId, (int)$item['class_id'], $item['activity_date'] ?? date('Y-m-d'));
        if ($lockMessage !== null) {
            echo json_encode(['success' => false, 'message' => $lockMessage]);
            return;
        }

        $stmt = $db->prepare("DELETE FROM grade_items WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$gradeItemId, $teacherId]);
        if ($stmt->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }
        echo json_encode(['success' => true, 'message' => 'Grade activity deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function restoreGradeItem($db, $teacherId) {
    if (!(bool)$db->query("SHOW TABLES LIKE 'grade_items'")->fetch(PDO::FETCH_NUM)) {
        echo json_encode(['success' => false, 'message' => 'Database update required: add grade_items tables first']);
        return;
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    $itemId = (int)($_POST['id'] ?? ($payload['id'] ?? $_GET['id'] ?? 0));
    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid grade activity ID']);
        return;
    }

    try {
        $item = fetchGradeItemRecord($db, $teacherId, $itemId);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found']);
            return;
        }
        $lockMessage = gradeActivityPeriodLockMessage($db, $teacherId, (int)$item['class_id'], $item['activity_date'] ?? date('Y-m-d'));
        if ($lockMessage !== null) {
            echo json_encode(['success' => false, 'message' => $lockMessage]);
            return;
        }

        $stmt = $db->prepare("UPDATE grade_items
                              SET status = 'active', finished_at = NULL
                              WHERE id = ? AND teacher_id = ? AND status = 'finished'");
        $stmt->execute([$itemId, $teacherId]);
        if ($stmt->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'Grade activity not found or already active']);
            return;
        }
        echo json_encode(['success' => true, 'message' => 'Grade activity restored. You can edit it in Grade Entry.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function submitReportCard($db, $teacherId) {
    try {
        $academicYear = trim((string)($_POST['academic_year'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? ''));
        $gs = gradingSystemFromYear($academicYear);
        if ($gs === '3_term') {
            $semester = null;
        }

        if ($academicYear === '') {
            echo json_encode(['success' => false, 'message' => 'Academic year is required']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            echo json_encode(['success' => false, 'message' => 'Academic year must be in YYYY-YYYY format']);
            return;
        }
        if ($semester !== null && !in_array($semester, ['S1', 'S2'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid semester']);
            return;
        }

        $students = fetchTeacherAdvisoryStudents($db, $teacherId);
        if (empty($students)) {
            echo json_encode(['success' => false, 'message' => 'No students found in your advisory section']);
            return;
        }

        $hasGradeApprovals = (bool)$db->query("SHOW TABLES LIKE 'grade_approvals'")->fetch(PDO::FETCH_NUM);
        if (!$hasGradeApprovals) {
            echo json_encode(['success' => false, 'message' => 'Grade approvals table is required before submitting report cards']);
            return;
        }

        $tc = getTermColumnName($db);
        $semesterCondition = $semester !== null ? "AND g.semester = ?" : "";
        $pendingCheck = $db->prepare("SELECT COUNT(*)
                                      FROM grades g
                                      JOIN class_subjects cs ON cs.id = g.class_subject_id
                                      JOIN classes c ON c.id = cs.class_id
                                      LEFT JOIN grade_approvals ga ON ga.grade_id = g.id
                                      WHERE g.student_id = ?
                                      AND g.academic_year = ?
                                      {$semesterCondition}
                                      AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                      AND (ga.status IS NULL OR ga.status != 'admin_verified')");
        $gradeExistStmt = $db->prepare("SELECT COUNT(*) FROM grades WHERE student_id = ? AND academic_year = ?" . ($semester !== null ? " AND semester = ?" : ""));
        $pendingParams = [$academicYear];
        $existParams = [$academicYear];

        $studentsNotReady = [];
        foreach ($students as $student) {
            $studentId = (int)($student['id'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }
            if ($semester !== null) {
                $gradeExistStmt->execute([$studentId, $academicYear, $semester]);
                $pendingCheck->execute([$studentId, $academicYear, $semester]);
            } else {
                $gradeExistStmt->execute([$studentId, $academicYear]);
                $pendingCheck->execute([$studentId, $academicYear]);
            }
            $hasGrades = (int)$gradeExistStmt->fetchColumn() > 0;
            $hasPending = (int)$pendingCheck->fetchColumn() > 0;

            if (!$hasGrades || $hasPending) {
                $studentsNotReady[] = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? ''));
            }
        }
        if (!empty($studentsNotReady)) {
            $preview = implode(', ', array_slice($studentsNotReady, 0, 5));
            $extra = count($studentsNotReady) > 5 ? ' +' . (count($studentsNotReady) - 5) . ' more' : '';
            echo json_encode([
                'success' => false,
                'message' => 'Cannot submit all report cards yet. Some students have missing grades or pending subject approvals: ' . $preview . $extra
            ]);
            return;
        }

        $approvedCheck = $db->prepare("SELECT COUNT(*)
                                       FROM report_card_approvals
                                       WHERE student_id = ?
                                       AND academic_year = ?
                                       " . ($semester !== null ? "AND semester = ?" : "AND semester IS NULL") . "
                                       AND status = 'approved'");
        $deleteExisting = $db->prepare("DELETE FROM report_card_approvals
                                        WHERE student_id = ?
                                        AND academic_year = ?
                                        AND status NOT IN ('approved')
                                        " . ($semester !== null ? "AND semester = ?" : "AND semester IS NULL"));
        $upsert = $db->prepare("INSERT INTO report_card_approvals
                                (student_id, academic_year, semester, advisory_teacher_id, status, submitted_at, reviewed_by, reviewed_at, remarks)
                                VALUES (?, ?, ?, ?, 'submitted_admin', NOW(), NULL, NULL, NULL)
                                ON DUPLICATE KEY UPDATE
                                  advisory_teacher_id = VALUES(advisory_teacher_id),
                                  status = 'submitted_admin',
                                  submitted_at = NOW(),
                                  reviewed_by = NULL,
                                  reviewed_at = NULL,
                                  remarks = NULL");
        $db->beginTransaction();
        $submittedCount = 0;
        foreach ($students as $student) {
            $studentId = (int)($student['id'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }
            if ($semester !== null) {
                $approvedCheck->execute([$studentId, $academicYear, $semester]);
                $hasApproved = (int)$approvedCheck->fetchColumn() > 0;
                $deleteExisting->execute([$studentId, $academicYear, $semester]);
            } else {
                $approvedCheck->execute([$studentId, $academicYear]);
                $hasApproved = (int)$approvedCheck->fetchColumn() > 0;
                $deleteExisting->execute([$studentId, $academicYear]);
            }
            if ($hasApproved) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Recall approved report cards before resubmitting.']);
                return;
            }
            $upsert->execute([$studentId, $academicYear, $semester, $teacherId]);
            $submittedCount++;
        }
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Submitted report cards for ' . $submittedCount . ' student(s) to admin for approval']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("submitReportCard error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function recallReportCard($db, $teacherId) {
    try {
        $academicYear = trim((string)($_POST['academic_year'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? ''));
        $gs = gradingSystemFromYear($academicYear);
        if ($gs === '3_term') {
            $semester = null;
        }

        if ($academicYear === '' || !preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
            echo json_encode(['success' => false, 'message' => 'Valid academic year is required']);
            return;
        }
        if ($semester !== null && !in_array($semester, ['S1', 'S2'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid semester']);
            return;
        }

        $students = fetchTeacherAdvisoryStudents($db, $teacherId);
        if (empty($students)) {
            echo json_encode(['success' => false, 'message' => 'No students found in your advisory section']);
            return;
        }

        $deleteSql = "DELETE FROM report_card_approvals
                      WHERE student_id = ?
                      AND academic_year = ?
                      AND advisory_teacher_id = ?
                      AND status IN ('submitted_admin','rejected') "
                      . ($semester !== null ? "AND semester = ?" : "AND semester IS NULL");
        $deleteStmt = $db->prepare($deleteSql);

        $db->beginTransaction();
        $deletedCount = 0;
        foreach ($students as $student) {
            $studentId = (int)($student['id'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }
            $params = [$studentId, $academicYear, $teacherId];
            if ($semester !== null) {
                $params[] = $semester;
            }
            $deleteStmt->execute($params);
            $deletedCount += $deleteStmt->rowCount();
        }
        $db->commit();

        if ($deletedCount <= 0) {
            echo json_encode(['success' => false, 'message' => 'No adviser report card submission is available to recall, or it was already finally approved by admin.']);
            return;
        }
        echo json_encode(['success' => true, 'message' => 'Recalled ' . $deletedCount . ' report card submission(s).']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("recallReportCard error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function createClassAnnouncement($db, $teacherId) {
    try {
        $classId = (int)($_POST['class_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));

        if ($classId <= 0 || $title === '' || $content === '') {
            echo json_encode(['success' => false, 'message' => 'Class, title, and content are required']);
            return;
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        $stmt = $db->prepare("INSERT INTO class_announcements (class_id, posted_by, title, content, status, created_at, updated_at)
                              VALUES (?, ?, ?, ?, 'active', NOW(), NOW())");
        $stmt->execute([$classId, $teacherId, $title, $content]);
        $announcementId = (int)$db->lastInsertId();

        try {
            $classLabel = teacherGetClassSubtitle($db, $classId);
            $recipients = pushActiveClassRecipientIds($db, $classId);
            if (!empty($recipients)) {
                $titleText = 'New class announcement: ' . $title;
                $bodyText = $classLabel . ' - Tap to view details';
                appDispatchNotification(
                    $db,
                    $recipients,
                    'class_announcement_' . $announcementId,
                    $titleText,
                    $bodyText,
                    'bi-journal-text',
                    'success',
                    ['student' => 'Student_Announcements.php', 'parent' => 'Parent_Announcements.php'],
                    ['type' => 'class_announcement', 'announcement_id' => $announcementId, 'class_id' => $classId]
                );
            }
        } catch (Throwable $notificationError) {
            error_log('Class announcement saved, but push delivery failed: ' . $notificationError->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Class announcement posted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function fetchClassAnnouncements($db, $teacherId) {
    try {
        $classId = (int)($_GET['class_id'] ?? 0);

        $where = ["ca.posted_by = ?", "ca.status = 'active'"];
        $params = [$teacherId];

        if ($classId > 0) {
            if (!teacherOwnsClass($db, $teacherId, $classId)) {
                echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
                return;
            }
            $where[] = "ca.class_id = ?";
            $params[] = $classId;
        }

        $query = "SELECT ca.id, ca.class_id, ca.title, ca.content, ca.created_at,
                  c.class_name, c.grade_level, c.section
                  FROM class_announcements ca
                  JOIN classes c ON c.id = ca.class_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY ca.created_at DESC
                  LIMIT 50";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'announcements' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteClassAnnouncement($db, $teacherId) {
    try {
        $announcementId = (int)($_GET['id'] ?? 0);
        if ($announcementId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid announcement ID']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM class_announcements WHERE id = ? AND posted_by = ?");
        $stmt->execute([$announcementId, $teacherId]);

        if ($stmt->rowCount() <= 0) {
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            return;
        }

        echo json_encode(['success' => true, 'message' => 'Class announcement deleted successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function teacherOfflineBootstrap($db, $teacherId) {
    try {
        $tStmt = $db->prepare("SELECT id, first_name, last_name, email, reference_code, profile_picture FROM users WHERE id = ? LIMIT 1");
        $tStmt->execute([$teacherId]);
        $teacher = $tStmt->fetch(PDO::FETCH_ASSOC);

        $cStmt = $db->prepare("SELECT DISTINCT c.id, c.class_name AS name, s.subject_name AS subject_title, c.grade_level, c.section, c.schedule
                               FROM class_subjects cs
                               JOIN classes c ON c.id = cs.class_id
                               LEFT JOIN subjects s ON s.id = cs.subject_id
                               WHERE cs.teacher_id = ? AND c.status = 'active'
                               ORDER BY c.grade_level, c.section, c.class_name");
        $cStmt->execute([$teacherId]);
        $classes = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $rosters = [];
        $today = date('Y-m-d');
        foreach ($classes as $class) {
            $cId = (int)$class['id'];
            $students = tEnrollFetchStudentsWithAttendance($db, $cId, $today);
            $rosters[$cId] = array_map(function($s) {
                return [
                    'id' => (int)$s['id'],
                    'first_name' => $s['first_name'],
                    'last_name' => $s['last_name'],
                    'reference_code' => $s['reference_code'] ?? '',
                    'lrn' => $s['lrn'] ?? '',
                    'gender' => $s['gender'] ?? '',
                    'attendance_status' => $s['attendance_status'] ?? 'present',
                    'remarks' => $s['remarks'] ?? ''
                ];
            }, $students);
        }

        echo json_encode([
            'success' => true,
            'teacher' => $teacher,
            'classes' => $classes,
            'rosters' => $rosters
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error during offline bootstrap']);
    }
}

function teacherSaveOfflineActivity($db, $teacherId) {
    try {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
            return;
        }

        $classId = (int)($payload['class_id'] ?? 0);
        $title = trim((string)($payload['title'] ?? ''));
        $component = strtolower(trim((string)($payload['component'] ?? 'ww')));
        $totalScore = (float)($payload['total_score'] ?? 0);
        $activityDate = trim((string)($payload['activity_date'] ?? date('Y-m-d')));
        $scores = $payload['scores'] ?? [];

        if ($classId <= 0 || $title === '' || $totalScore <= 0) {
            echo json_encode(['success' => false, 'message' => 'Class, title, and total score are required']);
            return;
        }

        if (!in_array($component, ['ww', 'pt', 'qa'], true)) {
            $component = 'ww';
        }

        if (!teacherOwnsClass($db, $teacherId, $classId)) {
            echo json_encode(['success' => false, 'message' => 'You are not assigned to this class']);
            return;
        }

        if (!gradeItemsTablesExist($db)) {
            echo json_encode(['success' => false, 'message' => 'Grade items table not found']);
            return;
        }

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO grade_items (class_id, teacher_id, title, component, total_score, activity_date, status, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
        $stmt->execute([$classId, $teacherId, $title, $component, round($totalScore, 2), $activityDate]);
        $gradeItemId = (int)$db->lastInsertId();

        if ($gradeItemId > 0 && is_array($scores) && !empty($scores)) {
            $enrolled = array_fill_keys(getActiveEnrollmentStudentIds($db, $classId), true);
            $upsert = $db->prepare("INSERT INTO grade_item_scores (grade_item_id, student_id, score, created_at, updated_at)
                                    VALUES (?, ?, ?, NOW(), NOW())
                                    ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()");
            foreach ($scores as $row) {
                $studentId = (int)($row['student_id'] ?? 0);
                if ($studentId <= 0 || !isset($enrolled[$studentId])) continue;
                $scoreRaw = $row['score'] ?? null;
                if ($scoreRaw === '' || $scoreRaw === null || !is_numeric($scoreRaw)) continue;
                $score = round((float)$scoreRaw, 2);
                if ($score < 0 || $score > $totalScore) continue;
                $upsert->execute([$gradeItemId, $studentId, $score]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Offline activity and scores saved successfully', 'grade_item_id' => $gradeItemId]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

?>
