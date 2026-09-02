<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PwaInstallUiTest extends TestCase
{
    public function testHeaderDoesNotRenderPwaInstallButtonsOrDropdownItems(): void
    {
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertIsString($header);

        // Header quick action button is removed
        $this->assertStringNotContainsString('id="pwaInstallBtn"', $header);

        // Header profile dropdown item is removed
        $this->assertStringNotContainsString('id="headerPwaInstallDropdownItem"', $header);
        $this->assertStringNotContainsString('id="headerPwaInstallDropdownBtn"', $header);
    }

    public function testSettingsModalRendersPwaApplicationInstallSectionAndGuidanceModal(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        // Settings modal install section is rendered without display:none
        $this->assertStringContainsString('id="settingsPwaInstallSection"', $modals);
        $this->assertStringContainsString('id="settingsPwaInstallBtn"', $modals);
        $this->assertStringContainsString('class="settings-option"', $modals);
        $this->assertStringContainsString('Install App', $modals);
        $this->assertStringContainsString('Application', $modals);
        $this->assertStringNotContainsString('<div id="settingsPwaInstallSection" class="mt-4" style="display:none;">', $modals);

        // PWA install guidance modal exists with fallback steps
        $this->assertStringContainsString('id="pwaInstallModal"', $modals);
        $this->assertStringContainsString('id="pwaInstallModalTitle"', $modals);
        $this->assertStringContainsString('id="pwaInstallModalSubtitle"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep1Title"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep2Title"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep3Title"', $modals);
    }

    public function testConstantsRegistersPwaInstallPromptAndManagesSettingsModalWithFallback(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        $this->assertStringContainsString('window._pwaInstallPrompt = null;', $constants);
        $this->assertStringContainsString('window.isPwaStandalone = function ()', $constants);
        $this->assertStringContainsString('window.showPwaInstallModal = function ()', $constants);
        $this->assertStringContainsString('window.bindPwaInstallButton = function ()', $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallSection')", $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallBtn')", $constants);
        $this->assertStringNotContainsString("document.getElementById('pwaInstallBtn')", $constants);
        $this->assertStringNotContainsString("document.getElementById('headerPwaInstallDropdownItem')", $constants);
        $this->assertStringNotContainsString("document.getElementById('headerPwaInstallDropdownBtn')", $constants);
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt'", $constants);
        $this->assertStringContainsString("window.addEventListener('appinstalled'", $constants);
        $this->assertStringContainsString("window._pwaInstallPrompt.prompt()", $constants);
        $this->assertStringContainsString("window.showPwaInstallModal()", $constants);
    }

    public function testServiceWorkerRegistrationIsNotPreemptedOnFreshLoadWithoutController(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        // Ensure navigator.serviceWorker.register() is called and controllerchange listener is properly bound
        $this->assertStringContainsString("navigator.serviceWorker.addEventListener('controllerchange', function () {", $constants);
        $this->assertStringContainsString("navigator.serviceWorker.register(desiredScript, { scope: desiredScope, updateViaCache: 'none' })", $constants);

        // Assert that register occurs in the same finally block without an unconditional/unwrapped return before it
        $finallyPos = strpos($constants, '.finally(function () {');
        $this->assertNotFalse($finallyPos);
        $registerPos = strpos($constants, 'navigator.serviceWorker.register(', $finallyPos);
        $this->assertNotFalse($registerPos);
        $controllerListenerPos = strpos($constants, "navigator.serviceWorker.addEventListener('controllerchange'", $finallyPos);
        $this->assertNotFalse($controllerListenerPos);
        $this->assertLessThan($registerPos, $controllerListenerPos, 'controllerchange listener should be registered before or alongside register call');
    }

    public function testMainJsRefreshesPwaInstallStateWhenSettingsModalOpens(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        $this->assertStringContainsString('settingsModal', $js);
        $this->assertStringContainsString('shown.bs.modal', $js);
        $this->assertStringContainsString('bindPwaInstallButton', $js);
    }

    public function testAllPortalsIncludeSharedHeader(): void
    {
        $portals = [
            __DIR__ . '/../admin/admin.php',
            __DIR__ . '/../teacher/teacher.php',
            __DIR__ . '/../student/Student.php',
            __DIR__ . '/../parent/Parent.php',
        ];

        foreach ($portals as $portalFile) {
            $this->assertFileExists($portalFile);
            $content = file_get_contents($portalFile);
            $this->assertIsString($content);
            $this->assertTrue(
                str_contains($content, "includes/header.php"),
                "File $portalFile should include shared header.php"
            );
        }
    }
}
