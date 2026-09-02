<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class Sf1MultiParentAutoCreationTest extends TestCase
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
    }

    public function testFatherOnlyRowCreatesSingleFatherAccountAndLink(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 101;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => '',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $results = autoLinkSf1Parents($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['is_new']);
        $this->assertSame('Father', $results[0]['relationship']);

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$results[0]['parent_id']]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($parent);
        $this->assertSame('parent', $parent['role']);
        $this->assertSame('Juan', $parent['first_name']);
        $this->assertSame('Dela Cruz', $parent['last_name']);
        $this->assertSame('male', $parent['sex']);
        $this->assertSame('09123456789', $parent['contact_number']);

        $links = $this->db->query("SELECT * FROM parent_students WHERE student_id = {$studentId}")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $links);
        $this->assertSame((int)$results[0]['parent_id'], (int)$links[0]['parent_id']);
        $this->assertSame('Father', $links[0]['relationship']);
    }

    public function testMotherOnlyRowCreatesSingleMotherAccountAndLink(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 102;
        $row = [
            'father_name' => '',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09223344556',
        ];

        $results = autoLinkSf1Parents($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['is_new']);
        $this->assertSame('Mother', $results[0]['relationship']);

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$results[0]['parent_id']]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($parent);
        $this->assertSame('parent', $parent['role']);
        $this->assertSame('Maria', $parent['first_name']);
        $this->assertSame('Santos', $parent['last_name']);
        $this->assertSame('female', $parent['sex']);

        $links = $this->db->query("SELECT * FROM parent_students WHERE student_id = {$studentId}")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $links);
        $this->assertSame((int)$results[0]['parent_id'], (int)$links[0]['parent_id']);
        $this->assertSame('Mother', $links[0]['relationship']);
    }

    public function testFatherAndMotherRowCreatesTwoDistinctParentAccountsAndLinksBoth(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 103;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $results = autoLinkSf1Parents($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertCount(2, $results);

        // Father assertions
        $this->assertTrue($results[0]['is_new']);
        $this->assertSame('Father', $results[0]['relationship']);

        // Mother assertions
        $this->assertTrue($results[1]['is_new']);
        $this->assertSame('Mother', $results[1]['relationship']);

        // Assert distinct parent users
        $this->assertNotSame($results[0]['parent_id'], $results[1]['parent_id']);
        $this->assertNotSame($results[0]['parent_ref_code'], $results[1]['parent_ref_code']);

        $parentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertSame(2, $parentCount);

        // Verify both links in parent_students
        $links = $this->db->query("SELECT * FROM parent_students WHERE student_id = {$studentId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $links);
        $this->assertSame((int)$results[0]['parent_id'], (int)$links[0]['parent_id']);
        $this->assertSame('Father', $links[0]['relationship']);
        $this->assertSame((int)$results[1]['parent_id'], (int)$links[1]['parent_id']);
        $this->assertSame('Mother', $links[1]['relationship']);
    }

    public function testFatherMotherAndGuardianRowCreatesThreeDistinctParentAccountsAndLinksAllThree(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 104;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => 'Bautista, Ricardo Cruz',
            'relationship' => 'Uncle',
            'contact_number' => '09123456789',
        ];

        $results = autoLinkSf1Parents($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertCount(3, $results);
        $this->assertSame('Father', $results[0]['relationship']);
        $this->assertSame('Mother', $results[1]['relationship']);
        $this->assertSame('Uncle', $results[2]['relationship']);

        $parentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertSame(3, $parentCount);

        $links = $this->db->query("SELECT * FROM parent_students WHERE student_id = {$studentId}")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $links);
    }

    public function testDuplicateParentNameInSameRowIsDeduplicated(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 105;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => '',
            'guardian_name' => 'Dela Cruz, Juan Reyes',
            'relationship' => 'Father',
            'contact_number' => '09123456789',
        ];

        $results = autoLinkSf1Parents($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertCount(1, $results);

        $parentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertSame(1, $parentCount);

        $linkCount = (int)$this->db->query("SELECT COUNT(*) FROM parent_students WHERE student_id = {$studentId}")->fetchColumn();
        $this->assertSame(1, $linkCount);
    }

    public function testSiblingReusesBothExistingFatherAndMotherAccounts(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId1 = 106;
        $studentId2 = 107;

        $row1 = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $row2 = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $results1 = autoLinkSf1Parents($this->db, $studentId1, $row1, '2026-2027', $hashedPassword);
        $this->assertTrue($results1[0]['is_new']);
        $this->assertTrue($results1[1]['is_new']);

        $results2 = autoLinkSf1Parents($this->db, $studentId2, $row2, '2026-2027', $hashedPassword);
        $this->assertFalse($results2[0]['is_new']);
        $this->assertFalse($results2[1]['is_new']);
        $this->assertSame($results1[0]['parent_id'], $results2[0]['parent_id']);
        $this->assertSame($results1[1]['parent_id'], $results2[1]['parent_id']);

        // Exactly 2 parent users in total
        $parentCount = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertSame(2, $parentCount);

        // 4 links in parent_students table (2 per student)
        $linkCount = (int)$this->db->query("SELECT COUNT(*) FROM parent_students")->fetchColumn();
        $this->assertSame(4, $linkCount);
    }

    public function testLegacyAutoLinkSf1ParentReturnsFirstLinkedParent(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 108;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $result = autoLinkSf1Parent($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertNotNull($result);
        $this->assertSame('Father', $result['relationship']);
        $this->assertTrue($result['is_new']);
    }
}

