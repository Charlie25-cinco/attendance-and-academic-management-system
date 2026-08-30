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

// Process School Logo / Seal upload if provided
$logoUpdated = false;
if (isset($_FILES['school_logo']) && is_array($_FILES['school_logo']) && ($_FILES['school_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $logoFile = $_FILES['school_logo'];
    if ($logoFile['error'] === UPLOAD_ERR_OK && is_uploaded_file($logoFile['tmp_name'])) {
        $maxSizeBytes = 5 * 1024 * 1024; // 5MB limit
        if ($logoFile['size'] > $maxSizeBytes) {
            $err = 'School logo image must not exceed 5MB.';
            if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $err]);
            } else {
                header('Location: admin_School_Settings.php?error=' . urlencode($err));
            }
            exit();
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $logoFile['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            $err = 'Invalid logo format. Only PNG, JPG, and WEBP images are allowed.';
            if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $err]);
            } else {
                header('Location: admin_School_Settings.php?error=' . urlencode($err));
            }
            exit();
        }

        $destPath = dirname(__DIR__) . '/assets/images/bshs-logo.jpg';

        $srcImage = null;
        if ($mime === 'image/png') {
            $srcImage = @imagecreatefrompng($logoFile['tmp_name']);
        } elseif ($mime === 'image/webp') {
            $srcImage = @imagecreatefromwebp($logoFile['tmp_name']);
        } elseif ($mime === 'image/jpeg') {
            $srcImage = @imagecreatefromjpeg($logoFile['tmp_name']);
        }

        if ($srcImage) {
            $srcW = imagesx($srcImage);
            $srcH = imagesy($srcImage);
            $canvas = imagecreatetruecolor($srcW, $srcH);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $srcW, $srcH, $white);
            imagecopy($canvas, $srcImage, 0, 0, 0, 0, $srcW, $srcH);
            imagejpeg($canvas, $destPath, 95);
            imagedestroy($canvas);
            imagedestroy($srcImage);
            $logoUpdated = true;
        } else {
            if (move_uploaded_file($logoFile['tmp_name'], $destPath)) {
                $logoUpdated = true;
            }
        }

        // Regenerate PWA and browser favicons if GD is available
        if ($logoUpdated && extension_loaded('gd')) {
            $genScript = dirname(__DIR__) . '/scripts/generate_pwa_icons.php';
            if (is_file($genScript)) {
                try {
                    ob_start();
                    include $genScript;
                    ob_end_clean();
                } catch (Throwable $e) {
                    error_log('PWA icon generation failed: ' . $e->getMessage());
                }
            }
        }
    }
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