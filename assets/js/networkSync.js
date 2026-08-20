(function (global) {
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
            showNotification('You are offline. Submission queued safely for automatic sync.', 'warning');
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
        var syncedAttendance = 0;
        var syncedActivities = 0;

        for (var i = 0; i < queue.length; i++) {
            var item = queue[i];
            var action = item.action;

            if (!action || !action.url) {
                continue;
            }

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
                } else if (global.APP_CSRF_TOKEN) {
                    headers['X-CSRF-Token'] = global.APP_CSRF_TOKEN;
                }

                var response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(action.payload)
                });

                if (response.ok) {
                    var result = await response.json();
                    if (result && result.success) {
                        if (action.type === 'submit_attendance') {
                            syncedAttendance++;
                        } else {
                            syncedActivities++;
                        }
                        if (global.bshsOfflineStorage && typeof global.bshsOfflineStorage.removeSyncItem === 'function') {
                            await global.bshsOfflineStorage.removeSyncItem(item.id);
                        }
                        continue;
                    }
                }

                item.attempts = (item.attempts || 0) + 1;
                remaining.push(item);
            } catch (err) {
                item.attempts = (item.attempts || 0) + 1;
                remaining.push(item);
            }
        }

        saveQueue(remaining);
        isProcessing = false;

        var totalSynced = syncedAttendance + syncedActivities;
        if (totalSynced > 0) {
            var parts = [];
            if (syncedAttendance > 0) parts.push(syncedAttendance + ' attendance sheet(s)');
            if (syncedActivities > 0) parts.push(syncedActivities + ' activity score set(s)');
            var msg = 'Offline data (' + parts.join(', ') + ') synchronized to school database!';
            if (typeof showNotification === 'function') {
                showNotification(msg, 'success');
            } else if (typeof showToast === 'function') {
                showToast(msg, 'success');
            }
        }
    }

    function handleOnline() {
        isOnline = true;
        var count = getQueue().length;
        if (count > 0) {
            if (typeof showNotification === 'function') {
                showNotification('Back online! Syncing ' + count + ' pending offline submission(s)...', 'info');
            } else if (typeof showToast === 'function') {
                showToast('Back online! Syncing ' + count + ' pending offline submission(s)...', 'info');
            }
        }
        processQueue();
    }

    function handleOffline() {
        isOnline = false;
        if (typeof showNotification === 'function') {
            showNotification('You are offline. Submissions will be queued safely on this device.', 'warning');
        }
    }

    global.addEventListener('online', handleOnline);
    global.addEventListener('offline', handleOffline);

    global.bshsOffline = {
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
        queueActivity: function (classId, title, component, totalScore, date, scores, csrfToken) {
            addToQueue({
                type: 'save_offline_activity',
                url: 'teacher_Action.php?action=save_offline_activity',
                csrfToken: csrfToken,
                payload: {
                    class_id: parseInt(classId, 10),
                    title: title,
                    component: component,
                    total_score: parseFloat(totalScore),
                    activity_date: date,
                    scores: scores
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

    global.BSHS_NetworkSync = {
        processQueue: processQueue,
        getQueue: getQueue
    };

    // Auto-sync pending items on load if online
    if (isOnline && getQueue().length > 0) {
        setTimeout(processQueue, 1000);
    }
})(typeof window !== 'undefined' ? window : this);
