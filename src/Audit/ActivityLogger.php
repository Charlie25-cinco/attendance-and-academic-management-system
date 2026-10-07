<?php

namespace BshsAms\Audit;

use PDO;
use Throwable;

final class ActivityLogger
{
    private const REDACTED_KEYS = [
        'password', 'password_hash', 'token', 'secret', 'api_key', 'authorization',
        'contact_number', 'phone', 'email', 'message', 'notification_content',
        'lrn', 'reference_code', 'name', 'title', 'content', 'address',
    ];

    public static function record(
        PDO $db,
        int $actorUserId,
        string $actorRole,
        string $actionName,
        string $targetType,
        ?int $targetId = null,
        array $details = [],
        ?string $ipAddress = null
    ): bool {
        if ($actorUserId <= 0 || trim($actorRole) === '') {
            return false;
        }

        try {
            $stmt = $db->prepare('INSERT INTO activity_logs
                (actor_user_id, actor_role, action_name, target_type, target_id, details_json, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            return $stmt->execute([
                $actorUserId,
                substr(strtolower(trim($actorRole)), 0, 30),
                substr(trim($actionName), 0, 100),
                substr(trim($targetType), 0, 50),
                $targetId !== null && $targetId > 0 ? $targetId : null,
                $details === [] ? null : json_encode(self::sanitize($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $ipAddress !== null && $ipAddress !== '' ? substr($ipAddress, 0, 45) : null,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function sanitize(array $details): array
    {
        $clean = [];
        foreach ($details as $key => $value) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, self::REDACTED_KEYS, true)
                || preg_match('/(?:password|token|secret|phone|contact|email|message|address|reference_code|lrn)/', $normalized)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }
        return $clean;
    }
}
