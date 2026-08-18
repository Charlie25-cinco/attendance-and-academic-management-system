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
$current_page = 'dashboard';
$page_title = 'Student Dashboard';

$studentInfo = [
    'name' => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
    'reference_code' => $_SESSION['reference_code'] ?? '',
    'grade_level' => '',
    'section' => ''
];

$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'rate' => 0];
$stats = ['enrolled_subjects' => 0, 'gwa' => 0];
$todaySchedule = [];
$weeklySchedule = ['Mon' => [], 'Tue' => [], 'Wed' => [], 'Thu' => [], 'Fri' => []];
$gradeCards = [];
$materials = [];
$schoolAnnouncements = [];
$classAnnouncements = [];
$classIds = [];
$hasGradeQuarter = false;

function studentDayAbbrNow() {
    $map = ['Sun' => 'Sun', 'Mon' => 'Mon', 'Tue' => 'Tue', 'Wed' => 'Wed', 'Thu' => 'Thu', 'Fri' => 'Fri'];
    $k = date('D');
    return $map[$k] ?? 'Mon';
}

function studentParseScheduleEntries($schedule) {
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
        if (!preg_match('/^([A-Za-z\/,\s]+)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', $segment, $m)) {
            continue;
        }
        $daysRaw = preg_replace('/\s*\/\s*/', ',', trim($m[1]));
        $days = array_filter(array_map(function ($d) {
            return ucfirst(strtolower(substr(trim($d), 0, 3)));
        }, explode(',', $daysRaw)));
        $start = strtoupper(preg_replace('/\s+/', ' ', trim($m[2])));
        $end = strtoupper(preg_replace('/\s+/', ' ', trim($m[3])));
        foreach ($days as $day) {
            if (!in_array($day, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], true)) {
                continue;
            }
            $entries[] = ['day' => $day, 'time' => $start . ' - ' . $end];
        }
    }
    return $entries;
}

function studentScheduleTimesForDay($schedule, $todayAbbr) {
    $entries = studentParseScheduleEntries($schedule);
    if (empty($entries)) {
        return [];
    }
    $times = [];
    foreach ($entries as $entry) {
        if (($entry['day'] ?? '') === $todayAbbr) {
            $times[] = $entry['time'];
        }
    }
    return $times;
}

function studentScheduleStartMinutes($timeRange) {
    $timeRange = trim((string)$timeRange);
    if (!preg_match('/^(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*\d{1,2}:\d{2}\s*[AP]M$/i', $timeRange, $m)) {
        return PHP_INT_MAX;
    }
    $dt = DateTime::createFromFormat('g:i A', strtoupper(trim($m[1])));
    if (!$dt) {
        return PHP_INT_MAX;
    }
    return ((int)$dt->format('H') * 60) + (int)$dt->format('i');
}

