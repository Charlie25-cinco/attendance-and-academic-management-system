<?php

use BshsAms\Notification\SmsService;
use PHPUnit\Framework\TestCase;

final class SmsServiceTest extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT,
            last_name TEXT,
            role TEXT,
            contact_number TEXT,
            grade_level INTEGER,
            section TEXT,
            status TEXT
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            student_id INTEGER,
            status TEXT
        )");

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER,
            student_id INTEGER
        )");

        $this->db->exec("CREATE TABLE report_card_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            academic_year TEXT,
            semester TEXT,
            status TEXT
        )");
    }

    public function testPhilippineNumberNormalization(): void
    {
        // 09 local format
        $this->assertSame('639171234567', SmsService::normalizePhilippineNumber('09171234567'));
        $this->assertSame('09171234567', SmsService::normalizePhilippineNumber('09171234567', '09'));
        $this->assertSame('+639171234567', SmsService::normalizePhilippineNumber('09171234567', 'e164'));

        // +63 format with spaces/dashes
        $this->assertSame('639181234567', SmsService::normalizePhilippineNumber('+63 918-123-4567'));
        $this->assertSame('639191234567', SmsService::normalizePhilippineNumber('639191234567'));
        $this->assertSame('639201234567', SmsService::normalizePhilippineNumber('9201234567'));

        // Invalid inputs
        $this->assertNull(SmsService::normalizePhilippineNumber('12345'));
        $this->assertNull(SmsService::normalizePhilippineNumber('08171234567'));
        $this->assertNull(SmsService::normalizePhilippineNumber('abcdefghijk'));
        $this->assertNull(SmsService::normalizePhilippineNumber(''));
    }

    public function testSmsServiceLogDispatchAndDatabaseLogging(): void
    {
        $service = new SmsService('log', '', 'BSHS-TEST');
        $this->assertTrue($service->isConfigured());
        $this->assertSame('log', $service->getProvider());
        $this->assertSame('BSHS-TEST', $service->getSenderName());

        $res = $service->send('09171234567', 'Hello parent!', 42, $this->db);
        $this->assertTrue($res['success']);
        $this->assertSame('log', $res['provider']);

        $stmt = $this->db->query("SELECT * FROM sms_logs WHERE recipient_user_id = 42");
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
        $this->assertSame('639171234567', $log['recipient_phone']);
        $this->assertSame('Hello parent!', $log['message']);
        $this->assertSame('logged', $log['status']);
    }

    public function testPhilSmsProviderConfiguration(): void
    {
        $service = new SmsService('philsms', 'test-bearer-token', 'BSHS-AMS', 'https://mock.example.com/api/v3/sms/send');
        $this->assertTrue($service->isConfigured());
        $this->assertSame('philsms', $service->getProvider());
    }

    public function testGradePublicationSmsNotificationToParentsAndStudents(): void
    {
        // Set provider to log mode for safe testing
        putenv('SMS_PROVIDER=log');
        putenv('SMS_SENDER_NAME=BSHS-AMS');

        // Create class
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (1, 'Grade 12 STEM', 12, 'A')");

        // Create student & parent
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (10, 'Maria', 'Santos', 'student', '09171112222', 12, 'A', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (20, 'Juana', 'Santos', 'parent', '09183334444', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (1, 10, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (20, 10)");

        // Call smsNotifyGradePublication
        $result = smsNotifyGradePublication($this->db, 1, 'Term 1', '2026-2027');

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);

        // Verify entries in sms_logs
        $stmt = $this->db->query("SELECT * FROM sms_logs ORDER BY id ASC");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $logs);

        // Student log
        $this->assertSame('639171112222', $logs[0]['recipient_phone']);
        $this->assertStringContainsString('Maria Santos', $logs[0]['message']);
        $this->assertStringContainsString('approved by Admin', $logs[0]['message']);
        $this->assertStringContainsString('Term 1', $logs[0]['message']);

        // Parent log
        $this->assertSame('639183334444', $logs[1]['recipient_phone']);
        $this->assertStringContainsString('Maria Santos', $logs[1]['message']);
        $this->assertStringContainsString('approved by Admin', $logs[1]['message']);
    }

    public function testSingleStudentGradeReleaseSms(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (2, 'Grade 11 ABM', 11, 'B')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (30, 'Pedro', 'Penduko', 'student', '09205556666', 11, 'B', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (40, 'Petra', 'Penduko', 'parent', '09217778888', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (2, 30, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (40, 30)");

        $result = smsNotifyGradePublication($this->db, 2, 'Final', '2025-2026', 30);
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['sent']);

        $stmt = $this->db->query("SELECT * FROM sms_logs WHERE recipient_user_id = 40");
        $parentLog = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($parentLog);
        $this->assertStringContainsString('Pedro Penduko', $parentLog['message']);
        $this->assertStringContainsString('Final', $parentLog['message']);
    }
}
