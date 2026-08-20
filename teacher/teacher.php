<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Teacher Dashboard
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);

$formatSchedule = function ($schedule) {
    $schedule = trim((string)$schedule);
    if ($schedule === '') {
        return 'No schedule set';
    }

    $segments = array_filter(array_map('trim', preg_split('/\s*;\s*/', $schedule)));
    $formatted = [];
    foreach ($segments as $segment) {
        if (preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            $days = preg_replace('/\s*\/\s*/', ', ', trim($m[1]));
            $days = preg_replace('/\s*,\s*/', ', ', $days);
            $time = preg_replace('/\s+/', ' ', trim($m[2]));
            $formatted[] = $days . ' | ' . $time;
        } else {
            $formatted[] = $segment;
        }
    }
    return implode(' ; ', $formatted);
};

$parseScheduleEntries = function ($schedule) {
    $schedule = trim((string)$schedule);
    if ($schedule === '') {
        return [];
    }
    $segments = preg_split('/\s*;\s*/', $schedule);
    $entries = [];
    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if (!preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M\s*-\s*\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            continue;
        }
        $daysText = preg_replace('/\s*\/\s*/', ',', trim($m[1]));
        $dayParts = array_filter(array_map('trim', explode(',', $daysText)));
        $time = preg_replace('/\s+/', ' ', trim($m[2]));
        foreach ($dayParts as $part) {
            $abbr = ucfirst(strtolower(substr($part, 0, 3)));
            if (in_array($abbr, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], true)) {
                $entries[] = ['day' => $abbr, 'time' => $time];
            }
        }
    }
    return $entries;
};

$scheduleStartMinutes = function ($timeRange) {
    return ScheduleParser::startMinutes($timeRange);
};

$stats = ['class_count' => 0, 'student_total' => 0, 'today_rate' => 0, 'material_count' => 0];
$classes = [];
$announcements = [];
$classAnnouncements = [];
$weeklySchedule = ['Mon' => [], 'Tue' => [], 'Wed' => [], 'Thu' => [], 'Fri' => []];
$teacherWelcomeGradeSection = '';

