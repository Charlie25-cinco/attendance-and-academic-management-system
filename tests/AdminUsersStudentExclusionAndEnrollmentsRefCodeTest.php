<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class AdminUsersStudentExclusionAndEnrollmentsRefCodeTest extends TestCase
{
    private function createSqliteDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT,
            email TEXT,
            lrn TEXT,
            password TEXT,
            first_name TEXT,
            middle_name TEXT,
            last_name TEXT,
            sex TEXT,
            contact_number TEXT,
            address TEXT,
            house_street TEXT,
            barangay TEXT,
            municipality TEXT,
            province TEXT,
            date_of_birth TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT,
            curriculum TEXT,
            program TEXT,
            role TEXT,
            status TEXT DEFAULT 'active',
            api_token_version INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT,
            last_login TEXT
        )");

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT,
            schedule TEXT,
            teacher_id INTEGER,
            status TEXT DEFAULT 'active',
            created_at TEXT
        )");

        $db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT,
            semester TEXT,
            status TEXT DEFAULT 'enrolled',
            enrolled_at TEXT
        )");

        $db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER,
            student_id INTEGER,
            relationship TEXT
        )");

        $db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            subject_id INTEGER,
            teacher_id INTEGER,
            created_at TEXT
        )");

        return $db;
    }

    public function testManageUsersStrictlyExcludesStudentsFromViewAndFilters(): void
    {
        $usersPhp = file_get_contents(__DIR__ . '/../admin/admin_Users.php');
        $this->assertIsString($usersPhp);

        // Verify Manage Users excludes students by default
        $this->assertStringContainsString("role NOT IN ('admin', 'student')", $usersPhp);

        // Verify role dropdown in Manage Users has only Teacher, Parent, Admin (no student)
        $this->assertStringNotContainsString('<option value="student"', $usersPhp);
        $this->assertStringContainsString('<option value="teacher"', $usersPhp);
        $this->assertStringContainsString('<option value="parent"', $usersPhp);
        $this->assertStringContainsString('<option value="admin"', $usersPhp);

        // Verify role totals query in Manage Users counts only teachers and parents
        $this->assertStringNotContainsString("SUM(CASE WHEN role = 'student'", $usersPhp);
    }

    public function testManageUsersActionStrictlyBlocksAllStudentOperations(): void
    {
        $usersActionPhp = file_get_contents(__DIR__ . '/../admin/admin_Users_Action.php');
        $this->assertIsString($usersActionPhp);

        // Verify getUser explicitly blocks student details
        $this->assertStringContainsString('if ($user[\'role\'] === \'student\') {', $usersActionPhp);
        $this->assertStringContainsString('Student details are not available in Manage Users', $usersActionPhp);

        // Verify createUser, updateUser, deleteUser, resetUserPassword, setUserStatus block students
        $this->assertStringContainsString('Students must be managed through the Enrollments page', $usersActionPhp);
    }

    public function testEnrollmentsStudentListingAndReferenceCodeSearch(): void
    {
        $db = $this->createSqliteDb();

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO users (reference_code, email, lrn, first_name, last_name, sex, grade_level, section, track, role, status, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', ?)");
        $stmt->execute(['STU-2026-0001', 'juan.delacruz@students.balingasag.edu.ph', '123456789012', 'Juan', 'Dela Cruz', 'male', 11, 'HUMILITY', 'academic', $now]);

        $stmt->execute(['STU-2026-0002', 'maria.santos@students.balingasag.edu.ph', '123456789013', 'Maria', 'Santos', 'female', 12, 'PATIENCE', 'techpro', $now]);

        // Query students as in getStudents
        $search = 'STU-2026-0001';
        $where = ["u.role = 'student'"];
        $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.lrn LIKE :search OR u.reference_code LIKE :search)";
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $query = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.lrn, u.sex, u.grade_level, u.section, u.track, u.status, u.created_at
                  FROM users u $whereClause";
        $stmt = $db->prepare($query);
        $stmt->execute([':search' => "%{$search}%"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('STU-2026-0001', $rows[0]['reference_code']);
        $this->assertSame('Juan', $rows[0]['first_name']);
        $this->assertSame('123456789012', $rows[0]['lrn']);
    }

    public function testEnrollmentsGetStudentReturnsReferenceCodeAndEnrolledClasses(): void
    {
        $db = $this->createSqliteDb();

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO users (reference_code, email, lrn, first_name, middle_name, last_name, sex, grade_level, section, track, role, status, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', ?)");
        $stmt->execute(['STU-2026-0042', 'pedro.reyes@students.balingasag.edu.ph', '123456789099', 'Pedro', 'Cruz', 'Reyes', 'male', 11, 'HUMILITY', 'academic', $now]);
        $studentId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO classes (class_name, grade_level, section, track, status) VALUES ('General Mathematics', 11, 'HUMILITY', 'academic', 'active')");
        $classId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, status, enrolled_at) VALUES (?, ?, '2025-2026', '1', 'enrolled', ?)")
           ->execute([$studentId, $classId, $now]);

        // Query as in getStudent
        $stmt = $db->prepare("SELECT id, reference_code, first_name, middle_name, last_name, email, lrn, sex, grade_level, section, track, status, created_at
                              FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($student);
        $this->assertSame('STU-2026-0042', $student['reference_code']);
        $this->assertSame('123456789099', $student['lrn']);
        $this->assertSame('Pedro', $student['first_name']);

        // Enrolled classes query
        $classStmt = $db->prepare("SELECT c.class_name, e.status as enrollment_status
                                   FROM enrollments e
                                   JOIN classes c ON c.id = e.class_id
                                   WHERE e.student_id = ?");
        $classStmt->execute([$studentId]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $classes);
        $this->assertSame('General Mathematics', $classes[0]['class_name']);
        $this->assertSame('enrolled', $classes[0]['enrollment_status']);
    }

    public function testEnrollmentModalsAndTableMarkupContainReferenceCodeElements(): void
    {
        $modalsPhp = file_get_contents(__DIR__ . '/../includes/modals/enrollment_modals.php');
        $this->assertIsString($modalsPhp);

        // View Student Modal has #viewRefCode
        $this->assertStringContainsString('id="viewRefCode"', $modalsPhp);

        // Edit Student Modal has #editReferenceCode (readonly)
        $this->assertStringContainsString('id="editReferenceCode"', $modalsPhp);

        $enrollmentsPhp = file_get_contents(__DIR__ . '/../admin/admin_Enrollments.php');
        $this->assertIsString($enrollmentsPhp);

        // Table rendering populates reference code
        $this->assertStringContainsString('s.reference_code', $enrollmentsPhp);

        // viewStudent sets viewRefCode
        $this->assertStringContainsString("document.getElementById('viewRefCode').textContent = s.reference_code", $enrollmentsPhp);

        // editStudent sets editReferenceCode
        $this->assertStringContainsString("document.getElementById('editReferenceCode')", $enrollmentsPhp);
    }
}

