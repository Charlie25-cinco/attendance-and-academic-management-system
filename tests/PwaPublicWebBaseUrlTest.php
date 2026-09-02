<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PwaPublicWebBaseUrlTest extends TestCase
{
    private array $savedServer;
    private ?string $savedEnv;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedEnv = getenv('APP_PUBLIC_BASE_URL') !== false ? (string)getenv('APP_PUBLIC_BASE_URL') : null;
        putenv('APP_PUBLIC_BASE_URL=');
        unset($_SERVER['APP_PUBLIC_BASE_URL']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        if ($this->savedEnv !== null) {
            putenv("APP_PUBLIC_BASE_URL={$this->savedEnv}");
        } else {
            putenv('APP_PUBLIC_BASE_URL');
        }
    }

    public function testAppPublicWebBaseUrlResolvesToRootAcrossAllRolePortalsOnRootDeployment(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_NAME'] = 'balingasagshs.wasmer.app';
        $_SERVER['SERVER_PORT'] = 443;

        $portals = [
            '/admin/admin.php',
            '/admin/admin_Attendance.php',
            '/teacher/teacher.php',
            '/teacher/teacher_Attendance.php',
            '/student/Student.php',
            '/student/Student_Classes.php',
            '/parent/Parent.php',
            '/parent/Parent_Progress.php',
            '/auth/login.php',
            '/site/index.php',
        ];

        foreach ($portals as $scriptName) {
            $_SERVER['SCRIPT_NAME'] = $scriptName;

            $baseUrl = appPublicWebBaseUrl();
            $this->assertSame(
                'https://balingasagshs.wasmer.app',
                $baseUrl,
                "Failed resolving base URL for script: $scriptName"
            );

            $headHtml = pwaHeadHtml();
            $this->assertStringContainsString(
                '<link rel="manifest" href="https://balingasagshs.wasmer.app/assets/manifest.json">',
                $headHtml,
                "Incorrect manifest URL for $scriptName"
            );
            $this->assertStringContainsString(
                '<link rel="icon" type="image/png" sizes="192x192" href="https://balingasagshs.wasmer.app/assets/images/icon-192.png">',
                $headHtml,
                "Incorrect icon URL for $scriptName"
            );
            $this->assertStringContainsString(
                '<link rel="apple-touch-icon" href="https://balingasagshs.wasmer.app/assets/images/apple-touch-icon.png">',
                $headHtml,
                "Incorrect apple touch icon URL for $scriptName"
            );
            $this->assertStringContainsString(
                "var desiredScript = 'https://balingasagshs.wasmer.app/sw.js?v=0.3.82';",
                $headHtml,
                "Incorrect SW script URL for $scriptName"
            );
            $this->assertStringContainsString(
                "var desiredScope = 'https://balingasagshs.wasmer.app/';",
                $headHtml,
                "Incorrect SW scope URL for $scriptName"
            );
        }
    }

    public function testAppPublicWebBaseUrlResolvesSubdirectoryDeploymentAcrossAllRolePortals(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = 80;

        $portals = [
            '/attendance-and-academic-management-system/admin/admin.php',
            '/attendance-and-academic-management-system/teacher/teacher_Attendance.php',
            '/attendance-and-academic-management-system/student/Student.php',
            '/attendance-and-academic-management-system/parent/Parent.php',
            '/attendance-and-academic-management-system/auth/login.php',
        ];

        foreach ($portals as $scriptName) {
            $_SERVER['SCRIPT_NAME'] = $scriptName;

            $baseUrl = appPublicWebBaseUrl();
            $this->assertSame(
                'http://localhost/attendance-and-academic-management-system',
                $baseUrl,
                "Failed resolving subdirectory base URL for script: $scriptName"
            );

            $headHtml = pwaHeadHtml();
            $this->assertStringContainsString(
                '<link rel="manifest" href="http://localhost/attendance-and-academic-management-system/assets/manifest.json">',
                $headHtml,
                "Incorrect manifest URL for $scriptName"
            );
            $this->assertStringContainsString(
                '<link rel="icon" type="image/png" sizes="192x192" href="http://localhost/attendance-and-academic-management-system/assets/images/icon-192.png">',
                $headHtml,
                "Incorrect icon URL for $scriptName"
            );
            $this->assertStringContainsString(
                '<link rel="apple-touch-icon" href="http://localhost/attendance-and-academic-management-system/assets/images/apple-touch-icon.png">',
                $headHtml,
                "Incorrect apple touch icon URL for $scriptName"
            );
            $this->assertStringContainsString(
                "var desiredScript = 'http://localhost/attendance-and-academic-management-system/sw.js?v=0.3.82';",
                $headHtml,
                "Incorrect SW script URL for $scriptName"
            );
            $this->assertStringContainsString(
                "var desiredScope = 'http://localhost/attendance-and-academic-management-system/';",
                $headHtml,
                "Incorrect SW scope URL for $scriptName"
            );
        }
    }

    public function testManifestJsonRetainsValidRootAbsoluteSemantics(): void
    {
        $manifestPath = __DIR__ . '/../assets/manifest.json';
        $this->assertFileExists($manifestPath);
        $manifestContent = file_get_contents($manifestPath);
        $this->assertIsString($manifestContent);

        $json = json_decode($manifestContent, true);
        $this->assertIsArray($json);
        $this->assertSame('/auth/login.php', $json['start_url'] ?? null);
        $this->assertSame('/', $json['scope'] ?? null);
        $this->assertSame('standalone', $json['display'] ?? null);
        $this->assertNotEmpty($json['icons'] ?? null);
    }
}
