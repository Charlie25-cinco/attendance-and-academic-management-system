<?php
require_once __DIR__ . '/../functions/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

requireCsrfToken();

$action = normalizeText($_POST['action'] ?? '');
if ($action !== 'save_permissions') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit();
}

$roleId = normalizeInt($_POST['role_id'] ?? 0);
if ($roleId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role ID.']);
    exit();
}

$rawPerms = $_POST['permissions'] ?? '[]';
$decoded = json_decode($rawPerms, true);
if (!is_array($decoded)) {
    $decoded = [];
}
$enabledPerms = array_filter(array_map('strval', $decoded));

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

ensureRbacRolesSeeded($db);

$checkStmt = $db->prepare("SELECT id, role_key, is_system FROM rbac_roles WHERE id = ?");
$checkStmt->execute([$roleId]);
$role = $checkStmt->fetch(PDO::FETCH_ASSOC);
if (!$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role not found.']);
    exit();
}

try {
    $db->beginTransaction();

    $db->exec("UPDATE rbac_role_permissions SET enabled = 0 WHERE role_id = " . (int)$roleId);

    if (!empty($enabledPerms)) {
        $placeholders = implode(',', array_fill(0, count($enabledPerms), '?'));
        $permStmt = $db->prepare("SELECT id FROM rbac_permissions WHERE permission_key IN ($placeholders)");
        $permStmt->execute(array_values($enabledPerms));
        $permIds = $permStmt->fetchAll(PDO::FETCH_COLUMN);

        $insertStmt = $db->prepare("INSERT INTO rbac_role_permissions (role_id, permission_id, enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE enabled = 1");
        foreach ($permIds as $permId) {
            $insertStmt->execute([(int)$roleId, (int)$permId]);
        }
    }

    $db->commit();

    refreshSessionPermissions();
    recordAdminAuditLog($db, 'rbac.update_permissions', 'role', (int)$roleId, [
        'role_key' => (string)($role['role_key'] ?? ''),
        'enabled_permissions' => count($enabledPerms),
    ]);

    echo json_encode(['success' => true, 'message' => 'Permissions updated.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('RBAC save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
