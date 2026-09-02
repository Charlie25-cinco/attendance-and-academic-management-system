<?php

use PHPUnit\Framework\TestCase;

final class PwaNotificationPromptTest extends TestCase
{
    public function testLoginModalAndButtonsRender(): void
    {
        $loginPage = file_get_contents(__DIR__ . '/../auth/login.php');
        $this->assertIsString($loginPage);

        $this->assertStringContainsString('id="pushPromptModal"', $loginPage);
        $this->assertStringContainsString('id="pushPromptAllowBtn"', $loginPage);
        $this->assertStringContainsString('id="pushPromptDenyBtn"', $loginPage);
        $this->assertStringContainsString('id="pushPromptLaterBtn"', $loginPage);
        $this->assertStringContainsString('APP_PUSH_PUBLIC_KEY', $loginPage);
    }

    public function testMainJsHasInstalledPwaCheckAndDenyHandler(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        $this->assertStringContainsString('function isInstalledPwa()', $js);
        $this->assertStringContainsString('(display-mode: standalone)', $js);
        $this->assertStringContainsString('pushPromptDenyBtn', $js);
        $this->assertStringContainsString('bshs_push_prompt_dismissed', $js);
    }

    public function testNotificationLifecycleAllowDenyLaterAndAuthenticatedFollowup(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        // Node.js test script to test notification lifecycle
        $nodeScript = "
        class MockLocalStorage {
            constructor() { this.store = {}; }
            getItem(key) { return this.store[key] || null; }
            setItem(key, value) { this.store[key] = String(value); }
            removeItem(key) { delete this.store[key]; }
            clear() { this.store = {}; }
        }

        class MockElement {
            constructor(id) {
                this.id = id;
                this.style = {};
                this.checked = false;
                this.disabled = false;
                this.listeners = {};
                this.onclick = null;
                this.children = [];
                this.attributes = {};
                this.innerHTML = '';
            }
            setAttribute(k, v) { this.attributes[k] = v; }
            getAttribute(k) { return this.attributes[k]; }
            appendChild(el) { this.children.push(el); return el; }
            remove() {
                this.removed = true;
            }
            querySelector(sel) { return null; }
            addEventListener(event, fn) {
                if (!this.listeners[event]) this.listeners[event] = [];
                this.listeners[event].push(fn);
            }
            async dispatchEvent(event, data) {
                if (this.onclick && event === 'click') {
                    await this.onclick(data || { preventDefault: () => {} });
                }
                if (this.listeners[event]) {
                    for (const fn of this.listeners[event]) {
                        await fn(data || { preventDefault: () => {} });
                    }
                }
            }
        }

        class MockDocument {
            constructor() {
                this.elements = {};
                this.listeners = {};
                this.body = new MockElement('body');
            }
            getElementById(id) {
                if (id === 'appNotificationHost') {
                    if (!this.elements[id]) this.elements[id] = new MockElement('appNotificationHost');
                }
                return this.elements[id] || null;
            }
            querySelector(sel) { return null; }
            querySelectorAll(sel) { return []; }
            createElement(tag) { return new MockElement(tag); }
            addEventListener(event, fn) {
                if (!this.listeners[event]) this.listeners[event] = [];
                this.listeners[event].push(fn);
            }
            async dispatchEvent(event, data) {
                if (this.listeners[event]) {
                    for (const fn of this.listeners[event]) {
                        await fn(data || { preventDefault: () => {} });
                    }
                }
            }
        }

        async function run() {
            const storage = new MockLocalStorage();
            let notificationsShown = [];
            function showNotification(msg, type) {
                notificationsShown.push({ msg, type });
            }

            let backendSubscriptions = [];
            let isUserAuthenticated = false;

            const fakeSubscription = {
                endpoint: 'https://push.example.com/sub/12345',
                toJSON: () => ({
                    endpoint: 'https://push.example.com/sub/12345',
                    keys: { p256dh: 'BNcR...', auth: 'tBH...' }
                })
            };

            let currentSubscription = null;
            const mockPushManager = {
                getSubscription: async () => currentSubscription,
                subscribe: async (options) => {
                    currentSubscription = fakeSubscription;
                    return fakeSubscription;
                }
            };

            const mockServiceWorkerReg = {
                pushManager: mockPushManager
            };

            let mockPermission = 'default';
            const mockNotification = {
                get permission() { return mockPermission; },
                requestPermission: async () => {
                    mockPermission = 'granted';
                    return 'granted';
                }
            };

            // Test 1: Allow on login page (unauthenticated)
            const doc = new MockDocument();
            doc.elements['pushPromptModal'] = new MockElement('pushPromptModal');
            doc.elements['pushPromptAllowBtn'] = new MockElement('pushPromptAllowBtn');
            doc.elements['pushPromptDenyBtn'] = new MockElement('pushPromptDenyBtn');
            doc.elements['pushPromptLaterBtn'] = new MockElement('pushPromptLaterBtn');
            doc.elements['pushPromptCloseBtn'] = new MockElement('pushPromptCloseBtn');
            doc.elements['pushNotifSwitch'] = new MockElement('pushNotifSwitch');

            let modalHidden = false;
            let modalShown = false;
            const mockBootstrap = {
                Modal: {
                    getOrCreateInstance: (el) => ({
                        show: () => { modalShown = true; },
                        hide: () => { modalHidden = true; }
                    })
                }
            };

            const mockWindow = {
                APP_PUSH_PUBLIC_KEY: 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
                APP_CSRF_TOKEN: '',
                APP_INITIAL_SETTINGS: {},
                localStorage: storage,
                Notification: mockNotification,
                navigator: {
                    serviceWorker: { ready: Promise.resolve(mockServiceWorkerReg) }
                },
                PushManager: mockPushManager,
                matchMedia: () => ({ matches: true }),
                location: { pathname: '/auth/login.php' },
                bootstrap: mockBootstrap,
                document: doc,
                showNotification,
                setTimeout: (fn, ms) => { if (!ms || ms < 1000) fn(); },
                addEventListener: () => {},
                removeEventListener: () => {},
                isPwaStandalone: () => true,
                atob: (str) => Buffer.from(str, 'base64').toString('binary')
            };

            // Fake fetch simulating API
            global.fetch = async (url, options) => {
                if (url.includes('web-push-subscription')) {
                    if (!isUserAuthenticated) {
                        return {
                            ok: false,
                            status: 401,
                            json: async () => ({ ok: false, message: 'Authentication required' })
                        };
                    } else {
                        const body = JSON.parse(options.body);
                        backendSubscriptions.push(body.subscription);
                        return {
                            ok: true,
                            status: 200,
                            json: async () => ({ ok: true, message: 'Push notifications enabled' })
                        };
                    }
                }
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ ok: true })
                };
            };
            global.Headers = class {
                constructor(h) { this.map = Object.assign({}, h); }
                has(k) { return Boolean(this.map[k]); }
                set(k, v) { this.map[k] = v; }
            };
            global.atob = (str) => Buffer.from(str, 'base64').toString('binary');
            global.IntersectionObserver = class { constructor() {} observe() {} unobserve() {} disconnect() {} };
            global.MutationObserver = class { constructor() {} observe() {} disconnect() {} };
            global.ResizeObserver = class { constructor() {} observe() {} unobserve() {} disconnect() {} };

            global.window = mockWindow;
            global.navigator = mockWindow.navigator;
            global.document = doc;
            global.Notification = mockNotification;
            global.localStorage = storage;
            global.bootstrap = mockBootstrap;
            global.showNotification = showNotification;

            // Evaluate main.js in mock environment
            const jsCode = " . json_encode($js) . ";
            const fn = new Function('window', 'document', 'localStorage', 'Notification', 'navigator', 'bootstrap', 'showNotification', 'fetch', 'Headers', 'atob', jsCode);
            fn(mockWindow, doc, storage, mockNotification, mockWindow.navigator, mockBootstrap, showNotification, global.fetch, global.Headers, global.atob);

            // Trigger prompt initialization
            mockWindow.initPwaPushFirstOpenPrompt();

            // Click Allow on login page (unauthenticated)
            await doc.elements['pushPromptAllowBtn'].dispatchEvent('click');

            if (storage.getItem('bshs_push_prompt_dismissed') !== 'granted') {
                console.error('Storage dismissed status should be granted');
                process.exit(10);
            }
            if (!currentSubscription) {
                console.error('Browser PushSubscription should be created upon Allow');
                process.exit(11);
            }
            if (backendSubscriptions.length !== 0) {
                console.error('Backend subscription must not be saved when unauthenticated');
                process.exit(12);
            }
            const notifHost = doc.getElementById('appNotificationHost');
            const hasInfoNotif = notifHost && notifHost.children.some(c => c.innerHTML.includes('activate after login'));
            if (!hasInfoNotif) {
                console.error('User should receive informational notification on unauthenticated Allow', notifHost ? notifHost.children.map(c => c.innerHTML) : 'no host');
                process.exit(13);
            }

            // Test 2: Authenticated follow-up after login on dashboard
            isUserAuthenticated = true;
            mockWindow.APP_CSRF_TOKEN = 'valid_session_token';
            mockWindow.APP_INITIAL_SETTINGS = { push_notifications: 1 };
            mockWindow.location.pathname = '/admin/admin.php';

            await mockWindow.initPwaPushAutoRegistration();

            if (backendSubscriptions.length !== 1) {
                console.error('Backend subscription should be successfully saved upon dashboard load');
                process.exit(14);
            }
            if (backendSubscriptions[0].endpoint !== fakeSubscription.endpoint) {
                console.error('Backend subscription endpoint mismatch');
                process.exit(15);
            }

            // Test 3: Deny button (persistent refusal)
            storage.clear();
            mockPermission = 'default';
            const docDeny = new MockDocument();
            docDeny.elements['pushPromptModal'] = new MockElement('pushPromptModal');
            docDeny.elements['pushPromptAllowBtn'] = new MockElement('pushPromptAllowBtn');
            docDeny.elements['pushPromptDenyBtn'] = new MockElement('pushPromptDenyBtn');
            docDeny.elements['pushPromptLaterBtn'] = new MockElement('pushPromptLaterBtn');
            docDeny.elements['pushPromptCloseBtn'] = new MockElement('pushPromptCloseBtn');

            const mockWindowDeny = Object.assign({}, mockWindow, { document: docDeny, localStorage: storage });
            global.window = mockWindowDeny;
            global.document = docDeny;
            global.localStorage = storage;
            fn(mockWindowDeny, docDeny, storage, mockNotification, mockWindow.navigator, mockBootstrap, showNotification, global.fetch, global.Headers, global.atob);
            mockWindowDeny.initPwaPushFirstOpenPrompt();

            await docDeny.elements['pushPromptDenyBtn'].dispatchEvent('click');
            if (storage.getItem('bshs_push_prompt_dismissed') !== 'denied') {
                console.error('Storage dismissed status should be denied');
                process.exit(20);
            }

            // Test 4: Later button (24-hour deferral)
            storage.clear();
            const docLater = new MockDocument();
            docLater.elements['pushPromptModal'] = new MockElement('pushPromptModal');
            docLater.elements['pushPromptAllowBtn'] = new MockElement('pushPromptAllowBtn');
            docLater.elements['pushPromptDenyBtn'] = new MockElement('pushPromptDenyBtn');
            docLater.elements['pushPromptLaterBtn'] = new MockElement('pushPromptLaterBtn');
            docLater.elements['pushPromptCloseBtn'] = new MockElement('pushPromptCloseBtn');

            const mockWindowLater = Object.assign({}, mockWindow, { document: docLater, localStorage: storage });
            global.window = mockWindowLater;
            global.document = docLater;
            global.localStorage = storage;
            fn(mockWindowLater, docLater, storage, mockNotification, mockWindow.navigator, mockBootstrap, showNotification, global.fetch, global.Headers, global.atob);
            mockWindowLater.initPwaPushFirstOpenPrompt();

            await docLater.elements['pushPromptLaterBtn'].dispatchEvent('click');
            if (storage.getItem('bshs_push_prompt_dismissed') !== 'later') {
                console.error('Storage dismissed status should be later');
                process.exit(30);
            }
            const dismissedAt = Number(storage.getItem('bshs_push_prompt_dismissed_at') || '0');
            if (!dismissedAt || dismissedAt <= 0) {
                console.error('Dismissed timestamp must be saved for later');
                process.exit(31);
            }

            process.exit(0);
        }
        run();
        ";

        $tmpFile = tempnam(sys_get_temp_dir(), 'pwa_prompt_test_') . '.js';
        file_put_contents($tmpFile, $nodeScript);

        exec('node ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $returnCode);
        @unlink($tmpFile);

        $this->assertSame(0, $returnCode, 'Push notification prompt test failed with code ' . $returnCode . ': ' . implode("\n", $output));
    }
}