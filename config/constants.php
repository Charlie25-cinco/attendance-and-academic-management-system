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

function mailerConfigured(): bool {
    $cfg = smtpConfig();
    return $cfg['user'] !== '' && $cfg['pass'] !== '';
}

function mailerSendHtml(string $to, string $subject, string $html, string $fromEmail = '', string $fromName = ''): bool {
    if (!mailerConfigured()) {
        return false;
    }

    $cfg = smtpConfig();
    $host = $cfg['host'];
    $port = $cfg['port'];
    $user = $cfg['user'];
    $pass = $cfg['pass'];
    $from = $fromEmail !== '' ? $fromEmail : ($cfg['from_email'] ?: $user);
    $name = $fromName !== '' ? $fromName : $cfg['from_name'];

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
    return base64_decode(strtr($data, '-_', '+/'));
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
    $clientPublicRaw = pushBase64UrlDecode($p256dh);
    $authSecret = pushBase64UrlDecode($auth);

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
    if (!$clientKey || !function_exists('openssl_pkey_derive')) {
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

    $plaintext = pack('n', 0) . $payload;
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) { return null; }

    return [
        'ciphertext' => $ciphertext . $tag,
        'salt' => $salt,
        'server_public_key' => $serverPublicRaw
    ];
}

function pushSendToSubscription($subscription, $payload) {
    if (!pushConfigReady()) {
        return ['success' => false, 'status' => 0];
    }
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $p256dh = (string)($subscription['p256dh'] ?? '');
    $auth = (string)($subscription['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return ['success' => false, 'status' => 0];
    }

    $body = json_encode($payload);
    if ($body === false) { $body = '{}'; }

    $enc = pushEncryptPayload($body, $p256dh, $auth);
    if (!$enc) {
        return ['success' => false, 'status' => 0];
    }

    $endpointParts = parse_url($endpoint);
    $aud = ($endpointParts && isset($endpointParts['scheme'], $endpointParts['host']))
        ? $endpointParts['scheme'] . '://' . $endpointParts['host']
        : '';
    $jwt = pushCreateVapidJwt($aud, PUSH_VAPID_SUBJECT, time() + 12 * 60 * 60, PUSH_VAPID_PRIVATE_KEY);
    $vapidPublicRaw = pushGetVapidPublicKeyRaw(PUSH_VAPID_PRIVATE_KEY);
    if ($jwt === '' || $vapidPublicRaw === '') {
        return ['success' => false, 'status' => 0];
    }

    $headers = [
        'TTL: 300',
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'Encryption: salt=' . pushBase64UrlEncode($enc['salt']),
        'Crypto-Key: dh=' . pushBase64UrlEncode($enc['server_public_key']) . '; p256ecdsa=' . pushBase64UrlEncode($vapidPublicRaw),
        'Authorization: vapid t=' . $jwt . ', k=' . pushBase64UrlEncode($vapidPublicRaw)
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $enc['ciphertext']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['success' => $status >= 200 && $status < 300, 'status' => $status];
}

function pushSaveSubscription($db, $userId, $subscription, $userAgent = '') {
    if (!is_array($subscription)) { return false; }
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') { return false; }
    pushEnsureSubscriptionsTable($db);
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
    foreach ($subs as $sub) {
        $result = pushSendToSubscription($sub, $payload);
        if (in_array((int)($result['status'] ?? 0), [404, 410], true)) {
            pushDeleteSubscription($db, (int)$sub['user_id'], (string)$sub['endpoint']);
        }
    }
    return true;
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
    $manifestUrl = $baseUrl !== '' ? $baseUrl . '/assets/manifest.json' : '/assets/manifest.json';
    $appleTouchIcon = $baseUrl !== '' ? $baseUrl . '/assets/images/icon-192.png' : '/assets/images/icon-192.png';
    $faviconUrl = $baseUrl !== '' ? $baseUrl . '/assets/images/icon-192.png' : '/assets/images/icon-192.png';
    $serviceWorkerUrl = $baseUrl !== '' ? $baseUrl . '/assets/push-sw.js' : '/assets/push-sw.js';
    $scopeUrl = $baseUrl !== '' ? $baseUrl . '/assets/' : '/assets/';

    $html = '<link rel="manifest" href="' . htmlspecialchars($manifestUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<meta name="theme-color" content="#1d4ed8">' . "\n";
    $html .= '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    $html .= '<meta name="apple-mobile-web-app-title" content="BSHS AMS">' . "\n";
    $html .= '<link rel="apple-touch-icon" href="' . htmlspecialchars($appleTouchIcon, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    $html .= '<link rel="icon" type="image/png" href="' . htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    $swScript = "
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var desiredScript = '" . htmlspecialchars($serviceWorkerUrl, ENT_QUOTES, 'UTF-8') . "';
        var desiredScope = '" . htmlspecialchars($scopeUrl, ENT_QUOTES, 'UTF-8') . "';
        var desiredAbsoluteScope = window.location.origin + desiredScope;

        navigator.serviceWorker.getRegistrations()
            .then(function (registrations) {
                return Promise.all(registrations.map(function (registration) {
                    try {
                        var scope = registration.scope || '';
                        var isRootScoped = scope === window.location.origin + '/';
                        var isDifferentScope = scope !== desiredAbsoluteScope;
                        if (isRootScoped && isDifferentScope) {
                            return registration.unregister();
                        }
                    } catch (e) { console.warn('[PWA] SW cleanup:', e); }
                    return Promise.resolve(false);
                }));
            })
            .catch(function (e) { console.warn('[PWA] SW list:', e); })
            .finally(function () {
                navigator.serviceWorker.register(desiredScript, { scope: desiredScope })
                    .then(function (reg) {
                        window._swRegistration = reg;
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
