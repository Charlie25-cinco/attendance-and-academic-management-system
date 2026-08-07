<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$studentId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'student';
$current_page = 'attendance';
$page_title = 'Attendance';

$selectedClassId = (int)($_GET['class_id'] ?? 0);
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d');
}

$classes = [];
$rows = [];
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'rate' => 0];

if ($db && $studentId > 0) {
    $classStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                               FROM enrollments e
                               JOIN classes c ON c.id = e.class_id
                               WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                               ORDER BY c.grade_level, c.section, c.class_name");
    $classStmt->execute([$studentId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $allowedIds = array_map('intval', array_column($classes, 'id'));
        if ($selectedClassId <= 0 || !in_array($selectedClassId, $allowedIds, true)) {
            $selectedClassId = 0;
        }

        $where = [
            "a.student_id = ?",
            "a.date BETWEEN ? AND ?",
            "e.student_id = ?",
            "e.status = 'enrolled'"
        ];
        $params = [$studentId, $fromDate, $toDate, $studentId];

        if ($selectedClassId > 0) {
            $where[] = "a.class_id = ?";
            $params[] = $selectedClassId;
        }

        $query = "SELECT a.date, a.status, a.time_in, a.remarks, c.class_name, c.grade_level, c.section
                  FROM attendance a
                  JOIN classes c ON c.id = a.class_id
                  JOIN enrollments e ON e.class_id = a.class_id
                  WHERE " . implode(" AND ", $where) . "
                  ORDER BY a.date DESC, c.class_name";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary['total'] = count($rows);
        foreach ($rows as $row) {
            $status = $row['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        $lateToAbsent = intdiv((int)$summary['late'], 3);
        $summary['late'] = (int)$summary['late'] % 3;
        $summary['absent'] = (int)$summary['absent'] + $lateToAbsent;
        if ($summary['total'] > 0) {
            $summary['rate'] = round((($summary['present'] + $summary['late']) / $summary['total']) * 100, 1);
        }
    }
}

$selectedClassLabel = 'All enrolled classes';
foreach ($classes as $class) {
    if ((int)$class['id'] === $selectedClassId) {
        $selectedClassLabel = (string)$class['class_name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <section class="student-hero student-hero-compact mb-4">
            <div class="student-hero-grid">
                <div class="student-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-calendar-check"></i><span>Student Attendance</span></div>
                    <h4 class="mb-2">Track your attendance history with clearer filters.</h4>
                    <p class="text-muted mb-3">Review present, absent, and late records across all enrolled classes or narrow the view to a specific class and date range.</p>
                    <div class="student-chip-row">
                        <span class="student-chip"><i class="bi bi-journal-text"></i><?php echo htmlspecialchars($selectedClassLabel); ?></span>
                        <span class="student-chip"><i class="bi bi-calendar-range"></i><?php echo date('M d, Y', strtotime($fromDate)); ?> to <?php echo date('M d, Y', strtotime($toDate)); ?></span>
                    </div>
                </div>
                <div class="student-hero-side">
                    <div class="student-summary-panel">
                        <div class="student-summary-grid">
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Attendance Rate</span>
                                <strong class="student-summary-value"><?php echo $summary['rate']; ?>%</strong>
                            </div>
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Records Found</span>
                                <strong class="student-summary-value"><?php echo number_format((int)$summary['total']); ?></strong>
                            </div>
                        </div>
                        <p class="student-summary-note">Use the filter below to compare attendance across classes and date windows.</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="student-overview-grid mb-4">
            <div class="student-overview-card"><strong><?php echo (int)$summary['present']; ?></strong><span>Present</span></div>
            <div class="student-overview-card"><strong><?php echo (int)$summary['absent']; ?></strong><span>Absent</span></div>
            <div class="student-overview-card"><strong><?php echo (int)$summary['late']; ?></strong><span>Late</span></div>
            <div class="student-overview-card"><strong><?php echo number_format((int)$summary['total']); ?></strong><span>Total Records</span></div>
        </div>

        <div class="content-card mb-4">
            <div class="content-card-body py-3">
                <div class="student-filter-toolbar">
                    <div class="student-filter-copy">
                        <strong>Current attendance filter</strong>
                        <small><?php echo htmlspecialchars($selectedClassLabel); ?> • From <?php echo htmlspecialchars(date('M d, Y', strtotime($fromDate))); ?> • To <?php echo htmlspecialchars(date('M d, Y', strtotime($toDate))); ?></small>
                    </div>
                    <div class="text-muted small">
                        <?php echo number_format((int)$summary['total']); ?> record<?php echo (int)$summary['total'] === 1 ? '' : 's'; ?> found
                    </div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="content-card-header">
                <h5 class="content-card-title">Attendance History</h5>
                <form method="GET" class="row g-2 align-items-end w-100 student-inline-filter app-responsive-filter-form">
                    <div class="col-md-5 col-lg-4">
                        <label class="form-label small text-muted mb-1">Class</label>
                        <select name="class_id" class="form-select form-select-sm">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo ((int)$class['id'] === $selectedClassId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$class['class_name']); ?><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?> <?php echo htmlspecialchars('(' . (!empty($class['grade_level']) ? 'G' . $class['grade_level'] : '') . ((!empty($class['grade_level']) && !empty($class['section'])) ? ' - ' : '') . (!empty($class['section']) ? $class['section'] : '') . ')'); ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-3">
                        <label class="form-label small text-muted mb-1">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                    </div>
                    <div class="col-md-3 col-lg-3">
                        <label class="form-label small text-muted mb-1">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                    </div>
                    <div class="col-md-1 col-lg-2 d-flex gap-2">
                        <button class="btn btn-sm btn-primary-custom flex-fill" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                        <a class="btn btn-sm btn-outline-secondary flex-fill" href="Student_Attendance.php">Reset</a>
                    </div>
                </form>
            </div>
            <div class="content-card-body">
                <div class="table-container">
                    <table class="custom-table">
                        <thead><tr><th>Date</th><th>Class</th><th>Status</th><th>Time In</th><th>Remarks</th></tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No attendance records for selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                                    <td><?php echo htmlspecialchars((string)$row['class_name']); ?><?php if (!empty($row['grade_level']) || !empty($row['section'])): ?> <?php echo htmlspecialchars('(' . (!empty($row['grade_level']) ? 'G' . $row['grade_level'] : '') . ((!empty($row['grade_level']) && !empty($row['section'])) ? ' - ' : '') . (!empty($row['section']) ? $row['section'] : '') . ')'); ?><?php endif; ?></td>
                                    <td><span class="attendance-<?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                    <td><?php echo !empty($row['time_in']) ? date('h:i A', strtotime($row['time_in'])) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars((string)(($row['remarks'] ?? '-') ?: '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
</body>
</html>
