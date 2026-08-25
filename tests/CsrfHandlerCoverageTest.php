<?php

use PHPUnit\Framework\TestCase;

final class CsrfHandlerCoverageTest extends TestCase
{
    /**
     * Accepted enforcement markers, mirroring the audited conventions:
     * - requireCsrfToken()            shared helper (functions/app-helpers.php)
     * - adminAnnouncementsRequireCsrf local wrapper (admin_Announcements_Action.php)
     * - requirePostAndCsrfOrExit      local wrapper (*_Reports_Action.php)
     * - inline hash_equals() over the session csrf_token (auth pages, chats, sections)
     */
    private function csrfMarkers(): array
    {
        return [
            'requireCsrfToken(',
            'adminAnnouncementsRequireCsrf(',
            'requirePostAndCsrfOrExit(',
        ];
    }

    private function hasInlineHashEqualsCheck(string $content): bool
    {
        return str_contains($content, 'hash_equals(')
            && str_contains($content, 'csrf_token');
    }

    public function testEveryPortalActionHandlerEnforcesCsrf(): void
    {
        $readOnlyHandlers = [
            'student_action.php',
        ];
        $missing = [];

        foreach (['admin', 'teacher', 'student', 'parent'] as $portal) {
            $files = array_merge(
                glob(APP_ROOT . '/' . $portal . '/*Action*.php') ?: [],
                glob(APP_ROOT . '/' . $portal . '/*_action*.php') ?: []
            );
            foreach ($files as $path) {
                $script = strtolower(basename($path));
                if (in_array($script, $readOnlyHandlers, true)) {
                    continue;
                }
                $content = (string)file_get_contents($path);
                $enforced = false;
                foreach ($this->csrfMarkers() as $marker) {
                    if (str_contains($content, $marker)) {
                        $enforced = true;
                        break;
                    }
                }
                if (!$enforced && $this->hasInlineHashEqualsCheck($content)) {
                    $enforced = true;
                }
                if (!$enforced) {
                    $missing[] = $portal . '/' . basename($path);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'POST/AJAX action handlers without CSRF enforcement found. Gate every '
            . 'state-mutating branch with requireCsrfToken() or a documented local '
            . 'wrapper, or add genuinely read-only handlers to this test\'s allowlist.'
        );
    }

    public function testLogoutRequiresTheSessionCsrfToken(): void
    {
        $content = file_get_contents(APP_ROOT . '/auth/logout.php');
        $this->assertIsString($content);
        $this->assertStringContainsString('hash_equals(', $content);
        $this->assertStringContainsString('csrf_token', $content);
        $this->assertStringContainsString("Location: login.php", $content);

        $header = file_get_contents(APP_ROOT . '/includes/header.php');
        $this->assertIsString($header);
        $this->assertStringContainsString('logout.php?csrf_token=', $header);
    }

    public function testAuthPostHandlersEnforceCsrf(): void
    {
        $handlers = ['login.php', 'forgot-password.php', 'reset-password.php', 'change-password.php'];
        foreach ($handlers as $file) {
            $content = (string)file_get_contents(APP_ROOT . '/auth/' . $file);
            $this->assertTrue(
                $this->hasInlineHashEqualsCheck($content) || str_contains($content, 'requireCsrfToken('),
                $file . ' must validate the session CSRF token before processing POST input.'
            );
        }
    }
}
