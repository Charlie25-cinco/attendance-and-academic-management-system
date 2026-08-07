<?php

use PHPUnit\Framework\TestCase;

final class ParentRouteCaseTest extends TestCase
{
    public function testParentPortalFilesUseTheirPublishedRouteCase(): void
    {
        $parentDirectory = __DIR__ . '/../parent';
        $entries = scandir($parentDirectory);
        $this->assertIsArray($entries);

        $expectedRoutes = [
            'Parent.php',
            'Parent_Announcements.php',
            'Parent_Chat.php',
            'Parent_Chat_Action.php',
            'Parent_Progress.php',
            'Parent_Report_Card.php',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertContains($route, $entries, $route . ' must preserve its exact published casing.');
            $this->assertNotContains(lcfirst($route), $entries);
        }
    }

    public function testAuthenticationRedirectsUseThePublishedParentDashboardRoute(): void
    {
        foreach (['login.php', 'change-password.php'] as $authFile) {
            $content = file_get_contents(__DIR__ . '/../auth/' . $authFile);
            $this->assertIsString($content);
            $this->assertStringContainsString('../parent/Parent.php', $content);
            $this->assertStringNotContainsString('../parent/parent.php', $content);
        }
    }
}
