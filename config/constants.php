<?php

if (!function_exists('appEnvValue')) {
    function appEnvValue(string $key, string $default = ''): string {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $root = dirname(__DIR__);
            foreach (['.env', '.env.local'] as $filename) {
                $path = $root . DIRECTORY_SEPARATOR . $filename;
                if (!is_readable($path)) {
                    continue;
                }
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $parts = explode('=', $line, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    $cache[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $value = $cache[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}

function appPublicWebBaseUrl(): string {
    $fromEnv = appUrlReadEnv('APP_PUBLIC_BASE_URL');
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $defaultPort = ($scheme === 'https') ? 443 : 80;
    if ($port > 0 && $port !== $defaultPort) {
        $host .= ':' . $port;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*)/(api|auth|component|config|database|src|uploads|site)(?:/|$)#', $script, $m)) {
        return $scheme . '://' . $host . rtrim($m[1], '/');
    }

    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir !== '' && $dir !== '.') {
        return $scheme . '://' . $host . $dir;
    }

    return $scheme . '://' . $host;
}

function appUrlReadEnv(string $key): string {
    $val = getenv($key);
    if ($val !== false && trim((string)$val) !== '') {
        return trim((string)$val);
    }

    static $fileCache = null;
    if ($fileCache === null) {
        $fileCache = [];
        $root = dirname(__DIR__);
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $fileCache[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
    }

    $v = $fileCache[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function smtpConfig(): array {
    return [
        'host' => appEnvValue('SMTP_HOST', ''),
        'port' => (int)appEnvValue('SMTP_PORT', '587'),
        'user' => appEnvValue('SMTP_USER', ''),
        'pass' => appEnvValue('SMTP_PASS', ''),
        'from_email' => appEnvValue('SMTP_FROM_EMAIL', ''),
        'from_name' => appEnvValue('SMTP_FROM_NAME', 'Balingasag Senior High School')
    ];
}

function resendConfig(): array {
    return [
        'api_key' => appEnvValue('RESEND_API_KEY', ''),
        'from_email' => appEnvValue('RESEND_FROM_EMAIL', appEnvValue('SMTP_FROM_EMAIL', '')),
        'from_name' => appEnvValue('RESEND_FROM_NAME', appEnvValue('SMTP_FROM_NAME', 'Balingasag Senior High School'))
    ];
}

function resendConfigured(): bool {
    $cfg = resendConfig();
    return $cfg['api_key'] !== '' && $cfg['from_email'] !== '';
}

function smtpConfigured(): bool {
    $cfg = smtpConfig();
    return $cfg['user'] !== '' && $cfg['pass'] !== '';
}

function mailerConfigured(): bool {
    return resendConfigured() || smtpConfigured();
}

function resendSendHtml(string $to, string $subject, string $html, string $fromEmail = '', string $fromName = ''): bool {
    if (!resendConfigured()) {
        return false;
    }

    $cfg = resendConfig();
    $from = $fromEmail !== '' ? $fromEmail : $cfg['from_email'];
    $name = $fromName !== '' ? $fromName : $cfg['from_name'];
    $fromHeader = trim($name) !== '' ? trim($name) . ' <' . $from . '>' : $from;

    $payload = json_encode([
        'from' => $fromHeader,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html
    ]);
    if (!is_string($payload)) {
        return false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Authorization: Bearer ' . $cfg['api_key'],
                'Content-Type: application/json',
                'User-Agent: BSHS-AMS/1.0'
            ]),
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 20
        ]
    ]);

    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $statusLine = (string)($http_response_header[0] ?? '');
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        error_log('Resend email failed: ' . $statusLine . ' ' . substr((string)$response, 0, 500));
        return false;
    }
    return true;
}

function smtpSendHtml(string $to, string $subject, string $html, string $fromEmail = '', string $fromName = ''): bool {
    if (!smtpConfigured()) {
        return false;
    }

    $cfg = smtpConfig();
    $host = $cfg['host'];
    $port = $cfg['port'];
    $user = $cfg['user'];
    $pass = $cfg['pass'];
    $from = $fromEmail !== '' ? $fromEmail : ($cfg['from_email'] ?: $user);
    $name = $fromName !== '' ? $fromName : $cfg['from_name'];

    $to = str_replace(["\r", "\n"], '', $to);
    $from = str_replace(["\r", "\n"], '', $from);
    $name = str_replace(["\r", "\n"], '', $name);
    $subject = str_replace(["\r", "\n"], '', $subject);

    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }

    $read = function () use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return $data;
    };
    $expect = function (int $code) use ($read) {
        $data = $read();
        return strpos($data, (string)$code) === 0;
    };
    $send = function (string $command, int $code) use ($fp, $expect) {
        fwrite($fp, $command . "\r\n");
        return $expect($code);
    };

    if (!$expect(220)) { fclose($fp); return false; }
    if (!$send('EHLO localhost', 250)) { fclose($fp); return false; }
    if (!$send('STARTTLS', 220)) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
    if (!$send('EHLO localhost', 250)) { fclose($fp); return false; }
    if (!$send('AUTH LOGIN', 334)) { fclose($fp); return false; }
    if (!$send(base64_encode($user), 334)) { fclose($fp); return false; }
    if (!$send(base64_encode($pass), 235)) { fclose($fp); return false; }
    if (!$send("MAIL FROM:<{$from}>", 250)) { fclose($fp); return false; }
    if (!$send("RCPT TO:<{$to}>", 250)) { fclose($fp); return false; }
    if (!$send('DATA', 354)) { fclose($fp); return false; }

    $headers = [];
    $headers[] = "From: {$name} <{$from}>";
    $headers[] = "To: {$to}";
    $headers[] = "Subject: {$subject}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $html;
    $message = str_replace("\n.", "\n..", $message);
    fwrite($fp, $message . "\r\n.\r\n");
    $ok = $expect(250);
    $send('QUIT', 221);
    fclose($fp);
    return $ok;
}

