<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}


header('Content-Type: application/json');

$db = (new Database())->getConnection();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
if (ob_get_level() === 0) {
    ob_start();
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

if (in_array($action, ['create', 'update', 'delete'], true)) {
    $csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }
}

if (ob_get_length()) {
    ob_clean();
}

try {
    switch ($action) {
        case 'list':
            $gradeFilter = trim((string)($_GET['grade_level'] ?? ''));
            $trackFilter = trim((string)($_GET['track'] ?? ''));

            $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
            $sectionMatch = ($driver === 'sqlite')
                ? "LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(s.name, '')))"
                : sectionMatchSql('c.section', 's.name');

            $sql = "SELECT s.id, s.name, s.grade_level, s.track, s.curriculum, s.program, s.created_at,
                           (
                               SELECT COUNT(DISTINCT e.student_id)
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.status = 'enrolled'
                                 AND c.status = 'active'
                                 AND c.grade_level = s.grade_level
                                 AND ({$sectionMatch})
                                 AND (c.track IS NULL OR TRIM(c.track) = '' OR LOWER(TRIM(c.track)) = LOWER(TRIM(s.track)))
                           ) AS student_count
                    FROM sections s
                    WHERE 1=1";
            $params = [];

            if ($gradeFilter !== '') {
                $sql .= " AND s.grade_level = :grade_level";
                $params[':grade_level'] = (int)$gradeFilter;
            }
            if ($trackFilter !== '') {
                $sql .= " AND s.track = :track";
                $params[':track'] = $trackFilter;
            }

            $sql .= " ORDER BY s.grade_level, s.track, s.name";
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sections as &$sec) {
                $sec['student_count'] = (int)($sec['student_count'] ?? 0);
            }
            unset($sec);

            echo json_encode(['success' => true, 'sections' => $sections]);
            break;

        case 'create':
            ensureStrengthenedShsColumns($db);
            $name = strtoupper(trim((string)($_POST['name'] ?? '')));
            $gradeLevel = (int)($_POST['grade_level'] ?? 0);
            $track = trim((string)($_POST['track'] ?? ''));

            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'Section name is required.']);
                break;
            }
            if (!in_array($gradeLevel, [11, 12], true)) {
                echo json_encode(['success' => false, 'message' => 'Grade level must be 11 or 12.']);
                break;
            }
            if (!in_array($track, ['academic', 'techpro'], true)) {
                echo json_encode(['success' => false, 'message' => 'Track must be academic or techpro.']);
                break;
            }
            $curriculum = strengthenedShsCurriculum($gradeLevel);
            $program = strengthenedShsProgram($gradeLevel, $track);

            $check = $db->prepare("SELECT id FROM sections WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "Section \"$name\" already exists."]);
                break;
            }

            $insert = $db->prepare("INSERT INTO sections (name, grade_level, track, curriculum, program) VALUES (?, ?, ?, ?, ?)");
            $insert->execute([$name, $gradeLevel, $track, $curriculum, $program]);
            $newId = (int)$db->lastInsertId();
            recordAdminAuditLog($db, 'section.create', 'section', $newId, [
                'name' => $name,
                'grade_level' => $gradeLevel,
                'track' => $track,
                'curriculum' => $curriculum,
                'program' => $program,
            ]);

            echo json_encode(['success' => true, 'message' => "Section \"$name\" created.", 'id' => $newId]);
            break;

        case 'update':
            ensureStrengthenedShsColumns($db);
            $id = (int)($_POST['id'] ?? 0);
            $name = strtoupper(trim((string)($_POST['name'] ?? '')));
            $gradeLevel = (int)($_POST['grade_level'] ?? 0);
            $track = trim((string)($_POST['track'] ?? ''));

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid section ID.']);
                break;
            }
            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'Section name is required.']);
                break;
            }
            if (!in_array($gradeLevel, [11, 12], true)) {
                echo json_encode(['success' => false, 'message' => 'Grade level must be 11 or 12.']);
                break;
            }
            if (!in_array($track, ['academic', 'techpro'], true)) {
                echo json_encode(['success' => false, 'message' => 'Track must be academic or techpro.']);
                break;
            }
            $curriculum = strengthenedShsCurriculum($gradeLevel);
            $program = strengthenedShsProgram($gradeLevel, $track);

            $check = $db->prepare("SELECT id FROM sections WHERE name = ? AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "Section \"$name\" already exists."]);
                break;
            }

            $update = $db->prepare("UPDATE sections SET name = ?, grade_level = ?, track = ?, curriculum = ?, program = ? WHERE id = ?");
            $update->execute([$name, $gradeLevel, $track, $curriculum, $program, $id]);
            recordAdminAuditLog($db, 'section.update', 'section', $id, [
                'name' => $name,
                'grade_level' => $gradeLevel,
                'track' => $track,
                'curriculum' => $curriculum,
                'program' => $program,
            ]);

            echo json_encode(['success' => true, 'message' => "Section \"$name\" updated."]);
            break;

        case 'delete':
            ensureStrengthenedShsColumns($db);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid section ID.']);
                break;
            }

            $stmt = $db->prepare("DELETE FROM sections WHERE id = ?");
            $stmt->execute([$id]);
            recordAdminAuditLog($db, 'section.delete', 'section', $id);

            echo json_encode(['success' => true, 'message' => 'Section deleted.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
} catch (Throwable $e) {
    error_log('Admin_Sections_Action error: ' . $e->getMessage());
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => 'Server error occurred.']);
}
