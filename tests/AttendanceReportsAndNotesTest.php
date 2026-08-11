<?php

use PHPUnit\Framework\TestCase;

final class AttendanceReportsAndNotesTest extends TestCase
{
    public function testAggregateReportContractsAndLateConversionAreStable(): void
    {
        $this->assertSame(
            ['top_attendance', 'class_summary', 'at_risk'],
            aggregateAttendanceReportTypes()
        );
        $this->assertSame(
            ['Rank', 'Student', 'Reference', 'Present', 'Late', 'Absent', 'Total', 'Rate'],
            aggregateAttendanceReportDefinition('top_attendance')['headers']
        );

        $table = aggregateAttendanceReportTable('top_attendance', [[
            'student_name' => 'Ada Student',
            'reference_code' => 'S-1',
            'present_count' => 7,
            'effective_late_count' => 1,
            'absent_count' => 2,
            'total_records' => 10,
            'attendance_rate' => 80.0,
        ]]);

        $this->assertSame(['Rank', 'Student', 'Reference', 'Present', 'Late', 'Absent', 'Total', 'Rate'], $table['headers']);
        $this->assertSame([1, 'Ada Student', 'S-1', 7, 1, 2, 10, '80%'], $table['rows'][0]);
    }

    public function testTeacherAndAdminPagesUseSharedAggregateRunner(): void
    {
        foreach (['teacher/teacher_Reports.php', 'teacher/teacher_Reports_Action.php', 'admin/admin_Reports.php', 'admin/admin_Reports_Action.php'] as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertIsString($content);
            $this->assertStringContainsString('aggregateAttendanceReportRows', $content);
            $this->assertStringContainsString('top_attendance', $content);
            $this->assertStringContainsString('class_summary', $content);
            $this->assertStringContainsString('at_risk', $content);
        }
    }

    public function testReportNotesKeepJsonInitializationAndCsrfContracts(): void
    {
        $helper = file_get_contents(__DIR__ . '/../functions/app-helpers.php');
        $teacherAction = file_get_contents(__DIR__ . '/../teacher/teacher_Reports_Action.php');
        $adminAction = file_get_contents(__DIR__ . '/../admin/admin_Reports_Action.php');
        $teacherPage = file_get_contents(__DIR__ . '/../teacher/teacher_Reports.php');
        $adminPage = file_get_contents(__DIR__ . '/../admin/admin_Reports.php');

        $this->assertIsString($helper);
        $this->assertIsString($teacherAction);
        $this->assertIsString($adminAction);
        $this->assertIsString($teacherPage);
        $this->assertIsString($adminPage);

        $this->assertStringContainsString('throw $fallbackError;', $helper);
        $this->assertStringContainsString('ensureReportNotesTables($db);', $teacherAction);
        $this->assertStringContainsString('ensureReportNotesTables($db);', $adminAction);
        $this->assertStringContainsString('teacherReportsJsonExit([', $teacherAction);
        $this->assertStringContainsString('adminReportsJsonExit([', $adminAction);
        $this->assertStringContainsString('while (ob_get_level() > 0) { ob_end_clean(); }', $teacherAction);
        $this->assertStringContainsString('while (ob_get_level() > 0) { ob_end_clean(); }', $adminAction);
        $this->assertStringContainsString('appendCsrf(fd);', $teacherPage);
        $this->assertStringContainsString('appendCsrf(fd);', $adminPage);
        $this->assertStringContainsString('body: text.slice(0, 500)', $teacherPage);
        $this->assertStringContainsString('body: text.slice(0, 500)', $adminPage);
        $this->assertStringContainsString('WHERE id = ? AND teacher_id = ?', $teacherAction);
        $this->assertStringContainsString('WHERE id = ? AND admin_id = ?', $adminAction);
    }
}
