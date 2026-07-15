<?php
if (!function_exists('appRequestHostName')) {
    function appRequestHostName(): string {
        $host = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '') { return 'localhost'; }
        $parts = explode(':', $host, 2);
        return $parts[0];
    }
}

if (!function_exists('appIsLocalCookieHost')) {
    function appIsLocalCookieHost(): bool {
        return in_array(appRequestHostName(), ['localhost', '127.0.0.1', '::1'], true);
    }
}

if (!function_exists('appIsEmbeddedRequest')) {
    function appIsEmbeddedRequest(): bool {
        $fetchDest = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
        return in_array($fetchDest, ['iframe', 'frame'], true);
    }
}

if (!function_exists('appCookieSecure')) {
    function appCookieSecure(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || appIsLocalCookieHost();
    }
}

if (!function_exists('appCookieSameSite')) {
    function appCookieSameSite(): string {
        return appIsEmbeddedRequest() ? 'None' : 'Lax';
    }
}

if (!function_exists('appCookieParams')) {
    function appCookieParams(int $expires = 0, bool $httpOnly = true): array {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => appCookieSecure(),
            'httponly' => $httpOnly,
            'samesite' => appCookieSameSite()
        ];
    }
}

if (!function_exists('appSessionCookieParams')) {
    function appSessionCookieParams(bool $httpOnly = true): array {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => appCookieSecure(),
            'httponly' => $httpOnly,
            'samesite' => appCookieSameSite()
        ];
    }
}

if (!defined('APP_SESSION_IDLE_TIMEOUT')) {
    define('APP_SESSION_IDLE_TIMEOUT', 1800);
}

if (!function_exists('appExpireCurrentSession')) {
function appExpireCurrentSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    session_destroy();
}
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = appCookieSecure();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(appSessionCookieParams(true));
    }

    session_start();
}

if (!empty($_SESSION['logged_in'])) {
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && ($now - $lastActivity) > APP_SESSION_IDLE_TIMEOUT) {
        appExpireCurrentSession();
        session_start();
        $_SESSION['session_expired'] = true;
    } else {
        $_SESSION['last_activity'] = $now;
    }
}

if (!headers_sent()) {
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Vary: Sec-Fetch-Dest');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (!function_exists('appAssetPath')) {
    function appAssetPath(string $relativePath): string {
        $normalizedRelativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $normalizedRelativePath = preg_replace('#^src/#', '', $normalizedRelativePath);
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.*)/(admin|api|auth|config|database|includes|teacher|student|parent|assets|site)(?:/|$)#', $script, $m)) {
            $url = rtrim($m[1], '/') . '/assets/' . $normalizedRelativePath;
        } else {
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($dir !== '' && $dir !== '.') {
                $url = $dir . '/assets/' . $normalizedRelativePath;
            } else {
                $url = '/assets/' . $normalizedRelativePath;
            }
        }

        $publicRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets';
        $assetFile = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelativePath);
        if (is_file($assetFile)) {
            $version = @filemtime($assetFile);
            if ($version !== false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode((string)$version);
            }
        }

        return $url;
    }
}

if (!function_exists('appUiDarkModeEnabled')) {
    function appUiDarkModeEnabled() {
        if (isset($_SESSION['ui_dark_mode'])) {
            return (int)$_SESSION['ui_dark_mode'] === 1;
        }
        if (isset($_COOKIE['app_dark_mode'])) {
            return $_COOKIE['app_dark_mode'] === '1';
        }
        return false;
    }
}

if (!defined('APP_THEME_BODY_BUFFER_STARTED')) {
    define('APP_THEME_BODY_BUFFER_STARTED', true);
    ob_start(function ($buffer) {
        if ($buffer === '') { return $buffer; }
        if (!appUiDarkModeEnabled()) { return $buffer; }

        $criticalHead = '<meta name="color-scheme" content="dark light">' . "\n"
            . '<style id="app-theme-critical-dark">'
            . 'html,body{background-color:#111827 !important;color:#e5e7eb !important;}'
            . 'body.dark-mode{background-color:#111827 !important;color:#e5e7eb !important;}'
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
            . '</style>' . "\n"
            . '<script>document.documentElement.classList.add("dark-mode");document.documentElement.style.backgroundColor="#111827";</script>' . "\n";

        if (stripos($buffer, '<head>') !== false && stripos($buffer, 'app-theme-critical-dark') === false) {
            $buffer = preg_replace('/<head>/i', '<head>' . "\n" . $criticalHead, $buffer, 1);
        }

        if (stripos($buffer, '<html') !== false) {
            $buffer = preg_replace_callback('/<html([^>]*)>/i', function ($matches) {
                $attrs = (string)($matches[1] ?? '');
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $classMatch)) {
                    $classes = preg_split('/\s+/', trim((string)$classMatch[2])) ?: [];
                    if (!in_array('dark-mode', $classes, true)) { $classes[] = 'dark-mode'; }
                    $newClassAttr = 'class=' . $classMatch[1] . trim(implode(' ', array_filter($classes))) . $classMatch[1];
                    $updatedAttrs = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $newClassAttr, $attrs, 1);
                    if (!preg_match('/\bstyle\s*=/i', $updatedAttrs)) {
                        $updatedAttrs .= ' style="background-color:#111827;"';
                    }
                    return '<html' . $updatedAttrs . '>';
                }
                return '<html class="dark-mode" style="background-color:#111827;"' . $attrs . '>';
            }, $buffer, 1);
        }

        if (stripos($buffer, '<body') !== false) {
            return preg_replace_callback('/<body([^>]*)>/i', function ($matches) {
                $attrs = (string)($matches[1] ?? '');
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $classMatch)) {
                    $classes = preg_split('/\s+/', trim((string)$classMatch[2])) ?: [];
                    if (!in_array('dark-mode', $classes, true)) { $classes[] = 'dark-mode'; }
                    $newClassAttr = 'class=' . $classMatch[1] . trim(implode(' ', array_filter($classes))) . $classMatch[1];
                    $updatedAttrs = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $newClassAttr, $attrs, 1);
                    if (!preg_match('/\bstyle\s*=/i', $updatedAttrs)) {
                        $updatedAttrs .= ' style="background-color:#111827;color:#e5e7eb;"';
                    }
                    return '<body' . $updatedAttrs . '>';
                }
                return '<body class="dark-mode" style="background-color:#111827;color:#e5e7eb;"' . $attrs . '>';
            }, $buffer, 1);
        }

        return $buffer;
    });
}
