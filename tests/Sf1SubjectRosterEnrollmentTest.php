<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class Sf1SubjectRosterEnrollmentTest extends TestCase
{
    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_SESSION['csrf_token'] = 'test-token';
        $_POST['csrf_token'] = 'test-token';
        $_POST['action'] = 'noop';

        ob_start();
        require_once __DIR__ . '/../functions/bootstrap.php';
        require_once __DIR__ . '/../admin/admin_Classes_Action.php';
        require_once __DIR__ . '/../teacher/teacher_Enrollment_Helper.php';
        require_once __DIR__ . '/../admin/admin_SF1_Import_Action.php';
        ob_end_clean();

        restore_error_handler();
        restore_exception_handler();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT,
            email TEXT,
            lrn TEXT,
            password TEXT,
            first_name TEXT,
            middle_name TEXT,
            last_name TEXT,
            name_extension TEXT,
            sex TEXT,
            date_of_birth TEXT,
            religion TEXT,
            contact_number TEXT,
            address TEXT,
            house_street TEXT,
            barangay TEXT,
            municipality TEXT,
            province TEXT,
            father_name TEXT,
            mother_name TEXT,
            guardian_name TEXT,
            guardian_relationship TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT,
            curriculum TEXT,
            program TEXT,
            role TEXT,
            status TEXT DEFAULT 'active',
            created_at TEXT,
            updated_at TEXT
        )");

        $this->db->exec("CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            grade_level INTEGER,
            track TEXT,
            curriculum TEXT,
            program TEXT,
            created_at TEXT
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT,
            program TEXT,
            teacher_id INTEGER,
            schedule TEXT,
            room TEXT,
            status TEXT DEFAULT 'active',
            created_at TEXT
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT DEFAULT '2026-2027',
            semester INTEGER,
            curriculum TEXT,
            program TEXT,
            status TEXT DEFAULT 'enrolled',
            enrolled_at TEXT,
            UNIQUE (student_id, class_id, academic_year)
        )");

        $this->db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            subject_id INTEGER,
            teacher_id INTEGER,
            schedule TEXT,
            created_at TEXT
        )");

        $this->db->exec("CREATE TABLE class_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            day TEXT,
            start_time TEXT,
            end_time TEXT,
            created_at TEXT
        )");

        $this->db->exec("CREATE TABLE parent_students (
            parent_id INTEGER,
            student_id INTEGER,
            relationship TEXT
        )");
    }

    public function testSf1Grade11HumilityFilipinoClassSubjectStudentAppearsInFilipinoRoster(): void
    {
        // 1. Seed teacher Maria Santos (ID 10)
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, status)
            VALUES (10, 'TCH-2026-0010', 'maria.santos@balingasag.edu.ph', 'Maria', 'Santos', 'teacher', 'active')");

        // 2. Seed Filipino class for Grade 11 Humility (ID 15) assigned to teacher 10
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (15, 'Filipino', 11, 'Humility', 'academic', 10, 'active')");

        // 3. Seed class_subjects mapping (class_id 15 -> teacher_id 10)
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (15, 10)");

        // 4. Seed another subject for Grade 11 Humility (e.g. Oral Communication, ID 16)
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (16, 'Oral Communication', 11, 'Humility', 'academic', 10, 'active')");
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (16, 10)");

        // 5. Seed student imported from SF1 for Grade 11 Humility (ID 101)
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (101, 'STU-2026-0101', '111122223333', 'Jose', 'Rizal', 'student', 11, 'Humility', 'academic', 'active')");

        // 6. Sync enrollments for the SF1 imported student
        syncStudentEnrollments($this->db, 101, 11, 'Humility', 'academic', '2026-2027');

        // 7. Verify the student is enrolled in Filipino (class_id = 15) and Oral Comm (class_id = 16)
        $enrolledClasses = $this->db->query("SELECT class_id FROM enrollments WHERE student_id = 101 AND status = 'enrolled' ORDER BY class_id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([15, 16], array_map('intval', $enrolledClasses));

        // 8. Verify the student appears in the Filipino subject roster query
        $filipinoRosterStmt = $this->db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
            FROM users u
            JOIN enrollments e ON e.student_id = u.id
            JOIN classes c ON c.id = e.class_id
            JOIN class_subjects cs ON cs.class_id = c.id
            WHERE c.id = 15
              AND cs.teacher_id = 10
              AND c.class_name = 'Filipino'
              AND e.status = 'enrolled'
              AND u.status = 'active'
            ORDER BY u.last_name, u.first_name");
        $filipinoRosterStmt->execute();
        $filipinoRoster = $filipinoRosterStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $filipinoRoster);
        $this->assertSame(101, (int)$filipinoRoster[0]['id']);
        $this->assertSame('Jose', $filipinoRoster[0]['first_name']);
        $this->assertSame('Rizal', $filipinoRoster[0]['last_name']);
    }

    public function testSf1ImportAutoEnrollsStudentIntoExistingSectionSubjects(): void
    {
        // 1. Seed 2 active subject classes for Grade 11 Section Emerald
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES
            (10, 'General Mathematics', 11, 'Emerald', 'academic', 'active'),
            (20, 'Oral Communication', 11, 'Emerald', 'academic', 'active'),
            (30, 'Earth and Life Science', 11, 'Diamond', 'academic', 'active')");

        // 2. Insert student in Grade 11 Section Emerald
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (1, 'STU-2026-0001', '123456789012', 'Juan', 'Dela Cruz', 'student', 11, 'Emerald', 'academic', 'active')");

        // 3. Trigger syncStudentEnrollments
        syncStudentEnrollments($this->db, 1, 11, 'Emerald', 'academic', '2026-2027');

        // 4. Verify enrollment records
        $stmt = $this->db->prepare("SELECT class_id, status FROM enrollments WHERE student_id = 1 ORDER BY class_id ASC");
        $stmt->execute();
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $enrollments);
        $this->assertSame(10, (int)$enrollments[0]['class_id']);
        $this->assertSame('enrolled', $enrollments[0]['status']);
        $this->assertSame(20, (int)$enrollments[1]['class_id']);
        $this->assertSame('enrolled', $enrollments[1]['status']);

        // 5. Verify roster for General Mathematics (Class 10)
        $rosterStmt = $this->db->prepare("SELECT u.id, u.first_name, u.last_name
            FROM users u
            JOIN enrollments e ON e.student_id = u.id
            WHERE e.class_id = 10 AND e.status = 'enrolled' AND u.status = 'active'");
        $rosterStmt->execute();
        $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $roster);
        $this->assertSame('Juan', $roster[0]['first_name']);
        $this->assertSame('Dela Cruz', $roster[0]['last_name']);

        // 6. Verify roster for Diamond class (Class 30) is empty
        $diamondRoster = $this->db->query("SELECT COUNT(*) FROM enrollments WHERE class_id = 30")->fetchColumn();
        $this->assertSame(0, (int)$diamondRoster);
    }

    public function testSf1ImportReimportIsIdempotentAndPreservesEnrollments(): void
    {
        // 1. Seed classes and student
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES
            (10, 'General Mathematics', 11, 'Emerald', 'academic', 'active'),
            (20, 'Oral Communication', 11, 'Emerald', 'academic', 'active')");

        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (1, 'STU-2026-0001', '123456789012', 'Juan', 'Dela Cruz', 'student', 11, 'Emerald', 'academic', 'active')");

        // 2. Initial sync
        syncStudentEnrollments($this->db, 1, 11, 'Emerald', 'academic', '2026-2027');

        $initialIds = $this->db->query("SELECT id FROM enrollments WHERE student_id = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $initialIds);

        // 3. Re-sync (simulating SF1 re-import)
        syncStudentEnrollments($this->db, 1, 11, 'Emerald', 'academic', '2026-2027');

        $afterIds = $this->db->query("SELECT id FROM enrollments WHERE student_id = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $afterIds);
        $this->assertSame($initialIds, $afterIds, 'Existing enrollment records must be preserved without recreation');
    }

    public function testSf1ImportStudentIntoSectionWithNoClassesCreatesZeroEnrollments(): void
    {
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (2, 'STU-2026-0002', '987654321098', 'Maria', 'Santos', 'student', 12, 'Sapphire', 'techpro', 'active')");

        // Section Sapphire has 0 classes in classes table
        syncStudentEnrollments($this->db, 2, 12, 'Sapphire', 'techpro', '2026-2027');

        $enrollmentCount = (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 2")->fetchColumn();
        $this->assertSame(0, $enrollmentCount);
    }

    public function testSyncClassEnrollmentsByGradeSectionEnrollsExistingStudents(): void
    {
        // 1. Student already exists in Grade 11 Ruby
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (3, 'STU-2026-0003', '555555555555', 'Pedro', 'Penduko', 'student', 11, 'Ruby', 'academic', 'active')");

        // 2. Create new class for Grade 11 Ruby
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status)
            VALUES (99, 'Physical Science', 11, 'Ruby', 'academic', 'active')");

        // 3. Sync class enrollments
        syncClassEnrollmentsByGradeSection($this->db, 99, 11, 'Ruby', 'academic');

        // 4. Verify student is enrolled into Class 99
        $enrolled = (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 3 AND class_id = 99 AND status = 'enrolled'")->fetchColumn();
        $this->assertSame(1, $enrolled);
    }

    public function testExistingStudentAppearsInFetchClassStudentsQueryAfterSyncAndRemainsIdempotent(): void
    {
        // 1. Seed teacher Maria Santos (ID 10)
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, status)
            VALUES (10, 'TCH-2026-0010', 'maria.santos@balingasag.edu.ph', 'Maria', 'Santos', 'teacher', 'active')");

        // 2. Seed Filipino class for Grade 11 Humility (ID 15) assigned to teacher 10
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (15, 'Filipino', 11, 'Humility', 'academic', 10, 'active')");

        // 3. Seed class_subjects mapping (class_id 15 -> teacher_id 10)
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (15, 10)");

        // 4. Verify class_subjects mapping exists — this is the class/subject relationship
        $csCount = (int)$this->db->query(
            "SELECT COUNT(*) FROM class_subjects WHERE class_id = 15 AND teacher_id = 10"
        )->fetchColumn();
        $this->assertSame(1, $csCount, 'class_subjects mapping must exist for Filipino class');

        // 5. Seed an existing SF1-imported student (ID 201) with Grade 11 Humility
        //    but 0 initial enrollments (simulating student imported before v0.3.134)
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (201, 'STU-2026-0201', '999988887777', 'Jose', 'Rizal', 'student', 11, 'Humility', 'academic', 'active')");

        // 6. Assert 0 initial enrollments
        $this->assertSame(
            0,
            (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 201")->fetchColumn(),
            'Student must initially have zero enrollment records'
        );

        // 7. Run the exact query from fetchClassStudents() in teacher_Action.php — must return 0 before sync
        $rosterQuery = "SELECT u.id, u.reference_code, u.first_name, u.last_name
                        FROM enrollments e
                        JOIN users u ON u.id = e.student_id
                        WHERE e.class_id = ? AND e.status = 'enrolled'
                        ORDER BY u.last_name, u.first_name";
        $stmt = $this->db->prepare($rosterQuery);
        $stmt->execute([15]);
        $this->assertCount(0, $stmt->fetchAll(PDO::FETCH_ASSOC), 'fetchClassStudents query must return 0 before sync');

        // 8. Call syncClassEnrollmentsForClass — which delegates to syncClassEnrollmentsByGradeSection
        syncClassEnrollmentsForClass($this->db, 15);

        // 9. Run the exact fetchClassStudents query again — Jose Rizal must now appear
        $stmt->execute([15]);
        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $roster, 'fetchClassStudents query must return 1 student after sync');
        $this->assertSame(201, (int)$roster[0]['id']);
        $this->assertSame('Jose', $roster[0]['first_name']);
        $this->assertSame('Rizal', $roster[0]['last_name']);

        // 10. Verify enrollment record details
        $enrollStmt = $this->db->prepare(
            "SELECT id, student_id, class_id, status, enrolled_at FROM enrollments WHERE student_id = 201 AND class_id = 15"
        );
        $enrollStmt->execute();
        $enrollment = $enrollStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($enrollment);
        $this->assertSame('enrolled', $enrollment['status']);
        $enrollmentId = (int)$enrollment['id'];
        $enrolledAt = (string)$enrollment['enrolled_at'];

        // 11. Run sync a second time — must remain idempotent (no duplicates)
        syncClassEnrollmentsForClass($this->db, 15);
        $this->assertSame(
            1,
            (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 201 AND class_id = 15")->fetchColumn(),
            'Must not create duplicate enrollment records on repeated synchronization'
        );
        $enrollStmt->execute();
        $secondEnrollment = $enrollStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($enrollmentId, (int)$secondEnrollment['id']);
        $this->assertSame($enrolledAt, (string)$secondEnrollment['enrolled_at']);

        // 12. Verify the fetchClassStudents query still returns exactly 1 after second sync
        $stmt->execute([15]);
        $rosterAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rosterAfter, 'fetchClassStudents query must still return 1 after second sync');
        $this->assertSame(201, (int)$rosterAfter[0]['id']);
    }

    public function testBootstrapAndTeacherActionExplicitlyRequireAppHelpers(): void
    {
        $bootstrapContent = file_get_contents(__DIR__ . '/../functions/bootstrap.php');
        $this->assertStringContainsString(
            "require_once APP_ROOT . '/functions/app-helpers.php';",
            $bootstrapContent,
            'functions/bootstrap.php must explicitly require functions/app-helpers.php'
        );

        $teacherActionContent = file_get_contents(__DIR__ . '/../teacher/teacher_Action.php');
        $this->assertStringContainsString(
            "require_once \$__appRoot . '/functions/app-helpers.php';",
            $teacherActionContent,
            'teacher/teacher_Action.php must explicitly require functions/app-helpers.php'
        );

        $this->assertStringContainsString(
            'syncClassEnrollmentsForClass($db, $classId);',
            $teacherActionContent,
            'teacher_Action.php fetchClassStudents must wire syncClassEnrollmentsForClass'
        );
    }

    public function testSyncClassEnrollmentsForClassDefersCacheAssignmentUntilAfterSuccessfulCompletion(): void
    {
        // 1. Calling with non-existent class ID 999 must not mark it as synced or throw
        syncClassEnrollmentsForClass($this->db, 999);

        // 2. Seed student (ID 301) for Grade 11 Section Humility
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (301, 'STU-2026-0301', '333344445555', 'Andres', 'Bonifacio', 'student', 11, 'Humility', 'academic', 'active')");

        // 3. Seed Class 999 as active Filipino class
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (999, 'Filipino', 11, 'Humility', 'academic', 10, 'active')");

        // 4. Now synchronize Class 999 — it must successfully execute without being blocked by prior call
        syncClassEnrollmentsForClass($this->db, 999);

        $enrolledCount = (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 301 AND class_id = 999 AND status = 'enrolled'")->fetchColumn();
        $this->assertSame(1, $enrolledCount, 'Student must be enrolled after class is created and synchronized');
    }

    public function testSf1CommitResolvesExistingSectionWithoutDuplicationAndEnrollsStudentsInClassRoster(): void
    {
        // 1. Seed teacher Maria Santos (ID 10)
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, status)
            VALUES (10, 'TCH-2026-0010', 'maria.santos@balingasag.edu.ph', 'Maria', 'Santos', 'teacher', 'active')");

        // 2. Seed existing Section Humility for Grade 11 Academic
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track, curriculum, program)
            VALUES (1, 'Humility', 11, 'academic', 'strengthened_shs', 'academic_strengthened')");

        // 3. Seed active Filipino class (ID 15) for Grade 11 Humility
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (15, 'Filipino', 11, 'Humility', 'academic', 10, 'active')");
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (15, 10)");

        // 4. Resolve section with SF1 data where section name is 'HUMILITY' (all caps)
        $resolved = ensureSf1Section($this->db, 'HUMILITY', 11, 'academic', '2026-2027');
        $this->assertSame(1, (int)$resolved['id']);
        $this->assertSame('Humility', $resolved['name']);
        $this->assertFalse($resolved['created'], 'Must resolve existing section without creating a new one');

        $sectionCount = (int)$this->db->query("SELECT COUNT(*) FROM sections")->fetchColumn();
        $this->assertSame(1, $sectionCount, 'Sections table must have exactly 1 record');

        // 5. Seed Jose Rizal using resolved canonical section name
        $canonicalSection = $resolved['name'];
        $this->db->exec("INSERT INTO users (id, reference_code, email, lrn, first_name, last_name, grade_level, section, track, role, status)
            VALUES (201, 'STU-2026-0201', 'jose.rizal@students.balingasag.edu.ph', '123456789012', 'Jose', 'Rizal', 11, '{$canonicalSection}', 'academic', 'student', 'active')");

        syncStudentEnrollments($this->db, 201, 11, $canonicalSection, 'academic', '2026-2027');

        // 6. Verify enrollment in Filipino Class 15
        $enrollment = $this->db->query("SELECT id, student_id, class_id, status FROM enrollments WHERE student_id = 201 AND class_id = 15")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($enrollment);
        $this->assertSame('enrolled', $enrollment['status']);
        $enrollmentId = (int)$enrollment['id'];

        // 7. Verify roster query returns Jose Rizal
        $rosterQuery = "SELECT u.id, u.reference_code, u.first_name, u.last_name
                        FROM enrollments e
                        JOIN users u ON u.id = e.student_id
                        WHERE e.class_id = ? AND e.status = 'enrolled'
                        ORDER BY u.last_name, u.first_name";
        $stmt = $this->db->prepare($rosterQuery);
        $stmt->execute([15]);
        $roster = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $roster);
        $this->assertSame(201, (int)$roster[0]['id']);

        // 8. Re-import identical SF1 student (simulating re-import)
        $secondResolved = ensureSf1Section($this->db, 'Humility', 11, 'academic', '2026-2027');
        $this->assertSame(1, (int)$secondResolved['id']);
        $this->assertFalse($secondResolved['created']);

        // Re-sync existing student
        syncStudentEnrollments($this->db, 201, 11, $secondResolved['name'], 'academic', '2026-2027');

        // 9. Verify strictly idempotent: 1 section, 1 student (+ 1 teacher), 1 enrollment record (same ID)
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM sections")->fetchColumn());
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 201 AND class_id = 15")->fetchColumn());
        $afterEnrollmentId = (int)$this->db->query("SELECT id FROM enrollments WHERE student_id = 201 AND class_id = 15")->fetchColumn();
        $this->assertSame($enrollmentId, $afterEnrollmentId);

        $stmt->execute([15]);
        $rosterAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rosterAfter);
        $this->assertSame(201, (int)$rosterAfter[0]['id']);
    }
}

