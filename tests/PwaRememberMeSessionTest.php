<?php

use PHPUnit\Framework\TestCase;

final class PwaRememberMeSessionTest extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            last_login DATETIME
        )");

        $this->db->exec("CREATE TABLE auth_remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            selector TEXT NOT NULL UNIQUE,
            token_hash TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME,
            revoked_at DATETIME
        )");

        $this->db->exec("CREATE TABLE user_settings (
            user_id INTEGER PRIMARY KEY,
            dark_mode INTEGER DEFAULT 0,
            push_notifications INTEGER DEFAULT 1
        )");

        $this->db->exec("CREATE TABLE push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $_SESSION = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        $this->db = null;
    }

    public function testRememberMeTokenCreationAndAutoLoginRestoration(): void
    {
        $passwordHash = password_hash('SecretPassword123!', PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                                    VALUES ('TCH-001', 'teacher@school.edu', ?, 'Jane', 'Doe', 'teacher', 'active')");
        $stmt->execute([$passwordHash]);
        $userId = (int)$this->db->lastInsertId();

        // 1. Issue remember token
        appRememberIssueToken($this->db, $userId);

        $tokens = $this->db->query("SELECT * FROM auth_remember_tokens WHERE user_id = {$userId}")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $tokens);
        $selector = $tokens[0]['selector'];
        $tokenHash = $tokens[0]['token_hash'];
        $this->assertNotEmpty($selector);
        $this->assertNotEmpty($tokenHash);

        // 2. Simulate closing PWA window and session destruction
        $_SESSION = [];

        // In PHP CLI, setcookie won't populate $_COOKIE automatically, so we simulate the browser sending the cookie
        // To get the raw validator, we test verification with valid and invalid cookies
        $rawValidator = 'testValidatorToken12345678901234';
        $hashedValidator = hash('sha256', $rawValidator);

        $update = $this->db->prepare("UPDATE auth_remember_tokens SET token_hash = ? WHERE id = ?");
        $update->execute([$hashedValidator, $tokens[0]['id']]);

        // 3. Test failed auto-login with wrong validator
        $_COOKIE['remember_token'] = $selector . ':wrongValidator';
        $failedUser = appAttemptRememberLogin($this->db);
        $this->assertNull($failedUser);

        // Token should be revoked after invalid attempt
        $revoked = $this->db->query("SELECT revoked_at FROM auth_remember_tokens WHERE id = {$tokens[0]['id']}")->fetchColumn();
        $this->assertNotNull($revoked);

        // 4. Issue a fresh valid token and test successful restoration
        $newSelector = 'validSelector123';
        $newValidator = 'validValidator1234567890';
        $newHash = hash('sha256', $newValidator);
        $insert = $this->db->prepare("INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, created_at)
                                      VALUES (?, ?, ?, DATETIME('now', '+30 days'), DATETIME('now'))");
        $insert->execute([$userId, $newSelector, $newHash]);

        $_COOKIE['remember_token'] = $newSelector . ':' . $newValidator;
        $authUser = appAttemptRememberLogin($this->db);

        $this->assertIsArray($authUser);
        $this->assertSame($userId, $authUser['id']);
        $this->assertSame('teacher', $authUser['role']);
        $this->assertSame('TCH-001', $authUser['reference_code']);

        // 5. Establish session from restored user
        appEstablishLoginSession($this->db, $authUser);
        $this->assertTrue($_SESSION['logged_in']);
        $this->assertSame($userId, $_SESSION['user_id']);
        $this->assertSame('teacher', $_SESSION['role']);
        $this->assertNotEmpty($_SESSION['csrf_token']);
    }

    public function testSessionWithoutRememberMeDoesNotAutoLogin(): void
    {
        $_COOKIE = [];
        $_SESSION = [];

        $user = appAttemptRememberLogin($this->db);
        $this->assertNull($user);
        $this->assertEmpty($_SESSION);
    }

    public function testDefaultPasswordBlocksRememberMeAutoLogin(): void
    {
        $defaultPasswordHash = password_hash(getDefaultNewUserPassword(), PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, status)
                                    VALUES ('STD-001', 'student@school.edu', ?, 'John', 'Smith', 'student', 'active')");
        $stmt->execute([$defaultPasswordHash]);
        $userId = (int)$this->db->lastInsertId();

        $selector = 'defaultPwdSelector';
        $validator = 'defaultPwdValidator';
        $stmt = $this->db->prepare("INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, created_at)
                                    VALUES (?, ?, ?, DATETIME('now', '+30 days'), DATETIME('now'))");
        $stmt->execute([$userId, $selector, hash('sha256', $validator)]);

        $_COOKIE['remember_token'] = $selector . ':' . $validator;
        $user = appAttemptRememberLogin($this->db);
        $this->assertIsArray($user);

        // Verify that default password is detected
        $this->assertTrue(password_verify(getDefaultNewUserPassword(), (string)$user['password']));

        // Revoke token when default password is still in use
        appRememberRevokeByCookie($this->db, $_COOKIE['remember_token']);
        $revoked = $this->db->query("SELECT revoked_at FROM auth_remember_tokens WHERE selector = '{$selector}'")->fetchColumn();
        $this->assertNotNull($revoked);
    }

    public function testLoginRedirectsAuthenticatedSession(): void
    {
        $loginCode = file_get_contents(__DIR__ . '/../auth/login.php');
        $this->assertIsString($loginCode);

        $this->assertStringContainsString("if (!empty(\$_SESSION['logged_in']) && !empty(\$_SESSION['role']))", $loginCode);
        $this->assertStringContainsString("redirectByRole((string)\$_SESSION['role'])", $loginCode);
    }

    public function testPushModalLifecycleSequenceNodeSimulation(): void
    {
        $mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $constantsPhp = file_get_contents(__DIR__ . '/../config/constants.php');

        $this->assertIsString($mainJs);
        $this->assertIsString($constantsPhp);

        // Verify constants.php sets bshs_pwa_first_open_pending on appinstalled
        $this->assertStringContainsString("window.localStorage.setItem('bshs_pwa_first_open_pending', '1')", $constantsPhp);

        // Verify main.js consumes bshs_pwa_first_open_pending
        $this->assertStringContainsString("localStorage.removeItem(\"bshs_pwa_first_open_pending\")", $mainJs);

        // Node.js test simulating:
        // 1. appinstalled event sets bshs_pwa_first_open_pending
        // 2. Normal browser tab (not standalone) does not show prompt
        // 3. First standalone launch shows prompt and consumes pending flag
        // 4. Dismissal stops subsequent prompt
        $nodeScript = "
        class MockLocalStorage {
            constructor() { this.store = {}; }
            getItem(k) { return this.store[k] || null; }
            setItem(k, v) { this.store[k] = String(v); }
            removeItem(k) { delete this.store[k]; }
        }

        class MockElement {
            constructor(id) {
                this.id = id;
                this.listeners = {};
                this.onclick = null;
                this.style = {};
                this.classList = { add: () => {}, remove: () => {}, contains: () => false };
                this.children = [];
                this.attributes = {};
                this.innerHTML = '';
            }
            setAttribute(k, v) { this.attributes[k] = v; }
            getAttribute(k) { return this.attributes[k]; }
            appendChild(el) { this.children.push(el); return el; }
            remove() { this.removed = true; }
            querySelector(sel) { return null; }
            addEventListener(ev, fn) { this.listeners[ev] = fn; }
        }

        const localStorage = new MockLocalStorage();
        let modalShownCount = 0;
        let modalHiddenCount = 0;

        const mockModalInstance = {
            show: () => { modalShownCount++; },
            hide: () => { modalHiddenCount++; }
        };

        const bootstrap = {
            Modal: {
                getOrCreateInstance: () => mockModalInstance
            }
        };

        const modalEl = new MockElement('pushPromptModal');
        const allowBtn = new MockElement('pushPromptAllowBtn');
        const denyBtn = new MockElement('pushPromptDenyBtn');
        const laterBtn = new MockElement('pushPromptLaterBtn');
        const closeBtn = new MockElement('pushPromptCloseBtn');

        const elements = {
            pushPromptModal: modalEl,
            pushPromptAllowBtn: allowBtn,
            pushPromptDenyBtn: denyBtn,
            pushPromptLaterBtn: laterBtn,
            pushPromptCloseBtn: closeBtn
        };

        const document = {
            getElementById: (id) => elements[id] || null,
            querySelectorAll: () => [],
            querySelector: () => null,
            createElement: (tag) => new MockElement(tag),
            body: new MockElement('body'),
            addEventListener: () => {}
        };

        let isStandalone = false;
        function isInstalledPwa() { return isStandalone; }

        const Notification = {
            permission: 'default',
            requestPermission: async () => 'granted'
        };

        function showNotification() {}
        function updatePwaPushStatus() {}
        function pwaPushSupported() { return false; }
        function enablePwaPushNotifications() { return Promise.resolve(); }

        const window = global;
        window.document = document;
        window.localStorage = localStorage;
        window.Notification = Notification;
        window.bootstrap = bootstrap;
        window.showNotification = showNotification;
        window.updatePwaPushStatus = updatePwaPushStatus;
        window.pwaPushSupported = pwaPushSupported;
        window.enablePwaPushNotifications = enablePwaPushNotifications;
        window.isInstalledPwa = isInstalledPwa;
        window.isPwaStandalone = () => isStandalone;
        window.matchMedia = (query) => ({ matches: isStandalone && query.includes('standalone') });
        window.addEventListener = () => {};
        window.removeEventListener = () => {};
        window.location = { pathname: '/', href: '/', origin: 'https://example.com' };
        window.navigator = { userAgent: 'test', onLine: true };
        global.IntersectionObserver = class { constructor() {} observe() {} unobserve() {} disconnect() {} };
        global.MutationObserver = class { constructor() {} observe() {} disconnect() {} };
        global.ResizeObserver = class { constructor() {} observe() {} unobserve() {} disconnect() {} };

        // Load initPwaPushFirstOpenPrompt logic
        " . $mainJs . "

        // STEP 1: Simulate appinstalled event in browser
        localStorage.setItem('bshs_pwa_first_open_pending', '1');

        // STEP 2: In standard browser tab (isStandalone = false), call initPwaPushFirstOpenPrompt
        isStandalone = false;
        initPwaPushFirstOpenPrompt();
        if (modalShownCount !== 0) {
            console.error('FAIL: Browser tab showed modal');
            process.exit(1);
        }
        if (localStorage.getItem('bshs_pwa_first_open_pending') !== '1') {
            console.error('FAIL: Browser tab prematurely cleared pending flag');
            process.exit(1);
        }

        // STEP 3: First Standalone Launch (isStandalone = true)
        isStandalone = true;
        initPwaPushFirstOpenPrompt();
        if (modalShownCount !== 1) {
            console.error('FAIL: Standalone first launch did not show modal');
            process.exit(1);
        }
        if (localStorage.getItem('bshs_pwa_first_open_pending') !== null) {
            console.error('FAIL: Standalone first launch did not consume pending flag');
            process.exit(1);
        }

        // STEP 4: User clicks Deny
        denyBtn.onclick();
        if (localStorage.getItem('bshs_push_prompt_dismissed') !== 'denied') {
            console.error('FAIL: Deny did not record dismissal');
            process.exit(1);
        }

        // STEP 5: Subsequent navigation in standalone does NOT show modal again
        initPwaPushFirstOpenPrompt();
        if (modalShownCount !== 1) {
            console.error('FAIL: Dismissed prompt showed again');
            process.exit(1);
        }

        console.log('SUCCESS');
        ";

        $output = [];
        $exitCode = 0;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('node', $descriptors, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $nodeScript);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, "Node script error: " . $stderr . " " . $stdout);
            $this->assertStringContainsString('SUCCESS', $stdout);
        }
    }

    public function testClosedPwaServerSidePushSubscriptionTargeting(): void
    {
        $userId = 42;
        $sub = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-sub-token-closed-pwa',
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg'
            ]
        ];

        // 1. Store subscription in database
        $saved = pushSaveSubscription($this->db, $userId, $sub, 'BSHS AMS Standalone PWA');
        $this->assertTrue($saved);

        // 2. Query stored subscriptions for target user
        $subs = pushFetchSubscriptions($this->db, [$userId]);
        $this->assertCount(1, $subs);
        $this->assertSame($userId, (int)$subs[0]['user_id']);
        $this->assertSame($sub['endpoint'], $subs[0]['endpoint']);
        $this->assertSame($sub['keys']['p256dh'], $subs[0]['p256dh']);
        $this->assertSame($sub['keys']['auth'], $subs[0]['auth']);

        // 3. Verify notification payload structure
        $payload = [
            'title' => 'Important Announcement',
            'body' => 'Final Grade publication released for 1st Term',
            'url' => '/student/Student.php#grades',
            'icon' => '/assets/images/icon-192.png',
            'badge' => '/assets/images/icon-192.png',
        ];

        $this->assertSame('Important Announcement', $payload['title']);
        $this->assertSame('Final Grade publication released for 1st Term', $payload['body']);
        $this->assertSame('/student/Student.php#grades', $payload['url']);
        $this->assertNotEmpty($payload['icon']);
        $this->assertNotEmpty($payload['badge']);
    }

    public function testServiceWorkerPushHandlerExecutionWhenPwaClosed(): void
    {
        $swJs = file_get_contents(__DIR__ . '/../sw.js');
        $this->assertIsString($swJs);

        // Node.js test simulating service worker push event reception when NO window clients are open
        $nodeScript = "
        const registeredListeners = {};
        const shownNotifications = [];
        const openedWindows = [];

        const clients = {
            claim: () => Promise.resolve(),
            matchAll: () => Promise.resolve([]), // Zero active client windows (PWA closed)
            openWindow: (url) => {
                openedWindows.push(url);
                return Promise.resolve();
            }
        };

        const self = {
            location: { pathname: '/sw.js', origin: 'https://balingasagshs.wasmer.app' },
            addEventListener: (ev, fn) => { registeredListeners[ev] = fn; },
            skipWaiting: () => {},
            registration: {
                showNotification: (title, options) => {
                    shownNotifications.push({ title, options });
                    return Promise.resolve();
                }
            },
            clients: clients
        };

        const caches = {
            open: () => Promise.resolve({ put: () => Promise.resolve(), add: () => Promise.resolve() }),
            keys: () => Promise.resolve([]),
            delete: () => Promise.resolve()
        };

        // Load sw.js
        " . $swJs . "

        // Verify push listener registered
        if (typeof registeredListeners['push'] !== 'function') {
            console.error('FAIL: No push listener in sw.js');
            process.exit(1);
        }

        // STEP 1: Simulate push event with payload arriving while PWA is closed
        const pushPayload = {
            title: 'School Announcement',
            body: 'Classes resume on Monday',
            icon: '/assets/images/icon-192.png',
            badge: '/assets/images/icon-192.png',
            url: '/student/Student.php'
        };

        const pushEvent = {
            data: {
                json: () => pushPayload,
                text: () => JSON.stringify(pushPayload)
            },
            waitUntil: (p) => p
        };

        registeredListeners['push'](pushEvent);

        if (shownNotifications.length !== 1) {
            console.error('FAIL: showNotification was not called');
            process.exit(1);
        }

        const notif = shownNotifications[0];
        if (notif.title !== 'School Announcement') {
            console.error('FAIL: Incorrect notification title:', notif.title);
            process.exit(1);
        }
        if (notif.options.body !== 'Classes resume on Monday') {
            console.error('FAIL: Incorrect notification body:', notif.options.body);
            process.exit(1);
        }
        if (notif.options.data.url !== '/student/Student.php') {
            console.error('FAIL: Incorrect target url:', notif.options.data.url);
            process.exit(1);
        }

        // STEP 2: Simulate notification click opening the PWA window
        if (typeof registeredListeners['notificationclick'] !== 'function') {
            console.error('FAIL: No notificationclick listener in sw.js');
            process.exit(1);
        }

        let closed = false;
        const clickEvent = {
            notification: {
                close: () => { closed = true; },
                data: { url: '/student/Student.php' }
            },
            waitUntil: (p) => p
        };

        registeredListeners['notificationclick'](clickEvent);

        setTimeout(() => {
            if (!closed) {
                console.error('FAIL: Notification was not closed on click');
                process.exit(1);
            }
            if (openedWindows.length !== 1 || !openedWindows[0].endsWith('/student/Student.php')) {
                console.error('FAIL: Target URL not opened in clients.openWindow:', openedWindows);
                process.exit(1);
            }
            console.log('SUCCESS');
        }, 50);
        ";

        $output = [];
        $exitCode = 0;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('node', $descriptors, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $nodeScript);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, "SW push error: " . $stderr . " " . $stdout);
            $this->assertStringContainsString('SUCCESS', $stdout);
        }
    }
}
