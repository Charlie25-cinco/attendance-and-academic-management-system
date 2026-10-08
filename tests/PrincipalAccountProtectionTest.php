<?php
declare(strict_types=1);

namespace Tests;

use BshsAms\User\SystemAccountPolicy;
use PHPUnit\Framework\TestCase;

final class PrincipalAccountProtectionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DEFAULT_NEW_USER_PASSWORD');
    }

    public function testPrincipalRoleIsAProtectedSystemAccount(): void
    {
        self::assertTrue(SystemAccountPolicy::isPrincipalRole('principal'));
        self::assertTrue(SystemAccountPolicy::isPrincipalRole(' Principal '));
        self::assertFalse(SystemAccountPolicy::adminMayMutateRole('principal'));
        self::assertTrue(SystemAccountPolicy::adminMayMutateRole('teacher'));
        self::assertSame('PR341227-1', SystemAccountPolicy::PRINCIPAL_REFERENCE_CODE);
    }

    public function testSharedDefaultPasswordRequiresFirstLoginChangeForEveryRole(): void
    {
        putenv('DEFAULT_NEW_USER_PASSWORD=Shared!Default123');

        $defaultHash = password_hash('Shared!Default123', PASSWORD_BCRYPT);
        $changedHash = password_hash('Changed!Account123', PASSWORD_BCRYPT);

        foreach (['principal', 'admin', 'teacher', 'student', 'parent'] as $role) {
            self::assertTrue(appUserRequiresPasswordChange($role, $defaultHash), $role);
            self::assertFalse(appUserRequiresPasswordChange($role, $changedHash), $role);
        }
    }

    public function testSeedTemplateContainsSeparateProtectedAccountsWithoutPlaintextPasswords(): void
    {
        $sql = (string)file_get_contents(APP_ROOT . '/database/seed_admin.sql');
        $seeder = (string)file_get_contents(APP_ROOT . '/database/seed_admin.php');
        $generator = (string)file_get_contents(APP_ROOT . '/scripts/generate_seed_accounts_sql.php');

        self::assertStringContainsString("'A341227-1'", $sql);
        self::assertStringContainsString("'PR341227-1'", $sql);
        self::assertStringContainsString("'principal'", $sql);
        self::assertSame(2, substr_count($sql, '{{DEFAULT_PASSWORD_HASH}}'));
        self::assertStringNotContainsString('Temporary admin login', $sql);
        self::assertStringContainsString('getDefaultNewUserPassword()', $seeder);
        self::assertStringContainsString('getDefaultNewUserPassword()', $generator);
        self::assertStringNotContainsString('FIRST_RUN_ADMIN_PASSWORD', $seeder . $generator . $sql);
        self::assertStringNotContainsString('FIRST_RUN_PRINCIPAL_PASSWORD', $seeder . $generator . $sql);
        self::assertStringContainsString('storage/generated/seed_system_accounts.sql', $generator);
    }

    public function testAdminWebAndApiMutationsEnforcePrincipalProtection(): void
    {
        $action = (string)file_get_contents(APP_ROOT . '/admin/admin_Users_Action.php');
        $api = (string)file_get_contents(APP_ROOT . '/api/routes/06-admin.php');
        $page = (string)file_get_contents(APP_ROOT . '/admin/admin_Users.php');
        $modals = (string)file_get_contents(APP_ROOT . '/includes/modals/user_modals.php');

        self::assertGreaterThanOrEqual(5, substr_count($action, 'SystemAccountPolicy::isPrincipalRole'));
        self::assertGreaterThanOrEqual(2, substr_count($api, 'SystemAccountPolicy::isPrincipalRole'));
        self::assertStringNotContainsString('<option value="principal">Principal</option>', $modals);
        self::assertStringContainsString("(\$user['role'] ?? '') !== 'principal'", $page);
        self::assertStringContainsString('Principal credentials are managed outside the Admin portal', $page);
    }

    public function testAllLoginBoundariesUseCentralTemporaryPasswordRule(): void
    {
        foreach ([
            APP_ROOT . '/auth/login.php',
            APP_ROOT . '/functions/bootstrap.php',
            APP_ROOT . '/api/routes/02-auth.php',
        ] as $path) {
            $content = (string)file_get_contents($path);
            self::assertStringContainsString('appUserRequiresPasswordChange(', $content, $path);
        }
    }

    public function testDeploymentWorkflowRequiresOnlySharedDefaultPassword(): void
    {
        $workflow = (string)file_get_contents(APP_ROOT . '/.github/workflows/wasmer-deploy.yml');

        self::assertStringContainsString('DEFAULT_NEW_USER_PASSWORD', $workflow);
        self::assertStringNotContainsString('FIRST_RUN_ADMIN_PASSWORD', $workflow);
        self::assertStringNotContainsString('FIRST_RUN_PRINCIPAL_PASSWORD', $workflow);
    }
}
