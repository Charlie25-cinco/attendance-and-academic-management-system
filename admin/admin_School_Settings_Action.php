<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: admin_School_Settings.php');
    exit();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    } else {
        header('Location: ../auth/login.php');
    }
    exit();
}

requireCsrfToken();

if (!hasPermission('settings.manage')) {
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code(403);
        header('Content-Type: application/json');
    } else {
        header('Location: admin_School_Settings.php?error=' . urlencode('Insufficient permissions to manage settings'));
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    } else {
        header('Location: admin_School_Settings.php?error=' . urlencode('Database connection failed'));
    }
    exit();
}

$schoolName = trim((string)($_POST['school_name'] ?? ''));
$schoolId = trim((string)($_POST['school_id'] ?? ''));
$schoolHead = trim((string)($_POST['school_head'] ?? ''));
$region = trim((string)($_POST['region'] ?? ''));
$division = trim((string)($_POST['division'] ?? ''));
$district = trim((string)($_POST['district'] ?? ''));
$schoolAddress = trim((string)($_POST['school_address'] ?? ''));
$contactEmail = trim((string)($_POST['contact_email'] ?? ''));
$contactNumber = trim((string)($_POST['contact_number'] ?? ''));
$officeHours = trim((string)($_POST['office_hours'] ?? ''));

if ($schoolName === '' || $schoolId === '' || $region === '' || $division === '' || $schoolAddress === '') {
    $err = 'School Name, School ID, Region, Division, and Address are required.';
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $err]);
    } else {
        header('Location: admin_School_Settings.php?error=' . urlencode($err));
    }
    exit();
}

$fields = [
    'school_name' => $schoolName,
    'school_id' => $schoolId,
    'school_head' => $schoolHead,
    'region' => $region,
    'division' => $division,
    'district' => $district,
    'school_address' => $schoolAddress,
    'contact_email' => $contactEmail,
    'contact_number' => $contactNumber,
    'office_hours' => $officeHours,
];

foreach ($fields as $k => $v) {
    setSchoolSetting($db, $k, $v);
}

$websiteHeroTitle = trim((string)($_POST['website_hero_title'] ?? ''));
$websiteHeroSubtitle = trim((string)($_POST['website_hero_subtitle'] ?? ''));
$websiteAnnouncementsTagline = trim((string)($_POST['website_announcements_tagline'] ?? ''));
$websiteAboutTitle = trim((string)($_POST['website_about_title'] ?? ''));
$websiteAboutContent = trim((string)($_POST['website_about_content'] ?? ''));

// Sync with website_content table if present
try {
    if (dbHasTable($db, 'website_content')) {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $wcUpsertSql = $driver === 'sqlite'
            ? "INSERT INTO website_content (section_key, title, content, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(section_key) DO UPDATE SET title = excluded.title, content = excluded.content, updated_at = CURRENT_TIMESTAMP"
            : "INSERT INTO website_content (section_key, title, content, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), updated_at = NOW()";

        $wcStmt = $db->prepare($wcUpsertSql);

        if ($websiteHeroTitle !== '' || $websiteHeroSubtitle !== '') {
            $wcStmt->execute(['hero_title', $websiteHeroTitle !== '' ? $websiteHeroTitle : ('Welcome to ' . $schoolName), $websiteHeroSubtitle]);
        }
        if ($websiteAboutTitle !== '' || $websiteAboutContent !== '') {
            $wcStmt->execute(['about', $websiteAboutTitle !== '' ? $websiteAboutTitle : 'About Our School', $websiteAboutContent]);
        }
        if ($websiteAnnouncementsTagline !== '') {
            $wcStmt->execute(['announcements_heading', 'Announcements & Events', $websiteAnnouncementsTagline]);
        }

        $syncMap = [
            'contact_address' => ['title' => 'Address', 'content' => $schoolAddress],
            'contact_email' => ['title' => 'Email', 'content' => $contactEmail],
            'contact_phone' => ['title' => 'Phone', 'content' => $contactNumber],
            'contact_hours' => ['title' => 'Office Hours', 'content' => $officeHours],
        ];
        foreach ($syncMap as $secKey => $info) {
            if ($info['content'] !== '') {
                $wcStmt->execute([$secKey, $info['title'], $info['content']]);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Website content sync failed: ' . $e->getMessage());
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
recordAdminAuditLog(
    $db,
    'school_settings.update',
    'school_settings',
    null,
    [
        'school_name' => $schoolName,
        'school_id' => $schoolId,
        'region' => $region,
        'division' => $division,
        'district' => $district,
        'school_address' => $schoolAddress,
    ],
    $adminId > 0 ? $adminId : null
);

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'School settings saved successfully']);
} else {
    header('Location: admin_School_Settings.php?status=saved');
}
exit();