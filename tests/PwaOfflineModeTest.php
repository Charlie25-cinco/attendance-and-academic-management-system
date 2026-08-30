<?php

use PHPUnit\Framework\TestCase;

final class PwaOfflineModeTest extends TestCase
{
    public function testServiceWorkerPreCachesTeacherPagesAndHasInteractiveFallback(): void
    {
        $sw = file_get_contents(__DIR__ . '/../sw.js');
        $this->assertIsString($sw);

        $this->assertStringContainsString('/teacher/teacher.php', $sw);
        $this->assertStringContainsString('/teacher/teacher_Attendance.php', $sw);
        $this->assertStringContainsString('/teacher/teacher_Classes.php', $sw);
        $this->assertStringContainsString('/teacher/teacher_Grades.php', $sw);
        $this->assertStringContainsString('offlineFallbackResponse', $sw);
        $this->assertStringContainsString('Take Offline Attendance', $sw);
        $this->assertStringContainsString('Offline Classes & Grades', $sw);
    }

    public function testOfflineStorageSetsSessionAndCachedFlag(): void
    {
        $storageJs = file_get_contents(__DIR__ . '/../assets/js/offlineStorage.js');
        $this->assertIsString($storageJs);

        $this->assertStringContainsString('bshs_cached_teacher', $storageJs);
        $this->assertStringContainsString("clearTeacherSession", $storageJs);
        $this->assertStringContainsString("activeCacheName", $storageJs);
    }

    public function testLoginHasOfflineTeacherWorkspaceLaunchpad(): void
    {
        $loginPage = file_get_contents(__DIR__ . '/../auth/login.php');
        $this->assertIsString($loginPage);

        $this->assertStringContainsString('bshs_cached_teacher', $loginPage);
        $this->assertStringContainsString('offlineTeacherLaunchpad', $loginPage);
        $this->assertStringContainsString('Open Offline Attendance', $loginPage);
        $this->assertStringContainsString('Open Offline Classes & Grades', $loginPage);
    }
}