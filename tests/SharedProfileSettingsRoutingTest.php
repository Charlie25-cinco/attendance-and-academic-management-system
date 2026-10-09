<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class SharedProfileSettingsRoutingTest extends TestCase
{
    public function testSharedHeaderExposesTheCanonicalApplicationBaseUrl(): void
    {
        $header = (string)file_get_contents(APP_ROOT . '/includes/header.php');

        self::assertStringContainsString('window.APP_BASE_URL', $header);
        self::assertStringContainsString("appPublicWebBaseUrl()", $header);
    }

    public function testProfileAndRecoveryRoutesSupportEveryPortalIncludingPrincipal(): void
    {
        $modals = (string)file_get_contents(APP_ROOT . '/includes/modals.php');

        foreach (['principal', 'admin', 'teacher', 'student', 'parent'] as $portal) {
            self::assertGreaterThanOrEqual(
                2,
                substr_count($modals, "indexOf('/{$portal}/')"),
                "Shared profile routing does not cover the {$portal} portal."
            );
        }

        self::assertStringContainsString("window.APP_BASE_URL.replace", $modals);
        self::assertStringContainsString("basePath + '/api/index.php'", $modals);
        self::assertStringContainsString("basePath + '/auth/forgot-password.php'", $modals);
    }

    public function testSettingsAndProfileGuardAgainstNonJsonServerResponses(): void
    {
        $main = (string)file_get_contents(APP_ROOT . '/assets/js/main.js');
        $modals = (string)file_get_contents(APP_ROOT . '/includes/modals.php');

        self::assertStringContainsString('path.includes("/principal/")', $main);
        self::assertStringContainsString('function appReadJsonResponse(', $main);
        self::assertStringContainsString('The server returned an invalid response.', $main);
        self::assertStringContainsString('window.appReadJsonResponse(response', $modals);
    }
}
