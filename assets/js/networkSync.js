(function () {
    'use strict';

    var QUEUE_KEY = 'bshs_offline_queue';
    var isOnline = navigator.onLine;
    var isProcessing = false;

    function getQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

    function saveQueue(queue) {
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        } catch (e) {
            // storage quota exceeded or disabled
        }
    }

    function addToQueue(action) {
        var queue = getQueue();
        queue.push({
            id: Date.now() + '_' + Math.random().toString(36).slice(2, 8),
            action: action,
            addedAt: new Date().toISOString(),
            attempts: 0
        });
        saveQueue(queue);
        if (typeof showNotification === 'function') {
            showNotification('You are offline. Attendance submission queued for automatic sync.', 'warning');
        }
    }

    function removeFromQueue(id) {
        var queue = getQueue().filter(function (item) { return item.id !== id; });
        saveQueue(queue);
    }

    async function processQueue() {
        if (isProcessing) return;
        var queue = getQueue();
        if (queue.length === 0) return;

        if (!navigator.onLine) {
            return;
        }

        isProcessing = true;
        var remaining = [];
        var syncedCount = 0;

        for (var i = 0; i < queue.length; i++) {
            var item = queue[i];
            var action = item.action;

            if (action && action.type === 'submit_attendance') {
                try {
                    var targetUrl = action.url;
                    if (typeof withCsrfUrl === 'function') {
                        targetUrl = withCsrfUrl(targetUrl);
                    }

                    var headers = {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    };
                    if (action.csrfToken) {
                        headers['X-CSRF-Token'] = action.csrfToken;
                    }

                    var response = await fetch(targetUrl, {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify(action.payload)
                    });

                    if (response.ok) {
                        var result = await response.json();
                        if (result && result.success) {
                            syncedCount++;
                            continue; // Successfully synced, do not add to remaining
                        }
                    }

                    item.attempts = (item.attempts || 0) + 1;
                    remaining.push(item);
                } catch (err) {
                    // Network still down or request failed; keep item in queue for next retry
                    item.attempts = (item.attempts || 0) + 1;
                    remaining.push(item);
                }
            }
        }

        saveQueue(remaining);
        isProcessing = false;

        if (syncedCount > 0 && typeof showNotification === 'function') {
            showNotification('Offline attendance (' + syncedCount + ') synced successfully!', 'success');
        }
    }

    function handleOnline() {
        isOnline = true;
        if (typeof showNotification === 'function') {
            var count = getQueue().length;
            if (count > 0) {
                showNotification('Back online! Syncing ' + count + ' pending attendance submission(s)...', 'info');
            }
        }
        processQueue();
    }

    function handleOffline() {
        isOnline = false;
        if (typeof showNotification === 'function') {
            showNotification('You are offline. Attendance submissions will be queued safely on this device.', 'warning');
        }
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    window.bshsOffline = {
        isOnline: function () { return navigator.onLine; },
        queueAttendance: function (classId, date, records, csrfToken) {
            addToQueue({
                type: 'submit_attendance',
                url: 'teacher_Action.php?action=submit_attendance',
                csrfToken: csrfToken,
                payload: {
                    class_id: parseInt(classId, 10),
                    date: date,
                    mode: 'subject',
                    records: records
                }
            });
        },
        getQueueCount: function () {
            return getQueue().length;
        },
        processNow: function () {
            return processQueue();
        }
    };

    // Auto-sync pending items on load if online
    if (isOnline && getQueue().length > 0) {
        setTimeout(processQueue, 1000);
    }
})();
