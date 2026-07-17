<?php
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

        $baseDir = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'materials';
        if ($baseDir === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Upload path not found']);
            return;
        }

        $filePath = $baseDir . DIRECTORY_SEPARATOR . $material['file_name'];
        if (!is_file($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'File not found']);
            return;
        }

        $downloadName = preg_replace('/[^a-zA-Z0-9_\- ]+/', '', (string)$material['title']);
        if ($downloadName === '') {
            $downloadName = 'material';
        }
        $extension = strtolower((string)($material['file_type'] ?? ''));
        if ($extension === '') {
            $extension = pathinfo($material['file_name'], PATHINFO_EXTENSION);
        }
        if ($extension !== '') {
            $downloadName .= '.' . $extension;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: public');
        readfile($filePath);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>
