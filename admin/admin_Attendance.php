<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Manage Attendance Page
// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/Login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$classes = [];
$attendanceRecords = [];

// Pagination settings
$recordsPerPage = 20;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $recordsPerPage;

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedClass = $_GET['class_id'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$searchQuery = $_GET['search'] ?? '';

$totalRecords = 0;

if ($db) {
    // Fetch classes for dropdown
    $classesQuery = "SELECT id, class_name, grade_level, section FROM classes WHERE status = 'active' ORDER BY grade_level, section";
    $classes = $db->query($classesQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    // Build attendance query with filters
    $whereConditions = ["a.date = ?"];
    $params = [$selectedDate];
    
    if ($selectedClass) {
        $whereConditions[] = "a.class_id = ?";
        $params[] = $selectedClass;
    }
    
    if ($selectedStatus) {
        $whereConditions[] = "a.status = ?";
        $params[] = $selectedStatus;
    }
    
    if ($searchQuery) {
        $whereConditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.reference_code LIKE ?)";
        $searchParam = "%$searchQuery%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM attendance a
                   JOIN users u ON a.student_id = u.id
                   WHERE $whereClause";
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $recordsPerPage);
    
    // Fetch attendance records with pagination
    $attendanceQuery = "SELECT a.*, 
                        CONCAT(u.first_name, ' ', u.last_name) as student_name,
                        u.reference_code,
                        c.class_name,
                        c.grade_level,
                        c.section
                        FROM attendance a
                        JOIN users u ON a.student_id = u.id
                        JOIN classes c ON a.class_id = c.id
                        WHERE $whereClause
                        ORDER BY a.time_in DESC, student_name
                        LIMIT $recordsPerPage OFFSET $offset";
    $stmt = $db->prepare($attendanceQuery);
    $stmt->execute($params);
    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
}

$current_role = 'admin';
$current_page = 'attendance';
$page_title = 'Attendance';

// Helper function to build pagination URL
function buildPageUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
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
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Attendance</h4>
                    <p class="text-muted mb-0">Review attendance by class, date, and student performance trends.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-secondary-custom" onclick="exportToPDF()">
                        <i class="bi bi-download me-2"></i>Export Report
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form method="GET" action="">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $selectedDate; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Class</label>
                                <select name="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'] . ' - G' . $class['grade_level']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="present" <?php echo $selectedStatus == 'present' ? 'selected' : ''; ?>>Present</option>
                                    <option value="absent" <?php echo $selectedStatus == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                    <option value="late" <?php echo $selectedStatus == 'late' ? 'selected' : ''; ?>>Late</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary-custom w-100">
                                    <i class="bi bi-funnel me-2"></i>Apply Filters
                                </button>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><i class="bi bi-search me-1"></i>Search Student</label>
                                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Enter student name or reference code...">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-secondary-custom w-100">
                                    <i class="bi bi-search me-2"></i>Search
                                </button>
                            </div>
                            <?php if ($searchQuery || $selectedClass || $selectedStatus || $selectedDate != date('Y-m-d')): ?>
                            <div class="col-md-2 d-flex align-items-end">
                                <a href="Admin_Attendance.php" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-x-circle me-2"></i>Clear All
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="content-card-title mb-0">
                            Attendance Records - <?php echo date('F d, Y', strtotime($selectedDate)); ?>
                            <span class="text-muted fs-6">(<?php echo $totalRecords; ?> total)</span>
                        </h5>
                        <?php if ($totalRecords > 0): ?>
                        <span class="badge badge-success">Completed</span>
                        <?php else: ?>
                        <span class="badge badge-warning">No Records</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="table-container">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Reference Code</th>
                                    <th>Class</th>
                                    <th>Time In</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($attendanceRecords) > 0): ?>
                                    <?php foreach ($attendanceRecords as $record): 
                                        $initials = strtoupper(substr($record['student_name'], 0, 1) . substr(strrchr($record['student_name'], ' '), 1, 1));
                                        $statusClass = '';
                                        switch($record['status']) {
                                            case 'present': $statusClass = 'status-active'; break;
                                            case 'absent': $statusClass = 'status-inactive'; break;
                                            case 'late': $statusClass = 'status-pending'; break;
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="user-list-item" style="padding: 0; border: none;">
                                                <div class="user-avatar-small" style="background: var(--secondary-color);"><?php echo $initials; ?></div>
                                                <span><?php echo htmlspecialchars($record['student_name']); ?></span>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['reference_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($record['class_name'] . ' - G' . $record['grade_level']); ?></td>
                                        <td><?php echo $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '-'; ?></td>
                                        <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($record['status']); ?></span></td>
                                        <td><?php echo htmlspecialchars($record['remarks'] ?: '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5">
                                            <i class="bi bi-inbox fs-1 text-muted"></i>
                                            <p class="text-muted mt-2 mb-0">No attendance records found.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="text-muted">
                            Showing <?php echo (($currentPage - 1) * $recordsPerPage) + 1; ?> - 
                            <?php echo min($currentPage * $recordsPerPage, $totalRecords); ?> 
                            of <?php echo $totalRecords; ?> records
                        </div>
                        <nav aria-label="Attendance pagination">
                            <ul class="pagination mb-0">
                                <!-- Previous -->
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $currentPage > 1 ? buildPageUrl($currentPage - 1) : '#'; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Page Numbers -->
                                <?php 
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1): 
                                ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo buildPageUrl(1); ?>">1</a></li>
                                    <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i == $currentPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo buildPageUrl($totalPages); ?>"><?php echo $totalPages; ?></a></li>
                                <?php endif; ?>
                                
                                <!-- Next -->
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $currentPage < $totalPages ? buildPageUrl($currentPage + 1) : '#'; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function escapeHtml(text) {
            return String(text ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getAttendanceExportData() {
            const table = document.querySelector('.custom-table');
            if (!table) return { headers: [], rows: [] };

            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const rows = Array.from(table.querySelectorAll('tbody tr'))
                .filter(tr => !tr.querySelector('td[colspan]'))
                .map(tr => Array.from(tr.querySelectorAll('td')).map(td => td.textContent.replace(/\s+/g, ' ').trim()));

            return { headers, rows };
        }

        function exportToPDF() {
            const { headers, rows } = getAttendanceExportData();
            if (!rows.length) {
                showNotification('No attendance records to export.', 'warning');
                return;
            }

            const selectedDate = document.querySelector('input[name="date"]')?.value || '';
            const selectedClass = document.querySelector('select[name="class_id"] option:checked')?.textContent?.trim() || 'All Classes';
            const selectedStatus = document.querySelector('select[name="status"] option:checked')?.textContent?.trim() || 'All Status';
            const search = document.querySelector('input[name="search"]')?.value?.trim() || 'None';
            const generatedAt = new Date().toLocaleString();

            const rowsHtml = rows.map(row => {
                const cells = row.map(cell => `<td>${escapeHtml(cell)}</td>`).join('');
                return `<tr>${cells}</tr>`;
            }).join('');
            const headerHtml = headers.map(h => `<th>${escapeHtml(h)}</th>`).join('');

            const printWindow = window.open('', '_blank', 'width=1024,height=768');
            if (!printWindow) {
                showNotification('Popup blocked. Please allow popups to export PDF.', 'warning');
                return;
            }

            printWindow.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Attendance Report</title>
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
  <h1>Attendance Report</h1>
  <p><strong>Date:</strong> ${escapeHtml(selectedDate || 'N/A')}</p>
  <p><strong>Class:</strong> ${escapeHtml(selectedClass)}</p>
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
    </script>
<?php include '../includes/footer.php'; ?>







