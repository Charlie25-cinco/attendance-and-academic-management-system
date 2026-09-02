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
    if (preg_match('#^(.*)/(admin|teacher|student|parent|api|auth|component|config|database|includes|assets|src|uploads|site)(?:/|$)#', $script, $m)) {
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
    if ($val !== false) {
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
    $envPublic = appEnvValue('PUSH_VAPID_PUBLIC_KEY');
    define('PUSH_VAPID_PUBLIC_KEY', $envPublic !== '' ? $envPublic : (string)($pushFileKeys['public'] ?? ''));
}
if (!defined('PUSH_VAPID_PRIVATE_KEY')) {
    $envPrivate = appEnvValue('PUSH_VAPID_PRIVATE_KEY');
    define('PUSH_VAPID_PRIVATE_KEY', $envPrivate !== '' ? $envPrivate : (string)($pushFileKeys['private'] ?? ''));
}
if (!defined('PUSH_VAPID_SUBJECT')) {
    $envSubject = appEnvValue('PUSH_VAPID_SUBJECT');
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
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $p256dh = (string)($subscription['p256dh'] ?? '');
    $auth = (string)($subscription['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return ['success' => false, 'status' => 0, 'error' => 'invalid_subscription'];
    }

    try {
        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => PUSH_VAPID_SUBJECT,
                'publicKey' => PUSH_VAPID_PUBLIC_KEY,
                'privateKey' => PUSH_VAPID_PRIVATE_KEY,
            ],
        ], ['TTL' => 86400]);
        $webPush->setReuseVAPIDHeaders(true);
        $webSubscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $endpoint,
            'publicKey' => $p256dh,
            'authToken' => $auth,
            'contentEncoding' => 'aes128gcm',
        ]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $report = $webPush->sendOneNotification(
            $webSubscription,
            $body === false ? '{}' : $body,
            ['TTL' => 86400]
        );
        $response = $report->getResponse();
        $status = $response ? $response->getStatusCode() : 0;
        if (!$report->isSuccess()) {
            error_log('[push] Web Push send failed. Status: ' . $status . ' Reason: ' . $report->getReason());
        }
        return [
            'success' => $report->isSuccess(),
            'status' => $status,
            'error' => $report->isSuccess() ? '' : $report->getReason(),
            'expired' => $report->isSubscriptionExpired(),
        ];
    } catch (Throwable $e) {
        error_log('[push] Web Push exception: ' . $e->getMessage());
        return ['success' => false, 'status' => 0, 'error' => $e->getMessage()];
    }
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
        if (!empty($result['expired']) || in_array((int)($result['status'] ?? 0), [404, 410], true)) {
            pushDeleteSubscription($db, (int)$sub['user_id'], (string)$sub['endpoint']);
        }
    }
    if ($attempted === 0) {
        return false;
    }
    if ($sent === 0) {
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

    appDispatchNotification(
        $db,
        $targetIds,
        'attendance_direct_' . $date . '_' . $studentId,
        'Attendance Update: ' . $statusLabel,
        $studentName . ' marked ' . strtolower($statusLabel) . ' for ' . $formattedDate,
        'bi-calendar-check',
        'info',
        ['student' => 'Student_Attendance.php', 'parent' => 'Parent_Progress.php'],
        ['type' => 'attendance', 'student_id' => $studentId, 'status' => $status, 'date' => $date]
    );
    return true;
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

    appDispatchNotification(
        $db,
        $recipients,
        'report_card_release_' . $classId . '_' . $term . '_' . $academicYear,
        'Official Report Cards Released',
        'Report cards for ' . $className . ' are now available.',
        'bi-file-earmark-check',
        'success',
        ['student' => 'Student_Report_Card.php', 'parent' => 'Parent_Report_Card.php'],
        ['type' => 'grade_publication', 'class_id' => $classId, 'term' => $term, 'academic_year' => $academicYear]
    );

    if (function_exists('smsNotifyGradePublication')) {
        try {
            smsNotifyGradePublication($db, $classId, $term, $academicYear);
        } catch (Throwable $e) {
            error_log('[SMS] Grade publication notification error: ' . $e->getMessage());
        }
    }

    return true;
}

