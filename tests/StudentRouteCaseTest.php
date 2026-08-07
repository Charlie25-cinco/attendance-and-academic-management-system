<?php

use PHPUnit\Framework\TestCase;

final class StudentRouteCaseTest extends TestCase
{
    public function testStudentPortalFilesUseTheirPublishedRouteCase(): void
    {
        $studentDirectory = __DIR__ . '/../student';
        $entries = scandir($studentDirectory);
        $this->assertIsArray($entries);

        $expectedRoutes = [
            'Student.php',
            'Student_Action.php',
            'Student_Announcements.php',
            'Student_Attendance.php',
            'Student_Classes.php',
            'Student_QR.php',
            'Student_Report_Card.php',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertContains($route, $entries, $route . ' must preserve its exact published casing.');
            $this->assertNotContains(lcfirst($route), $entries);
        }
    }

    public function testSharedStudentNavigationUsesPublishedRouteCase(): void
    {
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
        $this->assertIsString($header);
        $this->assertIsString($sidebar);

        $this->assertStringContainsString("'classes' => 'Student_Classes.php'", $header);
        $this->assertStringNotContainsString("'classes' => 'student_Classes.php'", $header);
        $this->assertStringContainsString("'link' => 'Student.php'", $sidebar);
    }
}
