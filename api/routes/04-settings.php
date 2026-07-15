<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'settings' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    apiEnsureUserSettingsTable($db);
    $stmt = $db->prepare("SELECT dark_mode, email_notifications, push_notifications
                          FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiJson([
            'ok' => true,
            'has_saved' => false,
            'settings' => [
                'dark_mode' => 0,
                'email_notifications' => 1,
                'push_notifications' => 1,
            ],
        ]);
    }

    $row['dark_mode'] = (int)$row['dark_mode'];
    $row['email_notifications'] = (int)$row['email_notifications'];
    $row['push_notifications'] = (int)$row['push_notifications'];
    apiJson(['ok' => true, 'has_saved' => true, 'settings' => $row]);
}

if ($route === 'settings' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $darkMode = isset($body['dark_mode']) ? ((int)$body['dark_mode'] === 1 ? 1 : 0) : null;
    $emailNotif = isset($body['email_notifications']) ? ((int)$body['email_notifications'] === 1 ? 1 : 0) : null;
    $pushNotif = isset($body['push_notifications']) ? ((int)$body['push_notifications'] === 1 ? 1 : 0) : null;

    if ($darkMode === null && $emailNotif === null && $pushNotif === null) {
        apiJson(['ok' => false, 'message' => 'No settings to update'], 422);
    }

    apiEnsureUserSettingsTable($db);

    $existing = $db->prepare("SELECT id FROM user_settings WHERE user_id = ? LIMIT 1");
    $existing->execute([(int)$user['id']]);
    if ($existing->fetchColumn()) {
        $fields = [];
        $params = [];
        if ($darkMode !== null) { $fields[] = 'dark_mode = ?'; $params[] = $darkMode; }
        if ($emailNotif !== null) { $fields[] = 'email_notifications = ?'; $params[] = $emailNotif; }
        if ($pushNotif !== null) { $fields[] = 'push_notifications = ?'; $params[] = $pushNotif; }
        $params[] = (int)$user['id'];
        $fieldStr = implode(', ', $fields);
        $db->prepare("UPDATE user_settings SET $fieldStr, updated_at = NOW() WHERE user_id = ?")->execute($params);
    } else {
        $stmt = $db->prepare("INSERT INTO user_settings (user_id, dark_mode, email_notifications, push_notifications)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([(int)$user['id'], $darkMode ?? 0, $emailNotif ?? 1, $pushNotif ?? 1]);
    }

    apiJson(['ok' => true, 'message' => 'Settings saved successfully']);
}
