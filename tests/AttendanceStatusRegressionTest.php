<?php

use PHPUnit\Framework\TestCase;

final class AttendanceStatusRegressionTest extends TestCase
{
    public function testAttendanceUsesOnlyPresentAbsentAndLate(): void
    {
        $schema = (string)file_get_contents(APP_ROOT . '/database/schema.sql');
        self::assertStringContainsString("status ENUM('present', 'absent', 'late') NOT NULL", $schema);

        $runtimeFiles = [
            '/teacher/teacher_Attendance.php',
            '/teacher/teacher_Action.php',
            '/teacher/teacher_Enrollment_Helper.php',
            '/teacher/teacher_SF2_Export.php',
            '/src/Export/Sf2Exporter.php',
            '/assets/css/role.css',
        ];
        foreach ($runtimeFiles as $file) {
            $source = strtolower((string)file_get_contents(APP_ROOT . $file));
            self::assertStringNotContainsString('cutting', $source, $file . ' must not restore the removed status.');
        }

        $handler = (string)file_get_contents(APP_ROOT . '/teacher/teacher_Action.php');
        self::assertStringContainsString("\$validStatuses = ['present', 'absent', 'late'];", $handler);
        self::assertStringContainsString('Attendance status must be present, absent, or late.', $handler);
        self::assertStringContainsString('if (!empty($changedRecords))', $handler);
        self::assertStringContainsString('notifyAttendanceParents($db, $teacherId, $classId, $date, $changedRecords);', $handler);
        self::assertStringNotContainsString('$notifRecords = !empty($changedRecords) ? $changedRecords : $records;', $handler);
    }
}