if ($db) {
    $teacherProfileStmt = $db->prepare("SELECT grade_level, section
                                        FROM users
                                        WHERE id = ? AND role = 'teacher'
                                        LIMIT 1");
    $teacherProfileStmt->execute([$teacherId]);
    $teacherProfile = $teacherProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $profileGrade = isset($teacherProfile['grade_level']) ? (int)$teacherProfile['grade_level'] : 0;
    $profileSection = trim((string)($teacherProfile['section'] ?? ''));
    if ($profileGrade > 0 && $profileSection !== '') {
        $teacherWelcomeGradeSection = 'Grade ' . $profileGrade . ' - ' . $profileSection;
    } else {
        // Fallback only to advisory assignment (classes.teacher_id), not subject assignments.
        $advisoryStmt = $db->prepare("SELECT grade_level, section
                                      FROM classes
                                      WHERE teacher_id = ? AND status = 'active'
                                      ORDER BY id ASC
                                      LIMIT 1");
        $advisoryStmt->execute([$teacherId]);
        $advisory = $advisoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $advisoryGrade = isset($advisory['grade_level']) ? (int)$advisory['grade_level'] : 0;
        $advisorySection = trim((string)($advisory['section'] ?? ''));
        if ($advisoryGrade > 0 && $advisorySection !== '') {
            $teacherWelcomeGradeSection = 'Grade ' . $advisoryGrade . ' - ' . $advisorySection;
        }
    }

    $classStmt = $db->prepare("SELECT x.id, x.class_name, x.grade_level, x.section, x.schedule, x.room,
                               (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = x.id AND e.status = 'enrolled') AS student_count
                               FROM (
                                   SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule, c.room
                                   FROM classes c
                                   WHERE c.teacher_id = ? AND c.status = 'active'
                                   UNION
                                   SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule, c.room
                                   FROM class_subjects cs
                                   JOIN classes c ON c.id = cs.class_id
                                   WHERE cs.teacher_id = ? AND c.status = 'active'
                               ) x
                               ORDER BY x.grade_level, x.section, x.class_name");
    $classStmt->execute([$teacherId, $teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($classes as $class) {
        $entries = $parseScheduleEntries($class['schedule'] ?? '');
        foreach ($entries as $entry) {
            $day = $entry['day'] ?? '';
            if (!isset($weeklySchedule[$day])) {
                continue;
            }
            $weeklySchedule[$day][] = [
                'class_name' => $class['class_name'],
                'section' => $class['section'],
                'time' => $entry['time'] ?? '',
                'room' => $class['room']
            ];
        }
    }

    foreach ($weeklySchedule as $day => $slots) {
        usort($slots, function ($a, $b) use ($scheduleStartMinutes) {
            return $scheduleStartMinutes($a['time'] ?? '') <=> $scheduleStartMinutes($b['time'] ?? '');
        });
        $weeklySchedule[$day] = $slots;
    }

    $stats['class_count'] = count($classes);
    $stats['student_total'] = array_sum(array_map(function ($c) { return (int)$c['student_count']; }, $classes));

    if (!empty($classes)) {
        $classIds = array_map('intval', array_column($classes, 'id'));
        $ph = implode(',', array_fill(0, count($classIds), '?'));
        $attStmt = $db->prepare("SELECT
                                 COUNT(CASE WHEN status='present' THEN 1 END) AS present_count,
                                 COUNT(CASE WHEN status='late' THEN 1 END) AS late_count,
                                 COUNT(*) AS total
                                 FROM attendance
                                 WHERE date = CURDATE() AND class_id IN ($ph)");
        $attStmt->execute($classIds);
        $att = $attStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($att['total'])) {
            $stats['today_rate'] = round((($att['present_count'] + $att['late_count']) / $att['total']) * 100, 1);
        }
    }

    $mStmt = $db->prepare("SELECT COUNT(*) FROM materials WHERE uploaded_by = ?");
    $mStmt->execute([$teacherId]);
    $stats['material_count'] = (int)$mStmt->fetchColumn();

    $aStmt = $db->query("SELECT a.title, a.content, a.category, a.created_at,
                         CONCAT(u.first_name, ' ', u.last_name) AS posted_by_name
                         FROM announcements a
                         LEFT JOIN users u ON u.id = a.posted_by
                         WHERE a.status = 'active'
                         ORDER BY a.created_at DESC
                         LIMIT 5");
    $announcements = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($classes)) {
        $classIds = array_map('intval', array_column($classes, 'id'));
        $ph = implode(',', array_fill(0, count($classIds), '?'));
        $classAnnStmt = $db->prepare("SELECT ca.title, ca.content, ca.created_at, c.class_name
                                      FROM class_announcements ca
                                      JOIN classes c ON c.id = ca.class_id
                                      WHERE ca.class_id IN ($ph) AND ca.status = 'active'
                                      ORDER BY ca.created_at DESC
                                      LIMIT 5");
        $classAnnStmt->execute($classIds);
        $classAnnouncements = $classAnnStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$current_role = 'teacher';
$current_page = 'dashboard';
$page_title = 'Teacher Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
    <style>
        .weekly-schedule-grid { --schedule-accent: #0f766e; }
        .schedule-day-card {
            position: relative;
            border: 1px solid rgba(15, 118, 110, 0.12);
            border-radius: 20px;
            padding: 16px;
            min-height: 100%;
            background:
                radial-gradient(circle at top right, rgba(45, 212, 191, 0.18), transparent 38%),
                linear-gradient(180deg, #ffffff 0%, #f4fbfa 100%);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .schedule-day-card::after {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, #14b8a6 0%, #0f766e 100%);
        }
        .schedule-day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }
        .schedule-day-title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #0f172a;
        }
        .schedule-day-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 999px;
            background: rgba(15, 118, 110, 0.12);
            color: var(--schedule-accent);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .schedule-day-empty {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px dashed rgba(148, 163, 184, 0.5);
            border-radius: 16px;
            padding: 14px;
            color: #64748b;
            background: rgba(255, 255, 255, 0.65);
        }
        .schedule-day-empty i {
            font-size: 1rem;
            color: #94a3b8;
        }
        .schedule-slot-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .schedule-item {
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 16px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.88);
        }
        .schedule-item-time {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(20, 184, 166, 0.12);
            color: var(--schedule-accent);
            font-size: 0.74rem;
            font-weight: 700;
        }
        .schedule-item-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
            line-height: 1.35;
        }
        .schedule-item-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
            line-height: 1.45;
        }
        .schedule-item-meta i {
            color: #0f766e;
        }
        body.dark-mode .schedule-day-card {
            border-color: rgba(45, 212, 191, 0.16);
            background:
                radial-gradient(circle at top right, rgba(45, 212, 191, 0.16), transparent 38%),
                linear-gradient(180deg, #111827 0%, #0f172a 100%);
            box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
        }
        body.dark-mode .schedule-day-title,
        body.dark-mode .schedule-item-title {
            color: #f8fafc;
        }
        body.dark-mode .schedule-day-count {
            background: rgba(45, 212, 191, 0.14);
            color: #99f6e4;
        }
        body.dark-mode .schedule-day-empty,
        body.dark-mode .schedule-item {
            background: rgba(17, 24, 39, 0.82);
            border-color: rgba(148, 163, 184, 0.16);
            color: #cbd5e1;
        }
        body.dark-mode .schedule-day-empty i,
        body.dark-mode .schedule-item-meta,
        body.dark-mode .schedule-item-meta i {
            color: #94a3b8;
        }
        body.dark-mode .schedule-item-time {
            background: rgba(45, 212, 191, 0.14);
            color: #99f6e4;
        }
    </style>
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <div class="teacher-hero mb-4">
            <div class="teacher-hero-grid">
                <div class="teacher-hero-copy">
                    <div class="app-detail-kicker mb-2">Teacher Workspace</div>
                    <h4 class="mb-2" id="dashboardGreeting">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Teacher'); ?>!</h4>
                    <p class="mb-0">
                        <?php
                            $welcomeMetaParts = [];
                            if ($teacherWelcomeGradeSection !== '') $welcomeMetaParts[] = $teacherWelcomeGradeSection;
                            if (!empty($_SESSION['reference_code'])) $welcomeMetaParts[] = 'Reference: ' . $_SESSION['reference_code'];
                            if (empty($welcomeMetaParts)) $welcomeMetaParts[] = 'Classroom and academic management';
                            echo htmlspecialchars(implode(' | ', $welcomeMetaParts));
                        ?>
                    </p>
                    <div class="teacher-chip-row">
                        <span class="teacher-chip"><i class="bi bi-journal-bookmark-fill"></i><?php echo (int)$stats['class_count']; ?> active classes</span>
                        <span class="teacher-chip"><i class="bi bi-people-fill"></i><?php echo (int)$stats['student_total']; ?> total students</span>
                        <span class="teacher-chip"><i class="bi bi-calendar-check"></i><?php echo number_format((float)$stats['today_rate'], 1); ?>% attendance today</span>
                    </div>
                </div>
                <div class="teacher-summary-panel">
                    <div class="teacher-summary-grid">
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">Today</span>
                            <span class="teacher-summary-value"><?php echo date('M d'); ?></span>
                        </div>
                        <div class="teacher-summary-stat">
                            <span class="teacher-summary-label">School Year</span>
                            <span class="teacher-summary-value"><?php echo date('Y') . '-' . (date('Y') + 1); ?></span>
                        </div>
                    </div>
                    <p class="teacher-summary-note">Use this page as your daily teaching hub for attendance, grades, report cards, and class updates.</p>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-3">
            <div class="col-12">
                <div class="teacher-quick-grid">
                    <a href="teacher_Attendance.php" class="teacher-quick-card">
                        <span class="teacher-quick-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);"><i class="bi bi-calendar-check"></i></span>
                        <span class="teacher-quick-copy"><strong>Take Attendance</strong><span>Open the current class attendance workflow</span></span>
                    </a>
                    <a href="teacher_Grades.php" class="teacher-quick-card">
                        <span class="teacher-quick-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);"><i class="bi bi-clipboard-data"></i></span>
                        <span class="teacher-quick-copy"><strong>Enter Grades</strong><span>Update subject scores and approval-ready records</span></span>
                    </a>
                    <a href="teacher_Advisory.php" class="teacher-quick-card">
                        <span class="teacher-quick-icon" style="background: rgba(99, 102, 241, 0.12); color: #4f46e5;"><i class="bi bi-file-earmark-text"></i></span>
                        <span class="teacher-quick-copy"><strong>Report Cards</strong><span>Review advisory learners and submission status</span></span>
                    </a>
                    <a href="teacher_Classes.php" class="teacher-quick-card">
                        <span class="teacher-quick-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning-color);"><i class="bi bi-file-earmark-arrow-up"></i></span>
                        <span class="teacher-quick-copy"><strong>Upload Material</strong><span>Manage files and class learning resources</span></span>
                    </a>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">School Announcements</h5>
                        <a href="teacher_Announcements.php" class="btn btn-sm btn-primary-custom">View All</a>
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
                                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">Class Announcements</h5>
                        <a href="teacher_Announcements.php" class="btn btn-sm btn-primary-custom">View All</a>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($classAnnouncements)): ?>
                            <div class="text-center text-muted py-3">No class announcements available.</div>
                        <?php else: ?>
                            <?php foreach ($classAnnouncements as $a): ?>
                                <div class="announcement-card info mb-3">
                                    <div class="announcement-header">
                                        <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                        <span class="announcement-badge info"><?php echo htmlspecialchars($a['class_name']); ?></span>
                                    </div>
                                    <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                                    <div class="announcement-meta">
                                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-3">
            <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon blue"><i class="bi bi-journal-bookmark-fill"></i></div><div class="card-title">My Classes</div><div class="card-value"><?php echo $stats['class_count']; ?></div></div></div>
            <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon green"><i class="bi bi-people-fill"></i></div><div class="card-title">Total Students</div><div class="card-value"><?php echo $stats['student_total']; ?></div></div></div>
            <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon orange"><i class="bi bi-calendar-check-fill"></i></div><div class="card-title">Today's Attendance</div><div class="card-value"><?php echo $stats['today_rate']; ?>%</div></div></div>
            <div class="col-md-6 col-lg-3"><div class="dashboard-card"><div class="card-icon red"><i class="bi bi-file-earmark-text-fill"></i></div><div class="card-title">My Materials</div><div class="card-value"><?php echo $stats['material_count']; ?></div></div></div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header">
                        <div>
                            <h5 class="content-card-title mb-1">My Class Assignments</h5>
                            <div class="teacher-section-note">Review assigned classes, schedules, rooms, and student counts in one place.</div>
                        </div>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($classes)): ?>
                            <div class="text-center text-muted py-3">No assigned classes yet.</div>
                        <?php else: ?>
                            <div class="teacher-overview-grid mb-4">
                                <div class="teacher-overview-card">
                                    <strong><?php echo number_format(count($weeklySchedule['Mon']) + count($weeklySchedule['Tue']) + count($weeklySchedule['Wed']) + count($weeklySchedule['Thu']) + count($weeklySchedule['Fri'])); ?></strong>
                                    <span>Weekly Schedule Slots</span>
                                </div>
                                <div class="teacher-overview-card">
                                    <strong><?php echo number_format($stats['material_count']); ?></strong>
                                    <span>Uploaded Materials</span>
                                </div>
                                <div class="teacher-overview-card">
                                    <strong><?php echo number_format(count($classAnnouncements)); ?></strong>
                                    <span>Recent Class Announcements</span>
                                </div>
                            </div>
                            <?php foreach ($classes as $class): ?>
                                <div class="user-list-item">
                                    <div class="user-avatar-small"><?php echo strtoupper(substr($class['class_name'], 0, 1)); ?></div>
                                    <div class="user-list-info">
                                        <h5><?php echo htmlspecialchars($class['class_name']); ?></h5>
                                        <p>
                                            Grade <?php echo (int)$class['grade_level']; ?> - <?php echo htmlspecialchars($class['section']); ?>
                                            | <?php echo htmlspecialchars($formatSchedule($class['schedule'] ?? '')); ?>
                                            <?php if (!empty($class['room'])): ?> | Room: <?php echo htmlspecialchars($class['room']); ?><?php endif; ?>
                                            | <?php echo (int)$class['student_count']; ?> students
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">Weekly Schedule Calendar</h5></div>
                    <div class="content-card-body">
                        <div class="row g-3 weekly-schedule-grid">
                            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day): ?>
                                <div class="col-md-6 col-xl">
                                    <div class="schedule-day-card">
                                        <div class="schedule-day-header">
                                            <h6 class="schedule-day-title"><?php echo $day; ?></h6>
                                            <span class="schedule-day-count"><?php echo count($weeklySchedule[$day]); ?></span>
                                        </div>
                                        <?php if (empty($weeklySchedule[$day])): ?>
                                            <div class="schedule-day-empty">
                                                <i class="bi bi-calendar-x"></i>
                                                <span>No classes scheduled</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="schedule-slot-list">
                                            <?php foreach ($weeklySchedule[$day] as $slot): ?>
                                                <div class="schedule-item">
                                                    <div class="schedule-item-time"><i class="bi bi-clock"></i><?php echo htmlspecialchars($slot['time']); ?></div>
                                                    <p class="schedule-item-title"><?php echo htmlspecialchars($slot['class_name']); ?></p>
                                                    <p class="schedule-item-meta"><i class="bi bi-people"></i><?php echo htmlspecialchars($slot['section']); ?></p>
                                                    <p class="schedule-item-meta mb-0"><i class="bi bi-geo-alt"></i><?php echo !empty($slot['room']) ? htmlspecialchars($slot['room']) : 'Room not set'; ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
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
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/offlineStorage.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/networkSync.js'); ?>"></script>
<script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
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
            const userName = <?php echo json_encode($_SESSION['first_name'] ?? 'Teacher', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            greetingEl.innerText = greeting + ", " + userName + "!";
        }

        if (!navigator.onLine) {
            if (window.bshsOfflineStorage) {
                window.bshsOfflineStorage.getTeacherSession().then(s => {
                    if (s && s.first_name && greetingEl) {
                        greetingEl.innerText = greeting + ", " + s.first_name + "!";
                    }
                });
            }
        }

        if (window.bshsOfflineStorage && navigator.onLine) {
            try { localStorage.setItem('bshs_cached_teacher', '1'); } catch (e) {}
            window.bshsOfflineStorage.saveTeacherSession({
                teacher_id: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
                role: 'teacher',
                first_name: <?php echo json_encode($_SESSION['first_name'] ?? ''); ?>,
                last_name: <?php echo json_encode($_SESSION['last_name'] ?? ''); ?>,
                email: <?php echo json_encode($_SESSION['email'] ?? ''); ?>,
                reference_code: <?php echo json_encode($_SESSION['reference_code'] ?? ''); ?>
            });
            setTimeout(function () {
                window.bshsOfflineStorage.bootstrapOnline();
            }, 500);
        }
    });
</script>
</body>
</html>
