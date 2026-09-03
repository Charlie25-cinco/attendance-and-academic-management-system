/**
 * BSHS AMS - Production Network Synchronization Engine
 * Listens for connectivity restoration, verifies authenticated server session
 * and CSRF token via pre-flight bootstrap probe, and synchronizes pending offline
 * attendance and activity records to MySQL with strict gating and idempotency.
 */
(function (global) {
    'use strict';

    var isProcessing = false;

    async function verifyAndRestoreAuth() {
        try {
            var targetUrl = typeof withCsrfUrl === 'function'
                ? withCsrfUrl('teacher_Action.php?action=offline_bootstrap')
                : 'teacher_Action.php?action=offline_bootstrap';

            var res = await fetch(targetUrl, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (res.status === 401 || res.status === 403 || !res.ok) {
                return { authenticated: false };
            }

            var data = await res.json();
            if (data && data.success && data.teacher) {
                if (data.csrf_token) {
                    global.APP_CSRF_TOKEN = data.csrf_token;
                    if (typeof csrfToken !== 'undefined') {
                        csrfToken = data.csrf_token;
                    }
                }
                return { authenticated: true, csrfToken: data.csrf_token || '' };
            }

            return { authenticated: false };
        } catch (e) {
            return { authenticated: false, networkError: true };
        }
    }

    async function processQueue() {
        if (isProcessing) return;
        if (!navigator.onLine) return;

        var storage = global.bshsOfflineStorage;
        if (!storage) return;

        var queue = await storage.getSyncQueue();
        if (!queue || queue.length === 0) return;

        // Pre-flight check: ensure valid server authentication & CSRF context
        var authCheck = await verifyAndRestoreAuth();
        if (!authCheck.authenticated) {
            if (!authCheck.networkError) {
                var authWarning = 'You have pending offline records, but your server session is not signed in. Please sign in to synchronize.';
                if (typeof showNotification === 'function') {
                    showNotification(authWarning, 'warning');
                } else if (typeof showToast === 'function') {
                    showToast(authWarning, 'warning');
                }
            }
            return;
        }

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

                var currentToken = (typeof csrfToken !== 'undefined' && csrfToken)
                    ? csrfToken
                    : (global.APP_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value || authCheck.csrfToken || '');

                var headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                };
                if (currentToken) {
                    headers['X-CSRF-Token'] = currentToken;
                }

                var response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: headers,
                    credentials: 'same-origin',
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
                            await storage.markRecordSynced(opId, { grade_item_id: result.grade_item_id });
                        } else if (typeof storage.removeSyncItem === 'function') {
                            await storage.removeSyncItem(item.id);
                        }
                    } else if (result && (result.message === 'Unauthorized access' || result.message === 'Invalid CSRF token')) {
                        // Mid-queue auth failure: halt and preserve remaining queue items
                        var sessionMsg = 'Authentication required to complete offline synchronization. Please refresh or sign in.';
                        if (typeof showNotification === 'function') {
                            showNotification(sessionMsg, 'danger');
                        }
                        break;
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
            if (syncedAttendance > 0) {
                parts.push(syncedAttendance + ' attendance record' + (syncedAttendance === 1 ? '' : 's'));
            }
            if (syncedActivities > 0) {
                parts.push(syncedActivities + ' activity set' + (syncedActivities === 1 ? '' : 's'));
            }
            var msg = 'Offline data synchronized — ' + parts.join(' and ') + ' synchronized successfully.';

            if (typeof showNotification === 'function') {
                showNotification(msg, 'success');
            } else if (typeof showToast === 'function') {
                showToast(msg, 'success');
            }

            // Refresh fresh rosters and classes from server
            if (typeof storage.bootstrapOnline === 'function') {
                storage.bootstrapOnline().catch(function () {});
            }
        }
    }

    function handleOnline() {
        if (typeof showNotification === 'function') {
            showNotification('Internet connection restored. Checking offline records...', 'info');
        } else if (typeof showToast === 'function') {
            showToast('Internet connection restored. Checking offline records...', 'info');
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
        processQueue: processQueue,
        verifyAndRestoreAuth: verifyAndRestoreAuth
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