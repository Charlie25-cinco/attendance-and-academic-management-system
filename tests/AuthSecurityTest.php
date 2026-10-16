<?php

use BshsAms\Security\AuthSecurity;
use PHPUnit\Framework\TestCase;

final class AuthSecurityTest extends TestCase
{
    private PDO $db;
    private string $originalHash;
    private array $session;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, reference_code TEXT, email TEXT, first_name TEXT,
            last_name TEXT, role TEXT, status TEXT, password TEXT, api_token_version INTEGER DEFAULT 3, updated_at TEXT)");
        $this->db->exec("CREATE TABLE rate_limits (context TEXT, action_key TEXT, identifier_hash TEXT, attempts INTEGER,
            first_attempt INTEGER, expires_at TEXT, UNIQUE(context, action_key, identifier_hash))");
        foreach (['auth_password_resets', 'auth_password_change_tokens'] as $table) {
            $this->db->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT, expires_at TEXT, used_at TEXT)");
        }
        $this->db->exec('CREATE TABLE auth_remember_tokens (user_id INTEGER, revoked_at TEXT)');
        putenv('DEFAULT_NEW_USER_PASSWORD=Initial!Password123');
        $this->originalHash = password_hash('Initial!Password123', PASSWORD_DEFAULT);
        $this->db->prepare("INSERT INTO users VALUES (1, 'T1', 'teacher@example.test', 'Jane', 'Doe', 'teacher', 'active', ?, 3, NULL)")
            ->execute([$this->originalHash]);
        $this->db->exec('INSERT INTO auth_remember_tokens VALUES (1, NULL)');
        $this->session = ['logged_in' => true, 'user_id' => 1, 'role' => 'teacher', 'auth_token_version' => 3,
            'pending_password_change' => true, 'pending_user_id' => 1, 'pending_token_version' => 3,
            'pending_password_until' => time() + 900, 'csrf_token' => 'correct-token'];
    }

    protected function tearDown(): void
    {
        putenv('DEFAULT_NEW_USER_PASSWORD');
    }

    private function token(string $table, string $value, string $expiry = '+10 minutes'): void
    {
        $this->db->prepare("INSERT INTO {$table} (user_id, token_hash, expires_at) VALUES (1, ?, datetime('now', ?))")
            ->execute([$table === 'auth_password_change_tokens' ? AuthSecurity::passwordChangeTokenHash($value, 3) : hash('sha256', $value), $expiry]);
    }

    private function passwordInput(string $csrf = 'correct-token'): array
    {
        return ['csrf_token' => $csrf, 'new_password' => 'Replacement!Password456', 'confirm_password' => 'Replacement!Password456'];
    }

    public function testInvalidSetupCsrfCannotWriteEvenWithValidMatchingPasswords(): void
    {
        foreach (['wrong-token', ''] as $token) {
            $error = null;
            self::assertNull(AuthSecurity::completePasswordSetup($this->db, $this->session, $this->passwordInput($token), $error));
            self::assertStringContainsString('Invalid request', $error);
            self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
            self::assertSame(3, (int)$this->db->query('SELECT api_token_version FROM users')->fetchColumn());
        }
    }

    public function testSetupExpiresAndCannotOverwriteAnAccountAfterRevocation(): void
    {
        $expired = $this->session;
        $expired['pending_password_until'] = time() - 1;
        self::assertNull(AuthSecurity::completePasswordSetup($this->db, $expired, $this->passwordInput(), $error));
        bumpUserApiTokenVersion($this->db, 1);
        self::assertNull(AuthSecurity::completePasswordSetup($this->db, $this->session, $this->passwordInput(), $error));
        self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testValidSetupChangesPasswordOnceAndInvalidatesOlderSessions(): void
    {
        self::assertTrue(AuthSecurity::sessionIsCurrent($this->db, $this->session));
        $user = AuthSecurity::completePasswordSetup($this->db, $this->session, $this->passwordInput(), $error);
        self::assertSame(4, $user['api_token_version']);
        self::assertTrue(password_verify('Replacement!Password456', $this->db->query('SELECT password FROM users')->fetchColumn()));
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $this->session));
        $current = $this->session;
        $current['auth_token_version'] = 4;
        self::assertTrue(AuthSecurity::sessionIsCurrent($this->db, $current));
        self::assertNull(AuthSecurity::completePasswordSetup($this->db, $this->session, $this->passwordInput(), $error));
    }

    public function testResetLimitIsSharedAcrossEmailCaseAndIndependentOfSessionOrIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $_SERVER['REMOTE_ADDR'] = '192.0.2.' . ($i + 1);
            self::assertSame(0, AuthSecurity::reserveResetAttempt($this->db, $i % 2 ? ' TEACHER@EXAMPLE.TEST ' : 'teacher@example.test'));
        }
        self::assertGreaterThan(0, AuthSecurity::reserveResetAttempt($this->db, 'teacher@example.test'));
        self::assertSame(0, AuthSecurity::reserveResetAttempt($this->db, 'other@example.test'));
        $this->db->prepare("UPDATE rate_limits SET first_attempt = ? WHERE identifier_hash = ?")
            ->execute([time() - 901, hash('sha256', 'teacher@example.test')]);
        self::assertSame(0, AuthSecurity::reserveResetAttempt($this->db, 'teacher@example.test'));
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testResetConsumesAllRecoveryCredentialsAndRejectsReplay(): void
    {
        $this->token('auth_password_resets', '123456');
        $this->token('auth_password_change_tokens', 'temporary-token');
        $hash = password_hash('Replacement!Password456', PASSWORD_DEFAULT);
        $user = AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', $hash);
        self::assertSame(4, $user['api_token_version']);
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $this->session));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM auth_password_resets WHERE used_at IS NULL')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM auth_password_change_tokens WHERE used_at IS NULL')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM auth_remember_tokens WHERE revoked_at IS NULL')->fetchColumn());
        self::assertNull(AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'must-not-be-written'));
        self::assertSame($hash, $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testWrongExpiredAndInactiveResetRequestsDoNotChangePassword(): void
    {
        $this->token('auth_password_resets', '123456', '-1 minute');
        self::assertNull(AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'invalid'));
        $this->token('auth_password_resets', '654321');
        self::assertNull(AuthSecurity::resetPassword($this->db, 'teacher@example.test', '000000', 'invalid'));
        $this->db->exec("UPDATE users SET status = 'inactive'");
        self::assertNull(AuthSecurity::resetPassword($this->db, 'teacher@example.test', '654321', 'invalid'));
        self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testLostAtomicClaimCannotChangePassword(): void
    {
        $this->token('auth_password_resets', '123456');
        $this->db->exec("CREATE TRIGGER lost_claim BEFORE UPDATE ON auth_password_resets BEGIN SELECT RAISE(IGNORE); END");
        self::assertNull(AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'invalid'));
        self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testVersionFailureRollsBackPasswordAndTokenConsumption(): void
    {
        $this->token('auth_password_resets', '123456');
        $this->db->exec("CREATE TRIGGER reject_version BEFORE UPDATE OF api_token_version ON users BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'invalid');
            self::fail('Revocation failure must abort the password reset.');
        } catch (PDOException $e) {
            self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
            self::assertNull($this->db->query('SELECT used_at FROM auth_password_resets')->fetchColumn());
            self::assertFalse($this->db->inTransaction());
        }
    }

    public function testTemporaryTokenIsAccountBoundAndSingleUse(): void
    {
        $this->token('auth_password_change_tokens', 'temporary-token');
        self::assertNull(AuthSecurity::changePasswordWithToken($this->db, 'OTHER', 'temporary-token', 'invalid'));
        self::assertNotNull(AuthSecurity::changePasswordWithToken($this->db, 'T1', 'temporary-token', 'replacement-hash'));
        self::assertNull(AuthSecurity::changePasswordWithToken($this->db, 'T1', 'temporary-token', 'invalid'));
        self::assertSame('replacement-hash', $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testTemporaryTokenFromAnOlderCredentialVersionIsRejected(): void
    {
        $this->token('auth_password_change_tokens', 'temporary-token');
        bumpUserApiTokenVersion($this->db, 1);
        self::assertNull(AuthSecurity::changePasswordWithToken($this->db, 'T1', 'temporary-token', 'invalid'));
        self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
    }

    public function testRememberRevocationFailureRollsBackReset(): void
    {
        $this->token('auth_password_resets', '123456');
        $this->db->exec("CREATE TRIGGER reject_remember BEFORE UPDATE ON auth_remember_tokens BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'invalid');
            self::fail('Remember credential revocation failure must abort the reset.');
        } catch (PDOException $e) {
            self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
            self::assertSame(3, (int)$this->db->query('SELECT api_token_version FROM users')->fetchColumn());
            self::assertNull($this->db->query('SELECT used_at FROM auth_password_resets')->fetchColumn());
        }
    }

    public function testLostVersionUpdateCannotBeReportedAsSuccessful(): void
    {
        $this->token('auth_password_resets', '123456');
        $this->db->exec("CREATE TRIGGER lost_version BEFORE UPDATE OF api_token_version ON users BEGIN SELECT RAISE(IGNORE); END");
        try {
            AuthSecurity::resetPassword($this->db, 'teacher@example.test', '123456', 'invalid');
            self::fail('A zero-row credential version update must abort the reset.');
        } catch (RuntimeException $e) {
            self::assertSame($this->originalHash, $this->db->query('SELECT password FROM users')->fetchColumn());
            self::assertNull($this->db->query('SELECT used_at FROM auth_password_resets')->fetchColumn());
        }
    }

    public function testDeactivationAndReactivationCannotResurrectOldCredentials(): void
    {
        AuthSecurity::setAccountStatus($this->db, 1, 'inactive');
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $this->session));
        AuthSecurity::setAccountStatus($this->db, 1, 'active');
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $this->session));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM auth_remember_tokens WHERE revoked_at IS NULL')->fetchColumn());
        $unversioned = $this->session;
        unset($unversioned['auth_token_version']);
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $unversioned));
    }

    public function testSyncCannotChangeProtectedAccountsButCanUpdateOrdinaryUsers(): void
    {
        $route = '';
        $method = 'GET';
        require_once APP_ROOT . '/api/routes/11-sync.php';
        foreach (['principal', 'admin'] as $role) {
            $this->db->prepare('UPDATE users SET role = ? WHERE id = 1')->execute([$role]);
            self::assertFalse(syncUpdateExistingUser($this->db, 1, 'Changed', 'Name', 'changed@example.test', 'inactive'));
            $user = $this->db->query('SELECT first_name, email, status, api_token_version FROM users')->fetch(PDO::FETCH_ASSOC);
            self::assertSame('Jane', $user['first_name']);
            self::assertSame('teacher@example.test', $user['email']);
            self::assertSame('active', $user['status']);
            self::assertSame(3, (int)$user['api_token_version']);
        }
        $this->db->exec("UPDATE users SET role = 'teacher'");
        self::assertTrue(syncUpdateExistingUser($this->db, 1, 'Updated', 'Teacher', 'updated@example.test', 'inactive'));
        self::assertSame('Updated', $this->db->query('SELECT first_name FROM users')->fetchColumn());
        self::assertFalse(AuthSecurity::sessionIsCurrent($this->db, $this->session));
    }
}
