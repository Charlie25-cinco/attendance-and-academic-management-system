<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

function vapidBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function createVapidKeysWithOpenSslConfig(): array
{
    $configCandidates = array_filter([
        trim((string)getenv('OPENSSL_CONF')),
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
    ]);
    foreach (array_unique($configCandidates) as $configPath) {
        if (!is_file($configPath)) {
            continue;
        }
        $key = openssl_pkey_new([
            'config' => $configPath,
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = $key ? openssl_pkey_get_details($key) : false;
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y'], $ec['d'])) {
            continue;
        }
        $x = str_pad((string)$ec['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string)$ec['y'], 32, "\0", STR_PAD_LEFT);
        $private = str_pad((string)$ec['d'], 32, "\0", STR_PAD_LEFT);
        return [
            'publicKey' => vapidBase64UrlEncode("\x04" . $x . $y),
            'privateKey' => vapidBase64UrlEncode($private),
        ];
    }
    throw new RuntimeException('Unable to create a P-256 key with the available OpenSSL configuration.');
}

$subject = trim((string)($argv[1] ?? 'https://balingasagshs.wasmer.app'));
try {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
} catch (Throwable $e) {
    $keys = createVapidKeysWithOpenSslConfig();
}
\Minishlink\WebPush\VAPID::validate([
    'subject' => $subject,
    'publicKey' => $keys['publicKey'],
    'privateKey' => $keys['privateKey'],
]);

echo 'PUSH_VAPID_PUBLIC_KEY=' . $keys['publicKey'] . PHP_EOL;
echo 'PUSH_VAPID_PRIVATE_KEY=' . $keys['privateKey'] . PHP_EOL;
echo 'PUSH_VAPID_SUBJECT=' . $subject . PHP_EOL;
