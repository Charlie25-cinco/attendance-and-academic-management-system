<?php

namespace BshsAms\Security;

use PDO;
use Throwable;

final class AuthSecurity
{
    public const RESET_ATTEMPTS = 5;
    public const RESET_WINDOW = 900;

    /** Reserve an attempt before verification; shared by web/API and independent of IP/session. */
    public static function reserveResetAttempt(PDO $db, string $email): int
    {
        $hash = hash('sha256', strtolower(trim($email)));
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $db->beginTransaction();
        try {
            $now = time();
            $dbNow = (string)$db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            $expires = date('Y-m-d H:i:s', strtotime($dbNow) + self::RESET_WINDOW);
            $sql = "INSERT INTO rate_limits (context, action_key, identifier_hash, attempts, first_attempt, expires_at)
                    VALUES ('auth', 'reset-password', ?, 0, ?, ?)";
            $sql .= $sqlite ? ' ON CONFLICT DO NOTHING' : ' ON DUPLICATE KEY UPDATE identifier_hash = identifier_hash';
            $db->prepare($sql)->execute([$hash, $now, $expires]);
            $stmt = $db->prepare("SELECT attempts, first_attempt FROM rate_limits
                                 WHERE context = 'auth' AND action_key = 'reset-password' AND identifier_hash = ?"
                                 . ($sqlite ? '' : ' FOR UPDATE'));
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $start = (int)$row['first_attempt'];
            $attempts = (int)$row['attempts'];
            if ($now >= $start + self::RESET_WINDOW) {
                $start = $now;
                $attempts = 0;
            }
            if ($attempts >= self::RESET_ATTEMPTS) {
                $db->commit();
                return max(1, $start + self::RESET_WINDOW - $now);
            }
            $stmt = $db->prepare("UPDATE rate_limits SET attempts = ?, first_attempt = ?, expires_at = ?
                                 WHERE context = 'auth' AND action_key = 'reset-password' AND identifier_hash = ?");
            $stmt->execute([$attempts + 1, $start, $expires, $hash]);
            $db->commit();
            return 0;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public static function sessionIsCurrent(PDO $db, array $session): bool
    {
        if (!isset($session['auth_token_version']) || empty($session['user_id'])) {
            return false;
        }
        $stmt = $db->prepare('SELECT role, status, api_token_version FROM users WHERE id = ?');
        $stmt->execute([(int)$session['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user && $user['status'] === 'active'
            && $user['role'] === ($session['role'] ?? '')
            && (int)$user['api_token_version'] === (int)$session['auth_token_version'];
    }

    public static function resetPassword(PDO $db, string $email, string $code, string $hash): ?array
    {
        return self::replacePassword($db, 'email', strtolower(trim($email)), 'auth_password_resets', $code, $hash);
    }

    public static function changePasswordWithToken(PDO $db, string $reference, string $token, string $hash): ?array
    {
        return self::replacePassword($db, 'reference_code', $reference, 'auth_password_change_tokens', $token, $hash);
    }

    public static function passwordChangeTokenHash(string $token, int $version): string
    {
        return hash('sha256', $token . '|' . $version);
    }

    private static function replacePassword(PDO $db, string $column, string $identifier, string $table, string $token, string $hash): ?array
    {
        // Column/table names are private, fixed call-site constants, never request input.
        $db->beginTransaction();
        try {
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $stmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role, status, api_token_version
                                 FROM users WHERE {$column} = ? AND status = 'active' LIMIT 1" . $lock);
            $stmt->execute([$identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) { $db->rollBack(); return null; }
            $stmt = $db->prepare("SELECT id FROM {$table} WHERE user_id = ? AND token_hash = ?
                                 AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");
            $tokenHash = $table === 'auth_password_change_tokens'
                ? self::passwordChangeTokenHash($token, (int)$user['api_token_version'])
                : hash('sha256', $token);
            $stmt->execute([(int)$user['id'], $tokenHash]);
            $tokenId = $stmt->fetchColumn();
            $claim = $db->prepare("UPDATE {$table} SET used_at = CURRENT_TIMESTAMP
                                  WHERE id = ? AND user_id = ? AND token_hash = ?
                                  AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP");
            $claim->execute([(int)$tokenId, (int)$user['id'], $tokenHash]);
            if ($claim->rowCount() !== 1) { $db->rollBack(); return null; }
            $db->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$hash, (int)$user['id']]);
            bumpUserApiTokenVersion($db, (int)$user['id']);
            self::revokeRecoveryCredentials($db, (int)$user['id']);
            $user['api_token_version'] = (int)$user['api_token_version'] + 1;
            $db->commit();
            return $user;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public static function completePasswordSetup(PDO $db, array $session, array $input, ?string &$error): ?array
    {
        if (!ApiAccessPolicy::validCsrf((string)($session['csrf_token'] ?? ''), [$input['csrf_token'] ?? null])) {
            $error = 'Invalid request. Please refresh and try again.';
            return null;
        }
        $password = (string)($input['new_password'] ?? '');
        if ($password !== (string)($input['confirm_password'] ?? '')) {
            $error = 'Passwords do not match.';
            return null;
        }
        if (!validateStrongPassword($password, $error)) { return null; }
        if (empty($session['pending_password_change']) || !isset($session['pending_token_version'])
            || (int)($session['pending_password_until'] ?? 0) <= time()) {
            $error = 'Password setup expired. Please sign in again.';
            return null;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->beginTransaction();
        try {
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $stmt = $db->prepare("SELECT id, reference_code, email, first_name, last_name, role, password, api_token_version
                                 FROM users WHERE id = ? AND status = 'active'" . $lock);
            $stmt->execute([(int)($session['pending_user_id'] ?? 0)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (int)$user['api_token_version'] !== (int)$session['pending_token_version']
                || !appUserRequiresPasswordChange((string)$user['role'], (string)$user['password'])) {
                $db->rollBack();
                $error = 'Password setup expired. Please sign in again.';
                return null;
            }
            $db->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$hash, (int)$user['id']]);
            bumpUserApiTokenVersion($db, (int)$user['id']);
            self::revokeRecoveryCredentials($db, (int)$user['id']);
            $user['api_token_version'] = (int)$user['api_token_version'] + 1;
            unset($user['password']);
            $db->commit();
            return $user;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public static function revokeRecoveryCredentials(PDO $db, int $userId): void
    {
        $db->prepare('UPDATE auth_remember_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revoked_at IS NULL')
            ->execute([$userId]);
        foreach (['auth_password_resets', 'auth_password_change_tokens'] as $table) {
            $db->prepare("UPDATE {$table} SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL")
                ->execute([$userId]);
        }
    }

    public static function setAccountStatus(PDO $db, int $userId, string $status): void
    {
        if (!in_array($status, ['active', 'pending', 'inactive'], true)) {
            throw new \InvalidArgumentException('Invalid account status.');
        }
        $db->beginTransaction();
        try {
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $roleStmt = $db->prepare('SELECT role FROM users WHERE id = ?' . $lock);
            $roleStmt->execute([$userId]);
            $role = $roleStmt->fetchColumn();
            if ($role === false || \BshsAms\User\SystemAccountPolicy::isPrincipalRole((string)$role)) {
                $db->commit();
                return;
            }
            $db->prepare('UPDATE users SET api_token_version = api_token_version + CASE WHEN status <> ? THEN 1 ELSE 0 END,
                          status = ? WHERE id = ?')->execute([$status, $status, $userId]);
            if ($status !== 'active') { self::revokeRecoveryCredentials($db, $userId); }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }
}