function mailerSendHtml(string $to, string $subject, string $html, string $fromEmail = '', string $fromName = ''): bool {
    if (resendConfigured() && resendSendHtml($to, $subject, $html, $fromEmail, $fromName)) {
        return true;
    }
    return smtpSendHtml($to, $subject, $html, $fromEmail, $fromName);
}

$pushKeysFile = __DIR__ . '/push_keys.php';
$pushFileKeys = [];
if (is_file($pushKeysFile)) {
    $pushFileKeys = include $pushKeysFile;
    if (!is_array($pushFileKeys)) {
        $pushFileKeys = [];
    }
}

if (!defined('PUSH_VAPID_PUBLIC_KEY')) {
    $envPublic = getenv('PUSH_VAPID_PUBLIC_KEY') ?: '';
    define('PUSH_VAPID_PUBLIC_KEY', $envPublic !== '' ? $envPublic : (string)($pushFileKeys['public'] ?? ''));
}
if (!defined('PUSH_VAPID_PRIVATE_KEY')) {
    $envPrivate = getenv('PUSH_VAPID_PRIVATE_KEY') ?: '';
    define('PUSH_VAPID_PRIVATE_KEY', $envPrivate !== '' ? $envPrivate : (string)($pushFileKeys['private'] ?? ''));
}
if (!defined('PUSH_VAPID_SUBJECT')) {
    $envSubject = getenv('PUSH_VAPID_SUBJECT') ?: '';
    define('PUSH_VAPID_SUBJECT', $envSubject !== '' ? $envSubject : (string)($pushFileKeys['subject'] ?? 'mailto:admin@example.com'));
}

function pushConfigReady() {
    return PUSH_VAPID_PUBLIC_KEY !== '' && PUSH_VAPID_PRIVATE_KEY !== '' && PUSH_VAPID_SUBJECT !== '';
}

