<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin Announcements Action Handler
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once __DIR__ . '/../api/pushnotifications.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_GET['action'] ?? '';

function adminAnnouncementsRequireCsrf() {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

function adminAnnouncementsEnsureWebsiteColumn(PDO $db): bool {
    if (dbHasColumn($db, 'announcements', 'show_on_website')) {
        return true;
    }

    try {
        $db->exec("ALTER TABLE announcements ADD COLUMN show_on_website TINYINT NOT NULL DEFAULT 0 AFTER views");
        return true;
    } catch (Throwable $e) {
        error_log('Admin announcements website column update failed: ' . $e->getMessage());
        return false;
    }
}

if (in_array($action, ['create', 'update', 'archive', 'restore', 'delete'], true)) {
    adminAnnouncementsRequireCsrf();
}

switch ($action) {
    case 'create':
        createAnnouncement($db);
        break;
    case 'get':
        getAnnouncement($db);
        break;
    case 'update':
        updateAnnouncement($db);
        break;
    case 'archive':
        setAnnouncementStatus($db, 'archived');
        break;
    case 'restore':
        setAnnouncementStatus($db, 'active');
        break;
    case 'delete':
        deleteAnnouncement($db);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function createAnnouncement($db) {
    try {
        if (!adminAnnouncementsEnsureWebsiteColumn($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required for website announcements.']);
            return;
        }

        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = $_POST['category'] ?? 'general';

        $validCategories = ['general', 'urgent', 'event', 'academic'];
        if (!in_array($category, $validCategories, true)) {
            $category = 'general';
        }

        if ($title === '' || $content === '') {
            echo json_encode(['success' => false, 'message' => 'Title and content are required']);
            return;
        }

        $postedBy = $_SESSION['user_id'] ?? null;
        if ($postedBy === null) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
            return;
        }

        $showOnWebsite = isset($_POST['show_on_website']) && $_POST['show_on_website'] === '1' ? 1 : 0;
        $stmt = $db->prepare("INSERT INTO announcements (title, content, category, posted_by, status, views, show_on_website, created_at, updated_at)
                              VALUES (?, ?, ?, ?, 'active', 0, ?, NOW(), NOW())");
        $stmt->execute([$title, $content, $category, $postedBy, $showOnWebsite]);
        $announcementId = (int)$db->lastInsertId();

        try {
            $recipientsStmt = $db->query("SELECT id
                                          FROM users
                                          WHERE role IN ('teacher', 'student', 'parent')
                                          AND status IN ('active', 'pending')");
            $recipientIds = array_map('intval', $recipientsStmt->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($recipientIds)) {
                $titleText = 'School announcement: ' . $title;
                $bodyText = ucfirst($category) . ' · Tap to read more';
                pushNotifyUsers($db, $recipientIds, $titleText, $bodyText, [
                    'link' => 'admin_Announcements.php',
                ]);
            }
        } catch (Throwable $notificationError) {
            error_log('Announcement saved, but push delivery failed: ' . $notificationError->getMessage());
        }

        recordAdminAuditLog($db, 'announcement.create', 'announcement', $announcementId, [
            'title' => $title,
            'category' => $category,
            'show_on_website' => $showOnWebsite,
        ]);
        echo json_encode(['success' => true, 'message' => 'Announcement created successfully']);
    } catch (PDOException $e) {
        error_log("Admin_Announcements_Action create error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function getAnnouncement($db) {
    try {
        if (!adminAnnouncementsEnsureWebsiteColumn($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required for website announcements.']);
            return;
        }

        $id = $_GET['id'] ?? '';
        if ($id === '') {
            echo json_encode(['success' => false, 'message' => 'Announcement ID is required']);
            return;
        }

        $stmt = $db->prepare("SELECT id, title, content, category, status, views, show_on_website, created_at, updated_at FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$announcement) {
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            return;
        }

        echo json_encode(['success' => true, 'announcement' => $announcement]);
    } catch (PDOException $e) {
        error_log("Admin_Announcements_Action get error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function updateAnnouncement($db) {
    try {
        if (!adminAnnouncementsEnsureWebsiteColumn($db)) {
            echo json_encode(['success' => false, 'message' => 'Database update required for website announcements.']);
            return;
        }

        $id = $_POST['id'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = $_POST['category'] ?? 'general';

        $validCategories = ['general', 'urgent', 'event', 'academic'];
        if (!in_array($category, $validCategories, true)) {
            $category = 'general';
        }

        if ($id === '' || $title === '' || $content === '') {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            return;
        }

        $showOnWebsite = isset($_POST['show_on_website']) && $_POST['show_on_website'] === '1' ? 1 : 0;
        $stmt = $db->prepare("UPDATE announcements SET title = ?, content = ?, category = ?, show_on_website = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$title, $content, $category, $showOnWebsite, $id]);

        recordAdminAuditLog($db, 'announcement.update', 'announcement', (int)$id, [
            'title' => $title,
            'category' => $category,
            'show_on_website' => $showOnWebsite,
        ]);
        echo json_encode(['success' => true, 'message' => 'Announcement updated successfully']);
    } catch (PDOException $e) {
        error_log("Admin_Announcements_Action update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function setAnnouncementStatus($db, $status) {
    try {
        $id = $_GET['id'] ?? '';
        if ($id === '') {
            echo json_encode(['success' => false, 'message' => 'Announcement ID is required']);
            return;
        }

        $stmt = $db->prepare("UPDATE announcements SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $id]);

        if ($stmt->rowCount() > 0) {
            $verb = $status === 'archived' ? 'archived' : 'restored';
            recordAdminAuditLog($db, 'announcement.' . $verb, 'announcement', (int)$id, [
                'status' => $status,
            ]);
            echo json_encode(['success' => true, 'message' => 'Announcement ' . $verb . ' successfully']);
            return;
        }

        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
    } catch (PDOException $e) {
        error_log("Admin_Announcements_Action status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}

function deleteAnnouncement($db) {
    try {
        $id = $_GET['id'] ?? '';
        if ($id === '') {
            echo json_encode(['success' => false, 'message' => 'Announcement ID is required']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            recordAdminAuditLog($db, 'announcement.delete', 'announcement', (int)$id);
            echo json_encode(['success' => true, 'message' => 'Announcement deleted successfully']);
            return;
        }

        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
    } catch (PDOException $e) {
        error_log("Admin_Announcements_Action delete error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
}
?>






