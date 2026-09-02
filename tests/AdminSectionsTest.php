<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class AdminSectionsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            track TEXT DEFAULT 'academic',
            status TEXT DEFAULT 'active',
            created_at TEXT
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT DEFAULT '2026-2027',
            status TEXT DEFAULT 'enrolled',
            enrolled_at TEXT
        )");
    }

    public function testAdminSectionsPageContainsStudentCountCardStats(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Sections.php');
        $this->assertIsString($html);

        $this->assertStringContainsString('class-card-stats', $html);
        $this->assertStringContainsString('class-card-stat', $html);
        $this->assertStringContainsString('bi-people', $html);
        $this->assertStringContainsString('Students', $html);
    }

    public function testAdminSectionsListActionReturnsAccurateStudentCount(): void
    {
        // 1. Section 1: Emerald (0 students)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (1, 'Emerald', 11, 'academic')");

        // 2. Section 2: Diamond (1 student in 1 class)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (2, 'Diamond', 11, 'academic')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (20, 'Filipino', 11, 'Diamond', 'academic', 'active')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (101, 20, 'enrolled')");

        // 3. Section 3: Humility (1 student enrolled in 3 classes within the same section)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (3, 'Humility', 11, 'academic')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (30, 'Filipino', 11, 'Humility', 'academic', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (31, 'Mathematics', 11, 'Humility', 'academic', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (32, 'General Chemistry', 11, 'Humility', 'academic', 'active')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (201, 30, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (201, 31, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (201, 32, 'enrolled')");

        // 4. Section 4: Integrity (2 enrolled students, 1 dropped enrollment, 1 enrollment in inactive class)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (4, 'Integrity', 11, 'techpro')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (40, 'Computer Systems', 11, 'Integrity', 'techpro', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (41, 'Technical Drafting', 11, 'Integrity', 'techpro', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES (42, 'Archived Subject', 11, 'Integrity', 'techpro', 'inactive')");

        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (301, 40, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (302, 40, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (302, 41, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (303, 40, 'dropped')");
        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES (304, 42, 'enrolled')");

        // Execute list query from admin_Sections_Action.php
        $sectionMatch = "LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(s.name, '')))";
        $sql = "SELECT s.id, s.name, s.grade_level, s.track, s.curriculum, s.program, s.created_at,
                       (
                           SELECT COUNT(DISTINCT e.student_id)
                           FROM enrollments e
                           JOIN classes c ON c.id = e.class_id
                           WHERE e.status = 'enrolled'
                             AND c.status = 'active'
                             AND c.grade_level = s.grade_level
                             AND ({$sectionMatch})
                             AND (c.track IS NULL OR TRIM(c.track) = '' OR LOWER(TRIM(c.track)) = LOWER(TRIM(s.track)))
                       ) AS student_count
                FROM sections s
                ORDER BY s.id ASC";

        $stmt = $this->db->query($sql);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(4, $sections);

        // Section 1: Emerald -> 0 Students
        $this->assertSame('Emerald', $sections[0]['name']);
        $this->assertSame(0, (int)$sections[0]['student_count']);

        // Section 2: Diamond -> 1 Student
        $this->assertSame('Diamond', $sections[1]['name']);
        $this->assertSame(1, (int)$sections[1]['student_count']);

        // Section 3: Humility -> 1 Student (distinct counting across 3 classes)
        $this->assertSame('Humility', $sections[2]['name']);
        $this->assertSame(1, (int)$sections[2]['student_count']);

        // Section 4: Integrity -> 2 Students (excluding dropped and inactive class enrollments)
        $this->assertSame('Integrity', $sections[3]['name']);
        $this->assertSame(2, (int)$sections[3]['student_count']);
    }

    public function testAdminSectionsListFilteringByGradeAndTrack(): void
    {
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES
            (1, 'Emerald', 11, 'academic'),
            (2, 'Integrity', 11, 'techpro'),
            (3, 'Wisdom', 12, 'academic')");

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, track, status) VALUES
            (10, 'Filipino', 11, 'Emerald', 'academic', 'active'),
            (20, 'ICT', 11, 'Integrity', 'techpro', 'active'),
            (30, 'Physics', 12, 'Wisdom', 'academic', 'active')");

        $this->db->exec("INSERT INTO enrollments (student_id, class_id, status) VALUES
            (101, 10, 'enrolled'),
            (201, 20, 'enrolled'),
            (202, 20, 'enrolled'),
            (301, 30, 'enrolled')");

        $sectionMatch = "LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(s.name, '')))";

        $sql = "SELECT s.id, s.name, s.grade_level, s.track, s.curriculum, s.program, s.created_at,
                       (
                           SELECT COUNT(DISTINCT e.student_id)
                           FROM enrollments e
                           JOIN classes c ON c.id = e.class_id
                           WHERE e.status = 'enrolled'
                             AND c.status = 'active'
                             AND c.grade_level = s.grade_level
                             AND ({$sectionMatch})
                             AND (c.track IS NULL OR TRIM(c.track) = '' OR LOWER(TRIM(c.track)) = LOWER(TRIM(s.track)))
                       ) AS student_count
                FROM sections s
                WHERE s.grade_level = :grade_level AND s.track = :track
                ORDER BY s.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':grade_level' => 11, ':track' => 'techpro']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $results);
        $this->assertSame('Integrity', $results[0]['name']);
        $this->assertSame(2, (int)$results[0]['student_count']);
    }
}