<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    if (!headers_sent()) {
        header("Location: ../auth/login.php");
    } else {
        echo '<script>window.location.href="../auth/login.php";</script>';
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();

ensureRbacRolesSeeded($db);

$roles = loadAllRbacRoles($db);
$permissions = loadAllRbacPermissions($db);
$rolePermissions = loadRolePermissionMap($db);

$groupedPermissions = [];
foreach ($permissions as $p) {
    $groupedPermissions[$p['category']][] = $p;
}

$selectedRole = $_GET['role'] ?? ($roles[0]['role_key'] ?? 'admin');
$selectedRoleId = 0;
foreach ($roles as $r) {
    if ($r['role_key'] === $selectedRole) {
        $selectedRoleId = (int)$r['id'];
        break;
    }
}

$current_role = 'admin';
$current_page = 'rbac';
$page_title = 'RBAC Control Panel';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
<?php echo pwaHeadHtml(); ?>
    <style>
        .rbac-perm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .rbac-category { border: 1px solid var(--surface-border); border-radius: 12px; padding: 1.25rem; background: var(--surface-bg); }
        .rbac-category-title { font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary-color); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
        .rbac-category-title i { font-size: 1rem; }
        .rbac-perm-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0; }
        .rbac-perm-item label { font-size: 0.9rem; margin: 0; cursor: pointer; user-select: none; }
        .rbac-role-tab { border: 2px solid var(--surface-border); border-radius: 12px; padding: 1rem 1.25rem; cursor: pointer; transition: all 0.2s; background: var(--surface-bg); text-decoration: none; color: inherit; display: block; }
        .rbac-role-tab:hover { border-color: var(--secondary-color); box-shadow: 0 2px 8px rgba(59,130,246,0.15); }
        .rbac-role-tab.active { border-color: var(--primary-color); background: rgba(30,58,138,0.06); box-shadow: 0 2px 12px rgba(30,58,138,0.12); }
        .rbac-role-tab .role-name { font-weight: 600; font-size: 1rem; }
        .rbac-role-tab .role-desc { font-size: 0.8rem; color: var(--portal-muted); margin-top: 0.25rem; }
        .rbac-role-tab .role-badge-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 0.35rem; }
        .rbac-save-bar { position: sticky; bottom: 0; background: var(--surface-bg); border-top: 1px solid var(--surface-border); padding: 1rem; border-radius: 0 0 16px 16px; display: flex; justify-content: flex-end; gap: 0.5rem; z-index: 10; }
        .rbac-select-all { font-size: 0.8rem; color: var(--secondary-color); cursor: pointer; text-decoration: underline; background: none; border: none; padding: 0; }
        @media (max-width: 768px) { .rbac-perm-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">RBAC Control Panel</h4>
                    <p class="text-muted mb-0">Manage roles and their permissions across the system.</p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <?php foreach ($roles as $role): ?>
                <div class="col-md-3 col-sm-6">
                    <a href="?role=<?php echo htmlspecialchars($role['role_key']); ?>" class="rbac-role-tab <?php echo $role['role_key'] === $selectedRole ? 'active' : ''; ?>">
                        <div class="role-name">
                            <span class="role-badge-dot" style="background: <?php echo match($role['role_key']) { 'admin' => 'var(--primary-color)', 'teacher' => 'var(--accent-color)', 'student' => 'var(--secondary-color)', 'parent' => 'var(--warning-color)', default => '#999' }; ?>"></span>
                            <?php echo htmlspecialchars($role['label']); ?>
                        </div>
                        <div class="role-desc"><?php echo htmlspecialchars($role['description'] ?? ''); ?></div>
                        <?php if (!empty($rolePermissions[$role['role_key']])): ?>
                        <div class="mt-2">
                            <small class="text-muted"><?php echo count(array_filter($rolePermissions[$role['role_key']])); ?> of <?php echo count($permissions); ?> permissions enabled</small>
                        </div>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="content-card">
                <div class="content-card-header d-flex justify-content-between align-items-center">
                    <h5 class="content-card-title">
                        <i class="bi bi-shield-lock me-2"></i>
                        Permissions for <strong><?php echo htmlspecialchars($selectedRole); ?></strong>
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-secondary-custom" onclick="selectAllPerms(true)">
                            <i class="bi bi-check2-all me-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary-custom" onclick="selectAllPerms(false)">
                            <i class="bi bi-x-lg me-1"></i>Deselect All
                        </button>
                    </div>
                </div>
                <div class="content-card-body">
                    <form id="rbacForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="role_id" value="<?php echo $selectedRoleId; ?>">
                        <div class="rbac-perm-grid">
                            <?php foreach ($groupedPermissions as $category => $perms): ?>
                            <div class="rbac-category">
                                <div class="rbac-category-title">
                                    <i class="bi bi-<?php echo match($category) { 'attendance' => 'calendar-check', 'grades' => 'clipboard-data', 'classes' => 'journal-bookmark', 'users' => 'people', 'announcements' => 'megaphone', 'reports' => 'graph-up', 'settings' => 'gear', 'messages' => 'chat-dots', 'archives' => 'archive', default => 'shield' }; ?>"></i>
                                    <?php echo ucfirst(htmlspecialchars($category)); ?>
                                </div>
                                <?php foreach ($perms as $p): ?>
                                <div class="rbac-perm-item">
                                    <input type="checkbox" class="form-check-input perm-checkbox" name="permissions[]" value="<?php echo htmlspecialchars($p['permission_key']); ?>" id="perm_<?php echo htmlspecialchars($p['permission_key']); ?>" <?php echo !empty($rolePermissions[$selectedRole][$p['permission_key']]) ? 'checked' : ''; ?>>
                                    <label for="perm_<?php echo htmlspecialchars($p['permission_key']); ?>"><?php echo htmlspecialchars($p['label']); ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="rbac-save-bar">
                            <span id="saveStatus" class="text-muted me-auto" style="font-size:0.85rem;"></span>
                            <button type="submit" class="btn btn-primary-custom" id="saveBtn">
                                <i class="bi bi-check-lg me-1"></i>Save Permissions
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('rbacForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const btn = document.getElementById('saveBtn');
            const status = document.getElementById('saveStatus');
            const roleId = form.querySelector('[name="role_id"]').value;
            const checkboxes = form.querySelectorAll('.perm-checkbox:checked');
            const perms = Array.from(checkboxes).map(cb => cb.value);

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
            status.textContent = '';

            const formData = new FormData();
            formData.append('action', 'save_permissions');
            formData.append('role_id', roleId);
            formData.append('permissions', JSON.stringify(perms));
            formData.append('csrf_token', form.querySelector('[name="csrf_token"]').value);

            fetch('admin_RBAC_Action.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Permissions';
                    if (data.success) {
                        status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Permissions saved successfully.</span>';
                    } else {
                        status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + (data.message || 'Failed to save.') + '</span>';
                    }
                    setTimeout(() => { status.textContent = ''; }, 4000);
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Permissions';
                    status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Network error.</span>';
                });
        });

        function selectAllPerms(checked) {
            document.querySelectorAll('.perm-checkbox').forEach(cb => { cb.checked = checked; });
        }
    </script>
    <?php include '../includes/footer.php'; ?>
</body>
</html>
