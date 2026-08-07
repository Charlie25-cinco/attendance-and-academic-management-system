<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$parentId = (int)($_SESSION['user_id'] ?? 0);

$current_role = 'parent';
$current_page = 'dashboard';
$page_title = 'Parent Dashboard';

$parentName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
if ($parentName === '' && $db && $parentId > 0) {
    try {
        $parentUserStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $parentUserStmt->execute([$parentId]);
        $pRow = $parentUserStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            if (!empty($pRow['first_name'])) {
                $_SESSION['first_name'] = $pRow['first_name'];
            }
            if (!empty($pRow['last_name'])) {
                $_SESSION['last_name'] = $pRow['last_name'];
            }
            $parentName = trim((string)($pRow['first_name'] ?? '') . ' ' . (string)($pRow['last_name'] ?? ''));
        }
    } catch (Throwable $e) {}
}
if ($parentName === '') {
    $parentName = 'Parent';
}
$parentRef = $_SESSION['reference_code'] ?? '';

$children = [];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;

$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0, 'rate' => 0];
$stats = ['gwa' => 0, 'subjects' => 0];
$gradeCards = [];
$todaySchedule = [];
$announcements = [];
$classAnnouncements = [];

function parentDayAbbrNow() {
    $map = ['Sun' => 'Sun', 'Mon' => 'Mon', 'Tue' => 'Tue', 'Wed' => 'Wed', 'Thu' => 'Thu', 'Fri' => 'Fri'];
    $k = date('D');
    return $map[$k] ?? 'Mon';
}

