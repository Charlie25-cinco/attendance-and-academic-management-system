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
            name TEXT,
            grade_level INTEGER,
            track TEXT,
            curriculum TEXT,
            program TEXT,
            created_at TEXT,
            UNIQUE (name, grade_level, track)
        )");

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role TEXT,
            status TEXT DEFAULT 'active',
            grade_level INTEGER,
            section TEXT,
            track TEXT
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

        // 2. Section 2: Diamond (1 active student, even though no classes exist yet)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (2, 'Diamond', 11, 'academic')");
        $this->db->exec("INSERT INTO users (id, role, status, grade_level, section, track) VALUES (101, 'student', 'active', 11, 'Diamond', 'academic')");

        // 3. Section 3: Humility (1 active student and non-student accounts that must not be counted)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (3, 'Humility', 11, 'academic')");
        $this->db->exec("INSERT INTO users (id, role, status, grade_level, section, track) VALUES
            (201, 'student', 'active', 11, 'Humility', 'academic'),
            (202, 'parent', 'active', 11, 'Humility', 'academic'),
            (203, 'teacher', 'active', 11, 'Humility', 'academic')");

        // 4. Section 4: Integrity (2 active students; archived students are excluded)
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES (4, 'Integrity', 11, 'techpro')");
        $this->db->exec("INSERT INTO users (id, role, status, grade_level, section, track) VALUES
            (301, 'student', 'active', 11, 'Integrity', 'techpro'),
            (302, 'student', 'active', 11, 'Integrity', 'techpro'),
            (303, 'student', 'archived', 11, 'Integrity', 'techpro')");

        // Execute list query from admin_Sections_Action.php
        $sectionMatch = "LOWER(TRIM(COALESCE(u.section, ''))) = LOWER(TRIM(COALESCE(s.name, '')))";
        $sql = "SELECT s.id, s.name, s.grade_level, s.track, s.curriculum, s.program, s.created_at,
                       (
                           SELECT COUNT(*)
                           FROM users u
                           WHERE u.role = 'student'
                             AND u.status = 'active'
                             AND u.grade_level = s.grade_level
                             AND ({$sectionMatch})
                             AND (u.track IS NULL OR TRIM(u.track) = '' OR LOWER(TRIM(u.track)) = LOWER(TRIM(s.track)))
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

        // Section 3: Humility -> 1 Student (parent and teacher accounts excluded)
        $this->assertSame('Humility', $sections[2]['name']);
        $this->assertSame(1, (int)$sections[2]['student_count']);

        // Section 4: Integrity -> 2 Students (archived student excluded)
        $this->assertSame('Integrity', $sections[3]['name']);
        $this->assertSame(2, (int)$sections[3]['student_count']);
    }

    public function testAdminSectionsListFilteringByGradeAndTrack(): void
    {
        $this->db->exec("INSERT INTO sections (id, name, grade_level, track) VALUES
            (1, 'Emerald', 11, 'academic'),
            (2, 'Integrity', 11, 'techpro'),
            (3, 'Wisdom', 12, 'academic')");

        $this->db->exec("INSERT INTO users (id, role, status, grade_level, section, track) VALUES
            (101, 'student', 'active', 11, 'Emerald', 'academic'),
            (201, 'student', 'active', 11, 'Integrity', 'techpro'),
            (202, 'student', 'active', 11, 'Integrity', 'techpro'),
            (301, 'student', 'active', 12, 'Wisdom', 'academic')");

        $sectionMatch = "LOWER(TRIM(COALESCE(u.section, ''))) = LOWER(TRIM(COALESCE(s.name, '')))";

        $sql = "SELECT s.id, s.name, s.grade_level, s.track, s.curriculum, s.program, s.created_at,
                       (
                           SELECT COUNT(*)
                           FROM users u
                           WHERE u.role = 'student'
                             AND u.status = 'active'
                             AND u.grade_level = s.grade_level
                             AND ({$sectionMatch})
                             AND (u.track IS NULL OR TRIM(u.track) = '' OR LOWER(TRIM(u.track)) = LOWER(TRIM(s.track)))
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
