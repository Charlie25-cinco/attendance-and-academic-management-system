<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Class Detail Page
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/Login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$classId = $_GET['id'] ?? '';

if (empty($classId) || !$db) {
    header("Location: Admin_Classes.php");
    exit();
}

// Fetch class details
$classQuery = "SELECT c.*, 
               (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ')
                FROM class_subjects cs 
                JOIN users u ON cs.teacher_id = u.id 
                WHERE cs.class_id = c.id AND u.status IN ('active', 'pending')) as teacher_name
               FROM classes c 
               WHERE c.id = ? AND c.status = 'active'";
$stmt = $db->prepare($classQuery);
$stmt->execute([$classId]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: Admin_Classes.php");
    exit();
}

// Fetch enrolled students
$studentsQuery = "SELECT u.id, u.first_name, u.last_name, u.reference_code, e.enrolled_at
                  FROM enrollments e
                  JOIN users u ON e.student_id = u.id
                  WHERE e.class_id = ? AND e.status = 'enrolled' AND u.status = 'active'
                  ORDER BY u.last_name, u.first_name";
$stmt = $db->prepare($studentsQuery);
$stmt->execute([$classId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch attendance summary
$attendanceQuery = "SELECT 
                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                    COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                    COUNT(*) as total_records
                    FROM attendance 
                    WHERE class_id = ? AND date = CURDATE()";
$stmt = $db->prepare($attendanceQuery);
$stmt->execute([$classId]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

$current_role = 'admin';
$current_page = 'classes';
$page_title = 'Class Details - ' . $class['class_name'];
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
            <!-- Back Button -->
            <a href="Admin_Classes.php" class="btn btn-link text-decoration-none mb-3">
                <i class="bi bi-arrow-left me-2"></i>Back to Classes
            </a>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($class['class_name']); ?></h4>
                    <p class="text-muted mb-0">Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['section']); ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="Admin_Class_Edit.php?id=<?php echo $classId; ?>" class="btn btn-secondary-custom">
                        <i class="bi bi-pencil me-2"></i>Edit Class
                    </a>
                </div>
            </div>

            <!-- Class Info Card -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Class Information</h5>
                </div>
                <div class="content-card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="text-muted small">Teacher(s)</label>
                            <p class="mb-0 fw-medium"><?php echo htmlspecialchars($class['teacher_name'] ?: 'Not Assigned'); ?></p>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="text-muted small">Schedule</label>
                            <p class="mb-0 fw-medium"><?php echo htmlspecialchars($class['schedule'] ?: 'Not Set'); ?></p>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="text-muted small">Room</label>
                            <p class="mb-0 fw-medium"><?php echo htmlspecialchars($class['room'] ?: 'Not Assigned'); ?></p>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="text-muted small">Total Students</label>
                            <p class="mb-0 fw-medium"><?php echo count($students); ?> Students</p>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="text-muted small">Grading Weights</label>
                            <p class="mb-0 fw-medium">
                                WW: <?php echo htmlspecialchars($class['ww_weight'] ?? '25'); ?>% |
                                PT: <?php echo htmlspecialchars($class['pt_weight'] ?? '50'); ?>% |
                                Assessment: <?php echo htmlspecialchars($class['assessment_weight'] ?? '25'); ?>%
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Summary -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon green"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="card-title">Present Today</div>
                        <div class="card-value"><?php echo $attendance['present_count'] ?? 0; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon red"><i class="bi bi-x-circle-fill"></i></div>
                        <div class="card-title">Absent Today</div>
                        <div class="card-value"><?php echo $attendance['absent_count'] ?? 0; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-icon orange"><i class="bi bi-clock-fill"></i></div>
                        <div class="card-title">Late Today</div>
                        <div class="card-value"><?php echo $attendance['late_count'] ?? 0; ?></div>
                    </div>
                </div>
            </div>

            <!-- Enrolled Students -->
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>Enrolled Students (<?php echo count($students); ?>)</h5>
                </div>
                <div class="content-card-body p-0">
                    <?php if (count($students) > 0): ?>
                    <div class="table-responsive">
                        <table class="table custom-table mb-0">
                            <thead>
                                <tr>
                                    <th>Reference Code</th>
                                    <th>Student Name</th>
                                    <th>Enrolled Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($student['reference_code']); ?></span></td>
                                    <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($student['enrolled_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0">No students enrolled in this class yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>








