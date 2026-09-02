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
        $this->assertStringContainsString('bshs-ams-v37', $serviceWorker);
        $this->assertStringContainsString('BASE_PATH = (self.location.pathname ||', $serviceWorker);
        $this->assertStringContainsString("function resolvePath(path)", $serviceWorker);
        $this->assertStringContainsString('/assets/js/offlineStorage.js', $serviceWorker);
        $this->assertStringContainsString('/assets/js/networkSync.js', $serviceWorker);
        $this->assertStringContainsString('/assets/vendor/html5-qrcode/html5-qrcode.min.js', $serviceWorker);
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $serviceWorker);
        $this->assertStringNotContainsString('📶', $serviceWorker);
        $this->assertStringContainsString('return cached || fetchAndCache;', $serviceWorker);
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
        $this->assertStringContainsString('settingsModal', $javascript);
        $this->assertStringContainsString('shown.bs.modal', $javascript);
        $this->assertStringContainsString('updatePwaPushStatus', $javascript);
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

    public function testPushPromptModalRendersAndPromptsFirstTimeUsers(): void
    {
        $modal = file_get_contents(__DIR__ . '/../includes/modals.php');
        $javascript = file_get_contents(__DIR__ . '/../assets/js/main.js');

        $this->assertIsString($modal);
        $this->assertStringContainsString('id="pushPromptModal"', $modal);
        $this->assertStringContainsString('id="pushPromptAllowBtn"', $modal);
        $this->assertStringContainsString('id="pushPromptDenyBtn"', $modal);
        $this->assertStringContainsString('id="pushPromptLaterBtn"', $modal);
        $this->assertStringContainsString('id="pushPromptCloseBtn"', $modal);

        $this->assertIsString($javascript);
        $this->assertStringContainsString('isInstalledPwa', $javascript);
        $this->assertStringContainsString('initPwaPushFirstOpenPrompt', $javascript);
        $this->assertStringContainsString('bshs_push_prompt_dismissed', $javascript);
    }

    public function testPwaHeadScriptGuardsControllerChangeAndSynchronizesCacheVersion(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);
        $this->assertStringContainsString('var hadPreviousController = Boolean(navigator.serviceWorker.controller);', $constants);
        $this->assertStringContainsString('if (refreshingForUpdate || !hadPreviousController) { return; }', $constants);
        $this->assertStringContainsString('window._CACHE_NAME = \'bshs-ams-v37\';', $constants);
    }

    public function testPwaInstallButtonBindsHeaderAndSettingsModals(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);
        $this->assertStringContainsString('window.bindPwaInstallButton', $constants);
        $this->assertStringContainsString('settingsPwaInstallSection', $constants);
        $this->assertStringContainsString('headerPwaInstallDropdownItem', $constants);
    }
}
