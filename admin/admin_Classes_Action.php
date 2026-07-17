<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin Classes Action Handler
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}


$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_GET['action'] ?? '';

function ensureAdminClassAuditLogTable($db) {
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            action_name VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT DEFAULT NULL,
            details_json TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_audit_logs_admin_user_id (admin_user_id),
            INDEX idx_admin_audit_logs_action_name (action_name),
            INDEX idx_admin_audit_logs_target_type (target_type),
            INDEX idx_admin_audit_logs_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // Do not block the main action if audit log table creation fails.
    }
    $ready = true;
}

function adminClassAuditLog($db, string $actionName, int $targetId = 0, array $details = []): void {
    $adminUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($adminUserId <= 0) {
        return;
    }

    ensureAdminClassAuditLogTable($db);

    try {
        $stmt = $db->prepare("INSERT INTO admin_audit_logs (admin_user_id, action_name, target_type, target_id, details_json, created_at)
                              VALUES (?, ?, 'class', ?, ?, NOW())");
        $stmt->execute([
            $adminUserId,
            $actionName,
            $targetId > 0 ? $targetId : null,
            !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Admin class audit log failed: ' . $e->getMessage());
    }
}

if (in_array($action, ['create', 'update', 'delete', 'generate_core_classes'], true)) {
    requireCsrfToken();
}

if ($action === 'export_sf2') {
    exportSf2($db);
}

switch ($action) {
    case 'create':
        createClass($db);
        break;
    case 'update':
        updateClass($db);
        break;
    case 'delete':
        deleteClass($db);
        break;
    case 'generate_core_classes':
        generateCoreClasses($db);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function ensureClassTrackColumn($db) {
    try {
        $exists = dbHasColumn($db, 'classes', 'subject_category');
        if (!$exists) {
            $db->exec("ALTER TABLE classes ADD COLUMN subject_category VARCHAR(50) DEFAULT 'core' COMMENT 'core|academic_elective|techpro_elective|work_immersion|field_experience_elective'");
        }
        $exists = dbHasColumn($db, 'classes', 'track');
        if (!$exists) {
            $db->exec("ALTER TABLE classes ADD COLUMN track VARCHAR(50) DEFAULT 'academic' COMMENT 'academic|techpro'");
        }
        ensureStrengthenedShsColumns($db);
    } catch (PDOException $e) {
        // Ignore if schema change is not permitted.
    }
}

function normalizeScheduleRows($rows, &$error = '') {
    if (!is_array($rows)) {
        $error = 'Invalid schedule rows.';
        return [];
    }
    $segments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $day = ScheduleParser::normalizeDay($row['day'] ?? '');
        $startHour = $row['start_hour'] ?? '';
        $startMin = $row['start_min'] ?? '00';
        $startAmPm = $row['start_ampm'] ?? 'AM';
        $endHour = $row['end_hour'] ?? '';
        $endMin = $row['end_min'] ?? '00';
        $endAmPm = $row['end_ampm'] ?? 'AM';

        if ($day === '' || $startHour === '' || $endHour === '') {
            $error = 'Each schedule row must include day, start time, and end time.';
            return [];
        }

        $startMinVal = ScheduleParser::fromParts((int)$startHour, (int)$startMin, $startAmPm);
        $endMinVal = ScheduleParser::fromParts((int)$endHour, (int)$endMin, $endAmPm);
        if ($startMinVal === null || $endMinVal === null || $endMinVal <= $startMinVal) {
            $error = 'Invalid schedule time range.';
            return [];
        }

        $segments[] = [
            'day' => $day,
            'start' => $startMinVal,
            'end' => $endMinVal,
            'start_time' => ScheduleParser::to24hString($startMinVal),
            'end_time' => ScheduleParser::to24hString($endMinVal),
            'start_label' => ScheduleParser::toTimeLabel($startMinVal),
            'end_label' => ScheduleParser::toTimeLabel($endMinVal),
        ];
    }
    return $segments;
}

function parseScheduleSegmentsFromString($scheduleText) {
    return ScheduleParser::parseSegments($scheduleText);
}

function scheduleSummaryFromSegments($segments) {
    if (empty($segments)) {
        return '';
    }
    $order = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
    usort($segments, function ($a, $b) use ($order) {
        $dayA = $order[$a['day']] ?? 99;
        $dayB = $order[$b['day']] ?? 99;
        if ($dayA === $dayB) {
            return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
        }
        return $dayA <=> $dayB;
    });
    $parts = [];
    foreach ($segments as $seg) {
        if (empty($seg['day']) || empty($seg['start_label']) || empty($seg['end_label'])) {
            continue;
        }
        $parts[] = $seg['day'] . ' ' . $seg['start_label'] . ' - ' . $seg['end_label'];
    }
    return implode('; ', $parts);
}

function schedulesOverlap($a, $b) {
    return ($a['start'] < $b['end']) && ($b['start'] < $a['end']);
}

function fetchScheduleSegmentsForClasses($db, $classIds) {
    if (empty($classIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $stmt = $db->prepare("SELECT class_id, day, start_time, end_time
                          FROM class_schedules
                          WHERE class_id IN ($placeholders)");
    $stmt->execute($classIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $classId = (int)($row['class_id'] ?? 0);
        if ($classId <= 0) {
            continue;
        }
        $day = ScheduleParser::normalizeDay($row['day'] ?? '');
        if ($day === '') {
            continue;
        }
        $start = ScheduleParser::toMinutes(date('g:i A', strtotime($row['start_time'] ?? '00:00:00')));
        $end = ScheduleParser::toMinutes(date('g:i A', strtotime($row['end_time'] ?? '00:00:00')));
        if ($end <= $start) {
            continue;
        }
        $map[$classId][] = [
            'day' => $day,
            'start' => $start,
            'end' => $end,
        ];
    }
    return $map;
}

function validateScheduleConflict($db, $incomingSegments, $gradeLevel, $section, $excludeClassId = null) {
    if (empty($incomingSegments)) {
        return null;
    }

    $params = [(int)$gradeLevel, trim((string)$section)];
    $sql = "SELECT id, class_name, grade_level, section, schedule
            FROM classes
            WHERE status = 'active'
              AND grade_level = ?
              AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))";
    if ($excludeClassId !== null) {
        $sql .= " AND id <> ?";
        $params[] = (int)$excludeClassId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $existingClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($existingClasses)) {
        return null;
    }

    $classIds = array_map('intval', array_column($existingClasses, 'id'));
    $scheduleMap = fetchScheduleSegmentsForClasses($db, $classIds);

    foreach ($existingClasses as $existing) {
        $existingId = (int)($existing['id'] ?? 0);
        $existingSegments = $scheduleMap[$existingId] ?? parseScheduleSegmentsFromString($existing['schedule'] ?? '');
        if (empty($existingSegments)) {
            continue;
        }
        foreach ($incomingSegments as $incoming) {
            foreach ($existingSegments as $exSeg) {
                if (($incoming['day'] ?? '') !== ($exSeg['day'] ?? '')) {
                    continue;
                }
                if (schedulesOverlap($incoming, $exSeg)) {
                    $existingName = trim((string)$existing['class_name']) . " (G" . (string)$existing['grade_level'] . "-" . (string)$existing['section'] . ")";
                    return "Schedule conflict: overlapping time with {$existingName}.";
                }
            }
        }
    }

    return null;
}

function currentAcademicYearForClassSync() {
    $year = (int)date('Y');
    $month = (int)date('n');
    $start = $month >= 6 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}

function syncClassEnrollmentsByGradeSection($db, $classId, $gradeLevel, $section, ?string $track = null) {
    $academicYear = currentAcademicYearForClassSync();
    $curriculum = strengthenedShsCurriculum((int)$gradeLevel, $academicYear);
    $program = strengthenedShsProgram((int)$gradeLevel, $track, $academicYear);
    $semester = $curriculum === 'strengthened_shs' ? null : 1;

    // Enroll active/pending students whose profile matches class grade/section.
    $insert = $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, curriculum, program, status, enrolled_at)
                            SELECT u.id, ?, ?, ?, ?, ?, 'enrolled', NOW()
                            FROM users u
                            WHERE u.role = 'student'
                              AND u.status IN ('active', 'pending')
                              AND u.grade_level = ?
                              AND u.section = ?
                              AND (? IS NULL OR u.track = ?)
                              AND NOT EXISTS (
                                  SELECT 1
                                  FROM enrollments e
                                  WHERE e.student_id = u.id
                                    AND e.class_id = ?
                                    AND COALESCE(e.status, 'enrolled') = 'enrolled'
                              )");
    $insert->execute([(int)$classId, $academicYear, $semester, $curriculum, $program, (int)$gradeLevel, (string)$section, $track, $track, (int)$classId]);
}

function syncClassSchedules($db, $classId, $segments) {
    $deleteStmt = $db->prepare("DELETE FROM class_schedules WHERE class_id = ?");
    $deleteStmt->execute([(int)$classId]);

    if (empty($segments)) {
        return;
    }

    $insert = $db->prepare("INSERT INTO class_schedules (class_id, day, start_time, end_time, created_at)
                            VALUES (?, ?, ?, ?, NOW())");
    foreach ($segments as $seg) {
        if (empty($seg['day']) || empty($seg['start_time']) || empty($seg['end_time'])) {
            continue;
        }
        $insert->execute([(int)$classId, $seg['day'], $seg['start_time'], $seg['end_time']]);
    }
}

function createClass($db) {
    try {
        $className = normalizeSubjectNameValue($_POST['class_name'] ?? '');
        $gradeLevel = $_POST['grade_level'] ?? '';
        $section = $_POST['section'] ?? '';
        $scheduleRowsRaw = $_POST['schedule_rows'] ?? '';
        $scheduleRows = [];
        if ($scheduleRowsRaw !== '') {
            $decoded = json_decode((string)$scheduleRowsRaw, true);
            if (is_array($decoded)) {
                $scheduleRows = $decoded;
            }
        }
        $scheduleError = '';
        $segments = normalizeScheduleRows($scheduleRows, $scheduleError);
        if ($scheduleError !== '') {
            echo json_encode(['success' => false, 'message' => $scheduleError]);
            return;
        }
        if (empty($segments)) {
            $segments = parseScheduleSegmentsFromString($_POST['schedule'] ?? '');
        }
        if (empty($segments)) {
            echo json_encode(['success' => false, 'message' => 'Please add at least one schedule row']);
            return;
        }
        $schedule = scheduleSummaryFromSegments($segments);
        $room = normalizeRoomValue($_POST['room'] ?? '');
        $track = trim((string)($_POST['track'] ?? ''));
        $subjectCategory = trim((string)($_POST['subject_category'] ?? ''));
        $wwWeight = isset($_POST['ww_weight']) ? (float)$_POST['ww_weight'] : 25.00;
        $ptWeight = isset($_POST['pt_weight']) ? (float)$_POST['pt_weight'] : 50.00;
        $assessmentWeight = isset($_POST['assessment_weight']) ? (float)$_POST['assessment_weight'] : 25.00;
        
        // Validation
        if (empty($className) || empty($gradeLevel) || empty($section)) {
            echo json_encode(['success' => false, 'message' => 'Class name, grade level, and section are required']);
            return;
        }

        if ($wwWeight < 0 || $ptWeight < 0 || $assessmentWeight < 0) {
            echo json_encode(['success' => false, 'message' => 'Weights must be non-negative']);
            return;
        }
        if (abs(($wwWeight + $ptWeight + $assessmentWeight) - 100) > 0.01) {
            echo json_encode(['success' => false, 'message' => 'Written Work + Performance Task + Assessment must total 100%']);
            return;
        }

        $scheduleConflict = validateScheduleConflict($db, $segments, $gradeLevel, $section, null);
        if ($scheduleConflict !== null) {
            echo json_encode(['success' => false, 'message' => $scheduleConflict]);
            return;
        }
        
        // Allow multiple subjects per section; only block exact duplicate subject+section.
        $checkStmt = $db->prepare("SELECT id
                                   FROM classes
                                   WHERE status = 'active'
                                   AND grade_level = ?
                                   AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                   AND LOWER(TRIM(COALESCE(class_name, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                   LIMIT 1");
        $checkStmt->execute([$gradeLevel, $section, $className]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This subject class already exists for Grade ' . $gradeLevel . ' - ' . $section]);
            return;
        }
        
        // Insert class
        ensureClassTrackColumn($db);
        $columns = ['class_name', 'grade_level', 'section', 'schedule', 'room', 'ww_weight', 'pt_weight', 'assessment_weight', 'status', 'created_at'];
        $values = [$className, $gradeLevel, $section, $schedule, $room, $wwWeight, $ptWeight, $assessmentWeight, 'active', date('Y-m-d H:i:s')];
        $placeholders = array_fill(0, count($values), '?');

        $hasSubjectCategory = dbHasColumn($db, 'classes', 'subject_category');
        if ($hasSubjectCategory) {
            $columns[] = 'subject_category';
            $values[] = $subjectCategory ?: 'core';
        }

        $hasTrack = dbHasColumn($db, 'classes', 'track');
        $normalizedTrack = $track ?: ($subjectCategory === 'techpro_elective' ? 'techpro' : 'academic');
        if ($hasTrack) {
            $columns[] = 'track';
            $values[] = $normalizedTrack;
        }

        $curriculum = strengthenedShsCurriculum((int)$gradeLevel);
        $program = strengthenedShsProgram((int)$gradeLevel, $normalizedTrack);
        $hasCurriculum = dbHasColumn($db, 'classes', 'curriculum');
        if ($hasCurriculum) {
            $columns[] = 'curriculum';
            $values[] = $curriculum;
        }
        $hasProgram = dbHasColumn($db, 'classes', 'program');
        if ($hasProgram) {
            $columns[] = 'program';
            $values[] = $program;
        }

        $columnList = implode(', ', $columns);
        $placeholderList = implode(', ', array_fill(0, count($values), '?'));
        $stmt = $db->prepare("INSERT INTO classes ($columnList) VALUES ($placeholderList)");
        $stmt->execute($values);
        
        $classId = $db->lastInsertId();
        syncClassSchedules($db, $classId, $segments);
        syncClassEnrollmentsByGradeSection($db, $classId, $gradeLevel, $section, $normalizedTrack);
        adminClassAuditLog($db, 'create_class', (int)$classId, [
            'class_name' => $className,
            'grade_level' => $gradeLevel,
            'section' => $section,
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Class created successfully', 'class_id' => $classId]);
        
    } catch (PDOException $e) {
        error_log("Admin_Classes_Action create error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function updateClass($db) {
    try {
        $classId = $_POST['class_id'] ?? '';
        $className = normalizeSubjectNameValue($_POST['class_name'] ?? '');
        $gradeLevel = $_POST['grade_level'] ?? '';
        $section = $_POST['section'] ?? '';
        $scheduleRowsRaw = $_POST['schedule_rows'] ?? '';
        $scheduleRows = [];
        if ($scheduleRowsRaw !== '') {
            $decoded = json_decode((string)$scheduleRowsRaw, true);
            if (is_array($decoded)) {
                $scheduleRows = $decoded;
            }
        }
        $scheduleError = '';
        $segments = normalizeScheduleRows($scheduleRows, $scheduleError);
        if ($scheduleError !== '') {
            echo json_encode(['success' => false, 'message' => $scheduleError]);
            return;
        }
        if (empty($segments)) {
            $segments = parseScheduleSegmentsFromString($_POST['schedule'] ?? '');
        }
        if (empty($segments)) {
            echo json_encode(['success' => false, 'message' => 'Please add at least one schedule row']);
            return;
        }
        $schedule = scheduleSummaryFromSegments($segments);
        $room = normalizeRoomValue($_POST['room'] ?? '');
        $track = trim((string)($_POST['track'] ?? ''));
        $subjectCategory = trim((string)($_POST['subject_category'] ?? ''));
        $wwWeight = isset($_POST['ww_weight']) ? (float)$_POST['ww_weight'] : 25.00;
        $ptWeight = isset($_POST['pt_weight']) ? (float)$_POST['pt_weight'] : 50.00;
        $assessmentWeight = isset($_POST['assessment_weight']) ? (float)$_POST['assessment_weight'] : 25.00;
        
        // Validation
        if (empty($classId) || empty($className) || empty($gradeLevel) || empty($section)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            return;
        }

        if ($wwWeight < 0 || $ptWeight < 0 || $assessmentWeight < 0) {
            echo json_encode(['success' => false, 'message' => 'Weights must be non-negative']);
            return;
        }
        if (abs(($wwWeight + $ptWeight + $assessmentWeight) - 100) > 0.01) {
            echo json_encode(['success' => false, 'message' => 'Written Work + Performance Task + Assessment must total 100%']);
            return;
        }

        $scheduleConflict = validateScheduleConflict($db, $segments, $gradeLevel, $section, (int)$classId);
        if ($scheduleConflict !== null) {
            echo json_encode(['success' => false, 'message' => $scheduleConflict]);
            return;
        }
        
        // Allow multiple subjects per section; only block exact duplicate subject+section.
        $checkStmt = $db->prepare("SELECT id
                                   FROM classes
                                   WHERE status = 'active'
                                   AND id != ?
                                   AND grade_level = ?
                                   AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                   AND LOWER(TRIM(COALESCE(class_name, ''))) = LOWER(TRIM(COALESCE(?, '')))
                                   LIMIT 1");
        $checkStmt->execute([$classId, $gradeLevel, $section, $className]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Another subject class with the same name already exists for Grade ' . $gradeLevel . ' - ' . $section]);
            return;
        }
        
        // Update class
        ensureClassTrackColumn($db);
        $setCols = ['class_name = ?', 'grade_level = ?', 'section = ?', 'schedule = ?', 'room = ?', 'ww_weight = ?', 'pt_weight = ?', 'assessment_weight = ?'];
        $updateValues = [$className, $gradeLevel, $section, $schedule, $room, $wwWeight, $ptWeight, $assessmentWeight];

        $hasSubjectCategory = dbHasColumn($db, 'classes', 'subject_category');
        if ($hasSubjectCategory) {
            $setCols[] = 'subject_category = ?';
            $updateValues[] = $subjectCategory ?: 'core';
        }

        $hasTrack = dbHasColumn($db, 'classes', 'track');
        $normalizedTrack = $track ?: ($subjectCategory === 'techpro_elective' ? 'techpro' : 'academic');
        if ($hasTrack) {
            $setCols[] = 'track = ?';
            $updateValues[] = $normalizedTrack;
        }

        $curriculum = strengthenedShsCurriculum((int)$gradeLevel);
        $program = strengthenedShsProgram((int)$gradeLevel, $normalizedTrack);
        $hasCurriculum = dbHasColumn($db, 'classes', 'curriculum');
        if ($hasCurriculum) {
            $setCols[] = 'curriculum = ?';
            $updateValues[] = $curriculum;
        }
        $hasProgram = dbHasColumn($db, 'classes', 'program');
        if ($hasProgram) {
            $setCols[] = 'program = ?';
            $updateValues[] = $program;
        }

        $updateValues[] = (int)$classId;
        $setClause = implode(', ', $setCols);
        $stmt = $db->prepare("UPDATE classes SET $setClause WHERE id = ?");
        $stmt->execute($updateValues);
        syncClassSchedules($db, $classId, $segments);
        syncClassEnrollmentsByGradeSection($db, $classId, $gradeLevel, $section, $normalizedTrack);
        
        if ($stmt->rowCount() > 0) {
            adminClassAuditLog($db, 'update_class', (int)$classId, [
                'class_name' => $className,
                'grade_level' => $gradeLevel,
                'section' => $section,
            ]);
            echo json_encode(['success' => true, 'message' => 'Class updated successfully']);
        } else {
            echo json_encode(['success' => true, 'message' => 'No changes made']);
        }
        
    } catch (PDOException $e) {
        error_log("Admin_Classes_Action update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteClass($db) {
    try {
        $classId = $_POST['id'] ?? ($_GET['id'] ?? '');
        
        if (empty($classId)) {
            echo json_encode(['success' => false, 'message' => 'Class ID is required']);
            return;
        }
        
        // Soft delete - set status to inactive only when currently active.
        $stmt = $db->prepare("UPDATE classes SET status = 'inactive' WHERE id = ? AND status = 'active'");
        $stmt->execute([$classId]);

        if ($stmt->rowCount() > 0) {
            adminClassAuditLog($db, 'archive_class', (int)$classId, [
                'new_status' => 'inactive',
            ]);
            echo json_encode(['success' => true, 'message' => 'Class deleted successfully']);
            return;
        }

        // If class exists but already inactive, treat as successful idempotent delete.
        $checkStmt = $db->prepare("SELECT id, status FROM classes WHERE id = ? LIMIT 1");
        $checkStmt->execute([$classId]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['status']) && strtolower((string)$row['status']) === 'inactive') {
            echo json_encode(['success' => true, 'message' => 'Class is already deleted']);
            return;
        }

        echo json_encode(['success' => false, 'message' => 'Class not found']);
        
    } catch (PDOException $e) {
        error_log("Admin_Classes_Action delete error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function generateCoreClasses(PDO $db): void {
    $gradeLevel = (int)($_POST['grade_level'] ?? 0);
    $track = trim((string)($_POST['track'] ?? ''));

    if (!in_array($gradeLevel, [11, 12], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid grade level.']);
        return;
    }
    if (!in_array($track, ['academic', 'techpro'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid track.']);
        return;
    }

    $academicYear = currentAcademicYear();
    $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
    $program = strengthenedShsProgram($gradeLevel, $track, $academicYear);

    $sectionRows = $db->prepare("SELECT DISTINCT name FROM sections WHERE grade_level = ? AND track = ? ORDER BY name");
    $sectionRows->execute([$gradeLevel, $track]);
    $sectionNames = $sectionRows->fetchAll(PDO::FETCH_COLUMN);

    if (empty($sectionNames)) {
        echo json_encode(['success' => false, 'message' => 'No sections found for Grade ' . $gradeLevel . ' ' . $track . '. Create sections first.']);
        return;
    }

    $subjectStmt = $db->prepare("SELECT subject_code, subject_name, subject_category, term_count FROM subjects WHERE grade_level = ? AND curriculum = ? AND subject_category = 'core'");
    $subjectStmt->execute([$gradeLevel, $curriculum ?? 'strengthened_shs']);
    $coreSubjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($coreSubjects)) {
        echo json_encode(['success' => false, 'message' => 'No core subjects found in the registry. Run the subject seed first.']);
        return;
    }

    $created = 0;
    $skipped = 0;

    foreach ($sectionNames as $section) {
        foreach ($coreSubjects as $subject) {
            $existsStmt = $db->prepare("SELECT id FROM classes WHERE grade_level = ? AND section = ? AND class_name = ? AND status = 'active' LIMIT 1");
            $existsStmt->execute([$gradeLevel, $section, $subject['subject_name']]);
            if ($existsStmt->fetch()) {
                $skipped++;
                continue;
            }

            $insertStmt = $db->prepare("INSERT INTO classes (class_name, grade_level, section, subject_category, track, curriculum, program, status, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
            $insertStmt->execute([
                $subject['subject_name'],
                $gradeLevel,
                $section,
                $subject['subject_category'],
                $track,
                $curriculum,
                $program,
            ]);

            $newClassId = $db->lastInsertId();
            if ($newClassId) {
                syncClassEnrollmentsByGradeSection($db, $newClassId, $gradeLevel, $section, $track);
            }
            $created++;
        }
    }

    recordAdminAuditLog($db, 'classes.generate_core', 'classes', null, [
        'grade_level' => $gradeLevel,
        'track' => $track,
        'sections' => $sectionNames,
        'created' => $created,
        'skipped' => $skipped,
    ]);

    $msg = "$created core classes created across " . count($sectionNames) . " section(s).";
    if ($skipped > 0) $msg .= " $skipped already existed.";
    echo json_encode(['success' => true, 'message' => $msg, 'created' => $created, 'skipped' => $skipped]);
}

function exportSf2(PDO $db): void {
    $classId = (int)($_GET['class_id'] ?? 0);
    $month = (int)($_GET['month'] ?? date('n'));
    $year = (int)($_GET['year'] ?? date('Y'));
    $format = trim((string)($_GET['format'] ?? 'xlsx'));
    if (!in_array($format, ['csv', 'xlsx'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'SF2 export supports CSV and XLSX only.']);
        exit();
    }

    if ($month < 1 || $month > 12) $month = (int)date('n');
    if ($year < 2020 || $year > 2099) $year = (int)date('Y');
    if ($classId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Class ID is required.']);
        exit();
    }

    $classStmt = $db->prepare("SELECT c.*, u.first_name, u.last_name FROM classes c LEFT JOIN users u ON c.teacher_id = u.id WHERE c.id = ?");
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Class not found.']);
        exit();
    }

    $academicYearStart = $month >= 6 ? $year : $year - 1;
    $sf2AcademicYear = $academicYearStart . '-' . ($academicYearStart + 1);

    $stuStmt = $db->prepare("SELECT u.id, u.first_name, u.middle_name, u.last_name, u.lrn, u.sex
                             FROM users u
                             INNER JOIN enrollments e ON e.student_id = u.id
                             WHERE e.class_id = ?
                             AND e.academic_year = ?
                             AND COALESCE(e.status, 'enrolled') = 'enrolled'
                             AND u.role = 'student'
                             AND u.status = 'active'
                             ORDER BY u.last_name, u.first_name");
    $stuStmt->execute([$classId, $sf2AcademicYear]);
    $students = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $attStmt = $db->prepare("SELECT student_id, date, status FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? ORDER BY date");
    $attStmt->execute([$classId, $startDate, $endDate]);
    $attendance = [];
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attendance[$r['student_id']][$r['date']] = $r['status'];
    }

    $teacherName = trim(($class['first_name'] ?? '') . ' ' . ($class['last_name'] ?? ''));
    $monthName = date('F', mktime(0, 0, 0, $month, 1, $year));


    $exporter = new Sf2Exporter();
    $exporter->setClass($class);
    $exporter->setStudents($students);
    $exporter->setAttendance($attendance);
    $exporter->setMonth($month);
    $exporter->setYear($year);
    $exporter->setTeacherName($teacherName);

    $safeClass = preg_replace('/[^a-zA-Z0-9_-]/', '', $class['class_name'] ?? 'class');
    $safeSection = preg_replace('/[^a-zA-Z0-9_-]/', '', $class['section'] ?? '');
    if ($format === 'csv') {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . "SF2_G{$class['grade_level']}_{$safeSection}_{$safeClass}_{$monthName}_{$year}.csv" . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Present', 'Absent', 'Tardy']);
        foreach ($students as $student) {
            $present = 0; $absent = 0; $tardy = 0;
            foreach ($attendance[(int)$student['id']] ?? [] as $status) {
                $status = strtolower((string)$status);
                if ($status === 'present') { $present++; }
                elseif ($status === 'absent') { $absent++; }
                elseif ($status === 'late' || $status === 'tardy') { $tardy++; }
            }
            fputcsv($out, [$student['lrn'] ?? '', $student['last_name'] ?? '', $student['first_name'] ?? '', $student['middle_name'] ?? '', $student['sex'] ?? '', $present, $absent, $tardy]);
        }
        fclose($out);
        exit();
    }

    $tmp = tempnam(sys_get_temp_dir(), 'sf2_') . '.xlsx';
    if ($exporter->export($tmp)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $filename = "SF2_G{$class['grade_level']}_{$safeSection}_{$safeClass}_{$monthName}_{$year}.xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: public');
        readfile($tmp);
        unlink($tmp);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to create SF2 export.']);
    }
    exit();
}


