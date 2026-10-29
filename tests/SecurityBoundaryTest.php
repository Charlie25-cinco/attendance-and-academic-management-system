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

    public function testHtaccessUsesOrderedRulesWithoutLookaround(): void
    {
        $text = file_get_contents(__DIR__ . '/../.htaccess');
        self::assertDoesNotMatchRegularExpression('/\(\?(?:[=!]|<[=!])/', $text);
        $rules = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)\]/', trim($line), $match)) {
                $rules[] = $match;
            }
        }
        self::assertNotEmpty($rules);
        $paths = [
            '/', '/auth/login.php', '/auth/logout.php', '/admin/admin.php',
            '/api/index.php', '/assets/css/main.css', '/assets/uploads/profile.jpg',
            '/.env', '/config/session.php', '/vendor/composer/installed.json',
            '/storage/secrets/api_auth', '/api/routes/02-auth.php', '/api/INDEX.php',
            '/assets/uploads/Materials/private.jpg', '/assets/uploads/ecr/import.xlsx',
            '/assets/uploads/private.pdf', '/assets/uploads/private.php',
            '/assets/uploads/private.js', '/assets/uploads/private.svg',
            '/assets/uploads/.hidden.jpg', '/teacher/teacher_helper.php', '/router.php',
        ];
        foreach ($paths as $path) {
            $allowed = false;
            foreach ($rules as $rule) {
                $flags = explode(',', $rule[3]);
                $pattern = '~' . str_replace('~', '\\~', $rule[1]) . '~';
                if (in_array('NC', $flags, true)) {
                    $pattern .= 'i';
                }
                if (preg_match($pattern, ltrim($path, '/')) === 1) {
                    $allowed = !in_array('R=404', $flags, true);
                    break;
                }
            }
            self::assertSame(HttpAccessPolicy::allows($path), $allowed, $path);
        }
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
