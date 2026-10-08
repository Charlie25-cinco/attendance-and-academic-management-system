<?php

use BshsAms\Security\ApiAccessPolicy;
use BshsAms\Security\HttpAccessPolicy;
use PHPUnit\Framework\TestCase;

final class SecurityBoundaryTest extends TestCase
{
    public function testPrivateAndAmbiguousPathsAreDenied(): void
    {
        foreach (['/.env', '/api/.api_secret', '/storage/secrets/api_auth', '/database/schema.sql', '/vendor/composer/installed.json', '/config/session.php', '/api/routes/03-profile.php', '/assets/uploads/Materials/test.pdf', '/assets/uploads/ecr/import.xlsx', '/assets/../.env', '/assets/%2e%2e/.env', '/assets\\..\\.env', '/assets/uploads/test.php', '/router.php'] as $path) {
            self::assertFalse(HttpAccessPolicy::allows($path), $path);
        }
        foreach (['/', '/index.php', '/sw.js', '/auth/login.php', '/api/index.php', '/principal/principal.php', '/principal/principal_Pending.php', '/principal/principal_Released.php', '/principal/principal_History.php', '/principal/principal_Action.php', '/teacher/teacher_Action.php', '/assets/css/main.css', '/assets/js/offlineIdentity.js', '/assets/uploads/profile.jpg'] as $path) {
            self::assertTrue(HttpAccessPolicy::allows($path), $path);
        }
    }

    public function testPermissionsCannotBeReplacedByRoleOrWrongMethod(): void
    {
        $required = ApiAccessPolicy::permissions('admin-users', 'POST', 'admin');
        self::assertFalse(ApiAccessPolicy::permits($required, ['users.view']));
        self::assertTrue(ApiAccessPolicy::permits($required, ['users.create']));
        self::assertFalse(ApiAccessPolicy::permits(ApiAccessPolicy::permissions('admin-users', 'DELETE', 'admin'), ['users.create']));
        self::assertFalse(ApiAccessPolicy::permits(ApiAccessPolicy::permissions('unmapped-route', 'GET', 'admin'), ['users.view']));
        self::assertFalse(ApiAccessPolicy::permits(ApiAccessPolicy::permissions('teacher-attendance', 'POST', 'teacher'), ['attendance.view']));
        self::assertTrue(ApiAccessPolicy::permits(ApiAccessPolicy::permissions('profile', 'POST', 'student'), []));
    }

    public function testCsrfRequiresNonemptyMatchingTokenAndAcceptsSupportedCarriers(): void
    {
        self::assertFalse(ApiAccessPolicy::validCsrf('', ['']));
        self::assertFalse(ApiAccessPolicy::validCsrf('secret', [null, 'wrong', ['secret']]));
        for ($i = 0; $i < 4; $i++) {
            $tokens = array_fill(0, 4, null);
            $tokens[$i] = 'secret';
            self::assertTrue(ApiAccessPolicy::validCsrf('secret', $tokens));
        }
    }
}
