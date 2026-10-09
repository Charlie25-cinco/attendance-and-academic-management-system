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
            name_extension TEXT,
            sex TEXT,
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

        // Student profiles remain discoverable from their dedicated Admin workspace.
        $this->assertStringContainsString('href="admin_Enrollments.php"', $usersPhp);
        $this->assertStringContainsString('Student Records', $usersPhp);
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

        // Query students searching by reference code
        $searchRef = 'STU-2026-0001';
        $where = ["u.role = 'student'"];
        $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.lrn LIKE :search OR u.reference_code LIKE :search)";
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $query = "SELECT u.id, u.reference_code, u.first_name, u.last_name, u.lrn, u.sex, u.grade_level, u.section, u.track, u.status, u.created_at
                  FROM users u $whereClause";
        $stmt = $db->prepare($query);
        $stmt->execute([':search' => "%{$searchRef}%"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('STU-2026-0001', $rows[0]['reference_code']);
        $this->assertSame('Juan', $rows[0]['first_name']);
        $this->assertSame('123456789012', $rows[0]['lrn']);

        // Query students searching by LRN
        $searchLrn = '123456789013';
        $stmt = $db->prepare($query);
        $stmt->execute([':search' => "%{$searchLrn}%"]);
        $rowsLrn = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rowsLrn);
        $this->assertSame('STU-2026-0002', $rowsLrn[0]['reference_code']);
        $this->assertSame('Maria', $rowsLrn[0]['first_name']);
        $this->assertSame('123456789013', $rowsLrn[0]['lrn']);
    }

    public function testEnrollmentsGetStudentReturnsReferenceCodeAndEnrolledClasses(): void
    {
        $db = $this->createSqliteDb();

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO users (
                                reference_code, email, lrn, first_name, middle_name, last_name, name_extension,
                                sex, date_of_birth, religion, contact_number, address, house_street, barangay,
                                municipality, province, father_name, mother_name, guardian_name, guardian_relationship,
                                grade_level, section, track, curriculum, program, role, status, created_at, updated_at
                              ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', ?, ?
                              )");
        $stmt->execute([
            'STU-2026-0042', 'pedro.reyes@students.balingasag.edu.ph', '123456789099', 'Pedro', 'Cruz', 'Reyes', 'Jr.',
            'male', '2009-03-14', 'Catholic', '09123456789', 'Poblacion, Balingasag, Misamis Oriental', 'Rizal Street',
            'Poblacion', 'Balingasag', 'Misamis Oriental', 'Roberto Reyes', 'Elena Reyes', 'Elena Reyes', 'Mother',
            11, 'HUMILITY', 'academic', 'strengthened_shs', 'Academic Track', $now, $now,
        ]);
        $studentId = (int)$db->lastInsertId();

        $parentStmt = $db->prepare("INSERT INTO users (reference_code, email, first_name, last_name, contact_number, role, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, 'parent', 'active', ?)");
        $parentStmt->execute(['PAR-2026-0007', 'elena.reyes@example.test', 'Elena', 'Reyes', '09987654321', $now]);
        $parentId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, 'Mother')")
           ->execute([$parentId, $studentId]);

        $db->exec("INSERT INTO classes (class_name, grade_level, section, track, status) VALUES ('General Mathematics', 11, 'HUMILITY', 'academic', 'active')");
        $classId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, semester, status, enrolled_at) VALUES (?, ?, '2025-2026', '1', 'enrolled', ?)")
           ->execute([$studentId, $classId, $now]);

        // Query as in getStudent
        $stmt = $db->prepare("SELECT id, reference_code, first_name, middle_name, last_name, name_extension,
                                     email, lrn, sex, date_of_birth, religion, contact_number,
                                     address, house_street, barangay, municipality, province,
                                     father_name, mother_name, guardian_name, guardian_relationship,
                                     grade_level, section, track, curriculum, program, status,
                                     created_at, updated_at, last_login
                              FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($student);
        $this->assertSame('STU-2026-0042', $student['reference_code']);
        $this->assertSame('123456789099', $student['lrn']);
        $this->assertSame('Pedro', $student['first_name']);
        $this->assertSame('Jr.', $student['name_extension']);
        $this->assertSame('2009-03-14', $student['date_of_birth']);
        $this->assertSame('Rizal Street', $student['house_street']);
        $this->assertSame('Elena Reyes', $student['guardian_name']);
        $this->assertSame('strengthened_shs', $student['curriculum']);

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

        $linkedParentStmt = $db->prepare("SELECT p.reference_code, p.first_name, p.last_name, p.email,
                                                p.contact_number, p.status, ps.relationship
                                         FROM parent_students ps
                                         JOIN users p ON p.id = ps.parent_id AND p.role = 'parent'
                                         WHERE ps.student_id = ?");
        $linkedParentStmt->execute([$studentId]);
        $linkedParents = $linkedParentStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $linkedParents);
        $this->assertSame('PAR-2026-0007', $linkedParents[0]['reference_code']);
        $this->assertSame('Mother', $linkedParents[0]['relationship']);
    }

    public function testEnrollmentModalsAndTableMarkupContainReferenceCodeElements(): void
    {
        $modalsPhp = file_get_contents(__DIR__ . '/../includes/modals/enrollment_modals.php');
        $this->assertIsString($modalsPhp);

        // View Student Modal exposes the complete, responsive, scrollable profile workspace.
        $this->assertStringContainsString('id="viewRefCode"', $modalsPhp);
        $this->assertStringContainsString('id="viewLrn"', $modalsPhp);
        $this->assertStringContainsString('modal-dialog-scrollable modal-xl', $modalsPhp);
        $this->assertStringContainsString('aria-labelledby="viewStudentModalLabel"', $modalsPhp);
        foreach ([
            'viewDateOfBirth', 'viewReligion', 'viewCurriculum', 'viewStudentContact',
            'viewHouseStreet', 'viewBarangay', 'viewMunicipality', 'viewProvince',
            'viewStudentAddress', 'viewFatherName', 'viewMotherName', 'viewGuardianName',
            'viewGuardianRelationship', 'viewLinkedParents', 'viewStudentCreatedAt',
            'viewStudentUpdatedAt', 'viewStudentLastLogin',
        ] as $elementId) {
            $this->assertStringContainsString('id="' . $elementId . '"', $modalsPhp);
        }

        // Edit Student Modal has #editReferenceCode (readonly) and #editLrn
        $this->assertStringContainsString('id="editReferenceCode"', $modalsPhp);
        $this->assertStringContainsString('id="editLrn"', $modalsPhp);

        $enrollmentsPhp = file_get_contents(__DIR__ . '/../admin/admin_Enrollments.php');
        $this->assertIsString($enrollmentsPhp);

        // Table header has Reference Code as primary identifier column
        $this->assertStringContainsString('<th>Reference Code</th>', $enrollmentsPhp);

        // Table rendering populates reference code in first column and LRN in subtitle
        $this->assertStringContainsString('s.reference_code', $enrollmentsPhp);
        $this->assertStringContainsString('s.lrn', $enrollmentsPhp);

        // viewStudent sets viewRefCode
        $this->assertStringContainsString("document.getElementById('viewRefCode').textContent = s.reference_code", $enrollmentsPhp);
        $this->assertStringContainsString("setStudentDetail('viewGuardianName', s.guardian_name)", $enrollmentsPhp);
        $this->assertStringContainsString("document.getElementById('viewLinkedParents')", $enrollmentsPhp);

        // editStudent sets editReferenceCode
        $this->assertStringContainsString("document.getElementById('editReferenceCode')", $enrollmentsPhp);
    }

    public function testStudentDetailResponseExcludesAuthenticationSecrets(): void
    {
        $actionPhp = file_get_contents(__DIR__ . '/../admin/admin_Enrollments_Action.php');
        $this->assertIsString($actionPhp);

        $start = strpos($actionPhp, 'function getStudent(PDO $db): void');
        $end = strpos($actionPhp, 'function updateStudent(PDO $db): void');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $getStudentSource = substr($actionPhp, (int)$start, (int)$end - (int)$start);
        $this->assertStringContainsString("\$student['linked_parents']", $getStudentSource);
        $this->assertStringNotContainsString('password', strtolower($getStudentSource));
        $this->assertStringNotContainsString('token', strtolower($getStudentSource));
    }
}

