<?php

function pushEnsureMobileTokensTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS mobile_push_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        device VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mobile_token (token),
        KEY idx_mobile_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

function pushSaveMobileToken(PDO $db, int $userId, string $token, string $device = ''): bool {
    $token = trim($token);
    if ($token === '' || $userId <= 0) {
        return false;
    }
    pushEnsureMobileTokensTable($db);
    $stmt = $db->prepare("INSERT INTO mobile_push_tokens (user_id, token, device, created_at, updated_at)
                          VALUES (?, ?, ?, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE
                            user_id = VALUES(user_id),
                            device = VALUES(device),
                            updated_at = NOW()");
    $saved = $stmt->execute([(int)$userId, $token, substr($device, 0, 120)]);

    // Ensure push_notifications is enabled for users who register a device.
    $hasSettings = dbHasTable($db, 'user_settings');
    if ($hasSettings) {
        $settingsStmt = $db->prepare("INSERT INTO user_settings (user_id, push_notifications)
                                      VALUES (?, 1)
                                      ON DUPLICATE KEY UPDATE push_notifications = 1");
        $settingsStmt->execute([(int)$userId]);
    }

    return $saved;
}

function pushDeleteMobileToken(PDO $db, int $userId, string $token = ''): bool {
    if ($userId <= 0) {
        return false;
    }
    pushEnsureMobileTokensTable($db);
    if ($token !== '') {
        $stmt = $db->prepare("DELETE FROM mobile_push_tokens WHERE user_id = ? AND token = ?");
        return $stmt->execute([(int)$userId, trim($token)]);
    }
    $stmt = $db->prepare("DELETE FROM mobile_push_tokens WHERE user_id = ?");
    return $stmt->execute([(int)$userId]);
}

function pushFetchMobileTokens(PDO $db, array $userIds): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) {
        return $id > 0;
    })));
    if (empty($userIds)) {
        return [];
    }
    pushEnsureMobileTokensTable($db);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $hasSettings = dbHasTable($db, 'user_settings');
    if ($hasSettings) {
        $query = "SELECT mpt.user_id, mpt.token
                  FROM mobile_push_tokens mpt
                  LEFT JOIN user_settings us ON us.user_id = mpt.user_id
                  WHERE mpt.user_id IN ($placeholders)
                  AND (us.user_id IS NULL OR us.push_notifications = 1)";
    } else {
        $query = "SELECT mpt.user_id, mpt.token
                  FROM mobile_push_tokens mpt
                  WHERE mpt.user_id IN ($placeholders)";
    }
    $stmt = $db->prepare($query);
    $stmt->execute($userIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pushGetServiceAccount(): ?array {
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $keyFile = __DIR__ . '/../../Mobile/iprojects-96359-1ad492a4cdc5.json';
    error_log('[push] Looking for service account at: ' . $keyFile);
    if (!is_file($keyFile)) {
        error_log('[push] Service account file not found');
        return null;
    }
    $content = file_get_contents($keyFile);
    if (!$content) {
        error_log('[push] Failed to read service account file');
        return null;
    }
    $data = json_decode($content, true);
    if (!$data || empty($data['private_key']) || empty($data['client_email'])) {
        error_log('[push] Service account JSON invalid or missing keys');
        return null;
    }
    error_log('[push] Service account loaded: ' . ($data['client_email'] ?? 'no email'));
    $cached = $data;
    return $data;
}

function pushGetAccessToken(array $serviceAccount): ?string {
    $now = time();
    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
    $payload = json_encode([
        'iss' => $serviceAccount['client_email'],
        'sub' => $serviceAccount['client_email'],
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]);

    $privateKey = $serviceAccount['private_key'];

    $rsa = openssl_pkey_get_private($privateKey);
    if (!$rsa) {
        return null;
    }

    $signature = '';
    $input = base64_encode($header) . '.' . base64_encode($payload);
    if (!openssl_sign($input, $signature, $rsa, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $jwt = $input . '.' . base64_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function pushSendExpoMessages(array $messages): array {
    if (empty($messages)) {
        return ['ok' => true, 'data' => []];
    }

    $serviceAccount = pushGetServiceAccount();
    error_log('[push] pushSendExpoMessages: serviceAccount=' . ($serviceAccount ? 'found' : 'null'));
    if (!$serviceAccount) {
        error_log('[push] Falling back to legacy API: no service account');
        return pushSendExpoMessagesLegacy($messages);
    }

    $accessToken = pushGetAccessToken($serviceAccount);
    error_log('[push] pushSendExpoMessages: accessToken=' . ($accessToken ? 'obtained' : 'null'));
    if (!$accessToken) {
        error_log('[push] Falling back to legacy API: no access token');
        return pushSendExpoMessagesLegacy($messages);
    }

    $projectId = $serviceAccount['project_id'];
    $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $chunks = array_chunk($messages, 100);
    $allResults = [];

    foreach ($chunks as $batch) {
        $fcmMessages = [];
        foreach ($batch as $msg) {
            $fcmMessages[] = [
                'token' => $msg['to'],
                'notification' => [
                    'title' => $msg['title'],
                    'body' => $msg['body'],
                ],
                'data' => $msg['data'] ?? [],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'default',
                    ],
                ],
            ];
        }

        $payload = json_encode(['messages' => $fcmMessages], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            foreach ($fcmMessages as $idx => $msg) {
                $allResults[] = [
                    'status' => isset($decoded['results'][$idx]['error']) ? 'error' : 'ok',
                    'messageId' => $decoded['results'][$idx]['messageId'] ?? null,
                    'error' => $decoded['results'][$idx]['error'] ?? null,
                ];
            }
        } else {
            foreach ($batch as $msg) {
                $allResults[] = ['status' => 'error', 'error' => 'FCM request failed'];
            }
        }
    }

    return ['ok' => true, 'data' => $allResults];
}

function pushSendExpoMessagesLegacy(array $messages): array {
    if (empty($messages)) {
        return ['ok' => true, 'data' => []];
    }

    $payload = json_encode($messages, JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'data' => []];
    }

    $ch = curl_init('https://exp.host/--/api/v2/push/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300 || !is_string($response)) {
        return ['ok' => false, 'data' => []];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'data' => []];
    }

    return ['ok' => true, 'data' => $decoded['data'] ?? []];
}

