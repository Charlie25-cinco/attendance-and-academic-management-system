<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

function apiSchemaCache(PDO $db): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $allowedTables = [
        'users', 'classes', 'subjects', 'class_subjects', 'enrollments',
        'parent_students', 'announcements', 'class_announcements',
        'materials', 'grades', 'grade_items', 'grade_item_scores',
        'grade_item_score_verifications', 'grade_approvals',
        'report_card_approvals', 'user_settings', 'attendance',
        'auth_password_resets', 'auth_password_change_tokens',
        'auth_remember_tokens', 'user_notifications',
        'mobile_push_tokens', 'rate_limits', 'grade_approvals',
    ];

    $cache = ['tables' => $allowedTables, 'columns' => []];

    foreach ($allowedTables as $table) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            $cache['columns'][$table] = array_map(
                static fn(array $row): string => (string)$row['Field'],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable $e) {
            $cache['columns'][$table] = [];
        }
    }

    $stmt = $db->query("SHOW TABLES");
    $cache['existing_tables'] = array_map(
        static fn($row): string => (string)current((array)$row),
        $stmt->fetchAll(PDO::FETCH_NUM)
    );

    return $cache;
}

function apiValidateTableName(string $table): bool {
    $cache = apiSchemaCache(apiDb());
    return in_array($table, $cache['tables'], true);
}

function apiValidateColumnName(string $column): bool {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
        return false;
    }
    return true;
}

function apiTableHasColumn(PDO $db, string $table, string $column): bool {
    if (!apiValidateTableName($table) || !apiValidateColumnName($column)) {
        return false;
    }
    $cache = apiSchemaCache($db);
    return in_array($column, $cache['columns'][$table] ?? [], true);
}

function apiTableExists(PDO $db, string $table): bool {
    if (!apiValidateTableName($table)) {
        return false;
    }
    $cache = apiSchemaCache($db);
    return in_array($table, $cache['existing_tables'], true);
}

function apiConfig(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }
    return $default;
}

function apiFailSecretConfiguration(string $detail): void {
    error_log('[SECURITY] ' . $detail);
    if (function_exists('apiJson')) {
        apiJson(['ok' => false, 'message' => 'Application configuration error'], 500);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Application configuration error'], JSON_UNESCAPED_SLASHES);
    exit;
}

function apiWriteSecretFile(string $path, string $contents): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
    }
    $written = @file_put_contents($path, $contents, LOCK_EX);
    return $written !== false;
}

function apiSecret(): string {
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $secret = apiConfig('API_AUTH_SECRET', '');
    if ($secret !== '') {
        return $secret;
    }

    $isProduction = apiConfig('APP_ENV', 'development') === 'production';
    if ($isProduction) {
        error_log('[SECURITY] API_AUTH_SECRET is not set in production. Set API_AUTH_SECRET environment variable.');
        apiFailSecretConfiguration('API_AUTH_SECRET must be set in production. Set the API_AUTH_SECRET environment variable.');
    }

    error_log('[SECURITY] API_AUTH_SECRET is not set — using api/.api_secret. Set API_AUTH_SECRET in production.');
    $secretFile = __DIR__ . '/.api_secret';
    if (file_exists($secretFile)) {
        $secret = trim((string)file_get_contents($secretFile));
    }
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        if (!apiWriteSecretFile($secretFile, $secret)) {
            apiFailSecretConfiguration('Cannot write ' . $secretFile . ' — set API_AUTH_SECRET or fix permissions.');
        }
    }
    return $secret;
}

function apiSyncSecret(): string {
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $secret = apiConfig('API_SYNC_SECRET', '');
    if ($secret !== '') {
        return $secret;
    }

    $isProduction = apiConfig('APP_ENV', 'development') === 'production';
    if ($isProduction) {
        error_log('[SECURITY] API_SYNC_SECRET is not set in production. Set API_SYNC_SECRET environment variable.');
        apiFailSecretConfiguration('API_SYNC_SECRET must be set in production. Set the API_SYNC_SECRET environment variable.');
    }

    error_log('[SECURITY] API_SYNC_SECRET is not set — using api/.api_sync_secret. Set API_SYNC_SECRET in production.');
    $secretFile = __DIR__ . '/.api_sync_secret';
    if (file_exists($secretFile)) {
        $secret = trim((string)file_get_contents($secretFile));
    }
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        if (!apiWriteSecretFile($secretFile, $secret)) {
            apiFailSecretConfiguration('Cannot write ' . $secretFile . ' — set API_SYNC_SECRET or fix permissions.');
        }
    }
    return $secret;
}

