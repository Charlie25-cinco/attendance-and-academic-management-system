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

    public function testPrincipalPagesHaveExpectedFocusedBehavior(): void
    {
        $pending = (string)file_get_contents(APP_ROOT . '/principal/principal_Pending.php');
        $released = (string)file_get_contents(APP_ROOT . '/principal/principal_Released.php');
        $history = (string)file_get_contents(APP_ROOT . '/principal/principal_History.php');

        self::assertStringContainsString("'statuses' => ['submitted_admin']", $pending);
        self::assertStringContainsString("'actions' => ['approve', 'reject']", $pending);
        self::assertStringContainsString("'statuses' => ['approved']", $released);
        self::assertStringContainsString("'actions' => ['withdraw']", $released);
        self::assertStringContainsString("'statuses' => ['approved', 'rejected']", $history);
        self::assertStringContainsString("'actions' => []", $history);
    }

    public function testEveryPrincipalDestinationUsesReviewPermission(): void
    {
        foreach (['principal.php', 'principal_pending.php', 'principal_released.php', 'principal_history.php'] as $page) {
            self::assertSame('report_cards.review', permissionForScript($page));
        }
    }
}
