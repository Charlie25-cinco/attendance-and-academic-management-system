<?php
require_once __DIR__ . '/../functions/bootstrap.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$statsStmt = $db->query("SELECT
    SUM(CASE WHEN role = 'student' AND status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN role = 'student' AND status = 'inactive' THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN role = 'student' AND status = 'pending' THEN 1 ELSE 0 END) as pending,
    COUNT(*) as total
FROM users WHERE role = 'student'");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$sectionsStmt = $db->query("SELECT DISTINCT section FROM users WHERE role = 'student' AND section IS NOT NULL AND section <> '' ORDER BY section");
$sections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);

$current_role = 'admin';
$current_page = 'enrollments';
$page_title = 'Enrollments';

// Handle download of CSV template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="SF1_Student_Import_Template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['LRN','Last Name','First Name','Middle Name','Sex (Male/Female)','Grade Level (11 or 12)','Section','Track/Program','Date of Birth (YYYY-MM-DD)','Address','Contact Number (Parent)','Parent/Guardian Name']);
    fputcsv($out, ['123456789012','Dela Cruz','Juan','Reyes','Male','11','HUMILITY','academic','2007-05-15','Balingasag, MisOr','09XXXXXXXXX','Maria Dela Cruz']);
    fputcsv($out, ['123456789013','Santos','Maria','Lopez','Female','12','PATIENCE','techpro','2006-08-22','Balingasag, MisOr','09XXXXXXXXX','Jose Santos']);
    fclose($out);
    exit();
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

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(59,130,246,0.1);">
                            <i class="bi bi-people" style="color: #3b82f6;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['total'] ?? 0); ?></h3>
                            <p>Total Students</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(16,185,129,0.1);">
                            <i class="bi bi-person-check" style="color: #10b981;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['active'] ?? 0); ?></h3>
                            <p>Active</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(239,68,68,0.1);">
                            <i class="bi bi-person-x" style="color: #ef4444;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['inactive'] ?? 0); ?></h3>
                            <p>Archived</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="dashboard-card">
                        <div class="dashboard-card-icon" style="background: rgba(245,158,11,0.1);">
                            <i class="bi bi-person-up" style="color: #f59e0b;"></i>
                        </div>
                        <div class="dashboard-card-info">
                            <h3><?php echo (int)($stats['pending'] ?? 0); ?></h3>
                            <p>Pending</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form id="filterForm" class="row g-2 align-items-end app-responsive-filter-form" onsubmit="return loadStudents()" data-skip-loader="true">
                        <div class="col-md-3">
                            <label class="form-label small" for="filterSearch">Search</label>
                            <input type="text" name="search" id="filterSearch" class="form-control form-control-sm" placeholder="Name, LRN, or Code" autocomplete="off">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterGradeLevel">Grade Level</label>
                            <select name="grade_level" id="filterGradeLevel" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Grades</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterSection">Section</label>
                            <select name="section" id="filterSection" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterTrack">Track</label>
                            <select name="track" id="filterTrack" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Tracks</option>
                                <option value="academic">Academic</option>
                                <option value="techpro">TechPro</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="filterStatus">Status</label>
                            <select name="status" id="filterStatus" class="form-select form-select-sm" autocomplete="off">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Archived</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary-custom btn-sm w-100"><i class="bi bi-funnel"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Students Table -->
            <div class="content-card">
                    <div class="content-card-header">
                    <h5 class="content-card-title">Enrolled Students</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" onclick="openImportModal()">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import SF1
                        </button>
                        <button class="btn btn-primary-custom btn-sm" onclick="openAddModal()">
                            <i class="bi bi-person-plus me-1"></i>Add Student
                        </button>
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>LRN</th>
                                    <th>Name</th>
                                    <th>Grade</th>
                                    <th>Section</th>
                                    <th>Program</th>
                                    <th>Enrolled Classes</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="text-muted small" id="paginationInfo"></div>
                        <nav><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-person-plus"></i>Enrollment</div>
                        <h5 class="modal-title mb-0">Add New Student</h5>
                        <p class="app-modal-subtitle">Create a student account and enroll in matching classes.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="addStudentForm">
                        <div class="app-modal-stack">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-person-vcard"></i>Student Profile</div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="addFirstName">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" id="addFirstName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="given-name">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="addMiddleName">Middle Name</label>
                                        <input type="text" name="middle_name" id="addMiddleName" class="form-control" oninput="capitalizeWords(this)" autocomplete="additional-name">
                                    </div>
                                    <div class="col-md-4 mb-0">
                                        <label class="form-label" for="addLastName">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" id="addLastName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="family-name">
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addSex">Sex <span class="text-danger">*</span></label>
                                        <select name="sex" id="addSex" class="form-select" required autocomplete="sex">
                                            <option value="">Select Sex</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label" for="addLrn">LRN <span class="text-danger">*</span> <small class="text-muted">(12 digits)</small></label>
                                        <input type="text" name="lrn" id="addLrn" class="form-control" maxlength="12" placeholder="Enter 12-digit LRN" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required autocomplete="off">
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-mortarboard"></i>Enrollment Placement</div>
                                <p class="app-modal-panel-copy">For Grade 11, the selected section determines the Strengthened SHS program and matching classes.</p>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addGradeLevel">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="addGradeLevel" class="form-select" required onchange="updateAddSections()" autocomplete="off">
                                            <option value="">Select Grade</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label" for="addSection">Section <span class="text-danger">*</span></label>
                                        <select name="section" id="addSection" class="form-select" required disabled autocomplete="off">
                                            <option value="">Select Grade First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-telephone"></i>Contact Details</div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addDob">Date of Birth</label>
                                        <input type="date" name="date_of_birth" id="addDob" class="form-control" autocomplete="bday">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addContact">Contact Number</label>
                                        <input type="text" name="contact_number" id="addContact" class="form-control" maxlength="20" placeholder="e.g. 09XX-XXX-XXXX" autocomplete="tel">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addHouseStreet">House No./Street/Sitio/Purok</label>
                                        <input type="text" name="house_street" id="addHouseStreet" class="form-control" maxlength="120" autocomplete="street-address">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addBarangay">Barangay</label>
                                        <input type="text" name="barangay" id="addBarangay" class="form-control" maxlength="120">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="addMunicipality">Municipality/City</label>
                                        <input type="text" name="municipality" id="addMunicipality" class="form-control" maxlength="120">
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label" for="addProvince">Province</label>
                                        <input type="text" name="province" id="addProvince" class="form-control" maxlength="120">
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-note">
                                <i class="bi bi-info-circle-fill"></i>
                                <div>
                                    <strong>Auto-enrollment</strong>
                                    <p>Student will be auto-enrolled in all active classes matching the selected grade level, section, and track setup.</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="createStudentBtn" onclick="createStudent()">Create & Enroll</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Update</div>
                        <h5 class="modal-title mb-0">Edit Student</h5>
                        <p class="app-modal-subtitle">Update student details and re-sync enrollment.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <form id="editStudentForm">
                        <input type="hidden" name="user_id" id="editUserId">
                        <div class="app-modal-stack">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-pencil-square"></i>Profile Updates</div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="editFirstName">First Name <span class="text-danger">*</span></label>
                                        <input type="text" name="first_name" id="editFirstName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="given-name">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="editMiddleName">Middle Name</label>
                                        <input type="text" name="middle_name" id="editMiddleName" class="form-control" oninput="capitalizeWords(this)" autocomplete="additional-name">
                                    </div>
                                    <div class="col-md-4 mb-0">
                                        <label class="form-label" for="editLastName">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" id="editLastName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="family-name">
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="editSex">Sex <span class="text-danger">*</span></label>
                                        <select name="sex" id="editSex" class="form-select" required autocomplete="sex">
                                            <option value="">Select Sex</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label" for="editLrn">LRN <span class="text-danger">*</span> <small class="text-muted">(12 digits)</small></label>
                                        <input type="text" name="lrn" id="editLrn" class="form-control" maxlength="12" placeholder="Enter 12-digit LRN" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required autocomplete="off">
                                    </div>
                                </div>
                            </div>
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-arrow-repeat"></i>Enrollment Placement</div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="editGradeLevel">Grade Level <span class="text-danger">*</span></label>
                                        <select name="grade_level" id="editGradeLevel" class="form-select" required onchange="updateEditSections()" autocomplete="off">
                                            <option value="">Select Grade</option>
                                            <option value="11">Grade 11</option>
                                            <option value="12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-0">
                                        <label class="form-label" for="editSection">Section <span class="text-danger">*</span></label>
                                        <select name="section" id="editSection" class="form-select" required disabled autocomplete="off">
                                            <option value="">Select Grade First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateStudentBtn" onclick="updateStudent()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div class="modal fade" id="viewStudentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-person-circle"></i>Profile</div>
                        <h5 class="modal-title mb-0">Student Details</h5>
                        <p class="app-modal-subtitle">View student profile and enrolled classes.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <div class="text-center mb-4">
                        <div id="viewStudentAvatar" class="user-avatar-small mx-auto" style="width: 80px; height: 80px; font-size: 28px;"></div>
                        <h5 class="mt-3 mb-1" id="viewFullName"></h5>
                        <span class="role-badge role-student">Student</span>
                    </div>
                    <div class="app-modal-panel">
                        <div class="app-modal-panel-title"><i class="bi bi-person-badge"></i>Student Snapshot</div>
                        <div class="app-modal-detail-list">
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">LRN</div>
                                <div class="app-modal-detail-value" id="viewLrn"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Reference Code</div>
                                <div class="app-modal-detail-value" id="viewRefCode"></div>
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
                                <div class="app-modal-detail-label">Grade Level</div>
                                <div class="app-modal-detail-value" id="viewGradeLevel"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Section</div>
                                <div class="app-modal-detail-value" id="viewSection"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Program</div>
                                <div class="app-modal-detail-value" id="viewTrack"></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Status</div>
                                <div class="app-modal-detail-value"><span id="viewStatus" class="status-badge"></span></div>
                            </div>
                            <div class="app-modal-detail-row">
                                <div class="app-modal-detail-label">Enrolled Classes</div>
                                <div class="app-modal-detail-value" id="viewEnrolledClasses"></div>
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

    <!-- Bulk Import Modal -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content app-modal-content">
                <div class="modal-header app-modal-header">
                    <div>
                        <div class="app-modal-kicker"><i class="bi bi-file-earmark-spreadsheet"></i>SF1 Import</div>
                        <h5 class="modal-title mb-0">Bulk Student Import</h5>
                        <p class="app-modal-subtitle">Upload a CSV file following the SF1 format to register multiple students at once.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body app-modal-body">
                    <div class="app-modal-stack">
                        <div class="app-modal-note">
                            <i class="bi bi-file-earmark-check-fill"></i>
                            <div>
                                <strong>Before importing</strong>
                                <p>The system generates reference codes, applies the default password, and skips duplicate LRNs automatically. Supports both the official DepEd SF1 XLSX template and CSV format.</p>
                            </div>
                        </div>

                        <form id="importForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-upload"></i>Import Source</div>
                                <div class="row g-3 align-items-end mb-0">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold" for="sf1File">SF1 File <span class="text-danger">*</span></label>
                                        <input type="file" name="sf1_file" id="sf1File" class="form-control" accept=".csv,.xlsx" required>
                                        <div class="form-text">XLSX (official DepEd SF1 template) or CSV format only. Max 10MB.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold" for="importAcademicYear">Academic Year</label>
                                        <input type="text" name="academic_year" id="importAcademicYear" class="form-control" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" placeholder="e.g. 2025-2026">
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?download_template=1" class="btn btn-outline-primary btn-sm w-100 mb-2">
                                            <i class="bi bi-download me-1"></i>Download CSV Template
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Progress -->
                        <div id="importProgress" style="display:none;" class="mt-0">
                            <div class="progress mb-2" style="height:8px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" id="importProgressBar" style="width:0%;"></div>
                            </div>
                            <div id="importStatus" class="text-muted small">Processing...</div>
                        </div>

                        <!-- Results -->
                        <div id="importResults" style="display:none;" class="mt-0">
                            <div id="importSummary" class="mb-3"></div>
                            <div id="importLog" style="max-height:400px;overflow-y:auto;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer app-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="importBtn" onclick="submitImport()">
                        <i class="bi bi-upload me-1"></i>Import Students
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        const csrfToken = (window.APP_CSRF_TOKEN || '').toString();
        let sectionsCache = {};
        let studentsRequestController = null;
        let studentsRequestSeq = 0;

        document.addEventListener('DOMContentLoaded', () => {
            loadStudents();
            loadSectionsCache();
        });

        function loadStudents(page) {
            if (page) currentPage = page;
            if (!page) currentPage = 1;
            const form = document.getElementById('filterForm');
            const fd = new FormData(form);
            const params = new URLSearchParams(fd);
            params.set('action', 'list');
            if (currentPage) params.set('page', currentPage);

            const requestSeq = ++studentsRequestSeq;
            if (studentsRequestController) {
                studentsRequestController.abort();
            }
            studentsRequestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const requestTimeout = setTimeout(() => {
                if (studentsRequestController) {
                    studentsRequestController.abort();
                }
            }, 15000);
            document.getElementById('studentsTableBody').innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>';

            const fetchOptions = studentsRequestController ? { signal: studentsRequestController.signal } : {};
            fetch('admin_Enrollments_Action.php?' + params.toString(), fetchOptions)
                .then(async r => {
                    if (!r.ok) {
                        const text = await r.text();
                        console.error('AJAX status:', r.status, 'body:', text.substring(0, 500));
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(data => {
                    clearTimeout(requestTimeout);
                    if (requestSeq !== studentsRequestSeq) {
                        return;
                    }
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load students', 'danger');
                        return;
                    }
                    renderTable(data.students || []);
                    renderPagination(data);
                    document.getElementById('paginationInfo').textContent =
                        data.students.length > 0
                            ? `Showing ${((data.page - 1) * 15) + 1} to ${Math.min(data.page * 15, data.total)} of ${data.total} students`
                            : 'No students found';
                })
                .catch(err => {
                    clearTimeout(requestTimeout);
                    if (err.name === 'AbortError') {
                        if (requestSeq === studentsRequestSeq) {
                            document.getElementById('studentsTableBody').innerHTML =
                                '<tr><td colspan="8" class="text-center text-muted py-4">The filter took too long. Please try again.</td></tr>';
                            document.getElementById('paginationInfo').textContent = '';
                        }
                        return;
                    }
                    console.error(err);
                    document.getElementById('studentsTableBody').innerHTML =
                        '<tr><td colspan="8" class="text-center text-muted py-4">Error loading students.</td></tr>';
                    document.getElementById('paginationInfo').textContent = '';
                    showNotification('Error loading students', 'danger');
                });
            return false;
        }

        function renderTable(students) {
            const tbody = document.getElementById('studentsTableBody');
            if (!students.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No students found</td></tr>';
                return;
            }
            tbody.innerHTML = students.map(s => `
                <tr>
                    <td><span class="text-muted small">${escHtml(s.lrn || '—')}</span></td>
                    <td>
                        <div class="user-list-item" style="padding:0;border:none;">
                            <div class="user-avatar-small" style="background:var(--warning-color);width:32px;height:32px;font-size:12px;">
                                ${((s.first_name || '').charAt(0)) + ((s.last_name || '').charAt(0))}
                            </div>
                            <div class="user-list-info">
                                <h5>${escHtml(s.first_name + ' ' + s.last_name)}</h5>
                                <p>${escHtml(s.reference_code || '')}</p>
                            </div>
                        </div>
                    </td>
                    <td>${s.grade_level ? 'Grade ' + s.grade_level : '—'}</td>
                    <td>${escHtml(s.section || '—')}</td>
                    <td>${programLabel(s) ? `<span class="track-badge track-${s.track || 'academic'}">${escHtml(programLabel(s))}</span>` : '—'}</td>
                    <td>${s.enrolled_classes ? `<span class="badge bg-info text-dark">${escHtml(s.enrolled_classes)}</span>` : '<span class="text-muted">None</span>'}</td>
                    <td><span class="status-badge status-${s.status}">${ucfirst(s.status)}</span></td>
                    <td>
                        <div class="d-flex gap-2">
                            <button class="material-btn js-view-student" data-id="${s.id}" title="View" style="color:var(--secondary-color);"><i class="bi bi-eye"></i></button>
                            <button class="material-btn js-edit-student" data-id="${s.id}" title="Edit" style="color:var(--warning-color);"><i class="bi bi-pencil"></i></button>
                            <button class="material-btn js-toggle-student-status" data-id="${s.id}" data-status="${s.status}" title="${s.status === 'active' ? 'Archive' : 'Activate'}" style="color:${s.status === 'active' ? 'var(--warning-color)' : 'var(--accent-color)'};">
                                <i class="bi bi-${s.status === 'active' ? 'toggle-off' : 'toggle-on'}"></i>
                            </button>
                            <button class="material-btn js-delete-student" data-id="${s.id}" title="Delete" style="color:var(--danger-color);"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `).join('');

            // Bind events
            tbody.querySelectorAll('.js-view-student').forEach(btn => {
                btn.addEventListener('click', () => viewStudent(parseInt(btn.dataset.id)));
            });
            tbody.querySelectorAll('.js-edit-student').forEach(btn => {
                btn.addEventListener('click', () => editStudent(parseInt(btn.dataset.id)));
            });
            tbody.querySelectorAll('.js-toggle-student-status').forEach(btn => {
                btn.addEventListener('click', () => toggleStudentStatus(parseInt(btn.dataset.id), btn.dataset.status));
            });
            tbody.querySelectorAll('.js-delete-student').forEach(btn => {
                btn.addEventListener('click', () => deleteStudent(parseInt(btn.dataset.id)));
            });
        }

        function renderPagination(data) {
            const ul = document.getElementById('pagination');
            if (data.total_pages <= 1) { ul.innerHTML = ''; return; }
            let html = '';
            for (let i = 1; i <= data.total_pages; i++) {
                html += `<li class="page-item${i === data.page ? ' active' : ''}">
                    <button class="page-link" onclick="loadStudents(${i})">${i}</button>
                </li>`;
            }
            ul.innerHTML = html;
        }

        function ucfirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }


        function capitalizeWords(input) {
            input.value = input.value.replace(/\b\w/g, c => c.toUpperCase());
        }

        // Section dropdown helpers
        function loadSectionsCache() {
            return fetch('admin_Sections_Action.php?action=list')
                .then(r => r.json())
                .then(data => {
                    sectionsCache = {};
                    if (data.success && data.sections) {
                        data.sections.forEach(s => {
                            const key = s.grade_level + '_' + s.track;
                            if (!sectionsCache[key]) sectionsCache[key] = [];
                            const suffix = s.program === 'academic_strengthened' ? ' - Academic Track (Strengthened)' : (s.program === 'technical_professional' ? ' - Technical Professional' : '');
                            sectionsCache[key].push({ value: s.name, label: s.name + suffix, program: s.program || '' });
                        });
                    }
                    return sectionsCache;
                })
                .catch(() => { sectionsCache = {}; });
        }

        function getSectionsForGrade(grade) {
            if (!grade) return [];
            const all = [];
            Object.keys(sectionsCache).forEach(key => {
                if (key.startsWith(String(grade) + '_')) {
                    all.push(...sectionsCache[key]);
                }
            });
            return all;
        }

        function deriveTrackFromSection(grade, sectionValue) {
            if (!grade || !sectionValue) return '';
            for (const key in sectionsCache) {
                if (key.startsWith(String(grade) + '_')) {
                    if (sectionsCache[key].some(s => s.value === sectionValue)) {
                        return key.split('_')[1];
                    }
                }
            }
            return '';
        }

        function updateAddSections() {
            const grade = document.getElementById('addGradeLevel').value;
            const sel = document.getElementById('addSection');
            const list = getSectionsForGrade(grade);
            sel.innerHTML = '<option value="">' + (list.length ? 'Select Section' : 'No sections available') + '</option>';
            sel.disabled = !list.length;
            if (!grade) {
                sel.innerHTML = '<option value="">Select Grade First</option>';
                sel.disabled = true;
            }
            list.forEach(s => {
                sel.innerHTML += '<option value="' + escHtml(s.value) + '">' + escHtml(s.label) + '</option>';
            });
        }

        function updateEditSections() {
            const grade = document.getElementById('editGradeLevel').value;
            const sel = document.getElementById('editSection');
            const prevVal = sel.value;
            const list = getSectionsForGrade(grade);
            sel.innerHTML = '<option value="">' + (list.length ? 'Select Section' : 'No sections available') + '</option>';
            sel.disabled = !list.length;
            if (!grade) {
                sel.innerHTML = '<option value="">Select Grade First</option>';
                sel.disabled = true;
            }
            list.forEach(s => {
                sel.innerHTML += '<option value="' + escHtml(s.value) + '">' + escHtml(s.label) + '</option>';
            });
            if (prevVal) sel.value = prevVal;
        }

        // CRUD
        function openAddModal() {
            document.getElementById('addStudentForm').reset();
            document.getElementById('addSection').innerHTML = '<option value="">Select Grade First</option>';
            document.getElementById('addSection').disabled = true;
            const modal = new bootstrap.Modal(document.getElementById('addStudentModal'));
            modal.show();
        }

        function createStudent() {
            const form = document.getElementById('addStudentForm');
            const fd = new FormData(form);
            const lrn = (fd.get('lrn') || '').trim();
            const gradeLevel = fd.get('grade_level');
            const section = fd.get('section');

            if (!fd.get('first_name') || !fd.get('last_name')) {
                showNotification('First and last name are required', 'warning'); return;
            }
            if (!fd.get('sex')) {
                showNotification('Please select sex', 'warning'); return;
            }
            if (!lrn) {
                showNotification('LRN is required', 'warning'); return;
            }
            if (!/^\d{12}$/.test(lrn)) {
                showNotification('LRN must be exactly 12 digits', 'warning'); return;
            }
            if (!gradeLevel) {
                showNotification('Please select grade level', 'warning'); return;
            }
            if (!section) {
                showNotification('Please select section', 'warning'); return;
            }

            // Auto-derive track from selected section
            const derivedTrack = deriveTrackFromSection(gradeLevel, section);
            if (!derivedTrack) {
                showNotification('No track found for the selected section', 'warning'); return;
            }
            fd.set('track', derivedTrack);

            appendCsrfToFormData(fd);
            fetch('admin_Enrollments_Action.php?action=create', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Student created', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('addStudentModal')).hide();
                    loadStudents(1);
                } else {
                    showNotification(data.message || 'Failed to create student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error creating student', 'danger');
                console.error(err);
            });
        }

        function editStudent(id) {
            fetch('admin_Enrollments_Action.php?action=get&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load student', 'danger');
                        return;
                    }
                    const s = data.student;
                    document.getElementById('editUserId').value = s.id;
                    document.getElementById('editFirstName').value = s.first_name || '';
                    document.getElementById('editMiddleName').value = s.middle_name || '';
                    document.getElementById('editLastName').value = s.last_name || '';
                    document.getElementById('editSex').value = (s.sex || '').toLowerCase();
                    document.getElementById('editLrn').value = s.lrn || '';
                    document.getElementById('editGradeLevel').value = s.grade_level || '';
                    updateEditSections();
                    if (s.section) {
                        document.getElementById('editSection').value = s.section;
                    }
                    new bootstrap.Modal(document.getElementById('editStudentModal')).show();
                })
                .catch(err => {
                    showNotification('Error loading student', 'danger');
                    console.error(err);
                });
        }

        function updateStudent() {
            const form = document.getElementById('editStudentForm');
            const fd = new FormData(form);
            const lrn = (fd.get('lrn') || '').trim();
            const gradeLevel = fd.get('grade_level');
            const section = fd.get('section');

            if (!fd.get('first_name') || !fd.get('last_name')) {
                showNotification('First and last name are required', 'warning'); return;
            }
            if (!fd.get('sex')) {
                showNotification('Please select sex', 'warning'); return;
            }
            if (!lrn || !/^\d{12}$/.test(lrn)) {
                showNotification('LRN must be exactly 12 digits', 'warning'); return;
            }
            if (!gradeLevel || !section) {
                showNotification('Grade level and section are required', 'warning'); return;
            }

            // Auto-derive track from selected section
            const derivedTrack = deriveTrackFromSection(gradeLevel, section);
            if (!derivedTrack) {
                showNotification('No track found for the selected section', 'warning'); return;
            }
            fd.set('track', derivedTrack);

            appendCsrfToFormData(fd);
            fetch('admin_Enrollments_Action.php?action=update', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification('Student updated', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editStudentModal')).hide();
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to update student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error updating student', 'danger');
                console.error(err);
            });
        }

        function viewStudent(id) {
            fetch('admin_Enrollments_Action.php?action=get&id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showNotification(data.message || 'Failed to load student', 'danger');
                        return;
                    }
                    const s = data.student;
                    const fullName = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
                    const initials = ((s.first_name || 'U')[0] + (s.last_name || 'N')[0]).toUpperCase();

                    document.getElementById('viewStudentAvatar').textContent = initials;
                    document.getElementById('viewFullName').textContent = fullName;
                    document.getElementById('viewLrn').textContent = s.lrn || 'N/A';
                    document.getElementById('viewRefCode').textContent = s.reference_code || 'N/A';
                    document.getElementById('viewEmail').textContent = s.email || 'N/A';
                    document.getElementById('viewSex').textContent = s.sex ? ucfirst(s.sex) : 'N/A';
                    document.getElementById('viewGradeLevel').textContent = s.grade_level ? 'Grade ' + s.grade_level : 'N/A';
                    document.getElementById('viewSection').textContent = s.section || 'N/A';
                    document.getElementById('viewTrack').textContent = programLabel(s) || 'N/A';

                    const statusEl = document.getElementById('viewStatus');
                    statusEl.textContent = ucfirst(s.status);
                    statusEl.className = 'status-badge status-' + s.status;

                    const classesEl = document.getElementById('viewEnrolledClasses');
                    if (s.enrolled_classes && s.enrolled_classes.length) {
                        classesEl.innerHTML = s.enrolled_classes.map(ec =>
                            `<span class="badge bg-info text-dark me-1 mb-1 d-inline-block">${escHtml(ec.class_name)}${ec.enrollment_status !== 'enrolled' ? ' (' + ucfirst(ec.enrollment_status) + ')' : ''}</span>`
                        ).join('');
                    } else {
                        classesEl.innerHTML = '<span class="text-muted">Not enrolled in any class</span>';
                    }

                    new bootstrap.Modal(document.getElementById('viewStudentModal')).show();
                })
                .catch(err => {
                    showNotification('Error loading student', 'danger');
                    console.error(err);
                });
        }

        function toggleStudentStatus(id, currentStatus) {
            const nextStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const label = nextStatus === 'active' ? 'activate' : 'archive';
            const runToggleStudentStatus = function() {
                const params = new URLSearchParams();
                params.set('user_id', String(id));
                params.set('status', nextStatus);
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Enrollments_Action.php?action=toggle_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Status updated', 'success');
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to update status', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error updating status', 'danger');
                console.error(err);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runToggleStudentStatus();
                return;
            }
            showAppConfirm({
                title: `${label.charAt(0).toUpperCase() + label.slice(1)} this student?`,
                subtitle: 'This updates the selected student account status.',
                message: `You are about to <strong>${label}</strong> this student account.`,
                confirmText: label === 'activate' ? 'Activate student' : 'Archive student',
                cancelText: 'Cancel',
                tone: nextStatus === 'active' ? 'primary' : 'danger',
                icon: nextStatus === 'active' ? 'bi-person-check-fill' : 'bi-person-dash-fill'
            }).then((confirmed) => {
                if (confirmed) runToggleStudentStatus();
            });
        }

        function deleteStudent(id) {
            const runArchiveStudent = function() {
                const params = new URLSearchParams();
                params.set('id', String(id));
                if (csrfToken) params.set('csrf_token', csrfToken);

                return fetch('admin_Enrollments_Action.php?action=delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message || 'Student archived', 'success');
                    loadStudents(currentPage);
                } else {
                    showNotification(data.message || 'Failed to archive student', 'danger');
                }
            })
            .catch(err => {
                showNotification('Error archiving student', 'danger');
                console.error(err);
            });
            };
            if (typeof showAppConfirm !== 'function') {
                runArchiveStudent();
                return;
            }
            showAppConfirm({
                title: 'Archive this student?',
                subtitle: 'The student record will be moved out of the active list.',
                message: 'You can restore the student later from the appropriate admin workflow if needed.',
                confirmText: 'Archive student',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'bi-archive-fill'
            }).then((confirmed) => {
                if (confirmed) runArchiveStudent();
            });
        }

        // Import modal
        function openImportModal() {
            const form = document.getElementById('importForm');
            if (form) form.reset();
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResults').style.display = 'none';
            document.getElementById('importBtn').disabled = false;
            document.getElementById('importBtn').innerHTML = '<i class="bi bi-upload me-1"></i>Import Students';
            new bootstrap.Modal(document.getElementById('importModal')).show();
        }

        function submitImport() {
            const file = document.getElementById('sf1File').files[0];
            if (!file) { showNotification('Please select a file.', 'warning'); return; }
            const ext = file.name.toLowerCase().split('.').pop();
            if (!['csv', 'xlsx'].includes(ext)) { showNotification('Only CSV and XLSX files are supported.', 'warning'); return; }
            if (file.size > 10 * 1024 * 1024) { showNotification('File too large. Max 10MB.', 'warning'); return; }

            const btn = document.getElementById('importBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing...';
            document.getElementById('importProgress').style.display = '';
            document.getElementById('importResults').style.display = 'none';
            document.getElementById('importProgressBar').style.width = '30%';
            document.getElementById('importStatus').textContent = 'Uploading and processing...';

            const formData = new FormData(document.getElementById('importForm'));
            fetch('admin_SF1_Import_Action.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('importProgressBar').style.width = '100%';
                    document.getElementById('importProgressBar').classList.remove('progress-bar-animated');
                    document.getElementById('importStatus').textContent = 'Done.';

                    document.getElementById('importResults').style.display = '';
                    const created = data.created || 0;
                    const skipped = data.skipped || 0;
                    const errors = data.errors || 0;
                    document.getElementById('importSummary').innerHTML =
                        '<div class="d-flex gap-3 flex-wrap">' +
                        '<span class="badge bg-success fs-6"><i class="bi bi-person-plus me-1"></i>' + created + ' Created</span>' +
                        '<span class="badge bg-warning text-dark fs-6"><i class="bi bi-skip-forward me-1"></i>' + skipped + ' Skipped</span>' +
                        '<span class="badge bg-danger fs-6"><i class="bi bi-exclamation-triangle me-1"></i>' + errors + ' Errors</span>' +
                        '</div>';

                    const log = document.getElementById('importLog');
                    const rows = data.rows || [];
                    if (rows.length === 0) {
                        log.innerHTML = '<p class="text-muted">No row details available.</p>';
                    } else {
                        log.innerHTML = '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Row</th><th>Name</th><th>LRN</th><th>Status</th><th>Message</th></tr></thead><tbody>' +
                            rows.map(r => '<tr class="' + (r.status === 'created' ? 'table-success' : r.status === 'skipped' ? 'table-warning' : 'table-danger') + '">' +
                                '<td>' + r.row + '</td><td>' + escHtml(r.name || '') + '</td><td>' + escHtml(r.lrn || '') + '</td>' +
                                '<td><span class="badge bg-' + (r.status === 'created' ? 'success' : r.status === 'skipped' ? 'warning text-dark' : 'danger') + '">' + r.status + '</span></td>' +
                                '<td>' + escHtml(r.message || '') + '</td></tr>').join('') +
                            '</tbody></table>';
                    }

                    if (data.success) {
                        showNotification(created + ' students imported successfully!', 'success');
                        loadStudents(1);
                    } else {
                        showNotification(data.message || 'Import completed with errors.', 'warning');
                    }
                })
                .catch(err => {
                    document.getElementById('importStatus').textContent = 'Error.';
                    showNotification('Import failed. Please try again.', 'danger');
                    console.error(err);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-upload me-1"></i>Import Students';
                });
        }
    </script>
<?php include '../includes/footer.php'; ?>
