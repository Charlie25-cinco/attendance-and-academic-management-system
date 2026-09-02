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

    public function testSingleStudentGradePublicationDeduplicatesSharedPhoneNumber(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (3, 'Grade 11 TVL', 11, 'C')");
        // Student and parent share same phone formatted differently
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (50, 'Juan', 'Ramos', 'student', '09171234567', 11, 'C', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (51, 'Rosa', 'Ramos', 'parent', '+63 917 123-4567', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (3, 50, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (51, 50)");

        $result = smsNotifyGradePublication($this->db, 3, 'Term 2', '2026-2027', 50);
        $this->assertSame(1, $result['total'], 'Shared phone between student and parent must trigger only 1 SMS');
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);

        $stmt = $this->db->query("SELECT * FROM sms_logs WHERE recipient_phone = '639171234567'");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $logs);
    }

    public function testSingleStudentGradePublicationDeduplicatesMultipleParentsWithSamePhone(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (4, 'Grade 12 HUMSS', 12, 'D')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (60, 'Ana', 'Cruz', 'student', '09171111111', 12, 'D', 'active')");
        // Father and Mother have the same phone
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (61, 'Jose', 'Cruz', 'parent', '09182222222', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (62, 'Maria', 'Cruz', 'parent', '+63 918 222-2222', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (4, 60, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (61, 60)");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (62, 60)");

        $result = smsNotifyGradePublication($this->db, 4, 'Final', '2026-2027', 60);
        $this->assertSame(2, $result['total'], '1 for student + 1 for shared parent phone = 2 SMS');
        $this->assertSame(2, $result['sent']);

        $parentLogs = $this->db->query("SELECT * FROM sms_logs WHERE recipient_phone = '639182222222'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $parentLogs, 'Only 1 SMS sent to the shared parent phone number');
    }

    public function testClassWideGradePublicationDeduplicatesSiblingParentPhone(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (5, 'Grade 11 STEM-A', 11, 'A')");
        // 2 siblings in the same class
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (70, 'Mark', 'Bautista', 'student', '09171000001', 11, 'A', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (71, 'Mary', 'Bautista', 'student', '09171000002', 11, 'A', 'active')");
        // Shared parent linked to both siblings
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (72, 'Eduardo', 'Bautista', 'parent', '09182000000', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (5, 70, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (5, 71, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (72, 70)");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (72, 71)");

        $result = smsNotifyGradePublication($this->db, 5, 'Term 1', '2026-2027');
        $this->assertSame(3, $result['total'], '2 students + 1 parent = 3 SMS');
        $this->assertSame(3, $result['sent']);

        $parentLogs = $this->db->query("SELECT * FROM sms_logs WHERE recipient_phone = '639182000000'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $parentLogs, 'Parent of multiple siblings receives only 1 SMS for the class publication');
    }

    public function testClassWideGradePublicationDeduplicatesDistinctParentsWithSamePhone(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (6, 'Grade 12 STEM-B', 12, 'B')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (80, 'Leo', 'Navarro', 'student', '09173000001', 12, 'B', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (81, 'Lea', 'Navarro', 'student', '09173000002', 12, 'B', 'active')");

        // Two distinct parent user records sharing the same contact number
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (82, 'Danilo', 'Navarro', 'parent', '09184000000', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (83, 'Dina', 'Navarro', 'parent', '+63 918 400-0000', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (6, 80, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (6, 81, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (82, 80)");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (83, 81)");

        $result = smsNotifyGradePublication($this->db, 6, 'Term 2', '2026-2027');
        $this->assertSame(3, $result['total'], '2 students + 1 shared parent phone = 3 SMS');
        $this->assertSame(3, $result['sent']);

        $parentLogs = $this->db->query("SELECT * FROM sms_logs WHERE recipient_phone = '639184000000'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $parentLogs, 'Only 1 SMS sent to the shared parent phone number');
    }

    public function testClassWideGradePublicationSendsSeparateSmsForDistinctPhoneNumbers(): void
    {
        putenv('SMS_PROVIDER=log');

        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (7, 'Grade 12 GAS', 12, 'G')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (90, 'StudentA', 'Test', 'student', '09175000001', 12, 'G', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, grade_level, section, status)
                         VALUES (91, 'StudentB', 'Test', 'student', '09175000002', 12, 'G', 'active')");

        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (92, 'ParentA', 'Test', 'parent', '09185000001', 'active')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role, contact_number, status)
                         VALUES (93, 'ParentB', 'Test', 'parent', '09185000002', 'active')");

        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (7, 90, 'enrolled')");
        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (7, 91, 'enrolled')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (92, 90)");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (93, 91)");

        $result = smsNotifyGradePublication($this->db, 7, 'Term 3', '2026-2027');
        $this->assertSame(4, $result['total'], '4 distinct phone numbers must receive 4 separate SMS');
        $this->assertSame(4, $result['sent']);

        $logs = $this->db->query("SELECT recipient_phone FROM sms_logs WHERE recipient_phone LIKE '6391%' ORDER BY recipient_phone ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('639175000001', $logs);
        $this->assertContains('639175000002', $logs);
        $this->assertContains('639185000001', $logs);
        $this->assertContains('639185000002', $logs);
    }
}
