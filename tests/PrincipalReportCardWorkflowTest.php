<?php

use BshsAms\Audit\ActivityLogger;
use BshsAms\Grade\PrincipalReportCardQuery;
use BshsAms\Grade\ReportCardReview;
use PHPUnit\Framework\TestCase;

final class PrincipalReportCardWorkflowTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY, reference_code TEXT, first_name TEXT, last_name TEXT,
            grade_level INTEGER, section TEXT, role TEXT, status TEXT
        )');
        $this->db->exec('CREATE TABLE classes (id INTEGER PRIMARY KEY, class_name TEXT)');
        $this->db->exec('CREATE TABLE class_subjects (id INTEGER PRIMARY KEY, class_id INTEGER)');
        $this->db->exec('CREATE TABLE grades (id INTEGER PRIMARY KEY, student_id INTEGER, class_subject_id INTEGER, academic_year TEXT, semester TEXT)');
        $this->db->exec('CREATE TABLE grade_approvals (id INTEGER PRIMARY KEY, grade_id INTEGER, status TEXT)');
        $this->db->exec('CREATE TABLE report_card_approvals (
            id INTEGER PRIMARY KEY, student_id INTEGER, academic_year TEXT, semester TEXT,
            advisory_teacher_id INTEGER, status TEXT, reviewed_by INTEGER, reviewed_at TEXT, remarks TEXT
        )');
        $this->db->exec('CREATE TABLE activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, actor_role TEXT,
            action_name TEXT, target_type TEXT, target_id INTEGER, details_json TEXT,
            ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec("INSERT INTO users VALUES
            (1, 'PR-001', 'School', 'Principal', NULL, NULL, 'principal', 'active'),
            (2, 'A-001', 'System', 'Admin', NULL, NULL, 'admin', 'active'),
            (10, 'S-001', 'Sample', 'Learner', 11, 'A', 'student', 'active'),
            (20, 'T-001', 'Class', 'Adviser', NULL, NULL, 'teacher', 'active')");
        $this->db->exec("INSERT INTO classes VALUES (1, 'General Mathematics')");
        $this->db->exec('INSERT INTO class_subjects VALUES (1, 1)');
        $this->db->exec("INSERT INTO grades VALUES (1, 10, 1, '2026-2027', 'S1')");
        $this->db->exec("INSERT INTO grade_approvals VALUES (1, 1, 'admin_verified')");
        $this->db->exec("INSERT INTO report_card_approvals VALUES (1, 10, '2026-2027', 'S1', 20, 'submitted_admin', NULL, NULL, NULL)");
    }

    public function testPrincipalCanReleaseAdminVerifiedReportCardAndAuditDecision(): void
    {
        $notified = [];
        $service = new ReportCardReview($this->db, function (array $card, string $decision) use (&$notified): void {
            $notified[] = [(int)$card['student_id'], $decision];
        });

        self::assertSame(1, $service->review(1, [1], 'approve'));
        self::assertSame('approved', $this->db->query('SELECT status FROM report_card_approvals WHERE id = 1')->fetchColumn());
        self::assertSame([[10, 'approve']], $notified);
        self::assertSame('report_card.approve', $this->db->query('SELECT action_name FROM activity_logs')->fetchColumn());
    }

    public function testDecisionHistoryPreservesReleaseAndWithdrawalEvents(): void
    {
        $service = new ReportCardReview($this->db, static function (): void {});
        self::assertSame(1, $service->review(1, [1], 'approve'));
        self::assertSame(1, $service->review(1, [1], 'withdraw', 'Correct the learner record.'));

        $events = (new PrincipalReportCardQuery($this->db))->decisionHistory();

        self::assertCount(2, $events);
        self::assertSame('report_card.withdraw', $events[0]['action_name']);
        self::assertSame('Correct the learner record.', $events[0]['decision_remarks']);
        self::assertSame('report_card.approve', $events[1]['action_name']);
        self::assertSame('Sample', $events[0]['first_name']);
        self::assertSame('School Principal', $events[0]['reviewer_name']);
    }

    public function testNonPrincipalCannotDecideAndReturnRequiresReason(): void
    {
        $service = new ReportCardReview($this->db, static function (): void {});
        try {
            $service->review(2, [1], 'approve');
            self::fail('An Admin must not perform the Principal decision.');
        } catch (DomainException $e) {
            self::assertStringContainsString('principal', strtolower($e->getMessage()));
        }

        $this->expectException(DomainException::class);
        $service->review(1, [1], 'reject', '');
    }

    public function testActivityLoggerRedactsSensitiveDetails(): void
    {
        self::assertTrue(ActivityLogger::record($this->db, 1, 'principal', 'settings.update', 'settings', null, [
            'email' => 'private@example.test',
            'api_token' => 'secret-token',
            'lrn' => '123456789012',
            'name' => 'Private Learner',
            'changed_fields' => ['school_name'],
        ]));
        $details = json_decode((string)$this->db->query('SELECT details_json FROM activity_logs')->fetchColumn(), true);
        self::assertSame('[redacted]', $details['email']);
        self::assertSame('[redacted]', $details['api_token']);
        self::assertSame('[redacted]', $details['lrn']);
        self::assertSame('[redacted]', $details['name']);
        self::assertSame(['school_name'], $details['changed_fields']);
    }
}
