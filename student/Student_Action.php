<?php
use BshsAms\Storage\MaterialStorage;

$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}


$db = (new Database())->getConnection();
if (!$db) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$studentId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'download_material':
        downloadStudentMaterial($db, $studentId);
        break;
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function downloadStudentMaterial($db, $studentId) {
    header('Content-Type: application/json');
    try {
        $materialId = (int)($_GET['id'] ?? 0);
        if ($materialId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid material ID']);
            return;
        }

        $stmt = $db->prepare("SELECT m.id, m.title, m.file_name, m.file_type
                              FROM materials m
                              JOIN class_subjects cs ON cs.id = m.class_subject_id
                              JOIN enrollments e ON e.class_id = cs.class_id
                              WHERE m.id = ? AND e.student_id = ? AND e.status = 'enrolled'
                              LIMIT 1");
        $stmt->execute([$materialId, $studentId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$material) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            return;
        }

        $filePath = MaterialStorage::locate((string)$material['file_name']);
        if ($filePath === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'File not found']);
            return;
        }
        $extension = strtolower((string)($material['file_type'] ?? ''));
        if ($extension === '') {
            $extension = pathinfo($material['file_name'], PATHINFO_EXTENSION);
        }
        MaterialStorage::outputDownload($filePath, (string)$material['title'], $extension);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    } catch (Throwable $e) {
        error_log('Student material download failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Material download failed']);
    }
}
?>
