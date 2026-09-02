<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ExportProgressIndicatorTest extends TestCase
{
    public function testMainJsExposesAppTriggerExportAndBypassesProgress(): void
    {
        $mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($mainJs);

        $this->assertStringContainsString('function appTriggerExport', $mainJs);
        $this->assertStringContainsString('window.appTriggerExport = appTriggerExport', $mainJs);
        $this->assertStringContainsString('window.appDownloadUrl = appTriggerExport', $mainJs);
        $this->assertStringContainsString('window.APP_SUPPRESS_NEXT_UNLOAD_PROGRESS = true', $mainJs);
        $this->assertStringContainsString('link.hasAttribute("download")', $mainJs);
        $this->assertStringContainsString('link.getAttribute("data-skip-loader") === "true"', $mainJs);
        $this->assertStringContainsString('finishTopProgress()', $mainJs);
    }

    public function testAdminReportsContainsExplicitExportMarkersAndUsesAppTriggerExport(): void
    {
        $content = file_get_contents(__DIR__ . '/../admin/admin_Reports.php');
        $this->assertIsString($content);

        // Verify CSV export links have download and data-skip-loader="true"
        $this->assertStringContainsString('exportUrl(\'attendance\'', $content);
        $this->assertStringContainsString('download data-skip-loader="true"><i class="bi bi-download me-1"></i>CSV</a>', $content);

        // Verify SF1 and SF2 export functions use appTriggerExport
        $this->assertStringContainsString('appTriggerExport(\'admin_Enrollments_Action.php?\'', $content);
        $this->assertStringContainsString('appTriggerExport(\'admin_Classes_Action.php?\'', $content);
    }

    public function testTeacherReportsContainsExplicitExportMarkersAndUsesAppTriggerExport(): void
    {
        $content = file_get_contents(__DIR__ . '/../teacher/teacher_Reports.php');
        $this->assertIsString($content);

        // Verify teacher CSV export links have download and data-skip-loader="true"
        $this->assertStringContainsString('teacherExportUrl(\'top_attendance\'', $content);
        $this->assertStringContainsString('download data-skip-loader="true"><i class="bi bi-download me-1"></i>CSV</a>', $content);

        // Verify SF2 export function uses appTriggerExport
        $this->assertStringContainsString('appTriggerExport(\'teacher_SF2_Export.php?\'', $content);
    }

    public function testTeacherAttendanceUsesAppTriggerExport(): void
    {
        $content = file_get_contents(__DIR__ . '/../teacher/teacher_Attendance.php');
        $this->assertIsString($content);

        // Verify SF2 export buttons have data-skip-loader="true"
        $this->assertStringContainsString('data-skip-loader="true" onclick="exportAttendanceSf2(\'xlsx\')"', $content);
        $this->assertStringContainsString('data-skip-loader="true" onclick="exportAttendanceSf2(\'csv\')"', $content);

        // Verify exportAttendanceSf2 uses appTriggerExport
        $this->assertStringContainsString('appTriggerExport(\'teacher_SF2_Export.php?\'', $content);
    }

    public function testEnrollmentModalsContainsDownloadAndSkipLoaderOnTemplateLink(): void
    {
        $content = file_get_contents(__DIR__ . '/../includes/modals/enrollment_modals.php');
        $this->assertIsString($content);

        $this->assertStringContainsString('href="?download_template=1" class="btn btn-outline-primary btn-sm w-100 mb-2" download data-skip-loader="true"', $content);
    }

    public function testNormalPortalNavigationLinksDoNotHaveDownloadOrSkipLoader(): void
    {
        // Normal portal navigation links must not be marked as skip-loader
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertIsString($header);

        $this->assertStringNotContainsString('href="admin_Classes.php" data-skip-loader', $header);
        $this->assertStringNotContainsString('href="admin_Sections.php" data-skip-loader', $header);
        $this->assertStringNotContainsString('href="teacher_Attendance.php" data-skip-loader', $header);
    }

    public function testNavigationProgressDecisionLifecycle(): void
    {
        // Simulate shouldShowNavigationProgress lifecycle rules from main.js
        $shouldShow = function (array $link): bool {
            $href = $link['href'] ?? '';
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                return false;
            }
            if (!empty($link['download'])) {
                return false;
            }
            if (strtolower($link['target'] ?? '') === '_blank') {
                return false;
            }
            if (($link['data-skip-loader'] ?? '') === 'true') {
                return false;
            }
            return true;
        };

        // Export links must be bypassed
        $this->assertFalse($shouldShow(['href' => 'admin_Reports_Action.php?action=export&type=attendance', 'download' => true, 'data-skip-loader' => 'true']));
        $this->assertFalse($shouldShow(['href' => 'teacher_SF2_Export.php?class_id=1&export=xlsx', 'download' => true]));
        $this->assertFalse($shouldShow(['href' => '?download_template=1', 'download' => true, 'data-skip-loader' => 'true']));
        $this->assertFalse($shouldShow(['href' => 'teacher_Action.php?action=export_grades', 'target' => '_blank']));

        // Normal navigation links must proceed with progress loader
        $this->assertTrue($shouldShow(['href' => 'admin_Classes.php']));
        $this->assertTrue($shouldShow(['href' => 'teacher_Attendance.php']));
        $this->assertTrue($shouldShow(['href' => 'admin_Sections.php?grade_level=11']));
        $this->assertTrue($shouldShow(['href' => 'admin_Enrollments.php?page=2']));
    }
}