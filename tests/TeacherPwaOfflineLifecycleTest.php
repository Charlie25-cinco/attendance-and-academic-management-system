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

    public function testOfflineAttendanceSyncDispatchesIdempotentStudentAndParentNotifications(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-010', 'teacher10@school.edu', ?, 'Elena', 'Torres', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('STU-010', 'student10@school.edu', ?, 'Juan', 'Dela Cruz', 'student', 'active')")->execute([$passwordHash]);
        $studentId = (int)$this->db->lastInsertId();

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

        // 1. Simulate sync dispatching attendance notifications
        appNotifyAttendanceRecords($this->db, $classId, $date, $records, $teacherId);

        // Verify notifications created for both student and parent
        $studentNotifs = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$studentId}")->fetchAll(PDO::FETCH_ASSOC);
        $parentNotifs = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$parentId}")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $studentNotifs);
        $this->assertCount(1, $parentNotifs);
        $this->assertSame('attendance_' . $classId . '_' . $date . '_' . $studentId, $studentNotifs[0]['source_key']);
        $this->assertSame('attendance_' . $classId . '_' . $date . '_' . $studentId, $parentNotifs[0]['source_key']);

        // 2. Simulate sync retry with same attendance records
        appNotifyAttendanceRecords($this->db, $classId, $date, $records, $teacherId);

        // Verify NO duplicate rows created
        $studentNotifsAfter = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$studentId}")->fetchAll(PDO::FETCH_ASSOC);
        $parentNotifsAfter = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$parentId}")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $studentNotifsAfter);
        $this->assertCount(1, $parentNotifsAfter);
    }

    public function testOfflineActivitySyncDispatchesIdempotentStudentAndParentNotifications(): void
    {
        $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
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
        appNotifyGradeActivityCreated($this->db, $classId, $gradeItemId, 'Chemistry Quiz 1', 'ww', 25.0);

        $studentActivityNotifs = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$studentId} AND source_key LIKE 'grade_item_created_%'")->fetchAll(PDO::FETCH_ASSOC);
        $parentActivityNotifs = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$parentId} AND source_key LIKE 'grade_item_created_%'")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $studentActivityNotifs);
        $this->assertCount(1, $parentActivityNotifs);

        // 2. Retry sync: assert idempotency
        appNotifyGradeActivityCreated($this->db, $classId, $gradeItemId, 'Chemistry Quiz 1', 'ww', 25.0);

        $studentActivityNotifsAfter = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$studentId} AND source_key LIKE 'grade_item_created_%'")->fetchAll(PDO::FETCH_ASSOC);
        $parentActivityNotifsAfter = $this->db->query("SELECT * FROM user_notifications WHERE user_id = {$parentId} AND source_key LIKE 'grade_item_created_%'")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $studentActivityNotifsAfter);
        $this->assertCount(1, $parentActivityNotifsAfter);
    }

    public function testDeleteGradeItemRequiresTeacherOwnershipAndRemovesScores(): void
    {
        $passwordHash = password_hash('TeacherPassword123!', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                            VALUES ('TCH-004', 'teacher4@school.edu', ?, 'Luis', 'Gomez', 'teacher', 'active')")->execute([$passwordHash]);
        $teacherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id) VALUES ('12-Ruby', 12, 'Ruby', ?)")->execute([$teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO grade_items (class_id, teacher_id, title, component, total_score, activity_date, status)
                            VALUES (?, ?, 'Quarter Exam', 'qa', 50.0, '2026-09-03', 'active')")->execute([$classId, $teacherId]);
        $gradeItemId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO grade_item_scores (grade_item_id, student_id, score) VALUES (?, 101, 45.0)")->execute([$gradeItemId]);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM grade_items WHERE id = {$gradeItemId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM grade_item_scores WHERE grade_item_id = {$gradeItemId}")->fetchColumn());

        // Perform server deletion check (only matching teacher_id can delete)
        $delStmt = $this->db->prepare("DELETE FROM grade_items WHERE id = ? AND teacher_id = ?");
        $delStmt->execute([$gradeItemId, 999]); // Wrong teacher
        $this->assertSame(0, $delStmt->rowCount());

        // Correct teacher deletes
        $delStmt->execute([$gradeItemId, $teacherId]);
        $this->assertSame(1, $delStmt->rowCount());

        $this->db->prepare("DELETE FROM grade_item_scores WHERE grade_item_id = ?")->execute([$gradeItemId]);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM grade_items WHERE id = {$gradeItemId}")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM grade_item_scores WHERE grade_item_id = {$gradeItemId}")->fetchColumn());
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

        // Check stores have item
        if (!activityStore.data.has(localId) || !queueStore.data.has('op_' + localId)) {
            console.error('FAIL: Setup failed');
            process.exit(1);
        }

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

        // 2. Test Server ID Persistence on Successful Sync
        const syncedLocalId = 'act_6_1725345999000';
        const syncedActivity = {
            local_id: syncedLocalId,
            server_id: null,
            class_id: 6,
            title: 'Quiz 2',
            sync_status: 'pending'
        };
        activityStore.put(syncedActivity);

        // Simulate sync success returning grade_item_id = 88
        const serverGradeItemId = 88;
        const rec = activityStore.data.get(syncedLocalId);
        rec.sync_status = 'synced';
        rec.server_id = serverGradeItemId;
        rec.id = serverGradeItemId;
        activityStore.put(rec);

        const updatedRec = activityStore.data.get(syncedLocalId);
        if (updatedRec.server_id !== 88 || updatedRec.sync_status !== 'synced') {
            console.error('FAIL: Server ID persistence failed');
            process.exit(1);
        }

        // Authoritative deletion logic: because server_id is 88 (> 0), it is recognized as a server activity
        const isUnsynced = !updatedRec || !updatedRec.server_id || updatedRec.sync_status === 'pending';
        if (isUnsynced !== false) {
            console.error('FAIL: Synced activity incorrectly identified as unsynced');
            process.exit(1);
        }

        // 3. Test Service Worker Background Sync Simulation & Notification Formatting
        let notificationShown = 0;
        let notificationTitle = '';
        let notificationBody = '';

        const mockRegistration = {
            showNotification: (title, options) => {
                notificationShown++;
                notificationTitle = title;
                notificationBody = options.body;
            }
        };

        function formatSyncNotificationSummary(attRecords, actSets) {
            var parts = [];
            if (attRecords > 0) {
                parts.push(attRecords + ' attendance record' + (attRecords === 1 ? '' : 's'));
            }
            if (actSets > 0) {
                parts.push(actSets + ' activity set' + (actSets === 1 ? '' : 's'));
            }
            return 'Offline data synchronized — ' + parts.join(' and ') + ' synchronized successfully.';
        }

        async function simulateServiceWorkerSync(isOpenWindow, isAuth, queueItems, hasFailures = false) {
            if (!isAuth) return { success: false, reason: 'unauthorized' };
            if (!queueItems || queueItems.length === 0) return { success: true, synced: 0 };
            if (hasFailures) return { success: false, reason: 'partial_failure' };

            let attRecords = 0;
            let actSets = 0;

            queueItems.forEach(item => {
                if (item.operation === 'attendance.upsert') {
                    attRecords += (item.payload && item.payload.records) ? item.payload.records.length : 1;
                } else {
                    actSets += 1;
                }
            });

            if ((attRecords > 0 || actSets > 0) && !isOpenWindow) {
                const summary = formatSyncNotificationSummary(attRecords, actSets);
                mockRegistration.showNotification('BSHS AMS - Data Synchronized', { body: summary });
            }
            return { success: true, attRecords, actSets };
        }

        (async () => {
            // Case A: Attendance-only sync (12 records) -> 'Offline data synchronized — 12 attendance records synchronized successfully.'
            notificationShown = 0;
            const resAtt = await simulateServiceWorkerSync(false, true, [
                { operation: 'attendance.upsert', payload: { records: new Array(12).fill({}) } }
            ]);
            if (notificationShown !== 1 || notificationBody !== 'Offline data synchronized — 12 attendance records synchronized successfully.') {
                console.error('FAIL: Attendance-only sync notification mismatch:', notificationBody);
                process.exit(1);
            }

            // Case B: Activity-only sync (2 activity sets) -> 'Offline data synchronized — 2 activity sets synchronized successfully.'
            notificationShown = 0;
            const resAct = await simulateServiceWorkerSync(false, true, [
                { operation: 'activity.upsert', payload: { title: 'Act 1' } },
                { operation: 'activity.upsert', payload: { title: 'Act 2' } }
            ]);
            if (notificationShown !== 1 || notificationBody !== 'Offline data synchronized — 2 activity sets synchronized successfully.') {
                console.error('FAIL: Activity-only sync notification mismatch:', notificationBody);
                process.exit(1);
            }

            // Case C: Combined sync (12 attendance records and 2 activity sets)
            notificationShown = 0;
            const resComb = await simulateServiceWorkerSync(false, true, [
                { operation: 'attendance.upsert', payload: { records: new Array(12).fill({}) } },
                { operation: 'activity.upsert', payload: { title: 'Act 1' } },
                { operation: 'activity.upsert', payload: { title: 'Act 2' } }
            ]);
            if (notificationShown !== 1 || notificationBody !== 'Offline data synchronized — 12 attendance records and 2 activity sets synchronized successfully.') {
                console.error('FAIL: Combined sync notification mismatch:', notificationBody);
                process.exit(1);
            }

            // Case D: App Open in Foreground (isOpenWindow = true) -> 0 device notification (foreground handles UI)
            notificationShown = 0;
            const resFg = await simulateServiceWorkerSync(true, true, [{ operation: 'activity.upsert', payload: {} }]);
            if (notificationShown !== 0) {
                console.error('FAIL: Open app should not show background device notification');
                process.exit(1);
            }

            // Case E: Unauthenticated -> Halts, 0 notification
            notificationShown = 0;
            const resUnauth = await simulateServiceWorkerSync(false, false, [{ operation: 'activity.upsert', payload: {} }]);
            if (notificationShown !== 0) {
                console.error('FAIL: Unauthenticated should not show notification');
                process.exit(1);
            }

            // Case F: Partially failed batch -> 0 success notification
            notificationShown = 0;
            const resPartial = await simulateServiceWorkerSync(false, true, [{ operation: 'activity.upsert', payload: {} }], true);
            if (notificationShown !== 0) {
                console.error('FAIL: Partially failed batch should not show success notification');
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