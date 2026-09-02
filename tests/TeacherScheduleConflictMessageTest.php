<?php

declare(strict_types=1);

namespace Tests;

use BshsAms\User\UserValidationHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class TeacherScheduleConflictMessageTest extends TestCase
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
        require_once __DIR__ . '/../admin/admin_Users_Action.php';
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
            first_name TEXT,
            last_name TEXT,
            role TEXT,
            status TEXT DEFAULT 'active',
            grade_level INTEGER,
            section TEXT
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level TEXT,
            section TEXT,
            schedule TEXT,
            status TEXT DEFAULT 'active'
        )");

        $this->db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            teacher_id INTEGER
        )");
    }

    public function testNewTeacherScheduleConflictMessageIncludesGradeAndSection(): void
    {
        // Insert 2 classes of same subject name with overlapping schedule
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, schedule, status) VALUES
            (1, 'Life And Career Skills', '7', 'Section A', 'Mon 8:00 AM - 9:00 AM', 'active'),
            (2, 'Life And Career Skills', '7', 'Section B', 'Mon 8:30 AM - 9:30 AM', 'active')");

        $conflict = checkScheduleConflictsForNewTeacher($this->db, 0, [1, 2]);

        $this->assertNotNull($conflict);
        $this->assertSame(
            'Schedule conflict: Life And Career Skills (Grade 7 - Section A) overlaps with Life And Career Skills (Grade 7 - Section B)',
            $conflict
        );
    }

    public function testExistingTeacherScheduleConflictMessageIncludesGradeAndSection(): void
    {
        // Insert teacher and existing class assignment
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, status) VALUES (10, 'Maria', 'Santos', 'teacher', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, schedule, status) VALUES
            (1, 'Life And Career Skills', '7', 'Section A', 'Mon 8:00 AM - 9:00 AM', 'active'),
            (2, 'Life And Career Skills', '7', 'Section B', 'Mon 8:30 AM - 9:30 AM', 'active')");
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (1, 10)");

        // Assign class 2 to teacher 10 who already has class 1
        $conflict = checkScheduleConflicts($this->db, 10, [2]);

        $this->assertNotNull($conflict);
        $this->assertSame(
            'Schedule conflict: Life And Career Skills (Grade 7 - Section A) (existing) overlaps with Life And Career Skills (Grade 7 - Section B) (new)',
            $conflict
        );
    }

    public function testNonOverlappingSchedulesProduceNoConflict(): void
    {
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, schedule, status) VALUES
            (1, 'Life And Career Skills', '7', 'Section A', 'Mon 8:00 AM - 9:00 AM', 'active'),
            (3, 'Life And Career Skills', '7', 'Section C', 'Tue 1:00 PM - 2:00 PM', 'active')");

        $conflict = checkScheduleConflictsForNewTeacher($this->db, 0, [1, 3]);
        $this->assertNull($conflict);
    }

    public function testTeacherSubjectConflictMessageIncludesGradeAndSection(): void
    {
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, status) VALUES (10, 'Maria', 'Santos', 'teacher', 'active')");
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, schedule, status) VALUES
            (1, 'Life And Career Skills', '7', 'Section A', 'Mon 8:00 AM - 9:00 AM', 'active')");
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (1, 10)");

        $conflicts = UserValidationHelper::getTeacherSubjectConflicts($this->db, [1], 0);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('Life And Career Skills (Grade 7 - Section A)', $conflicts[0]);
        $this->assertStringContainsString('Maria Santos', $conflicts[0]);
    }
}