function pushEnsureSubscriptionsTable($db) {
    $driver = '';
    try { $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable $e) {}
    if (strtolower($driver) === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint VARCHAR(512) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_push_endpoint (endpoint),
        KEY idx_push_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

function pushBase64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function pushBase64UrlDecode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function pushPublicKeyToPem($raw) {
    $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
    $der = $prefix . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n";
    $pem .= chunk_split(base64_encode($der), 64, "\n");
    $pem .= "-----END PUBLIC KEY-----\n";
    return $pem;
}

function pushGetVapidPublicKeyRaw($privatePem) {
    $priv = openssl_pkey_get_private($privatePem);
    if (!$priv) {
        return '';
    }
    $details = openssl_pkey_get_details($priv);
    if (!$details || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        return '';
    }
    return "\x04" . $details['ec']['x'] . $details['ec']['y'];
}

function pushDerToJose($der) {
    $pos = 0;
    if (ord($der[$pos]) !== 0x30) { return ''; }
    $pos++;
    $len = ord($der[$pos++]);
    if ($len & 0x80) {
        $n = $len & 0x1f;
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($der[$pos++]);
        }
    }
    if (ord($der[$pos++]) !== 0x02) { return ''; }
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    if (ord($der[$pos++]) !== 0x02) { return ''; }
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function pushCreateVapidJwt($aud, $sub, $exp, $privatePem) {
    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $payload = ['aud' => $aud, 'exp' => $exp, 'sub' => $sub];
    $headerB64 = pushBase64UrlEncode(json_encode($header));
    $payloadB64 = pushBase64UrlEncode(json_encode($payload));
    $signingInput = $headerB64 . '.' . $payloadB64;

    $priv = openssl_pkey_get_private($privatePem);
    if (!$priv) { return ''; }
    $sig = '';
    openssl_sign($signingInput, $sig, $priv, 'sha256');
    $sigJose = pushDerToJose($sig);
    if ($sigJose === '') { return ''; }
    return $signingInput . '.' . pushBase64UrlEncode($sigJose);
}

function pushHkdfExtract($salt, $ikm) {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function pushHkdfExpand($prk, $info, $length) {
    $t = '';
    $okm = '';
    $blockIndex = 1;
    while (strlen($okm) < $length) {
        $t = hash_hmac('sha256', $t . $info . chr($blockIndex), $prk, true);
        $okm .= $t;
        $blockIndex++;
    }
    return substr($okm, 0, $length);
}

function pushEncryptPayload($payload, $p256dh, $auth) {
    if (
        !function_exists('openssl_pkey_new') ||
        !function_exists('openssl_pkey_derive') ||
        !function_exists('openssl_encrypt')
    ) {
        return null;
    }

    $clientPublicRaw = pushBase64UrlDecode($p256dh);
    $authSecret = pushBase64UrlDecode($auth);
    if ($clientPublicRaw === '' || $authSecret === '') {
        return null;
    }

    $serverKey = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);
    $serverDetails = openssl_pkey_get_details($serverKey);
    if (!$serverDetails || empty($serverDetails['ec']['x']) || empty($serverDetails['ec']['y'])) {
        return null;
    }
    $serverPublicRaw = "\x04" . $serverDetails['ec']['x'] . $serverDetails['ec']['y'];

    $clientPem = pushPublicKeyToPem($clientPublicRaw);
    $clientKey = openssl_pkey_get_public($clientPem);
    if (!$clientKey) {
        return null;
    }
    $sharedSecret = openssl_pkey_derive($clientKey, $serverKey, 32);
    if ($sharedSecret === false || $sharedSecret === '') {
        return null;
    }

    $salt = random_bytes(16);
    $prk = pushHkdfExtract($authSecret, $sharedSecret);
    $context = "WebPush: info\0" . $clientPublicRaw . $serverPublicRaw;
    $ikm = pushHkdfExpand($prk, $context, 32);
    $prk2 = pushHkdfExtract($salt, $ikm);
    $cek = pushHkdfExpand($prk2, "Content-Encoding: aes128gcm\0", 16);
    $nonce = pushHkdfExpand($prk2, "Content-Encoding: nonce\0", 12);

    $plaintext = $payload . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) { return null; }
    $body = $salt . pack('N', 4096) . chr(strlen($serverPublicRaw)) . $serverPublicRaw . $ciphertext . $tag;

    return [
        'body' => $body,
        'server_public_key' => $serverPublicRaw,
    ];
}

function pushSendToSubscription($subscription, $payload) {
    if (!pushConfigReady()) {
        return ['success' => false, 'status' => 0, 'error' => 'push_not_configured'];
    }
    if (!function_exists('curl_init')) {
        error_log('[push] Web Push send skipped: PHP curl extension is not available.');
        return ['success' => false, 'status' => 0, 'error' => 'curl_missing'];
    }
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $p256dh = (string)($subscription['p256dh'] ?? '');
    $auth = (string)($subscription['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return ['success' => false, 'status' => 0, 'error' => 'invalid_subscription'];
    }

    $body = json_encode($payload);
    if ($body === false) { $body = '{}'; }

    $enc = pushEncryptPayload($body, $p256dh, $auth);
    if (!$enc) {
        error_log('[push] Web Push encryption failed.');
        return ['success' => false, 'status' => 0, 'error' => 'encryption_failed'];
    }

    $endpointParts = parse_url($endpoint);
    $aud = ($endpointParts && isset($endpointParts['scheme'], $endpointParts['host']))
        ? $endpointParts['scheme'] . '://' . $endpointParts['host']
        : '';
    $jwt = pushCreateVapidJwt($aud, PUSH_VAPID_SUBJECT, time() + 12 * 60 * 60, PUSH_VAPID_PRIVATE_KEY);
    $vapidPublicRaw = pushGetVapidPublicKeyRaw(PUSH_VAPID_PRIVATE_KEY);
    if ($jwt === '' || $vapidPublicRaw === '') {
        error_log('[push] VAPID signing failed.');
        return ['success' => false, 'status' => 0, 'error' => 'vapid_failed'];
    }

    $headers = [
        'TTL: 300',
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'Content-Length: ' . strlen($enc['body']),
        'Authorization: vapid t=' . $jwt . ', k=' . pushBase64UrlEncode($vapidPublicRaw)
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $enc['body']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $success = $response !== false && $status >= 200 && $status < 300;
    if (!$success) {
        error_log('[push] Web Push send failed. Status: ' . $status . ' Error: ' . $curlError);
    }
    return ['success' => $success, 'status' => $status, 'error' => $curlError];
}

function pushSaveSubscription($db, $userId, $subscription, $userAgent = '') {
    if (!is_array($subscription)) { return false; }
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') { return false; }
    pushEnsureSubscriptionsTable($db);

    $driver = '';
    try { $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable $e) {}
    if (strtolower($driver) === 'sqlite') {
        $stmt = $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, DATETIME('now'), DATETIME('now'))
                              ON CONFLICT(endpoint) DO UPDATE SET
                                user_id = excluded.user_id,
                                p256dh = excluded.p256dh,
                                auth = excluded.auth,
                                user_agent = excluded.user_agent,
                                updated_at = DATETIME('now')");
        return $stmt->execute([(int)$userId, $endpoint, $p256dh, $auth, substr((string)$userAgent, 0, 255)]);
    }

    $stmt = $db->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE
                            user_id = VALUES(user_id),
                            p256dh = VALUES(p256dh),
                            auth = VALUES(auth),
                            user_agent = VALUES(user_agent),
                            updated_at = NOW()");
    return $stmt->execute([(int)$userId, $endpoint, $p256dh, $auth, substr((string)$userAgent, 0, 255)]);
}

function pushDeleteSubscription($db, $userId, $endpoint) {
    if ($endpoint === '') { return false; }
    pushEnsureSubscriptionsTable($db);
    $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
    return $stmt->execute([(int)$userId, $endpoint]);
}

function pushFetchSubscriptions($db, array $userIds) {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) { return $id > 0; })));
    if (empty($userIds)) { return []; }
    pushEnsureSubscriptionsTable($db);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $query = "SELECT ps.user_id, ps.endpoint, ps.p256dh, ps.auth
              FROM push_subscriptions ps
              LEFT JOIN user_settings us ON us.user_id = ps.user_id
              WHERE ps.user_id IN ($placeholders)
              AND (us.user_id IS NULL OR us.push_notifications = 1)";
    $stmt = $db->prepare($query);
    $stmt->execute($userIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pushSendToUserIds($db, array $userIds, array $payload) {
    if (!pushConfigReady()) { return false; }
    $subs = pushFetchSubscriptions($db, $userIds);
    $attempted = 0;
    $sent = 0;
    foreach ($subs as $sub) {
        $attempted++;
        $result = pushSendToSubscription($sub, $payload);
        if (!empty($result['success'])) {
            $sent++;
        }
        if (in_array((int)($result['status'] ?? 0), [404, 410], true)) {
            pushDeleteSubscription($db, (int)$sub['user_id'], (string)$sub['endpoint']);
        }
    }
    if ($attempted > 0 && $sent === 0) {
        error_log('[push] Web Push had subscriptions but no messages were delivered.');
        return false;
    }
    return true;
}

function pushNotifyAttendanceEvent($db, int $studentId, string $status, string $date): bool {
    if ($studentId <= 0 || !$db) {
        return false;
    }
    $stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        return false;
    }
    $studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $statusLabel = ucfirst(strtolower(trim($status)));
    $formattedDate = date('M d, Y', strtotime($date));

    $parentStmt = $db->prepare("SELECT parent_id FROM parent_students WHERE student_id = ?");
    $parentStmt->execute([$studentId]);
    $parentIds = array_map('intval', $parentStmt->fetchAll(PDO::FETCH_COLUMN));

    $targetIds = array_values(array_unique(array_merge([$studentId], $parentIds)));

    $payload = [
        'title' => 'Attendance Update: ' . $statusLabel,
        'body' => $studentName . ' marked ' . strtolower($statusLabel) . ' for ' . $formattedDate,
        'icon' => 'assets/icons/icon-192.png',
        'badge' => 'assets/icons/icon-72.png',
        'url' => 'student/attendance.php',
        'data' => [
            'type' => 'attendance',
            'student_id' => $studentId,
            'status' => $status,
            'date' => $date,
        ],
    ];

    $webPushSent = pushSendToUserIds($db, $targetIds, $payload);
    if (function_exists('pushNotifyUsers')) {
        pushNotifyUsers($db, $targetIds, $payload['title'], $payload['body'], $payload['data']);
    }
    return $webPushSent;
}

