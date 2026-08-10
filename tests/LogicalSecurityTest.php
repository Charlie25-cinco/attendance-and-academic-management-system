<?php

use PHPUnit\Framework\TestCase;

final class LogicalSecurityTest extends TestCase
{
    public function testCentralSessionLayerAppliesLogicalSecurityHeaders(): void
    {
        $content = file_get_contents(__DIR__ . '/../config/session.php');
        $this->assertIsString($content);

        $this->assertStringContainsString('function appApplyLogicalSecurityHeaders()', $content);
        $this->assertStringContainsString("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'self'", $content);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $content);
        $this->assertStringContainsString('X-Permitted-Cross-Domain-Policies: none', $content);
        $this->assertStringContainsString('Cross-Origin-Opener-Policy: same-origin', $content);
        $this->assertStringContainsString('Cross-Origin-Resource-Policy: same-origin', $content);
        $this->assertStringContainsString('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', $content);
        $this->assertStringContainsString('HTTP_X_FORWARDED_PROTO', $content);
    }

    public function testSharedJavascriptGuardsDirtySensitiveFormsWithoutOsShortcutClaims(): void
    {
        $content = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($content);

        $this->assertStringContainsString('function initLogicalSecurityGuards()', $content);
        $this->assertStringContainsString("form:not([data-skip-logical-security='true'])", $content);
        $this->assertStringContainsString('method !== "get" && method !== "dialog"', $content);
        $this->assertStringContainsString('Save or clear the form before refreshing this page.', $content);
        $this->assertStringContainsString('app_unsaved_sensitive_form', $content);
        $this->assertStringNotContainsString('Ctrl + Alt + Del', $content);
        $this->assertStringNotContainsString('Alt + Tab', $content);
    }
}
