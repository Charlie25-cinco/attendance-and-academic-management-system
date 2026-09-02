<?php

use PHPUnit\Framework\TestCase;

final class RealtimeNotificationPollingTest extends TestCase
{
    public function testNotificationPollerJavaScriptStructure(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        // Polling interval within 15-30s
        $this->assertStringContainsString('LiveNotificationPoller', $js);
        $this->assertStringContainsString('intervalMs: 20000', $js);
        $this->assertStringContainsString('minIntervalMs: 15000', $js);
        $this->assertStringContainsString('maxIntervalMs: 60000', $js);

        // Visibility lifecycle & offline handling
        $this->assertStringContainsString('document.addEventListener("visibilitychange"', $js);
        $this->assertStringContainsString('window.addEventListener("online"', $js);
        $this->assertStringContainsString('window.addEventListener("offline"', $js);

        // In-flight locking & AbortController
        $this->assertStringContainsString('this.isPolling', $js);
        $this->assertStringContainsString('this.abortController =', $js);
        $this->assertStringContainsString('this.abortController.abort()', $js);

        // Duplicate prevention & seen IDs tracking
        $this->assertStringContainsString('seenNotificationIds', $js);
        $this->assertStringContainsString('ams:notificationReceived', $js);
        $this->assertStringContainsString('badge-pulse', $js);

        // Function initialization
        $this->assertStringContainsString('initLiveNotificationPoller()', $js);
        $this->assertStringContainsString('renderLiveNotifications(items, unreadCount', $js);
        $this->assertStringContainsString('setHeaderNotificationCount(unreadCount', $js);
    }

    public function testRenderLiveNotificationsMarkupGeneration(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        // Verify target container
        $this->assertStringContainsString('.header-notification-scroll-body, #headerNotificationList', $js);

        // Verify empty state markup
        $this->assertStringContainsString('No new notifications', $js);

        // Verify notification row markup & action attributes
        $this->assertStringContainsString('data-notification-row="${id}"', $js);
        $this->assertStringContainsString('${isRead ? "opacity-75" : ""}', $js);
        $this->assertStringContainsString('header-notification-item', $js);
        $this->assertStringContainsString('data-notification-id="${id}"', $js);
        $this->assertStringContainsString('header-notification-icon bg-${color}', $js);
        $this->assertStringContainsString('notification-title', $js);
        $this->assertStringContainsString('notification-subtitle', $js);
        $this->assertStringContainsString('header-notification-time', $js);
        $this->assertStringContainsString('data-event-at="${eventAt}"', $js);
        $this->assertStringContainsString('delete-notification-btn', $js);

        // Verify re-binding of click actions
        $this->assertStringContainsString('initHeaderNotificationActions();', $js);
    }

    public function testNotificationCssAnimationDefinition(): void
    {
        $css = file_get_contents(__DIR__ . '/../assets/css/main.css');
        $this->assertIsString($css);

        $this->assertStringContainsString('@keyframes badgePulse', $css);
        $this->assertStringContainsString('.notification-badge.badge-pulse', $css);
    }

    public function testNotificationApiContract(): void
    {
        $api = file_get_contents(__DIR__ . '/../api/routes/05-notifications.php');
        $this->assertIsString($api);

        $this->assertStringContainsString("if (\$route === 'notifications' && \$method === 'GET')", $api);
        $this->assertStringContainsString("'unread_count' => \$unreadCount", $api);
        $this->assertStringContainsString("'notifications' => \$savedItems", $api);
        $this->assertStringContainsString("'items' => \$savedItems", $api);
    }

    public function testHeaderActionBindingDeduplicationAndSafety(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        $this->assertStringContainsString('item.dataset.bound === "1"', $js);
        $this->assertStringContainsString('button.dataset.bound === "1"', $js);
        $this->assertStringContainsString('readAll.dataset.bound !== "1"', $js);
        $this->assertStringContainsString('deleteAll.dataset.bound !== "1"', $js);
        $this->assertStringContainsString('eventsBound: false', $js);
        $this->assertStringContainsString('if (this.eventsBound) return;', $js);
        $this->assertStringContainsString('window.refreshNotifications = function', $js);
        $this->assertStringContainsString('err.status === 401', $js);
    }

    public function testPushPromptModalRenderedInAuthenticatedModals(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        $this->assertStringContainsString('id="pushPromptModal"', $modals);
        $this->assertStringContainsString('id="pushPromptAllowBtn"', $modals);
        $this->assertStringContainsString('id="pushPromptDenyBtn"', $modals);
        $this->assertStringContainsString('id="pushPromptLaterBtn"', $modals);
        $this->assertStringContainsString('id="pushPromptCloseBtn"', $modals);

        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertIsString($header);
        $this->assertStringContainsString("include __DIR__ . '/modals.php'", $header);
        $this->assertStringContainsString('id="headerRefreshBtn"', $header);
    }