function pushNotifyUsers(PDO $db, array $userIds, string $title, string $body, array $data = []): bool {
    $records = pushFetchMobileTokens($db, $userIds);
    if (empty($records)) {
        return false;
    }

    $chunks = array_chunk($records, 100);
    foreach ($chunks as $chunk) {
        $messages = [];
        foreach ($chunk as $row) {
            $messages[] = [
                'to' => (string)($row['token'] ?? ''),
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
                'data' => $data,
            ];
        }

        $result = pushSendExpoMessages($messages);
        if (!($result['ok'] ?? false)) {
            continue;
        }

        $responses = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($responses as $idx => $resp) {
            if (!is_array($resp)) {
                continue;
            }
            $status = $resp['status'] ?? '';
            $details = $resp['details'] ?? [];
            $error = is_array($details) ? ($details['error'] ?? '') : '';
            if ($status === 'error' && in_array($error, ['DeviceNotRegistered', 'InvalidCredentials'], true)) {
                $token = (string)($chunk[$idx]['token'] ?? '');
                if ($token !== '') {
                    $stmt = $db->prepare("DELETE FROM mobile_push_tokens WHERE token = ?");
                    $stmt->execute([$token]);
                }
            }
        }
    }

    return true;
}

function pushActiveClassRecipientIds(PDO $db, int $classId): array {
    if ($classId <= 0) {
        return [];
    }
    $studentStmt = $db->prepare("SELECT DISTINCT u.id
                                 FROM enrollments e
                                 JOIN users u ON u.id = e.student_id
                                 WHERE e.class_id = ?
                                 AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                 AND u.role = 'student'
                                 AND u.status IN ('active', 'pending')");
    $studentStmt->execute([$classId]);
    $studentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));

    $parentStmt = $db->prepare("SELECT DISTINCT p.id
                                FROM parent_students ps
                                JOIN users p ON p.id = ps.parent_id
                                JOIN enrollments e ON e.student_id = ps.student_id
                                WHERE e.class_id = ?
                                AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                AND p.role = 'parent'
                                AND p.status IN ('active', 'pending')");
    $parentStmt->execute([$classId]);
    $parentIds = array_map('intval', $parentStmt->fetchAll(PDO::FETCH_COLUMN));

    $all = array_merge($studentIds, $parentIds);
    return array_values(array_unique(array_filter($all, function ($id) { return $id > 0; })));
}