function apiDb(): ?PDO {
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $database = new Database();
    $db = $database->getConnection();
    return $db ?: null;
}

function apiJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function apiRequestBody(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function apiHandleCors(): void {
    $origin = apiConfig('API_ALLOWED_ORIGIN', '*');
    if ($origin === '*' && apiConfig('APP_ENV', 'development') === 'production') {
        apiFailSecretConfiguration('API_ALLOWED_ORIGIN must be set to a trusted origin in production.');
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Sync-Key');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function apiIssueToken(array $user): string {
    $payload = [
        'sub' => (int)$user['id'],
        'role' => (string)$user['role'],
        'exp' => time() + 86400 * 7,
    ];
    $json = json_encode($payload);
    $encoded = rtrim(strtr(base64_encode($json ?: '{}'), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $encoded, apiSecret());
    return $encoded . '.' . $signature;
}

function apiReadTokenPayload(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encoded, $signature] = $parts;
    $expected = hash_hmac('sha256', $encoded, apiSecret());
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $base64 = strtr($encoded, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding > 0) {
        $base64 .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($base64, true);
    if ($decoded === false) {
        return null;
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload) || !isset($payload['sub'], $payload['role'], $payload['exp'])) {
        return null;
    }

    if ((int)$payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function apiAuthUser(): ?array {
    // Check for PHP session (for web-based access)
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id'])) {
        $db = apiDb();
        if ($db) {
            $stmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role, status, grade_level, section
                                  FROM users
                                  WHERE id = ? AND status = 'active'
                                  LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        }
    }

    // Check for Bearer token (for mobile app access)
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '') {
        $header = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    if ($header === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string)$key, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }
    }
    $token = '';
    if (stripos($header, 'Bearer ') === 0) {
        $token = trim(substr($header, 7));
    }
    if ($token === '') {
        return null;
    }

    $payload = apiReadTokenPayload($token);
    if (!$payload) {
        return null;
    }

    $db = apiDb();
    if (!$db) {
        return null;
    }

    $stmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role, status, grade_level, section
                          FROM users
                          WHERE id = ? AND role = ? AND status = 'active'
                          LIMIT 1");
    $stmt->execute([(int)$payload['sub'], (string)$payload['role']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function apiRequireUser(): array {
    $user = apiAuthUser();
    if (!$user) {
        apiJson(['ok' => false, 'message' => 'Unauthorized'], 401);
    }
    return $user;
}

function apiRequireSyncKey(): void {
    $syncKey = (string)($_SERVER['HTTP_X_SYNC_KEY'] ?? '');
    if ($syncKey === '' || !hash_equals(apiSyncSecret(), $syncKey)) {
        apiJson(['ok' => false, 'message' => 'Unauthorized'], 401);
    }
}

function apiTodayAbbr(): string {
    $abbr = date('D');
    $valid = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return in_array($abbr, $valid, true) ? $abbr : 'Mon';
}

function apiCurrentAcademicYear(): string {
    $year = (int)date('Y');
    $month = (int)date('n');
    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }
    return ($year - 1) . '-' . $year;
}

function apiAcademicYearForDate(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return apiCurrentAcademicYear();
    }
    [$year, $month] = array_map('intval', explode('-', substr($date, 0, 7)));
    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }
    return ($year - 1) . '-' . $year;
}

function apiGradingSystemForAcademicYear(?string $academicYear): string {
    if ($academicYear === null || $academicYear === '') {
        return '3_term';
    }

    if (preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m)) {
        return ((int)$m[1] >= 2026) ? '3_term' : '4_quarter';
    }

    return '3_term';
}

function apiDefaultPeriodLabel(?string $academicYear): string {
    return apiGradingSystemForAcademicYear($academicYear) === '4_quarter' ? 'Q1' : 'Term1';
}

