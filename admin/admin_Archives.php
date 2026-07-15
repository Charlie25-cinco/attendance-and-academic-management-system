<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Archived Records Page
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/Login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

function buildArchivesPageUrl(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'admin_Archives.php' . (!empty($params) ? ('?' . http_build_query($params)) : '');
}

$archivedItems = [];
$studentArchives = [];
$archivedUsers = [];
$studentAcademicYears = [];
$studentSections = [];

$categoryFilter = $_GET['category'] ?? '';
$yearFilter = $_GET['year'] ?? '';
$search = trim($_GET['search'] ?? '');
$studentSearch = trim((string)($_GET['student_search'] ?? ''));
$studentAcademicYear = trim((string)($_GET['student_academic_year'] ?? ''));
$studentGradeLevel = trim((string)($_GET['student_grade_level'] ?? ''));
$studentSection = trim((string)($_GET['student_section'] ?? ''));
$studentSex = strtolower(trim((string)($_GET['student_sex'] ?? '')));
$studentAccountStatus = strtolower(trim((string)($_GET['student_account_status'] ?? 'inactive')));
$studentEnrollmentStatus = strtolower(trim((string)($_GET['student_enrollment_status'] ?? '')));
$archivedUserSearch = trim((string)($_GET['archived_user_search'] ?? ''));
$archivedUserRole = strtolower(trim((string)($_GET['archived_user_role'] ?? '')));
$annPage = max(1, (int)($_GET['ann_page'] ?? 1));
$archivedUserPage = max(1, (int)($_GET['archived_user_page'] ?? 1));
$studentPage = max(1, (int)($_GET['student_page'] ?? 1));
$itemsPerPage = 10;
$annTotal = 0;
$annTotalPages = 1;
$archivedUserTotal = 0;
$archivedUserTotalPages = 1;
$studentTotal = 0;
$studentTotalPages = 1;

if (!in_array($studentSex, ['', 'male', 'female'], true)) {
    $studentSex = '';
}
if (!in_array($studentAccountStatus, ['', 'active', 'inactive', 'pending'], true)) {
    $studentAccountStatus = 'inactive';
}
if (!in_array($studentEnrollmentStatus, ['', 'enrolled', 'dropped', 'completed'], true)) {
    $studentEnrollmentStatus = '';
}
if ($studentGradeLevel !== '' && !in_array($studentGradeLevel, ['11', '12'], true)) {
    $studentGradeLevel = '';
}
if (!in_array($archivedUserRole, ['', 'admin', 'teacher', 'student', 'parent'], true)) {
    $archivedUserRole = '';
}

