<?php
// Header Component - Attendance and Academic Management System
// Usage: Include this file and optionally set $page_title variable before including

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

if (!isset($page_title)) $page_title = 'Dashboard';
if (!isset($current_role)) $current_role = 'admin';
if (!isset($current_page)) $current_page = 'dashboard';

$headerIconMap = [
    'dashboard' => 'bi-grid-1x2-fill',
    'users' => 'bi-people-fill',
    'classes' => 'bi-journal-bookmark-fill',
    'sections' => 'bi-diagram-3-fill',
    'attendance' => 'bi-calendar-check-fill',
    'grades' => 'bi-bar-chart-fill',
    'reports' => 'bi-file-earmark-text-fill',
    'report_cards' => 'bi-journal-check',
    'announcements' => 'bi-megaphone-fill',
    'archives' => 'bi-archive-fill'
];
$headerIconClass = $headerIconMap[$current_page] ?? 'bi-layout-text-window-reverse';
$headerSubtitle = 'Balingasag Senior High School';

$rawFirstName = trim((string)($_SESSION['first_name'] ?? ''));
$displayMiddleName = trim((string)($_SESSION['middle_name'] ?? ''));
$displayFirstName = $rawFirstName;

// Backward compatibility: older data stores middle name inside first_name.
if ($displayMiddleName === '' && strpos($rawFirstName, ' ') !== false) {
    $parts = preg_split('/\s+/', $rawFirstName);
    $displayFirstName = trim((string)array_shift($parts));
    $displayMiddleName = trim(implode(' ', $parts));
}

$displayRole = ucfirst($_SESSION['role'] ?? $current_role);
$displayEmail = trim((string)($_SESSION['email'] ?? ''));
$displaySex = strtolower(trim((string)($_SESSION['sex'] ?? '')));
$displayReference = trim((string)($_SESSION['reference_code'] ?? ''));
$displayInitials = '';
if ($displayFirstName !== '') {
    $displayInitials .= strtoupper(substr($displayFirstName, 0, 1));
}
$lastNameForInitial = trim((string)($_SESSION['last_name'] ?? ''));
if ($lastNameForInitial !== '') {
    $displayInitials .= strtoupper(substr($lastNameForInitial, 0, 1));
}
if ($displayInitials === '') {
    $displayInitials = 'U';
}

$rolePages = [
    'admin' => ['dashboard' => 'admin.php', 'attendance' => 'admin_Attendance.php', 'announcements' => 'admin_Announcements.php'],
    'teacher' => ['dashboard' => 'teacher.php', 'attendance' => 'teacher_Attendance.php', 'announcements' => 'teacher_Announcements.php', 'classes' => 'teacher_Classes.php'],
    'student' => ['dashboard' => 'Student.php', 'attendance' => 'Student_Attendance.php', 'announcements' => 'Student_Announcements.php', 'classes' => 'student_Classes.php'],
    'parent' => ['dashboard' => 'Parent.php', 'attendance' => 'Parent_Progress.php', 'announcements' => 'Parent_Announcements.php'],
];
$currentPages = $rolePages[$current_role] ?? $rolePages['admin'];

if (!function_exists('headerTimeAgo')) {
    function headerTimeAgo($dateTime) {
        $ts = strtotime((string)$dateTime);
        if (!$ts) return 'Just now';
        $diff = time() - $ts;
        if ($diff < 0) return 'Just now';
        if ($diff < 60) return $diff . ' sec ago';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        if ($diff < 172800) return 'Yesterday';
        return date('M d, Y', $ts);
    }
}

if (!function_exists('headerNotificationSourceKey')) {
    function headerNotificationSourceKey($role, array $item) {
        return md5(implode('|', [
            (string)$role,
            trim((string)($item['title'] ?? '')),
            trim((string)($item['subtitle'] ?? '')),
            trim((string)($item['icon'] ?? 'bi-bell')),
            trim((string)($item['color'] ?? 'primary')),
            trim((string)($item['link'] ?? '')),
            trim((string)($item['event_at'] ?? ''))
        ]));
    }
}

