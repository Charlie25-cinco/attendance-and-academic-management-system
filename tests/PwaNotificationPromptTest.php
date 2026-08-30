<?php

use PHPUnit\Framework\TestCase;

final class PwaNotificationPromptTest extends TestCase
{
    public function testLoginModalAndButtonsRender(): void
    {
        $loginPage = file_get_contents(__DIR__ . '/../auth/login.php');
        $this->assertIsString($loginPage);

        $this->assertStringContainsString('id="pushPromptModal"', $loginPage);
        $this->assertStringContainsString('id="pushPromptAllowBtn"', $loginPage);
        $this->assertStringContainsString('id="pushPromptDenyBtn"', $loginPage);
        $this->assertStringContainsString('id="pushPromptLaterBtn"', $loginPage);
        $this->assertStringContainsString('APP_PUSH_PUBLIC_KEY', $loginPage);
    }

    public function testMainJsHasInstalledPwaCheckAndDenyHandler(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        $this->assertStringContainsString('function isInstalledPwa()', $js);
        $this->assertStringContainsString('(display-mode: standalone)', $js);
        $this->assertStringContainsString('pushPromptDenyBtn', $js);
        $this->assertStringContainsString('bshs_push_prompt_dismissed', $js);
    }
}