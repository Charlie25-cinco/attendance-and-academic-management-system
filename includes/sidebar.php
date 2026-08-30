<?php
// Sidebar Component - Attendance and Academic Management System
// Usage: Include this file and set $current_role and $current_page variables before including

if (session_status() === PHP_SESSION_NONE) {
}

// Default values if not set
if (!isset($current_role)) $current_role = 'admin';
if (!isset($current_page)) $current_page = 'dashboard';

// Define sidebar menus for each role
$sidebar_menus = [
    'admin' => [
        ['id' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'link' => 'admin.php'],
        ['id' => 'users', 'icon' => 'bi-people', 'label' => 'Manage Users', 'link' => 'admin_Users.php'],
        ['id' => 'enrollments', 'icon' => 'bi-person-video2', 'label' => 'Enrollments', 'link' => 'admin_Enrollments.php'],
        ['id' => 'classes', 'icon' => 'bi-journal-bookmark', 'label' => 'Manage Classes', 'link' => 'admin_Classes.php'],
        ['id' => 'sections', 'icon' => 'bi-diagram-3', 'label' => 'Sections', 'link' => 'admin_Sections.php'],
        ['id' => 'attendance', 'icon' => 'bi-calendar-check', 'label' => 'Attendance', 'link' => 'admin_Attendance.php'],
        ['id' => 'announcements', 'icon' => 'bi-megaphone', 'label' => 'Announcements', 'link' => 'admin_Announcements.php'],
        ['id' => 'grade_approvals', 'icon' => 'bi-check2-square', 'label' => 'Grade Approvals', 'link' => 'admin_Grade_Approvals.php'],
        ['id' => 'reports', 'icon' => 'bi-graph-up', 'label' => 'Reports', 'link' => 'admin_Reports.php'],
        ['id' => 'archives', 'icon' => 'bi-archive', 'label' => 'Archived Records', 'link' => 'admin_Archives.php'],
        ['id' => 'audit_logs', 'icon' => 'bi-shield-check', 'label' => 'Audit Logs', 'link' => 'admin_Audit_Logs.php'],
        ['id' => 'school_settings', 'icon' => 'bi-building-gear', 'label' => 'School Settings', 'link' => 'admin_School_Settings.php'],
        ['id' => 'rbac', 'icon' => 'bi-shield-lock', 'label' => 'RBAC Control Panel', 'link' => 'admin_RBAC.php'],
    ],
    'teacher' => [
        ['id' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'link' => 'teacher.php'],
        ['id' => 'attendance', 'icon' => 'bi-calendar-check', 'label' => 'Attendance', 'link' => 'teacher_Attendance.php'],
        ['id' => 'grades', 'icon' => 'bi-clipboard-data', 'label' => 'Grade Entry', 'link' => 'teacher_Grades.php'],
        ['id' => 'advisory', 'icon' => 'bi-file-earmark-text', 'label' => 'Report Card', 'link' => 'teacher_Advisory.php'],
        ['id' => 'classes', 'icon' => 'bi-journal-bookmark', 'label' => 'Classes', 'link' => 'teacher_Classes.php'],
        ['id' => 'announcements', 'icon' => 'bi-megaphone', 'label' => 'Announcements', 'link' => 'teacher_Announcements.php'],
        ['id' => 'chat', 'icon' => 'bi-chat-dots', 'label' => 'Messages', 'link' => 'teacher_Chat.php'],
        ['id' => 'reports', 'icon' => 'bi-graph-up', 'label' => 'Reports', 'link' => 'teacher_Reports.php'],
        ['id' => 'archives', 'icon' => 'bi-archive', 'label' => 'Archives', 'link' => 'teacher_Archives.php'],
    ],
    'student' => [
        ['id' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'link' => 'Student.php'],
        ['id' => 'attendance', 'icon' => 'bi-calendar-check', 'label' => 'Attendance', 'link' => 'Student_Attendance.php'],
        ['id' => 'qr_code', 'icon' => 'bi-qr-code', 'label' => 'My QR Code', 'link' => 'Student_QR.php'],
        ['id' => 'classes', 'icon' => 'bi-journal-bookmark', 'label' => 'Classes', 'link' => 'Student_Classes.php'],
        ['id' => 'report_card', 'icon' => 'bi-file-earmark-text', 'label' => 'Report Card', 'link' => 'Student_Report_Card.php'],
        ['id' => 'announcements', 'icon' => 'bi-megaphone', 'label' => 'Announcements', 'link' => 'Student_Announcements.php'],
    ],
    'parent' => [
        ['id' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'link' => 'Parent.php'],
        ['id' => 'progress', 'icon' => 'bi-graph-up-arrow', 'label' => 'Student Progress', 'link' => 'Parent_Progress.php'],
        ['id' => 'report_card', 'icon' => 'bi-file-earmark-text', 'label' => 'Report Card', 'link' => 'Parent_Report_Card.php'],
        ['id' => 'announcements', 'icon' => 'bi-megaphone', 'label' => 'Announcements', 'link' => 'Parent_Announcements.php'],
        ['id' => 'chat', 'icon' => 'bi-chat-dots', 'label' => 'Messages', 'link' => 'Parent_Chat.php'],
    ],
];

