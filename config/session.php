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

if (!function_exists('appRequestIsHttps')) {
    function appRequestIsHttps(): bool {
        $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        return ($https !== '' && $https !== 'off') || $forwardedProto === 'https';
    }
}

if (!function_exists('appCookieSecure')) {
    function appCookieSecure(): bool {
        return appRequestIsHttps() || appIsLocalCookieHost();
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
    define('APP_SESSION_IDLE_TIMEOUT', (int)appEnvValue('APP_SESSION_IDLE_TIMEOUT', '1800'));
}

if (!function_exists('appDatabaseSessionsEnabled')) {
    function appDatabaseSessionsEnabled(): bool {
        $driver = strtolower(appEnvValue('APP_SESSION_DRIVER', 'file'));
        return in_array($driver, ['database', 'db', 'tidb'], true);
    }
}

if (!class_exists('AppDatabaseSessionHandler')) {
    class_alias(\BshsAms\Database\SessionHandler::class, 'AppDatabaseSessionHandler');
}

if (!function_exists('appConfigureSessionStorage')) {
    function appConfigureSessionStorage(): void {
        if (!appDatabaseSessionsEnabled() || session_status() === PHP_SESSION_ACTIVE || !class_exists('Database')) {
            return;
        }
        try {
            $db = (new Database())->getConnection();
            if (!$db instanceof PDO) {
                error_log('Database session storage requested but database connection is unavailable; using PHP default sessions.');
                return;
            }
            session_set_save_handler(new AppDatabaseSessionHandler($db), true);
        } catch (Throwable $e) {
            error_log('Database session storage setup failed: ' . $e->getMessage());
        }
    }
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

if (!function_exists('appPermissionsPolicyHeader')) {
    function appPermissionsPolicyHeader(string $scriptName): string {
        $cameraDirective = strtolower($scriptName) === 'teacher_attendance.php' ? 'camera=(self)' : 'camera=()';
        return $cameraDirective
            . ', microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), accelerometer=(), gyroscope=(), magnetometer=()';
    }
}

if (!function_exists('appApplyLogicalSecurityHeaders')) {
    function appApplyLogicalSecurityHeaders(): void {
        if (headers_sent()) { return; }

        $scriptName = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: ' . appPermissionsPolicyHeader($scriptName));
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Vary: Sec-Fetch-Dest');

        if (!empty($_SESSION['logged_in'])) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        if (appRequestIsHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = appCookieSecure();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.gc_maxlifetime', appEnvValue('APP_SESSION_LIFETIME', (string)APP_SESSION_IDLE_TIMEOUT));

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(appSessionCookieParams(true));
    }

    appConfigureSessionStorage();
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

appApplyLogicalSecurityHeaders();

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
    function appUiDarkModeEnabled(): bool {
        if (isset($_SESSION['ui_dark_mode'])) {
            return (int)$_SESSION['ui_dark_mode'] === 1;
        }
        if (isset($_COOKIE['app_dark_mode'])) {
            return $_COOKIE['app_dark_mode'] === '1';
        }
        return false;
    }
}

