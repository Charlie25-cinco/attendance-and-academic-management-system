<?php

use PHPUnit\Framework\TestCase;

final class AdminClassEditRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_GET['action'] = 'test';
    }

    public function testAdminClassEditJsIntegrity(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Class_Edit.php');
        $this->assertIsString($html);

        // Verify populateScheduleFields is called and undefined buildSchedule is removed
        $this->assertStringContainsString('populateScheduleFields();', $html);
        $this->assertStringNotContainsString('buildSchedule();', $html);
        $this->assertStringContainsString('function populateScheduleFields()', $html);

        // Verify Grade Level and Section are rendered as fixed context
        $this->assertStringContainsString('Grade Level <span class="badge bg-secondary-subtle text-muted ms-1" style="font-size: 11px;">Fixed</span>', $html);
        $this->assertStringContainsString('Section <span class="badge bg-secondary-subtle text-muted ms-1" style="font-size: 11px;">Fixed</span>', $html);
        $this->assertStringContainsString('readonly disabled', $html);

        // Verify schedule mode toggle row auto-population
        $this->assertStringContainsString("if (mode === 'specific')", $html);
    }

    public function testUpdateClassBackendWorkflow(): void
    {
        require_once __DIR__ . '/../admin/admin_Classes_Action.php';

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            teacher_id INTEGER,
            schedule TEXT,
            room TEXT,
            ww_weight REAL DEFAULT 25.00,
            pt_weight REAL DEFAULT 50.00,
            assessment_weight REAL DEFAULT 25.00,
            status TEXT DEFAULT 'active',
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT DEFAULT 'strengthened_shs',
            program TEXT DEFAULT 'academic_strengthened',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $db->exec("CREATE TABLE class_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            day TEXT,
            start_time TEXT,
            end_time TEXT,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            teacher_id INTEGER,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT,
            semester INTEGER,
            curriculum TEXT,
            program TEXT,
            status TEXT DEFAULT 'enrolled',
            enrolled_at DATETIME
        )");

        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            role TEXT,
            status TEXT,
            first_name TEXT,
            last_name TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT
        )");

        $db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER,
            action_name TEXT,
            target_type TEXT,
            target_id INTEGER,
            details_json TEXT,
            created_at DATETIME
        )");

        // Insert teacher and student
        $db->exec("INSERT INTO users (id, role, status, first_name, last_name) VALUES (10, 'teacher', 'active', 'Jane', 'Doe')");
        $db->exec("INSERT INTO users (id, role, status, first_name, last_name, grade_level, section, track) VALUES (20, 'student', 'active', 'John', 'Smith', 11, 'Emerald', 'academic')");

        // Insert initial class
        $db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id, schedule, room, ww_weight, pt_weight, assessment_weight, status, subject_category, track)
                   VALUES (1, 'Old Subject', 11, 'Emerald', NULL, 'TBA', 'Room 100', 25.00, 50.00, 25.00, 'active', 'core', 'academic')");

        // 1. Test updating class to new subject, assigned teacher, specific schedule, new room, new weights
        $_POST = [
            'class_id' => '1',
            'class_name' => 'General Mathematics',
            'grade_level' => '11',
            'section' => 'Emerald',
            'teacher_id' => '10',
            'room' => 'Room 205',
            'subject_category' => 'core',
            'track' => 'academic',
            'ww_weight' => '25.00',
            'pt_weight' => '50.00',
            'assessment_weight' => '25.00',
            'schedule_mode' => 'specific',
            'schedule_rows' => json_encode([
                [
                    'day' => 'Mon',
                    'start_hour' => '08',
                    'start_min' => '00',
                    'start_ampm' => 'AM',
                    'end_hour' => '09',
                    'end_min' => '00',
                    'end_ampm' => 'AM'
                ]
            ])
        ];

        ob_start();
        updateClass($db);
        $output = ob_get_clean();

        $res = json_decode($output, true);
        $this->assertIsArray($res, "Output was: " . $output);
        $this->assertTrue($res['success'] ?? false, $res['message'] ?? 'Failed');

        // Verify class record in DB
        $updated = $db->query("SELECT * FROM classes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('General Mathematics', $updated['class_name']);
        $this->assertSame(10, (int)$updated['teacher_id']);
        $this->assertSame('Room 205', $updated['room']);
        $this->assertStringContainsString('Mon', $updated['schedule']);

        // Verify class schedule record
        $schedRows = $db->query("SELECT * FROM class_schedules WHERE class_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $schedRows);
        $this->assertSame('Mon', $schedRows[0]['day']);

        // 2. Test updating to TBA schedule mode without additional sections
        $_POST = [
            'class_id' => '1',
            'class_name' => 'General Mathematics',
            'grade_level' => '11',
            'section' => 'Emerald',
            'teacher_id' => '10',
            'room' => 'Room 205',
            'subject_category' => 'core',
            'track' => 'academic',
            'ww_weight' => '25.00',
            'pt_weight' => '50.00',
            'assessment_weight' => '25.00',
            'schedule_mode' => 'tba',
            'schedule' => 'TBA'
        ];

        ob_start();
        updateClass($db);
        $tbaOutput = ob_get_clean();
        $tbaRes = json_decode($tbaOutput, true);
        $this->assertTrue($tbaRes['success'] ?? false);

        $tbaUpdated = $db->query("SELECT * FROM classes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('TBA', $tbaUpdated['schedule']);
        $tbaSchedRows = $db->query("SELECT * FROM class_schedules WHERE class_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(0, $tbaSchedRows);

        // 3. Test updating class is strictly section-specific and does not modify or create other sections
        $_POST = [
            'class_id' => '1',
            'class_name' => 'General Mathematics',
            'grade_level' => '11',
            'section' => 'Emerald',
            'teacher_id' => '10',
            'room' => 'Room 205',
            'subject_category' => 'core',
            'track' => 'academic',
            'ww_weight' => '25.00',
            'pt_weight' => '50.00',
            'assessment_weight' => '25.00',
            'schedule_mode' => 'tba',
            'schedule' => 'TBA'
        ];

        ob_start();
        updateClass($db);
        $multiOutput = ob_get_clean();
        $multiRes = json_decode($multiOutput, true);
        $this->assertTrue($multiRes['success'] ?? false);
        $this->assertSame('Class updated successfully', $multiRes['message']);

        // Verify only Emerald class exists in DB
        $allClasses = $db->query("SELECT section FROM classes WHERE class_name = 'General Mathematics' ORDER BY section ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Emerald'], $allClasses);
    }
}