$menu_items = $sidebar_menus[$current_role] ?? $sidebar_menus['admin'];

$sidebarUnreadMessages = 0;
if (!empty($_SESSION['logged_in']) && in_array($current_role, ['teacher', 'parent'], true)) {
    try {
        $sidebarDb = (new Database())->getConnection();
        if ($sidebarDb instanceof PDO && function_exists('dbHasTable') && dbHasTable($sidebarDb, 'messages')) {
            $msgCountStmt = $sidebarDb->prepare("SELECT COUNT(*) FROM messages WHERE to_user_id = ? AND is_read = 0");
            $msgCountStmt->execute([(int)$_SESSION['user_id']]);
            $sidebarUnreadMessages = (int)$msgCountStmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $sidebarUnreadMessages = 0;
    }
}
?>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <!-- Toggle Button -->
    <button class="sidebar-toggle" id="sidebarToggleBtn" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar navigation">
        <i class="bi bi-chevron-left" id="toggleIcon"></i>
    </button>

    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="../assets/images/bshs-logo.jpg" alt="School Logo" class="sidebar-logo-img">
        </div>
        <span class="sidebar-title" title="<?php
            $sidebarSchoolTitle = 'Balingasag SHS';
            $activeDb = $sidebarDb ?? ($db ?? null);
            if (isset($activeDb) && $activeDb instanceof PDO) {
                $dbSchool = getSchoolSetting($activeDb, 'school_name', '');
                if ($dbSchool !== '') {
                    $sidebarSchoolTitle = $dbSchool;
                }
            }
            echo htmlspecialchars($sidebarSchoolTitle);
        ?>"><?php echo htmlspecialchars($sidebarSchoolTitle); ?></span>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php foreach ($menu_items as $item): ?>
        <div class="nav-item">
            <a href="<?php echo $item['link']; ?>" class="nav-link <?php echo ($current_page === $item['id']) ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($item['label']); ?>" aria-label="<?php echo htmlspecialchars($item['label']); ?>">
                <i class="bi <?php echo $item['icon']; ?>"></i>
                <span class="nav-text"><?php echo $item['label']; ?></span>
                <?php if ($item['id'] === 'chat' && $sidebarUnreadMessages > 0): ?>
                <span class="badge bg-danger rounded-pill ms-auto me-1" style="font-size: 0.72rem; padding: 0.25em 0.55em;"><?php echo $sidebarUnreadMessages > 99 ? '99+' : $sidebarUnreadMessages; ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-system-text">Academic Management System v1.0.0</div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay" onclick="closeMobileSidebar()"></div>

<script>
(() => {
    try {
        if (window.innerWidth < 992) return;
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (!isCollapsed) return;

        const toggleIcon = document.getElementById('toggleIcon');
        sidebar.classList.add('collapsed', 'sidebar-no-transition');
        if (toggleIcon) {
            toggleIcon.classList.remove('bi-chevron-left');
            toggleIcon.classList.add('bi-chevron-right');
        }

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                sidebar.classList.remove('sidebar-no-transition');
            });
        });
    } catch (e) {}
})();
</script>
