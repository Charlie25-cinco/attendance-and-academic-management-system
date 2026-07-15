<?php

function dbHasColumn(PDO $db, string $table, string $column): bool {
    return SchemaCache::hasColumn($db, $table, $column);
}

function dbHasTable(PDO $db, string $table): bool {
    return SchemaCache::hasTable($db, $table);
}

function appIsProductionEnv(): bool {
    return strtolower(trim((string)appEnvValue('APP_ENV', ''))) === 'production';
}

function appSecurityValueLooksUnsafe(?string $value, array $blockedValues): bool {
    $value = trim((string)$value);
    if ($value === '') { return true; }
    foreach ($blockedValues as $blockedValue) {
        if (hash_equals((string)$blockedValue, $value)) { return true; }
    }
    return false;
}

function appGuardProductionSecret(string $envKey, ?string $value, array $blockedValues): void {
    if (!appIsProductionEnv()) { return; }
    if (appSecurityValueLooksUnsafe($value, $blockedValues)) {
        error_log('[SECURITY] ' . $envKey . ' must be changed before production deployment.');
        die('Application configuration error - ' . $envKey . ' is not configured securely');
    }
}

function getDefaultNewUserPassword(): string {
    $env = appEnvValue('DEFAULT_NEW_USER_PASSWORD', '');
    if ($env !== '' && $env !== false) {
        appGuardProductionSecret('DEFAULT_NEW_USER_PASSWORD', (string)$env, ['bshsams341227', 'change-me', 'change-this-before-production']);
        return (string)$env;
    }
    $localPath = __DIR__ . '/../config/App.local.php';
    if (file_exists($localPath)) {
        $config = require $localPath;
        if (is_array($config) && !empty($config['default_new_user_password'])) {
            appGuardProductionSecret('DEFAULT_NEW_USER_PASSWORD', (string)$config['default_new_user_password'], ['bshsams341227', 'change-me', 'change-this-before-production']);
            return (string)$config['default_new_user_password'];
        }
    }
    $isProduction = appEnvValue('APP_ENV', '') === 'production';
    if ($isProduction) {
        error_log('[SECURITY] DEFAULT_NEW_USER_PASSWORD must be set in production.');
        die('Application configuration error - DEFAULT_NEW_USER_PASSWORD not set');
    }
    error_log('[BSHS] WARNING: DEFAULT_NEW_USER_PASSWORD env var not set. Using the legacy local-development password.');
    return 'bshsams341227';
}

function getFirstRunAdminPassword(): string {
    $env = appEnvValue('FIRST_RUN_ADMIN_PASSWORD', '');
    if ($env !== '' && $env !== false) {
        appGuardProductionSecret('FIRST_RUN_ADMIN_PASSWORD', (string)$env, ['bshsams341227', 'change-me', 'change-this-before-production']);
        return (string)$env;
    }
    return getDefaultNewUserPassword();
}

function validateStrongPassword(string $password, ?string &$error = null): bool {
    if (strlen($password) < 12) { $error = 'Password must be at least 12 characters.'; return false; }
    if (strlen($password) > 72) { $error = 'Password must not exceed 72 characters.'; return false; }
    if (!preg_match('/[A-Z]/', $password)) { $error = 'Password must contain at least one uppercase letter.'; return false; }
    if (!preg_match('/[a-z]/', $password)) { $error = 'Password must contain at least one lowercase letter.'; return false; }
    if (!preg_match('/[0-9]/', $password)) { $error = 'Password must contain at least one number.'; return false; }
    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) { $error = 'Password must contain at least one special character.'; return false; }
    $error = null;
    return true;
}

function referenceCodeYearPart(?string $academicYear = null): string {
    if ($academicYear !== null && preg_match('/^\d{4}-(\d{4})$/', $academicYear, $m)) {
        return substr((string)$m[1], -2);
    }
    return date('y');
}

