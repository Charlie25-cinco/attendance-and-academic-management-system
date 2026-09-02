<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PwaInstallUiTest extends TestCase
{
    public function testHeaderRendersPwaInstallButtonsAndDropdownItems(): void
    {
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertIsString($header);

        // Header quick action button
        $this->assertStringContainsString('id="pwaInstallBtn"', $header);
        $this->assertStringContainsString('title="Install App"', $header);

        // Header profile dropdown item
        $this->assertStringContainsString('id="headerPwaInstallDropdownItem"', $header);
        $this->assertStringContainsString('id="headerPwaInstallDropdownBtn"', $header);
        $this->assertStringContainsString('Install App', $header);
    }

    public function testSettingsModalRendersPwaApplicationInstallSection(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        $this->assertStringContainsString('id="settingsPwaInstallSection"', $modals);
        $this->assertStringContainsString('id="settingsPwaInstallBtn"', $modals);
        $this->assertStringContainsString('class="settings-option"', $modals);
        $this->assertStringContainsString('Install App', $modals);
        $this->assertStringContainsString('Application', $modals);
    }

    public function testConstantsRegistersPwaInstallPromptAndManagesAllUiSurfaces(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        $this->assertStringContainsString('window._pwaInstallPrompt = null;', $constants);
        $this->assertStringContainsString('window.bindPwaInstallButton = function ()', $constants);
        $this->assertStringContainsString("document.getElementById('pwaInstallBtn')", $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallSection')", $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallBtn')", $constants);
        $this->assertStringContainsString("document.getElementById('headerPwaInstallDropdownItem')", $constants);
        $this->assertStringContainsString("document.getElementById('headerPwaInstallDropdownBtn')", $constants);
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt'", $constants);
        $this->assertStringContainsString("window.addEventListener('appinstalled'", $constants);
        $this->assertStringContainsString("window._pwaInstallPrompt.prompt()", $constants);
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
