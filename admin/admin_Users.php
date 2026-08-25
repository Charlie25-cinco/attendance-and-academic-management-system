<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Manage Users Page
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$users = [];
$stats = [
    'total' => 0,
    'teachers' => 0,
    'parents' => 0
];

// Get next reference codes
$nextRefCodes = [
    'teacher' => '',
    'parent' => ''
];
$availableClassCounts = [];
$studentsForParent = [];
$takenTeacherAdvisories = [];
$takenSubjectTeachers = [];
$sectionRowsForForms = [];

if ($db) {
    // Get the highest reference code for each role
    foreach ($nextRefCodes as $role => $default) {
        $nextRefCodes[$role] = generateReferenceCode($role, $db, currentAcademicYear());
    }
    
    // Get filter parameters
    $role_filter = $_GET['role'] ?? '';
    $status_filter = $_GET['status'] ?? 'active';
    if (!in_array($status_filter, ['active', 'inactive', 'pending', ''], true)) {
        $status_filter = 'active';
    }
    $search = $_GET['search'] ?? '';
    $sex_filter = strtolower(trim((string)($_GET['sex'] ?? '')));
    if (!in_array($sex_filter, ['male', 'female'], true)) {
        $sex_filter = '';
    }
    
    // Pagination parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    
    // Build query
    $where = [];
    $params = [];
    
    // Exclude admin role by default
    if (!$role_filter) {
        $where[] = "role NOT IN ('admin', 'student')";
    }
    if ($role_filter) {
        $where[] = "role = :role";
        $params[':role'] = $role_filter;
    }
    if ($status_filter) {
        $where[] = "status = :status";
        $params[':status'] = $status_filter;
    }
    if ($sex_filter) {
        $where[] = "LOWER(COALESCE(sex, '')) = :sex";
        $params[':sex'] = $sex_filter;
    }
    if ($search) {
        $where[] = "(first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR reference_code LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count total users for pagination
    $countQuery = "SELECT COUNT(*) FROM users u $whereClause";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total_users = (int)$countStmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_users / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }
    
    $hasTrackColumn = dbHasColumn($db, 'users', 'track');
    $trackSelect = $hasTrackColumn ? 'u.track,' : '';
    
    // Fetch users with assigned classes for teachers (paginated)
    $query = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at, u.last_login, $trackSelect
              (SELECT GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ')
               FROM class_subjects cs 
               JOIN classes c ON cs.class_id = c.id 
               WHERE cs.teacher_id = u.id AND c.status = 'active') as assigned_classes
              FROM users u $whereClause ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also fetch class names for newly created pending teachers
    if (!empty($users)) {
        foreach ($users as &$user) {
            if ($user['role'] === 'teacher' && empty($user['assigned_classes'])) {
                $classQuery = "SELECT GROUP_CONCAT(DISTINCT c.class_name SEPARATOR ', ')
                              FROM class_subjects cs 
                              JOIN classes c ON cs.class_id = c.id 
                              WHERE cs.teacher_id = ?";
                $classStmt = $db->prepare($classQuery);
                $classStmt->execute([$user['id']]);
                $user['assigned_classes'] = $classStmt->fetchColumn();
            }
        }
        unset($user);
    }
    
    // Fetch statistics (filtered)
    $stats['total'] = $total_users;
    $roleTotalsStmt = $db->query("SELECT
                                  SUM(CASE WHEN role = 'teacher' AND status <> 'inactive' THEN 1 ELSE 0 END) AS teachers,
                                  SUM(CASE WHEN role = 'parent' AND status <> 'inactive' THEN 1 ELSE 0 END) AS parents
                                  FROM users");
    $roleTotals = $roleTotalsStmt ? ($roleTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $stats['teachers'] = (int)($roleTotals['teachers'] ?? 0);
    $stats['parents'] = (int)($roleTotals['parents'] ?? 0);

    $classCountStmt = $db->query("SELECT grade_level, section, COUNT(*) AS total
                                  FROM classes
                                  WHERE status = 'active'
                                  GROUP BY grade_level, section");
    foreach ($classCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $availableClassCounts[$row['grade_level'] . '|' . $row['section']] = (int)$row['total'];
    }

    if (dbHasTable($db, 'sections')) {
        $sectionsStmt = $db->query("SELECT id, name, grade_level, track, program
                                    FROM sections
                                    ORDER BY grade_level ASC, track ASC, name ASC");
        foreach (($sectionsStmt ? $sectionsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $sectionRowsForForms[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
                'grade_level' => (string)($row['grade_level'] ?? ''),
                'track' => trim((string)($row['track'] ?? '')),
                'program' => trim((string)($row['program'] ?? '')),
            ];
        }
    }

    $studentsForParentStmt = $db->query("SELECT id, reference_code, first_name, last_name
                                         FROM users
                                         WHERE role = 'student' AND status IN ('active', 'pending')
                                         ORDER BY last_name, first_name");
    $studentsForParent = $studentsForParentStmt->fetchAll(PDO::FETCH_ASSOC);

    $takenSubjectStmt = $db->query("SELECT cs.class_id, cs.teacher_id
                                    FROM class_subjects cs
                                    JOIN users u ON u.id = cs.teacher_id
                                    JOIN classes c ON c.id = cs.class_id
                                    WHERE u.role = 'teacher'
                                    AND u.status IN ('active', 'pending')
                                    AND c.status = 'active'");
    $takenSubjectRows = $takenSubjectStmt ? $takenSubjectStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($takenSubjectRows as $row) {
        $classId = (int)($row['class_id'] ?? 0);
        $teacherId = (int)($row['teacher_id'] ?? 0);
        if ($classId <= 0 || $teacherId <= 0) {
            continue;
        }
        if (!isset($takenSubjectTeachers[$classId])) {
            $takenSubjectTeachers[$classId] = [];
        }
        if (!in_array($teacherId, $takenSubjectTeachers[$classId], true)) {
            $takenSubjectTeachers[$classId][] = $teacherId;
        }
    }

    $takenTeacherStmt = $db->query("SELECT id, grade_level, section
                                    FROM users
                                    WHERE role = 'teacher'
                                    AND status IN ('active', 'pending')
                                    AND grade_level IS NOT NULL
                                    AND TRIM(COALESCE(section, '')) <> ''");
    $takenTeacherRows = $takenTeacherStmt ? $takenTeacherStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($takenTeacherRows as $row) {
        $key = ((string)$row['grade_level']) . '|' . strtolower(trim((string)$row['section']));
        if ($key !== '|') {
            $takenTeacherAdvisories[$key] = (int)$row['id'];
        }
    }

}

$current_role = 'admin';
$current_page = 'users';
$page_title = 'Manage Users';
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
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Manage Users</h4>
                    <p class="text-muted mb-0">Create accounts, assign roles, and maintain secure user access.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="bi bi-person-plus me-2"></i>Add New User
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon blue"><i class="bi bi-people-fill"></i></div>
                        <div class="card-title">Total Users</div>
                        <div class="card-value"><?php echo $stats['total']; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon blue"><i class="bi bi-person-workspace"></i></div>
                        <div class="card-title">Teachers</div>
                        <div class="card-value"><?php echo $stats['teachers']; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon orange"><i class="bi bi-people"></i></div>
                        <div class="card-title">Parents</div>
                        <div class="card-value"><?php echo $stats['parents']; ?></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" id="userSearchInput" class="form-control" placeholder="Search users by name, reference code, email..." value="<?php echo htmlspecialchars($search); ?>">
                                <?php if ($search !== ''): ?>
                                    <a href="admin_Users.php<?php echo ($role_filter || $status_filter || $sex_filter) ? '?' . http_build_query(array_filter(['role' => $role_filter, 'status' => $status_filter, 'sex' => $sex_filter])) : ''; ?>" class="btn btn-outline-secondary" title="Clear Search"><i class="bi bi-x-lg"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <select name="role" class="form-select">
                                <option value="">All Roles</option>
                                <option value="teacher" <?php echo $role_filter === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="parent" <?php echo $role_filter === 'parent' ? 'selected' : ''; ?>>Parent</option>
                                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="sex" class="form-select">
                                <option value="">All Sex</option>
                                <option value="male" <?php echo $sex_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $sex_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary-custom w-100">
                                <i class="bi bi-funnel me-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Users Table -->
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">All Users</h5>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-secondary-custom dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-2"></i>Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="exportToExcel(); return false;"><i class="bi bi-file-earmark-excel me-2"></i>Excel</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportToPDF(); return false;"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</a></li>
                        </ul>
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Reference Code</th>
                                    <th>Role</th>
                                    <th>Track</th>
                                    <th>Status</th>
                                    <th>Last Active</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-list-item" style="padding: 0; border: none;">
                                            <div class="user-avatar-small" style="background: <?php echo $user['role'] === 'admin' ? 'var(--secondary-color)' : ($user['role'] === 'teacher' ? 'var(--accent-color)' : 'var(--danger-color)'); ?>">
                                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="user-list-info">
                                                <h5><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                                                <p><?php echo htmlspecialchars($user['email']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['reference_code']); ?></td>
                                    <td><span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                    <td><?php if (!empty($user['track'])): ?><span class="track-badge track-<?php echo $user['track']; ?>"><?php echo ($user['track'] ?? '') === 'techpro' ? 'TechPro' : ucfirst($user['track'] ?? ''); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                    <td><span class="status-badge status-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                    <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="material-btn js-view-user" data-user-id="<?php echo $user['id']; ?>" title="View" style="color: var(--secondary-color);"><i class="bi bi-eye"></i></button>
                                            <button type="button" class="material-btn js-toggle-status" data-user-id="<?php echo $user['id']; ?>" data-current-status="<?php echo $user['status']; ?>" title="<?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>" style="color: <?php echo $user['status'] === 'active' ? 'var(--warning-color)' : 'var(--accent-color)'; ?>;">
                                                <i class="bi bi-<?php echo $user['status'] === 'active' ? 'toggle-off' : 'toggle-on'; ?>"></i>
                                            </button>
                                            <button type="button" class="material-btn js-edit-user" data-user-id="<?php echo $user['id']; ?>" title="Edit" style="color: var(--warning-color);"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="material-btn js-delete-user" data-user-id="<?php echo $user['id']; ?>" title="Delete" style="color: var(--danger-color);"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="text-muted">
                                Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_users); ?> of <?php echo $total_users; ?> users
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <?php
                                    $query_params = $_GET;
                                    unset($query_params['page']);
                                    $base_url = '?' . http_build_query($query_params);
                                    if ($base_url !== '?') {
                                        $base_url .= '&';
                                    } else {
                                        $base_url = '?';
                                    }
                                    $max_visible = 5;
                                    $start = max(1, $page - floor($max_visible / 2));
                                    $end = min($total_pages, $start + $max_visible - 1);
                                    if ($end - $start < $max_visible - 1) {
                                        $start = max(1, $end - $max_visible + 1);
                                    }
                                    ?>
                                    <?php if ($page > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $page - 1; ?>">Prev</a></li>
                                    <?php endif; ?>
                                    <?php if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo $base_url; ?>page=1">1</a></li>
                                    <?php if ($start > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    <?php if ($end < $total_pages): ?>
                                    <?php if ($end < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a></li>
                                    <?php endif; ?>
                                    <?php if ($page < $total_pages): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo $base_url; ?>page=<?php echo $page + 1; ?>">Next</a></li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-person-plus"></i>Users</div>
                        <h5 class="modal-title mb-0">Add New User</h5>
                        <p class="app-modal-subtitle">Create accounts and assign roles quickly.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="addUserForm">
                        <div class="app-modal-stack">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-person-badge"></i>Account Setup</div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Role <span class="text-danger">*</span></label>
                                        <select name="role" id="userRole" class="form-select" required onchange="updateReferenceCode(); toggleClassDropdown();">
                                            <option value="">Select Role</option>
                                            <option value="teacher">Teacher</option>
                                            <option value="parent">Parent</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label">Reference Code <small class="text-muted">(Auto-generated)</small></label>
                                        <input type="text" name="reference_code" id="referenceCode" class="form-control" readonly style="background-color: #f8f9fa;">
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-md-4 mb-3" id="gradeLevelDropdown" style="display: none;">
                                        <label class="form-label" id="gradeLevelLabel">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="gradeLevel" class="form-select" onchange="updateSections()">
                                            <option value="">Select Grade Level</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3" id="trackDropdownCreate" style="display: none;">
                                        <label class="form-label" id="trackLabelCreate">Track <span class="text-danger">*</span></label>
                                        <select name="track" id="trackCreate" class="form-select" onchange="updateSections()">
                                            <option value="">Select Track</option>
                                            <option value="academic">Academic</option>
                                            <option value="techpro">TechPro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-0" id="sectionDropdown" style="display: none;">
                                        <label class="form-label" id="sectionLabel">Section <span class="text-danger">*</span></label>
                                        <select name="section" id="sectionSelect" class="form-select" disabled onchange="updateClassAvailabilityWarning()">
                                            <option value="">Select Grade & Track First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>Profile Details</div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" id="firstName" class="form-control" required oninput="capitalizeWords(this)">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" name="middle_name" id="middleName" class="form-control" oninput="capitalizeWords(this)">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" id="lastName" class="form-control" required oninput="capitalizeWords(this)">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Sex <span class="text-danger">*</span></label>
                                        <select name="sex" id="sex" class="form-select" required>
                                            <option value="">Select Sex</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                <div class="class-checklist" id="classChecklist">
                                <?php
                                // Fetch classes for checklist - these are the subjects
                                $classQuery = "SELECT id, class_name, grade_level, section, schedule FROM classes WHERE status = 'active' ORDER BY class_name";
                                $classStmt = $db->query($classQuery);
                                $classesList = $classStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($classesList as $class):
                                    $scheduleDisplay = $class['schedule'] ? htmlspecialchars($class['schedule']) : 'No schedule set';
                                ?>
                                <div class="form-check class-check-item">
                                    <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" id="class_<?php echo $class['id']; ?>">
                                    <label class="form-check-label" for="class_<?php echo $class['id']; ?>">
                                        <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                        <small class="text-muted d-block"><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?><?php if (!empty($class['grade_level'])): ?>Grade <?php echo htmlspecialchars((string)$class['grade_level']); ?><?php endif; ?><?php if (!empty($class['grade_level']) && !empty($class['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($class['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></small>
                                        <small class="text-info d-block"><i class="bi bi-clock me-1"></i><?php echo $scheduleDisplay; ?></small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <div class="form-text text-muted" id="classHelpText">Select subjects now or transfer a subject if needed.</div>
                            </div>
                            <div class="app-modal-panel mb-0" id="parentStudentDropdown" style="display: none;">
                                <div class="app-modal-panel-title"><i class="bi bi-people"></i>Parent Link</div>
                                <label class="form-label">Link Student(s) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm mb-2">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="parentStudentSearch" placeholder="Type student name or reference code to filter..." oninput="filterParentStudentList('parentStudentSearch', '#parentStudentChecklist .class-check-item')">
                                    <button class="btn btn-outline-secondary" type="button" id="clearParentStudentSearchBtn" style="display:none;" onclick="clearParentSearch('parentStudentSearch', '#parentStudentChecklist .class-check-item', 'clearParentStudentSearchBtn')"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <div class="class-checklist" id="parentStudentChecklist">
                                <?php foreach ($studentsForParent as $student): ?>
                                <div class="form-check class-check-item" data-student-search="<?php echo htmlspecialchars(strtolower($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['reference_code'] ?? ''))); ?>">
                                    <input class="form-check-input parent-student-checkbox" type="checkbox" name="linked_student_ids[]" value="<?php echo (int)$student['id']; ?>" id="parent_student_<?php echo (int)$student['id']; ?>">
                                    <label class="form-check-label" for="parent_student_<?php echo (int)$student['id']; ?>">
                                        <strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo !empty($student['reference_code']) ? htmlspecialchars((string)$student['reference_code']) : 'No reference code'; ?></small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <div class="form-text text-muted">Select at least 1 student.</div>
                            </div>
                            <div class="app-modal-note">
                                <i class="bi bi-info-circle-fill"></i>
                                <div>
                                    <strong>Access reminder</strong>
                                    <p>Default password will be assigned during account creation. Users should update it from their profile after first login.</p>
                                </div>
                            </div>
                            <div class="app-modal-note d-none" id="classAvailabilityWarning">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong>No matching classes yet</strong>
                                    <p>No active class was found for the selected grade level and section.</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="createUserBtn" onclick="createUser()">Create User</button>
                </div>
            </div>

        </div>
    </div>

    <?php include '../includes/delete_modal.php'; ?>

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-person-circle"></i>Profile</div>
                        <h5 class="modal-title mb-0">User Details</h5>
                        <p class="app-modal-subtitle">Review profile details and assignments.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <div class="text-center mb-4">
                        <div id="viewUserAvatar" class="user-avatar-small mx-auto" style="width: 80px; height: 80px; font-size: 28px;"></div>
                        <h5 class="mt-3 mb-1" id="viewUserName"></h5>
                        <span id="viewUserRole" class="role-badge"></span>
                    </div>
                    <div class="app-modal-panel">
                        <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>User Snapshot</div>
                        <div class="app-modal-detail-list">
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Reference Code</div>
                                <div class="app-modal-detail-value" id="viewReferenceCode"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Full Name</div>
                                <div class="app-modal-detail-value" id="viewFullName"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Email</div>
                                <div class="app-modal-detail-value" id="viewEmail"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Sex</div>
                                <div class="app-modal-detail-value" id="viewSex"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Status</div>
                                <div class="app-modal-detail-value"><span id="viewStatus" class="status-badge"></span></div>
                            </div>
                            <div class="app-modal-detail-row" id="viewGradeLevelRow">
                                <div class="app-modal-detail-label">Grade Level</div>
                                <div class="app-modal-detail-value" id="viewGradeLevel"></div>
                            </div>
                            <div class="app-modal-detail-row" id="viewSectionRow">
                                <div class="app-modal-detail-label">Section</div>
                                <div class="app-modal-detail-value" id="viewSection"></div>
                            </div>
                            <div class="app-modal-detail-row" id="viewTrackRow">
                                <div class="app-modal-detail-label">Track</div>
                                <div class="app-modal-detail-value" id="viewTrack"></div>
                            </div>
                            <div class="app-modal-detail-row" id="viewAssignedClassesRow">
                                <div class="app-modal-detail-label" id="viewAssignedLabel">Assigned Subjects</div>
                                <div class="app-modal-detail-value" id="viewAssignedClasses"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Created At</div>
                                <div class="app-modal-detail-value" id="viewCreatedAt"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Last Login</div>
                                <div class="app-modal-detail-value" id="viewLastLogin"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Update</div>
                        <h5 class="modal-title mb-0">Edit User</h5>
                        <p class="app-modal-subtitle">Update profile details and assignments.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="editUserForm">
                        <input type="hidden" name="user_id" id="editUserId">
                        <div class="app-modal-stack">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-sliders2"></i>Account Summary</div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Reference Code</label>
                                        <input type="text" id="editReferenceCode" class="form-control" readonly style="background-color: #f8f9fa;">
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label">Role</label>
                                        <input type="text" name="role" id="editRole" class="form-control" readonly style="background-color: #f8f9fa;">
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-md-4 mb-3" id="editGradeLevelDiv" style="display: none;">
                                        <label class="form-label" id="editGradeLevelLabel">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="editGradeLevel" class="form-select" onchange="editUpdateSections()">
                                            <option value="">Select Grade Level</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3" id="editTrackDiv" style="display: none;">
                                        <label class="form-label" id="editTrackLabel">Track <span class="text-danger">*</span></label>
                                        <select name="track" id="editTrack" class="form-select" onchange="editUpdateSections()">
                                            <option value="">Select Track</option>
                                            <option value="academic">Academic</option>
                                            <option value="techpro">TechPro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-0" id="editSectionDiv" style="display: none;">
                                        <label class="form-label" id="editSectionLabel">Section <span class="text-danger">*</span></label>
                                        <select name="section" id="editSection" class="form-select" disabled onchange="editUpdateClassAvailabilityWarning()">
                                            <option value="">Select Grade & Track First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Subject/Class Selection (Teacher only) -->
                            <div class="app-modal-panel mb-0" id="editClassDiv" style="display: none;">
                                <div class="app-modal-panel-title"><i class="bi bi-journal-check"></i>Teacher Subject Assignment</div>
                                <div class="class-checklist" id="editClassChecklist">
                                <?php 
                                // Fetch classes for edit checklist - show ALL classes without filtering
                                $classQuery2 = "SELECT id, class_name, grade_level, section, schedule FROM classes WHERE status = 'active' ORDER BY grade_level, section, class_name";
                                $classStmt2 = $db->query($classQuery2);
                                $classesList2 = $classStmt2->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($classesList2 as $class): 
                                    $scheduleDisplay = $class['schedule'] ? htmlspecialchars($class['schedule']) : 'No schedule set';
                                ?>
                                <div class="form-check class-check-item">
                                    <input class="form-check-input edit-class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" id="edit_class_<?php echo $class['id']; ?>">
                                    <label class="form-check-label" for="edit_class_<?php echo $class['id']; ?>">
                                        <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                        <small class="text-muted d-block"><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?><?php if (!empty($class['grade_level'])): ?>Grade <?php echo htmlspecialchars((string)$class['grade_level']); ?><?php endif; ?><?php if (!empty($class['grade_level']) && !empty($class['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($class['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></small>
                                        <small class="text-info d-block"><i class="bi bi-clock me-1"></i><?php echo $scheduleDisplay; ?></small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <div class="form-text text-muted">Select subjects now or transfer a subject if needed.</div>
                            </div>
                            <div class="app-modal-panel mb-0" id="editParentStudentDiv" style="display: none;">
                                <div class="app-modal-panel-title"><i class="bi bi-people"></i>Parent Link</div>
                                <label class="form-label">Link Student(s) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm mb-2">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="editParentStudentSearch" placeholder="Type student name or reference code to filter..." oninput="filterParentStudentList('editParentStudentSearch', '#editParentStudentChecklist .class-check-item')">
                                    <button class="btn btn-outline-secondary" type="button" id="clearEditParentStudentSearchBtn" style="display:none;" onclick="clearParentSearch('editParentStudentSearch', '#editParentStudentChecklist .class-check-item', 'clearEditParentStudentSearchBtn')"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <div class="class-checklist" id="editParentStudentChecklist">
                                <?php foreach ($studentsForParent as $student): ?>
                                <div class="form-check class-check-item" data-student-search="<?php echo htmlspecialchars(strtolower($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['reference_code'] ?? ''))); ?>">
                                    <input class="form-check-input edit-parent-student-checkbox" type="checkbox" name="linked_student_ids[]" value="<?php echo (int)$student['id']; ?>" id="edit_parent_student_<?php echo (int)$student['id']; ?>">
                                    <label class="form-check-label" for="edit_parent_student_<?php echo (int)$student['id']; ?>">
                                        <strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo !empty($student['reference_code']) ? htmlspecialchars((string)$student['reference_code']) : 'No reference code'; ?></small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                </div>
                                <div class="form-text text-muted">Select at least 1 student.</div>
                            </div>

                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>Profile Details</div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" id="editFirstName" class="form-control" required oninput="capitalizeWords(this)">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Middle Name</label>
                                        <input type="text" name="middle_name" id="editMiddleName" class="form-control" oninput="capitalizeWords(this)">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" id="editLastName" class="form-control" required oninput="capitalizeWords(this)">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Sex <span class="text-danger">*</span></label>
                                        <select name="sex" id="editSex" class="form-select" required>
                                            <option value="">Select Sex</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Number (Mobile)</label>
                                        <input type="text" name="contact_number" id="editContactNumber" class="form-control" placeholder="09XXXXXXXXX" maxlength="20" autocomplete="tel">
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-note d-none" id="editClassAvailabilityWarning">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong>No matching classes yet</strong>
                                    <p>No active class was found for the selected grade level and section.</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-warning" onclick="resetUserPassword()">
                        <i class="bi bi-key me-2"></i>Reset Password
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateUserBtn" onclick="updateUser()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Next reference codes from PHP
        const nextRefCodes = <?php echo json_encode($nextRefCodes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const availableClassCounts = <?php echo json_encode($availableClassCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const takenTeacherAdvisories = <?php echo json_encode($takenTeacherAdvisories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const takenSubjectTeachers = <?php echo json_encode($takenSubjectTeachers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const serverSections = <?php echo json_encode($sectionRowsForForms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
        let currentEditUserId = 0;
        let sectionsCache = {};


        function updateReferenceCode(role) {
            const refInput = document.getElementById('referenceCode');
            if (!refInput) return;
            const r = role || document.getElementById('userRole')?.value || '';
            if (nextRefCodes[r]) {
                refInput.value = nextRefCodes[r];
            } else {
                refInput.value = '';
            }
        }


        function getAvailableClassCount(gradeLevel, section) {
            if (!gradeLevel || !section) return 0;
            return parseInt(availableClassCounts[`${gradeLevel}|${section}`] || 0, 10);
        }

        function isTakenTeacherAdvisory(gradeLevel, sectionValue, excludeTeacherId = 0) {
            if (!gradeLevel || !sectionValue) return false;
            const key = `${gradeLevel}|${String(sectionValue).trim().toLowerCase()}`;
            const ownerId = parseInt(takenTeacherAdvisories[key] || 0, 10);
            if (!ownerId) return false;
            return ownerId !== parseInt(excludeTeacherId || 0, 10);
        }

        function isSubjectTakenByOtherTeacher(classId, excludeTeacherId = 0) {
            const owners = takenSubjectTeachers[String(classId)] || takenSubjectTeachers[classId] || [];
            if (!Array.isArray(owners) || owners.length === 0) return false;
            const exclude = parseInt(excludeTeacherId || 0, 10);
            return owners.some(ownerId => parseInt(ownerId, 10) !== exclude);
        }

        function refreshSubjectCheckboxAvailability(selector, excludeTeacherId = 0) {
            document.querySelectorAll(selector).forEach(cb => {
                const blocked = isSubjectTakenByOtherTeacher(cb.value, excludeTeacherId);
                cb.disabled = blocked;
                if (blocked) cb.checked = false;
                cb.title = blocked ? 'Already assigned to another teacher' : '';
                const item = cb.closest('.class-check-item');
                if (item) item.classList.toggle('opacity-50', blocked);
            });
        }

        function onRoleChange(roleSelect) {
            const role = roleSelect?.value || '';
            const gradeLevelDropdown = document.getElementById('gradeLevelDropdown');
            const sectionDropdown = document.getElementById('sectionDropdown');
            const classDropdown = document.getElementById('classDropdown');
            const parentStudentDropdown = document.getElementById('parentStudentDropdown');
            const classLabel = document.getElementById('classLabel');
            const classHelpText = document.getElementById('classHelpText');
            const gradeLevelLabel = document.getElementById('gradeLevelLabel');
            const sectionLabel = document.getElementById('sectionLabel');
            const gradeLevelInput = document.getElementById('gradeLevel');
            const sectionInput = document.getElementById('sectionSelect');
            const checkboxes = document.querySelectorAll('.class-checkbox');
            const parentStudentChecks = document.querySelectorAll('.parent-student-checkbox');
            const trackDropdownCreate = document.getElementById('trackDropdownCreate');
            const trackCreate = document.getElementById('trackCreate');
            const trackLabelCreate = document.getElementById('trackLabelCreate');
            
            // Show fields for teachers
            if (role === 'teacher') {
                if (gradeLevelDropdown) gradeLevelDropdown.style.display = 'block';
                if (sectionDropdown) sectionDropdown.style.display = 'block';
                if (trackDropdownCreate) trackDropdownCreate.style.display = 'none';
                if (classDropdown) classDropdown.style.display = 'block';
                if (parentStudentDropdown) parentStudentDropdown.style.display = 'none';
                if (gradeLevelLabel) gradeLevelLabel.innerHTML = 'Advisory Grade Level (Optional)';
                if (sectionLabel) sectionLabel.innerHTML = 'Advisory Section (Optional)';
                if (gradeLevelInput) gradeLevelInput.required = false;
                if (sectionInput) sectionInput.required = false;
                if (classLabel) classLabel.innerHTML = 'Assign to Subject(s) (Optional)';
                if (classHelpText) classHelpText.textContent = 'Select subjects now or transfer a subject if needed.';
                checkboxes.forEach(cb => { cb.onclick = null; });
                refreshSubjectCheckboxAvailability('.class-checkbox', 0);
                parentStudentChecks.forEach(cb => cb.checked = false);
            } else if (role === 'parent') {
                if (gradeLevelDropdown) gradeLevelDropdown.style.display = 'none';
                if (sectionDropdown) sectionDropdown.style.display = 'none';
                if (classDropdown) classDropdown.style.display = 'none';
                if (parentStudentDropdown) parentStudentDropdown.style.display = 'block';
                if (trackDropdownCreate) trackDropdownCreate.style.display = 'none';
                if (trackCreate) { trackCreate.required = false; trackCreate.value = ''; }
                if (gradeLevelInput) gradeLevelInput.value = '';
                if (sectionInput) sectionInput.value = '';
                if (gradeLevelInput) gradeLevelInput.required = false;
                if (sectionInput) sectionInput.required = false;
                checkboxes.forEach(cb => cb.checked = false);
            } else {
                if (gradeLevelDropdown) gradeLevelDropdown.style.display = 'none';
                if (sectionDropdown) sectionDropdown.style.display = 'none';
                if (classDropdown) classDropdown.style.display = 'none';
                if (parentStudentDropdown) parentStudentDropdown.style.display = 'none';
                if (trackDropdownCreate) trackDropdownCreate.style.display = 'none';
                if (trackCreate) { trackCreate.required = false; trackCreate.value = ''; }
                if (gradeLevelInput) gradeLevelInput.value = '';
                if (sectionInput) sectionInput.value = '';
                if (gradeLevelInput) gradeLevelInput.required = false;
                if (sectionInput) sectionInput.required = false;
                checkboxes.forEach(cb => cb.checked = false);
                parentStudentChecks.forEach(cb => cb.checked = false);
            }

            updateClassAvailabilityWarning();
        }

        function filterParentStudentList(inputId, itemSelector) {
            const input = document.getElementById(inputId);
            const term = (input?.value || '').trim().toLowerCase();
            const btnId = inputId === 'parentStudentSearch' ? 'clearParentStudentSearchBtn' : 'clearEditParentStudentSearchBtn';
            const clearBtn = document.getElementById(btnId);
            if (clearBtn) clearBtn.style.display = term ? 'block' : 'none';
            document.querySelectorAll(itemSelector).forEach(item => {
                const searchData = (item.getAttribute('data-student-search') || item.textContent || '').toLowerCase();
                item.style.display = (!term || searchData.includes(term)) ? '' : 'none';
            });
        }

        function clearParentSearch(inputId, itemSelector, btnId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = '';
                input.focus();
            }
            const btn = document.getElementById(btnId);
            if (btn) btn.style.display = 'none';
            document.querySelectorAll(itemSelector).forEach(item => {
                item.style.display = '';
            });
        }

        function hydrateSectionsCache(sections) {
            const nextCache = {};
            (sections || []).forEach(s => {
                const name = (s.name || '').toString().trim();
                const gradeLevel = (s.grade_level || '').toString().trim();
                const track = (s.track || '').toString().trim();

                if (!name || !gradeLevel) {
                    return;
                }

                const key = gradeLevel + '_' + track;
                if (!nextCache[key]) {
                    nextCache[key] = [];
                }
                nextCache[key].push({ value: name, label: name });
            });

            sectionsCache = nextCache;
            return sectionsCache;
        }

        function loadSectionsCache() {
            return fetch('admin_Sections_Action.php?action=list')
                .then(r => r.json())
                .then(data => {
                    if (data.success && Array.isArray(data.sections)) {
                        return hydrateSectionsCache(data.sections);
                    }
                    return sectionsCache;
                })
                .catch(() => sectionsCache);
        }

        function getSectionsFor(grade, track) {
            if (track) return sectionsCache[grade + '_' + track] || [];
            // No track specified — return all sections for this grade level
            const all = [];
            Object.keys(sectionsCache).forEach(key => {
                if (key.startsWith(grade + '_')) {
                    all.push(...sectionsCache[key]);
                }
            });
            return all;
        }

        function deriveTrackFromSection(gradeLevel, sectionValue) {
            if (!gradeLevel || !sectionValue) return '';
            for (const key in sectionsCache) {
                if (key.startsWith(String(gradeLevel) + '_')) {
                    if (sectionsCache[key].some(item => item.value === sectionValue)) {
                        return key.split('_')[1];
                    }
                }
            }
            return '';
        }

        function updateSections() {
            const gradeLevel = document.getElementById('gradeLevel')?.value || '';
            const sectionSelect = document.getElementById('sectionSelect');
            const role = document.getElementById('userRole')?.value || '';

            if (!sectionSelect) {
                return;
            }

            sectionSelect.innerHTML = '<option value="">Select Section</option>';

            if (gradeLevel) {
                const list = role === 'teacher'
                    ? getSectionsFor(gradeLevel, '')
                    : getSectionsFor(gradeLevel, document.getElementById('trackCreate')?.value || '');
                sectionSelect.disabled = !list.length;
                list.forEach(section => {
                    if (role === 'teacher' && isTakenTeacherAdvisory(gradeLevel, section.value, 0)) {
                        return;
                    }
                    const option = document.createElement('option');
                    option.value = section.value;
                    option.textContent = section.label;
                    sectionSelect.appendChild(option);
                });
                if (!list.length) {
                    sectionSelect.innerHTML = '<option value="">No sections available</option>';
                }
            } else {
                sectionSelect.disabled = true;
            }
            updateClassAvailabilityWarning();
        }

        function updateClassAvailabilityWarning() {
            const warning = document.getElementById('classAvailabilityWarning');
            const createBtn = document.getElementById('createUserBtn');
            if (!warning) return;
            warning.classList.add('d-none');
            if (createBtn) createBtn.disabled = false;
        }
        
        function createUser() {
            const form = document.getElementById('addUserForm');
            const formData = new FormData(form);
            
            // Validate required fields
            const role = formData.get('role');
            const sex = (formData.get('sex') || '').toString().trim();
            const gradeLevel = formData.get('grade_level');
            const section = formData.get('section');
            const classIds = formData.getAll('class_ids[]');
            const linkedStudentIds = formData.getAll('linked_student_ids[]');
            
            if (!role) {
                showNotification('Please select a role', 'warning');
                return;
            }
            if (!sex) {
                showNotification('Please select sex', 'warning');
                return;
            }

            // Fallback: ensure reference code is always set from selected role.
            const fallbackRefCode = nextRefCodes[role] || '';
            const currentRefCode = (formData.get('reference_code') || '').toString().trim();
            if (!currentRefCode && fallbackRefCode) {
                formData.set('reference_code', fallbackRefCode);
                const refCodeInput = document.getElementById('referenceCode');
                if (refCodeInput) refCodeInput.value = fallbackRefCode;
            }
            
            if (role === 'teacher') {
                if ((gradeLevel && !section) || (!gradeLevel && section)) {
                    showNotification('For advisory assignment, select both grade level and section', 'warning');
                    return;
                }
            }
            if (role === 'parent' && linkedStudentIds.length < 1) {
                showNotification('Please link at least 1 student to parent', 'warning');
                return;
            }

            // Auto-derive track from advisory section for teachers
            if (role === 'teacher' && section) {
                const derivedTrack = deriveTrackFromSection(gradeLevel, section);
                if (derivedTrack) formData.set('track', derivedTrack);
            }

            appendCsrfToFormData(formData);
            fetch('admin_Users_Action.php?action=create', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('User created successfully', 'success');
                    const addModalEl = document.getElementById('addUserModal');
                    const modal = (typeof bootstrap !== 'undefined' && bootstrap.Modal && addModalEl) ? bootstrap.Modal.getInstance(addModalEl) : null;
                    if (modal) modal.hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to create user', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error creating user', 'danger');
                console.error(error);
            });
        }
        
        function capitalizeWords(input) {
            input.value = input.value.replace(/\b\w/g, char => char.toUpperCase());
        }

        function resetCreateUserModal() {
            const form = document.getElementById('addUserForm');
            if (form) form.reset();

            const roleInput = document.getElementById('userRole');
            const refCodeInput = document.getElementById('referenceCode');
            const gradeLevel = document.getElementById('gradeLevel');
            const sectionSelect = document.getElementById('sectionSelect');
            const trackCreate = document.getElementById('trackCreate');

            if (roleInput) roleInput.value = '';
            if (refCodeInput) refCodeInput.value = '';
            if (gradeLevel) gradeLevel.value = '';
            if (trackCreate) trackCreate.value = '';
            if (sectionSelect) {
                sectionSelect.innerHTML = '<option value="">Select Grade Level First</option>';
                sectionSelect.disabled = true;
            }

            document.querySelectorAll('.class-checkbox').forEach(cb => { cb.checked = false; });
            document.querySelectorAll('.parent-student-checkbox').forEach(cb => { cb.checked = false; });
            refreshSubjectCheckboxAvailability('.class-checkbox', 0);
            updateReferenceCode();
            toggleClassDropdown();
            updateClassAvailabilityWarning();
        }

        function resetEditUserModal() {
            const form = document.getElementById('editUserForm');
            if (form) form.reset();
            currentEditUserId = 0;

            const sectionSelect = document.getElementById('editSection');
            if (sectionSelect) {
                sectionSelect.innerHTML = '<option value="">Select Grade Level First</option>';
                sectionSelect.disabled = true;
            }

            document.querySelectorAll('.edit-class-checkbox').forEach(cb => { cb.checked = false; });
            document.querySelectorAll('.edit-parent-student-checkbox').forEach(cb => { cb.checked = false; });
            refreshSubjectCheckboxAvailability('.edit-class-checkbox', 0);

            const gradeLevelDiv = document.getElementById('editGradeLevelDiv');
            const sectionDiv = document.getElementById('editSectionDiv');
            const classDiv = document.getElementById('editClassDiv');
            const parentStudentDiv = document.getElementById('editParentStudentDiv');
            const editTrackDiv = document.getElementById('editTrackDiv');
            if (gradeLevelDiv) gradeLevelDiv.style.display = 'none';
            if (sectionDiv) sectionDiv.style.display = 'none';
            if (classDiv) classDiv.style.display = 'none';
            if (parentStudentDiv) parentStudentDiv.style.display = 'none';
            if (editTrackDiv) editTrackDiv.style.display = 'none';
        }
        
        function getBootstrapModalInstance(modalId) {
            const modalEl = document.getElementById(modalId);
            if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return null;
            }
            return bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        
        // Edit User Modal Instance
        // Edit User Modal Instance
        let editUserModal = null;

        function editUser(id) {
            // Fetch user data
            resetEditUserModal();
            editUserModal = getBootstrapModalInstance('editUserModal');
            if (editUserModal) {
                editUserModal.show();
            }
            fetch('admin_Users_Action.php?action=get&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        currentEditUserId = parseInt(user.id || 0, 10);
                        const editUserId = document.getElementById('editUserId');
                        const editReferenceCode = document.getElementById('editReferenceCode');
                        const editRole = document.getElementById('editRole');
                        const editFirstName = document.getElementById('editFirstName');
                        const editMiddleName = document.getElementById('editMiddleName');
                        const editLastName = document.getElementById('editLastName');
                        const editSex = document.getElementById('editSex');
                        const editContactNumber = document.getElementById('editContactNumber');
                        const editGradeLevel = document.getElementById('editGradeLevel');
                        const editSection = document.getElementById('editSection');

                        if (editUserId) editUserId.value = user.id;
                        if (editReferenceCode) editReferenceCode.value = user.reference_code || '';
                        if (editRole) editRole.value = user.role || '';
                        if (editFirstName) editFirstName.value = user.first_name || '';
                        if (editMiddleName) editMiddleName.value = user.middle_name || '';
                        if (editLastName) editLastName.value = user.last_name || '';
                        if (editSex) editSex.value = (user.sex || '').toString().toLowerCase();
                        if (editContactNumber) editContactNumber.value = user.contact_number || '';

                        if (user.grade_level && editGradeLevel) {
                            editGradeLevel.value = user.grade_level;
                            editUpdateSections();
                            if (user.section && editSection) {
                                editUpdateClassAvailabilityWarning();
                            }
                        }

                        const role = user.role;
                        const gradeLevelDiv = document.getElementById('editGradeLevelDiv');
                        const sectionDiv = document.getElementById('editSectionDiv');
                        const classDiv = document.getElementById('editClassDiv');
                        const parentStudentDiv = document.getElementById('editParentStudentDiv');
                        const editTrackDiv = document.getElementById('editTrackDiv');
                        const editTrackLabel = document.getElementById('editTrackLabel');
                        const editGradeLevelLabel = document.getElementById('editGradeLevelLabel');
                        const editSectionLabel = document.getElementById('editSectionLabel');
                        const classLabel = document.getElementById('editClassLabel');
                        const checkboxes = document.querySelectorAll('.edit-class-checkbox');
                        const parentStudentChecks = document.querySelectorAll('.edit-parent-student-checkbox');

                        if (role === 'teacher' || role === 'student') {
                            if (gradeLevelDiv) gradeLevelDiv.style.display = 'block';
                            if (sectionDiv) sectionDiv.style.display = 'block';
                            if (editTrackDiv) editTrackDiv.style.display = role === 'student' ? 'block' : 'none';
                            if (editTrack) editTrack.value = user.track || '';
                            if (role === 'teacher') {
                                if (classDiv) classDiv.style.display = 'block';
                                if (parentStudentDiv) parentStudentDiv.style.display = 'none';
                                if (editGradeLevelLabel) editGradeLevelLabel.innerHTML = 'Advisory Grade Level (Optional)';
                                if (editSectionLabel) editSectionLabel.innerHTML = 'Advisory Section (Optional)';
                                if (classLabel) classLabel.innerHTML = 'Assign to Subject(s) (Optional)';
                                if (editGradeLevel) editGradeLevel.required = false;
                                if (editSection) editSection.required = false;
                                checkboxes.forEach(cb => { cb.onclick = null; });
                                refreshSubjectCheckboxAvailability('.edit-class-checkbox', currentEditUserId);
                                const assignedClassIds = Array.isArray(user.assigned_class_ids) ? user.assigned_class_ids.map(id => parseInt(id, 10)) : [];
                                checkboxes.forEach(cb => {
                                    cb.checked = assignedClassIds.includes(parseInt(cb.value, 10));
                                });
                                parentStudentChecks.forEach(cb => cb.checked = false);
                            } else {
                                if (classDiv) classDiv.style.display = 'none';
                                if (parentStudentDiv) parentStudentDiv.style.display = 'none';
                                if (editGradeLevelLabel) editGradeLevelLabel.innerHTML = 'Grade Level <span class="text-danger">*</span>';
                                if (editSectionLabel) editSectionLabel.innerHTML = 'Section <span class="text-danger">*</span>';
                                if (editTrackLabel) editTrackLabel.innerHTML = 'Track <span class="text-danger">*</span>';
                                if (editTrack) editTrack.required = true;
                                if (editGradeLevel) editGradeLevel.required = true;
                                if (editSection) editSection.required = true;
                                checkboxes.forEach(cb => cb.checked = false);
                                parentStudentChecks.forEach(cb => cb.checked = false);
                            }
                        } else if (role === 'parent') {
                            if (gradeLevelDiv) gradeLevelDiv.style.display = 'none';
                            if (sectionDiv) sectionDiv.style.display = 'none';
                            if (classDiv) classDiv.style.display = 'none';
                            if (parentStudentDiv) parentStudentDiv.style.display = 'block';
                            if (editTrackDiv) editTrackDiv.style.display = 'none';
                            if (editTrack) { editTrack.required = false; editTrack.value = ''; }
                            if (editGradeLevel) editGradeLevel.value = '';
                            if (editSection) editSection.value = '';
                            if (editGradeLevel) editGradeLevel.required = false;
                            if (editSection) editSection.required = false;
                            checkboxes.forEach(cb => cb.checked = false);
                            parentStudentChecks.forEach(cb => cb.checked = false);
                            if (Array.isArray(user.linked_student_ids)) {
                                parentStudentChecks.forEach(cb => {
                                    cb.checked = user.linked_student_ids.includes(parseInt(cb.value, 10));
                                });
                            }
                        } else {
                            if (gradeLevelDiv) gradeLevelDiv.style.display = 'none';
                            if (sectionDiv) sectionDiv.style.display = 'none';
                            if (classDiv) classDiv.style.display = 'none';
                            if (parentStudentDiv) parentStudentDiv.style.display = 'none';
                            if (editTrackDiv) editTrackDiv.style.display = 'none';
                            if (editTrack) { editTrack.required = false; editTrack.value = ''; }
                            if (editGradeLevel) editGradeLevel.value = '';
                            if (editSection) editSection.value = '';
                            if (editGradeLevel) editGradeLevel.required = false;
                            if (editSection) editSection.required = false;
                            checkboxes.forEach(cb => cb.checked = false);
                            parentStudentChecks.forEach(cb => cb.checked = false);
                        }
                        editUpdateClassAvailabilityWarning();
                        editUserModal = getBootstrapModalInstance('editUserModal');
                        if (editUserModal) {
                            editUserModal.show();
                        }
                    } else {
                        showNotification(data.message || 'Failed to load user data', 'danger');
                    }
                })
                .catch(error => {
                    showNotification('Error loading user data', 'danger');
                    console.error(error);
                });
        }

        window.editUser = editUser;
        function editUpdateSections() {
            const gradeLevel = document.getElementById('editGradeLevel')?.value || '';
            const sectionSelect = document.getElementById('editSection');
            if (!sectionSelect) {
                return;
            }
            const previousSection = sectionSelect.value;
            const role = document.getElementById('editRole')?.value || '';

            sectionSelect.innerHTML = '<option value="">Select Section</option>';

            if (gradeLevel) {
                const list = role === 'teacher'
                    ? getSectionsFor(gradeLevel, '')
                    : getSectionsFor(gradeLevel, document.getElementById('editTrack')?.value || '');
                sectionSelect.disabled = !list.length;
                list.forEach(section => {
                    if (role === 'teacher' && isTakenTeacherAdvisory(gradeLevel, section.value, currentEditUserId)) {
                        return;
                    }
                    const option = document.createElement('option');
                    option.value = section.value;
                    option.textContent = section.label;
                    sectionSelect.appendChild(option);
                });
                // Restore previous section if it exists
                if (previousSection) {
                    const exists = Array.from(sectionSelect.options).some(opt => opt.value === previousSection);
                    if (exists) sectionSelect.value = previousSection;
                }
                if (!list.length) {
                    sectionSelect.innerHTML = '<option value="">No sections available</option>';
                }
            }
            editUpdateClassAvailabilityWarning();
        }

        function editUpdateClassAvailabilityWarning() {
            const warning = document.getElementById('editClassAvailabilityWarning');
            const updateBtn = document.getElementById('updateUserBtn');
            if (!warning) return;
            warning.classList.add('d-none');
            if (updateBtn) updateBtn.disabled = false;
        }

        function getSectionLabel(gradeLevel, sectionValue) {
            if (!gradeLevel || !sectionValue) return sectionValue || 'N/A';
            for (const key in sectionsCache) {
                const list = sectionsCache[key];
                if (key.startsWith(String(gradeLevel) + '_')) {
                    const match = list.find(item => item.value === sectionValue);
                    if (match) return match.label;
                }
            }
            return sectionValue;
        }

        function csvEscape(value) {
            const text = String(value ?? '').replace(/"/g, '""');
            return `"${text}"`;
        }


        function getUsersExportData() {
            const table = document.querySelector('.custom-table');
            if (!table) return { headers: [], rows: [] };

            const headerEls = Array.from(table.querySelectorAll('thead th'));
            const includedIndexes = [];
            const headers = [];
            headerEls.forEach((th, idx) => {
                const name = (th.textContent || '').trim();
                if (name.toLowerCase() === 'actions') return;
                includedIndexes.push(idx);
                headers.push(name);
            });

            const rows = Array.from(table.querySelectorAll('tbody tr'))
                .filter(tr => tr.querySelectorAll('td').length)
                .map(tr => {
                    const cells = Array.from(tr.querySelectorAll('td'));
                    return includedIndexes.map(i => (cells[i]?.textContent || '').replace(/\s+/g, ' ').trim());
                });

            return { headers, rows };
        }

        function exportToExcel() {
            const { headers, rows } = getUsersExportData();
            if (!rows.length) {
                showNotification('No user data to export.', 'warning');
                return;
            }

            const lines = [headers.map(csvEscape).join(',')];
            rows.forEach(row => lines.push(row.map(csvEscape).join(',')));
            const csv = '\uFEFF' + lines.join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);

            const dateTag = new Date().toISOString().slice(0, 10);
            const a = document.createElement('a');
            a.href = url;
            a.download = `users_export_${dateTag}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showNotification('Users export downloaded.', 'success');
        }

        function exportToPDF() {
            const { headers, rows } = getUsersExportData();
            if (!rows.length) {
                showNotification('No user data to export.', 'warning');
                return;
            }

            const selectedRole = document.querySelector('select[name="role"] option:checked')?.textContent?.trim() || 'All Roles';
            const selectedStatus = document.querySelector('select[name="status"] option:checked')?.textContent?.trim() || 'All Status';
            const search = document.querySelector('input[name="search"]')?.value?.trim() || 'None';
            const generatedAt = new Date().toLocaleString();

            const headerHtml = headers.map(h => `<th>${escapeHtml(h)}</th>`).join('');
            const rowsHtml = rows.map(row => `<tr>${row.map(v => `<td>${escapeHtml(v)}</td>`).join('')}</tr>`).join('');

            const printWindow = window.open('', '_blank', 'width=1024,height=768');
            if (!printWindow) {
                showNotification('Popup blocked. Please allow popups to export PDF.', 'warning');
                return;
            }

            printWindow.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Users Report</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; color: #111; }
    h1 { font-size: 20px; margin: 0 0 6px; }
    p { margin: 3px 0; font-size: 12px; color: #444; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; }
  </style>
</head>
<body>
  <h1>Users Report</h1>
  <p><strong>Role:</strong> ${escapeHtml(selectedRole)}</p>
  <p><strong>Status:</strong> ${escapeHtml(selectedStatus)}</p>
  <p><strong>Search:</strong> ${escapeHtml(search)}</p>
  <p><strong>Generated:</strong> ${escapeHtml(generatedAt)}</p>
  <table>
    <thead><tr>${headerHtml}</tr></thead>
    <tbody>${rowsHtml}</tbody>
  </table>
</body>
</html>`);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
            }, 250);
        }
        
        function updateUser() {
            const form = document.getElementById('editUserForm');
            if (!form) {
                showNotification('Edit form is not available', 'danger');
                return;
            }
            const formData = new FormData(form);
            
            // Validate
            const firstName = formData.get('first_name');
            const lastName = formData.get('last_name');
            const sex = (formData.get('sex') || '').toString().trim();
            const role = document.getElementById('editRole')?.value || '';
            const gradeLevel = formData.get('grade_level');
            const section = formData.get('section');
            const linkedStudentIds = formData.getAll('linked_student_ids[]');
            
            if (!firstName || !lastName) {
                showNotification('Please fill in all required fields', 'warning');
                return;
            }
            if (!sex) {
                showNotification('Please select sex', 'warning');
                return;
            }
            if (role === 'teacher' && ((gradeLevel && !section) || (!gradeLevel && section))) {
                showNotification('For advisory assignment, select both grade level and section', 'warning');
                return;
            }
            if (role === 'parent' && linkedStudentIds.length < 1) {
                showNotification('Please link at least 1 student to parent', 'warning');
                return;
            }

            // Auto-derive track from advisory section for teachers
            if (role === 'teacher' && section) {
                const derivedTrack = deriveTrackFromSection(gradeLevel, section);
                if (derivedTrack) formData.set('track', derivedTrack);
            }

            appendCsrfToFormData(formData);
            fetch('admin_Users_Action.php?action=update', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('User updated successfully', 'success');
                    const modal = getBootstrapModalInstance('editUserModal');
                    if (modal) modal.hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to update user', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error updating user', 'danger');
                console.error(error);
                });
        }

        window.updateUser = updateUser;
        function resetUserPassword() {
            const userId = parseInt(document.getElementById('editUserId')?.value || '0', 10);
            if (!userId) {
                showNotification('Invalid user ID', 'warning');
                return;
            }
            const runPasswordReset = function() {
                const params = new URLSearchParams();
                params.set('user_id', String(userId));
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Users_Action.php?action=reset_password', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Password reset successfully', 'success');
                } else {
                    showNotification(data.message || 'Failed to reset password', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error resetting password', 'danger');
                console.error(error);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runPasswordReset();
                return;
            }
            showAppConfirm({
                title: 'Reset this user password?',
                subtitle: 'The account will use the configured default password.',
                message: 'Share the new default password securely with the user after resetting it.',
                confirmText: 'Reset password',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'bi-key-fill'
            }).then((confirmed) => {
                if (confirmed) runPasswordReset();
            });
        }
        window.resetUserPassword = resetUserPassword;
        // View User Modal Instance
        // View User Modal Instance
        let viewUserModal = null;

        function viewUser(id) {
            fetch('admin_Users_Action.php?action=get&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        const fullName = [user.first_name, user.middle_name, user.last_name]
                            .filter(part => part && String(part).trim() !== '')
                            .join(' ');
                        const firstInitial = (user.first_name || 'U').charAt(0);
                        const lastInitial = (user.last_name || 'N').charAt(0);
                        const initials = (firstInitial + lastInitial).toUpperCase();

                        const avatarDiv = document.getElementById('viewUserAvatar');
                        avatarDiv.textContent = initials;
                        avatarDiv.style.background = user.role === 'admin' ? 'var(--secondary-color)' :
                                                   (user.role === 'teacher' ? 'var(--accent-color)' : 'var(--danger-color)');

                        document.getElementById('viewUserName').textContent = fullName;
                        document.getElementById('viewUserRole').textContent = user.role.charAt(0).toUpperCase() + user.role.slice(1);
                        document.getElementById('viewUserRole').className = 'role-badge role-' + user.role;
                        document.getElementById('viewReferenceCode').textContent = user.reference_code;

                        document.getElementById('viewFullName').textContent = fullName;
                        document.getElementById('viewEmail').textContent = user.email;
                        document.getElementById('viewSex').textContent = user.sex ? (user.sex.charAt(0).toUpperCase() + user.sex.slice(1)) : 'N/A';
                        document.getElementById('viewStatus').textContent = user.status.charAt(0).toUpperCase() + user.status.slice(1);
                        document.getElementById('viewStatus').className = 'status-badge status-' + user.status;
                        document.getElementById('viewCreatedAt').textContent = new Date(user.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        document.getElementById('viewLastLogin').textContent = user.last_login ?
                            new Date(user.last_login).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Never';

                        const gradeLevelRow = document.getElementById('viewGradeLevelRow');
                        const sectionRow = document.getElementById('viewSectionRow');
                        const trackRow = document.getElementById('viewTrackRow');
                        const classesRow = document.getElementById('viewAssignedClassesRow');

                        if (user.role === 'teacher') {
                            gradeLevelRow.style.display = 'flex';
                            sectionRow.style.display = 'flex';
                            trackRow.style.display = 'none';
                            document.getElementById('viewGradeLevel').textContent = user.grade_level ? ('Grade ' + user.grade_level) : 'N/A';
                            document.getElementById('viewSection').textContent = getSectionLabel(user.grade_level, user.section);
                            document.getElementById('viewTrack').textContent = user.track ? (user.track.charAt(0).toUpperCase() + user.track.slice(1)) : 'N/A';
                        } else if (user.role === 'student') {
                            gradeLevelRow.style.display = 'flex';
                            sectionRow.style.display = 'flex';
                            trackRow.style.display = 'flex';
                            document.getElementById('viewGradeLevel').textContent = user.grade_level ? ('Grade ' + user.grade_level) : 'N/A';
                            document.getElementById('viewSection').textContent = getSectionLabel(user.grade_level, user.section);
                            document.getElementById('viewTrack').textContent = user.track ? (user.track.charAt(0).toUpperCase() + user.track.slice(1)) : 'N/A';
                        } else {
                            gradeLevelRow.style.display = 'none';
                            sectionRow.style.display = 'none';
                            trackRow.style.display = 'none';
                        }

                        const assignedLabel = document.getElementById('viewAssignedLabel');
                        if ((user.role === 'teacher' || user.role === 'parent') && user.assigned_classes) {
                            classesRow.style.display = 'flex';
                            assignedLabel.textContent = user.role === 'teacher'
                                ? 'Assigned Subjects:'
                                : 'Linked Students:';
                            document.getElementById('viewAssignedClasses').innerHTML =
                                '<span class="badge bg-info text-dark">' + user.assigned_classes + '</span>';
                        } else {
                            classesRow.style.display = 'none';
                        }

                        viewUserModal = getBootstrapModalInstance('viewUserModal');
                        if (viewUserModal) {
                            viewUserModal.show();
                        }
                    } else {
                        showNotification(data.message || 'Failed to load user data', 'danger');
                    }
                })
                .catch(error => {
                    showNotification('Error loading user data', 'danger');
                    console.error(error);
                });
        }

        window.viewUser = viewUser;
        const addUserModalEl = document.getElementById('addUserModal');
        if (addUserModalEl) {
            addUserModalEl.addEventListener('show.bs.modal', resetCreateUserModal);
        }
        const addUserRoleEl = document.getElementById('userRole');
        if (addUserRoleEl) {
            const syncAddUserRoleState = function() { updateReferenceCode(this.value); toggleClassDropdown(); };
            addUserRoleEl.addEventListener('change', syncAddUserRoleState);
            addUserRoleEl.addEventListener('input', syncAddUserRoleState);
        }

        document.querySelectorAll('.js-view-user').forEach(btn => {
            btn.addEventListener('click', () => viewUser(parseInt(btn.dataset.userId, 10)));
        });
        document.querySelectorAll('.js-edit-user').forEach(btn => {
            btn.addEventListener('click', () => editUser(parseInt(btn.dataset.userId, 10)));
        });
        document.querySelectorAll('.js-toggle-status').forEach(btn => {
            btn.addEventListener('click', () => toggleUserStatus(parseInt(btn.dataset.userId, 10), btn.dataset.currentStatus || ''));
        });
        document.querySelectorAll('.js-delete-user').forEach(btn => {
            btn.addEventListener('click', () => deleteUser(parseInt(btn.dataset.userId, 10)));
        });
        const editUserModalEl = document.getElementById('editUserModal');
        if (editUserModalEl) {
            editUserModalEl.addEventListener('hidden.bs.modal', resetEditUserModal);
        }
        
        // Delete user - uses reusable modal
        function toggleUserStatus(id, currentStatus) {
            const nextStatus = String(currentStatus || '').toLowerCase() === 'active' ? 'inactive' : 'active';
            const actionLabel = nextStatus === 'active' ? 'activate' : 'archive';
            const runToggleStatus = function() {
                const params = new URLSearchParams();
                params.set('user_id', String(id));
                params.set('status', nextStatus);
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Users_Action.php?action=set_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'User status updated successfully', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to update user status', 'danger');
                }
            })
            .catch(error => {
                showNotification('Error updating user status', 'danger');
                console.error(error);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runToggleStatus();
                return;
            }
            showAppConfirm({
                title: `${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} this account?`,
                subtitle: 'This changes the account availability for the selected user.',
                message: `You are about to <strong>${actionLabel}</strong> this user account.`,
                confirmText: actionLabel === 'activate' ? 'Activate account' : 'Archive account',
                cancelText: 'Cancel',
                tone: nextStatus === 'active' ? 'primary' : 'danger',
                icon: nextStatus === 'active' ? 'bi-person-check-fill' : 'bi-person-dash-fill'
            }).then((confirmed) => {
                if (confirmed) runToggleStatus();
            });
        }

        window.toggleUserStatus = toggleUserStatus;
        function deleteUser(id) {
            showDeleteModal(id, 'User', 'user');
        }
        
        window.deleteUser = deleteUser;
        // Handle delete confirmation - called by reusable modal
        function handleDelete(id) {
            const params = new URLSearchParams();
            params.set('id', String(id));
            if (csrfToken) params.set('csrf_token', csrfToken);

            fetch('admin_Users_Action.php?action=delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'User deleted successfully', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification(data.message || 'Failed to delete user', 'danger');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting user', 'danger');
                    console.error(error);
                });
        }
        window.handleDelete = handleDelete;

        document.addEventListener('DOMContentLoaded', () => {
            hydrateSectionsCache(serverSections);
            loadSectionsCache();
        });
    </script>
<?php include '../includes/footer.php'; ?>