function nextReferenceSuffix(PDO $db, string $prefix): int {
    $stmt = $db->prepare("SELECT reference_code FROM users WHERE reference_code LIKE ? ORDER BY CAST(SUBSTRING_INDEX(reference_code, '-', -1) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) { return ((int)$m[1]) + 1; }
    return 1;
}

function generateReferenceCode(string $role, ?PDO $db = null, ?string $academicYear = null): string {
    $role = strtolower(trim($role));
    $yearPart = referenceCodeYearPart($academicYear);
    $prefix = match ($role) {
        'teacher' => 'T' . $yearPart . '-',
        'student' => 'S' . $yearPart . '-',
        'parent' => 'P' . $yearPart . '-',
        'admin' => 'A341227-',
        default => strtoupper(substr($role, 0, 1)) . $yearPart . '-',
    };
    if ($role === 'admin') { return $prefix . '1'; }
    $next = 1;
    if ($db instanceof PDO) { $next = nextReferenceSuffix($db, $prefix); }
    return $prefix . $next;
}

function isValidPersonName(string $value, bool $allowEmpty = false): bool {
    $value = trim($value);
    if ($value === '') { return $allowEmpty; }
    if (!preg_match("/^[\\p{L}][\\p{L}\\s'.-]*$/u", $value)) { return false; }
    if (preg_match("/['.-]{2,}/", $value)) { return false; }
    return true;
}

function hasMinimumLetters(string $value, int $minimum = 2): bool {
    $value = trim($value);
    if ($value === '') { return false; }
    preg_match_all('/\p{L}/u', $value, $matches);
    return count($matches[0] ?? []) >= $minimum;
}

function isValidMiddleName(string $value): bool {
    $value = trim($value);
    if ($value === '') { return true; }
    return isValidPersonName($value, true) && hasMinimumLetters($value, 2);
}

function normalizeIdArray($values): array {
    if (!is_array($values)) { return []; }
    $normalized = array_map('intval', $values);
    return array_values(array_unique(array_filter($normalized, function ($v) { return $v > 0; })));
}

function currentAcademicYear(): string {
    $year = (int)date('Y'); $month = (int)date('n');
    $start = $month >= 6 ? $year : $year - 1;
    return $start . '-' . ($start + 1);
}

function academicYearStart(string $academicYear): int {
    if (preg_match('/^(\d{4})-\d{4}$/', trim($academicYear), $m)) { return (int)$m[1]; }
    return (int)date('Y');
}

function isStrengthenedGrade11(int $gradeLevel, ?string $academicYear = null): bool {
    $academicYear = $academicYear ?: currentAcademicYear();
    return $gradeLevel === 11 && academicYearStart($academicYear) >= 2026;
}

function strengthenedShsCurriculum(int $gradeLevel, ?string $academicYear = null): ?string {
    return isStrengthenedGrade11($gradeLevel, $academicYear) ? 'strengthened_shs' : null;
}

function strengthenedShsProgram(int $gradeLevel, ?string $track, ?string $academicYear = null): ?string {
    if (!isStrengthenedGrade11($gradeLevel, $academicYear)) { return null; }
    return $track === 'techpro' ? 'technical_professional' : 'academic_strengthened';
}

function strengthenedShsProgramLabel(?string $program): string {
    return match ($program) {
        'academic_strengthened' => 'Academic Track (Strengthened)',
        'technical_professional' => 'Technical Professional',
        default => '',
    };
}

function ensureStrengthenedShsColumns(PDO $db): void {
    static $ready = false;
    if ($ready) { return; }

    $definitions = [
        'users' => [
            'name_extension' => "ALTER TABLE users ADD COLUMN name_extension VARCHAR(10) NULL COMMENT 'Jr., Sr., III, etc.' AFTER last_name",
            'religion' => "ALTER TABLE users ADD COLUMN religion VARCHAR(50) NULL AFTER date_of_birth",
            'father_name' => "ALTER TABLE users ADD COLUMN father_name VARCHAR(100) NULL AFTER address",
            'mother_name' => "ALTER TABLE users ADD COLUMN mother_name VARCHAR(100) NULL AFTER father_name",
            'guardian_name' => "ALTER TABLE users ADD COLUMN guardian_name VARCHAR(100) NULL AFTER mother_name",
            'guardian_relationship' => "ALTER TABLE users ADD COLUMN guardian_relationship VARCHAR(50) NULL AFTER guardian_name",
            'curriculum' => "ALTER TABLE users ADD COLUMN curriculum VARCHAR(50) NULL AFTER track",
            'program' => "ALTER TABLE users ADD COLUMN program VARCHAR(50) NULL AFTER curriculum",
        ],
        'classes' => ['curriculum' => "ALTER TABLE classes ADD COLUMN curriculum VARCHAR(50) NULL AFTER track", 'program' => "ALTER TABLE classes ADD COLUMN program VARCHAR(50) NULL AFTER curriculum"],
        'sections' => ['curriculum' => "ALTER TABLE sections ADD COLUMN curriculum VARCHAR(50) NULL AFTER track", 'program' => "ALTER TABLE sections ADD COLUMN program VARCHAR(50) NULL AFTER curriculum"],
        'enrollments' => ['curriculum' => "ALTER TABLE enrollments ADD COLUMN curriculum VARCHAR(50) NULL AFTER semester", 'program' => "ALTER TABLE enrollments ADD COLUMN program VARCHAR(50) NULL AFTER curriculum"],
    ];

    foreach ($definitions as $table => $columns) {
        foreach ($columns as $column => $sql) {
            try {
                $exists = (bool)$db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_NUM);
                if (!$exists) { $db->exec($sql); }
            } catch (Throwable $e) { error_log('Strengthened SHS column check failed: ' . $e->getMessage()); }
        }
    }

    try {
        $db->exec("UPDATE users SET curriculum = 'strengthened_shs', program = CASE WHEN track = 'techpro' THEN 'technical_professional' ELSE 'academic_strengthened' END WHERE role = 'student' AND grade_level = 11 AND (curriculum IS NULL OR curriculum = '' OR program IS NULL OR program = '')");
        $db->exec("UPDATE sections SET curriculum = 'strengthened_shs', program = CASE WHEN track = 'techpro' THEN 'technical_professional' ELSE 'academic_strengthened' END WHERE grade_level = 11 AND (curriculum IS NULL OR curriculum = '' OR program IS NULL OR program = '')");
        $db->exec("UPDATE classes SET curriculum = 'strengthened_shs', program = CASE WHEN track = 'techpro' THEN 'technical_professional' ELSE 'academic_strengthened' END WHERE grade_level = 11 AND (curriculum IS NULL OR curriculum = '' OR program IS NULL OR program = '')");
        $db->exec("UPDATE enrollments e JOIN classes c ON c.id = e.class_id SET e.semester = NULL, e.curriculum = 'strengthened_shs', e.program = CASE WHEN c.track = 'techpro' THEN 'technical_professional' ELSE 'academic_strengthened' END WHERE c.grade_level = 11 AND CAST(SUBSTRING(e.academic_year, 1, 4) AS UNSIGNED) >= 2026 AND (e.semester IS NOT NULL OR e.curriculum IS NULL OR e.curriculum = '' OR e.program IS NULL OR e.program = '')");
    } catch (Throwable $e) { error_log('Strengthened SHS backfill failed: ' . $e->getMessage()); }

    $ready = true;
}