function pushNotifyGradeRecall($db, int $classId, string $term = 'Final', string $academicYear = '', string $reason = ''): bool {
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

    $subtitle = 'Report cards for ' . $className . ' have been recalled for review/correction by administration.' . ($reason !== '' ? ' Reason: ' . $reason : '');

    appDispatchNotification(
        $db,
        $recipients,
        'report_card_recalled_' . $classId . '_' . $term . '_' . $academicYear . '_' . time(),
        'Report Card Recalled for Correction',
        $subtitle,
        'bi-exclamation-triangle',
        'warning',
        ['student' => 'Student_Report_Card.php', 'parent' => 'Parent_Report_Card.php'],
        ['type' => 'grade_recall', 'class_id' => $classId, 'term' => $term, 'academic_year' => $academicYear]
    );

    return true;
}

function smsGetService(?\BshsAms\Notification\SmsService $override = null): \BshsAms\Notification\SmsService {
    static $service = null;
    if ($override !== null) {
        $service = $override;
        return $service;
    }
    if ($service === null) {
        $service = new \BshsAms\Notification\SmsService();
    }
    return $service;
}

function smsInitConfig(): void {
    global $smsConfig;
    if ($smsConfig !== null) return;
    $service = smsGetService();
    $smsConfig = [
        'provider' => $service->getProvider(),
        'api_key' => trim((string)getenv('SMS_API_KEY') ?: ''),
        'sender_name' => $service->getSenderName(),
    ];
}

function smsConfigured(): bool {
    return smsGetService()->isConfigured();
}

function smsSend(string $to, string $message, ?int $recipientUserId = null, ?PDO $db = null): array {
    return smsGetService()->send($to, $message, $recipientUserId, $db);
}

