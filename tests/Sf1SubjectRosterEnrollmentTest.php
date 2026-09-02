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

        ob_start();
        require_once __DIR__ . '/../functions/bootstrap.php';
        require_once __DIR__ . '/../admin/admin_Classes_Action.php';
        require_once __DIR__ . '/../teacher/teacher_Enrollment_Helper.php';
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

    public function testExistingSf1ImportedStudentWithZeroEnrollmentsIsRepairedOnRosterLoadAndRemainsUnchangedOnSecondSync(): void
    {
        // 1. Seed teacher Maria Santos (ID 10)
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, status)
            VALUES (10, 'TCH-2026-0010', 'maria.santos@balingasag.edu.ph', 'Maria', 'Santos', 'teacher', 'active')");

        // 2. Seed Filipino class for Grade 11 Humility (ID 15) assigned to teacher 10
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, teacher_id, status)
            VALUES (15, 'Filipino', 11, 'Humility', 'academic', 10, 'active')");

        // 3. Seed class_subjects mapping (class_id 15 -> teacher_id 10)
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (15, 10)");

        // 4. Seed an existing SF1-imported student (ID 201, "Jose Rizal") with Grade 11 Humility
        // BUT with 0 initial enrollments (simulating student imported before v0.3.134)
        $this->db->exec("INSERT INTO users (id, reference_code, lrn, first_name, last_name, role, grade_level, section, track, status)
            VALUES (201, 'STU-2026-0201', '999988887777', 'Jose', 'Rizal', 'student', 11, 'Humility', 'academic', 'active')");

        // 5. Assert that initially the student has 0 enrollments
        $initialEnrollmentCount = (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 201")->fetchColumn();
        $this->assertSame(0, $initialEnrollmentCount, 'Student must initially have zero enrollment records');

        // 6. Assert that initially the raw roster join query returns 0 students
        $rawRosterCount = (int)$this->db->query("SELECT COUNT(*) FROM users u
            JOIN enrollments e ON e.student_id = u.id
            JOIN classes c ON c.id = e.class_id
            JOIN class_subjects cs ON cs.class_id = c.id
            WHERE c.id = 15 AND cs.teacher_id = 10 AND e.status = 'enrolled'")->fetchColumn();
        $this->assertSame(0, $rawRosterCount, 'Roster must initially be empty before on-demand sync');

        // 7. On-demand roster load: tEnrollFetchActiveStudentIds triggers syncClassEnrollmentsForClass
        $activeStudentIds = tEnrollFetchActiveStudentIds($this->db, 15);

        // 8. Verify the student was repaired and is now returned in active student IDs
        $this->assertContains(201, $activeStudentIds);

        // 9. Verify the enrollment record in the database
        $stmt = $this->db->prepare("SELECT id, student_id, class_id, status, enrolled_at FROM enrollments WHERE student_id = 201 AND class_id = 15");
        $stmt->execute();
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($enrollment);
        $this->assertSame('enrolled', $enrollment['status']);
        $enrollmentId = (int)$enrollment['id'];
        $enrolledAt = (string)$enrollment['enrolled_at'];

        // 10. Verify student now appears in full Filipino subject roster query
        $rosterStmt = $this->db->prepare("SELECT u.id, u.reference_code, u.first_name, u.last_name
            FROM users u
            JOIN enrollments e ON e.student_id = u.id
            JOIN classes c ON c.id = e.class_id
            JOIN class_subjects cs ON cs.class_id = c.id
            WHERE c.id = 15 AND cs.teacher_id = 10 AND e.status = 'enrolled' AND u.status = 'active'");
        $rosterStmt->execute();
        $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $roster);
        $this->assertSame(201, (int)$roster[0]['id']);
        $this->assertSame('Jose', $roster[0]['first_name']);
        $this->assertSame('Rizal', $roster[0]['last_name']);

        // 11. Run synchronization a second time (e.g. second page visit)
        syncClassEnrollmentsForClass($this->db, 15);

        // 12. Assert that enrollment record is unchanged and no duplicates exist
        $totalForStudent = (int)$this->db->query("SELECT COUNT(*) FROM enrollments WHERE student_id = 201 AND class_id = 15")->fetchColumn();
        $this->assertSame(1, $totalForStudent, 'Must not create duplicate enrollment records on repeated synchronization');

        $stmt->execute();
        $secondEnrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($enrollmentId, (int)$secondEnrollment['id']);
        $this->assertSame($enrolledAt, (string)$secondEnrollment['enrolled_at']);
    }
}
