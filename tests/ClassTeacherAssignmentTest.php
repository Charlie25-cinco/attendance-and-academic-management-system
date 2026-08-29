<?php

use PHPUnit\Framework\TestCase;

final class ClassTeacherAssignmentTest extends TestCase
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
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            role TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            section TEXT NOT NULL,
            teacher_id INTEGER NULL,
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT NULL,
            program TEXT NULL,
            schedule TEXT NULL,
            room TEXT NULL,
            ww_weight REAL DEFAULT 25.00,
            pt_weight REAL DEFAULT 50.00,
            assessment_weight REAL DEFAULT 25.00,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NULL,
            teacher_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE class_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            academic_year TEXT NULL,
            semester INTEGER NULL,
            curriculum TEXT NULL,
            program TEXT NULL,
            status TEXT DEFAULT 'enrolled',
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER NULL,
            action_name TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NULL,
            details_json TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Seed an active teacher
        $this->db->exec("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                         VALUES ('T27-1', 't27-1@balingasag.edu.ph', 'hash', 'Maria', 'Santos', 'teacher', 'active')");
        $this->db->exec("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                         VALUES ('T27-2', 't27-2@balingasag.edu.ph', 'hash', 'Juan', 'Dela Cruz', 'teacher', 'active')");
    }

    public function testClassCreationWithTeacherStoresTeacherIdAndClassSubject(): void
    {
        $teacherId = 1;
        $className = 'General Mathematics';
        $gradeLevel = 11;
        $section = 'Ruby';

        $stmt = $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id, status)
                                   VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$className, $gradeLevel, $section, $teacherId]);
        $classId = (int)$this->db->lastInsertId();

        $csStmt = $this->db->prepare("INSERT INTO class_subjects (class_id, teacher_id) VALUES (?, ?)");
        $csStmt->execute([$classId, $teacherId]);

        // Verify class teacher_id
        $class = $this->db->query("SELECT * FROM classes WHERE id = $classId")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$class['teacher_id']);

        // Verify class_subjects mapping
        $cs = $this->db->query("SELECT * FROM class_subjects WHERE class_id = $classId")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$cs['teacher_id']);
    }

    public function testClassUpdateReassignsTeacher(): void
    {
        // Create initial class with teacher 1
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id, status)
                         VALUES (10, 'Oral Communication', 11, 'Emerald', 1, 'active')");
        $this->db->exec("INSERT INTO class_subjects (class_id, teacher_id) VALUES (10, 1)");

        // Reassign to teacher 2
        $newTeacherId = 2;
        $this->db->prepare("UPDATE classes SET teacher_id = ? WHERE id = 10")->execute([$newTeacherId]);
        $this->db->prepare("DELETE FROM class_subjects WHERE class_id = 10")->execute();
        $this->db->prepare("INSERT INTO class_subjects (class_id, teacher_id) VALUES (10, ?)")->execute([$newTeacherId]);

        $class = $this->db->query("SELECT * FROM classes WHERE id = 10")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$class['teacher_id']);

        $cs = $this->db->query("SELECT * FROM class_subjects WHERE class_id = 10")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$cs['teacher_id']);
    }
}