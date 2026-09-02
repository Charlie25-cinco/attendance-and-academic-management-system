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

function appPerfLoggingEnabled(): bool {
    return appEnvValue('APP_PERF_LOG', appIsProductionEnv() ? '0' : '1') === '1';
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

function appWarnUnsafeDefaults(string $envKey, ?string $value, array $blockedValues): void {
    $value = trim((string)$value);
    if ($value === '') { return; }
    foreach ($blockedValues as $blockedValue) {
        if (hash_equals((string)$blockedValue, $value)) {
            if (appIsProductionEnv()) {
                error_log('[SECURITY] ' . $envKey . ' must be changed before production deployment.');
                die('Application configuration error - ' . $envKey . ' is not configured securely');
            }
            error_log('[SECURITY WARNING] ' . $envKey . ' is using a known default value. Change it before deploying to production.');
            return;
        }
    }
}

function requireCsrfToken(): void {
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = trim((string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

function ensureUserApiTokenVersionColumn(PDO $db): void {
    static $done = false;
    if ($done) { return; }
    try {
        if (!\BshsAms\Database\SchemaCache::hasColumn($db, 'users', 'api_token_version')) {
            $db->exec("ALTER TABLE users ADD COLUMN api_token_version INT NOT NULL DEFAULT 0 COMMENT 'Bumped on password change to revoke issued API bearer tokens' AFTER password");
        }
        $done = true;
    } catch (Throwable $e) {
        error_log('api_token_version column check failed: ' . $e->getMessage());
    }
}

function bumpUserApiTokenVersion(PDO $db, int $userId): void {
    if ($userId <= 0) { return; }
    try {
        ensureUserApiTokenVersionColumn($db);
        $stmt = $db->prepare("UPDATE users SET api_token_version = api_token_version + 1 WHERE id = ?");
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('API token version bump failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

function normalizeDate($value): string {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function normalizeInt($value): int {
    if (!is_scalar($value)) {
        return 0;
    }
    $value = trim((string)$value);
    return ctype_digit($value) ? (int)$value : 0;
}

function normalizeText($value): string {
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string)$value);
}

function normalizeRoomValue($room): string {
    $room = preg_replace('/\\s+/', ' ', trim((string)$room));
    if ($room === '') {
        return '';
    }
    return strtoupper(substr($room, 0, 1)) . substr($room, 1);
}

function normalizeSubjectNameValue($name): string {
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    if ($name === '') {
        return '';
    }
    return (string)preg_replace_callback('/\b\p{L}/u', function ($matches) {
        return mb_strtoupper($matches[0], 'UTF-8');
    }, $name);
}

function getDefaultNewUserPassword(): string {
    $env = appEnvValue('DEFAULT_NEW_USER_PASSWORD', '');
    if ($env !== '' && $env !== false) {
        appWarnUnsafeDefaults('DEFAULT_NEW_USER_PASSWORD', (string)$env, ['bshsams341227', 'change-me', 'change-this-before-production']);
        return (string)$env;
    }
    $localPath = __DIR__ . '/../config/App.local.php';
    if (file_exists($localPath)) {
        $config = require $localPath;
        if (is_array($config) && !empty($config['default_new_user_password'])) {
            appWarnUnsafeDefaults('DEFAULT_NEW_USER_PASSWORD', (string)$config['default_new_user_password'], ['bshsams341227', 'change-me', 'change-this-before-production']);
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
        appWarnUnsafeDefaults('FIRST_RUN_ADMIN_PASSWORD', (string)$env, ['bshsams341227', 'change-me', 'change-this-before-production']);
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
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $db->prepare("SELECT reference_code FROM users WHERE reference_code LIKE ?");
        $stmt->execute([$prefix . '%']);
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/-(\d+)$/', (string)$code, $m)) {
                $num = (int)$m[1];
                if ($num > $max) { $max = $num; }
            }
        }
        return $max + 1;
    }

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
    return isValidPersonName($value, true) && hasMinimumLetters($value, 1);
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
            'house_street' => "ALTER TABLE users ADD COLUMN house_street VARCHAR(120) NULL AFTER address",
            'barangay' => "ALTER TABLE users ADD COLUMN barangay VARCHAR(120) NULL AFTER house_street",
            'municipality' => "ALTER TABLE users ADD COLUMN municipality VARCHAR(120) NULL AFTER barangay",
            'province' => "ALTER TABLE users ADD COLUMN province VARCHAR(120) NULL AFTER municipality",
            'father_name' => "ALTER TABLE users ADD COLUMN father_name VARCHAR(100) NULL AFTER province",
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

function ensureReportNotesTables(PDO $db): void {
    static $ready = false;
    if ($ready) { return; }

    $tables = [
        'admin_report_notes' => [
            'owner' => 'admin_id',
            'types' => "'general','attendance','top_attendance','class_summary','at_risk','grades','enrollment','teachers','classes'",
            'index' => 'idx_admin_type_created',
        ],
        'teacher_report_notes' => [
            'owner' => 'teacher_id',
            'types' => "'general','top_attendance','class_summary','at_risk'",
            'index' => 'idx_teacher_type_created',
        ],
    ];

    foreach ($tables as $table => $definition) {
        $ownerColumn = $definition['owner'];
        $reportTypes = $definition['types'];
        $indexName = $definition['index'];
        $tableSql = "CREATE TABLE IF NOT EXISTS {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            {$ownerColumn} INT NOT NULL,
            report_type ENUM({$reportTypes}) DEFAULT 'general',
            title VARCHAR(200) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY ({$ownerColumn}) REFERENCES users(id) ON DELETE CASCADE,
            KEY {$indexName} ({$ownerColumn}, report_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $fallbackSql = "CREATE TABLE IF NOT EXISTS {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            {$ownerColumn} INT NOT NULL,
            report_type ENUM({$reportTypes}) DEFAULT 'general',
            title VARCHAR(200) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY {$indexName} ({$ownerColumn}, report_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            $db->exec($tableSql);
        } catch (Throwable $e) {
            error_log('Report notes table check failed for ' . $table . ': ' . $e->getMessage());
            try {
                $db->exec($fallbackSql);
            } catch (Throwable $fallbackError) {
                error_log('Report notes fallback table check failed for ' . $table . ': ' . $fallbackError->getMessage());
                throw $fallbackError;
            }
        }

        try {
            $alterSql = "ALTER TABLE {$table} MODIFY COLUMN report_type ENUM({$reportTypes}) DEFAULT 'general'";
            $db->exec($alterSql);
        } catch (Throwable $alterError) {
            // Non-critical if table already matches or user lacks ALTER privilege
        }
    }

    $ready = true;
}

function syncStudentEnrollments(PDO $db, int $studentId, int $gradeLevel, string $section, ?string $track = null, ?string $academicYear = null): void {
    ensureStrengthenedShsColumns($db);
    $academicYear = $academicYear ?: currentAcademicYear();
    $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
    $program = strengthenedShsProgram($gradeLevel, $track, $academicYear);
    $semester = $curriculum === 'strengthened_shs' ? null : 1;

    $sectionTrimmed = trim((string)$section);
    if ($sectionTrimmed === '' || $gradeLevel <= 0 || $studentId <= 0) {
        return;
    }

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sectionSql = "(LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, ''))))";
        $params = [$gradeLevel, $sectionTrimmed];
    } else {
        $sectionSql = sectionMatchSql('section');
        $params = [$gradeLevel, $sectionTrimmed, $sectionTrimmed];
    }

    $sql = "SELECT id, curriculum, program FROM classes
            WHERE grade_level = ?
              AND {$sectionSql}
              AND status = 'active'";

    if ($track !== null && trim($track) !== '') {
        $sql .= " AND (track IS NULL OR TRIM(track) = '' OR LOWER(TRIM(track)) = LOWER(TRIM(?)))";
        $params[] = trim($track);
    }

    $classStmt = $db->prepare($sql);
    $classStmt->execute($params);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($classes)) {
        return;
    }

    $checkStmt = $db->prepare("SELECT id, status FROM enrollments WHERE student_id = ? AND class_id = ? AND academic_year = ? LIMIT 1");
    $insertStmt = $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, curriculum, program, status, enrolled_at) VALUES (?, ?, ?, ?, ?, ?, 'enrolled', ?)");
    $reactivateStmt = $db->prepare("UPDATE enrollments SET status = 'enrolled' WHERE id = ?");
    $now = date('Y-m-d H:i:s');

    foreach ($classes as $class) {
        $classId = (int)$class['id'];
        $classCurriculum = !empty($class['curriculum']) ? $class['curriculum'] : $curriculum;
        $classProgram = !empty($class['program']) ? $class['program'] : $program;
        $classSemester = $classCurriculum === 'strengthened_shs' ? null : $semester;

        $checkStmt->execute([$studentId, $classId, $academicYear]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $insertStmt->execute([$studentId, $classId, $academicYear, $classSemester, $classCurriculum, $classProgram, $now]);
        } elseif (($existing['status'] ?? '') !== 'enrolled') {
            $reactivateStmt->execute([(int)$existing['id']]);
        }
    }
}

function syncClassEnrollmentsByGradeSection($db, $classId, $gradeLevel, $section, ?string $track = null) {
    $academicYear = currentAcademicYear();
    $curriculum = strengthenedShsCurriculum((int)$gradeLevel, $academicYear);
    $program = strengthenedShsProgram((int)$gradeLevel, $track, $academicYear);
    $semester = $curriculum === 'strengthened_shs' ? null : 1;

    $sectionTrimmed = trim((string)$section);
    if ($sectionTrimmed === '' || (int)$gradeLevel <= 0 || (int)$classId <= 0) {
        return;
    }

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sectionSql = "(LOWER(TRIM(COALESCE(u.section, ''))) = LOWER(TRIM(COALESCE(?, ''))))";
        $params = [
            (int)$classId, $academicYear, $semester, $curriculum, $program, date('Y-m-d H:i:s'),
            (int)$gradeLevel, $sectionTrimmed,
            $track, $track, $track,
            (int)$classId, $academicYear
        ];
    } else {
        $sectionSql = sectionMatchSql('u.section');
        $params = [
            (int)$classId, $academicYear, $semester, $curriculum, $program, date('Y-m-d H:i:s'),
            (int)$gradeLevel, $sectionTrimmed, $sectionTrimmed,
            $track, $track, $track,
            (int)$classId, $academicYear
        ];
    }

    // Enroll active/pending students whose profile matches class grade/section.
    $insert = $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, curriculum, program, status, enrolled_at)
                            SELECT u.id, ?, ?, ?, ?, ?, 'enrolled', ?
                            FROM users u
                            WHERE u.role = 'student'
                              AND u.status IN ('active', 'pending')
                              AND u.grade_level = ?
                              AND {$sectionSql}
                              AND (? IS NULL OR TRIM(?) = '' OR u.track IS NULL OR TRIM(u.track) = '' OR LOWER(TRIM(u.track)) = LOWER(TRIM(?)))
                              AND NOT EXISTS (
                                  SELECT 1
                                  FROM enrollments e
                                  WHERE e.student_id = u.id
                                    AND e.class_id = ?
                                    AND e.academic_year = ?
                                    AND COALESCE(e.status, 'enrolled') = 'enrolled'
                              )");
    $insert->execute($params);
}

function syncClassEnrollmentsForClass(PDO $db, int $classId): void {
    if ($classId <= 0) {
        return;
    }
    static $syncedInRequest = [];
    $connId = spl_object_id($db);
    if (isset($syncedInRequest[$connId][$classId])) {
        return;
    }

    $stmt = $db->prepare("SELECT grade_level, section, track FROM classes WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class || (int)($class['grade_level'] ?? 0) <= 0 || trim((string)($class['section'] ?? '')) === '') {
        return;
    }

    syncClassEnrollmentsByGradeSection(
        $db, $classId, (int)$class['grade_level'],
        trim((string)$class['section']), $class['track'] ?? null
    );

    $syncedInRequest[$connId][$classId] = true;
}

function autoLinkSf1Parents(PDO $db, int $studentId, array $row, string $academicYear, ?string $hashedPassword = null): array {
    $contactNumber = trim((string)($row['contact_number'] ?? ''));

    $candidates = [];
    $fatherName = trim((string)($row['father_name'] ?? ''));
    if ($fatherName !== '') {
        $candidates[] = [
            'name' => $fatherName,
            'relationship' => 'Father',
            'sex' => 'male',
            'contact' => $contactNumber,
        ];
    }

    $motherName = trim((string)($row['mother_name'] ?? ''));
    if ($motherName !== '') {
        $candidates[] = [
            'name' => $motherName,
            'relationship' => 'Mother',
            'sex' => 'female',
            'contact' => $contactNumber,
        ];
    }

    $guardianName = trim((string)($row['guardian_name'] ?? ''));
    if ($guardianName !== '') {
        $guardianRelationship = trim((string)($row['relationship'] ?? ''));
        $rel = $guardianRelationship !== '' ? $guardianRelationship : 'Guardian';
        $candidates[] = [
            'name' => $guardianName,
            'relationship' => $rel,
            'sex' => match (strtolower($rel)) {
                'father' => 'male',
                'mother' => 'female',
                default => null,
            },
            'contact' => $contactNumber,
        ];
    }

    if (empty($candidates)) {
        $parentName = trim((string)($row['parent_name'] ?? ''));
        if ($parentName !== '') {
            $candidates[] = [
                'name' => $parentName,
                'relationship' => 'Parent',
                'sex' => null,
                'contact' => $contactNumber,
            ];
        }
    }

    if (empty($candidates)) {
        return [];
    }

    $pwHash = $hashedPassword ?: password_hash(getDefaultNewUserPassword(), PASSWORD_BCRYPT);
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $linked = [];
    $seenNames = [];

    foreach ($candidates as $cand) {
        $parsed = \BshsAms\Export\Sf1Parser::parseLearnerName($cand['name']);
        $firstName = trim((string)($parsed['first_name'] ?? ''));
        $middleName = trim((string)($parsed['middle_name'] ?? ''));
        $lastName = trim((string)($parsed['last_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            continue;
        }

        $nameKey = strtolower($firstName . '|' . $lastName);
        if (isset($seenNames[$nameKey])) {
            continue;
        }
        $seenNames[$nameKey] = true;

        $relationship = $cand['relationship'];
        $parentSex = $cand['sex'];
        $candContact = $cand['contact'];

        $parentId = null;
        $isNewParent = false;
        $parentRefCode = '';

        $checkStmt = $db->prepare("SELECT id, reference_code, contact_number FROM users WHERE role = 'parent' AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1");
        $checkStmt->execute([$firstName, $lastName]);
        $existingParent = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingParent) {
            $parentId = (int)$existingParent['id'];
            $parentRefCode = (string)$existingParent['reference_code'];
            if ($candContact !== '' && empty($existingParent['contact_number'])) {
                $db->prepare("UPDATE users SET contact_number = ? WHERE id = ?")->execute([$candContact, $parentId]);
            }
        } else {
            $parentRefCode = generateReferenceCode('parent', $db, $academicYear);
            $parentEmail = strtolower($parentRefCode) . '@balingasag.edu.ph';

            $insertParent = $db->prepare("INSERT INTO users
                (reference_code, email, password, first_name, middle_name, last_name, sex, contact_number, role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'parent', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insertParent->execute([
                $parentRefCode,
                $parentEmail,
                $pwHash,
                $firstName,
                ($middleName !== '' ? $middleName : null),
                $lastName,
                $parentSex,
                ($candContact !== '' ? $candContact : null),
            ]);
            $parentId = (int)$db->lastInsertId();
            $isNewParent = true;
        }

        if ($driver === 'sqlite') {
            $linkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship)
                VALUES (?, ?, ?)
                ON CONFLICT(parent_id, student_id) DO UPDATE SET relationship = excluded.relationship");
        } else {
            $linkStmt = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE relationship = VALUES(relationship)");
        }
        $linkStmt->execute([$parentId, $studentId, $relationship]);

        $linked[] = [
            'parent_id' => $parentId,
            'parent_ref_code' => $parentRefCode,
            'is_new' => $isNewParent,
            'relationship' => $relationship,
        ];
    }

    return $linked;
}

function autoLinkSf1Parent(PDO $db, int $studentId, array $row, string $academicYear, ?string $hashedPassword = null): ?array {
    $all = autoLinkSf1Parents($db, $studentId, $row, $academicYear, $hashedPassword);
    return !empty($all) ? $all[0] : null;
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

function sectionMatchSql(string $leftColumn = 'section', ?string $rightColumn = null): string {
    foreach ([$leftColumn, $rightColumn] as $identifier) {
        if ($identifier !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', trim($identifier))) {
            throw new InvalidArgumentException('Invalid SQL identifier for sectionMatchSql.');
        }
    }
    $left = trim($leftColumn);
    if ($rightColumn === null) {
        return "(LOWER(TRIM(COALESCE({$left}, ''))) = LOWER(TRIM(COALESCE(?, '')))"
            . " OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE({$left}, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(?, ''), '(', 1))))";
    }
    $right = trim($rightColumn);
    return "(LOWER(TRIM(COALESCE({$left}, ''))) = LOWER(TRIM(COALESCE({$right}, '')))"
        . " OR LOWER(TRIM(SUBSTRING_INDEX(COALESCE({$left}, ''), '(', 1))) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE({$right}, ''), '(', 1))))";
}

function sf1NormalizeSectionName(string $name): string {
    $s = trim(strtolower($name));
    if ($s === '') {
        return '';
    }
    // Strip parenthetical descriptors: e.g. "Humility (Academic)", "Humility (STEM)" -> "Humility"
    $s = preg_replace('/\s*\([^)]*\)\s*$/', '', $s);
    // Strip grade level prefixes: e.g. "Grade 11 - Humility", "11 - Humility", "G11 Humility", "11-Humility" -> "Humility"
    $s = preg_replace('/^(?:grade\s*|g)?(?:11|12)\s*[-:]*\s*/i', '', $s);
    // Strip track/strand dash suffixes: e.g. "Humility - Academic", "Humility - STEM", "Humility - TVL" -> "Humility"
    $s = preg_replace('/\s*[-:]\s*(?:academic|techpro|stem|humss|abm|gas|tvl|ict|he|agri-fishery|industrial\s*arts)\s*$/i', '', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return $s !== '' ? $s : trim(strtolower($name));
}

function appEnsureUserNotificationsTable(PDO $db): void {
    static $readyConnections = [];
    $connectionId = spl_object_id($db);
    if (isset($readyConnections[$connectionId])) { return; }
    try {
        $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                source_key TEXT NOT NULL,
                title TEXT NOT NULL,
                subtitle TEXT DEFAULT '',
                icon TEXT DEFAULT 'bi-bell',
                color TEXT DEFAULT 'primary',
                link TEXT DEFAULT '',
                event_at DATETIME NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, source_key)
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_user_read_event ON user_notifications (user_id, is_read, event_at)");
            $readyConnections[$connectionId] = true;
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            source_key VARCHAR(255) NOT NULL,
            title VARCHAR(200) NOT NULL,
            subtitle VARCHAR(255) DEFAULT '',
            icon VARCHAR(50) DEFAULT 'bi-bell',
            color VARCHAR(20) DEFAULT 'primary',
            link VARCHAR(255) DEFAULT '',
            event_at DATETIME NOT NULL,
            is_read TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_source (user_id, source_key),
            KEY idx_user_read_event (user_id, is_read, event_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('User notifications table check failed: ' . $e->getMessage());
    }
    $readyConnections[$connectionId] = true;
}

function appNotifyUsers(PDO $db, array $userIds, string $sourceKey, string $title, string $subtitle, string $icon = 'bi-bell', string $color = 'primary', string $link = '', ?string $eventAt = null): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) { return $id > 0; })));
    if (empty($userIds) || trim($sourceKey) === '' || trim($title) === '') {
        return;
    }
    appEnsureUserNotificationsTable($db);
    $eventAt = $eventAt ?: date('Y-m-d H:i:s');
    try {
        $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $db->prepare("INSERT INTO user_notifications
                (user_id, source_key, title, subtitle, icon, color, link, event_at, is_read)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                ON CONFLICT(user_id, source_key) DO UPDATE SET
                title = excluded.title, subtitle = excluded.subtitle, icon = excluded.icon,
                color = excluded.color, link = excluded.link, event_at = excluded.event_at,
                is_read = 0, updated_at = DATETIME('now')");
        } else {
            $stmt = $db->prepare("INSERT INTO user_notifications
                (user_id, source_key, title, subtitle, icon, color, link, event_at, is_read)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE title = VALUES(title), subtitle = VALUES(subtitle),
                icon = VALUES(icon), color = VALUES(color), link = VALUES(link),
                event_at = VALUES(event_at), is_read = 0, updated_at = NOW()");
        }
        foreach ($userIds as $userId) {
            $stmt->execute([
                $userId,
                substr($sourceKey, 0, 255),
                substr($title, 0, 200),
                substr($subtitle, 0, 255),
                substr($icon, 0, 50),
                substr($color, 0, 20),
                substr($link, 0, 255),
                $eventAt
            ]);
        }
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentUserId > 0 && in_array($currentUserId, $userIds, true)) {
            unset($_SESSION['app_header_notifications']);
        }
    } catch (Throwable $e) {
        error_log('User notification insert failed: ' . $e->getMessage());
    }
}

function appNotificationTargetUrl(string $role, string $link = '', string $sourceKey = ''): string {
    $role = strtolower(trim($role));
    $roleLandingPages = [
        'admin' => '/admin/admin.php',
        'teacher' => '/teacher/teacher.php',
        'student' => '/student/Student.php',
        'parent' => '/parent/Parent.php',
    ];
    $announcementPages = [
        'admin' => 'admin/admin_Announcements.php',
        'teacher' => 'teacher/teacher_Announcements.php',
        'student' => 'student/Student_Announcements.php',
        'parent' => 'parent/Parent_Announcements.php',
    ];
    if (preg_match('/^school_announcement_(\d+)$/', $sourceKey, $matches) && isset($announcementPages[$role])) {
        return '/' . $announcementPages[$role] . '#notification-school-' . $matches[1];
    }
    if (preg_match('/^class_announcement_(\d+)$/', $sourceKey, $matches)) {
        if (in_array($role, ['student', 'parent'], true)) {
            return '/' . $announcementPages[$role] . '#notification-class-' . $matches[1];
        }
        if ($role === 'teacher') {
            return '/teacher/teacher_Announcements.php#notification-class-' . $matches[1];
        }
    }

    $link = trim($link);
    if ($link === '' || preg_match('#^https?://#i', $link)) {
        return $roleLandingPages[$role] ?? '/auth/login.php';
    }
    return str_starts_with($link, '/') ? $link : '/' . $role . '/' . ltrim($link, '/');
}

function appDispatchNotification(
    PDO $db,
    array $userIds,
    string $sourceKey,
    string $title,
    string $subtitle,
    string $icon = 'bi-bell',
    string $color = 'primary',
    array $linksByRole = [],
    array $data = [],
    ?string $eventAt = null
): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) {
        return $id > 0;
    })));
    if (empty($userIds)) {
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT id, role FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        $idsByRole = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = strtolower(trim((string)($row['role'] ?? '')));
            if ($role !== '') {
                $idsByRole[$role][] = (int)$row['id'];
            }
        }

        foreach ($idsByRole as $role => $roleUserIds) {
            $link = trim((string)($linksByRole[$role] ?? $linksByRole['default'] ?? ''));
            $targetUrl = appNotificationTargetUrl($role, $link, $sourceKey);
            appNotifyUsers($db, $roleUserIds, $sourceKey, $title, $subtitle, $icon, $color, $targetUrl, $eventAt);
            $payload = [
                'title' => $title,
                'body' => $subtitle,
                'icon' => '/assets/images/icon-192.png',
                'badge' => '/assets/images/icon-192.png',
                'url' => $targetUrl,
                'data' => array_merge($data, [
                    'source_key' => $sourceKey,
                    'url' => $targetUrl,
                    'link' => $targetUrl,
                ]),
            ];

            try {
                if (function_exists('pushSendToUserIds')) {
                    pushSendToUserIds($db, $roleUserIds, $payload);
                }
                if (function_exists('pushNotifyUsers')) {
                    pushNotifyUsers($db, $roleUserIds, $title, $subtitle, $payload['data']);
                }
            } catch (Throwable $e) {
                error_log('[notification] Push delivery failed for source ' . $sourceKey . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('[notification] Dispatch failed for source ' . $sourceKey . ': ' . $e->getMessage());
    }
}

function appClassStudentParentRecipients(PDO $db, int $classId): array {
    if ($classId <= 0) { return ['students' => [], 'parents_by_student' => []]; }
    $students = [];
    try {
        $stmt = $db->prepare("SELECT DISTINCT e.student_id
                              FROM enrollments e
                              JOIN users u ON u.id = e.student_id
                              WHERE e.class_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled'
                              AND u.role = 'student' AND u.status IN ('active', 'pending')");
        $stmt->execute([$classId]);
        $students = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('Class student notification lookup failed: ' . $e->getMessage());
    }

    $parentsByStudent = [];
    if (!empty($students)) {
        try {
            $placeholders = implode(',', array_fill(0, count($students), '?'));
            $stmt = $db->prepare("SELECT ps.student_id, p.id AS parent_id
                                  FROM parent_students ps
                                  JOIN users p ON p.id = ps.parent_id
                                  WHERE ps.student_id IN ($placeholders)
                                  AND p.role = 'parent' AND p.status IN ('active', 'pending')");
            $stmt->execute($students);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sid = (int)$row['student_id'];
                $pid = (int)$row['parent_id'];
                if ($sid > 0 && $pid > 0) {
                    $parentsByStudent[$sid][] = $pid;
                }
            }
        } catch (Throwable $e) {
            error_log('Class parent notification lookup failed: ' . $e->getMessage());
        }
    }

    return ['students' => $students, 'parents_by_student' => $parentsByStudent];
}

function appClassSubtitle(PDO $db, int $classId): string {
    try {
        $stmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ? LIMIT 1");
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $label = trim((string)($row['class_name'] ?? 'Class'));
        if (($row['grade_level'] ?? '') !== '' && trim((string)($row['section'] ?? '')) !== '') {
            $label .= ' (G' . (int)$row['grade_level'] . ' - ' . trim((string)$row['section']) . ')';
        }
        return $label !== '' ? $label : 'Class';
    } catch (Throwable $e) {
        return 'Class';
    }
}

function appNotifyGradeActivityCreated(PDO $db, int $classId, int $gradeItemId, string $title, string $component, $totalScore): void {
    $recipients = appClassStudentParentRecipients($db, $classId);
    $studentIds = $recipients['students'];
    if (empty($studentIds)) { return; }
    $classLabel = appClassSubtitle($db, $classId);
    $subtitle = $classLabel . ' | ' . strtoupper($component) . ' | Total: ' . (string)$totalScore;
    foreach ($studentIds as $studentId) {
        appDispatchNotification(
            $db,
            [(int)$studentId],
            'grade_item_created_' . $gradeItemId . '_' . (int)$studentId,
            'New grade activity: ' . $title,
            $subtitle,
            'bi-clipboard-check',
            'primary',
            ['student' => 'Student_Classes.php'],
            ['type' => 'grade_activity', 'class_id' => $classId, 'grade_item_id' => $gradeItemId]
        );
        appDispatchNotification(
            $db,
            $recipients['parents_by_student'][(int)$studentId] ?? [],
            'grade_item_created_' . $gradeItemId . '_' . (int)$studentId,
            'New grade activity: ' . $title,
            $subtitle,
            'bi-clipboard-check',
            'primary',
            ['parent' => 'Parent_Progress.php'],
            ['type' => 'grade_activity', 'class_id' => $classId, 'grade_item_id' => $gradeItemId]
        );
    }
}

function appNotifyAttendanceRecords(PDO $db, int $classId, string $date, array $records, int $recordedBy = 0): void {
    if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return; }
    $recipients = appClassStudentParentRecipients($db, $classId);
    $classLabel = appClassSubtitle($db, $classId);
    $studentIds = array_values(array_unique(array_filter(array_map(function ($record) {
        return (int)($record['student_id'] ?? 0);
    }, $records))));
    if (empty($studentIds)) { return; }

    $names = [];
    try {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
        $stmt->execute($studentIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int)$row['id']] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        }
    } catch (Throwable $e) {}

    foreach ($records as $record) {
        $studentId = (int)($record['student_id'] ?? 0);
        $status = strtolower(trim((string)($record['status'] ?? '')));
        if ($studentId <= 0 || !in_array($status, ['present', 'late', 'absent'], true)) { continue; }
        $studentName = $names[$studentId] ?? 'Student';
        $subtitle = $studentName . ' marked ' . ucfirst($status) . ' on ' . $date . ' in ' . $classLabel;
        appDispatchNotification(
            $db,
            [$studentId],
            'attendance_' . $classId . '_' . $date . '_' . $studentId,
            'Attendance recorded',
            $subtitle,
            'bi-calendar-check',
            'info',
            ['student' => 'Student_Attendance.php'],
            ['type' => 'attendance', 'class_id' => $classId, 'student_id' => $studentId, 'date' => $date, 'status' => $status]
        );
        appDispatchNotification(
            $db,
            $recipients['parents_by_student'][$studentId] ?? [],
            'attendance_' . $classId . '_' . $date . '_' . $studentId,
            'Attendance recorded',
            $subtitle,
            'bi-calendar-check',
            'info',
            ['parent' => 'Parent_Progress.php'],
            ['type' => 'attendance', 'class_id' => $classId, 'student_id' => $studentId, 'date' => $date, 'status' => $status]
        );
    }
}