function syncStudentEnrollments(PDO $db, int $studentId, int $gradeLevel, string $section, ?string $track = null, ?string $academicYear = null): void {
    ensureStrengthenedShsColumns($db);
    $academicYear = $academicYear ?: currentAcademicYear();
    $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
    $program = strengthenedShsProgram($gradeLevel, $track, $academicYear);

    $sql = "SELECT id FROM classes WHERE grade_level = ? AND section = ? AND status = 'active'";
    $params = [$gradeLevel, $section];
    if ($track) { $sql .= " AND track = ?"; $params[] = $track; }
    $classStmt = $db->prepare($sql);
    $classStmt->execute($params);
    $classIds = array_map('intval', $classStmt->fetchAll(PDO::FETCH_COLUMN));

    $deleteStmt = $db->prepare("DELETE FROM enrollments WHERE student_id = ? AND academic_year = ?");
    $deleteStmt->execute([$studentId, $academicYear]);

    if (empty($classIds)) { return; }

    $semester = $curriculum === 'strengthened_shs' ? null : 1;
    $insertStmt = $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, curriculum, program, status, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, 'enrolled', NOW())");
    foreach ($classIds as $classId) {
        $insertStmt->execute([$studentId, $classId, $academicYear, $semester, $curriculum, $program]);
    }
}

function parseSchedule(string $scheduleStr): ?array {
    if ($scheduleStr === '') return null;
    $segments = ScheduleParser::parseSegments($scheduleStr);
    if (empty($segments)) return null;
    return array_map(function ($s) { return ['day' => $s['day'], 'start' => $s['start'], 'end' => $s['end']]; }, $segments);
}

function timeToMinutes(string $timeStr): int { return ScheduleParser::toMinutes($timeStr); }

function hasTimeConflict(array $sched1, array $sched2): bool {
    return ScheduleParser::hasConflict($sched1['segments'] ?? [], $sched2['segments'] ?? []);
}

function getTeacherRoles(PDO $db, int $teacherId): array {
    $roles = ['adviser' => false, 'subject_teacher' => false, 'sections' => [], 'classes' => []];

    $adviserStmt = $db->prepare("SELECT id, class_name, grade_level, section FROM classes WHERE teacher_id = ? AND status = 'active'");
    $adviserStmt->execute([$teacherId]);
    $adviserClasses = $adviserStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($adviserClasses)) {
        $roles['adviser'] = true;
        $roles['sections'] = array_map(function($c) {
            return ['class_id' => (int)$c['id'], 'class_name' => (string)$c['class_name'], 'grade_level' => (int)$c['grade_level'], 'section' => (string)$c['section']];
        }, $adviserClasses);
    }

    $subjectStmt = $db->prepare("SELECT cs.class_id, c.class_name, c.grade_level, c.section, s.subject_name FROM class_subjects cs JOIN classes c ON c.id = cs.class_id LEFT JOIN subjects s ON s.id = cs.subject_id WHERE cs.teacher_id = ? AND c.status = 'active'");
    $subjectStmt->execute([$teacherId]);
    $subjectClasses = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($subjectClasses)) {
        $roles['subject_teacher'] = true;
        $roles['classes'] = array_map(function($c) {
            return ['class_id' => (int)$c['class_id'], 'class_name' => (string)$c['class_name'], 'grade_level' => (int)$c['grade_level'], 'section' => (string)$c['section'], 'subject_name' => (string)($c['subject_name'] ?? '')];
        }, $subjectClasses);
    }

    return $roles;
}