if ($db) {
    $where = ["a.status = 'archived'"];
    $params = [];

    if ($categoryFilter !== '') {
        $where[] = 'a.category = :category';
        $params[':category'] = $categoryFilter;
    }

    if ($yearFilter !== '') {
        $where[] = 'YEAR(a.updated_at) = :year';
        $params[':year'] = $yearFilter;
    }

    if ($search !== '') {
        $where[] = '(a.title LIKE :search OR a.content LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $annTotalStmt = $db->prepare("SELECT COUNT(*) FROM announcements a $whereClause");
    foreach ($params as $key => $value) {
        $annTotalStmt->bindValue($key, $value);
    }
    $annTotalStmt->execute();
    $annTotal = (int)$annTotalStmt->fetchColumn();
    $annTotalPages = max(1, (int)ceil($annTotal / $itemsPerPage));
    $annPage = min($annPage, $annTotalPages);
    $annOffset = ($annPage - 1) * $itemsPerPage;

    $query = "SELECT a.id, a.title, a.content, a.category, a.views, a.created_at, a.updated_at,
              CONCAT(u.first_name, ' ', u.last_name) as posted_by_name
              FROM announcements a
              LEFT JOIN users u ON a.posted_by = u.id
              $whereClause
              ORDER BY a.updated_at DESC
              LIMIT :ann_limit OFFSET :ann_offset";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':ann_limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':ann_offset', $annOffset, PDO::PARAM_INT);
    $stmt->execute();
    $archivedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $yearsStmt = $db->query("SELECT DISTINCT academic_year
                             FROM enrollments
                             WHERE academic_year IS NOT NULL
                             AND TRIM(academic_year) <> ''
                             ORDER BY academic_year DESC");
    $studentAcademicYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    $sectionsStmt = $db->query("SELECT DISTINCT section
                                FROM users
                                WHERE role = 'student'
                                AND section IS NOT NULL
                                AND TRIM(section) <> ''
                                ORDER BY section ASC");
    $studentSections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);

    $studentWhere = [
        "u.role = 'student'",
        "(u.status = 'inactive'
          OR EXISTS (SELECT 1 FROM enrollments e0 WHERE e0.student_id = u.id)
          OR EXISTS (SELECT 1 FROM attendance a0 WHERE a0.student_id = u.id)
          OR EXISTS (SELECT 1 FROM grades g0 WHERE g0.student_id = u.id))"
    ];
    $studentParams = [];

    if ($studentSearch !== '') {
        $studentWhere[] = "(u.reference_code LIKE :student_search
                            OR u.first_name LIKE :student_search
                            OR u.last_name LIKE :student_search)";
        $studentParams[':student_search'] = '%' . $studentSearch . '%';
    }
    if ($studentAcademicYear !== '') {
        $studentWhere[] = "EXISTS (
            SELECT 1 FROM enrollments eay
            WHERE eay.student_id = u.id
            AND eay.academic_year = :student_academic_year
        )";
        $studentParams[':student_academic_year'] = $studentAcademicYear;
    }
    if ($studentGradeLevel !== '') {
        $studentWhere[] = "u.grade_level = :student_grade_level";
        $studentParams[':student_grade_level'] = (int)$studentGradeLevel;
    }
    if ($studentSection !== '') {
        $studentWhere[] = "LOWER(TRIM(COALESCE(u.section, ''))) = :student_section";
        $studentParams[':student_section'] = strtolower($studentSection);
    }
    if ($studentSex !== '') {
        $studentWhere[] = "LOWER(TRIM(COALESCE(u.sex, ''))) = :student_sex";
        $studentParams[':student_sex'] = $studentSex;
    }
    if ($studentAccountStatus !== '') {
        $studentWhere[] = "u.status = :student_account_status";
        $studentParams[':student_account_status'] = $studentAccountStatus;
    }
    if ($studentEnrollmentStatus !== '') {
        $studentWhere[] = "EXISTS (
            SELECT 1 FROM enrollments ees
            WHERE ees.student_id = u.id
            AND ees.status = :student_enrollment_status
        )";
        $studentParams[':student_enrollment_status'] = $studentEnrollmentStatus;
    }

    $studentWhereClause = 'WHERE ' . implode(' AND ', $studentWhere);
    $studentCountStmt = $db->prepare("SELECT COUNT(*) FROM users u $studentWhereClause");
    foreach ($studentParams as $key => $value) {
        $studentCountStmt->bindValue($key, $value);
    }
    $studentCountStmt->execute();
    $studentTotal = (int)$studentCountStmt->fetchColumn();
    $studentTotalPages = max(1, (int)ceil($studentTotal / $itemsPerPage));
    $studentPage = min($studentPage, $studentTotalPages);
    $studentOffset = ($studentPage - 1) * $itemsPerPage;

    $studentQuery = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.sex, u.grade_level, u.section, u.status,
                     e_agg.latest_academic_year,
                     e_agg.latest_enrollment_status,
                     e_agg.last_enrolled_at,
                     COALESCE(e_agg.enrollment_records, 0) AS enrollment_records,
                     COALESCE(a_agg.attendance_records, 0) AS attendance_records,
                     COALESCE(g_agg.grade_records, 0) AS grade_records
                     FROM users u
                     LEFT JOIN (
                         SELECT student_id,
                                MAX(academic_year) AS latest_academic_year,
                                SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1) AS latest_enrollment_status,
                                MAX(enrolled_at) AS last_enrolled_at,
                                COUNT(*) AS enrollment_records
                         FROM enrollments GROUP BY student_id
                     ) e_agg ON e_agg.student_id = u.id
                     LEFT JOIN (
                         SELECT student_id, COUNT(*) AS attendance_records
                         FROM attendance GROUP BY student_id
                     ) a_agg ON a_agg.student_id = u.id
                     LEFT JOIN (
                         SELECT student_id, COUNT(*) AS grade_records
                         FROM grades GROUP BY student_id
                     ) g_agg ON g_agg.student_id = u.id
                     $studentWhereClause
                     ORDER BY COALESCE(e_agg.latest_academic_year, '') DESC,
                              u.last_name ASC,
                              u.first_name ASC
                     LIMIT :student_limit OFFSET :student_offset";
    $studentStmt = $db->prepare($studentQuery);
    foreach ($studentParams as $key => $value) {
        $studentStmt->bindValue($key, $value);
    }
    $studentStmt->bindValue(':student_limit', $itemsPerPage, PDO::PARAM_INT);
    $studentStmt->bindValue(':student_offset', $studentOffset, PDO::PARAM_INT);
    $studentStmt->execute();
    $studentArchives = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

    $archivedUserWhere = ["u.status = 'inactive'"];
    $archivedUserParams = [];

    if ($archivedUserSearch !== '') {
        $archivedUserWhere[] = "(u.reference_code LIKE :archived_user_search
                                 OR u.first_name LIKE :archived_user_search
                                 OR u.last_name LIKE :archived_user_search
                                 OR u.email LIKE :archived_user_search)";
        $archivedUserParams[':archived_user_search'] = '%' . $archivedUserSearch . '%';
    }
    if ($archivedUserRole !== '') {
        $archivedUserWhere[] = "u.role = :archived_user_role";
        $archivedUserParams[':archived_user_role'] = $archivedUserRole;
    }

    $archivedUserWhereClause = 'WHERE ' . implode(' AND ', $archivedUserWhere);
    $archivedUserCountStmt = $db->prepare("SELECT COUNT(*) FROM users u $archivedUserWhereClause");
    foreach ($archivedUserParams as $key => $value) {
        $archivedUserCountStmt->bindValue($key, $value);
    }
    $archivedUserCountStmt->execute();
    $archivedUserTotal = (int)$archivedUserCountStmt->fetchColumn();
    $archivedUserTotalPages = max(1, (int)ceil($archivedUserTotal / $itemsPerPage));
    $archivedUserPage = min($archivedUserPage, $archivedUserTotalPages);
    $archivedUserOffset = ($archivedUserPage - 1) * $itemsPerPage;

    $archivedUserQuery = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.email, u.role, u.sex, u.grade_level, u.section, u.status
                          FROM users u
                          $archivedUserWhereClause
                          ORDER BY u.last_name ASC, u.first_name ASC
                          LIMIT :archived_user_limit OFFSET :archived_user_offset";
    $archivedUserStmt = $db->prepare($archivedUserQuery);
    foreach ($archivedUserParams as $key => $value) {
        $archivedUserStmt->bindValue($key, $value);
    }
    $archivedUserStmt->bindValue(':archived_user_limit', $itemsPerPage, PDO::PARAM_INT);
    $archivedUserStmt->bindValue(':archived_user_offset', $archivedUserOffset, PDO::PARAM_INT);
    $archivedUserStmt->execute();
    $archivedUsers = $archivedUserStmt->fetchAll(PDO::FETCH_ASSOC);

}