    public function testLiveDatabaseNotificationQueryFreshness(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            source_key TEXT NULL,
            title TEXT NOT NULL,
            subtitle TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT 'bi-bell',
            color TEXT NOT NULL DEFAULT 'primary',
            link TEXT NOT NULL DEFAULT '#',
            event_at TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $now = date('Y-m-d H:i:s');
        $insert = $pdo->prepare("INSERT INTO user_notifications (user_id, source_key, title, subtitle, icon, color, link, event_at, is_read)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([10, 'grade_1', 'Quarterly Grades Released', 'Term 1 grades are ready', 'bi-file-earmark', 'success', '/grades.php', $now, 0]);
        $insert->execute([10, 'attend_1', 'Attendance Recorded', 'Marked Present for Period 1', 'bi-calendar-check', 'primary', '/attendance.php', $now, 0]);
        $insert->execute([10, 'old_1', 'Old Advisory', 'Previous advisory note', 'bi-bell', 'secondary', '/announcements.php', $now, 1]);

        $stmt = $pdo->prepare("SELECT id, source_key, title, subtitle, icon, color, link, event_at, is_read
                               FROM user_notifications
                               WHERE user_id = ?
                               ORDER BY is_read ASC, event_at DESC, id DESC
                               LIMIT 50");
        $stmt->execute([10]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows);
        $unread = 0;
        foreach ($rows as $row) {
            if ((int)$row['is_read'] === 0) {
                $unread++;
            }
        }
        $this->assertSame(2, $unread);
        $this->assertSame('Attendance Recorded', $rows[0]['title']);
        $this->assertSame('Quarterly Grades Released', $rows[1]['title']);
    }

    public function testNotificationPollerNodeRuntimeExecution(): void
    {
        $mainJsPath = realpath(__DIR__ . '/../assets/js/main.js');
        $this->assertFileExists($mainJsPath);

        $nodeScript = <<<'NODE'
        const fs = require('fs');

        const elements = {};

        class MockElement {
            constructor(id = '', tag = 'div') {
                this._id = '';
                this.tagName = tag.toUpperCase();
                this.dataset = {};
                this.listeners = {};
                this.onclick = null;
                this.disabled = false;
                this.children = [];
                this.innerHTML = '';
                this.textContent = '';
                this.className = '';
                this.attributes = {};
                this.classList = {
                    classes: new Set(),
                    add: (c) => this.classList.classes.add(c),
                    remove: (c) => this.classList.classes.delete(c),
                    contains: (c) => this.classList.classes.has(c)
                };
                if (id) this.id = id;
            }
            get id() { return this._id; }
            set id(val) {
                this._id = val;
                if (val) elements[val] = this;
            }
            setAttribute(k, v) { this.attributes[k] = v; }
            getAttribute(k) { return this.attributes[k] || (k === 'data-notification-row' ? this.dataset.notificationRow : null); }
            appendChild(el) { this.children.push(el); return el; }
            remove() {
                this.removed = true;
                if (this.id && elements[this.id] === this) {
                    delete elements[this.id];
                }
            }
            querySelector(sel) {
                if (sel.includes('.header-notification-scroll-body') || sel.includes('#headerNotificationList')) {
                    return elements['headerNotificationList'];
                }
                return null;
            }
            querySelectorAll(sel) {
                if (sel.includes('.header-notification-item')) return elements['items'] || [];
                if (sel.includes('.delete-notification-btn')) return elements['deleteBtns'] || [];
                if (sel.includes('[data-notification-row]')) return elements['rows'] || [];
                return [];
            }
            closest(sel) { return this; }
            addEventListener(ev, fn) {
                if (!this.listeners[ev]) this.listeners[ev] = [];
                this.listeners[ev].push(fn);
            }
            dispatchEvent(ev) {
                if (this.listeners[ev.type]) {
                    this.listeners[ev.type].forEach(fn => fn(ev));
                }
            }
        }

        const bellBtn = new MockElement('headerBellBtn', 'button');
        bellBtn.setAttribute('aria-label', 'View notifications');
        const scrollList = new MockElement('headerNotificationList', 'div');
        const readAllBtn = new MockElement('markAllNotificationsRead', 'a');
        const deleteAllBtn = new MockElement('deleteAllNotifications', 'a');
        const refreshBtn = new MockElement('headerRefreshBtn', 'button');

        elements['headerBellBtn'] = bellBtn;
        elements['headerNotificationList'] = scrollList;
        elements['markAllNotificationsRead'] = readAllBtn;
        elements['deleteAllNotifications'] = deleteAllBtn;
        elements['headerRefreshBtn'] = refreshBtn;
        elements['items'] = [];
        elements['deleteBtns'] = [];
        elements['rows'] = [];

        const document = {
            getElementById: (id) => elements[id] || null,
            querySelector: (sel) => {
                if (sel.includes('aria-label="View notifications"')) return bellBtn;
                if (sel.includes('.header-notification-scroll-body') || sel.includes('#headerNotificationList')) return scrollList;
                return null;
            },
            querySelectorAll: (sel) => {
                if (sel.includes('[data-notification-row]')) return elements.rows;
                if (sel.includes('.header-notification-item')) return elements.items;
                if (sel.includes('.delete-notification-btn')) return elements.deleteBtns;
                return [];
            },
            createElement: (tag) => new MockElement('', tag),
            body: new MockElement('body', 'body'),
            addEventListener: () => {},
            hidden: false
        };

        let apiItems = [];
        let apiUnreadCount = 0;
        let actionCalls = [];
        let poller401Received = false;

        const mockFetch = function(url, init = {}) {
            const urlStr = String(url || '');
            console.log('[DEBUG mockFetch]', urlStr);
            if (urlStr.includes('route=notifications')) {
                if (poller401Received) {
                    return Promise.resolve({
                        ok: false,
                        status: 401,
                        json: () => Promise.resolve({ ok: false, message: 'Authentication required' })
                    });
                }
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({
                        ok: true,
                        notifications: apiItems,
                        items: apiItems,
                        count: apiItems.length,
                        unread_count: apiUnreadCount
                    })
                });
            }
            if (urlStr.includes('route=notification-action')) {
                actionCalls.push(JSON.parse(init.body || '{}'));
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ ok: true, unread_count: 0 })
                });
            }
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ ok: true })
            });
        };

        class MockHeaders {
            constructor(init = {}) {
                this.map = new Map();
                if (init && typeof init === 'object') {
                    for (const [k, v] of Object.entries(init)) {
                        this.map.set(k.toLowerCase(), v);
                    }
                }
            }
            has(k) { return this.map.has(k.toLowerCase()); }
            get(k) { return this.map.get(k.toLowerCase()) || null; }
            set(k, v) { this.map.set(k.toLowerCase(), v); }
        }

        const window = global;
        window.Headers = MockHeaders;
        window.document = document;
        window.navigator = { onLine: true };
        window.location = { pathname: '/', href: '/', origin: 'https://example.com' };
        window.fetch = mockFetch;
        window.showNotification = () => {};
        window.CustomEvent = class CustomEvent { constructor(t, d) { this.type = t; this.detail = d; } };
        window.dispatchEvent = () => {};
        window.setTimeout = (fn) => { return 1; };
        window.clearTimeout = () => {};
        window.setInterval = (fn) => { return 2; };
        window.clearInterval = () => {};
        window.addEventListener = () => {};
        window.removeEventListener = () => {};
        window.matchMedia = () => ({ matches: false });

        global.Headers = MockHeaders;
        global.document = document;
        global.window = window;
        global.fetch = mockFetch;
        global.showNotification = () => {};
        global.CustomEvent = window.CustomEvent;
        global.dispatchEvent = () => {};
        global.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
        global.MutationObserver = class { observe() {} disconnect() {} };
        global.ResizeObserver = class { observe() {} disconnect() {} };

        const mainJsCode = fs.readFileSync(process.argv[2], 'utf8');
        eval(mainJsCode + '; global.LiveNotificationPoller = LiveNotificationPoller;');

        // 1. Initial poller setup
        LiveNotificationPoller.init();
        if (LiveNotificationPoller.eventsBound !== true) {
            console.error('FAIL: eventsBound was not set to true');
            process.exit(1);
        }

        // 2. Simulate inserting new notification and polling
        apiItems = [{
            id: 101,
            title: 'Attendance Recorded',
            subtitle: 'Present in Science',
            icon: 'bi-calendar-check',
            color: 'success',
            link: '/attendance.php',
            event_at: '2026-09-03 10:00:00',
            time: 'Just now',
            is_read: 0
        }];
        apiUnreadCount = 1;

        LiveNotificationPoller.poll().then(() => {
            const badge = elements['headerNotificationBadge'];
            if (!badge || badge.textContent !== '1') {
                console.error('FAIL: Badge was not updated in place with unread count 1');
                process.exit(1);
            }
            if (!scrollList.innerHTML.includes('Attendance Recorded')) {
                console.error('FAIL: Notification scroll body did not render new item');
                process.exit(1);
            }

            // 3. Test listener deduplication on multiple poll cycles
            LiveNotificationPoller.poll().then(() => {
                const readAll = elements['markAllNotificationsRead'];
                if (!readAll.listeners['click'] || readAll.listeners['click'].length !== 1) {
                    console.error('FAIL: readAll has duplicate click listeners: ' + (readAll.listeners['click'] ? readAll.listeners['click'].length : 0));
                    process.exit(1);
                }

                // 4. Test 401 error stops polling
                poller401Received = true;
                LiveNotificationPoller.poll().then(() => {
                    if (LiveNotificationPoller.timerId !== null) {
                        console.error('FAIL: 401 error did not stop polling timer');
                        process.exit(1);
                    }
                    console.log('SUCCESS: All in-place notification poller tests passed');
                    process.exit(0);
                });
            });
        }).catch(err => {
            console.error('ERROR in test:', err);
            process.exit(1);
        });
NODE;

        $nodeBinary = 'node';
        $cmd = escapeshellarg($nodeBinary) . ' - ' . escapeshellarg($mainJsPath);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $nodeScript);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, "Node script error: " . $stderr . " " . $stdout);
        $this->assertStringContainsString('SUCCESS: All in-place notification poller tests passed', $stdout);
    }
}

