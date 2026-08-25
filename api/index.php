<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

require_once __DIR__ . '/apisupport.php';

// Global exception handler for API
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('[API] Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'message' => 'Internal server error'], JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/pushnotifications.php';

apiHandleCors();

$route = strtolower(trim((string)($_GET['route'] ?? 'health')));
// Strip API version prefix (e.g., "v1/login" -> "login") for backward compatibility
$route = preg_replace('/^v\d+\//', '', $route);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function apiDefaultPassword(): string {
    $env = getenv('DEFAULT_NEW_USER_PASSWORD');
    if ($env !== false && $env !== '') {
        return (string)$env;
    }
    $isProduction = getenv('APP_ENV') === 'production';
    if ($isProduction) {
        error_log('[SECURITY] DEFAULT_NEW_USER_PASSWORD must be set in production.');
        apiJson(['ok' => false, 'message' => 'Application configuration error — DEFAULT_NEW_USER_PASSWORD not set'], 500);
    }
    return getDefaultNewUserPassword();
}

function apiHasColumn(PDO $db, string $table, string $column): bool {
    return apiTableHasColumn($db, $table, $column);
}

function apiHasTable(PDO $db, string $table): bool {
    return apiTableExists($db, $table);
}

function apiValidPersonName(string $value, bool $allowEmpty = false): bool {
    return isValidPersonName($value, $allowEmpty);
}

function apiHasMinimumLetters(string $value, int $minimum = 2): bool {
    return hasMinimumLetters($value, $minimum);
}

function apiValidMiddleName(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return true;
    }
    if (!apiValidPersonName($value, true)) {
        return false;
    }
    return apiHasMinimumLetters($value, 2);
}

function apiHasNotificationsTable(PDO $db): bool {
    return dbHasTable($db, 'user_notifications');
}

function apiNotificationSourceKey(string $role, array $item): string {
    return md5(implode('|', [
        $role,
        trim((string)($item['title'] ?? '')),
        trim((string)($item['subtitle'] ?? '')),
        trim((string)($item['icon'] ?? 'bi-bell')),
        trim((string)($item['color'] ?? 'primary')),
        trim((string)($item['link'] ?? '')),
        trim((string)($item['event_at'] ?? '')),
    ]));
}

function apiNotificationTimeAgo(string $dateTime): string {
    $ts = strtotime($dateTime);
    if (!$ts) return 'Just now';
    $diff = time() - $ts;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . ' sec ago';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M d, Y', $ts);
}

// Load route handlers
require __DIR__ . '/routes/00-env.php';
require __DIR__ . '/routes/01-health.php';
require __DIR__ . '/routes/02-auth.php';
require __DIR__ . '/routes/03-profile.php';
require __DIR__ . '/routes/04-settings.php';
require __DIR__ . '/routes/05-notifications.php';
require __DIR__ . '/routes/06-admin.php';
require __DIR__ . '/routes/07-bootstrap.php';
require __DIR__ . '/routes/08-student.php';
require __DIR__ . '/routes/09-parent.php';
require __DIR__ . '/routes/10-teacher.php';
require __DIR__ . '/routes/11-sync.php';
require __DIR__ . '/routes/12-ecr.php';

function apiNormalizeDate(string $value): string {
    return normalizeDate($value);
}

function apiNormalizeDateRange(string &$from, string &$to): void {
    if ($from !== '' && $to !== '' && $from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }
}

