<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class Sf1ReimportAndMultiParentTest extends TestCase
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
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is not available.');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            lrn TEXT NULL,
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT NULL,
            last_name TEXT NOT NULL,
            name_extension TEXT NULL,
            sex TEXT NULL,
            contact_number TEXT NULL,
            date_of_birth TEXT NULL,
            religion TEXT NULL,
            address TEXT NULL,
            house_street TEXT NULL,
            barangay TEXT NULL,
            municipality TEXT NULL,
            province TEXT NULL,
            father_name TEXT NULL,
            mother_name TEXT NULL,
            guardian_name TEXT NULL,
            guardian_relationship TEXT NULL,
            grade_level INTEGER NULL,
            section TEXT NULL,
            track TEXT NULL,
            curriculum TEXT NULL,
            program TEXT NULL,
            role TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            relationship TEXT NULL,
            UNIQUE(parent_id, student_id)
        )");

        $this->db->exec("CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            track TEXT NOT NULL,
            curriculum TEXT NULL,
            program TEXT NULL
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            section TEXT NOT NULL,
            track TEXT NOT NULL,
            curriculum TEXT NULL,
            program TEXT NULL,
            teacher_id INTEGER NULL,
            status TEXT DEFAULT 'active'
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            semester INTEGER NULL,
            curriculum TEXT NULL,
            program TEXT NULL,
            status TEXT DEFAULT 'enrolled',
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, class_id, academic_year)
        )");

        $this->db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER,
            action TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER,
            details TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testReimportOfExistingStudentProcessesFatherMotherAndGuardianWithoutDuplicateStudentAccount(): void
    {
        // 1. Initial import: student with Father only
        $initialRows = [
            [
                'lrn'            => '123456789012',
                'first_name'     => 'Juan',
                'middle_name'    => 'Reyes',
                'last_name'      => 'Dela Cruz',
                'name_extension' => '',
                'sex'            => 'male',
                'birthdate'      => '2008-05-12',
                'house_street'   => 'Purok 1',
                'barangay'       => 'Barangay 1',
                'municipality'   => 'Balingasag',
                'province'       => 'Misamis Oriental',
                'contact_number' => '09123456789',
                'father_name'    => 'Dela Cruz, Pedro Reyes',
                'mother_name'    => '',
                'guardian_name'  => '',
                'relationship'   => '',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ]
        ];

        $firstResult = commitSf1Students($this->db, $initialRows, '2026-2027');
        $this->assertTrue($firstResult['success']);
        $this->assertSame(1, $firstResult['created']);
        $this->assertSame(0, $firstResult['skipped']);
        $this->assertSame(1, $firstResult['parents_created']);
        $this->assertSame(1, $firstResult['parents_linked']);

        $studentId = (int)$this->db->query("SELECT id FROM users WHERE lrn = '123456789012'")->fetchColumn();
        $this->assertGreaterThan(0, $studentId);

        // Verify initial state: 1 student, 1 parent, 1 link
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM parent_students WHERE student_id = {$studentId}")->fetchColumn());

        // 2. Re-import: same LRN, now containing Father + Mother + Guardian
        $reimportRows = [
            [
                'lrn'            => '123456789012',
                'first_name'     => 'Juan',
                'middle_name'    => 'Reyes',
                'last_name'      => 'Dela Cruz',
                'name_extension' => '',
                'sex'            => 'male',
                'birthdate'      => '2008-05-12',
                'house_street'   => 'Purok 1',
                'barangay'       => 'Barangay 1',
                'municipality'   => 'Balingasag',
                'province'       => 'Misamis Oriental',
                'contact_number' => '09123456789',
                'father_name'    => 'Dela Cruz, Pedro Reyes',
                'mother_name'    => 'Santos, Maria Lopez',
                'guardian_name'  => 'Bautista, Ricardo Cruz',
                'relationship'   => 'Uncle',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ]
        ];

        $secondResult = commitSf1Students($this->db, $reimportRows, '2026-2027');
        $this->assertTrue($secondResult['success']);
        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(1, $secondResult['skipped']);
        $this->assertSame(2, $secondResult['parents_created'], 'Mother and Guardian should be newly created');
        $this->assertSame(3, $secondResult['parents_linked'], 'Father, Mother, and Guardian should all be linked');

        // Verify student was NOT duplicated
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn());

        // Verify all 3 parents exist in users table
        $this->assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn());

        // Verify all 3 links exist in parent_students
        $links = $this->db->query("SELECT * FROM parent_students WHERE student_id = {$studentId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $links);
        $relationships = array_column($links, 'relationship');
        $this->assertContains('Father', $relationships);
        $this->assertContains('Mother', $relationships);
        $this->assertContains('Uncle', $relationships);
    }

    public function testNonDestructiveFieldBackfillPreservesExistingDataWhenSf1HasBlanks(): void
    {
        // Seed student with existing contact number and house street
        $this->db->exec("INSERT INTO users (
            reference_code, email, lrn, password, first_name, last_name,
            contact_number, house_street, barangay, municipality, province, address,
            father_name, mother_name, grade_level, section, track, role, status
        ) VALUES (
            'STU-2026-0001', 'juan.delacruz.123456789012@students.balingasag.edu.ph', '123456789012', 'hash', 'Juan', 'Dela Cruz',
            '09111111111', 'House 42', 'Poblacion', 'Balingasag', 'Misamis Oriental', 'House 42, Poblacion, Balingasag, Misamis Oriental',
            '', '', 11, 'Humility', 'academic', 'student', 'active'
        )");

        $studentId = (int)$this->db->lastInsertId();

        // Re-import with blank contact and house street, but with father name
        $reimportRows = [
            [
                'lrn'            => '123456789012',
                'first_name'     => 'Juan',
                'last_name'      => 'Dela Cruz',
                'house_street'   => '',
                'barangay'       => '',
                'municipality'   => '',
                'province'       => '',
                'address'        => '',
                'contact_number' => '',
                'father_name'    => 'Dela Cruz, Pedro Reyes',
                'mother_name'    => '',
                'guardian_name'  => '',
                'relationship'   => '',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ]
        ];

        $result = commitSf1Students($this->db, $reimportRows, '2026-2027');
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['skipped']);

        // Verify existing contact number and house street were preserved
        $user = $this->db->query("SELECT * FROM users WHERE id = {$studentId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('09111111111', $user['contact_number'], 'Existing contact number must not be erased by empty value');
        $this->assertSame('House 42', $user['house_street'], 'Existing house street must not be erased by empty value');
        $this->assertSame('Dela Cruz, Pedro Reyes', $user['father_name'], 'Father name should be backfilled');
    }

    public function testRepeatedReimportIsStrictlyIdempotent(): void
    {
        $rows = [
            [
                'lrn'            => '123456789012',
                'first_name'     => 'Juan',
                'last_name'      => 'Dela Cruz',
                'father_name'    => 'Dela Cruz, Pedro Reyes',
                'mother_name'    => 'Santos, Maria Lopez',
                'guardian_name'  => '',
                'relationship'   => '',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ]
        ];

        // First import
        commitSf1Students($this->db, $rows, '2026-2027');

        // Second import
        $second = commitSf1Students($this->db, $rows, '2026-2027');
        $this->assertSame(0, $second['parents_created']);
        $this->assertSame(2, $second['parents_linked']);

        // Third import
        $third = commitSf1Students($this->db, $rows, '2026-2027');
        $this->assertSame(0, $third['parents_created']);
        $this->assertSame(2, $third['parents_linked']);

        // Verify exact counts
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn());
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn());
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM parent_students")->fetchColumn());
    }

    public function testNonStudentInstructionalAndFooterRowsAreIgnored(): void
    {
        $mixedRows = [
            [
                'lrn'            => '123456789012',
                'first_name'     => 'Juan',
                'last_name'      => 'Dela Cruz',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ],
            [
                'lrn'            => '',
                'first_name'     => '',
                'last_name'      => 'Information Required',
                'grade_level'    => 0,
                'section'        => '',
            ],
            [
                'lrn'            => '',
                'first_name'     => '',
                'last_name'      => 'SUMMARY TABLE',
                'grade_level'    => 0,
                'section'        => '',
            ],
            [
                'lrn'            => '',
                'first_name'     => '',
                'last_name'      => 'Prepared by: Teacher Name',
                'grade_level'    => 0,
                'section'        => '',
            ],
            [
                'lrn'            => '',
                'first_name'     => '',
                'last_name'      => '',
                'grade_level'    => 0,
                'section'        => '',
            ]
        ];

        $result = commitSf1Students($this->db, $mixedRows, '2026-2027');
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['errors'], 'Non-student instructional/footer rows must be ignored, not counted as errors');

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn());
    }

    public function testStrictLrnValidationPreservedForActualLearnerRows(): void
    {
        $invalidLrnRows = [
            [
                'lrn'            => '12345678', // 8 digits instead of 12
                'first_name'     => 'Bad',
                'last_name'      => 'Student',
                'grade_level'    => 11,
                'section'        => 'Humility',
                'track'          => 'academic',
            ]
        ];

        $result = commitSf1Students($this->db, $invalidLrnRows, '2026-2027');
        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('LRN must be exactly 12 digits.', $result['rows'][0]['message']);
    }
}
