<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin Dashboard - Attendance and Academic Management System
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/Login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

// Fetch statistics
$stats = [
    'total_users' => 0,
    'total_classes' => 0,
    'today_attendance' => 0,
    'active_announcements' => 0,
    'grade11_students' => 0,
    'grade12_students' => 0
];
$genderByGrade = [
    'grade11' => ['male' => 0, 'female' => 0],
    'grade12' => ['male' => 0, 'female' => 0]
];
$sectionEnrollmentData = [];
$recentUsers = [];
$classes = [];
$announcements = [];
$archives = [];

if ($db) {
    // Batched stats: total users, total classes, today's attendance, active announcements
    $row = $db->query("SELECT
        (SELECT COUNT(*) FROM users WHERE status <> 'inactive' AND role <> 'admin') AS total_users,
        (SELECT COUNT(*) FROM classes WHERE status = 'active') AS total_classes,
        (SELECT COUNT(CASE WHEN status = 'present' THEN 1 END) FROM attendance WHERE date = CURDATE()) AS present_today,
        (SELECT COUNT(*) FROM attendance WHERE date = CURDATE()) AS total_today,
        (SELECT COUNT(*) FROM announcements WHERE status = 'active') AS active_announcements
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['total_users'] = (int)($row['total_users'] ?? 0);
    $stats['total_classes'] = (int)($row['total_classes'] ?? 0);
    $present = (int)($row['present_today'] ?? 0);
    $total = (int)($row['total_today'] ?? 0);
    $stats['today_attendance'] = $total > 0 ? round(($present / $total) * 100, 1) : 0;
    $stats['active_announcements'] = (int)($row['active_announcements'] ?? 0);

    // Student counts by grade level + gender in one query
    $query = "SELECT
                grade_level,
                LOWER(TRIM(COALESCE(sex, ''))) AS sex,
                COUNT(*) AS total
              FROM users
              WHERE role = 'student'
                AND status = 'active'
                AND grade_level IN (11, 12)
                AND LOWER(TRIM(COALESCE(sex, ''))) IN ('male', 'female')
              GROUP BY grade_level, LOWER(TRIM(COALESCE(sex, '')))";
    $stmt = $db->query($query);
    $genderRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($genderRows as $row) {
        $grade = (int)($row['grade_level'] ?? 0);
        $sex = strtolower(trim((string)($row['sex'] ?? '')));
        $count = (int)($row['total'] ?? 0);
        if ($grade === 11) {
            $stats['grade11_students'] += $count;
            if (isset($genderByGrade['grade11'][$sex])) $genderByGrade['grade11'][$sex] = $count;
        } elseif ($grade === 12) {
            $stats['grade12_students'] += $count;
            if (isset($genderByGrade['grade12'][$sex])) $genderByGrade['grade12'][$sex] = $count;
        }
    }

    // Enrolled students per section (distinct students) for active classes
    $query = "SELECT
                c.grade_level,
                c.section,
                COUNT(DISTINCT e.student_id) AS enrolled_total
              FROM classes c
              LEFT JOIN enrollments e
                ON e.class_id = c.id
               AND COALESCE(e.status, 'enrolled') = 'enrolled'
              WHERE c.status = 'active'
              GROUP BY c.grade_level, c.section
              ORDER BY c.grade_level ASC, c.section ASC";
    $stmt = $db->query($query);
    $sectionRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($sectionRows as $row) {
        $grade = (int)($row['grade_level'] ?? 0);
        $section = trim((string)($row['section'] ?? ''));
        if ($section === '') continue;
        $sectionEnrollmentData[] = [
            'label' => 'G' . $grade . ' - ' . $section,
            'total' => (int)($row['enrolled_total'] ?? 0)
        ];
    }
    
    // Recent users
    $query = "SELECT id, reference_code, first_name, last_name, email, role, status, created_at 
               FROM users ORDER BY created_at DESC LIMIT 4";
    $recentUsers = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Classes with teacher info
    $query = "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
               (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id) as student_count
               FROM classes c 
               LEFT JOIN users u ON c.teacher_id = u.id 
               WHERE c.status = 'active' LIMIT 4";
    $classes = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent announcements
    $query = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as posted_by_name
               FROM announcements a 
               JOIN users u ON a.posted_by = u.id 
               WHERE a.status = 'active'
               ORDER BY a.created_at DESC LIMIT 3";
    $announcements = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent archives (announcements as archives example)
    $query = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as posted_by_name
               FROM announcements a 
               JOIN users u ON a.posted_by = u.id 
               ORDER BY a.created_at DESC LIMIT 3";
    $archives = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
}

$totalStudents = (int)$stats['grade11_students'] + (int)$stats['grade12_students'];
$sectionCount = count($sectionEnrollmentData);
$latestAnnouncement = $announcements[0]['title'] ?? 'No active announcement yet';
$latestAnnouncementMeta = !empty($announcements[0]['created_at'])
    ? date('M d, Y h:i A', strtotime((string)$announcements[0]['created_at']))
    : 'Waiting for the next school update';
$topSection = $sectionEnrollmentData[0]['label'] ?? 'No active section';
$topSectionCount = $sectionEnrollmentData[0]['total'] ?? 0;

$current_role = 'admin';
$current_page = 'dashboard';
$page_title = 'Admin Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Balingasag Senior High School</title>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        
        <div class="page-content">
            <section class="admin-hero mb-4">
                <div class="admin-hero-grid">
                    <div class="admin-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-shield-check"></i><span>System Administration</span></div>
                        <h3 class="welcome-title mb-2" id="dashboardGreeting">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</h3>
                        <p class="welcome-meta mb-3">
                            Monitor the school operations hub, keep data moving across roles, and stay ahead of attendance, enrollment, and report activity.
                        </p>
                        <div class="admin-hero-metrics">
                            <div class="admin-hero-metric">
                                <span>Reference</span>
                                <strong><?php echo htmlspecialchars($_SESSION['reference_code'] ?? 'Admin Control Center'); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>School Year</span>
                                <strong><?php echo date('Y') . '-' . (date('Y') + 1); ?></strong>
                            </div>
                            <div class="admin-hero-metric">
                                <span>Today</span>
                                <strong><?php echo date('l, F d, Y'); ?></strong>
                            </div>
                        </div>
                        <div class="admin-action-strip">
                            <a href="Admin_Users.php" class="btn btn-primary-custom">
                                <i class="bi bi-people me-1"></i>Manage Users
                            </a>
                            <a href="Admin_Grade_Approvals.php" class="btn btn-outline-primary">
                                <i class="bi bi-folder-check me-1"></i>Review Approvals
                            </a>
                            <a href="admin_Reports.php" class="btn btn-outline-primary">
                                <i class="bi bi-bar-chart-line me-1"></i>Open Reports
                            </a>
                        </div>
                    </div>
                    <div class="admin-hero-side">
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Latest announcement</span>
                            <strong><?php echo htmlspecialchars((string)$latestAnnouncement); ?></strong>
                            <small><?php echo htmlspecialchars((string)$latestAnnouncementMeta); ?></small>
                        </div>
                        <div class="admin-focus-card">
                            <span class="admin-focus-label">Largest active section</span>
                            <strong><?php echo htmlspecialchars((string)$topSection); ?></strong>
                            <small><?php echo number_format((int)$topSectionCount); ?> enrolled students</small>
                        </div>
                    </div>
                </div>    
            </section>

            <div class="admin-stats-grid mb-4">
                <div class="dashboard-card animate-fade-in">
                    <div class="card-icon blue">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="card-title">Total Users</div>
                    <div class="card-value"><?php echo number_format($stats['total_users']); ?></div>
                </div>
                
                <div class="dashboard-card animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="card-icon green">
                        <i class="bi bi-journal-bookmark-fill"></i>
                    </div>
                    <div class="card-title">Total Classes</div>
                    <div class="card-value"><?php echo number_format($stats['total_classes']); ?></div>
                </div>
                
                <div class="dashboard-card animate-fade-in" style="animation-delay: 0.2s;">
                    <div class="card-icon orange">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                    <div class="card-title">Today's Attendance</div>
                    <div class="card-value"><?php echo $stats['today_attendance']; ?>%</div>
                </div>
                
                <div class="dashboard-card animate-fade-in" style="animation-delay: 0.3s;">
                    <div class="card-icon red">
                        <i class="bi bi-megaphone-fill"></i>
                    </div>
                    <div class="card-title">Active Announcements</div>
                    <div class="card-value"><?php echo number_format($stats['active_announcements']); ?></div>
                </div>

                <div class="dashboard-card animate-fade-in" style="animation-delay: 0.4s;">
                    <div class="card-icon blue">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div class="card-title">Grade 11 Students</div>
                    <div class="card-value"><?php echo number_format($stats['grade11_students']); ?></div>
                </div>

                <div class="dashboard-card animate-fade-in" style="animation-delay: 0.5s;">
                    <div class="card-icon green">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div class="card-title">Grade 12 Students</div>
                    <div class="card-value"><?php echo number_format($stats['grade12_students']); ?></div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Operations Snapshot</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="admin-summary-grid">
                                <div class="admin-summary-card">
                                    <span>Active student body</span>
                                    <strong><?php echo number_format($totalStudents); ?></strong>
                                    <small>Combined Grade 11 and Grade 12 learners</small>
                                </div>
                                <div class="admin-summary-card">
                                    <span>Tracked sections</span>
                                    <strong><?php echo number_format($sectionCount); ?></strong>
                                    <small>Active class sections with enrollment data</small>
                                </div>
                                <div class="admin-summary-card">
                                    <span>Attendance pulse</span>
                                    <strong><?php echo $stats['today_attendance']; ?>%</strong>
                                    <small>Present rate recorded for <?php echo date('F d, Y'); ?></small>
                                </div>
                                <div class="admin-summary-card">
                                    <span>Announcements live</span>
                                    <strong><?php echo number_format($stats['active_announcements']); ?></strong>
                                    <small>Current notices visible to school users</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Quick Actions</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="admin-quick-stack">
                                <a href="Admin_Users.php" class="quick-action">
                                    <div class="quick-action-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);">
                                        <i class="bi bi-person-plus"></i>
                                    </div>
                                    <span class="quick-action-text">Add New User</span>
                                </a>
                                <a href="Admin_Classes.php" class="quick-action">
                                    <div class="quick-action-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);">
                                        <i class="bi bi-journal-plus"></i>
                                    </div>
                                    <span class="quick-action-text">Create New Class</span>
                                </a>
                                <a href="Admin_Announcements.php" class="quick-action">
                                    <div class="quick-action-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning-color);">
                                        <i class="bi bi-megaphone"></i>
                                    </div>
                                    <span class="quick-action-text">Post Announcement</span>
                                </a>
                                <a href="admin_Reports.php" class="quick-action">
                                    <div class="quick-action-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger-color);">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </div>
                                    <span class="quick-action-text">Generate Report</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title mb-0">Grade 11: Male vs Female</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="gender-chart-wrap">
                                <canvas id="grade11GenderChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title mb-0">Grade 12: Male vs Female</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="gender-chart-wrap">
                                <canvas id="grade12GenderChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title mb-0">Enrolled Students Per Section</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="gender-chart-wrap">
                                <canvas id="sectionEnrollmentChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title">School Announcements</h5>
                            <a href="Admin_Announcements.php" class="btn btn-sm btn-primary-custom">View All</a>
                        </div>
                        <div class="content-card-body">
                            <?php if (empty($announcements)): ?>
                                <div class="text-center text-muted py-3">No announcements available.</div>
                            <?php else: ?>
                                <?php foreach ($announcements as $a): ?>
                                    <div class="announcement-card info mb-3">
                                        <div class="announcement-header">
                                            <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                            <span class="announcement-badge info"><?php echo htmlspecialchars($a['category'] ?? 'General'); ?></span>
                                        </div>
                                        <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                                        <div class="announcement-meta">
                                            <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($a['posted_by_name'] ?: 'Unknown'); ?></span>
                                            <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Recently Added Users</h5>
                            <a href="Admin_Users.php" class="btn btn-sm btn-primary-custom">View All</a>
                        </div>
                        <div class="content-card-body">
                            <?php foreach ($recentUsers as $user): ?>
                            <div class="user-list-item">
                                <div class="user-avatar-small" style="background: <?php echo $user['role'] === 'admin' ? 'var(--secondary-color)' : ($user['role'] === 'teacher' ? 'var(--accent-color)' : ($user['role'] === 'student' ? 'var(--warning-color)' : 'var(--danger-color)')); ?>">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                                <div class="user-list-info">
                                    <h5><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                                    <p><?php echo ucfirst($user['role']); ?><?php if (!empty($user['reference_code'])): ?> • <?php echo htmlspecialchars((string)$user['reference_code']); ?><?php endif; ?> • Added <?php echo date('M d', strtotime($user['created_at'])); ?></p>
                                </div>
                                <span class="status-badge status-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-2">
                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="content-card-title">Classes Overview</h5>
                            <a href="Admin_Classes.php" class="btn btn-sm btn-primary-custom">Manage Classes</a>
                        </div>
                        <div class="content-card-body">
                            <div class="row g-4">
                                <?php foreach ($classes as $index => $class): ?>
                                <div class="col-md-6 col-lg-3">
                                    <div class="class-card">
                                        <div class="class-card-header" style="background: <?php echo ['linear-gradient(135deg, var(--primary-color), var(--secondary-color))', 'linear-gradient(135deg, var(--accent-color), #059669)', 'linear-gradient(135deg, var(--warning-color), #d97706)', 'linear-gradient(135deg, var(--danger-color), #dc2626)'][$index % 4]; ?>">
                                            <div class="class-card-icon">
                                                <i class="bi bi-journal-bookmark"></i>
                                            </div>
                                        </div>
                                        <div class="class-card-body">
                                            <h4 class="class-card-title"><?php echo htmlspecialchars($class['class_name']); ?></h4>
                                            <p class="class-card-subtitle"><?php echo htmlspecialchars($class['teacher_name'] ?? 'No Teacher'); ?></p>
                                            <div class="class-card-stats">
                                                <span class="class-card-stat">
                                                    <i class="bi bi-people"></i> <?php echo $class['student_count']; ?> Students
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hour = new Date().getHours();
            let greeting = "Welcome";
            if (hour < 12) {
                greeting = "Good Morning";
            } else if (hour < 18) {
                greeting = "Good Afternoon";
            } else {
                greeting = "Good Evening";
            }
            const greetingEl = document.getElementById('dashboardGreeting');
            if (greetingEl) {
                const userName = <?php echo json_encode($_SESSION['first_name'] ?? 'Admin', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                greetingEl.innerText = greeting + ", " + userName + "!";
            }
        });
    </script>
    <script>
        (function() {
            const gradeGenderData = <?php echo json_encode($genderByGrade, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const sectionEnrollmentData = <?php echo json_encode($sectionEnrollmentData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const chartConfig = {
                type: 'pie',
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        datalabels: {
                            color: '#ffffff',
                            font: {
                                weight: '600',
                                size: 12
                            },
                            formatter: function(value, context) {
                                const dataset = context.dataset.data || [];
                                const total = dataset.reduce((sum, v) => sum + Number(v || 0), 0);
                                if (total <= 0) return '';
                                const pct = (Number(value || 0) / total) * 100;
                                return pct >= 1 ? pct.toFixed(1) + '%' : '';
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = Number(context.parsed || 0);
                                    const dataset = context.dataset.data || [];
                                    const total = dataset.reduce((sum, v) => sum + Number(v || 0), 0);
                                    const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                    return context.label + ': ' + value + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            };

            function renderGenderChart(canvasId, dataObj) {
                const canvas = document.getElementById(canvasId);
                if (!canvas || typeof Chart === 'undefined') return;
                const male = Number(dataObj?.male || 0);
                const female = Number(dataObj?.female || 0);
                const total = male + female;
                if (total === 0) {
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.font = '14px Poppins, sans-serif';
                    ctx.fillStyle = '#6b7280';
                    ctx.textAlign = 'center';
                    ctx.fillText('No data available', canvas.width / 2, 40);
                    return;
                }

                const cfg = JSON.parse(JSON.stringify(chartConfig));
                cfg.data = {
                    labels: ['Male', 'Female'],
                    datasets: [{
                        data: [male, female],
                        backgroundColor: ['#3b82f6', '#ec4899'],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                };
                cfg.plugins = [ChartDataLabels];
                new Chart(canvas, cfg);
            }

            renderGenderChart('grade11GenderChart', gradeGenderData.grade11);
            renderGenderChart('grade12GenderChart', gradeGenderData.grade12);

            (function renderSectionEnrollmentChart() {
                const canvas = document.getElementById('sectionEnrollmentChart');
                if (!canvas || typeof Chart === 'undefined') return;
                if (!Array.isArray(sectionEnrollmentData) || sectionEnrollmentData.length === 0) {
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.font = '14px Poppins, sans-serif';
                    ctx.fillStyle = '#6b7280';
                    ctx.textAlign = 'center';
                    ctx.fillText('No section enrollment data available', canvas.width / 2, 40);
                    return;
                }

                const labels = sectionEnrollmentData.map(item => String(item.label || ''));
                const values = sectionEnrollmentData.map(item => Number(item.total || 0));

                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Enrolled Students',
                            data: values,
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: '#3b82f6',
                            borderWidth: 1.5,
                            borderRadius: 6,
                            maxBarThickness: 42
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Enrolled: ' + Number(context.parsed.y || 0);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            },
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 0
                                }
                            }
                        }
                    }
                });
            })();
        })();
    </script>
<?php include '../includes/footer.php'; ?>





