<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'bootstrap' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    $today = apiTodayAbbr();

    $response = [
        'ok' => true,
        'role' => $user['role'],
        'user' => [
            'id' => (int)$user['id'],
            'reference_code' => $user['reference_code'],
            'name' => apiFullName($user),
            'role' => $user['role'],
            'grade_level' => $user['grade_level'] !== null ? (int)$user['grade_level'] : null,
            'section' => $user['section'],
        ],
        'announcements' => [],
    ];

    $annStmt = $db->query("SELECT a.title, a.content, a.category, a.created_at, a.views,
                           CONCAT(u.first_name, ' ', u.last_name) AS posted_by_name
                           FROM announcements a
                           LEFT JOIN users u ON u.id = a.posted_by
                           WHERE a.status = 'active'
                           ORDER BY a.created_at DESC
                           LIMIT 8");
    $response['announcements'] = $annStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($user['role'] === 'admin') {
        $row = $db->query("SELECT
            (SELECT COUNT(*) FROM users WHERE status <> 'inactive' AND role <> 'admin') AS total_users,
            (SELECT COUNT(*) FROM classes WHERE status = 'active') AS total_classes,
            (SELECT COUNT(CASE WHEN status = 'present' THEN 1 END) FROM attendance WHERE date = CURDATE()) AS present_today,
            (SELECT COUNT(*) FROM attendance WHERE date = CURDATE()) AS total_today,
            (SELECT COUNT(*) FROM announcements WHERE status = 'active') AS active_announcements
        ")->fetch(PDO::FETCH_ASSOC);
        $present = (int)($row['present_today'] ?? 0);
        $total = (int)($row['total_today'] ?? 0);
        $response['stats'] = [
            'total_users' => (int)($row['total_users'] ?? 0),
            'total_classes' => (int)($row['total_classes'] ?? 0),
            'today_attendance' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
            'active_announcements' => (int)($row['active_announcements'] ?? 0),
        ];

        $pendingStmt = $db->query("SELECT id, first_name, last_name, role, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
        $response['pending_users'] = $pendingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($user['role'] === 'teacher') {
        $teacherId = (int)$user['id'];
        $advisory = apiTeacherAdvisoryInfo($db, $teacherId);
        $response['advisory'] = $advisory;

        $teacherRoles = apiGetTeacherRoles($db, $teacherId);
        $response['teacher_roles'] = $teacherRoles;

        $academicYear = apiCurrentAcademicYear();
        $semester = apiCurrentSemester() === 1 ? 'S1' : 'S2';
        $response['academic_year'] = $academicYear;
        $response['semester'] = $semester;

        if ($advisory) {
            $students = apiTeacherAdvisoryStudents($db, $advisory);
            $response['advisory_students'] = $students;
            $sectionStatus = apiAdvisorySectionStatus($db, $advisory, $academicYear, $semester, $teacherId);
            $response['advisory_section_status'] = $sectionStatus;
        }
    }

    if ($user['role'] === 'student') {
        $studentId = (int)$user['id'];
        $stmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section, s.subject_name
                              FROM enrollments e
                              JOIN classes c ON c.id = e.class_id
                              LEFT JOIN class_subjects cs ON cs.class_id = c.id
                              LEFT JOIN subjects s ON s.id = cs.subject_id
                              WHERE e.student_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled' AND c.status = 'active'
                              ORDER BY c.grade_level, c.section, c.class_name");
        $stmt->execute([$studentId]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['classes'] = $classes;

        $classIds = array_values(array_unique(array_map(function ($c) { return (int)$c['id']; }, $classes)));
        if (!empty($classIds)) {
            $schedules = $db->prepare("SELECT id, class_name, schedule, grade_level, section FROM classes WHERE id IN (" . implode(',', $classIds) . ") AND status = 'active'");
            $schedules->execute();
            $classSchedules = $schedules->fetchAll(PDO::FETCH_ASSOC);
            $response['schedules'] = $classSchedules;

            $todayClasses = [];
            foreach ($classSchedules as $cls) {
                $sched = apiParseScheduleForToday((string)($cls['schedule'] ?? ''), $today);
                if ($sched) {
                    $todayClasses[] = [
                        'class_id' => (int)$cls['id'],
                        'class_name' => (string)$cls['class_name'],
                        'grade_level' => (int)$cls['grade_level'],
                        'section' => (string)$cls['section'],
                        'time' => $sched['time'],
                    ];
                }
            }
            usort($todayClasses, function ($a, $b) {
                return apiScheduleStartMinutes($a['time']) <=> apiScheduleStartMinutes($b['time']);
            });
            $response['today_classes'] = $todayClasses;
        }
    }

    if ($user['role'] === 'parent') {
        $parentId = (int)$user['id'];
        $childrenStmt = $db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name, u.grade_level, u.section
                                      FROM parent_students ps
                                      JOIN users u ON u.id = ps.student_id
                                      WHERE ps.parent_id = ? AND u.role = 'student' AND u.status IN ('active', 'pending')
                                      ORDER BY u.last_name, u.first_name");
        $childrenStmt->execute([$parentId]);
        $response['children'] = $childrenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    apiJson($response);
}