function recordAdminAuditLog(PDO $db, string $actionName, string $targetType, ?int $targetId = null, array $details = [], ?int $adminUserId = null): void {
    $adminUserId = $adminUserId ?? (int)($_SESSION['user_id'] ?? 0);
    if ($adminUserId <= 0) { return; }
    try {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $timeSql = $driver === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
        $stmt = $db->prepare("INSERT INTO admin_audit_logs (admin_user_id, action_name, target_type, target_id, details_json, created_at) VALUES (?, ?, ?, ?, ?, {$timeSql})");
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


function getSchoolSetting(PDO $db, string $key, string $default = ''): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM school_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Throwable $e) { return $default; }
}

function getSchoolSettings(PDO $db): array {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM school_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $settings[$row['setting_key']] = $row['setting_value']; }
        return $settings;
    } catch (Throwable $e) { return []; }
}

function setSchoolSetting(PDO $db, string $key, string $value): bool {
    try {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $db->prepare("INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
            return $stmt->execute([$key, $value]);
        }
        $stmt = $db->prepare("INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $stmt->execute([$key, $value]);
    } catch (Throwable $e) { error_log('School setting update failed: ' . $e->getMessage()); return false; }
}

// =============================================================================
// RBAC (Role-Based Access Control) Helpers
// =============================================================================

function ensureRbacTables(PDO $db): void {
    static $ready = false;
    if ($ready) { return; }
    $migrations = [
        "CREATE TABLE IF NOT EXISTS rbac_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_key VARCHAR(30) UNIQUE NOT NULL,
            label VARCHAR(50) NOT NULL,
            description TEXT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS rbac_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(50) UNIQUE NOT NULL,
            label VARCHAR(100) NOT NULL,
            description TEXT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'general',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS rbac_role_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_role_permission (role_id, permission_id),
            CONSTRAINT fk_rbac_rp_role FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rbac_rp_perm FOREIGN KEY (permission_id) REFERENCES rbac_permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($migrations as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { error_log('RBAC table creation failed: ' . $e->getMessage()); }
    }
    $ready = true;
}

function ensureRbacRolesSeeded(PDO $db): void {
    static $done = false;
    if ($done) { return; }
    ensureRbacTables($db);
    try {
        $db->query("SELECT COUNT(*) FROM rbac_roles")->fetchColumn();
    } catch (Throwable $e) { return; }

    $roles = [
        ['admin', 'Administrator', 'Full system access.', 1],
        ['teacher', 'Teacher', 'Can manage attendance, grades, and view assigned classes.', 1],
        ['student', 'Student', 'Can view attendance, grades, and class schedules.', 1],
        ['parent', 'Parent', 'Can view child progress and report cards.', 1],
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO rbac_roles (role_key, label, description, is_system) VALUES (?, ?, ?, ?)");
    foreach ($roles as $r) { $stmt->execute($r); }

    $permissions = [
        ['attendance.view', 'View Attendance', 'attendance'],
        ['attendance.manage', 'Manage Attendance', 'attendance'],
        ['attendance.reports', 'Attendance Reports', 'attendance'],
        ['grades.view', 'View Grades', 'grades'],
        ['grades.enter', 'Enter Grades', 'grades'],
        ['grades.approve', 'Approve Grades', 'grades'],
        ['grades.reports', 'Grade Reports', 'grades'],
        ['classes.view', 'View Classes', 'classes'],
        ['classes.manage', 'Manage Classes', 'classes'],
        ['classes.assign', 'Assign Teachers', 'classes'],
        ['users.view', 'View Users', 'users'],
        ['users.create', 'Create Users', 'users'],
        ['users.edit', 'Edit Users', 'users'],
        ['users.delete', 'Delete Users', 'users'],
        ['users.reset_password', 'Reset Passwords', 'users'],
        ['announcements.view', 'View Announcements', 'announcements'],
        ['announcements.create', 'Create Announcements', 'announcements'],
        ['announcements.delete', 'Delete Announcements', 'announcements'],
        ['reports.view', 'View Reports', 'reports'],
        ['reports.export', 'Export Reports', 'reports'],
        ['settings.view', 'View Settings', 'settings'],
        ['settings.manage', 'Manage Settings', 'settings'],
        ['messages.view', 'View Messages', 'messages'],
        ['messages.send', 'Send Messages', 'messages'],
        ['archives.view', 'View Archives', 'archives'],
        ['archives.manage', 'Manage Archives', 'archives'],
    ];
    $permStmt = $db->prepare("INSERT IGNORE INTO rbac_permissions (permission_key, label, category) VALUES (?, ?, ?)");
    foreach ($permissions as $p) { $permStmt->execute($p); }

    $teacherPerms = [
        'attendance.view', 'attendance.manage', 'attendance.reports',
        'grades.view', 'grades.enter',
        'classes.view',
        'users.view',
        'announcements.view',
        'reports.view', 'reports.export',
        'messages.view', 'messages.send',
        'archives.view',
    ];
    $studentPerms = ['attendance.view', 'grades.view', 'classes.view', 'announcements.view'];
    $parentPerms = [
        'attendance.view', 'grades.view', 'reports.view', 'announcements.view',
        'messages.view', 'messages.send',
    ];

    $roleMap = [
        'admin' => null,
        'teacher' => $teacherPerms,
        'student' => $studentPerms,
        'parent' => $parentPerms,
    ];

    foreach ($roleMap as $roleKey => $perms) {
        $roleId = (int)$db->query("SELECT id FROM rbac_roles WHERE role_key = " . $db->quote($roleKey, PDO::PARAM_STR))->fetchColumn();
        if ($roleId <= 0) { continue; }
        if ($roleKey === 'admin') {
            $db->exec("INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id, enabled) SELECT $roleId, id, 1 FROM rbac_permissions");
        } else {
            foreach ($perms as $permKey) {
                $permId = (int)$db->query("SELECT id FROM rbac_permissions WHERE permission_key = " . $db->quote($permKey, PDO::PARAM_STR))->fetchColumn();
                if ($permId > 0) {
                    $db->exec("INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id, enabled) VALUES ($roleId, $permId, 1)");
                }
            }
        }
    }
    $done = true;
}

function loadRbacPermissions(PDO $db, string $roleKey): array {
    ensureRbacRolesSeeded($db);
    $stmt = $db->prepare("SELECT p.permission_key
                          FROM rbac_role_permissions rp
                          JOIN rbac_roles r ON r.id = rp.role_id
                          JOIN rbac_permissions p ON p.id = rp.permission_id
                          WHERE r.role_key = ? AND rp.enabled = 1");
    $stmt->execute([$roleKey]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function isRbacPermissionsCacheValid(): bool {
    if (empty($_SESSION['logged_in']) || !isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return false;
    }
    $cachedUserId = (int)($_SESSION['rbac_permissions_user_id'] ?? 0);
    $currentUserId = (int)$_SESSION['user_id'];
    if ($cachedUserId !== $currentUserId || $currentUserId <= 0) {
        return false;
    }
    $cachedRole = (string)($_SESSION['rbac_permissions_role'] ?? '');
    $currentRole = (string)$_SESSION['role'];
    if ($cachedRole !== $currentRole || $currentRole === '') {
        return false;
    }
    $loadedAt = (int)($_SESSION['rbac_permissions_loaded_at'] ?? 0);
    $ttl = max(0, (int)appEnvValue('APP_RBAC_CACHE_TTL', '300'));
    if ($ttl <= 0 || (time() - $loadedAt) > $ttl) {
        return false;
    }
    $perms = $_SESSION['rbac_permissions'] ?? null;
    return is_array($perms);
}

function cachedRbacPermissions(PDO $db, string $roleKey): array {
    $ttl = max(0, (int)appEnvValue('APP_RBAC_CACHE_TTL', '300'));
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $cachedUserId = (int)($_SESSION['rbac_permissions_user_id'] ?? 0);
    $cachedRole = (string)($_SESSION['rbac_permissions_role'] ?? '');
    $loadedAt = (int)($_SESSION['rbac_permissions_loaded_at'] ?? 0);
    $cachedPermissions = $_SESSION['rbac_permissions'] ?? null;

    if (
        is_array($cachedPermissions)
        && $cachedRole === $roleKey
        && $cachedUserId === $currentUserId
        && $currentUserId > 0
        && $ttl > 0
        && (time() - $loadedAt) <= $ttl
    ) {
        return $cachedPermissions;
    }

    $permissions = loadRbacPermissions($db, $roleKey);
    $_SESSION['rbac_permissions'] = $permissions;
    $_SESSION['rbac_permissions_user_id'] = $currentUserId;
    $_SESSION['rbac_permissions_role'] = $roleKey;
    $_SESSION['rbac_permissions_loaded_at'] = time();

    return $permissions;
}

function hasPermission(string $permissionKey): bool {
    if (!isset($_SESSION['role'])) { return false; }
    $roleKey = (string)$_SESSION['role'];
    if (!isRbacPermissionsCacheValid()) {
        $dbInstance = new Database();
        $db = $dbInstance->getConnection();
        if (!$db) { return false; }
        cachedRbacPermissions($db, $roleKey);
    }
    $perms = $_SESSION['rbac_permissions'] ?? [];
    return is_array($perms) && in_array($permissionKey, $perms, true);
}

function requirePermission(string $permissionKey): void {
    if (!hasPermission($permissionKey)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions.']);
        exit();
    }
}

function requirePagePermission(string $permissionKey): void {
    if (hasPermission($permissionKey)) { return; }
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access denied</title></head><body style="font-family:Arial,sans-serif;padding:2rem;"><h1>Access denied</h1><p>You do not have permission to access this page.</p></body></html>';
    exit();
}

function permissionForScript(string $scriptName): string {
    $scriptName = strtolower($scriptName);
    $map = [
        'admin.php' => 'users.view',
        'admin_users.php' => 'users.view',
        'admin_users_action.php' => 'users.view',
        'admin_enrollments.php' => 'users.view',
        'admin_enrollments_action.php' => 'users.view',
        'admin_sf1_import.php' => 'users.create',
        'admin_sf1_import_action.php' => 'users.create',
        'admin_classes.php' => 'classes.view',
        'admin_class_detail.php' => 'classes.view',
        'admin_class_edit.php' => 'classes.manage',
        'admin_classes_action.php' => 'classes.manage',
        'admin_sections.php' => 'classes.view',
        'admin_section_detail.php' => 'classes.view',
        'admin_sections_action.php' => 'classes.manage',
        'admin_attendance.php' => 'attendance.view',
        'admin_announcements.php' => 'announcements.view',
        'admin_announcements_action.php' => 'announcements.create',
        'admin_grade_approvals.php' => 'grades.approve',
        'admin_grade_approvals_detail.php' => 'grades.approve',
        'admin_grade_approvals_action.php' => 'grades.approve',
        'admin_reports.php' => 'reports.view',
        'admin_reports_action.php' => 'reports.view',
        'admin_archives.php' => 'archives.view',
        'admin_audit_logs.php' => 'settings.view',
        'admin_school_settings.php' => 'settings.view',
        'admin_school_settings_action.php' => 'settings.manage',
        'admin_rbac.php' => 'settings.manage',
        'admin_rbac_action.php' => 'settings.manage',
        'teacher.php' => 'attendance.view',
        'teacher_attendance.php' => 'attendance.view',
        'teacher_classes.php' => 'classes.view',
        'teacher_grades.php' => 'grades.view',
        'teacher_reports.php' => 'reports.view',
        'teacher_reports_action.php' => 'reports.view',
        'teacher_advisory.php' => 'grades.view',
        'teacher_announcements.php' => 'announcements.view',
        'teacher_archives.php' => 'archives.view',
        'teacher_chat.php' => 'messages.view',
        'teacher_chat_action.php' => 'messages.send',
        'teacher_sf2_export.php' => 'reports.export',
        'teacher_sf5_export.php' => 'reports.export',
        'teacher_sf9_export.php' => 'reports.export',
        'student_qr.php' => 'attendance.view',
        'teacher_action.php' => 'classes.view',
        'student.php' => 'classes.view',
        'student_attendance.php' => 'attendance.view',
        'student_classes.php' => 'classes.view',
        'student_report_card.php' => 'grades.view',
        'student_announcements.php' => 'announcements.view',
        'student_action.php' => 'classes.view',
        'parent.php' => 'reports.view',
        'parent_progress.php' => 'grades.view',
        'parent_report_card.php' => 'grades.view',
        'parent_announcements.php' => 'announcements.view',
        'parent_chat.php' => 'messages.view',
        'parent_chat_action.php' => 'messages.send',
    ];
    return $map[$scriptName] ?? '';
}

function enforceScriptPermission(?PDO $db = null): void {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['role'])) { return; }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $permission = permissionForScript($script);
    if ($permission === '') { return; }

    if (!isRbacPermissionsCacheValid()) {
        if ($db === null) {
            $db = (new Database())->getConnection();
        }
        if ($db instanceof PDO) {
            cachedRbacPermissions($db, (string)$_SESSION['role']);
        }
    }

    $isJson = str_ends_with(strtolower($script), '_action.php') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if (hasPermission($permission)) { return; }
    http_response_code(403);
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions.']);
    } else {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access denied</title></head><body style="font-family:Arial,sans-serif;padding:2rem;"><h1>Access denied</h1><p>You do not have permission to access this page.</p></body></html>';
    }
    exit();
}

function loadAllRbacRoles(PDO $db): array {
    ensureRbacRolesSeeded($db);
    $stmt = $db->query("SELECT id, role_key, label, description, is_system FROM rbac_roles ORDER BY role_key");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function loadAllRbacPermissions(PDO $db): array {
    ensureRbacRolesSeeded($db);
    $stmt = $db->query("SELECT id, permission_key, label, description, category FROM rbac_permissions ORDER BY category, permission_key");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function loadRolePermissionMap(PDO $db): array {
    ensureRbacRolesSeeded($db);
    $stmt = $db->query("SELECT r.role_key, p.permission_key, rp.enabled
                         FROM rbac_role_permissions rp
                         JOIN rbac_roles r ON r.id = rp.role_id
                         JOIN rbac_permissions p ON p.id = rp.permission_id
                         ORDER BY r.role_key, p.category, p.permission_key");
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[$row['role_key']][$row['permission_key']] = (int)$row['enabled'];
    }
    return $map;
}

function syncRbacPermissions(PDO $db, int $roleId, array $enabledPermissions): void {
    $db->exec("UPDATE rbac_role_permissions SET enabled = 0 WHERE role_id = " . (int)$roleId);
    $permStmt = $db->prepare("UPDATE rbac_role_permissions SET enabled = 1 WHERE role_id = ? AND permission_id = (SELECT id FROM (SELECT id FROM rbac_permissions WHERE permission_key = ?) AS tmp)");
    foreach ($enabledPermissions as $permKey) {
        $permStmt->execute([$roleId, $permKey]);
    }
}

function refreshSessionPermissions(): void {
    if (!isset($_SESSION['role'])) { return; }
    $dbInstance = new Database();
    $db = $dbInstance->getConnection();
    if ($db) {
        $_SESSION['rbac_permissions'] = loadRbacPermissions($db, $_SESSION['role']);
    }
}
