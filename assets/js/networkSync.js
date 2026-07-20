(function () {
    'use strict';

    var QUEUE_KEY = 'bshs_offline_queue';
    var isOnline = navigator.onLine;

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
            // storage full
        }
    }

    function addToQueue(action) {
        var queue = getQueue();
        queue.push({
            id: Date.now() + '_' + Math.random().toString(36).slice(2, 8),
            action: action,
            addedAt: new Date().toISOString()
        });
        saveQueue(queue);
        if (typeof showNotification === 'function') {
            showNotification('You are offline. Action queued for sync when online.', 'warning');
        }
    }

    function removeFromQueue(id) {
        var queue = getQueue().filter(function (item) { return item.id !== id; });
        saveQueue(queue);
    }

    function processQueue() {
        var queue = getQueue();
        if (queue.length === 0) return;

        var remaining = queue.filter(function (item) {
            var action = item.action;

            if (action.type === 'submit_attendance') {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', action.url, false);
                xhr.setRequestHeader('Content-Type', 'application/json');
                if (action.csrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', action.csrfToken);
                }
                try {
                    xhr.send(JSON.stringify(action.payload));
                    var result = JSON.parse(xhr.responseText);
                    if (result && result.success) {
                        if (typeof showNotification === 'function') {
                            showNotification('Offline attendance synced successfully!', 'success');
                        }
                        return false; // remove from queue
                    }
                } catch (e) {
                    // still offline or error, keep in queue
                }
                return true; // keep in queue
            }

            return false; // unknown action type, remove
        });

        saveQueue(remaining);
    }

    function handleOnline() {
        isOnline = true;
        if (typeof showNotification === 'function') {
            showNotification('You are back online. Syncing pending data...', 'info');
        }
        processQueue();
    }

    function handleOffline() {
        isOnline = false;
        if (typeof showNotification === 'function') {
            showNotification('You are offline. Attendance submissions will be queued.', 'warning');
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
            processQueue();
        }
    };

    // Process queue on load if online
    if (isOnline) {
        processQueue();
    }
})();

