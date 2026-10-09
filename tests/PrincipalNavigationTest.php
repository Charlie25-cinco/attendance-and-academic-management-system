<?php

use PHPUnit\Framework\TestCase;

final class PrincipalNavigationTest extends TestCase
{
    public function testPrincipalSidebarUsesDistinctPages(): void
    {
        $sidebar = (string)file_get_contents(APP_ROOT . '/includes/sidebar.php');

        foreach (['principal.php', 'principal_Subject_Grades.php', 'principal_Pending.php', 'principal_Endorsed.php', 'principal_Academic_Monitoring.php', 'principal_Attendance_Monitoring.php', 'principal_Activity_Logs.php', 'principal_Released.php', 'principal_History.php'] as $page) {
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
        self::assertStringContainsString('Ready to endorse', $pending);
        self::assertStringContainsString("decision, remarks", $pending);
        self::assertStringContainsString("find(['approved']", $released);
        self::assertStringContainsString('Admin-released report cards', $released);
        self::assertStringNotContainsString("decision: 'withdraw'", $released);
        self::assertStringContainsString('decisionHistory(', $history);
        self::assertStringContainsString('Read-only audit trail', $history);
        self::assertStringNotContainsString('principal_Action.php', $history);
        self::assertFileDoesNotExist(APP_ROOT . '/includes/principal-report-card-page.php');
    }

    public function testPrincipalDashboardPrioritizesWorkflowAndMonitoringShortcuts(): void
    {
        $dashboard = (string)file_get_contents(APP_ROOT . '/principal/principal.php');
        $styles = (string)file_get_contents(APP_ROOT . '/assets/css/role.css');

        foreach (['Pending endorsement', 'Awaiting Admin', 'Admin released', 'Returned / withdrawn'] as $metric) {
            self::assertStringContainsString("'label' => '$metric'", $dashboard);
        }

        foreach (['principal_Subject_Grades.php', 'principal_Academic_Monitoring.php', 'principal_Attendance_Monitoring.php', 'principal_Activity_Logs.php'] as $destination) {
            self::assertStringContainsString("'href' => '$destination'", $dashboard);
        }

        self::assertStringContainsString('principal-workflow-step', $dashboard);
        self::assertStringContainsString('principal-readiness', $dashboard);
        self::assertStringContainsString('principal-decision-list', $dashboard);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString('body.dark-mode .principal-workflow li.is-principal', $styles);
    }

    public function testEveryPrincipalDestinationUsesReviewPermission(): void
    {
        foreach (['principal.php', 'principal_subject_grades.php', 'principal_subject_grades_detail.php', 'principal_subject_grades_action.php', 'principal_pending.php', 'principal_endorsed.php', 'principal_released.php', 'principal_history.php'] as $page) {
            self::assertSame('report_cards.review', permissionForScript($page));
        }
        foreach (['principal_academic_monitoring.php', 'principal_attendance_monitoring.php', 'principal_activity_logs.php'] as $page) {
            self::assertSame('principal.monitoring.view', permissionForScript($page));
        }
    }

    public function testPrincipalActivityMonitoringIsReadOnlyAndPrivacyFiltered(): void
    {
        $activity = (string)file_get_contents(APP_ROOT . '/principal/principal_Activity_Logs.php');
        self::assertStringContainsString('FROM activity_logs', $activity);
        self::assertStringNotContainsString('auth_login_logs', $activity);
        self::assertStringContainsString("unset(\$data['ip_address']", $activity);
        self::assertStringNotContainsString('requireCsrfToken(', $activity);
    }
}