function apiNormalizePeriodLabel(string $value, ?string $academicYear = null): string {
    $normalized = strtoupper(trim($value));
    $gradingSystem = apiGradingSystemForAcademicYear($academicYear);

    if ($normalized === '') {
        return '';
    }

    if ($gradingSystem === '4_quarter') {
        if (in_array($normalized, ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
            return $normalized;
        }
        if (preg_match('/^TERM([123])$/', $normalized, $m)) {
            return 'Q' . $m[1];
        }
        return '';
    }

    if (preg_match('/^TERM([123])$/', $normalized, $m)) {
        return 'Term' . $m[1];
    }
    if (preg_match('/^Q([1-4])$/', $normalized, $m)) {
        return ((int)$m[1] >= 4) ? 'Term3' : 'Term' . $m[1];
    }

    return '';
}

function apiCurrentSemester(): int {
    $month = (int)date('n');
    return ($month >= 6 && $month <= 11) ? 1 : 2;
}

function apiSemesterForDate(string $date): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return apiCurrentSemester();
    }
    $month = (int)substr($date, 5, 2);
    return ($month >= 6 && $month <= 11) ? 1 : 2;
}

function apiParseSchedule(string $schedule): array {
    return ScheduleParser::getDaysAndTime($schedule);
}

function apiParseScheduleForToday(string $schedule, string $todayAbbr): ?array {
    $parsed = apiParseSchedule($schedule);
    if (!in_array($todayAbbr, $parsed['days'], true)) {
        return null;
    }
    return ['time' => $parsed['time']];
}

function apiScheduleStartMinutes(string $timeRange): int {
    return ScheduleParser::startMinutes($timeRange);
}

function apiStatusTone(string $status): string {
    $status = strtolower(trim($status));
    if ($status === 'present') {
        return 'green';
    }
    if ($status === 'late' || $status === 'pending') {
        return 'amber';
    }
    if ($status === 'absent') {
        return 'red';
    }
    return 'blue';
}

function apiFullName(array $user): string {
    return trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
}

function apiAnnouncementTone(string $category): string {
    $category = strtolower(trim($category));
    if ($category === 'urgent') {
        return 'red';
    }
    if ($category === 'event') {
        return 'amber';
    }
    if ($category === 'academic') {
        return 'green';
    }
    return 'blue';
}

function apiGradeTermColumn(PDO $db): string {
    if (apiTableHasColumn($db, 'grades', 'term')) {
        return 'term';
    }
    if (apiTableHasColumn($db, 'grades', 'quarter')) {
        return 'quarter';
    }
    return 'term';
}

