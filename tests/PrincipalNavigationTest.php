<?php

use PHPUnit\Framework\TestCase;

final class PrincipalNavigationTest extends TestCase
{
    public function testPrincipalSidebarUsesDistinctPages(): void
    {
        $sidebar = (string)file_get_contents(APP_ROOT . '/includes/sidebar.php');

        foreach (['principal.php', 'principal_Pending.php', 'principal_Released.php', 'principal_History.php'] as $page) {
            self::assertStringContainsString("'link' => '$page'", $sidebar);
            self::assertFileExists(APP_ROOT . '/principal/' . $page);
        }

        self::assertStringNotContainsString('principal.php?status=', $sidebar);
    }

    public function testPrincipalPagesHaveIndependentFocusedInterfaces(): void
    {
        $pending = (string)file_get_contents(APP_ROOT . '/principal/principal_Pending.php');
        $released = (string)file_get_contents(APP_ROOT . '/principal/principal_Released.php');
        $history = (string)file_get_contents(APP_ROOT . '/principal/principal_History.php');

        self::assertStringContainsString("find(['submitted_admin']", $pending);
        self::assertStringContainsString('Ready to release', $pending);
        self::assertStringContainsString("decision, remarks", $pending);
        self::assertStringContainsString("find(['approved']", $released);
        self::assertStringContainsString('Family-visible records', $released);
        self::assertStringContainsString("decision: 'withdraw'", $released);
        self::assertStringContainsString('decisionHistory(', $history);
        self::assertStringContainsString('Read-only audit trail', $history);
        self::assertStringNotContainsString('principal_Action.php', $history);
        self::assertFileDoesNotExist(APP_ROOT . '/includes/principal-report-card-page.php');
    }

    public function testEveryPrincipalDestinationUsesReviewPermission(): void
    {
        foreach (['principal.php', 'principal_pending.php', 'principal_released.php', 'principal_history.php'] as $page) {
            self::assertSame('report_cards.review', permissionForScript($page));
        }
    }
}
