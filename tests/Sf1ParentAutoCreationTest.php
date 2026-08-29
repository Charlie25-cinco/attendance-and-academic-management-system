<?php

use PHPUnit\Framework\TestCase;

final class Sf1ParentAutoCreationTest extends TestCase
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
            UNIQUE(parent_id, student_id),
            UNIQUE(student_id)
        )");
    }

    public function testAutoLinkSf1ParentCreatesNewParentAndLinksStudent(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 101;
        $row = [
            'father_name' => 'Dela Cruz, Juan Reyes',
            'mother_name' => 'Santos, Maria Lopez',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09123456789',
        ];

        $result = autoLinkSf1Parent($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_new']);
        $this->assertSame('Father', $result['relationship']);

        // Verify parent in users table
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$result['parent_id']]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($parent);
        $this->assertSame('parent', $parent['role']);
        $this->assertSame('Juan', $parent['first_name']);
        $this->assertSame('Dela Cruz', $parent['last_name']);
        $this->assertSame('09123456789', $parent['contact_number']);

        // Verify link in parent_students table
        $linkStmt = $this->db->prepare("SELECT * FROM parent_students WHERE student_id = ?");
        $linkStmt->execute([$studentId]);
        $link = $linkStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($link);
        $this->assertSame((int)$result['parent_id'], (int)$link['parent_id']);
        $this->assertSame('Father', $link['relationship']);
    }

    public function testAutoLinkSf1ParentReusesExistingParentForSibling(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId1 = 101;
        $studentId2 = 102;

        $row1 = [
            'father_name' => 'Reyes, Pedro Gomez',
            'mother_name' => '',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09223344556',
        ];

        $row2 = [
            'father_name' => 'Reyes, Pedro Gomez',
            'mother_name' => '',
            'guardian_name' => '',
            'relationship' => '',
            'contact_number' => '09223344556',
        ];

        $result1 = autoLinkSf1Parent($this->db, $studentId1, $row1, '2026-2027', $hashedPassword);
        $this->assertTrue($result1['is_new']);

        $result2 = autoLinkSf1Parent($this->db, $studentId2, $row2, '2026-2027', $hashedPassword);
        $this->assertFalse($result2['is_new']);
        $this->assertSame($result1['parent_id'], $result2['parent_id']);

        // Verify only 1 parent user was created
        $count = $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
        $this->assertEquals(1, (int)$count);

        // Verify both students are linked to the same parent
        $links = $this->db->query("SELECT student_id FROM parent_students WHERE parent_id = " . (int)$result1['parent_id'])->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $links);
        $this->assertContains((string)$studentId1, array_map('strval', $links));
        $this->assertContains((string)$studentId2, array_map('strval', $links));
    }

    public function testAutoLinkSf1ParentHandlesGuardian(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
        $studentId = 103;
        $row = [
            'father_name' => '',
            'mother_name' => '',
            'guardian_name' => 'Garcia, Elena Cruz',
            'relationship' => 'Aunt',
            'contact_number' => '09334455667',
        ];

        $result = autoLinkSf1Parent($this->db, $studentId, $row, '2026-2027', $hashedPassword);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_new']);
        $this->assertSame('Aunt', $result['relationship']);

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$result['parent_id']]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($parent);
        $this->assertSame('Elena', $parent['first_name']);
        $this->assertSame('Garcia', $parent['last_name']);
    }
}