function pushNotifyGradePublication($db, int $classId, string $term = 'Final', string $academicYear = ''): bool {
    if ($classId <= 0 || !$db) {
        return false;
    }
    $classStmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ?");
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        return false;
    }
    $className = trim(($class['class_name'] ?? '') . ' (' . ($class['grade_level'] ?? '') . '-' . ($class['section'] ?? '') . ')');

    if (function_exists('pushActiveClassRecipientIds')) {
        $recipients = pushActiveClassRecipientIds($db, $classId);
    } else {
        $sStmt = $db->prepare("SELECT DISTINCT student_id FROM enrollments WHERE class_id = ? AND COALESCE(status, 'enrolled') = 'enrolled'");
        $sStmt->execute([$classId]);
        $sIds = array_map('intval', $sStmt->fetchAll(PDO::FETCH_COLUMN));

        $pIds = [];
        if (!empty($sIds)) {
            $inClause = implode(',', array_fill(0, count($sIds), '?'));
            $pStmt = $db->prepare("SELECT DISTINCT parent_id FROM parent_students WHERE student_id IN ($inClause)");
            $pStmt->execute($sIds);
            $pIds = array_map('intval', $pStmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $recipients = array_values(array_unique(array_merge($sIds, $pIds)));
    }

    if (empty($recipients)) {
        return false;
    }

    $payload = [
        'title' => 'Official Report Cards Released',
        'body' => 'Report cards for ' . $className . ' are now available.',
        'icon' => 'assets/icons/icon-192.png',
        'badge' => 'assets/icons/icon-72.png',
        'url' => 'student/report_card.php',
        'data' => [
            'type' => 'grade_publication',
            'class_id' => $classId,
            'term' => $term,
            'academic_year' => $academicYear,
        ],
    ];

    $webPushSent = pushSendToUserIds($db, $recipients, $payload);
    if (function_exists('pushNotifyUsers')) {
        pushNotifyUsers($db, $recipients, $payload['title'], $payload['body'], $payload['data']);
    }
    return $webPushSent;
}

function smsInitConfig(): void {
    global $smsConfig;
    if ($smsConfig !== null) return;
    $smsConfig = [
        'provider' => strtolower(trim(getenv('SMS_PROVIDER') ?: 'log')),
        'api_key' => trim(getenv('SMS_API_KEY') ?: ''),
        'sender_name' => trim(getenv('SMS_SENDER_NAME') ?: 'BSHS-AMS'),
    ];
}

function smsConfigured(): bool {
    smsInitConfig();
    global $smsConfig;
    return $smsConfig['api_key'] !== '';
}

function smsSend(string $to, string $message): array {
    smsInitConfig();
    global $smsConfig;
    $to = preg_replace('/[^0-9]/', '', $to);
    if ($to === '') {
        return ['success' => false, 'error' => 'Invalid phone number'];
    }
    $provider = $smsConfig['provider'];
    switch ($provider) {
        case 'semaphore': return smsSendSemaphore($to, $message);
        case 'twilio': return smsSendTwilio($to, $message);
        case 'log': default: return smsSendLog($to, $message);
    }
}

function smsSendLog(string $to, string $message): array {
    error_log("[SMS] To: {$to} | Message: {$message}");
    return ['success' => true, 'provider' => 'log'];
}

function smsSendSemaphore(string $to, string $message): array {
    global $smsConfig;
    $apiKey = $smsConfig['api_key'];
    $senderName = $smsConfig['sender_name'];
    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey' => $apiKey, 'number' => $to, 'message' => $message, 'sendername' => $senderName,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) { return ['success' => true, 'provider' => 'semaphore']; }
    error_log("[SMS] Semaphore error (HTTP {$httpCode}): {$response}");
    return ['success' => false, 'error' => "Semaphore returned HTTP {$httpCode}"];
}

function smsSendTwilio(string $to, string $message): array {
    global $smsConfig;
    $apiKey = $smsConfig['api_key'];
    $parts = explode(':', $apiKey, 2);
    if (count($parts) !== 2) { return ['success' => false, 'error' => 'Twilio API key must be in format: AccountSID:AuthToken']; }
    [$accountSid, $authToken] = $parts;
    $from = $smsConfig['sender_name'];
    $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['To' => '+' . $to, 'From' => $from, 'Body' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) { return ['success' => true, 'provider' => 'twilio']; }
    error_log("[SMS] Twilio error (HTTP {$httpCode}): {$response}");
    return ['success' => false, 'error' => "Twilio returned HTTP {$httpCode}"];
}

function pwaHeadHtml(): string {
    $baseUrl = function_exists('appPublicWebBaseUrl') ? appPublicWebBaseUrl() : '';
    $baseUrl = rtrim((string)$baseUrl, '/');
    $manifestUrl = $baseUrl !== '' ? $baseUrl . '/assets/manifest.json' : (function_exists('appAssetPath') ? appAssetPath('manifest.json') : '/assets/manifest.json');
    $appleTouchIcon = $baseUrl !== '' ? $baseUrl . '/assets/images/apple-touch-icon.png' : (function_exists('appAssetPath') ? appAssetPath('images/apple-touch-icon.png') : '/assets/images/apple-touch-icon.png');
    $faviconUrl = $baseUrl !== '' ? $baseUrl . '/assets/images/icon-192.png' : (function_exists('appAssetPath') ? appAssetPath('images/icon-192.png') : '/assets/images/icon-192.png');
    $favicon32Url = $baseUrl !== '' ? $baseUrl . '/assets/images/favicon.png' : (function_exists('appAssetPath') ? appAssetPath('images/favicon.png') : '/assets/images/favicon.png');
    $favicon16Url = $baseUrl !== '' ? $baseUrl . '/assets/images/favicon-16.png' : (function_exists('appAssetPath') ? appAssetPath('images/favicon-16.png') : '/assets/images/favicon-16.png');
    $logoUrl = $baseUrl !== '' ? $baseUrl . '/assets/images/bshs-logo.jpg' : (function_exists('appAssetPath') ? appAssetPath('images/bshs-logo.jpg') : '/assets/images/bshs-logo.jpg');
    $serviceWorkerUrl = $baseUrl !== '' ? $baseUrl . '/sw.js' : '/sw.js';
    $scopeUrl = $baseUrl !== '' ? $baseUrl . '/' : '/';

    $html = '<link rel="manifest" href="' . htmlspecialchars($manifestUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<meta name="theme-color" content="#1f4f82">' . "\n";
    $html .= '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-title" content="BSHS AMS">' . "\n";
    $html .= '<link rel="apple-touch-icon" href="' . htmlspecialchars($appleTouchIcon, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="shortcut icon" type="image/png" href="' . htmlspecialchars($favicon32Url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="icon" type="image/png" sizes="32x32" href="' . htmlspecialchars($favicon32Url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="icon" type="image/png" sizes="16x16" href="' . htmlspecialchars($favicon16Url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="icon" type="image/png" sizes="192x192" href="' . htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="icon" type="image/jpeg" href="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<style id="app-top-progress-critical">'
        . '.app-top-progress{--app-progress:0vw;position:fixed;top:0;left:0;width:var(--app-progress,0vw);max-width:100vw;height:3px;z-index:100000;pointer-events:none;opacity:0;background:linear-gradient(90deg,#1f4f82 0%,#11856f 50%,#3b82f6 100%);box-shadow:0 0 16px rgba(31,79,130,0.55);transition:width .32s cubic-bezier(0.4,0,0.2,1),opacity .2s ease;}'
        . '.app-top-progress.is-visible{opacity:1;}'
        . '.app-top-progress.is-finishing{opacity:0;transition:width .32s cubic-bezier(0.4,0,0.2,1),opacity .28s ease .12s;}'
        . '</style>' . "\n";
    $html .= '<script>'
        . '(function(){try{if(sessionStorage.getItem("app_page_navigating")==="true"){var b=document.createElement("div");b.id="appTopProgress";b.className="app-top-progress is-visible";b.style.setProperty("--app-progress","75vw");b.style.width="75vw";(document.head||document.documentElement).appendChild(b);}}catch(e){}})();'
        . '</script>' . "\n";

    $swScript = "
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var desiredScript = '" . htmlspecialchars($serviceWorkerUrl, ENT_QUOTES, 'UTF-8') . "';
        var desiredScope = '" . htmlspecialchars($scopeUrl, ENT_QUOTES, 'UTF-8') . "';
        var desiredAbsoluteScope = new URL(desiredScope, window.location.origin).href;
        var desiredAbsoluteScript = new URL(desiredScript, window.location.origin).href;

        navigator.serviceWorker.getRegistrations()
            .then(function (registrations) {
                return Promise.all(registrations.map(function (registration) {
                    try {
                        var scope = registration.scope || '';
                        var activeScript = registration.active && registration.active.scriptURL ? registration.active.scriptURL : '';
                        var waitingScript = registration.waiting && registration.waiting.scriptURL ? registration.waiting.scriptURL : '';
                        var installingScript = registration.installing && registration.installing.scriptURL ? registration.installing.scriptURL : '';
                        var scriptUrl = activeScript || waitingScript || installingScript;
                        var isSameOrigin = scope.indexOf(window.location.origin + '/') === 0;
                        var isDifferentScope = scope !== desiredAbsoluteScope;
                        var isDifferentScript = scriptUrl && scriptUrl !== desiredAbsoluteScript;
                        if (isSameOrigin && (isDifferentScope || isDifferentScript)) {
                            return registration.unregister();
                        }
                    } catch (e) { console.warn('[PWA] SW cleanup:', e); }
                    return Promise.resolve(false);
                }));
            })
            .catch(function (e) { console.warn('[PWA] SW list:', e); })
            .finally(function () {
                var refreshingForUpdate = false;
                navigator.serviceWorker.addEventListener('controllerchange', function () {
                    if (refreshingForUpdate) { return; }
                    refreshingForUpdate = true;
                    window.location.reload();
                });

                var activateWaitingWorker = function (reg) {
                    if (reg && reg.waiting) {
                        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }
                };

                navigator.serviceWorker.register(desiredScript, { scope: desiredScope })
                    .then(function (reg) {
                        window._swRegistration = reg;
                        activateWaitingWorker(reg);
                        reg.addEventListener('updatefound', function () {
                            var worker = reg.installing;
                            if (!worker) { return; }
                            worker.addEventListener('statechange', function () {
                                if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                                    activateWaitingWorker(reg);
                                }
                            });
                        });
                        if (typeof reg.update === 'function') {
                            reg.update().catch(function (e) { console.warn('[PWA] SW update:', e); });
                        }
                    })
                    .catch(function (e) { console.warn('[PWA] SW:', e); });
            });
    });
    window._pwaInstallPrompt = null;
    window.bindPwaInstallButton = function () {
        var btn = document.getElementById('pwaInstallBtn');
        if (!btn) { return; }
        btn.style.display = window._pwaInstallPrompt ? 'flex' : 'none';
        if (btn.dataset.bound === '1') { return; }
        btn.dataset.bound = '1';
        btn.addEventListener('click', async function () {
            if (!window._pwaInstallPrompt) { return; }
            try {
                await window._pwaInstallPrompt.prompt();
                var choice = await window._pwaInstallPrompt.userChoice;
                if (choice && choice.outcome === 'accepted') { btn.style.display = 'none'; }
            } catch (e) { console.warn('[PWA] Install prompt:', e); }
            finally { window._pwaInstallPrompt = null; btn.style.display = 'none'; }
        });
    };
    document.addEventListener('DOMContentLoaded', function () { window.bindPwaInstallButton(); });
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        window._pwaInstallPrompt = e;
        window.bindPwaInstallButton();
    });
    window.addEventListener('appinstalled', function () {
        window._pwaInstallPrompt = null;
        var btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.style.display = 'none';
    });
}
</script>";
    $html .= $swScript;
    return $html;
}
