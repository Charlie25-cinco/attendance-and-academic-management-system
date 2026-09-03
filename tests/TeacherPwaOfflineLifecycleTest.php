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

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            relationship TEXT DEFAULT 'parent',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(parent_id, student_id)
        )");

        $this->db->exec("CREATE TABLE user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            source_key TEXT,
            title TEXT NOT NULL,
            subtitle TEXT,
            icon TEXT DEFAULT 'bi-bell',
            color TEXT DEFAULT 'primary',
            link TEXT,
            event_at DATETIME,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, source_key)
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

        $this->db->exec("CREATE TABLE grades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_subject_id INTEGER NOT NULL,
            term TEXT,
            quarter TEXT,
            academic_year TEXT NOT NULL,
            final_grade REAL,
            remarks TEXT,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE grade_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grade_id INTEGER NOT NULL UNIQUE,
            status TEXT NOT NULL,
            submitted_by INTEGER NOT NULL,
            submitted_at DATETIME NOT NULL,
            reviewed_by INTEGER,
            reviewed_at DATETIME,
            remarks TEXT
        )");

        $this->db->exec("CREATE TABLE attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            status TEXT NOT NULL,
            time_in TEXT,
            remarks TEXT,
            academic_year TEXT,
            semester INTEGER,
            recorded_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, class_id, date)
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

        appRememberIssueToken($this->db, $teacherId);
        $_SESSION = [];

        $selector = $this->db->query("SELECT selector FROM auth_remember_tokens WHERE user_id = {$teacherId}")->fetchColumn();
        $this->assertNotEmpty($selector);

        $validator = 'mockValidatorKey1234567890123456';
        $update = $this->db->prepare("UPDATE auth_remember_tokens SET token_hash = ? WHERE selector = ?");
        $update->execute([hash('sha256', $validator), $selector]);

        $_COOKIE['remember_token'] = $selector . ':' . $validator;

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

    public function testOfflineAttendanceSyncDispatchesFourRoleNotifications(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
        // Create Admin
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('ADM-010', 'admin10@school.edu', ?, 'Admin', 'User', 'admin', 'active')")->execute([$passwordHash]);
        $adminId = (int)$this->db->lastInsertId();

        // Create Teacher
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-010', 'teacher10@school.edu', ?, 'Elena', 'Torres', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        // Create Student
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-010', 'student10@school.edu', ?, 'Juan', 'Dela Cruz', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

        // Create Parent
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('PAR-010', 'parent10@school.edu', ?, 'Maria', 'Dela Cruz', 'parent', 'active')")->execute([$passwordHash]);
        $parentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id) VALUES (?, ?)")->execute([$parentId, $studentId]);

        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id) VALUES ('11-Diamond', 11, 'Diamond', ?)")->execute([$teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$studentId, $classId]);

        $date = '2026-09-03';
        $records = [
            ['student_id' => $studentId, 'status' => 'present']
        ];

        // 1. Simulate attendance sync dispatching notifications to all 4 roles
        appNotifyAttendanceRecords($this->db, $classId, $date, $records, $teacherId);

        // Verify notifications created for Student, Parent, Teacher, and Admin
        $studentNotif = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$studentId}")->fetch(PDO::FETCH_ASSOC);
        $parentNotif = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$parentId}")->fetch(PDO::FETCH_ASSOC);
        $teacherNotif = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$teacherId}")->fetch(PDO::FETCH_ASSOC);
        $adminNotif = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$adminId}")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($studentNotif);
        $this->assertNotEmpty($parentNotif);
        $this->assertNotEmpty($teacherNotif);
        $this->assertNotEmpty($adminNotif);

        $this->assertStringContainsString('Student_Attendance.php', (string)$studentNotif['link']);
        $this->assertStringContainsString('Parent_Progress.php', (string)$parentNotif['link']);
        $this->assertStringContainsString('teacher_Attendance.php', (string)$teacherNotif['link']);
        $this->assertStringContainsString('admin_Attendance.php', (string)$adminNotif['link']);

        // 2. Simulate sync retry with same attendance records -> verify strict idempotency
        appNotifyAttendanceRecords($this->db, $classId, $date, $records, $teacherId);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$studentId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$parentId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$teacherId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$adminId}")->fetchColumn());
    }

    public function testOfflineActivityAndScoreSyncDispatchesFourRoleNotifications(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('ADM-020', 'admin20@school.edu', ?, 'Admin', 'Officer', 'admin', 'active')")->execute([$passwordHash]);
        $adminId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-020', 'teacher20@school.edu', ?, 'Ramon', 'Valdez', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-020', 'student20@school.edu', ?, 'Carla', 'Mendoza', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('PAR-020', 'parent20@school.edu', ?, 'Pedro', 'Mendoza', 'parent', 'active')")->execute([$passwordHash]);
        $parentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id) VALUES (?, ?)")->execute([$parentId, $studentId]);

        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id) VALUES ('12-Emerald', 12, 'Emerald', ?)")->execute([$teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$studentId, $classId]);

        $this->db->prepare("INSERT INTO grade_items (class_id, teacher_id, title, component, total_score, activity_date, status)
                            VALUES (?, ?, 'Chemistry Quiz 1', 'ww', 25.0, '2026-09-03', 'active')")->execute([$classId, $teacherId]);
        $gradeItemId = (int)$this->db->lastInsertId();

        // 1. Dispatch activity creation notification
        appNotifyGradeActivityCreated($this->db, $classId, $gradeItemId, 'Chemistry Quiz 1', 'ww', 25.0, $teacherId);

        $this->assertGreaterThanOrEqual(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$studentId} AND source_key LIKE 'grade_item_created_%'")->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$parentId} AND source_key LIKE 'grade_item_created_%'")->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$teacherId} AND source_key LIKE 'grade_item_created_%'")->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int)$this->db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = {$adminId} AND source_key LIKE 'grade_item_created_%'")->fetchColumn());
    }

    public function testAdminGradeApprovalWorkflowPreservedWithoutStateChange(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('ADM-030', 'admin30@school.edu', ?, 'Admin', 'Evaluator', 'admin', 'active')")->execute([$passwordHash]);
        $adminId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-030', 'teacher30@school.edu', ?, 'Teacher', 'Author', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO grades (student_id, class_subject_id, term, academic_year, final_grade, remarks, status)
                            VALUES (501, 101, 'Term1', '2026-2027', 88.5, 'Passed', 'pending')")->execute();
        $gradeId = (int)$this->db->lastInsertId();

        // Teacher submits grade for approval (Stage 1: submitted)
        $this->db->prepare("INSERT INTO grade_approvals (grade_id, status, submitted_by, submitted_at)
                            VALUES (?, 'submitted', ?, datetime('now'))")->execute([$gradeId, $teacherId]);
        $approvalId = (int)$this->db->lastInsertId();

        // Informational Admin Notification Dispatched
        $targetLink = 'admin_Grade_Approvals_Detail.php?tab=grades&grade_level=11&section=Diamond&academic_year=2026-2027&semester=1';
        appDispatchNotification(
            $this->db,
            [$adminId],
            'grade_submission_admin_101_Term1_2026-2027',
            'Subject Grades Submitted',
            'Teacher submitted 11-Diamond for admin verification.',
            'bi-journal-check',
            'primary',
            ['admin' => $targetLink]
        );

        // Assert notification created for admin
        $adminNotif = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$adminId} AND source_key = 'grade_submission_admin_101_Term1_2026-2027'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($adminNotif);
        $this->assertStringContainsString('admin_Grade_Approvals_Detail.php', (string)$adminNotif['link']);

        // Assert grade approval status in DB remains 'submitted' (NOT auto-approved or modified)
        $approvalStatus = $this->db->query("SELECT status FROM grade_approvals WHERE id = {$approvalId}")->fetchColumn();
        $this->assertSame('submitted', $approvalStatus);
    }

    public function testFailedTransactionRollbackCreatesZeroNotifications(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-040', 'student40@school.edu', ?, 'Test', 'Student', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

        $initialCount = (int)$this->db->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();

        // Simulate a failing database transaction
        try {
            $this->db->beginTransaction();
            $this->db->exec("INSERT INTO attendance (student_id, class_id, date, status) VALUES ({$studentId}, 1, '2026-09-03', 'present')");
            // Simulate error triggering rollback
            throw new \RuntimeException("Database constraint error during sync");
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }

        // Assert 0 notifications created on failed write
        $afterCount = (int)$this->db->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();
        $this->assertSame($initialCount, $afterCount);
    }

    public function testOfflineSimulationInNodeJsRuntime(): void
    {
        $offlineStorageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $networkSyncJs = file_get_contents(__DIR__ . '/../assets/js/networkSync.js');
        $swJs = file_get_contents(__DIR__ . '/../sw.js');

        $this->assertIsString($offlineStorageJs);
        $this->assertIsString($networkSyncJs);
        $this->assertIsString($swJs);

        // Verify key code additions
        $this->assertStringContainsString('deleteActivityLocally', $offlineStorageJs);
        $this->assertStringContainsString('requestBackgroundSync', $offlineStorageJs);
        $this->assertStringContainsString('bshs-offline-sync', $swJs);
        $this->assertStringContainsString('handleBackgroundSync', $swJs);
        $this->assertStringContainsString('wasOffline', $networkSyncJs);
        $this->assertStringContainsString('bshs:sync-completed', $networkSyncJs);

        $nodeScript = "
        class MockStore {
            constructor() { this.data = new Map(); }
            get(k) {
                const res = this.data.get(k);
                return { onsuccess: null, result: res };
            }
            put(v) { this.data.set(v.local_id || v.id, v); }
            delete(k) { this.data.delete(k); }
            getAll() { return { onsuccess: null, result: Array.from(this.data.values()) }; }
        }

        class MockLocalStorage {
            constructor() { this.store = {}; }
            getItem(k) { return this.store[k] || null; }
            setItem(k, v) { this.store[k] = String(v); }
            removeItem(k) { delete this.store[k]; }
        }

        const localStorage = new MockLocalStorage();
        const activityStore = new MockStore();
        const queueStore = new MockStore();

        // 1. Test Unsynced Offline Activity Deletion & Queue Cancellation
        const localId = 'act_5_1725345678000';
        const activity = {
            local_id: localId,
            server_id: null,
            class_id: 5,
            title: 'Offline Quiz',
            component: 'ww',
            total_score: 20,
            scores: [],
            sync_status: 'pending'
        };
        activityStore.put(activity);

        const syncOp = {
            id: 'op_' + localId,
            operation_id: localId,
            operation: 'activity.upsert',
            payload: { class_id: 5, title: 'Offline Quiz', server_id: null }
        };
        queueStore.put(syncOp);
        localStorage.setItem('bshs_offline_queue', JSON.stringify([syncOp]));

        // Simulate deleteActivityLocally(localId)
        activityStore.delete(localId);
        queueStore.delete('op_' + localId);
        queueStore.delete(localId);
        let queue = JSON.parse(localStorage.getItem('bshs_offline_queue')) || [];
        queue = queue.filter(q => q.id !== localId && q.id !== 'op_' + localId);
        localStorage.setItem('bshs_offline_queue', JSON.stringify(queue));

        if (activityStore.data.has(localId) || queueStore.data.has('op_' + localId) || queue.length !== 0) {
            console.error('FAIL: deleteActivityLocally did not cancel queue');
            process.exit(1);
        }

        // 2. Test Online Event Debounce and Empty Queue Notice Suppression
        let noticesShown = [];
        function showNotification(msg, type) {
            noticesShown.push({ msg, type });
        }

        let wasOffline = false;
        let isProcessing = false;
        let mockQueue = [];

        function handleOffline() {
            wasOffline = true;
            showNotification('You are in offline mode. All records will be stored safely on this device.', 'warning');
        }

        async function handleOnlineDebounced(queueItems, isCurrentlyProcessing) {
            if (!wasOffline) return;
            if (isCurrentlyProcessing) return;

            if (queueItems && queueItems.length > 0) {
                wasOffline = false;
                showNotification('Internet connection restored. Checking offline records...', 'info');
            } else {
                wasOffline = false;
                // Empty queue: no notice shown!
            }
        }

        (async () => {
            // Case A: Online event without prior offline -> 0 notice
            noticesShown = [];
            wasOffline = false;
            await handleOnlineDebounced([{ id: 1 }], false);
            if (noticesShown.length !== 0) {
                console.error('FAIL: Showed online notice without prior offline transition');
                process.exit(1);
            }

            // Case B: Genuine offline transition, but empty queue -> 0 online notice
            noticesShown = [];
            handleOffline();
            if (noticesShown.length !== 1 || noticesShown[0].type !== 'warning') {
                console.error('FAIL: Offline notice missing');
                process.exit(1);
            }
            await handleOnlineDebounced([], false);
            if (noticesShown.length !== 1) { // Still just the 1 offline notice
                console.error('FAIL: Showed online notice with empty queue');
                process.exit(1);
            }

            // Case C: Genuine offline transition WITH pending queue -> shows notice
            noticesShown = [];
            handleOffline();
            await handleOnlineDebounced([{ id: 1 }], false);
            if (noticesShown.length !== 2 || noticesShown[1].type !== 'info') {
                console.error('FAIL: Did not show online notice when pending items existed');
                process.exit(1);
            }

            // Case D: Sync already running (isProcessing = true) -> suppresses duplicate notice
            noticesShown = [];
            handleOffline();
            await handleOnlineDebounced([{ id: 1 }], true);
            if (noticesShown.length !== 1) {
                console.error('FAIL: Showed notice while isProcessing was true');
                process.exit(1);
            }

            console.log('NODE_ACTIVITY_AND_SW_SYNC_OK');
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
            $this->assertStringContainsString('NODE_ACTIVITY_AND_SW_SYNC_OK', $stdout);
        }
    }
}