function parentParseScheduleEntries($schedule) {
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

function parentScheduleTimesForDay($schedule, $todayAbbr) {
    $entries = parentParseScheduleEntries($schedule);
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

if ($db && $parentId > 0) {
    $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
                                  FROM parent_students ps
                                  JOIN users u ON u.id = ps.student_id
                                  WHERE ps.parent_id = ? AND u.role = 'student' AND u.status = 'active'
                                  ORDER BY u.last_name, u.first_name");
    $childrenStmt->execute([$parentId]);
    $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($children)) {
        $validIds = array_map('intval', array_column($children, 'id'));
        if (!in_array($selectedStudentId, $validIds, true)) {
            $selectedStudentId = (int)$children[0]['id'];
        }
        foreach ($children as $child) {
            if ((int)$child['id'] === $selectedStudentId) {
                $selectedStudent = $child;
                break;
            }
        }
    }

    if ($selectedStudentId > 0) {
        $has_quarter = dbHasColumn($db, 'grades', 'quarter');
        $hasQuarter = $has_quarter;
        $tc = 'term';
        if ($hasQuarter) {
            $has_term = dbHasColumn($db, 'grades', 'term');
            $tc = $has_term ? 'term' : 'quarter';
        }

        $attStmt = $db->prepare("SELECT
                                 COUNT(CASE WHEN status = 'present' THEN 1 END) AS present_count,
                                 COUNT(CASE WHEN status = 'absent' THEN 1 END) AS absent_count,
                                 COUNT(CASE WHEN status = 'late' THEN 1 END) AS late_count,
                                 COUNT(*) AS total_count
                                 FROM attendance
                                 WHERE student_id = ?");
        $attStmt->execute([$selectedStudentId]);
        $attRow = $attStmt->fetch(PDO::FETCH_ASSOC);
        $attendance['present'] = (int)($attRow['present_count'] ?? 0);
        $attendance['absent'] = (int)($attRow['absent_count'] ?? 0);
        $attendance['late'] = (int)($attRow['late_count'] ?? 0);
        $attendance['total'] = (int)($attRow['total_count'] ?? 0);
        if ($attendance['total'] > 0) {
            $attendance['rate'] = round((($attendance['present'] + $attendance['late']) / $attendance['total']) * 100, 1);
        }

        $classStmt = $db->prepare("SELECT c.id, c.class_name, c.schedule, c.room,
                                   GROUP_CONCAT(DISTINCT CONCAT(t.first_name, ' ', t.last_name) SEPARATOR ', ') AS teacher_names
                                   FROM enrollments e
                                   JOIN classes c ON c.id = e.class_id
                                   LEFT JOIN class_subjects cs ON cs.id = c.id
                                   LEFT JOIN users t ON t.id = cs.teacher_id
                                   WHERE e.student_id = ? AND e.status = 'enrolled' AND c.status = 'active'
                                   GROUP BY c.id
                                   ORDER BY c.class_name");
        $classStmt->execute([$selectedStudentId]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['subjects'] = count($classes);

        if ($hasQuarter) {
            $gwaStmt = $db->prepare("SELECT AVG(x.semester_grade)
                                     FROM (
                                        SELECT class_subject_id, semester, academic_year, AVG(final_grade) AS semester_grade
                                        FROM grades
                                        WHERE student_id = ? AND final_grade IS NOT NULL AND {$tc} IN ('Q1', 'Q2')
                                        AND EXISTS (
                                            SELECT 1 FROM report_card_approvals rc
                                            WHERE rc.student_id = grades.student_id
                                            AND rc.academic_year = grades.academic_year
                                            AND rc.semester <=> grades.semester
                                            AND rc.status = 'approved'
                                        )
                                        GROUP BY class_subject_id, semester, academic_year
                                     ) x");
            $gwaStmt->execute([$selectedStudentId]);

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
                                         AND EXISTS (
                                            SELECT 1 FROM report_card_approvals rc
                                            WHERE rc.student_id = g.student_id
                                            AND rc.academic_year = g.academic_year
                                            AND rc.semester <=> g.semester
                                            AND rc.status = 'approved'
                                         )
                                         GROUP BY g.class_subject_id, g.semester, g.academic_year
                                       ) x
                                       JOIN class_subjects cs ON cs.id = x.class_subject_id
                                       JOIN classes c ON c.id = cs.class_id
                                       GROUP BY c.id
                                       ORDER BY c.class_name
                                       LIMIT 5");
            $gradeStmt->execute([$selectedStudentId]);
        } else {
            $gwaStmt = $db->prepare("SELECT AVG(final_grade) FROM grades
                                     WHERE student_id = ? AND final_grade IS NOT NULL
                                     AND EXISTS (
                                        SELECT 1 FROM report_card_approvals rc
                                        WHERE rc.student_id = grades.student_id
                                        AND rc.academic_year = grades.academic_year
                                        AND rc.semester <=> grades.semester
                                        AND rc.status = 'approved'
                                     )");
            $gwaStmt->execute([$selectedStudentId]);

            $gradeStmt = $db->prepare("SELECT c.class_name,
                                       AVG(g.quiz_score) AS quiz_avg,
                                       AVG(g.exam_score) AS exam_avg,
                                       AVG(g.activity_score) AS activity_avg,
                                       AVG(g.final_grade) AS final_avg
                                       FROM grades g
                                       JOIN class_subjects cs ON cs.id = g.class_subject_id
                                       JOIN classes c ON c.id = cs.class_id
                                       WHERE g.student_id = ?
                                       AND EXISTS (
                                         SELECT 1 FROM report_card_approvals rc
                                         WHERE rc.student_id = g.student_id
                                         AND rc.academic_year = g.academic_year
                                         AND rc.semester <=> g.semester
                                         AND rc.status = 'approved'
                                       )
                                       GROUP BY c.id
                                       ORDER BY c.class_name
                                       LIMIT 5");
            $gradeStmt->execute([$selectedStudentId]);
        }

        $stats['gwa'] = round((float)($gwaStmt->fetchColumn() ?: 0), 2);
        $gradeCards = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);

        $today = parentDayAbbrNow();

        $todaySchedule = [];
        $classIds = array_map('intval', array_column($classes, 'id'));
        $attMap = [];
        if (!empty($classIds)) {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $attBatchStmt = $db->prepare("SELECT class_id, status FROM attendance WHERE student_id = ? AND class_id IN ($ph) AND date = CURDATE()");
            $attBatchStmt->execute(array_merge([$selectedStudentId], $classIds));
            while ($attRow = $attBatchStmt->fetch(PDO::FETCH_ASSOC)) {
                $attMap[(int)$attRow['class_id']] = $attRow['status'];
            }
        }

        foreach ($classes as $class) {
            $attStatus = $attMap[(int)$class['id']] ?? 'pending';
            $times = parentScheduleTimesForDay($class['schedule'] ?? '', $today);
            foreach ($times as $time) {
                $todaySchedule[] = [
                    'time' => $time,
                    'subject' => $class['class_name'],
                    'teacher' => $class['teacher_names'] ?: 'TBA',
                    'room' => $class['room'] ?: 'TBA',
                    'status' => $attStatus
                ];
            }
        }

        if (!empty($classIds)) {
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

        $schoolAnn = $db->query("SELECT title, content, created_at
                                 FROM announcements
                                 WHERE status = 'active'
                                 ORDER BY created_at DESC
                                 LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $announcements = array_map(function ($sa) {
            return [
                'title' => $sa['title'],
                'content' => $sa['content'],
                'created_at' => $sa['created_at'],
                'class_name' => 'School'
            ];
        }, $schoolAnn);
    }
}

$selectedStudentName = $selectedStudent
    ? trim((string)($selectedStudent['first_name'] ?? '') . ' ' . (string)($selectedStudent['last_name'] ?? ''))
    : 'No student selected';
$announcementCount = count($announcements) + count($classAnnouncements);
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
    <link rel="stylesheet" href="../assets/css/main.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/main.css') ?: '2'; ?>">
    <link rel="stylesheet" href="../assets/css/role.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/role.css') ?: '2'; ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <section class="parent-hero mb-4">
                <div class="parent-hero-grid">
                    <div class="parent-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-people-fill"></i><span>Parent Portal</span></div>
                        <h3 class="welcome-title mb-2" id="dashboardGreeting">Welcome, <?php echo htmlspecialchars($parentName); ?>!</h3>
                        <p class="welcome-meta mb-3">Follow attendance, approved grades, class-day activity, and school updates for your linked student accounts.</p>
                        <div class="parent-chip-row">
                            <?php if ($parentRef !== ''): ?>
                                <span class="parent-chip"><i class="bi bi-upc-scan"></i>Ref: <?php echo htmlspecialchars($parentRef); ?></span>
                            <?php endif; ?>
                            <span class="parent-chip"><i class="bi bi-person-heart"></i><?php echo htmlspecialchars($selectedStudentName); ?></span>
                            <span class="parent-chip"><i class="bi bi-calendar-event"></i><?php echo date('l, F d, Y'); ?></span>
                        </div>
                    </div>
                    <div class="parent-hero-side">
                        <div class="parent-summary-panel">
                            <div class="parent-summary-grid">
                                <div class="parent-summary-stat">
                                    <span class="parent-summary-label">Children Linked</span>
                                    <strong class="parent-summary-value"><?php echo number_format(count($children)); ?></strong>
                                </div>
                                <div class="parent-summary-stat">
                                    <span class="parent-summary-label">Announcements</span>
                                    <strong class="parent-summary-value"><?php echo number_format($announcementCount); ?></strong>
                                </div>
                            </div>
                            <p class="parent-summary-note">Next class: <?php echo htmlspecialchars($nextClassLabel); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <div class="content-card mb-4">
                <div class="content-card-header"><h5 class="content-card-title">My Children</h5></div>
                <div class="content-card-body">
                    <?php if (empty($children)): ?>
                        <div class="text-muted">No linked student found. Ask admin to link your account in parent-student records.</div>
                    <?php else: ?>
                        <div class="portal-switcher parent-switcher">
                            <?php foreach ($children as $child): ?>
                                <a href="?student_id=<?php echo (int)$child['id']; ?>" class="btn portal-chip <?php echo ((int)$child['id'] === $selectedStudentId) ? 'btn-primary-custom' : 'btn-outline-secondary'; ?>">
                                    <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                    <?php if (!empty($child['reference_code'])): ?>
                                        <small class="portal-chip-reference ms-1">(<?php echo htmlspecialchars((string)$child['reference_code']); ?>)</small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selectedStudent): ?>
                <div class="dashboard-section-intro">
                    <div>
                        <span class="dashboard-section-kicker">Guardian view</span>
                        <h4 class="dashboard-section-title">Student monitoring dashboard</h4>
                        <p class="dashboard-section-copy">Follow attendance, approved grades, and class-day activity for the selected learner.</p>
                    </div>
                </div>

                <div class="parent-overview-grid mb-4">
                    <div class="parent-overview-card"><strong><?php echo (int)$attendance['present']; ?></strong><span>Present</span></div>
                    <div class="parent-overview-card"><strong><?php echo (int)$attendance['absent']; ?></strong><span>Absent</span></div>
                    <div class="parent-overview-card"><strong><?php echo (int)$attendance['late']; ?></strong><span>Late</span></div>
                    <div class="parent-overview-card"><strong><?php echo $stats['gwa'] > 0 ? $stats['gwa'] : 'N/A'; ?></strong><span>Current GWA</span></div>
                </div>

                <div class="row g-4 mt-2">
                    <div class="col-12">
                        <div class="content-card">
                            <div class="content-card-header"><h5 class="content-card-title">Academic Progress</h5></div>
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
                                                <div class="grade-item"><div class="grade-item-value"><?php echo $g['activity_avg'] !== null ? round($g['activity_avg'], 2) : 'N/A'; ?></div><div class="grade-item-label">Performance Tasks</div></div>
                                                <div class="grade-item"><div class="grade-item-value"><?php echo $g['exam_avg'] !== null ? round($g['exam_avg'], 2) : 'N/A'; ?></div><div class="grade-item-label">Quarterly Assessment</div></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-2">
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="content-card-header"><h5 class="content-card-title">Today's Schedule</h5></div>
                            <div class="content-card-body">
                                <?php if (empty($todaySchedule)): ?>
                                    <div class="text-center text-muted py-3">No classes scheduled for today.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Class</th>
                                                    <th>Teacher</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($todaySchedule as $s): ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($s['time']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($s['subject']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['teacher']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $s['status'] === 'present' ? 'success' : ($s['status'] === 'late' ? 'warning' : ($s['status'] === 'absent' ? 'danger' : 'secondary')); ?>">
                                                                <?php echo ucfirst($s['status']); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="content-card">
                            <div class="content-card-header"><h5 class="content-card-title">Class Updates</h5></div>
                            <div class="content-card-body">
                                <?php if (empty($classAnnouncements)): ?>
                                    <div class="text-center text-muted py-3">No class announcements yet.</div>
                                <?php else: ?>
                                    <?php foreach ($classAnnouncements as $ca): ?>
                                        <div class="announcement-item mb-3 pb-3 border-bottom">
                                            <div class="d-flex justify-content-between">
                                                <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($ca['title']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($ca['class_name']); ?></small>
                                            </div>
                                            <p class="text-muted small mb-1"><?php echo nl2br(htmlspecialchars($ca['content'])); ?></p>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($ca['created_at'])); ?></small>
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
                            <div class="content-card-header"><h5 class="content-card-title">School Announcements</h5></div>
                            <div class="content-card-body">
                                <?php if (empty($announcements)): ?>
                                    <div class="text-center text-muted py-3">No school announcements yet.</div>
                                <?php else: ?>
                                    <?php foreach ($announcements as $a): ?>
                                        <div class="announcement-item mb-3 pb-3 border-bottom">
                                            <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($a['title']); ?></h6>
                                            <p class="text-muted small mb-1"><?php echo nl2br(htmlspecialchars($a['content'])); ?></p>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($a['created_at'])); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
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
                const userName = <?php echo json_encode($parentName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                greetingEl.innerText = greeting + ", " + userName + "!";
            }
        });
    </script>
</body>
</html>