function ensureAdminAuditLogTable(PDO $db): void {
    static $ready = false;
    if ($ready) { return; }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, admin_user_id INT NOT NULL, action_name VARCHAR(100) NOT NULL, target_type VARCHAR(50) NOT NULL, target_id INT DEFAULT NULL, details_json TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_admin_audit_logs_admin_user_id (admin_user_id), INDEX idx_admin_audit_logs_action_name (action_name), INDEX idx_admin_audit_logs_target_type (target_type), INDEX idx_admin_audit_logs_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('Admin audit log table check failed: ' . $e->getMessage()); }
    $ready = true;
}

function recordAdminAuditLog(PDO $db, string $actionName, string $targetType, ?int $targetId = null, array $details = [], ?int $adminUserId = null): void {
    $adminUserId = $adminUserId ?? (int)($_SESSION['user_id'] ?? 0);
    if ($adminUserId <= 0) { return; }
    ensureAdminAuditLogTable($db);
    try {
        $stmt = $db->prepare("INSERT INTO admin_audit_logs (admin_user_id, action_name, target_type, target_id, details_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$adminUserId, substr($actionName, 0, 100), substr($targetType, 0, 50), $targetId && $targetId > 0 ? $targetId : null, !empty($details) ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { error_log('Admin audit log insert failed: ' . $e->getMessage()); }
}

function ensureAuthLoginLogsTable(PDO $db): void {
    static $ready = false;
    if ($ready) { return; }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS auth_login_logs (id INT AUTO_INCREMENT PRIMARY KEY, reference_code VARCHAR(50) NOT NULL, user_id INT NULL, success TINYINT(1) NOT NULL DEFAULT 0, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, failure_reason VARCHAR(120) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_ref_time (reference_code, created_at), KEY idx_success_time (success, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $columns = SchemaCache::getColumns($db, 'auth_login_logs');
        if (!in_array('user_id', $columns, true)) { $db->exec("ALTER TABLE auth_login_logs ADD COLUMN user_id INT NULL AFTER reference_code"); }
        if (!in_array('user_agent', $columns, true)) { $db->exec("ALTER TABLE auth_login_logs ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address"); }
        if (!isset($columns['failure_reason'])) { $db->exec("ALTER TABLE auth_login_logs ADD COLUMN failure_reason VARCHAR(120) NULL AFTER user_agent"); }
    } catch (Throwable $e) { error_log('Auth login log table check failed: ' . $e->getMessage()); }
    $ready = true;
}

function recordAuthLoginLog(PDO $db, string $referenceCode, ?int $userId, bool $success, string $failureReason = ''): void {
    ensureAuthLoginLogsTable($db);
    try {
        $stmt = $db->prepare("INSERT INTO auth_login_logs (reference_code, user_id, success, ip_address, user_agent, failure_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([substr(strtoupper(trim($referenceCode)), 0, 50), $userId && $userId > 0 ? $userId : null, $success ? 1 : 0, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $failureReason !== '' ? substr($failureReason, 0, 120) : null]);
    } catch (Throwable $e) { error_log('Auth login log insert failed: ' . $e->getMessage()); }
}

function ensureSchoolSettingsTable(PDO $db): void {
    static $ready = false;
    if ($ready) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS school_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(50) UNIQUE NOT NULL, setting_value TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('School settings table check failed: ' . $e->getMessage()); }
    $ready = true;
}

function getSchoolSetting(PDO $db, string $key, string $default = ''): string {
    ensureSchoolSettingsTable($db);
    try {
        $stmt = $db->prepare("SELECT setting_value FROM school_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Throwable $e) { return $default; }
}

function getSchoolSettings(PDO $db): array {
    ensureSchoolSettingsTable($db);
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM school_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $settings[$row['setting_key']] = $row['setting_value']; }
        return $settings;
    } catch (Throwable $e) { return []; }
}

function setSchoolSetting(PDO $db, string $key, string $value): bool {
    ensureSchoolSettingsTable($db);
    try {
        $stmt = $db->prepare("INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $stmt->execute([$key, $value]);
    } catch (Throwable $e) { error_log('School setting update failed: ' . $e->getMessage()); return false; }
}
