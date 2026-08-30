<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    } else {
        header('Location: ../auth/login.php');
    }
    exit();
}

requireCsrfToken();

if (!hasPermission('settings.manage')) {
    http_response_code(403);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
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

// Sync with website_content table if present
try {
    if (dbHasTable($db, 'website_content')) {
        $syncMap = [
            'contact_address' => $schoolAddress,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactNumber,
            'contact_hours' => $officeHours,
        ];
        $upStmt = $db->prepare("UPDATE website_content SET content = ?, updated_at = NOW() WHERE section_key = ?");
        foreach ($syncMap as $secKey => $contentVal) {
            if ($contentVal !== '') {
                $upStmt->execute([$contentVal, $secKey]);
            }
        }
    }
} catch (Throwable $e) {
    // Non-fatal
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
recordAdminAuditLog(
    $db,
    $adminId,
    'update_school_settings',
    'school_settings',
    null,
    "Updated school info: {$schoolName} (ID: {$schoolId}, Region: {$region}, Division: {$division})"
);

if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'School settings saved successfully']);
} else {
    header('Location: admin_School_Settings.php?status=saved');
}
exit();