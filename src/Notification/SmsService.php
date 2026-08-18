<?php

namespace BshsAms\Notification;

use PDO;
use Throwable;

class SmsService
{
    private string $provider;
    private string $apiKey;
    private string $senderName;
    private string $endpoint;

    public function __construct(?string $provider = null, ?string $apiKey = null, ?string $senderName = null, ?string $endpoint = null)
    {
        $this->provider = strtolower(trim((string)($provider ?? (getenv('SMS_PROVIDER') ?: 'philsms'))));
        $this->apiKey = trim((string)($apiKey ?? (getenv('SMS_API_KEY') ?: '')));
        $this->senderName = trim((string)($senderName ?? (getenv('SMS_SENDER_NAME') ?: 'BSHS-AMS')));
        $this->endpoint = trim((string)($endpoint ?? (getenv('SMS_ENDPOINT') ?: '')));
    }

    public function isConfigured(): bool
    {
        if ($this->provider === 'log') {
            return true;
        }
        return $this->apiKey !== '';
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    /**
     * Normalize a Philippine mobile number into standard formats.
     * Supported formats:
     * - '63': '639171234567' (e.g. for PhilSMS, Semaphore)
     * - '09': '09171234567' (local format)
     * - 'e164': '+639171234567' (e.g. for Twilio)
     */
    public static function normalizePhilippineNumber(string $phone, string $format = '63'): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === null || $digits === '') {
            return null;
        }