if (!function_exists('headerCacheRead')) {
    function headerCacheRead(string $key, int $userId, int $ttl): ?array {
        if ($ttl <= 0) {
            return null;
        }
        $cached = $_SESSION[$key] ?? null;
        if (!is_array($cached)) {
            return null;
        }
        if ((int)($cached['user_id'] ?? 0) !== $userId) {
            return null;
        }
        if ((int)($cached['expires_at'] ?? 0) < time()) {
            return null;
        }
        $data = $cached['data'] ?? null;
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('headerCacheWrite')) {
    function headerCacheWrite(string $key, int $userId, array $data, int $ttl): void {
        if ($ttl <= 0) {
            return;
        }
        $_SESSION[$key] = [
            'user_id' => $userId,
            'expires_at' => time() + $ttl,
            'data' => $data
        ];
    }
}

if (!function_exists('headerApplyProfileRow')) {
    function headerApplyProfileRow(array $profileRow, string &$displayFirstName, string &$displayMiddleName, string &$displaySex, string &$displayEmail, string &$displayReference): void {
        $dbFirstName = trim((string)($profileRow['first_name'] ?? ''));
        $dbMiddleName = trim((string)($profileRow['middle_name'] ?? ''));
        $dbLastName = trim((string)($profileRow['last_name'] ?? ''));
        $dbSex = strtolower(trim((string)($profileRow['sex'] ?? '')));
        $dbEmail = trim((string)($profileRow['email'] ?? ''));
        $dbReference = trim((string)($profileRow['reference_code'] ?? ''));

        if ($dbFirstName !== '') {
            $displayFirstName = $dbFirstName;
            $_SESSION['first_name'] = $dbFirstName;
        }
        $displayMiddleName = $dbMiddleName;
        $_SESSION['middle_name'] = $dbMiddleName;
        if ($dbLastName !== '') {
            $_SESSION['last_name'] = $dbLastName;
        }
        if ($dbSex !== '') {
            $displaySex = $dbSex;
            $_SESSION['sex'] = $dbSex;
        }
        if ($dbEmail !== '') {
            $displayEmail = $dbEmail;
            $_SESSION['email'] = $dbEmail;
        }
        if ($dbReference !== '') {
            $displayReference = $dbReference;
            $_SESSION['reference_code'] = $dbReference;
        }
    }
}

$notificationItems = [];
$notification_count = 0;
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$headerCacheTtl = max(0, (int)appEnvValue('APP_HEADER_CACHE_TTL', '60'));
$headerInitialSettings = [
    'dark_mode' => 0,
    'email_notifications' => 1,
    'push_notifications' => 1
];
$headerHasSavedSettings = false;

$headerDb = null;
if (isset($db) && $db instanceof PDO) {
    $headerDb = $db;
} else {
    $headerDb = (new Database())->getConnection();
}

$sessionRole = $_SESSION['role'] ?? $current_role;
if ($headerDb && $sessionUserId > 0 && $sessionRole === 'teacher') {
    try {
        $teacherRoles = headerCacheRead('app_header_teacher_roles', $sessionUserId, $headerCacheTtl);
        if ($teacherRoles === null) {
            $teacherRoles = getTeacherRoles($headerDb, $sessionUserId);
            headerCacheWrite('app_header_teacher_roles', $sessionUserId, $teacherRoles, $headerCacheTtl);
        }
        $roleLabels = [];
        if (!empty($teacherRoles['sections'])) {
            $roleLabels[] = 'Adviser';
        }
        if (!empty($teacherRoles['classes'])) {
            $roleLabels[] = 'Subject Teacher';
        }
        if (!empty($roleLabels)) {
            $displayRole = implode(' & ', $roleLabels);
        }
    } catch (Throwable $e) {
        error_log("getTeacherRoles error: " . $e->getMessage());
    }
}

if ($headerDb && $sessionUserId > 0) {
    try {
        $profileRow = headerCacheRead('app_header_profile', $sessionUserId, $headerCacheTtl);
        if ($profileRow === null) {
            $profileStmt = $headerDb->prepare("SELECT first_name, middle_name, last_name, sex, email, reference_code
                                               FROM users
                                               WHERE id = ?
                                               LIMIT 1");
            $profileStmt->execute([$sessionUserId]);
            $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            headerCacheWrite('app_header_profile', $sessionUserId, $profileRow, $headerCacheTtl);
        }
        if (!empty($profileRow)) {
            headerApplyProfileRow($profileRow, $displayFirstName, $displayMiddleName, $displaySex, $displayEmail, $displayReference);
        }
    } catch (Throwable $e) {
        error_log('Header profile query error: ' . $e->getMessage());
    }

    try {
        $settingsCache = headerCacheRead('app_header_settings', $sessionUserId, $headerCacheTtl);
        if ($settingsCache === null) {
            $settingsCache = ['has_saved' => false, 'settings' => $headerInitialSettings];
            if (dbHasTable($headerDb, 'user_settings')) {
                $settingsStmt = $headerDb->prepare("SELECT dark_mode, email_notifications, push_notifications
                                                    FROM user_settings
                                                    WHERE user_id = ?
                                                    LIMIT 1");
                $settingsStmt->execute([$sessionUserId]);
                $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);
                if ($settingsRow) {
                    $settingsCache = [
                        'has_saved' => true,
                        'settings' => [
                            'dark_mode' => (int)($settingsRow['dark_mode'] ?? 0),
                            'email_notifications' => (int)($settingsRow['email_notifications'] ?? 1),
                            'push_notifications' => (int)($settingsRow['push_notifications'] ?? 1)
                        ]
                    ];
                }
            }
            headerCacheWrite('app_header_settings', $sessionUserId, $settingsCache, $headerCacheTtl);
        }
        $headerHasSavedSettings = !empty($settingsCache['has_saved']);
        if (isset($settingsCache['settings']) && is_array($settingsCache['settings'])) {
            $headerInitialSettings = [
                'dark_mode' => (int)($settingsCache['settings']['dark_mode'] ?? 0),
                'email_notifications' => (int)($settingsCache['settings']['email_notifications'] ?? 1),
                'push_notifications' => (int)($settingsCache['settings']['push_notifications'] ?? 1)
            ];
            $_SESSION['ui_dark_mode'] = $headerInitialSettings['dark_mode'] === 1 ? 1 : 0;
            setcookie('app_dark_mode', $_SESSION['ui_dark_mode'] ? '1' : '0', appCookieParams(time() + (86400 * 365), false));
        }
    } catch (Throwable $e) {
        error_log('Header settings query error: ' . $e->getMessage());
    }

    try {
        if (dbHasTable($headerDb, 'user_notifications')) {
            $notifStmt = $headerDb->prepare("SELECT id, title, subtitle, icon, color, link, event_at, is_read
                                             FROM user_notifications
                                             WHERE user_id = ?
                                             ORDER BY is_read ASC, event_at DESC, id DESC
                                             LIMIT 8");
            $notifStmt->execute([$sessionUserId]);
            foreach ($notifStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $item['time'] = headerTimeAgo($item['event_at'] ?? '');
                $notificationItems[] = $item;
                if ((int)($item['is_read'] ?? 0) === 0) {
                    $notification_count++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Header notifications query error: ' . $e->getMessage());
    }
}

if ($displayFirstName === '' && $rawFirstName !== '') {
    $displayFirstName = $rawFirstName;
}

$displayName = trim(implode(' ', array_filter([
    $displayFirstName,
    $displayMiddleName,
    trim((string)($_SESSION['last_name'] ?? ''))
])));
if ($displayName === '') {
    $displayName = 'My Account';
}
?>
<script>
window.APP_CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
window.APP_PUSH_PUBLIC_KEY = <?php echo json_encode(PUSH_VAPID_PUBLIC_KEY); ?>;
// Auto-attach CSRF token to same-origin action endpoints.
(function() {
    const token = (window.APP_CSRF_TOKEN || '').toString();
    if (!token || typeof window.fetch !== 'function') return;

    const originalFetch = window.fetch.bind(window);
    window.fetch = function(input, init) {
        try {
            const req = input instanceof Request ? input : null;
            const method = ((init && init.method) || (req && req.method) || 'GET').toUpperCase();
            const rawUrl = req ? req.url : String(input || '');
            const urlObj = new URL(rawUrl, window.location.href);
            const path = (urlObj.pathname || '').toLowerCase();
            const sameOrigin = urlObj.origin === window.location.origin;
            const isActionEndpoint = path.includes('_action.php');

            if (!sameOrigin || !isActionEndpoint) {
                return originalFetch(input, init);
            }

            if (method === 'GET' || method === 'HEAD') {
                if (!urlObj.searchParams.has('csrf_token')) {
                    urlObj.searchParams.set('csrf_token', token);
                }
                if (req) {
                    input = new Request(urlObj.toString(), req);
                } else {
                    input = urlObj.toString();
                }
                return originalFetch(input, init);
            }

            const nextInit = Object.assign({}, init || {});
            const headers = new Headers(nextInit.headers || (req ? req.headers : undefined) || undefined);
            if (!headers.has('X-CSRF-Token')) {
                headers.set('X-CSRF-Token', token);
            }
            const contentType = (headers.get('Content-Type') || '').toLowerCase();
            let body = nextInit.body;

            if (body instanceof FormData) {
                if (!body.has('csrf_token')) body.append('csrf_token', token);
                nextInit.body = body;
                nextInit.headers = headers;
                return originalFetch(input, nextInit);
            }

            if (body instanceof URLSearchParams) {
                if (!body.has('csrf_token')) body.set('csrf_token', token);
                nextInit.body = body;
                nextInit.headers = headers;
                return originalFetch(input, nextInit);
            }

            if (typeof body === 'string') {
                if (contentType.includes('application/json')) {
                    const parsed = JSON.parse(body || '{}');
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && !Object.prototype.hasOwnProperty.call(parsed, 'csrf_token')) {
                        parsed.csrf_token = token;
                    }
                    nextInit.body = JSON.stringify(parsed);
                } else {
                    const params = new URLSearchParams(body);
                    if (!params.has('csrf_token')) params.set('csrf_token', token);
                    nextInit.body = params.toString();
                    if (!headers.has('Content-Type')) {
                        headers.set('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8');
                    }
                }
                nextInit.headers = headers;
                return originalFetch(input, nextInit);
            }

            if (!body) {
                if (contentType.includes('application/json')) {
                    nextInit.body = JSON.stringify({ csrf_token: token });
                } else {
                    const params = new URLSearchParams();
                    params.set('csrf_token', token);
                    nextInit.body = params;
                }
                nextInit.headers = headers;
                return originalFetch(input, nextInit);
            }
        } catch (e) {}

        return originalFetch(input, init);
    };
})();
// Apply dark mode as early as possible to reduce flash/flicker.
window.APP_INITIAL_SETTINGS = <?php echo json_encode($headerInitialSettings); ?>;
window.APP_HAS_SAVED_SETTINGS = <?php echo $headerHasSavedSettings ? 'true' : 'false'; ?>;
try {
    const shouldUseDarkMode = window.APP_HAS_SAVED_SETTINGS
        ? String(window.APP_INITIAL_SETTINGS.dark_mode) === '1'
        : localStorage.getItem('darkMode') === 'true';
    if (shouldUseDarkMode) {
        document.body.classList.add('dark-mode');
    }
} catch (e) {}

</script>

<!-- Header -->
<header class="header">
    <div class="header-main">
        <!-- Mobile Menu Button -->
        <button class="header-btn mobile-menu-btn d-lg-none" type="button" onclick="openMobileSidebar()" aria-label="Open navigation menu">
            <i class="bi bi-list" style="font-size: 20px;"></i>
        </button>
        
        <div class="header-title-wrap">
            <span class="header-title-icon"><i class="bi <?php echo htmlspecialchars($headerIconClass); ?>"></i></span>
            <div class="header-title-text">
                <h2 class="header-title mb-0"><?php echo htmlspecialchars($page_title); ?></h2>
                <small class="header-subtitle"><?php echo htmlspecialchars($headerSubtitle); ?></small>
            </div>
        </div>
    </div>

    <div class="header-actions">
        <!-- PWA Install -->
        <button class="header-btn" id="pwaInstallBtn" title="Install App" aria-label="Install App" style="display:none;">
            <i class="bi bi-download"></i>
        </button>

        <!-- Refresh -->
        <button class="header-btn" id="headerRefreshBtn" type="button" title="Refresh page" aria-label="Refresh page">
            <i class="bi bi-arrow-clockwise"></i>
        </button>

        <!-- Notifications -->
        <button class="header-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="View notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notification_count > 0): ?>
            <span class="notification-badge"><?php echo $notification_count; ?></span>
            <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end header-dropdown-menu header-notification-menu">
            <li class="dropdown-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Notifications</span>
                <div class="d-flex gap-2">
                    <a href="#" id="markAllNotificationsRead" class="text-decoration-none" style="font-size: 12px;">Read all</a>
                    <a href="#" id="deleteAllNotifications" class="text-decoration-none text-danger" style="font-size: 12px;">Delete all</a>
                </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <?php if (empty($notificationItems)): ?>
                <li><div class="dropdown-item text-muted py-2">No new notifications</div></li>
            <?php else: ?>
                <?php foreach ($notificationItems as $item): ?>
                    <li>
                        <div class="dropdown-item d-flex align-items-start py-2 <?php echo ((int)($item['is_read'] ?? 0) === 1) ? 'opacity-75' : ''; ?>">
                            <a class="header-notification-item d-flex align-items-start text-decoration-none text-reset flex-grow-1" href="<?php echo htmlspecialchars((string)$item['link']); ?>" data-notification-id="<?php echo (int)($item['id'] ?? 0); ?>">
                                <div class="bg-<?php echo htmlspecialchars((string)$item['color']); ?> bg-opacity-10 rounded-circle p-2 me-3">
                                    <i class="bi <?php echo htmlspecialchars((string)$item['icon']); ?> text-<?php echo htmlspecialchars((string)$item['color']); ?>"></i>
                                </div>
                                <div>
                                    <p class="mb-0 fw-medium" style="font-size: 14px;"><?php echo htmlspecialchars((string)$item['title']); ?></p>
                                    <p class="mb-0 text-muted" style="font-size: 12px;"><?php echo htmlspecialchars((string)$item['subtitle']); ?></p>
                                    <small class="text-muted header-notification-time" data-event-at="<?php echo htmlspecialchars((string)($item['event_at'] ?? '')); ?>"><?php echo htmlspecialchars((string)$item['time']); ?></small>
                                </div>
                            </a>
                            <button type="button"
                                    class="btn btn-sm btn-link text-danger p-0 ms-2 delete-notification-btn"
                                    title="Delete"
                                    data-notification-id="<?php echo (int)($item['id'] ?? 0); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-center text-primary" href="<?php echo htmlspecialchars($currentPages['announcements']); ?>" id="viewAllNotifications">View all notifications</a></li>
        </ul>

        <!-- Profile -->
        <div class="dropdown">
            <button class="header-btn header-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open profile menu">
                <span class="header-profile-avatar"><?php echo htmlspecialchars($displayInitials); ?></span>
                <span class="header-profile-meta d-none d-md-flex">
                    <span class="header-profile-name"><?php echo htmlspecialchars($displayName); ?></span>
                    <span class="header-profile-role"><?php echo htmlspecialchars($displayRole); ?></span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end header-dropdown-menu">
                <li class="dropdown-header">
                    <div class="fw-semibold" id="headerProfileName"><?php echo htmlspecialchars($displayName); ?></div>
                    <small class="text-muted"><?php echo htmlspecialchars($displayRole); ?></small>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../auth/logout.php" id="headerLogoutLink"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>
<?php include __DIR__ . '/modals.php'; ?>
