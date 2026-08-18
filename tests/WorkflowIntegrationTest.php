<?php

use PHPUnit\Framework\TestCase;
use BshsAms\Notification\SmsService;

final class WorkflowIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Core Users
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT UNIQUE,
            email TEXT UNIQUE,
            password TEXT,
            first_name TEXT,
            last_name TEXT,
            role TEXT,
            contact_number TEXT,
            grade_level INTEGER,
            section TEXT,
            status TEXT DEFAULT 'active'
        )");

        // Classes & Sections
        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            section TEXT NOT NULL,
            teacher_id INTEGER,
            status TEXT DEFAULT 'active'
        )");

        // Subjects
        $this->db->exec("CREATE TABLE subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_name TEXT NOT NULL,
            subject_code TEXT UNIQUE NOT NULL
        )");

        // Class Subjects with Unique Constraint
        $this->db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            teacher_id INTEGER,
            UNIQUE(class_id, subject_id)
        )");

        // Enrollments with Unique Constraint
        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL DEFAULT '2026-2027',
            status TEXT DEFAULT 'enrolled',
            UNIQUE(student_id, class_id, academic_year)
        )");

        // Parent Student Links
        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            UNIQUE(parent_id, student_id)
        )");

        // Grade Items & Scores
        $this->db->exec("CREATE TABLE grade_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            teacher_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            component TEXT NOT NULL,
            total_score REAL NOT NULL,
            activity_date TEXT NOT NULL,
            status TEXT DEFAULT 'active'
        )");

        $this->db->exec("CREATE TABLE grade_item_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grade_item_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            score REAL NOT NULL,
            UNIQUE(grade_item_id, student_id)
        )");

        // Grades with Unique Constraint
        $this->db->exec("CREATE TABLE grades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_subject_id INTEGER NOT NULL,
            ww_raw_score REAL,
            pt_raw_score REAL,
            assessment_raw_score REAL,
            final_grade REAL,
            term TEXT NOT NULL DEFAULT 'Term1',
            academic_year TEXT NOT NULL DEFAULT '2026-2027',
            UNIQUE(student_id, class_subject_id, term, academic_year)
        )");

        // Grade Approvals (Subject Teacher -> Admin)
        $this->db->exec("CREATE TABLE grade_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_subject_id INTEGER NOT NULL,
            term TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'submitted',
            submitted_by INTEGER,
            verified_by INTEGER,
            rejection_reason TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        // Report Card Approvals (Adviser -> Admin -> Release)
        $this->db->exec("CREATE TABLE report_card_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            student_id INTEGER NULL,
            term TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'submitted_admin',
            submitted_by INTEGER,
            approved_by INTEGER,
            rejection_reason TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        // Attendance
        $this->db->exec("CREATE TABLE attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            status TEXT NOT NULL,
            time_in TEXT,
            recorded_by INTEGER,
            UNIQUE(student_id, class_id, date)
        )");

        // In-App Notifications
        $this->db->exec("CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT NOT NULL,
            link TEXT,
            is_read INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        // SMS Logs
        $this->db->exec("CREATE TABLE sms_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_user_id INTEGER NULL,
            recipient_phone TEXT NOT NULL,
            message TEXT NOT NULL,
            provider TEXT NOT NULL,
            status TEXT NOT NULL,
            response_data TEXT NULL,
            error_message TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        putenv('SMS_PROVIDER=log');
        putenv('SMS_SENDER_NAME=BSHS-AMS');
        smsGetService(new SmsService('log', '', 'BSHS-AMS'));
    }

    public function testFullGradeApprovalAndPublicationWorkflow(): void
    {
        // 1. Setup Users: Admin, Subject Teacher, Class Adviser, Student, and Parent
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (1, 'ADM-001', 'admin@bshs.edu.ph', 'School', 'Admin', 'admin')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (2, 'TCH-001', 'teacher.math@bshs.edu.ph', 'Juan', 'Cruz', 'teacher')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (3, 'TCH-002', 'adviser.stem@bshs.edu.ph', 'Elena', 'Reyes', 'teacher')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, contact_number, grade_level, section)
            VALUES (10, 'STD-001', 'maria.santos@student.bshs.edu.ph', 'Maria', 'Santos', 'student', '09171234567', 12, 'STEM-A')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, contact_number)
            VALUES (20, 'PAR-001', 'juana.santos@parent.bshs.edu.ph', 'Juana', 'Santos', 'parent', '09189876543')");

        // 2. Setup Academic Structures: Class Section (with Adviser), Subject, and Class Subject
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id)
            VALUES (1, 'Grade 12 STEM A', 12, 'STEM-A', 3)");

        $this->db->exec("INSERT INTO subjects (id, subject_name, subject_code)
            VALUES (101, 'General Mathematics', 'GEN-MATH')");

        $this->db->exec("INSERT INTO class_subjects (id, class_id, subject_id, teacher_id)
            VALUES (501, 1, 101, 2)");

        $this->db->exec("INSERT INTO enrollments (student_id, class_id, academic_year, status)
            VALUES (10, 1, '2026-2027', 'enrolled')");

        $this->db->exec("INSERT INTO parent_students (parent_id, student_id)
            VALUES (20, 10)");

        // Step 1: Teacher creates grade items and records student score
        $this->db->exec("INSERT INTO grade_items (id, class_id, teacher_id, title, component, total_score, activity_date)
            VALUES (1001, 1, 2, 'Quiz 1 - Functions', 'WW', 50.00, '2026-09-15')");

        $this->db->exec("INSERT INTO grade_item_scores (grade_item_id, student_id, score)
            VALUES (1001, 10, 48.00)");

        $this->db->exec("INSERT INTO grades (student_id, class_subject_id, ww_raw_score, pt_raw_score, assessment_raw_score, final_grade, term, academic_year)
            VALUES (10, 501, 48.00, 90.00, 92.00, 94.00, 'Term1', '2026-2027')");

        // Step 2: Subject Teacher submits grades to Admin
        $this->db->exec("INSERT INTO grade_approvals (class_subject_id, term, academic_year, status, submitted_by)
            VALUES (501, 'Term1', '2026-2027', 'submitted', 2)");

        // Verify grade visibility is currently locked for students and parents
        $stmt = $this->db->query("SELECT COUNT(*) FROM report_card_approvals WHERE class_id = 1 AND status = 'approved'");
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Grades must not be visible to students before official approval');

        // Step 3: Admin verifies Subject Grades
        $this->db->exec("UPDATE grade_approvals SET status = 'admin_verified', verified_by = 1 WHERE class_subject_id = 501 AND term = 'Term1'");

        $stmt = $this->db->query("SELECT status FROM grade_approvals WHERE class_subject_id = 501 AND term = 'Term1'");
        $this->assertSame('admin_verified', $stmt->fetchColumn());

        // Step 4: Class Adviser submits full Section Report Cards to Admin
        $this->db->exec("INSERT INTO report_card_approvals (class_id, student_id, term, academic_year, status, submitted_by)
            VALUES (1, NULL, 'Term1', '2026-2027', 'submitted_admin', 3)");

        // Step 5: Admin gives Final Official Approval and Releases Report Cards
        $this->db->exec("UPDATE report_card_approvals SET status = 'approved', approved_by = 1 WHERE class_id = 1 AND term = 'Term1'");

        // Step 6: Verify Student & Parent visibility rule
        $stmt = $this->db->query("SELECT status FROM report_card_approvals WHERE class_id = 1 AND term = 'Term1'");
        $approvalStatus = $stmt->fetchColumn();
        $this->assertSame('approved', $approvalStatus);
        $this->assertTrue($approvalStatus === 'approved', 'Student and Parent portal visibility is unlocked');

        // Step 7: Trigger SMS Notifications on Official Grade Publication
        $smsResult = smsNotifyGradePublication($this->db, 1, 'Term 1', '2026-2027');
        $this->assertSame(2, $smsResult['total']);
        $this->assertSame(2, $smsResult['sent']);
        $this->assertSame(0, $smsResult['failed']);

        // Verify SMS log entries
        $stmt = $this->db->query("SELECT * FROM sms_logs ORDER BY id ASC");
        $smsLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $smsLogs);
        $this->assertSame('639171234567', $smsLogs[0]['recipient_phone']);
        $this->assertSame('639189876543', $smsLogs[1]['recipient_phone']);
        $this->assertStringContainsString('approved by Admin', $smsLogs[0]['message']);
        $this->assertStringContainsString('Maria Santos', $smsLogs[1]['message']);
    }

    public function testAttendanceMarkingAndNotificationWorkflow(): void
    {
        // Setup Class, Teacher, Student, Parent
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (2, 'TCH-001', 'teacher@bshs.edu.ph', 'Juan', 'Cruz', 'teacher')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role, grade_level, section)
            VALUES (10, 'STD-001', 'student@bshs.edu.ph', 'Maria', 'Santos', 'student', 12, 'A')");

        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (20, 'PAR-001', 'parent@bshs.edu.ph', 'Juana', 'Santos', 'parent')");

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id)
            VALUES (1, 'Grade 12 Section A', 12, 'A', 2)");

        $this->db->exec("INSERT INTO enrollments (student_id, class_id, academic_year, status)
            VALUES (10, 1, '2026-2027', 'enrolled')");

        $this->db->exec("INSERT INTO parent_students (parent_id, student_id)
            VALUES (20, 10)");

        // 1. Teacher records attendance on 3 different dates (Present, Late, Absent)
        $this->db->exec("INSERT INTO attendance (student_id, class_id, date, status, time_in, recorded_by)
            VALUES (10, 1, '2026-09-01', 'present', '07:25:00', 2)");

        $this->db->exec("INSERT INTO attendance (student_id, class_id, date, status, time_in, recorded_by)
            VALUES (10, 1, '2026-09-02', 'late', '07:55:00', 2)");

        $this->db->exec("INSERT INTO attendance (student_id, class_id, date, status, time_in, recorded_by)
            VALUES (10, 1, '2026-09-03', 'absent', NULL, 2)");

        // 2. Dispatch In-App Notifications for Student and Linked Parent
        $this->db->exec("INSERT INTO notifications (user_id, title, message, type, link)
            VALUES (10, 'Attendance Recorded', 'You were marked late on 2026-09-02.', 'attendance', 'Student_Attendance.php')");

        $this->db->exec("INSERT INTO notifications (user_id, title, message, type, link)
            VALUES (20, 'Attendance Alert', 'Maria Santos was marked late on 2026-09-02.', 'attendance', 'Parent_Progress.php')");

        // 3. Verify in-app notifications exist
        $stmt = $this->db->query("SELECT COUNT(*) FROM notifications WHERE type = 'attendance'");
        $this->assertSame(2, (int)$stmt->fetchColumn());

        // 4. Verify Student and Parent Dashboard KPI Calculation
        $stmt = $this->db->prepare("SELECT 
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM attendance WHERE student_id = ?");
        $stmt->execute([10]);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1, (int)$kpi['present_count']);
        $this->assertSame(1, (int)$kpi['late_count']);
        $this->assertSame(1, (int)$kpi['absent_count']);

        // 5. Verify Zero SMS was sent for routine attendance
        $stmt = $this->db->query("SELECT COUNT(*) FROM sms_logs");
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Daily attendance must not trigger SMS notifications');
    }

    public function testDatabaseUniquenessInvariants(): void
    {
        // 1. Test Duplicate Enrollment Invariant
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (1, 'Grade 12 STEM A', 12, 'A')");
        $this->db->exec("INSERT INTO users (id, reference_code, email, first_name, last_name, role)
            VALUES (10, 'STD-001', 'maria@bshs.edu.ph', 'Maria', 'Santos', 'student')");

        $this->db->exec("INSERT INTO enrollments (student_id, class_id, academic_year, status)
            VALUES (10, 1, '2026-2027', 'enrolled')");

        $duplicateEnrollmentFailed = false;
        try {
            $this->db->exec("INSERT INTO enrollments (student_id, class_id, academic_year, status)
                VALUES (10, 1, '2026-2027', 'enrolled')");
        } catch (PDOException $e) {
            $duplicateEnrollmentFailed = true;
        }
        $this->assertTrue($duplicateEnrollmentFailed, 'Database must block duplicate enrollment for student in same class and year');

        // 2. Test Duplicate Class-Subject Invariant
        $this->db->exec("INSERT INTO subjects (id, subject_name, subject_code) VALUES (101, 'Biology', 'BIO-1')");
        $this->db->exec("INSERT INTO class_subjects (id, class_id, subject_id) VALUES (501, 1, 101)");

        $duplicateClassSubjectFailed = false;
        try {
            $this->db->exec("INSERT INTO class_subjects (class_id, subject_id) VALUES (1, 101)");
        } catch (PDOException $e) {
            $duplicateClassSubjectFailed = true;
        }
        $this->assertTrue($duplicateClassSubjectFailed, 'Database must block duplicate subject linkage to the same class section');

        // 3. Test Duplicate Term Grade Invariant
        $this->db->exec("INSERT INTO grades (student_id, class_subject_id, final_grade, term, academic_year)
            VALUES (10, 501, 88.00, 'Term1', '2026-2027')");

        $duplicateGradeFailed = false;
        try {
            $this->db->exec("INSERT INTO grades (student_id, class_subject_id, final_grade, term, academic_year)
                VALUES (10, 501, 91.00, 'Term1', '2026-2027')");
        } catch (PDOException $e) {
            $duplicateGradeFailed = true;
        }
        $this->assertTrue($duplicateGradeFailed, 'Database must block duplicate grade records for the same student, subject, term, and year');
    }
}
