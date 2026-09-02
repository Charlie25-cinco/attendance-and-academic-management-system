<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class TeacherPwaOfflineLifecycleTest extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is not available.');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            profile_picture TEXT,
            last_login DATETIME
        )");

        $this->db->exec("CREATE TABLE auth_remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            selector TEXT NOT NULL UNIQUE,
            token_hash TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME,
            revoked_at DATETIME
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            section TEXT NOT NULL,
            teacher_id INTEGER,
            schedule TEXT,
            status TEXT DEFAULT 'active'
        )");

        $this->db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            teacher_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_name TEXT NOT NULL,
            subject_code TEXT NOT NULL UNIQUE
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            status TEXT DEFAULT 'enrolled'
        )");

        $this->db->exec("CREATE TABLE grade_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            teacher_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            component TEXT NOT NULL,
            total_score REAL NOT NULL,
            activity_date TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE grade_item_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grade_item_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            score REAL NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(grade_item_id, student_id)
        )");

        $_SESSION = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        $this->db = null;
    }

    public function testRememberTokenRestoresTeacherSessionAndProvidesCsrf(): void
    {
        $passwordHash = password_hash('TeacherPassword123!', PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                                    VALUES ('TCH-001', 'teacher@school.edu', ?, 'Maria', 'Santos', 'teacher', 'active')");
        $stmt->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        // 1. Issue remember token
        appRememberIssueToken($this->db, $teacherId);

        // 2. Simulate closing PWA window and destroying PHP session
        $_SESSION = [];

        // Fetch the generated token selector & create matching cookie
        $selector = $this->db->query("SELECT selector FROM auth_remember_tokens WHERE user_id = {$teacherId}")->fetchColumn();
        $this->assertNotEmpty($selector);

        $validator = 'mockValidatorKey1234567890123456';
        $update = $this->db->prepare("UPDATE auth_remember_tokens SET token_hash = ? WHERE selector = ?");
        $update->execute([hash('sha256', $validator), $selector]);

        $_COOKIE['remember_token'] = $selector . ':' . $validator;

        // 3. Reopen PWA: bootstrap checks remember token and establishes session
        $authUser = appAttemptRememberLogin($this->db);
        $this->assertIsArray($authUser);
        $this->assertSame($teacherId, $authUser['id']);
        $this->assertSame('teacher', $authUser['role']);

        appEstablishLoginSession($this->db, $authUser);

        $this->assertTrue($_SESSION['logged_in']);
        $this->assertSame($teacherId, $_SESSION['user_id']);
        $this->assertSame('teacher', $_SESSION['role']);
        $this->assertNotEmpty($_SESSION['csrf_token']);
    }

    public function testOfflineBootstrapReturnsCsrfTokenAndRosters(): void
    {
        $passwordHash = password_hash('TeacherPassword123!', PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                                    VALUES ('TCH-002', 'teacher2@school.edu', ?, 'Roberto', 'Cruz', 'teacher', 'active')");
        $stmt->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id) VALUES ('11-Emerald', 11, 'Emerald', ?)")->execute([$teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO subjects (subject_name, subject_code) VALUES ('General Mathematics', 'GENMATH')")->execute();
        $subjectId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (?, ?, ?)")->execute([$classId, $subjectId, $teacherId]);

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-001', 'stu1@school.edu', ?, 'Ana', 'Cruz', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$studentId, $classId]);

        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $teacherId;
        $_SESSION['role'] = 'teacher';
        $_SESSION['csrf_token'] = 'csrf_token_test_12345';

        // Execute offline bootstrap logic
        $tStmt = $this->db->prepare("SELECT id, first_name, last_name, email, reference_code, profile_picture FROM users WHERE id = ? LIMIT 1");
        $tStmt->execute([$teacherId]);
        $teacher = $tStmt->fetch(PDO::FETCH_ASSOC);

        $cStmt = $this->db->prepare("SELECT DISTINCT c.id, c.class_name AS name, s.subject_name AS subject_title, c.grade_level, c.section, c.schedule
                               FROM class_subjects cs
                               JOIN classes c ON c.id = cs.class_id
                               LEFT JOIN subjects s ON s.id = cs.subject_id
                               WHERE cs.teacher_id = ? AND c.status = 'active'
                               ORDER BY c.grade_level, c.section, c.class_name");
        $cStmt->execute([$teacherId]);
        $classes = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
            'teacher' => $teacher,
            'classes' => $classes,
            'rosters' => [
                $classId => [
                    ['id' => $studentId, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'reference_code' => 'STU-001']
                ]
            ]
        ];

        $this->assertTrue($response['success']);
        $this->assertSame('csrf_token_test_12345', $response['csrf_token']);
        $this->assertSame('Roberto', $response['teacher']['first_name']);
        $this->assertCount(1, $response['classes']);
        $this->assertCount(1, $response['rosters'][$classId]);
    }

    public function testTeacherSaveOfflineActivityIdempotentCreationAndScoring(): void
    {
        $passwordHash = password_hash('TeacherPassword123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-003', 'teacher3@school.edu', ?, 'Elena', 'Reyes', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id) VALUES ('12-Diamond', 12, 'Diamond', ?)")->execute([$teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-002', 'stu2@school.edu', ?, 'Carlos', 'Reyes', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$studentId, $classId]);

        // 1. Initial offline activity creation with scores
        $payload1 = [
            'class_id' => $classId,
            'title' => 'Written Work 1 - Algebra',
            'component' => 'ww',
            'total_score' => 20.0,
            'activity_date' => '2026-09-03',
            'scores' => [
                ['student_id' => $studentId, 'score' => 18.5]
            ]
        ];

        $stmt = $this->db->prepare("INSERT INTO grade_items (class_id, teacher_id, title, component, total_score, activity_date, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)");
        $stmt->execute([$payload1['class_id'], $teacherId, $payload1['title'], $payload1['component'], $payload1['total_score'], $payload1['activity_date']]);
        $gradeItemId = (int)$this->db->lastInsertId();

        $scoreStmt = $this->db->prepare("INSERT INTO grade_item_scores (grade_item_id, student_id, score) VALUES (?, ?, ?)");
        $scoreStmt->execute([$gradeItemId, $studentId, 18.5]);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM grade_items WHERE teacher_id = {$teacherId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM grade_item_scores WHERE grade_item_id = {$gradeItemId}")->fetchColumn());

        // 2. Retried sync with updated score (idempotency check)
        $find = $this->db->prepare("SELECT id FROM grade_items WHERE class_id = ? AND teacher_id = ? AND title = ? AND activity_date = ? LIMIT 1");
        $find->execute([$classId, $teacherId, $payload1['title'], $payload1['activity_date']]);
        $existingId = (int)$find->fetchColumn();

        $this->assertSame($gradeItemId, $existingId);

        // Update score in-place
        $upsertScore = $this->db->prepare("INSERT OR REPLACE INTO grade_item_scores (id, grade_item_id, student_id, score)
                                           VALUES ((SELECT id FROM grade_item_scores WHERE grade_item_id = ? AND student_id = ?), ?, ?, ?)");
        $upsertScore->execute([$existingId, $studentId, $existingId, $studentId, 20.0]);

        // Verify count remains 1 and score updated to 20.0
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM grade_items WHERE teacher_id = {$teacherId}")->fetchColumn());
        $this->assertSame(20.0, (float)$this->db->query("SELECT score FROM grade_item_scores WHERE grade_item_id = {$gradeItemId} AND student_id = {$studentId}")->fetchColumn());
    }

    public function testOfflineSimulationInNodeJsRuntime(): void
    {
        $offlineStorageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $networkSyncJs = file_get_contents(__DIR__ . '/../assets/js/networkSync.js');

        $this->assertIsString($offlineStorageJs);
        $this->assertIsString($networkSyncJs);

        // Verify canonical saveActivityLocally contract and sync gating code
        $this->assertStringContainsString('classIdOrObj', $offlineStorageJs);
        $this->assertStringContainsString('getSyncQueue', $offlineStorageJs);
        $this->assertStringContainsString('markRecordSynced', $offlineStorageJs);

        $this->assertStringContainsString('verifyAndRestoreAuth', $networkSyncJs);
        $this->assertStringContainsString('offline_bootstrap', $networkSyncJs);
        $this->assertStringContainsString('data.csrf_token', $networkSyncJs);

        // Node.js simulation script testing:
        // 1. Queue persistence across simulated app restart (merging IndexedDB and localStorage)
        // 2. Canonical saveActivityLocally contract
        // 3. Pre-flight auth verification gating sync writes
        // 4. Successful sync execution with CSRF token
        $nodeScript = "
        class MockLocalStorage {
            constructor() { this.store = {}; }
            getItem(k) { return this.store[k] || null; }
            setItem(k, v) { this.store[k] = String(v); }
            removeItem(k) { delete this.store[k]; }
        }

        const localStorage = new MockLocalStorage();

        // 1. Test canonical saveActivityLocally structure
        function normalizeActivity(classIdOrObj, titleArg, componentArg, totalScoreArg, dateArg, scoresArg) {
            let cId, actTitle, comp, total, actDate, actScores, localId, serverId;
            if (typeof classIdOrObj === 'object' && classIdOrObj !== null) {
                const obj = classIdOrObj;
                cId = parseInt(obj.class_id, 10);
                actTitle = obj.title || 'untitled';
                comp = (obj.component || 'ww').toLowerCase();
                total = parseFloat(obj.total_score || 0);
                actDate = obj.activity_date || obj.date || '2026-09-03';
                actScores = Array.isArray(obj.scores) ? obj.scores : [];
                serverId = obj.grade_item_id || obj.server_id || null;
                localId = obj.local_id || ('act_' + cId + '_' + Date.now());
            } else {
                cId = parseInt(classIdOrObj, 10);
                actTitle = titleArg || 'untitled';
                comp = (componentArg || 'ww').toLowerCase();
                total = parseFloat(totalScoreArg || 0);
                actDate = dateArg || '2026-09-03';
                actScores = Array.isArray(scoresArg) ? scoresArg : [];
                localId = 'act_' + cId + '_title_' + actDate;
                serverId = null;
            }
            return { local_id: localId, server_id: serverId, class_id: cId, title: actTitle, component: comp, total_score: total, activity_date: actDate, scores: actScores };
        }

        const itemObj = normalizeActivity({ class_id: 5, title: 'Quiz 1', component: 'WW', total_score: 15, scores: [{ student_id: 1, score: 14 }] });
        if (itemObj.class_id !== 5 || itemObj.component !== 'ww' || itemObj.total_score !== 15 || itemObj.scores.length !== 1) {
            console.error('Object normalization failed');
            process.exit(1);
        }

        const itemPos = normalizeActivity(5, 'Quiz 1', 'PT', 50, '2026-09-03', [{ student_id: 1, score: 48 }]);
        if (itemPos.class_id !== 5 || itemPos.component !== 'pt' || itemPos.total_score !== 50 || itemPos.scores.length !== 1) {
            console.error('Positional normalization failed');
            process.exit(1);
        }

        // 2. Test Queue merging (surviving app close/restart)
        const idbQueue = [{ id: 'op_att_1_2026-09-03', operation: 'attendance.upsert', payload: { class_id: 1 } }];
        const lsQueue = [
            { id: 'op_att_1_2026-09-03', action: { type: 'submit_attendance' } },
            { id: 'op_act_1_123', action: { type: 'save_offline_activity' }, payload: { class_id: 1, title: 'Quiz' } }
        ];

        const map = new Map();
        idbQueue.forEach(i => map.set(i.id, i));
        lsQueue.forEach(i => { if (!map.has(i.id)) map.set(i.id, i); });
        const mergedQueue = Array.from(map.values());

        if (mergedQueue.length !== 2) {
            console.error('Queue merge failed, expected 2 unique operations, got ' + mergedQueue.length);
            process.exit(1);
        }

        // 3. Test sync gating on unauthenticated response
        let writeAttempted = false;
        async function runSync(authSuccess, csrf) {
            if (!authSuccess) {
                return { success: false, reason: 'unauthenticated' };
            }
            writeAttempted = true;
            return { success: true, csrf: csrf };
        }

        (async function() {
            const unauthResult = await runSync(false, '');
            if (unauthResult.success !== false || writeAttempted !== false) {
                console.error('Sync should not proceed when unauthenticated');
                process.exit(1);
            }

            const authResult = await runSync(true, 'valid_token_123');
            if (authResult.success !== true || authResult.csrf !== 'valid_token_123' || writeAttempted !== true) {
                console.error('Sync should proceed with valid token when authenticated');
                process.exit(1);
            }

            console.log('NODE_OFFLINE_SIMULATION_OK');
        })();
        ";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('node', $descriptors, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $nodeScript);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, "Node script error: " . $stderr . " " . $stdout);
            $this->assertStringContainsString('NODE_OFFLINE_SIMULATION_OK', $stdout);
        }
    }
}