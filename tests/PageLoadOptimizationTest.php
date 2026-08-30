<?php

use PHPUnit\Framework\TestCase;

final class PageLoadOptimizationTest extends TestCase
{
    public function testOfflineStorageWarmsPagesInIdleTime(): void
    {
        $storageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $this->assertIsString($storageJs);

        $this->assertStringContainsString('warmPages', $storageJs);
        $this->assertStringContainsString('requestIdleCallback', $storageJs);
        $this->assertStringNotContainsString('cache: "no-cache"', $storageJs);
    }

    public function testMainJsDefersServiceWorkerFreshnessChecks(): void
    {
        $mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($mainJs);

        $this->assertStringContainsString('checkFreshness', $mainJs);
        $this->assertStringContainsString('window.requestIdleCallback(checkFreshness', $mainJs);
    }

    public function testTeacherActionReleasesSessionLockForReadOnlyQueries(): void
    {
        $actionPhp = file_get_contents(__DIR__ . '/../teacher/teacher_Action.php');
        $this->assertIsString($actionPhp);

        $this->assertStringContainsString('$readOnlyActions', $actionPhp);
        $this->assertStringContainsString('session_write_close()', $actionPhp);
        $this->assertStringContainsString('offline_bootstrap', $actionPhp);
    }

    public function testTeacherPagesScheduleBootstrapInIdleTime(): void
    {
        foreach (['teacher/teacher.php', 'teacher/teacher_Attendance.php', 'teacher/teacher_Classes.php'] as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertIsString($content, "File $file should exist");
            $this->assertStringContainsString('scheduleBootstrap', $content, "File $file should use scheduleBootstrap");
            $this->assertStringContainsString('requestIdleCallback', $content, "File $file should support requestIdleCallback");
        }
    }
}