function smsNotifyGradePublication(
    PDO $db,
    int $classId,
    string $term = 'Final',
    string $academicYear = '',
    ?int $singleStudentId = null
): array {
    $service = smsGetService();

    $classStmt = $db->prepare("SELECT class_name, grade_level, section FROM classes WHERE id = ?");
    $classStmt->execute([$classId]);
    $classRow = $classStmt->fetch(PDO::FETCH_ASSOC);
    $className = $classRow ? trim(($classRow['class_name'] ?? '') . ' (' . ($classRow['grade_level'] ?? '') . '-' . ($classRow['section'] ?? '') . ')') : 'Class #' . $classId;

    $results = ['total' => 0, 'sent' => 0, 'failed' => 0];

    if ($singleStudentId !== null && $singleStudentId > 0) {
        $studentStmt = $db->prepare("SELECT id, first_name, last_name, contact_number FROM users WHERE id = ? AND role = 'student'");
        $studentStmt->execute([$singleStudentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            return $results;
        }
        $studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));

        $parentStmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.contact_number
                                   FROM users u
                                   JOIN parent_students ps ON ps.parent_id = u.id
                                   WHERE ps.student_id = ? AND u.role = 'parent' AND u.contact_number IS NOT NULL AND u.contact_number != ''");
        $parentStmt->execute([$singleStudentId]);
        $parents = $parentStmt->fetchAll(PDO::FETCH_ASSOC);

        $recipientsToSend = [];
        if (!empty($student['contact_number'])) {
            $recipientsToSend[] = [
                'user_id' => (int)$student['id'],
                'phone' => (string)$student['contact_number'],
                'name' => $studentName,
                'role' => 'student',
            ];
        }
        foreach ($parents as $parent) {
            $recipientsToSend[] = [
                'user_id' => (int)$parent['id'],
                'phone' => (string)$parent['contact_number'],
                'name' => $studentName,
                'role' => 'parent',
            ];
        }

        $termLabel = $term !== '' ? $term : 'Final';
        $ayLabel = $academicYear !== '' ? " S.Y. {$academicYear}" : '';
        $message = "BSHS AMS: Official grades and report card for {$studentName} ({$className}{$ayLabel} - {$termLabel}) have been approved by Admin and are now available on the portal.";

        $sentPhoneKeys = [];
        foreach ($recipientsToSend as $recip) {
            $rawPhone = trim((string)($recip['phone'] ?? ''));
            if ($rawPhone === '') {
                continue;
            }
            $normPhoneKey = \BshsAms\Notification\SmsService::normalizePhilippineNumber($rawPhone) ?? $rawPhone;
            if (isset($sentPhoneKeys[$normPhoneKey])) {
                continue;
            }
            $sentPhoneKeys[$normPhoneKey] = true;

            $results['total']++;
            $res = $service->send($recip['phone'], $message, $recip['user_id'], $db);
            if (!empty($res['success'])) {
                $results['sent']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    // Section/Class-wide grade publication
    $enrollStmt = $db->prepare("SELECT s.id, s.first_name, s.last_name, s.contact_number
                                FROM enrollments e
                                JOIN users s ON s.id = e.student_id
                                WHERE e.class_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled' AND s.role = 'student'");
    $enrollStmt->execute([$classId]);
    $students = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        return $results;
    }

    $studentIds = array_column($students, 'id');
    $inClause = implode(',', array_fill(0, count($studentIds), '?'));
    $parentStmt = $db->prepare("SELECT ps.student_id, u.id as parent_id, u.first_name, u.last_name, u.contact_number
                               FROM users u
                               JOIN parent_students ps ON ps.parent_id = u.id
                               WHERE ps.student_id IN ($inClause) AND u.role = 'parent' AND u.contact_number IS NOT NULL AND u.contact_number != ''");
    $parentStmt->execute($studentIds);
    $parentsByStudent = [];
    foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
        $parentsByStudent[(int)$pRow['student_id']][] = $pRow;
    }

    $termLabel = $term !== '' ? $term : 'Final';
    $ayLabel = $academicYear !== '' ? " S.Y. {$academicYear}" : '';

    $sentPhoneKeys = [];
    foreach ($students as $student) {
        $studentId = (int)$student['id'];
        $studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
        $message = "BSHS AMS: Official grades and report card for {$studentName} ({$className}{$ayLabel} - {$termLabel}) have been approved by Admin and are now available on the portal.";

        $recipients = [];
        if (!empty($student['contact_number'])) {
            $recipients[] = ['user_id' => $studentId, 'phone' => (string)$student['contact_number']];
        }
        if (isset($parentsByStudent[$studentId])) {
            foreach ($parentsByStudent[$studentId] as $p) {
                $recipients[] = ['user_id' => (int)$p['parent_id'], 'phone' => (string)$p['contact_number']];
            }
        }

        foreach ($recipients as $r) {
            $rawPhone = trim((string)($r['phone'] ?? ''));
            if ($rawPhone === '') {
                continue;
            }
            $normPhoneKey = \BshsAms\Notification\SmsService::normalizePhilippineNumber($rawPhone) ?? $rawPhone;
            if (isset($sentPhoneKeys[$normPhoneKey])) {
                continue;
            }
            $sentPhoneKeys[$normPhoneKey] = true;

            $results['total']++;
            $res = $service->send($r['phone'], $message, $r['user_id'], $db);
            if (!empty($res['success'])) {
                $results['sent']++;
            } else {
                $results['failed']++;
            }
        }
    }

    return $results;
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
    $serviceWorkerUrl = $baseUrl !== '' ? $baseUrl . '/sw.js?v=0.3.82' : '/sw.js?v=0.3.82';
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
    $html .= '<link rel="icon" type="image/png" sizes="192x192" href="' . htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    $html .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    $html .= '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" media="print" onload="this.media=\'all\'">' . "\n";
    $isDark = function_exists('appUiDarkModeEnabled') && appUiDarkModeEnabled();
    $html .= '<style id="app-theme-critical-dark">'
        . 'html.dark-mode,body.dark-mode{background-color:#111827 !important;color:#e5e7eb !important;}'
        . 'body.dark-mode .sidebar{background:#111827 !important;color:#e5e7eb !important;}'
        . 'body.dark-mode .main-content{background:#111827 !important;color:#e5e7eb !important;}'
        . 'body.dark-mode .header{background:#1f2937 !important;border-bottom:1px solid #374151 !important;color:#e5e7eb !important;}'
        . 'body.dark-mode .dashboard-card,body.dark-mode .content-card,body.dark-mode .attendance-sheet,body.dark-mode .material-card,body.dark-mode .grade-card,body.dark-mode .announcement-card,body.dark-mode .report-card,body.dark-mode .class-card,body.dark-mode .table-container{background:#1f2937 !important;color:#e5e7eb !important;border-color:#374151 !important;box-shadow:none !important;}'
        . 'body.dark-mode .custom-table thead th{background:#374151 !important;color:#e5e7eb !important;border-color:#374151 !important;}'
        . 'body.dark-mode .custom-table tbody tr,body.dark-mode .custom-table tbody td{background:transparent !important;color:#d1d5db !important;border-color:#374151 !important;}'
        . 'body.dark-mode .form-control,body.dark-mode .form-select,body.dark-mode .input-group-text{background:#374151 !important;border-color:#4b5563 !important;color:#e5e7eb !important;}'
        . 'body.dark-mode .text-muted,body.dark-mode p,body.dark-mode small{color:#9ca3af !important;}'
        . 'body.dark-mode .header-btn,body.dark-mode .btn-secondary-custom{background:#374151 !important;color:#e5e7eb !important;border-color:#4b5563 !important;}'
        . 'body.dark-mode .sidebar-link{color:#9ca3af !important;}'
        . 'body.dark-mode .sidebar-link.active,body.dark-mode .sidebar-link:hover{background:rgba(59,130,246,.2) !important;color:#93c5fd !important;}'
        . '</style>' . "\n";
    $html .= '<script>'
        . '(function(){try{var dark=localStorage.getItem("darkMode")==="true"||' . ($isDark ? 'true' : 'false') . ';if(dark){document.documentElement.classList.add("dark-mode");document.documentElement.style.backgroundColor="#111827";}}catch(e){}})();'
        . '</script>' . "\n";
    $html .= '<style id="app-top-progress-critical">'
        . '.app-top-progress{--app-progress:0vw;position:fixed;top:0;left:0;width:var(--app-progress,0vw);max-width:100vw;height:3px;z-index:100000;pointer-events:none;opacity:0;background:linear-gradient(90deg,#1f4f82 0%,#11856f 50%,#3b82f6 100%);box-shadow:0 0 16px rgba(31,79,130,0.55);transition:width .32s cubic-bezier(0.4,0,0.2,1),opacity .2s ease;}'
        . '.app-top-progress.is-visible{opacity:1;}'
        . '.app-top-progress.is-finishing{opacity:0;transition:width .32s cubic-bezier(0.4,0,0.2,1),opacity .28s ease .12s;}'
        . '</style>' . "\n";
    $html .= '<script>'
        . 'window._CACHE_NAME="bshs-ams-v37";'
        . '</script>' . "\n";

    $swScript = "
<script>
window._CACHE_NAME = 'bshs-ams-v37';
if ('serviceWorker' in navigator) {
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
                var hadPreviousController = Boolean(navigator.serviceWorker.controller);
                var refreshingForUpdate = false;
                navigator.serviceWorker.addEventListener('controllerchange', function () {
                    if (refreshingForUpdate || !hadPreviousController) { return; }
                    refreshingForUpdate = true;
                    window.location.reload();
                });

                var activateWaitingWorker = function (reg) {
                    if (reg && reg.waiting) {
                        reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                    }
                };

                navigator.serviceWorker.register(desiredScript, { scope: desiredScope, updateViaCache: 'none' })
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
    }
    window._pwaInstallPrompt = null;
    window._pwaInstalled = false;
    window.isPwaStandalone = function () {
        return Boolean((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true);
    };
    window.isPwaInstalled = function () {
        return Boolean(window.isPwaStandalone() || window._pwaInstalled === true);
    };

    window.showPwaInstallModal = function () {
        var modalEl = document.getElementById('pwaInstallModal');
        var isInstalled = window.isPwaInstalled();
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent || '') && !window.MSStream;

        var titleEl = document.getElementById('pwaInstallModalTitle');
        var subtitleEl = document.getElementById('pwaInstallModalSubtitle');
        var step1Title = document.getElementById('pwaGuideStep1Title');
        var step1Desc = document.getElementById('pwaGuideStep1Desc');
        var step2Title = document.getElementById('pwaGuideStep2Title');
        var step2Desc = document.getElementById('pwaGuideStep2Desc');
        var step3Title = document.getElementById('pwaGuideStep3Title');
        var step3Desc = document.getElementById('pwaGuideStep3Desc');

        if (isInstalled) {
            if (titleEl) titleEl.textContent = 'Application Already Installed';
            if (subtitleEl) subtitleEl.textContent = 'BSHS AMS is currently running as an installed application on this device.';
            if (step1Title) step1Title.textContent = 'Installed & Active';
            if (step1Desc) step1Desc.textContent = 'You are already using the installed PWA standalone version.';
            if (step2Title) step2Title.textContent = 'Quick Access Ready';
            if (step2Desc) step2Desc.textContent = 'Launch the app directly from your home screen or application launcher.';
            if (step3Title) step3Title.textContent = 'Offline Reliability';
            if (step3Desc) step3Desc.textContent = 'Essential caching and offline sync are automatically active.';
        } else if (isIos) {
            if (titleEl) titleEl.textContent = 'Install on iOS (iPhone / iPad)';
            if (subtitleEl) subtitleEl.textContent = 'Follow these steps to add BSHS AMS to your Home Screen in Safari:';
            if (step1Title) step1Title.textContent = 'Tap the Share Button';
            if (step1Desc) step1Desc.textContent = 'Tap the Share icon (square with arrow) at the bottom or top of Safari.';
            if (step2Title) step2Title.textContent = 'Select \"Add to Home Screen\"';
            if (step2Desc) step2Desc.textContent = 'Scroll down the options list and select \"Add to Home Screen\".';
            if (step3Title) step3Title.textContent = 'Tap \"Add\"';
            if (step3Desc) step3Desc.textContent = 'Confirm by tapping \"Add\" in the upper-right corner.';
        } else {
            if (titleEl) titleEl.textContent = 'Install BSHS AMS';
            if (subtitleEl) subtitleEl.textContent = 'Install BSHS AMS on this device for quick access and offline reliability.';
            if (step1Title) step1Title.textContent = 'Open Browser Menu or Address Bar';
            if (step1Desc) step1Desc.textContent = 'Look for the Install icon in your browser address bar or tap the menu (⋮ / ⋯).';
            if (step2Title) step2Title.textContent = 'Select \"Install BSHS AMS\" or \"Add to Home Screen\"';
            if (step2Desc) step2Desc.textContent = 'Choose the install option to add the application to your device.';
            if (step3Title) step3Title.textContent = 'Confirm & Launch';
            if (step3Desc) step3Desc.textContent = 'Accept the confirmation. The app will launch in its own standalone window.';
        }

        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        } else if (modalEl) {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }
    };

    window.bindPwaInstallButton = function () {
        var isInstalled = window.isPwaInstalled();
        var settingsSection = document.getElementById('settingsPwaInstallSection');
        var settingsBtn = document.getElementById('settingsPwaInstallBtn');

        if (settingsSection) {
            settingsSection.style.display = 'block';
        }
        if (settingsBtn) {
            if (isInstalled) {
                settingsBtn.innerHTML = '<i class=\"bi bi-check2-circle me-1\"></i>Installed';
                settingsBtn.classList.remove('btn-primary-custom');
                settingsBtn.classList.add('btn-outline-secondary');
            } else {
                settingsBtn.innerHTML = '<i class=\"bi bi-download me-1\"></i>Install';
                settingsBtn.classList.remove('btn-outline-secondary');
                settingsBtn.classList.add('btn-primary-custom');
            }

            if (settingsBtn.dataset.bound !== '1') {
                settingsBtn.dataset.bound = '1';
                settingsBtn.addEventListener('click', async function (e) {
                    if (e) e.preventDefault();
                    if (window.isPwaInstalled()) {
                        window.showPwaInstallModal();
                        return;
                    }
                    if (window._pwaInstallPrompt) {
                        try {
                            await window._pwaInstallPrompt.prompt();
                            var choice = await window._pwaInstallPrompt.userChoice;
                            if (choice && choice.outcome === 'accepted') {
                                window._pwaInstalled = true;
                                window._pwaInstallPrompt = null;
                                try {
                                    if (window.localStorage) {
                                        window.localStorage.setItem('bshs_pwa_first_open_pending', '1');
                                    }
                                } catch (err) {}
                            }
                        } catch (err) {
                            console.warn('[PWA] Install prompt:', err);
                            window.showPwaInstallModal();
                        } finally {
                            window._pwaInstallPrompt = null;
                            window.bindPwaInstallButton();
                        }
                    } else {
                        window.showPwaInstallModal();
                    }
                });
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () { window.bindPwaInstallButton(); });
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        window._pwaInstallPrompt = e;
        window._pwaInstalled = false;
        try {
            if (window.localStorage) {
                window.localStorage.removeItem('bshs_pwa_installed');
            }
        } catch (err) {}
        window.bindPwaInstallButton();
    });
    window.addEventListener('appinstalled', function () {
        window._pwaInstalled = true;
        window._pwaInstallPrompt = null;
        try {
            if (window.localStorage) {
                window.localStorage.setItem('bshs_pwa_first_open_pending', '1');
            }
        } catch (err) {}
        window.bindPwaInstallButton();
    });
</script>";
    $html .= $swScript;
    return $html;
}