$current_role = 'admin';
$current_page = 'archives';
$page_title = 'Archived Records';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Balingasag Senior High School</title>
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Archived Records</h4>
                    <p class="text-muted mb-0">Review archived announcements and past student records with advanced filters.</p>
                </div>
            </div>

            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">Archived Announcements</h5>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 mb-4">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="search" placeholder="Search title/content..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <option value="general" <?php echo $categoryFilter === 'general' ? 'selected' : ''; ?>>General</option>
                                <option value="urgent" <?php echo $categoryFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="event" <?php echo $categoryFilter === 'event' ? 'selected' : ''; ?>>Event</option>
                                <option value="academic" <?php echo $categoryFilter === 'academic' ? 'selected' : ''; ?>>Academic</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="year" class="form-select">
                                <option value="">All Years</option>
                                <?php for ($year = (int)date('Y'); $year >= 2024; $year--): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $yearFilter == (string)$year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary-custom w-100" type="submit"><i class="bi bi-funnel me-2"></i>Filter</button>
                        </div>
                    </form>

                    <?php if (empty($archivedItems)): ?>
                        <div class="text-center text-muted py-4">No archived announcements found.</div>
                    <?php endif; ?>

                    <?php foreach ($archivedItems as $item): ?>
                        <div class="announcement-card <?php echo htmlspecialchars($item['category']); ?> mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="announcement-header">
                                        <h6 class="announcement-title"><?php echo htmlspecialchars($item['title']); ?></h6>
                                        <span class="announcement-badge <?php echo htmlspecialchars($item['category']); ?>">
                                            <?php echo ucfirst($item['category']); ?>
                                        </span>
                                    </div>
                                    <p class="announcement-content"><?php echo nl2br(htmlspecialchars($item['content'])); ?></p>
                                    <div class="announcement-meta">
                                        <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($item['posted_by_name'] ?: 'Unknown'); ?></span>
                                        <span><i class="bi bi-clock-history"></i> Archived on <?php echo date('M d, Y h:i A', strtotime($item['updated_at'])); ?></span>
                                        <span><i class="bi bi-eye"></i> <?php echo (int)$item['views']; ?> views</span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-secondary-custom" onclick="restoreAnnouncement(<?php echo (int)$item['id']; ?>)">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick='deleteArchived(<?php echo (int)$item['id']; ?>, <?php echo json_encode($item["title"]); ?>)'>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($annTotalPages > 1): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <small class="text-muted">
                                Showing <?php echo $annTotal > 0 ? (($annPage - 1) * $itemsPerPage + 1) : 0; ?>-<?php echo min($annPage * $itemsPerPage, $annTotal); ?> of <?php echo $annTotal; ?>
                            </small>
                            <nav aria-label="Archived announcements pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $annPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['ann_page' => $annPage - 1])); ?>">Prev</a>
                                    </li>
                                    <?php for ($i = max(1, $annPage - 2); $i <= min($annTotalPages, $annPage + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $annPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['ann_page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $annPage >= $annTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['ann_page' => $annPage + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card mt-4">
                <div class="content-card-header">
                    <h5 class="content-card-title">Archived User Accounts</h5>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 mb-4 align-items-end">
                        <div class="col-12 col-lg-6">
                            <label class="form-label mb-1">Search</label>
                            <input type="text" class="form-control" name="archived_user_search" placeholder="Name, reference code, or email..." value="<?php echo htmlspecialchars($archivedUserSearch); ?>">
                        </div>
                        <div class="col-8 col-lg-3">
                            <label class="form-label mb-1">Role</label>
                            <select name="archived_user_role" class="form-select">
                                <option value="">All Roles</option>
                                <option value="admin" <?php echo $archivedUserRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="teacher" <?php echo $archivedUserRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="student" <?php echo $archivedUserRole === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="parent" <?php echo $archivedUserRole === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            </select>
                        </div>
                        <div class="col-4 col-lg-3">
                            <label class="form-label mb-1 d-none d-lg-block">&nbsp;</label>
                            <div class="d-grid d-sm-flex gap-2">
                                <button class="btn btn-primary-custom flex-grow-1" type="submit">Apply</button>
                                <a class="btn btn-secondary-custom flex-grow-1" href="admin_Archives.php">Clear</a>
                            </div>
                        </div>
                    </form>

                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Reference</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Sex</th>
                                    <th>Grade/Section</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archivedUsers)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No archived user accounts found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($archivedUsers as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(trim((string)$user['first_name'] . ' ' . (string)$user['last_name'])); ?></td>
                                            <td><?php echo htmlspecialchars((string)$user['reference_code']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$user['email']); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst((string)$user['role'])); ?></td>
                                            <td><?php echo !empty($user['sex']) ? htmlspecialchars(ucfirst((string)$user['sex'])) : 'N/A'; ?></td>
                                            <td><?php echo !empty($user['grade_level']) ? ('G' . (int)$user['grade_level']) : 'N/A'; ?><?php echo !empty($user['section']) ? (' - ' . htmlspecialchars((string)$user['section'])) : ''; ?></td>
                                            <td><span class="status-badge status-<?php echo htmlspecialchars((string)$user['status']); ?>"><?php echo ucfirst((string)$user['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($archivedUserTotalPages > 1): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <small class="text-muted">
                                Showing <?php echo $archivedUserTotal > 0 ? (($archivedUserPage - 1) * $itemsPerPage + 1) : 0; ?>-<?php echo min($archivedUserPage * $itemsPerPage, $archivedUserTotal); ?> of <?php echo $archivedUserTotal; ?>
                            </small>
                            <nav aria-label="Archived users pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $archivedUserPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['archived_user_page' => $archivedUserPage - 1])); ?>">Prev</a>
                                    </li>
                                    <?php for ($i = max(1, $archivedUserPage - 2); $i <= min($archivedUserTotalPages, $archivedUserPage + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $archivedUserPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['archived_user_page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $archivedUserPage >= $archivedUserTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['archived_user_page' => $archivedUserPage + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="content-card mt-4">
                <div class="content-card-header">
                    <h5 class="content-card-title">Past Student Records Archive</h5>
                </div>
                <div class="content-card-body">
                    <form method="GET" class="row g-3 mb-4 align-items-end">
                        <div class="col-12 col-lg-4">
                            <label class="form-label mb-1">Search</label>
                            <input type="text" class="form-control" name="student_search" placeholder="Name or reference code..." value="<?php echo htmlspecialchars($studentSearch); ?>">
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label mb-1">Academic Year</label>
                            <select name="student_academic_year" class="form-select">
                                <option value="">All Academic Years</option>
                                <?php foreach ($studentAcademicYears as $sy): ?>
                                    <option value="<?php echo htmlspecialchars((string)$sy); ?>" <?php echo $studentAcademicYear === (string)$sy ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$sy); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3 col-lg-2">
                            <label class="form-label mb-1">Grade</label>
                            <select name="student_grade_level" class="form-select">
                                <option value="">All Grades</option>
                                <option value="11" <?php echo $studentGradeLevel === '11' ? 'selected' : ''; ?>>11</option>
                                <option value="12" <?php echo $studentGradeLevel === '12' ? 'selected' : ''; ?>>12</option>
                            </select>
                        </div>
                        <div class="col-9 col-lg-3">
                            <label class="form-label mb-1">Section</label>
                            <select name="student_section" class="form-select">
                                <option value="">All Sections</option>
                                <?php foreach ($studentSections as $section): ?>
                                    <option value="<?php echo htmlspecialchars((string)$section); ?>" <?php echo strtolower($studentSection) === strtolower((string)$section) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$section); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-4 col-lg-2">
                            <label class="form-label mb-1">Sex</label>
                            <select name="student_sex" class="form-select">
                                <option value="">All</option>
                                <option value="male" <?php echo $studentSex === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $studentSex === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-8 col-lg-3">
                            <label class="form-label mb-1">Account Status</label>
                            <select name="student_account_status" class="form-select">
                                <option value="">All Accounts</option>
                                <option value="inactive" <?php echo $studentAccountStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="active" <?php echo $studentAccountStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $studentAccountStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-3">
                            <label class="form-label mb-1">Enrollment Status</label>
                            <select name="student_enrollment_status" class="form-select">
                                <option value="">All Enrollments</option>
                                <option value="completed" <?php echo $studentEnrollmentStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="dropped" <?php echo $studentEnrollmentStatus === 'dropped' ? 'selected' : ''; ?>>Dropped</option>
                                <option value="enrolled" <?php echo $studentEnrollmentStatus === 'enrolled' ? 'selected' : ''; ?>>Enrolled</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-4 ms-lg-auto">
                            <label class="form-label mb-1 d-none d-lg-block">&nbsp;</label>
                            <div class="d-grid d-sm-flex justify-content-lg-end gap-2">
                                <button class="btn btn-primary-custom flex-grow-1" type="submit">Apply Filter</button>
                                <a class="btn btn-secondary-custom flex-grow-1" href="admin_Archives.php">Clear</a>
                            </div>
                        </div>
                    </form>

                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Reference</th>
                                    <th>Account</th>
                                    <th>Sex</th>
                                    <th>Grade/Section</th>
                                    <th>Latest Term</th>
                                    <th>Enrollments</th>
                                    <th>Attendance</th>
                                    <th>Grades</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($studentArchives)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No past student records found for selected filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($studentArchives as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(trim($student['first_name'] . ' ' . $student['last_name'])); ?></td>
                                            <td><?php echo htmlspecialchars((string)$student['reference_code']); ?></td>
                                            <td><span class="status-badge status-<?php echo htmlspecialchars((string)$student['status']); ?>"><?php echo ucfirst((string)$student['status']); ?></span></td>
                                            <td><?php echo $student['sex'] ? htmlspecialchars(ucfirst((string)$student['sex'])) : 'N/A'; ?></td>
                                            <td><?php echo $student['grade_level'] ? ('G' . (int)$student['grade_level']) : 'N/A'; ?><?php echo $student['section'] ? (' - ' . htmlspecialchars((string)$student['section'])) : ''; ?></td>
                                            <td>
                                                <?php echo $student['latest_academic_year'] ? htmlspecialchars((string)$student['latest_academic_year']) : 'N/A'; ?>
                                                <?php if (!empty($student['latest_enrollment_status'])): ?>
                                                    <small class="text-muted d-block"><?php echo ucfirst((string)$student['latest_enrollment_status']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int)$student['enrollment_records']; ?></td>
                                            <td><?php echo (int)$student['attendance_records']; ?></td>
                                            <td><?php echo (int)$student['grade_records']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($studentTotalPages > 1): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                            <small class="text-muted">
                                Showing <?php echo $studentTotal > 0 ? (($studentPage - 1) * $itemsPerPage + 1) : 0; ?>-<?php echo min($studentPage * $itemsPerPage, $studentTotal); ?> of <?php echo $studentTotal; ?>
                            </small>
                            <nav aria-label="Past student records pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $studentPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['student_page' => $studentPage - 1])); ?>">Prev</a>
                                    </li>
                                    <?php for ($i = max(1, $studentPage - 2); $i <= min($studentTotalPages, $studentPage + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $studentPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['student_page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $studentPage >= $studentTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars(buildArchivesPageUrl(['student_page' => $studentPage + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/delete_modal.php'; ?>

    <script>
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
        function withCsrfUrl(url) {
            if (!csrfToken) return url;
            return url + (url.includes('?') ? '&' : '?') + 'csrf_token=' + encodeURIComponent(csrfToken);
        }

        function restoreAnnouncement(id) {
            fetch(withCsrfUrl('admin_Announcements_Action.php?action=restore&id=' + id))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to restore announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error restoring announcement', 'danger'));
        }

        function deleteArchived(id, title) {
            showDeleteModal(id, title, 'archived announcement');
        }

        function handleDelete(id) {
            fetch(withCsrfUrl('admin_Announcements_Action.php?action=delete&id=' + id))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showNotification(data.message || 'Failed to delete announcement', 'danger');
                }
            })
            .catch(() => showNotification('Error deleting announcement', 'danger'));
        }
    </script>
<?php include '../includes/footer.php'; ?>






