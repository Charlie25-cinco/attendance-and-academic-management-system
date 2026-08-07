<?php

use BshsAms\Schedule\ScheduleParser;
use PHPUnit\Framework\TestCase;

final class QrAttendanceStatusTest extends TestCase
{
    public function testScanBeforeFifteenMinuteBoundaryIsPresent(): void
    {
        $status = ScheduleParser::attendanceStatusAt(
            'Fri 8:00 AM - 9:30 AM',
            '2026-08-07',
            (8 * 60) + 14
        );

        $this->assertSame('present', $status);
    }

    public function testScanAtFifteenMinuteBoundaryIsLate(): void
    {
        $status = ScheduleParser::attendanceStatusAt(
            'Fri 8:00 AM - 9:30 AM',
            '2026-08-07',
            (8 * 60) + 15
        );

        $this->assertSame('late', $status);
    }

    public function testClassificationUsesTheRelevantSameDayScheduleSegment(): void
    {
        $status = ScheduleParser::attendanceStatusAt(
            'Fri 8:00 AM - 9:30 AM; Fri 2:00 PM - 3:30 PM',
            '2026-08-07',
            (14 * 60) + 5
        );

        $this->assertSame('present', $status);
    }

    public function testQrClassificationIsServerAuthorizedAndUsedByScanner(): void
    {
        $action = file_get_contents(__DIR__ . '/../teacher/teacher_Action.php');
        $page = file_get_contents(__DIR__ . '/../teacher/teacher_Attendance.php');

        $this->assertIsString($action);
        $this->assertStringContainsString("'classify_qr_scan'", $action);
        $this->assertStringContainsString('ScheduleParser::attendanceStatusAt', $action);
        $this->assertStringContainsString("\$date !== date('Y-m-d')", $action);
        $this->assertStringContainsString('teacherOwnsClass($db, $teacherId, $classId)', $action);

        $this->assertIsString($page);
        $this->assertStringContainsString('teacher_Action.php?action=classify_qr_scan', $page);
        $this->assertStringContainsString("status === 'late' ? 'late' : 'present'", $page);
        $this->assertStringContainsString('date !== serverToday', $page);
        $this->assertStringContainsString("date_default_timezone_set('Asia/Manila')", $page);
    }
}
