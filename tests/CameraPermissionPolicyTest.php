<?php

use PHPUnit\Framework\TestCase;

final class CameraPermissionPolicyTest extends TestCase
{
    public function testCameraPermissionIsLimitedToTeacherAttendance(): void
    {
        $content = file_get_contents(__DIR__ . '/../config/session.php');
        $this->assertIsString($content);

        $this->assertStringContainsString("strtolower(\$scriptName) === 'teacher_attendance.php'", $content);
        $this->assertStringContainsString("? 'camera=(self)' : 'camera=()'", $content);
        $this->assertStringContainsString('microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()', $content);
    }

    public function testQrScannerProvidesPermissionGuidanceAndRetry(): void
    {
        $content = file_get_contents(__DIR__ . '/../teacher/teacher_Attendance.php');
        $this->assertIsString($content);

        $this->assertStringContainsString('function startQrCamera()', $content);
        $this->assertStringContainsString('NotAllowedError|Permission denied', $content);
        $this->assertStringContainsString('Allow camera access for this site', $content);
        $this->assertStringContainsString('Retry Camera', $content);
        $this->assertStringNotContainsString("'Camera error: ' + err", $content);
    }
}