function buildAttendanceSummary(array $rows): array {
    $counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
    foreach ($rows as $row) {
        $status = ucfirst((string)($row['status'] ?? 'Pending'));
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    return [
        ['label' => 'Present', 'value' => $counts['Present'], 'color' => '#10b981'],
        ['label' => 'Late', 'value' => $counts['Late'], 'color' => '#f59e0b'],
        ['label' => 'Absent', 'value' => $counts['Absent'], 'color' => '#ef4444'],
    ];
}

function normalizeGradeNumber($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = round((float)$value, 2);
    if ($number < 0 || $number > 100) {
        return null;
    }
    return $number;
}

function apiTeacherAdvisoryInfo(PDO $db, int $teacherId): ?array {
    $profileStmt = $db->prepare("SELECT grade_level, section
                                 FROM users
                                 WHERE id = ? AND role = 'teacher'
                                 LIMIT 1");
    $profileStmt->execute([$teacherId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $profileGrade = isset($profile['grade_level']) ? (int)$profile['grade_level'] : 0;
    $profileSection = trim((string)($profile['section'] ?? ''));
    if ($profileGrade > 0 && $profileSection !== '') {
        return [
            'grade_level' => $profileGrade,
            'section' => $profileSection,
        ];
    }

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
        return [
            'grade_level' => $advisoryGrade,
            'section' => $advisorySection,
        ];
    }
    return null;
}

function apiTeacherAdvisoryStudents(PDO $db, array $advisory): array {
    $stmt = $db->prepare("SELECT id, reference_code, first_name, last_name
                          FROM users
                          WHERE role = 'student'
                          AND status IN ('active', 'pending')
                          AND grade_level = ?
                          AND " . sectionMatchSql('section') . "
                          ORDER BY last_name, first_name");
    $stmt->execute([
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section'],
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function apiTeacherAdvisoryHasStudent(PDO $db, array $advisory, int $studentId): bool {
    $stmt = $db->prepare("SELECT 1
                          FROM users
                          WHERE id = ?
                          AND role = 'student'
                          AND status IN ('active', 'pending')
                          AND grade_level = ?
                          AND " . sectionMatchSql('section') . "
                          LIMIT 1");
    $stmt->execute([
        $studentId,
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section'],
    ]);
    return (bool)$stmt->fetchColumn();
}

function apiAttendanceHasTermColumns(PDO $db): bool {
    $hasYear = dbHasColumn($db, 'attendance', 'academic_year');
    $hasSemester = dbHasColumn($db, 'attendance', 'semester');
    return $hasYear && $hasSemester;
}

function apiAcademicTermBounds(string $academicYear, string $semester): array {
    preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m);
    $startYear = (int)($m[1] ?? date('Y'));
    $endYear = (int)($m[2] ?? ($startYear + 1));
    if ($semester === 'S2') {
        return [$startYear . '-12-01', $endYear . '-05-31'];
    }
    return [$startYear . '-06-01', $startYear . '-11-30'];
}

function apiAdvisoryAttendanceSummary(PDO $db, int $studentId, array $advisory, string $academicYear, string $semester): array {
    $hasTerms = apiAttendanceHasTermColumns($db);
    $semesterNo = $semester === 'S2' ? 2 : 1;
    $attendanceWhere = $hasTerms ? "AND a.academic_year = ? AND a.semester = ?" : "AND a.date BETWEEN ? AND ?";
    $attendanceParams = $hasTerms ? [$academicYear, $semesterNo] : apiAcademicTermBounds($academicYear, $semester);

    $attendanceStmt = $db->prepare("SELECT SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                                           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                                           SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count
                                    FROM attendance a
                                    JOIN classes c ON c.id = a.class_id
                                    WHERE a.student_id = ?
                                    AND LOWER(TRIM(COALESCE(c.class_name, ''))) <> 'advisory'
                                    AND c.grade_level = ?
                                    AND " . sectionMatchSql('c.section') . "
                                    $attendanceWhere");
    $attendanceStmt->execute(array_merge([
        $studentId,
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section'],
    ], $attendanceParams));
    $att = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $present = (int)($att['present_count'] ?? 0);
    $absent = (int)($att['absent_count'] ?? 0);
    $late = (int)($att['late_count'] ?? 0);
    $total = $present + $absent + $late;
    $rate = $total > 0 ? round(($present / $total) * 100, 2) : 0;

    return [
        'present' => $present,
        'absent' => $absent,
        'late' => $late,
        'attendance_rate' => $rate,
    ];
}

function apiAdvisorySectionStatus(PDO $db, array $advisory, string $academicYear, string $semester, int $teacherId): string {
    $semesterCondition = $semester !== ''
        ? "AND rc.semester = ?"
        : "AND rc.semester IS NULL";
    $stmt = $db->prepare("SELECT
                                COUNT(*) AS total_rows,
                                SUM(CASE WHEN rc.status IN ('pending','submitted_admin') THEN 1 ELSE 0 END) AS pending_count,
                                SUM(CASE WHEN rc.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                                SUM(CASE WHEN rc.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                            FROM report_card_approvals rc
                            JOIN users s ON s.id = rc.student_id
                            WHERE s.role = 'student'
                            AND s.status IN ('active', 'pending')
                            AND s.grade_level = ?
                            AND " . sectionMatchSql('s.section') . "
                            AND rc.academic_year = ?
                            {$semesterCondition}
                            AND rc.advisory_teacher_id = ?");
    $params = [
        (int)$advisory['grade_level'],
        (string)$advisory['section'],
        (string)$advisory['section'],
        $academicYear,
        $teacherId,
    ];
    if ($semester !== '') {
        array_splice($params, 4, 0, [$semester]);
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int)($row['total_rows'] ?? 0);
    $pending = (int)($row['pending_count'] ?? 0);
    $approved = (int)($row['approved_count'] ?? 0);
    $rejected = (int)($row['rejected_count'] ?? 0);
    if ($total <= 0) {
        return '';
    }
    if ($pending > 0 && $approved === 0 && $rejected === 0) {
        return 'pending';
    }
    if ($approved === $total) {
        return 'approved';
    }
    if ($rejected === $total) {
        return 'rejected';
    }
    return 'mixed';
}

function apiMarkGradePendingApproval(PDO $db, int $gradeId, int $teacherId): void {
    $stmt = $db->prepare("INSERT INTO grade_approvals (grade_id, status, submitted_by, submitted_at, reviewed_by, reviewed_at, remarks)
                          VALUES (?, 'submitted', ?, NOW(), NULL, NULL, NULL)
                          ON DUPLICATE KEY UPDATE
                            status = 'submitted',
                            submitted_by = VALUES(submitted_by),
                            submitted_at = NOW(),
                            reviewed_by = NULL,
                            reviewed_at = NULL,
                            remarks = 'Awaiting admin review'");
    $stmt->execute([$gradeId, $teacherId]);
}

apiJson(['ok' => false, 'message' => 'Route not found'], 404);
