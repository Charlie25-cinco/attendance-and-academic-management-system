<?php

use PHPUnit\Framework\TestCase;

final class PwaAssetFreshnessTest extends TestCase
{
    public function testPortalPagesUseVersionedMainJavascriptUrl(): void
    {
        foreach (['teacher', 'student', 'parent'] as $portal) {
            $files = glob(__DIR__ . '/../' . $portal . '/*.php') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $this->assertIsString($content);
                $this->assertStringNotContainsString('../assets/js/main.js', $content, $file);
                if (str_contains($content, "appAssetPath('js/main.js')")) {
                    $this->assertStringContainsString("appAssetPath('js/main.js')", $content);
                }
            }
        }
    }

    public function testServiceWorkerRefreshesJavascriptAndCssBeforeUsingCache(): void
    {
        $serviceWorker = file_get_contents(__DIR__ . '/../sw.js');

        $this->assertIsString($serviceWorker);
        $this->assertStringContainsString("const CACHE_NAME = 'bshs-ams-v19';", $serviceWorker);
        $this->assertStringContainsString("const BASE_PATH = (self.location.pathname || '').replace(/\/sw\.js$/, '');", $serviceWorker);
        $this->assertStringContainsString("function resolvePath(path)", $serviceWorker);
        $this->assertStringContainsString("'/offline.html'", $serviceWorker);
        $this->assertStringContainsString("'/assets/js/offlineStorage.js'", $serviceWorker);
        $this->assertStringContainsString("'/assets/js/networkSync.js'", $serviceWorker);
        $this->assertStringContainsString("'/assets/vendor/html5-qrcode/html5-qrcode.min.js'", $serviceWorker);
        $this->assertStringContainsString('var needsFreshAsset = /\\.(css|js)$/i.test(url.pathname);', $serviceWorker);
        $this->assertStringContainsString('if (needsFreshAsset) {', $serviceWorker);
        $networkFirst = strpos($serviceWorker, 'if (needsFreshAsset) {');
        $cacheFirst = strpos($serviceWorker, 'if (isAsset || isStaticAsset) {');
        $this->assertIsInt($networkFirst);
        $this->assertIsInt($cacheFirst);
        $this->assertLessThan($cacheFirst, $networkFirst);
    }

    public function testTeacherAttendanceUsesVersionedNetworkSync(): void
    {
        $attendanceContent = file_get_contents(__DIR__ . '/../teacher/teacher_Attendance.php');
        $this->assertIsString($attendanceContent);
        $this->assertStringContainsString("appAssetPath('js/networkSync.js')", $attendanceContent);
        $this->assertStringNotContainsString('../assets/js/networkSync.js', $attendanceContent);
    }

    public function testPushStatusRefreshesWhenSettingsModalOpens(): void
    {
        $javascript = file_get_contents(__DIR__ . '/../assets/js/main.js');

        $this->assertIsString($javascript);
        $this->assertStringContainsString(
            'document.getElementById("settingsModal")?.addEventListener("shown.bs.modal", updatePwaPushStatus);',
            $javascript
        );
    }

    public function testSettingsExplainsAndRequestsNotificationPermissionFromUserAction(): void
    {
        $modal = file_get_contents(__DIR__ . '/../includes/modals.php');
        $javascript = file_get_contents(__DIR__ . '/../assets/js/main.js');

        $this->assertIsString($modal);
        $this->assertStringContainsString('id="allowPushPermissionBtn"', $modal);
        $this->assertStringContainsString('id="deferPushPermissionBtn"', $modal);
        $this->assertStringContainsString('id="pushPermissionGuidance"', $modal);

        $this->assertIsString($javascript);
        $this->assertStringContainsString('Notification.permission === "default"', $javascript);
        $this->assertStringContainsString('Notification.permission === "denied"', $javascript);
        $this->assertStringContainsString('Notification.requestPermission()', $javascript);
        $this->assertStringContainsString('requestPushPermissionFromSettings', $javascript);
    }
}
