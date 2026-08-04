<?php

namespace BshsAms\Database;

use PDO;
use SessionHandlerInterface;
use Throwable;

class SessionHandler implements SessionHandlerInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $stmt = $this->db->prepare("SELECT payload FROM app_sessions WHERE id = ? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$id]);
            $payload = $stmt->fetchColumn();
            return is_string($payload) ? $payload : '';
        } catch (Throwable $e) {
            error_log('Database session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)ini_get('session.gc_maxlifetime'));
            $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $stmt = $this->db->prepare(
                "INSERT INTO app_sessions (id, user_id, payload, ip_address, user_agent, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    payload = VALUES(payload),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    expires_at = VALUES(expires_at),
                    updated_at = NOW()"
            );
            return $stmt->execute([$id, $userId, $data, $ip, $agent, $expiresAt]);
        } catch (Throwable $e) {
            error_log('Database session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM app_sessions WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (Throwable $e) {
            error_log('Database session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM app_sessions WHERE expires_at <= NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Database session garbage collection failed: ' . $e->getMessage());
            return false;
        }
    }
}