function apiTeacherOwnsClass(PDO $db, int $teacherId, int $classId): bool {
    $stmt = $db->prepare("SELECT 1
                          FROM classes c
                          WHERE c.id = ?
                          AND c.status = 'active'
                          AND (
                            c.teacher_id = ?
                            OR EXISTS (
                                SELECT 1
                                FROM class_subjects cs
                                WHERE cs.class_id = c.id
                                AND cs.teacher_id = ?
                            )
                          )
                          LIMIT 1");
    $stmt->execute([$classId, $teacherId, $teacherId]);
    return (bool)$stmt->fetchColumn();
}

function apiTeacherClassSubjectId(PDO $db, int $teacherId, int $classId): int {
    $stmt = $db->prepare("SELECT id
                          FROM class_subjects
                          WHERE class_id = ? AND teacher_id = ?
                          ORDER BY id ASC
                          LIMIT 1");
    $stmt->execute([$classId, $teacherId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function apiClassHasScheduleOnDate(PDO $db, int $classId, string $date): bool {
    $stmt = $db->prepare("SELECT schedule
                          FROM classes
                          WHERE id = ? AND status = 'active'
                          LIMIT 1");
    $stmt->execute([$classId]);
    $schedule = (string)($stmt->fetchColumn() ?: '');
    if ($schedule === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = apiParseSchedule($schedule);
    if (empty($parsed['days'])) {
        return false;
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        return false;
    }

    return in_array($dateObj->format('D'), $parsed['days'], true);
}

function apiClassWeights(PDO $db, int $classId): array {
    $stmt = $db->prepare("SELECT
                          COALESCE(ww_weight, 25) AS ww_weight,
                          COALESCE(pt_weight, 50) AS pt_weight,
                          COALESCE(assessment_weight, 25) AS assessment_weight
                          FROM classes
                          WHERE id = ?
                          LIMIT 1");
    $stmt->execute([$classId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'ww_weight' => (float)($row['ww_weight'] ?? 25),
        'pt_weight' => (float)($row['pt_weight'] ?? 50),
        'assessment_weight' => (float)($row['assessment_weight'] ?? 25),
    ];
}

function apiActiveClassStudents(PDO $db, int $classId, string $sex = ''): array {
    $sex = strtolower(trim($sex));
    $params = [$classId];
    $sql = "SELECT u.id, u.reference_code, u.first_name, u.last_name
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            WHERE e.class_id = ?
            AND e.status = 'enrolled'
            AND u.role = 'student'
            AND u.status IN ('active', 'pending')";
    if (in_array($sex, ['male', 'female'], true)) {
        if ($sex === 'male') {
            $sql .= " AND LOWER(COALESCE(u.sex, '')) IN ('male', 'm')";
        } else {
            $sql .= " AND LOWER(COALESCE(u.sex, '')) IN ('female', 'f')";
        }
    }
    $sql .= " ORDER BY u.last_name, u.first_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gradePeriodBounds($academicYear, $semester, $quarter = null) {
    preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m);
    $startYear = (int)($m[1] ?? date('Y'));
    $endYear = (int)($m[2] ?? ($startYear + 1));
    $period = apiNormalizePeriodLabel((string)$quarter, $academicYear);
    $gradingSystem = apiGradingSystemForAcademicYear($academicYear);

    if ($period === '') {
        $period = apiDefaultPeriodLabel($academicYear);
    }

    if ($gradingSystem === '4_quarter') {
        if ($period === 'Q1') return [$startYear . '-06-01', $startYear . '-08-31'];
        if ($period === 'Q2') return [$startYear . '-09-01', $startYear . '-11-30'];
        if ($period === 'Q3') return [$startYear . '-12-01', $endYear . '-' . str_pad((string)cal_days_in_month(CAL_GREGORIAN, 2, $endYear), 2, '0', STR_PAD_LEFT)];
        return [$endYear . '-03-01', $endYear . '-05-31'];
    }

    if ($period === 'Term1') return [$startYear . '-07-01', $startYear . '-09-30'];
    if ($period === 'Term2') return [$startYear . '-10-01', $startYear . '-12-31'];
    return [$endYear . '-01-01', $endYear . '-03-31'];
}

function apiGetTeacherRoles(PDO $db, int $teacherId): array {
    $roles = ['adviser' => false, 'subject_teacher' => false, 'sections' => [], 'classes' => []];
    
    $adviserStmt = $db->prepare("SELECT id, class_name, grade_level, section 
                                 FROM classes 
                                 WHERE teacher_id = ? AND status = 'active'");
    $adviserStmt->execute([$teacherId]);
    $adviserClasses = $adviserStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($adviserClasses)) {
        $roles['adviser'] = true;
        $roles['sections'] = array_map(function($c) {
            return [
                'class_id' => (int)$c['id'],
                'class_name' => (string)$c['class_name'],
                'grade_level' => (int)$c['grade_level'],
                'section' => (string)$c['section']
            ];
        }, $adviserClasses);
    }
    
    $subjectStmt = $db->prepare("SELECT cs.class_id, c.class_name, c.grade_level, c.section, s.subject_name
                                 FROM class_subjects cs
                                 JOIN classes c ON c.id = cs.class_id
                                 LEFT JOIN subjects s ON s.id = cs.subject_id
                                 WHERE cs.teacher_id = ? AND c.status = 'active'");
    $subjectStmt->execute([$teacherId]);
    $subjectClasses = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($subjectClasses)) {
        $roles['subject_teacher'] = true;
        $roles['classes'] = array_map(function($c) {
            return [
                'class_id' => (int)$c['class_id'],
                'class_name' => (string)$c['class_name'],
                'grade_level' => (int)$c['grade_level'],
                'section' => (string)$c['section'],
                'subject_name' => (string)($c['subject_name'] ?? '')
            ];
        }, $subjectClasses);
    }
    
    return $roles;
}
