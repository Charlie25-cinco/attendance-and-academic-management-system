<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$page_title = 'Audit Logs';
$current_page = 'audit_logs';
$current_role = 'admin';

$search = trim((string)($_GET['search'] ?? ''));
$actionFilter = trim((string)($_GET['action_name'] ?? ''));
$targetFilter = trim((string)($_GET['target_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$loginPage = max(1, (int)($_GET['login_page'] ?? 1));
$perPage = 15;

$auditRows = [];
$loginRows = [];
$actionOptions = [];
$targetOptions = [];
$auditTotal = 0;
$loginTotal = 0;
$auditTotalPages = 1;
$loginTotalPages = 1;
$errorMessage = '';

function auditLogBuildUrl(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'admin_Audit_Logs.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

function auditLogFormatAction(string $actionName): string
{
    $label = str_replace(['.', '_'], ' ', $actionName);
    return ucwords(trim($label));
}

function auditLogFormatDetails(?string $json): string
{
    if ($json === null || trim($json) === '') {
        return 'No extra details';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $json;
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        } elseif ($value === null) {
            $value = 'none';
        }
        $parts[] = ucwords(str_replace('_', ' ', (string)$key)) . ': ' . (string)$value;
    }
    return implode(' | ', $parts);
}

function auditLogDateIsValid(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date instanceof DateTime && $date->format('Y-m-d') === $value;
}

if ($db) {
    try {
        ensureAdminAuditLogTable($db);
        ensureAuthLoginLogsTable($db);

        if (!auditLogDateIsValid($dateFrom)) {
            $dateFrom = '';
        }
        if (!auditLogDateIsValid($dateTo)) {
            $dateTo = '';
        }

        $actionOptions = $db->query("SELECT DISTINCT action_name FROM admin_audit_logs ORDER BY action_name")
            ->fetchAll(PDO::FETCH_COLUMN);
        $targetOptions = $db->query("SELECT DISTINCT target_type FROM admin_audit_logs ORDER BY target_type")
            ->fetchAll(PDO::FETCH_COLUMN);

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = "(aal.action_name LIKE :search
                       OR aal.target_type LIKE :search
                       OR aal.details_json LIKE :search
                       OR u.reference_code LIKE :search
                       OR u.first_name LIKE :search
                       OR u.last_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if ($actionFilter !== '') {
            $where[] = 'aal.action_name = :action_name';
            $params[':action_name'] = $actionFilter;
        }
        if ($targetFilter !== '') {
            $where[] = 'aal.target_type = :target_type';
            $params[':target_type'] = $targetFilter;
        }
        if ($dateFrom !== '') {
            $where[] = 'aal.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'aal.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $countStmt = $db->prepare("SELECT COUNT(*)
                                   FROM admin_audit_logs aal
                                   LEFT JOIN users u ON u.id = aal.admin_user_id
                                   $whereSql");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $auditTotal = (int)$countStmt->fetchColumn();
        $auditTotalPages = max(1, (int)ceil($auditTotal / $perPage));
        $page = min($page, $auditTotalPages);
        $offset = ($page - 1) * $perPage;

        $auditStmt = $db->prepare("SELECT aal.id, aal.admin_user_id, aal.action_name, aal.target_type,
                                          aal.target_id, aal.details_json, aal.created_at,
                                          u.reference_code, u.first_name, u.last_name, u.role
                                   FROM admin_audit_logs aal
                                   LEFT JOIN users u ON u.id = aal.admin_user_id
                                   $whereSql
                                   ORDER BY aal.created_at DESC, aal.id DESC
                                   LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $auditStmt->bindValue($key, $value);
        }
        $auditStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $auditStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $auditStmt->execute();
        $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        $loginCountStmt = $db->query("SELECT COUNT(*) FROM auth_login_logs");
        $loginTotal = (int)$loginCountStmt->fetchColumn();
        $loginTotalPages = max(1, (int)ceil($loginTotal / $perPage));
        $loginPage = min($loginPage, $loginTotalPages);
        $loginOffset = ($loginPage - 1) * $perPage;

        $loginStmt = $db->prepare("SELECT allog.id, allog.reference_code, allog.user_id, allog.success,
                                          allog.ip_address, allog.user_agent, allog.failure_reason,
                                          allog.created_at, u.first_name, u.last_name, u.role
                                   FROM auth_login_logs allog
                                   LEFT JOIN users u ON u.id = allog.user_id
                                   ORDER BY allog.created_at DESC, allog.id DESC
                                   LIMIT :limit OFFSET :offset");
        $loginStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $loginStmt->bindValue(':offset', $loginOffset, PDO::PARAM_INT);
        $loginStmt->execute();
        $loginRows = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Audit logs page error: ' . $e->getMessage());
        $errorMessage = 'Unable to load audit logs right now.';
    }
} else {
    $errorMessage = 'Database connection failed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <section class="admin-hero admin-hero-compact mb-4">
                <div class="admin-hero-grid">
                    <div class="admin-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-shield-check"></i><span>System Audit</span></div>
                        <h4 class="mb-2">Track important system activity.</h4>
                        <p class="text-muted mb-3">Review admin changes and recent sign-in attempts for accountability and troubleshooting.</p>
                        <div class="admin-hero-metrics">
                            <div class="admin-hero-metric">
                                <span>Admin actions</span>
                                <strong><?php echo number_format($auditTotal); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Login attempts</span>
                                <strong><?php echo number_format($loginTotal); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Filtered page</span>
                                <strong><?php echo (int)$page; ?>/<?php echo (int)$auditTotalPages; ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="admin-hero-side">
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Audit question</span>
                            <strong>Who did what?</strong>
                            <small>Each row keeps the actor, action, target, and timestamp.</small>
                        </div>
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Security</span>
                            <strong>Login trail</strong>
                            <small>Successful and failed sign-ins are listed below.</small>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($errorMessage !== '') : ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="content-card-title">Admin Action Logs</h5>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 align-items-end mb-4 app-responsive-filter-form">
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" placeholder="Admin, action, target, details..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">Action</label>
                            <select name="action_name" class="form-select">
                                <option value="">All actions</option>
                                <?php foreach ($actionOptions as $option) : ?>
                                    <option value="<?php echo htmlspecialchars((string)$option); ?>" <?php echo $actionFilter === (string)$option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(auditLogFormatAction((string)$option)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">Target</label>
                            <select name="target_type" class="form-select">
                                <option value="">All targets</option>
                                <?php foreach ($targetOptions as $option) : ?>
                                    <option value="<?php echo htmlspecialchars((string)$option); ?>" <?php echo $targetFilter === (string)$option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst((string)$option)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-grid d-sm-flex gap-2 justify-content-end">
                                <a class="btn btn-secondary-custom" href="admin_Audit_Logs.php">Clear</a>
                                <button class="btn btn-primary-custom" type="submit"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                            </div>
                        </div>
                    </form>

                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Admin</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auditRows)) : ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No admin action logs found.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($auditRows as $row) : ?>
                                        <?php
                                            $adminName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                                        if ($adminName === '') {
                                            $adminName = 'Unknown admin';
                                        }
                                            $targetLabel = ucfirst((string)$row['target_type']);
                                        if (!empty($row['target_id'])) {
                                            $targetLabel .= ' #' . (int)$row['target_id'];
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string)$row['created_at']))); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars((string)($row['reference_code'] ?? 'No reference')); ?></small>
                                            </td>
                                            <td><span class="status-badge status-active"><?php echo htmlspecialchars(auditLogFormatAction((string)$row['action_name'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($targetLabel); ?></td>
                                            <td><small><?php echo htmlspecialchars(auditLogFormatDetails($row['details_json'] ?? null)); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($auditTotalPages > 1) : ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <small class="text-muted">
                                Showing <?php echo $auditTotal > 0 ? (($page - 1) * $perPage + 1) : 0; ?>-<?php echo min($page * $perPage, $auditTotal); ?> of <?php echo $auditTotal; ?>
                            </small>
                            <nav aria-label="Audit log pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['page' => $page - 1])); ?>">Prev</a>
                                    </li>
                                    <?php for ($i = max(1, $page - 2); $i <= min($auditTotalPages, $page + 2); $i++) : ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page >= $auditTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">Recent Login Attempts</h5>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Reference</th>
                                    <th>User</th>
                                    <th>Status</th>
                                    <th>IP Address</th>
                                    <th>Failure Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($loginRows)) : ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No login attempts recorded yet.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($loginRows as $row) : ?>
                                        <?php
                                            $userName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                                        if ($userName === '') {
                                            $userName = 'Unknown user';
                                        }
                                            $success = (int)($row['success'] ?? 0) === 1;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string)$row['created_at']))); ?></td>
                                            <td><?php echo htmlspecialchars((string)$row['reference_code']); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars(ucfirst((string)($row['role'] ?? '')) ?: 'No role'); ?></small>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $success ? 'active' : 'inactive'; ?>">
                                                    <?php echo $success ? 'Success' : 'Failed'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars((string)($row['ip_address'] ?? '')); ?></td>
                                            <td><small><?php echo htmlspecialchars((string)($row['failure_reason'] ?? ($success ? 'None' : 'Not specified'))); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($loginTotalPages > 1) : ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <small class="text-muted">
                                Showing <?php echo $loginTotal > 0 ? (($loginPage - 1) * $perPage + 1) : 0; ?>-<?php echo min($loginPage * $perPage, $loginTotal); ?> of <?php echo $loginTotal; ?>
                            </small>
                            <nav aria-label="Login log pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $loginPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['login_page' => $loginPage - 1])); ?>">Prev</a>
                                    </li>
                                    <?php for ($i = max(1, $loginPage - 2); $i <= min($loginTotalPages, $loginPage + 2); $i++) : ?>
                                        <li class="page-item <?php echo $i === $loginPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['login_page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $loginPage >= $loginTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(auditLogBuildUrl(['login_page' => $loginPage + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