if ($db && $studentId > 0) {
    $has_quarter = dbHasColumn($db, 'grades', 'quarter');
    $hasGradeQuarter = $has_quarter;
    $tc = 'term';
    if ($hasGradeQuarter) {
        $has_term = dbHasColumn($db, 'grades', 'term');
        $tc = $has_term ? 'term' : 'quarter';
    }

    $enrollStmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, c.schedule, c.room,
                                GROUP_CONCAT(DISTINCT CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') AS teacher_names
                                FROM enrollments e
                                JOIN classes c ON c.id = e.class_id
                                LEFT JOIN class_subjects cs ON cs.class_id = c.id
                                LEFT JOIN users t ON t.id = cs.teacher_id
                                WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                                GROUP BY c.id
                                ORDER BY c.grade_level, c.section, c.class_name");
    $enrollStmt->execute([$studentId]);
    $enrolledClasses = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($enrolledClasses)) {
        $studentInfo['grade_level'] = 'Grade ' . $enrolledClasses[0]['grade_level'];
        $studentInfo['section'] = $enrolledClasses[0]['section'];
        $classIds = array_map('intval', array_column($enrolledClasses, 'id'));
    }
    $stats['enrolled_subjects'] = count($enrolledClasses);

    $attStmt = $db->prepare("SELECT
                             COUNT(CASE WHEN status='present' THEN 1 END) AS present_count,
                             COUNT(CASE WHEN status='absent' THEN 1 END) AS absent_count,
                             COUNT(CASE WHEN status='late' THEN 1 END) AS late_count,
                             COUNT(*) AS total_count
                             FROM attendance
                             WHERE student_id = ? AND academic_year = ?");
    $attStmt->execute([$studentId, currentAcademicYear()]);
    $attRow = $attStmt->fetch(PDO::FETCH_ASSOC);
    $attendance['present'] = (int)($attRow['present_count'] ?? 0);
    $attendance['absent'] = (int)($attRow['absent_count'] ?? 0);
    $attendance['late'] = (int)($attRow['late_count'] ?? 0);
    $attendance['total'] = (int)($attRow['total_count'] ?? 0);
    $lateToAbsent = intdiv($attendance['late'], 3);
    $attendance['late'] = $attendance['late'] % 3;
    $attendance['absent'] += $lateToAbsent;
    if ($attendance['total'] > 0) {
        $attendance['rate'] = round((($attendance['present'] + $attendance['late']) / $attendance['total']) * 100, 1);
    }

    if ($hasGradeQuarter) {
        $gwaStmt = $db->prepare("SELECT AVG(x.semester_grade)
                                 FROM (
                                    SELECT class_subject_id, semester, academic_year, AVG(final_grade) AS semester_grade
                                    FROM grades
                                    WHERE student_id = ? AND final_grade IS NOT NULL AND {$tc} IN ('Q1', 'Q2')
                                    GROUP BY class_subject_id, semester, academic_year
                                 ) x");
        $gwaStmt->execute([$studentId]);
    } else {
        $gwaStmt = $db->prepare("SELECT AVG(final_grade) FROM grades WHERE student_id = ? AND final_grade IS NOT NULL");
        $gwaStmt->execute([$studentId]);
    }
    $stats['gwa'] = round((float)($gwaStmt->fetchColumn() ?: 0), 2);

    if ($hasGradeQuarter) {
        $gradeStmt = $db->prepare("SELECT c.class_name,
                                   AVG(x.quiz_score) AS quiz_avg,
                                   AVG(x.exam_score) AS exam_avg,
                                   AVG(x.activity_score) AS activity_avg,
                                   AVG(x.semester_grade) AS final_avg
                                   FROM (
                                     SELECT g.class_subject_id, g.semester, g.academic_year,
                                            AVG(g.quiz_score) AS quiz_score,
                                            AVG(g.exam_score) AS exam_score,
                                            AVG(g.activity_score) AS activity_score,
                                            AVG(g.final_grade) AS semester_grade
                                     FROM grades g
                                      WHERE g.student_id = ? AND g.{$tc} IN ('Q1', 'Q2')
                                     GROUP BY g.class_subject_id, g.semester, g.academic_year
                                   ) x
                                   JOIN class_subjects cs ON cs.id = x.class_subject_id
                                   JOIN classes c ON c.id = cs.class_id
                                   GROUP BY c.id
                                   ORDER BY c.class_name
                                   LIMIT 4");
        $gradeStmt->execute([$studentId]);
    } else {
        $gradeStmt = $db->prepare("SELECT c.class_name,
                                   AVG(g.quiz_score) AS quiz_avg,
                                   AVG(g.exam_score) AS exam_avg,
                                   AVG(g.activity_score) AS activity_avg,
                                   AVG(g.final_grade) AS final_avg
                                   FROM grades g
                                   JOIN class_subjects cs ON cs.id = g.class_subject_id
                                   JOIN classes c ON c.id = cs.class_id
                                   WHERE g.student_id = ?
                                   GROUP BY c.id
                                   ORDER BY c.class_name
                                   LIMIT 4");
        $gradeStmt->execute([$studentId]);
    }
    $gradeCards = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);

    $today = studentDayAbbrNow();
    foreach ($enrolledClasses as $class) {
        $times = studentScheduleTimesForDay($class['schedule'], $today);
        foreach ($times as $time) {
            $todaySchedule[] = [
                'time' => $time,
                'subject' => $class['class_name'],
                'teacher' => $class['teacher_names'] ?: 'TBA',
                'room' => $class['room'] ?: 'TBA'
            ];
        }
    }
    usort($todaySchedule, function ($a, $b) {
        return strcmp($a['time'], $b['time']);
    });

    foreach ($enrolledClasses as $class) {
        $entries = studentParseScheduleEntries($class['schedule'] ?? '');
        foreach ($entries as $entry) {
            $day = $entry['day'] ?? '';
            if (!isset($weeklySchedule[$day])) {
                continue;
            }
            $weeklySchedule[$day][] = [
                'class_name' => $class['class_name'],
                'teacher' => $class['teacher_names'] ?: 'TBA',
                'time' => $entry['time'] ?? '',
                'room' => $class['room'] ?: 'TBA'
            ];
        }
    }
    foreach ($weeklySchedule as $day => $slots) {
        usort($slots, function ($a, $b) {
            return studentScheduleStartMinutes($a['time'] ?? '') <=> studentScheduleStartMinutes($b['time'] ?? '');
        });
        $weeklySchedule[$day] = $slots;
    }

    if (!empty($classIds)) {
        $ph = implode(',', array_fill(0, count($classIds), '?'));

        $matStmt = $db->prepare("SELECT m.id, m.title, m.file_type, m.created_at, c.class_name
                                 FROM materials m
                                 JOIN class_subjects cs ON cs.id = m.class_subject_id
                                 JOIN classes c ON c.id = cs.class_id
                                 WHERE cs.class_id IN ($ph)
                                 ORDER BY m.created_at DESC
                                 LIMIT 5");
        $matStmt->execute($classIds);
        $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

        $classAnnStmt = $db->prepare("SELECT ca.title, ca.content, ca.created_at, c.class_name
                                      FROM class_announcements ca
                                      JOIN classes c ON c.id = ca.class_id
                                      WHERE ca.class_id IN ($ph) AND ca.status = 'active'
                                      ORDER BY ca.created_at DESC
                                      LIMIT 5");
        $classAnnStmt->execute($classIds);
        $classAnnouncements = $classAnnStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $schoolAnn = $db->query("SELECT title, content, created_at
                             FROM announcements
                             WHERE status = 'active'
                             ORDER BY created_at DESC
                             LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $schoolAnnouncements = array_map(function ($sa) {
        return [
            'title' => $sa['title'],
            'content' => $sa['content'],
            'created_at' => $sa['created_at'],
            'class_name' => 'School'
        ];
    }, $schoolAnn);
}

$studentSectionLabel = trim($studentInfo['grade_level'] . ' - ' . $studentInfo['section'], ' -');
$todayClassesCount = count($todaySchedule);
$announcementCount = count($schoolAnnouncements) + count($classAnnouncements);
$latestMaterial = $materials[0]['title'] ?? 'No uploaded material yet';
$nextClassLabel = !empty($todaySchedule)
    ? $todaySchedule[0]['subject'] . ' • ' . $todaySchedule[0]['time']
    : 'No class scheduled today';
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
        .weekly-schedule-grid { --schedule-accent: #1d4ed8; }
        .schedule-day-card {
            position: relative;
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 20px;
            padding: 16px;
            min-height: 100%;
            background:
                radial-gradient(circle at top right, rgba(96, 165, 250, 0.22), transparent 38%),
                linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .schedule-day-card::after {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
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
            background: rgba(37, 99, 235, 0.12);
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
            background: rgba(37, 99, 235, 0.12);
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
            color: #2563eb;
        }
        body.dark-mode .schedule-day-card {
            border-color: rgba(96, 165, 250, 0.16);
            background:
                radial-gradient(circle at top right, rgba(96, 165, 250, 0.16), transparent 38%),
                linear-gradient(180deg, #111827 0%, #0f172a 100%);
            box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
        }
        body.dark-mode .schedule-day-title,
        body.dark-mode .schedule-item-title {
            color: #f8fafc;
        }
        body.dark-mode .schedule-day-count {
            background: rgba(96, 165, 250, 0.14);
            color: #bfdbfe;
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
            background: rgba(96, 165, 250, 0.14);
            color: #bfdbfe;
        }
    </style>
<?php echo pwaHeadHtml(); ?>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../includes/header.php'; ?>
    <div class="page-content">
        <section class="student-hero mb-4">
            <div class="student-hero-grid">
                <div class="student-hero-main">
                    <div class="welcome-role-chip"><i class="bi bi-person-badge-fill"></i><span>Student Portal</span></div>
                    <h3 class="welcome-title mb-2" id="dashboardGreeting">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Student'); ?>!</h3>
                    <p class="welcome-meta mb-3">Stay on top of your classes, attendance, learning materials, and released report card updates from one place.</p>
                    <div class="student-chip-row">
                        <?php if ($studentSectionLabel !== ''): ?>
                            <span class="student-chip"><i class="bi bi-mortarboard"></i><?php echo htmlspecialchars($studentSectionLabel); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($studentInfo['reference_code'])): ?>
                            <span class="student-chip"><i class="bi bi-upc-scan"></i>Ref: <?php echo htmlspecialchars($studentInfo['reference_code']); ?></span>
                        <?php endif; ?>
                        <span class="student-chip"><i class="bi bi-calendar-event"></i><?php echo date('l, F d, Y'); ?></span>
                    </div>
                </div>
                <div class="student-hero-side">
                    <div class="student-summary-panel">
                        <div class="student-summary-grid">
                            <div class="student-summary-stat">
                                <span class="student-summary-label">School Year</span>
                                <strong class="student-summary-value"><?php echo date('Y') . '-' . (date('Y') + 1); ?></strong>
                            </div>
                            <div class="student-summary-stat">
                                <span class="student-summary-label">Today’s Classes</span>
                                <strong class="student-summary-value"><?php echo number_format($todayClassesCount); ?></strong>
                            </div>
                        </div>
                        <p class="student-summary-note">Next class: <?php echo htmlspecialchars($nextClassLabel); ?></p>
                    </div>
                </div>
            </div>
        </section>

        <div class="student-overview-grid mb-4">
            <div class="student-overview-card">
                <strong><?php echo $attendance['rate']; ?>%</strong>
                <span>Attendance Rate</span>
            </div>
            <div class="student-overview-card">
                <strong><?php echo (int)$stats['enrolled_subjects']; ?></strong>
                <span>Enrolled Subjects</span>
            </div>
            <div class="student-overview-card">
                <strong><?php echo $stats['gwa'] > 0 ? $stats['gwa'] : 'N/A'; ?></strong>
                <span>Current GWA</span>
            </div>
            <div class="student-overview-card">
                <strong><?php echo number_format($announcementCount); ?></strong>
                <span>Live Announcements</span>
            </div>
        </div>

        <div class="row g-4 mb-2">
            <div class="col-12 col-xl-7">
                <div class="content-card h-100">
                    <div class="content-card-header"><h5 class="content-card-title">Student Snapshot</h5></div>
                    <div class="content-card-body">
                        <div class="student-kpi-grid">
                            <div class="student-kpi-card"><span>Present Records</span><strong><?php echo number_format((int)$attendance['present']); ?></strong></div>
                            <div class="student-kpi-card"><span>Absent Records</span><strong><?php echo number_format((int)$attendance['absent']); ?></strong></div>
                            <div class="student-kpi-card"><span>Late Records</span><strong><?php echo number_format((int)$attendance['late']); ?></strong></div>
                            <div class="student-kpi-card"><span>Latest Material</span><strong><?php echo htmlspecialchars($latestMaterial); ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="content-card h-100">
                    <div class="content-card-header"><h5 class="content-card-title">Quick Access</h5></div>
                    <div class="content-card-body">
                        <div class="student-quick-grid">
                            <a href="Student_Attendance.php" class="student-quick-card"><div class="student-quick-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--accent-color);"><i class="bi bi-calendar-check"></i></div><div class="student-quick-copy"><strong>Attendance History</strong><span>Review present, absent, and late records</span></div></a>
                            <a href="Student_Classes.php" class="student-quick-card"><div class="student-quick-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--secondary-color);"><i class="bi bi-journal-bookmark"></i></div><div class="student-quick-copy"><strong>My Classes</strong><span>Open schedules, materials, and class details</span></div></a>
                            <a href="Student_Report_Card.php" class="student-quick-card"><div class="student-quick-icon" style="background: rgba(99, 102, 241, 0.12); color: #4f46e5;"><i class="bi bi-file-earmark-text"></i></div><div class="student-quick-copy"><strong>Report Card</strong><span>Check approved grades and attendance summary</span></div></a>
                            <a href="Student_Announcements.php" class="student-quick-card"><div class="student-quick-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning-color);"><i class="bi bi-megaphone"></i></div><div class="student-quick-copy"><strong>Announcements</strong><span>See school and class updates</span></div></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-2">
            <div class="col-12 col-xl-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title">School Announcements</h5>
                        <a href="Student_Announcements.php" class="btn btn-sm btn-primary-custom">View All</a>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($schoolAnnouncements)): ?>
                            <div class="text-center text-muted py-3">No announcements available.</div>
                        <?php else: ?>
                            <?php foreach ($schoolAnnouncements as $a): ?>
                                <div class="announcement-card info mb-3">
                                    <div class="announcement-header">
                                        <h6 class="announcement-title"><?php echo htmlspecialchars($a['title']); ?></h6>
                                        <span class="announcement-badge info"><?php echo htmlspecialchars($a['class_name']); ?></span>
                                    </div>
                                    <p class="announcement-content"><?php echo htmlspecialchars($a['content']); ?></p>
                                    <div class="announcement-meta"><span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span></div>
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
                        <a href="Student_Announcements.php" class="btn btn-sm btn-primary-custom">View All</a>
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
                                    <div class="announcement-meta"><span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></span></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-12">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">Today's Schedule</h5></div>
                    <div class="content-card-body">
                        <div class="table-container dashboard-table-wrap">
                            <table class="custom-table">
                                <thead><tr><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead>
                                <tbody>
                                <?php if (empty($todaySchedule)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No classes scheduled today.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($todaySchedule as $slot): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($slot['time']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($slot['subject']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($slot['teacher']); ?></td>
                                            <td><?php echo htmlspecialchars($slot['room']); ?></td>
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
                                                    <p class="schedule-item-meta"><i class="bi bi-person"></i><?php echo htmlspecialchars($slot['teacher']); ?></p>
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

            <div class="col-12">
                <div class="content-card">
                    <div class="content-card-header"><h5 class="content-card-title">Academic Progress</h5><a href="Student_Classes.php" class="btn btn-sm btn-primary-custom">View All</a></div>
                    <div class="content-card-body">
                        <?php if (empty($gradeCards)): ?>
                            <div class="text-center text-muted py-3">No recorded grades yet.</div>
                        <?php else: ?>
                            <?php foreach ($gradeCards as $g): ?>
                                <div class="grade-card mb-3">
                                    <div class="grade-header">
                                        <span class="grade-subject"><?php echo htmlspecialchars($g['class_name']); ?></span>
                                        <span class="grade-score good"><?php echo $g['final_avg'] !== null ? round($g['final_avg'], 2) : 'N/A'; ?></span>
                                    </div>
                                    <div class="grade-breakdown">
                                        <div class="grade-item"><div class="grade-item-value"><?php echo $g['quiz_avg'] !== null ? round($g['quiz_avg'], 2) : 'N/A'; ?></div><div class="grade-item-label">Written Work</div></div>
                                        <div class="grade-item"><div class="grade-item-value"><?php echo $g['exam_avg'] !== null ? round($g['exam_avg'], 2) : 'N/A'; ?></div><div class="grade-item-label">Assessment</div></div>
                                        <div class="grade-item"><div class="grade-item-value"><?php echo $g['activity_avg'] !== null ? round($g['activity_avg'], 2) : 'N/A'; ?></div><div class="grade-item-label">Performance Task</div></div>
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
                    <div class="content-card-header"><h5 class="content-card-title">Recent Learning Materials</h5><a href="Student_Classes.php" class="btn btn-sm btn-primary-custom">Open Classes</a></div>
                    <div class="content-card-body">
                        <?php if (empty($materials)): ?>
                            <div class="text-center text-muted py-3">No materials available yet.</div>
                        <?php else: ?>
                            <?php foreach ($materials as $m): ?>
                                <div class="material-card mb-3">
                                    <div class="material-icon doc"><i class="bi bi-file-earmark-text"></i></div>
                                    <div class="material-info">
                                        <div class="material-title"><?php echo htmlspecialchars($m['title']); ?></div>
                                        <div class="material-meta"><?php echo htmlspecialchars($m['class_name']); ?> | <?php echo date('M d, Y', strtotime($m['created_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
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
            const userName = <?php echo json_encode($_SESSION['first_name'] ?? 'Student', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            greetingEl.innerText = greeting + ", " + userName + "!";
        }
    });
</script>
</body>
</html>