        // 09XXXXXXXXX (11 digits starting with 09)
        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            $national = substr($digits, 1); // 9XXXXXXXXX
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            $national = substr($digits, 2); // 9XXXXXXXXX
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $national = $digits; // 9XXXXXXXXX
        } else {
            return null;
        }

        if (strlen($national) !== 10) {
            return null;
        }

        return match ($format) {
            '09' => '0' . $national,
            'e164' => '+63' . $national,
            default => '63' . $national,
        };
    }

    /**
     * Send an SMS message using the configured provider and record it in sms_logs if database is available.
     *
     * @param string $to Recipient phone number (normalized automatically)
     * @param string $message Text content to deliver
     * @param int|null $recipientUserId Optional user ID of recipient for audit logging
     * @param PDO|null $db Optional database connection for sms_logs
     * @return array Result array ['success' => bool, 'provider' => string, 'error' => ?string]
     */
    public function send(string $to, string $message, ?int $recipientUserId = null, ?PDO $db = null): array
    {
        $normalizedPhone = self::normalizePhilippineNumber($to, $this->provider === 'twilio' ? 'e164' : '63');
        if ($normalizedPhone === null) {
            $result = [
                'success' => false,
                'provider' => $this->provider,
                'error' => 'Invalid Philippine mobile number: ' . $to,
            ];
            if ($db !== null) {
                $this->logDelivery($db, $recipientUserId, $to, $message, $this->provider, 'failed', null, $result['error']);
            }
            return $result;
        }

        if ($this->provider === 'log' || $this->apiKey === '') {
            $result = $this->sendLog($normalizedPhone, $message);
        } else {
            $result = match ($this->provider) {
                'philsms' => $this->sendPhilSms($normalizedPhone, $message),
                'semaphore' => $this->sendSemaphore($normalizedPhone, $message),
                'twilio' => $this->sendTwilio($normalizedPhone, $message),
                default => $this->sendLog($normalizedPhone, $message),
            };
        }

        if ($db !== null) {
            $status = $result['success'] ? ($result['provider'] === 'log' ? 'logged' : 'sent') : 'failed';
            $this->logDelivery(
                $db,
                $recipientUserId,
                $normalizedPhone,
                $message,
                $this->provider,
                $status,
                $result['response'] ?? null,
                $result['error'] ?? null
            );
        }

        return $result;
    }

    public function sendPhilSms(string $to, string $message): array
    {
        $endpoint = $this->endpoint !== '' ? $this->endpoint : 'https://dashboard.philsms.com/api/v3/sms/send';
        if (!str_ends_with($endpoint, '/sms/send') && !str_ends_with($endpoint, '/sms/send/')) {
            $endpoint = rtrim($endpoint, '/') . '/sms/send';
        }
        $payload = [
            'recipient' => $to,
            'sender_id' => $this->senderName !== '' ? $this->senderName : 'PhilSMS',
            'type' => 'plain',
            'message' => $message,
        ];

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                error_log("[SMS] PhilSMS cURL error: " . $curlError);
                return ['success' => false, 'provider' => 'philsms', 'error' => 'cURL error: ' . $curlError];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'provider' => 'philsms', 'response' => (string)$response];
            }

            error_log("[SMS] PhilSMS error (HTTP {$httpCode}): {$response}");
            return ['success' => false, 'provider' => 'philsms', 'error' => "HTTP {$httpCode}: {$response}", 'response' => (string)$response];
        } catch (Throwable $e) {
            error_log("[SMS] PhilSMS exception: " . $e->getMessage());
            return ['success' => false, 'provider' => 'philsms', 'error' => $e->getMessage()];
        }
    }

    public function sendSemaphore(string $to, string $message): array
    {
        $endpoint = $this->endpoint !== '' ? $this->endpoint : 'https://api.semaphore.co/api/v4/messages';
        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'apikey' => $this->apiKey,
                    'number' => $to,
                    'message' => $message,
                    'sendername' => $this->senderName,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                error_log("[SMS] Semaphore cURL error: " . $curlError);
                return ['success' => false, 'provider' => 'semaphore', 'error' => 'cURL error: ' . $curlError];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'provider' => 'semaphore', 'response' => (string)$response];
            }

            error_log("[SMS] Semaphore error (HTTP {$httpCode}): {$response}");
            return ['success' => false, 'provider' => 'semaphore', 'error' => "HTTP {$httpCode}: {$response}", 'response' => (string)$response];
        } catch (Throwable $e) {
            error_log("[SMS] Semaphore exception: " . $e->getMessage());
            return ['success' => false, 'provider' => 'semaphore', 'error' => $e->getMessage()];
        }
    }

    public function sendTwilio(string $to, string $message): array
    {
        $parts = explode(':', $this->apiKey, 2);
        if (count($parts) !== 2) {
            return ['success' => false, 'provider' => 'twilio', 'error' => 'Twilio API key must be AccountSID:AuthToken'];
        }
        [$accountSid, $authToken] = $parts;
        $endpoint = $this->endpoint !== '' ? $this->endpoint : "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
                CURLOPT_POSTFIELDS => http_build_query([
                    'To' => $to,
                    'From' => $this->senderName,
                    'Body' => $message,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                error_log("[SMS] Twilio cURL error: " . $curlError);
                return ['success' => false, 'provider' => 'twilio', 'error' => 'cURL error: ' . $curlError];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'provider' => 'twilio', 'response' => (string)$response];
            }

            error_log("[SMS] Twilio error (HTTP {$httpCode}): {$response}");
            return ['success' => false, 'provider' => 'twilio', 'error' => "HTTP {$httpCode}: {$response}", 'response' => (string)$response];
        } catch (Throwable $e) {
            error_log("[SMS] Twilio exception: " . $e->getMessage());
            return ['success' => false, 'provider' => 'twilio', 'error' => $e->getMessage()];
        }
    }

    public function sendLog(string $to, string $message): array
    {
        error_log("[SMS LOG] To: {$to} | Provider: {$this->provider} | Sender: {$this->senderName} | Message: {$message}");
        return ['success' => true, 'provider' => 'log', 'response' => 'logged'];
    }

    public function logDelivery(
        PDO $db,
        ?int $recipientUserId,
        string $phone,
        string $message,
        string $provider,
        string $status,
        ?string $responseData,
        ?string $errorMessage
    ): void {
        try {
            $this->ensureLogsTable($db);
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $nowExpr = $driver === 'sqlite' ? "datetime('now')" : "NOW()";
            $stmt = $db->prepare("INSERT INTO sms_logs (recipient_user_id, recipient_phone, message, provider, status, response_data, error_message, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, {$nowExpr})");
            $stmt->execute([
                $recipientUserId > 0 ? $recipientUserId : null,
                $phone,
                $message,
                $provider,
                $status,
                $responseData,
                $errorMessage,
            ]);
        } catch (Throwable $e) {
            error_log("[SMS] Failed to write delivery log: " . $e->getMessage());
        }
    }

    public function ensureLogsTable(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS sms_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient_user_id INTEGER NULL,
                recipient_phone TEXT NOT NULL,
                message TEXT NOT NULL,
                provider TEXT NOT NULL,
                status TEXT DEFAULT 'queued',
                response_data TEXT NULL,
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS sms_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_user_id INT NULL,
                recipient_phone VARCHAR(30) NOT NULL,
                message TEXT NOT NULL,
                provider VARCHAR(50) NOT NULL,
                status ENUM('queued', 'sent', 'failed', 'logged') DEFAULT 'queued',
                response_data TEXT NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_logs_user (recipient_user_id),
                INDEX idx_sms_logs_status (status),
                INDEX idx_sms_logs_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
}
