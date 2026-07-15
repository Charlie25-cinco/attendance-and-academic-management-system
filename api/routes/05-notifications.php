<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
if ($route === 'notifications' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db) {
        apiJson(['ok' => false, 'message' => 'Database connection failed'], 500);
    }
    $role = (string)($user['role'] ?? '');
    $userId = (int)$user['id'];

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
