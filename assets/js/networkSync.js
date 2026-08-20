/**
 * BSHS AMS - Production Network Synchronization Engine
 * Listens for connectivity restoration and submits pending offline
 * attendance and activity records to MySQL with idempotency protection.
 */
(function (global) {
    'use strict';

    var isProcessing = false;

    async function processQueue() {
        if (isProcessing) return;
        if (!navigator.onLine) return;

        var storage = global.bshsOfflineStorage;
        if (!storage) return;

        var queue = await storage.getSyncQueue();
        if (!queue || queue.length === 0) return;

        isProcessing = true;
        var syncedAttendance = 0;
        var syncedActivities = 0;

        for (var i = 0; i < queue.length; i++) {
            var item = queue[i];
            var payload = item.payload || item.action?.payload;
            var url = item.url || item.action?.url;
            var opType = item.operation || item.action?.type;
            var opId = item.operation_id || item.id;

            if (!url || !payload) continue;

            try {
                var targetUrl = url;
                if (typeof withCsrfUrl === 'function') {
                    targetUrl = withCsrfUrl(targetUrl);
                }

                var headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                };
                if (item.csrfToken || item.action?.csrfToken) {
                    headers['X-CSRF-Token'] = item.csrfToken || item.action?.csrfToken;
                } else if (global.APP_CSRF_TOKEN) {
                    headers['X-CSRF-Token'] = global.APP_CSRF_TOKEN;
                }

                var response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(payload)
                });

                if (response.ok) {
                    var result = await response.json();
                    if (result && result.success) {
                        if (opType === 'attendance.upsert' || opType === 'submit_attendance') {
                            syncedAttendance++;
                        } else {
                            syncedActivities++;
                        }

                        // Mark record synced in IndexedDB & remove from queue
                        if (typeof storage.markRecordSynced === 'function') {
                            await storage.markRecordSynced(opId);
                        } else if (typeof storage.removeSyncItem === 'function') {
                            await storage.removeSyncItem(item.id);
                        }
                    }
                }
            } catch (err) {
                // Keep item in queue for next sync cycle
                console.warn('[Sync] Sync attempt failed for item:', item.id, err);
            }
        }

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

            // Refresh fresh rosters from server
            if (typeof storage.bootstrapOnline === 'function') {
                storage.bootstrapOnline().catch(function () {});
            }
        }
    }

    function handleOnline() {
        if (typeof showNotification === 'function') {
            showNotification('Internet connection restored. Synchronizing offline records...', 'info');
        } else if (typeof showToast === 'function') {
            showToast('Internet connection restored. Synchronizing offline records...', 'info');
        }
        setTimeout(processQueue, 500);
    }

    function handleOffline() {
        if (typeof showNotification === 'function') {
            showNotification('You are in offline mode. All records will be stored safely on this device.', 'warning');
        }
    }

    global.addEventListener('online', handleOnline);
    global.addEventListener('offline', handleOffline);

    global.BSHS_NetworkSync = {
        processQueue: processQueue
    };

    global.bshsOffline = {
        isOnline: function () { return navigator.onLine; },
        processNow: function () { return processQueue(); }
    };

    // Trigger sync on startup if online
    if (navigator.onLine) {
        setTimeout(processQueue, 1200);
    }
})(typeof window !== 'undefined' ? window : this);
