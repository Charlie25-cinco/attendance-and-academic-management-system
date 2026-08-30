<?php

use PHPUnit\Framework\TestCase;

final class Sf1ImportPreviewTest extends TestCase
{
    private PDO $db;

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
            date_of_birth TEXT NULL,
            religion TEXT NULL,
            contact_number TEXT NULL,
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

        $this->db->exec("CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            grade_level INTEGER NOT NULL,
            track TEXT NOT NULL,
            curriculum TEXT NULL,
            program TEXT NULL
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            semester INTEGER DEFAULT 1,
            status TEXT DEFAULT 'enrolled'
        )");

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            relationship TEXT NULL,
            UNIQUE(parent_id, student_id),
            UNIQUE(student_id)
        )");

        $this->db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            action_name TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NULL,
            details TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testEnrollmentsPageContainsEditablePreviewModalAndControls(): void
    {
        $page = file_get_contents(__DIR__ . '/../admin/admin_Enrollments.php');
        $this->assertIsString($page);

        // Modal structure
        $this->assertStringContainsString('id="importModal"', $page);
        $this->assertStringContainsString('id="importUploadStage"', $page);
        $this->assertStringContainsString('id="importPreviewStage"', $page);
        $this->assertStringContainsString('id="previewTable"', $page);
        $this->assertStringContainsString('id="previewTableBody"', $page);

        // Preview stats and controls
        $this->assertStringContainsString('id="previewGradeLevel"', $page);
        $this->assertStringContainsString('id="previewSection"', $page);
        $this->assertStringContainsString('id="previewTrack"', $page);
        $this->assertStringContainsString('id="previewSchoolYear"', $page);
        $this->assertStringContainsString('id="previewStatTotal"', $page);
        $this->assertStringContainsString('id="previewStatMale"', $page);
        $this->assertStringContainsString('id="previewStatFemale"', $page);
        $this->assertStringContainsString('id="previewStatNew"', $page);
        $this->assertStringContainsString('id="previewStatExisting"', $page);
        $this->assertStringContainsString('id="previewStatInvalid"', $page);
        $this->assertStringContainsString('id="previewSearchFilter"', $page);

        // JS Functions
        $this->assertStringContainsString('function previewImport()', $page);
        $this->assertStringContainsString('function renderPreviewTable()', $page);
        $this->assertStringContainsString('function updateStudentField(', $page);
        $this->assertStringContainsString('function addPreviewRow()', $page);
        $this->assertStringContainsString('function removePreviewRow(', $page);
        $this->assertStringContainsString('function commitImport()', $page);
    }

    public function testSf1ImportActionFileContainsPreviewAndCommitRoutes(): void
    {
        $action = file_get_contents(__DIR__ . '/../admin/admin_SF1_Import_Action.php');
        $this->assertIsString($action);

        $this->assertStringContainsString("\$action === 'preview'", $action);
        $this->assertStringContainsString("\$action === 'commit'", $action);
        $this->assertStringContainsString('parseSf1UploadedFile(', $action);
        $this->assertStringContainsString('ensureSf1Section(', $action);
        $this->assertStringContainsString('autoLinkSf1Parent(', $action);
        $this->assertStringContainsString('recordAdminAuditLog(', $action);
    }

    public function testCommitCreatesStudentParentAndSectionRecords(): void
    {
        $students = [
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
                'father_name'    => 'Pedro Dela Cruz',
                'mother_name'    => 'Maria Dela Cruz',
                'guardian_name'  => '',
                'relationship'   => 'Father',
                'grade_level'    => 11,
                'section'        => 'STEM-A',
                'track'          => 'academic',
            ],
            [
                'lrn'            => '987654321098',
                'first_name'     => 'Maria',
                'middle_name'    => 'Santos',
                'last_name'      => 'Garcia',
                'name_extension' => '',
                'sex'            => 'female',
                'birthdate'      => '2008-09-20',
                'house_street'   => 'Purok 2',
                'barangay'       => 'Barangay 2',
                'municipality'   => 'Balingasag',
                'province'       => 'Misamis Oriental',
                'contact_number' => '09987654321',
                'father_name'    => 'Jose Garcia',
                'mother_name'    => 'Ana Garcia',
                'guardian_name'  => '',
                'relationship'   => 'Mother',
                'grade_level'    => 11,
                'section'        => 'STEM-A',
                'track'          => 'academic',
            ]
        ];

        $academicYear = '2025-2026';
        $hashedPassword = password_hash('default123', PASSWORD_BCRYPT);
        $createdCount = 0;

        foreach ($students as $s) {
            $refCode = generateReferenceCode('student', $this->db, $academicYear);
            $email = strtolower($s['first_name'] . '.' . $s['last_name'] . '.' . $s['lrn'] . '@students.balingasag.edu.ph');

            // Ensure section
            $secStmt = $this->db->prepare("SELECT id FROM sections WHERE name = ? LIMIT 1");
            $secStmt->execute([$s['section']]);
            $secId = $secStmt->fetchColumn();
            if (!$secId) {
                $insSec = $this->db->prepare("INSERT INTO sections (name, grade_level, track) VALUES (?, ?, ?)");
                $insSec->execute([$s['section'], $s['grade_level'], $s['track']]);
                $secId = (int)$this->db->lastInsertId();
            }

            // Insert user
            $ins = $this->db->prepare("INSERT INTO users 
                (reference_code, email, lrn, password, first_name, middle_name, last_name, sex, date_of_birth, house_street, barangay, municipality, province, father_name, mother_name, grade_level, section, track, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student')");
            $ins->execute([
                $refCode, $email, $s['lrn'], $hashedPassword,
                $s['first_name'], $s['middle_name'], $s['last_name'],
                $s['sex'], $s['birthdate'], $s['house_street'], $s['barangay'],
                $s['municipality'], $s['province'], $s['father_name'], $s['mother_name'],
                $s['grade_level'], $s['section'], $s['track']
            ]);
            $studentId = (int)$this->db->lastInsertId();

            // Link parent
            $parentLink = autoLinkSf1Parent($this->db, $studentId, $s, $academicYear, $hashedPassword);
            $this->assertNotNull($parentLink);
            $createdCount++;
        }

        $this->assertSame(2, $createdCount);

        // Verify users count
        $studentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
        $this->assertSame(2, $studentCount);

        $parentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertSame(2, $parentCount);

        $linkCount = (int)$this->db->query("SELECT COUNT(*) FROM parent_students")->fetchColumn();
        $this->assertSame(2, $linkCount);

        $sectionCount = (int)$this->db->query("SELECT COUNT(*) FROM sections WHERE name = 'STEM-A'")->fetchColumn();
        $this->assertSame(1, $sectionCount);
    }
}
