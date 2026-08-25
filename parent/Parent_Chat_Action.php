<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Parent Chat Action Handler
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'parent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit();
}

header('Content-Type: application/json');

$db = (new Database())->getConnection();
if (!$db) { echo json_encode(['success' => false, 'message' => 'DB error.']); exit(); }

$parentId = (int)($_SESSION['user_id'] ?? 0);

// GET: fetch new messages
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim($_GET['action'] ?? '');
    if ($action === 'get_messages') {
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        $lastId = (int)($_GET['last_id'] ?? 0);
        if ($teacherId <= 0) { echo json_encode(['messages' => []]); exit(); }

        // Verify teacher is the adviser of one of the parent's linked children.
        $verifyStmt = $db->prepare("
            SELECT 1
            FROM parent_students ps
            JOIN users st ON st.id = ps.student_id AND st.role = 'student' AND st.status = 'active'
            JOIN classes c ON c.teacher_id = ?
                AND c.status = 'active'
                AND c.grade_level = st.grade_level
                AND " . sectionMatchSql('c.section', 'st.section') . "
            WHERE ps.parent_id = ?
            LIMIT 1
        ");
        $verifyStmt->execute([$teacherId, $parentId]);
        if (!$verifyStmt->fetch()) { echo json_encode(['messages' => []]); exit(); }

        // Mark teacher's messages as read
        $db->prepare("UPDATE messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0")
           ->execute([$teacherId, $parentId]);

        $stmt = $db->prepare("
            SELECT m.id, m.from_user_id, m.message, m.created_at
            FROM messages m
            WHERE ((m.from_user_id = ? AND m.to_user_id = ?) OR (m.from_user_id = ? AND m.to_user_id = ?))
            AND m.id > ?
            ORDER BY m.created_at ASC LIMIT 50
        ");
        $stmt->execute([$parentId, $teacherId, $teacherId, $parentId, $lastId]);
        $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($msgs as $msg) {
            $result[] = [
                'id' => (int)$msg['id'],
                'is_mine' => (int)$msg['from_user_id'] === $parentId,
                'message' => (string)$msg['message'],
                'time' => date('M d, g:i A', strtotime((string)$msg['created_at']))
            ];
        }
        echo json_encode(['messages' => $result]);
        exit();
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit();
}

// POST: send message
$action = trim($_POST['action'] ?? '');
$csrfToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

if ($action === 'send_message') {
    $toUserId = (int)($_POST['to_user_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($toUserId <= 0 || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid message.']);
        exit();
    }
    if (mb_strlen($message) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Message too long.']);
        exit();
    }

    // Verify recipient is the adviser of one of the parent's linked children.
    $verifyStmt = $db->prepare("
        SELECT ps.student_id
        FROM parent_students ps
        JOIN users st ON st.id = ps.student_id AND st.role = 'student' AND st.status = 'active'
        JOIN classes c ON c.teacher_id = ?
            AND c.status = 'active'
            AND c.grade_level = st.grade_level
            AND " . sectionMatchSql('c.section', 'st.section') . "
        WHERE ps.parent_id = ?
          AND (? <= 0 OR ps.student_id = ?)
        LIMIT 1
    ");
    $verifyStmt->execute([$toUserId, $parentId, $studentId, $studentId]);
    $verifiedStudentId = (int)($verifyStmt->fetchColumn() ?: 0);
    if ($verifiedStudentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized recipient.']);
        exit();
    }

    $insertStmt = $db->prepare("INSERT INTO messages (from_user_id, to_user_id, student_id, message, created_at)
                                VALUES (?, ?, ?, ?, NOW())");
    $insertStmt->execute([$parentId, $toUserId, $verifiedStudentId, $message]);
    $messageId = (int)$db->lastInsertId();

    $senderName = trim((string)($_SESSION['first_name'] ?? 'Parent') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    $studentName = '';
    $stStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stStmt->execute([$verifiedStudentId]);
    $stRow = $stStmt->fetch(PDO::FETCH_ASSOC);
    if ($stRow) {
        $studentName = trim($stRow['first_name'] . ' ' . $stRow['last_name']);
    }

    $preview = mb_substr($message, 0, 80);
    $subtitle = $studentName !== '' ? "Re: $studentName - $preview" : $preview;

    if (function_exists('appDispatchNotification')) {
        appDispatchNotification(
            $db,
            [$toUserId],
            'chat_msg_' . $messageId,
            "New message from $senderName (Parent)",
            $subtitle,
            'bi-chat-dots',
            'primary',
            [
                'teacher' => "teacher_Chat.php?parent_id={$parentId}",
                'default' => "teacher_Chat.php?parent_id={$parentId}"
            ],
            [
                'type' => 'chat_message',
                'from_user_id' => $parentId,
                'message_id' => $messageId
            ]
        );
    }

    echo json_encode(['success' => true, 'message' => 'Sent.', 'id' => $messageId]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
