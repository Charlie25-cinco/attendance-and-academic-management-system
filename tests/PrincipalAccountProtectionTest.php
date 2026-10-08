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
        putenv('FIRST_RUN_ADMIN_PASSWORD');
        putenv('FIRST_RUN_PRINCIPAL_PASSWORD');
    }

    public function testPrincipalRoleIsAProtectedSystemAccount(): void
    {
        self::assertTrue(SystemAccountPolicy::isPrincipalRole('principal'));
        self::assertTrue(SystemAccountPolicy::isPrincipalRole(' Principal '));
        self::assertFalse(SystemAccountPolicy::adminMayMutateRole('principal'));
        self::assertTrue(SystemAccountPolicy::adminMayMutateRole('teacher'));
        self::assertSame('PR341227-1', SystemAccountPolicy::PRINCIPAL_REFERENCE_CODE);
    }

    public function testDistinctBootstrapPasswordsRequireFirstLoginChange(): void
    {
        putenv('DEFAULT_NEW_USER_PASSWORD=Default!Account123');
        putenv('FIRST_RUN_ADMIN_PASSWORD=Admin!Bootstrap123');
        putenv('FIRST_RUN_PRINCIPAL_PASSWORD=Principal!Bootstrap123');

        $principalHash = password_hash('Principal!Bootstrap123', PASSWORD_BCRYPT);
        $adminHash = password_hash('Admin!Bootstrap123', PASSWORD_BCRYPT);
        $changedHash = password_hash('Changed!Account123', PASSWORD_BCRYPT);

        self::assertTrue(appUserRequiresPasswordChange('principal', $principalHash));
        self::assertTrue(appUserRequiresPasswordChange('admin', $adminHash));
        self::assertFalse(appUserRequiresPasswordChange('principal', $changedHash));
        self::assertFalse(appUserRequiresPasswordChange('teacher', $principalHash));
    }

    public function testBootstrapPasswordsCannotBeReusedAcrossRoles(): void
    {
        putenv('DEFAULT_NEW_USER_PASSWORD=Default!Account123');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FIRST_RUN_PRINCIPAL_PASSWORD must be different');
        appAssertDistinctBootstrapPasswords('Admin!Bootstrap123', 'Default!Account123');
    }

    public function testSeedTemplateContainsSeparateProtectedAccountsWithoutPlaintextPasswords(): void
    {
        $sql = (string)file_get_contents(APP_ROOT . '/database/seed_admin.sql');
        $seeder = (string)file_get_contents(APP_ROOT . '/database/seed_admin.php');
        $generator = (string)file_get_contents(APP_ROOT . '/scripts/generate_seed_accounts_sql.php');

        self::assertStringContainsString("'A341227-1'", $sql);
        self::assertStringContainsString("'PR341227-1'", $sql);
        self::assertStringContainsString("'principal'", $sql);
        self::assertStringContainsString('{{ADMIN_PASSWORD_HASH}}', $sql);
        self::assertStringContainsString('{{PRINCIPAL_PASSWORD_HASH}}', $sql);
        self::assertStringNotContainsString('Temporary admin login', $sql);
        self::assertStringContainsString('getFirstRunPrincipalPassword()', $seeder);
        self::assertStringContainsString('getFirstRunPrincipalPassword()', $generator);
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

    public function testDeploymentWorkflowRequiresSeparatePrincipalSecret(): void
    {
        $workflow = (string)file_get_contents(APP_ROOT . '/.github/workflows/wasmer-deploy.yml');

        self::assertStringContainsString('FIRST_RUN_ADMIN_PASSWORD', $workflow);
        self::assertStringContainsString('FIRST_RUN_PRINCIPAL_PASSWORD', $workflow);
        self::assertStringContainsString('bootstrap passwords must be different', $workflow);
    }
}
