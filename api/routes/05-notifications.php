<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

if ($route === 'notification-action' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionCsrf !== '') {
        $requestCsrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? ''));
        if ($requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
            apiJson(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
        }
    }

    appEnsureUserNotificationsTable($db);
    $body = apiRequestBody();
    $action = strtolower(trim((string)($body['action'] ?? '')));
    $notificationId = (int)($body['id'] ?? 0);
    $userId = (int)$user['id'];

    if ($action === 'read' && $notificationId > 0) {
        $stmt = $db->prepare('UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    } elseif ($action === 'read_all') {
        $stmt = $db->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
    } elseif ($action === 'delete' && $notificationId > 0) {
        $stmt = $db->prepare('DELETE FROM user_notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    } elseif ($action === 'delete_all') {
        $stmt = $db->prepare('DELETE FROM user_notifications WHERE user_id = ?');
        $stmt->execute([$userId]);
    } else {
        apiJson(['ok' => false, 'message' => 'Invalid notification action'], 422);
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0');
    $countStmt->execute([$userId]);
    apiJson([
        'ok' => true,
        'message' => 'Notification state updated',
        'unread_count' => (int)$countStmt->fetchColumn(),
    ]);
}

if ($route === 'web-push-status' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $subscriptions = pushFetchSubscriptions($db, [(int)$user['id']]);
    apiJson([
        'ok' => true,
        'configured' => pushConfigReady(),
        'subscription_count' => count($subscriptions),
    ]);
}

if ($route === 'web-push-test' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
    $requestCsrf = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? ''));
    if ($sessionCsrf !== '' && ($requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf))) {
        apiJson(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    if (!pushConfigReady()) {
        apiJson(['ok' => false, 'message' => 'Server push keys are not configured'], 503);
    }
    if (count(pushFetchSubscriptions($db, [(int)$user['id']])) === 0) {
        apiJson(['ok' => false, 'message' => 'This account has no subscribed device'], 422);
    }
    $role = (string)($user['role'] ?? '');
    $sent = pushSendToUserIds($db, [(int)$user['id']], [
        'title' => 'Test notification',
        'body' => 'Device notifications are working for this account.',
        'icon' => '/assets/images/icon-192.png',
        'badge' => '/assets/images/icon-192.png',
        'url' => appNotificationTargetUrl($role),
        'data' => ['type' => 'push_test', 'url' => appNotificationTargetUrl($role)],
    ]);
    apiJson([
        'ok' => $sent,
        'message' => $sent ? 'Test notification sent' : 'The push service rejected the test notification',
    ], $sent ? 200 : 502);
}

if ($route === 'notifications' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $role = (string)($user['role'] ?? '');
    $userId = (int)$user['id'];

    appEnsureUserNotificationsTable($db);
    try {
        $savedStmt = $db->prepare("SELECT id, source_key, title, subtitle, icon, color, link, event_at, is_read
                                   FROM user_notifications
                                   WHERE user_id = ?
                                   ORDER BY is_read ASC, event_at DESC, id DESC
                                   LIMIT 50");
        $savedStmt->execute([$userId]);
        $savedItems = [];
        $unreadCount = 0;
        foreach ($savedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isRead = (int)($row['is_read'] ?? 0);
            if ($isRead === 0) {
                $unreadCount++;
            }
            $savedItems[] = [
                'id' => (int)$row['id'],
                'icon' => (string)$row['icon'],
                'color' => (string)$row['color'],
                'title' => (string)$row['title'],
                'subtitle' => (string)$row['subtitle'],
                'event_at' => (string)$row['event_at'],
                'time' => apiNotificationTimeAgo((string)$row['event_at']),
                'link' => appNotificationTargetUrl($role, (string)$row['link'], (string)$row['source_key']),
                'is_read' => $isRead,
            ];
        }
        apiJson([
            'ok' => true,
            'notifications' => $savedItems,
            'count' => count($savedItems),
            'unread_count' => $unreadCount,
        ]);
    } catch (Throwable $e) {
        error_log('[notification] Saved notification fetch failed: ' . $e->getMessage());
    }

    $notificationItems = [];

    try {
        if ($role === 'admin') {
            $stmt = $db->query("SELECT title, category, created_at
                                FROM announcements
                                WHERE status = 'active'
                                ORDER BY created_at DESC
                                LIMIT 4");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-megaphone', 'color' => 'warning',
                    'title' => (string)$row['title'],
                    'subtitle' => 'School announcement (' . ucfirst((string)$row['category']) . ')',
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $pendingStmt = $db->query("SELECT first_name, last_name, role, created_at
                                       FROM users WHERE status = 'pending'
                                       ORDER BY created_at DESC LIMIT 3");
            foreach ($pendingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-person-plus', 'color' => 'primary',
                    'title' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'subtitle' => 'Pending ' . ucfirst((string)$row['role']) . ' account',
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }
        } elseif ($role === 'teacher') {
            $stmt = $db->query("SELECT title, category, created_at
                                FROM announcements WHERE status = 'active'
                                ORDER BY created_at DESC LIMIT 4");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-megaphone', 'color' => 'warning',
                    'title' => (string)$row['title'],
                    'subtitle' => 'School announcement (' . ucfirst((string)$row['category']) . ')',
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $classAnnStmt = $db->prepare("SELECT ca.title, c.class_name, ca.created_at
                                          FROM class_announcements ca
                                          JOIN class_subjects cs ON cs.class_id = ca.class_id
                                          JOIN classes c ON c.id = ca.class_id
                                          WHERE cs.teacher_id = ? AND ca.status = 'active' AND ca.posted_by <> ?
                                          ORDER BY ca.created_at DESC LIMIT 3");
            $classAnnStmt->execute([$userId, $userId]);
            foreach ($classAnnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-journal-text', 'color' => 'success',
                    'title' => (string)$row['title'],
                    'subtitle' => (string)$row['class_name'],
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }
        } elseif ($role === 'student') {
            $classAnnStmt = $db->prepare("SELECT ca.title, c.class_name, ca.created_at
                                          FROM class_announcements ca
                                          JOIN classes c ON c.id = ca.class_id
                                          JOIN enrollments e ON e.class_id = ca.class_id AND e.student_id = ?
                                          WHERE ca.status = 'active' AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                          ORDER BY ca.created_at DESC LIMIT 5");
            $classAnnStmt->execute([$userId]);
            foreach ($classAnnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-journal-text', 'color' => 'success',
                    'title' => (string)$row['title'],
                    'subtitle' => (string)$row['class_name'],
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $attStmt = $db->prepare("SELECT status, date, created_at FROM attendance
                                     WHERE student_id = ? ORDER BY date DESC, created_at DESC LIMIT 2");
            $attStmt->execute([$userId]);
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-calendar-check', 'color' => 'primary',
                    'title' => 'Attendance marked: ' . ucfirst((string)$row['status']),
                    'subtitle' => 'Date: ' . date('M d, Y', strtotime((string)$row['date'])),
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $matStmt = $db->prepare("SELECT m.title, m.created_at, c.class_name
                                     FROM materials m
                                     JOIN class_subjects cs ON cs.id = m.class_subject_id
                                     JOIN classes c ON c.id = cs.class_id
                                     JOIN enrollments e ON e.class_id = c.id AND e.student_id = ?
                                     WHERE COALESCE(e.status, 'enrolled') = 'enrolled'
                                     ORDER BY m.created_at DESC LIMIT 4");
            $matStmt->execute([$userId]);
            foreach ($matStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-folder2-open', 'color' => 'info',
                    'title' => 'New material: ' . (string)$row['title'],
                    'subtitle' => (string)$row['class_name'],
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $gradeItemStmt = $db->prepare("SELECT gi.title, gi.component, gi.total_score, gi.created_at, c.class_name
                                           FROM grade_items gi
                                           JOIN classes c ON c.id = gi.class_id
                                           JOIN enrollments e ON e.class_id = c.id AND e.student_id = ?
                                           WHERE COALESCE(e.status, 'enrolled') = 'enrolled'
                                           ORDER BY gi.created_at DESC LIMIT 4");
            $gradeItemStmt->execute([$userId]);
            foreach ($gradeItemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $meta = trim((string)$row['class_name'] . ((string)$row['component'] !== '' ? ' · ' . (string)$row['component'] : '') . ((string)$row['total_score'] !== '' ? ' / ' . (string)$row['total_score'] : ''));
                $notificationItems[] = [
                    'icon' => 'bi-clipboard-check', 'color' => 'primary',
                    'title' => 'New grade activity: ' . (string)$row['title'],
                    'subtitle' => $meta,
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }
        } elseif ($role === 'parent') {
            $attStmt = $db->prepare("SELECT a.status, a.date, a.created_at, u.first_name, u.last_name
                                     FROM parent_students ps
                                     JOIN attendance a ON a.student_id = ps.student_id
                                     JOIN users u ON u.id = ps.student_id
                                     WHERE ps.parent_id = ?
                                     ORDER BY a.date DESC, a.created_at DESC LIMIT 5");
            $attStmt->execute([$userId]);
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-calendar-check', 'color' => 'primary',
                    'title' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) . ': ' . ucfirst((string)$row['status']),
                    'subtitle' => 'Date: ' . date('M d, Y', strtotime((string)$row['date'])),
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $annStmt = $db->query("SELECT title, category, created_at FROM announcements WHERE status = 'active' ORDER BY created_at DESC LIMIT 3");
            foreach ($annStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ts = strtotime((string)$row['created_at']) ?: time();
                $notificationItems[] = [
                    'icon' => 'bi-megaphone', 'color' => 'warning',
                    'title' => (string)$row['title'],
                    'subtitle' => 'School announcement (' . ucfirst((string)$row['category']) . ')',
                    'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                    'link' => '#',
                ];
            }

            $childrenStmt = $db->prepare("SELECT u.id, u.first_name, u.last_name FROM parent_students ps JOIN users u ON u.id = ps.student_id WHERE ps.parent_id = ?");
            $childrenStmt->execute([$userId]);
            $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
            $childIds = array_map('intval', array_column($children, 'id'));

            if (!empty($childIds)) {
                $placeholders = implode(',', array_fill(0, count($childIds), '?'));
                $matStmt = $db->prepare("SELECT m.title, m.created_at, c.class_name, e.student_id
                                         FROM materials m
                                         JOIN class_subjects cs ON cs.id = m.class_subject_id
                                         JOIN classes c ON c.id = cs.class_id
                                         JOIN enrollments e ON e.class_id = c.id AND e.student_id IN ($placeholders)
                                         WHERE COALESCE(e.status, 'enrolled') = 'enrolled'
                                         ORDER BY m.created_at DESC LIMIT 6");
                $matStmt->execute($childIds);
                foreach ($matStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $ts = strtotime((string)$row['created_at']) ?: time();
                    $childName = 'Your child';
                    foreach ($children as $c) {
                        if ((int)$c['id'] === (int)$row['student_id']) {
                            $childName = trim((string)$c['first_name'] . ' ' . (string)$c['last_name']);
                            break;
                        }
                    }
                    $notificationItems[] = [
                        'icon' => 'bi-folder2-open', 'color' => 'info',
                        'title' => 'New material for ' . $childName . ': ' . (string)$row['title'],
                        'subtitle' => (string)$row['class_name'],
                        'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                        'link' => '#',
                    ];
                }

                $gradeItemStmt = $db->prepare("SELECT gi.title, gi.component, gi.total_score, gi.created_at, c.class_name, e.student_id
                                               FROM grade_items gi
                                               JOIN classes c ON c.id = gi.class_id
                                               JOIN enrollments e ON e.class_id = c.id AND e.student_id IN ($placeholders)
                                               WHERE COALESCE(e.status, 'enrolled') = 'enrolled'
                                               ORDER BY gi.created_at DESC LIMIT 6");
                $gradeItemStmt->execute($childIds);
                foreach ($gradeItemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $ts = strtotime((string)$row['created_at']) ?: time();
                    $childName = 'Your child';
                    foreach ($children as $c) {
                        if ((int)$c['id'] === (int)$row['student_id']) {
                            $childName = trim((string)$c['first_name'] . ' ' . (string)$c['last_name']);
                            break;
                        }
                    }
                    $meta = trim((string)$row['class_name'] . ((string)$row['component'] !== '' ? ' · ' . (string)$row['component'] : '') . ((string)$row['total_score'] !== '' ? ' / ' . (string)$row['total_score'] : ''));
                    $notificationItems[] = [
                        'icon' => 'bi-clipboard-check', 'color' => 'primary',
                        'title' => 'Grade activity for ' . $childName . ': ' . (string)$row['title'],
                        'subtitle' => $meta,
                        'sort_ts' => $ts, 'time' => apiNotificationTimeAgo($row['created_at']),
                        'link' => '#',
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        $notificationItems = [];
    }

    usort($notificationItems, function ($a, $b) {
        return (int)($b['sort_ts'] ?? 0) <=> (int)($a['sort_ts'] ?? 0);
    });
    $notificationItems = array_slice($notificationItems, 0, 8);

    apiJson(['ok' => true, 'notifications' => $notificationItems, 'count' => count($notificationItems)]);
}

if ($route === 'register-push-token' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $body = apiRequestBody();
    $token = trim((string)($body['token'] ?? ''));
    $device = trim((string)($body['device'] ?? ''));
    if ($token === '') {
        apiJson(['ok' => false, 'message' => 'Push token is required'], 422);
    }
    $ok = pushSaveMobileToken($db, (int)$user['id'], $token, $device);
    apiJson(['ok' => $ok, 'message' => $ok ? 'Push token registered' : 'Failed to register push token']);
}

if ($route === 'unregister-push-token' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $body = apiRequestBody();
    $token = trim((string)($body['token'] ?? ''));
    $ok = pushDeleteMobileToken($db, (int)$user['id'], $token);
    apiJson(['ok' => $ok, 'message' => $ok ? 'Push token unregistered' : 'Failed to unregister push token']);
}

if ($route === 'web-push-subscription' && $method === 'POST') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    if (!pushConfigReady()) {
        apiJson(['ok' => false, 'message' => 'Push notifications are not configured'], 503);
    }

    $body = apiRequestBody();
    $subscription = $body['subscription'] ?? $body;
    if (!is_array($subscription)) {
        apiJson(['ok' => false, 'message' => 'Push subscription is required'], 422);
    }

    $saved = pushSaveSubscription(
        $db,
        (int)$user['id'],
        $subscription,
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
    if ($saved && function_exists('apiEnsureUserSettingsTable')) {
        apiEnsureUserSettingsTable($db);
        $stmt = $db->prepare("INSERT INTO user_settings (user_id, push_notifications)
                              VALUES (?, 1)
                              ON DUPLICATE KEY UPDATE push_notifications = 1");
        $stmt->execute([(int)$user['id']]);
    }

    apiJson([
        'ok' => $saved,
        'message' => $saved ? 'Push notifications enabled' : 'Failed to save push subscription',
    ], $saved ? 200 : 422);
}

if ($route === 'web-push-subscription' && $method === 'DELETE') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }

    $body = apiRequestBody();
    $endpoint = trim((string)($body['endpoint'] ?? ''));
    if ($endpoint === '' && isset($body['subscription']) && is_array($body['subscription'])) {
        $endpoint = trim((string)($body['subscription']['endpoint'] ?? ''));
    }
    if ($endpoint === '') {
        apiJson(['ok' => false, 'message' => 'Push subscription endpoint is required'], 422);
    }

    $deleted = pushDeleteSubscription($db, (int)$user['id'], $endpoint);
    apiJson([
        'ok' => $deleted,
        'message' => $deleted ? 'Push notifications disabled for this device' : 'Failed to remove push subscription',
    ]);
}
