/**
 * BSHS AMS - Production Network Synchronization Engine
 * Listens for connectivity restoration, verifies authenticated server session
 * and CSRF token via pre-flight bootstrap probe, and synchronizes pending offline
 * attendance and activity records to MySQL with strict gating and idempotency.
 */
(function (global) {
  "use strict";

  var isProcessing = false;

  function formatSyncTime(value) {
    if (!value) return "Not synchronized yet";
    var date = new Date(value);
    return Number.isNaN(date.getTime()) ? "Not synchronized yet" : "Last sync " + date.toLocaleString("en-PH");
  }

  function ensureSyncPanel() {
    if (typeof document === "undefined" || document.getElementById("offlineSyncStatus")) return;
    var panel = document.createElement("aside");
    panel.id = "offlineSyncStatus";
    panel.className = "offline-sync-status";
    panel.setAttribute("aria-live", "polite");
    panel.innerHTML = '<div class="offline-sync-status__icon"><i class="bi bi-cloud-check"></i></div>' +
      '<div class="offline-sync-status__copy"><strong id="offlineSyncTitle">Offline data ready</strong><span id="offlineSyncDetail">Checking synchronization status...</span></div>' +
      '<div class="offline-sync-status__actions"><button type="button" class="btn btn-sm btn-outline-primary" id="offlineSyncRetryBtn">Retry Sync</button>' +
      '<button type="button" class="btn btn-sm btn-link text-danger" id="offlineSyncClearBtn">Clear Local Data</button></div>';
    document.body.appendChild(panel);
    document.getElementById("offlineSyncRetryBtn")?.addEventListener("click", async function () {
      var storage = global.bshsOfflineStorage;
      if (!storage) return;
      this.disabled = true;
      await storage.retryFailedItems();
      await processQueue();
      this.disabled = false;
      refreshSyncPanel();
    });
    document.getElementById("offlineSyncClearBtn")?.addEventListener("click", async function () {
      var confirmed = typeof global.showAppConfirm === "function"
        ? await global.showAppConfirm({
            title: "Clear offline data?",
            message: "Pending attendance and grade activity records on this device will be permanently removed.",
            confirmText: "Clear local data",
            cancelText: "Keep data",
            tone: "danger",
            icon: "bi-trash",
          })
        : global.confirm("Clear all offline data for this teacher on this device?");
      if (!confirmed || !global.bshsOfflineStorage) return;
      await global.bshsOfflineStorage.clearLocalData();
      refreshSyncPanel();
    });
  }

  async function refreshSyncPanel() {
    if (typeof document === "undefined") return;
    ensureSyncPanel();
    var storage = global.bshsOfflineStorage;
    var panel = document.getElementById("offlineSyncStatus");
    if (!storage || !panel) return;
    var status = await storage.getSyncStatus();
    var online = navigator.onLine;
    var title = document.getElementById("offlineSyncTitle");
    var detail = document.getElementById("offlineSyncDetail");
    var retry = document.getElementById("offlineSyncRetryBtn");
    panel.classList.toggle("is-offline", !online);
    panel.classList.toggle("has-failures", status.failed > 0);
    panel.classList.toggle("has-pending", status.pending > 0);
    if (title) {
      title.textContent = status.failed > 0
        ? status.failed + " offline item" + (status.failed === 1 ? " needs" : "s need") + " attention"
        : status.pending > 0
          ? status.pending + " item" + (status.pending === 1 ? "" : "s") + " waiting to sync"
          : online ? "Offline data synchronized" : "Working offline";
    }
    if (detail) {
      detail.textContent = status.failed > 0 && status.lastError
        ? status.lastError
        : online ? formatSyncTime(status.lastSyncedAt) : "Reconnect to synchronize saved work.";
      detail.title = detail.textContent;
    }
    if (retry) retry.hidden = status.total === 0 || !online;
  }

  async function verifyAndRestoreAuth() {
    try {
      var targetUrl =
        typeof withCsrfUrl === "function"
          ? withCsrfUrl("teacher_Action.php?action=offline_bootstrap")
          : "teacher_Action.php?action=offline_bootstrap";

      var res = await fetch(targetUrl, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store",
      });

      if (res.status === 401 || res.status === 403 || !res.ok) {
        return { authenticated: false };
      }

      var data = await res.json();
      if (data && data.success && data.teacher) {
        if (data.csrf_token) {
          global.APP_CSRF_TOKEN = data.csrf_token;
        }
        return { authenticated: true, owner: "teacher:" + Number(data.teacher.id), csrfToken: data.csrf_token || "" };
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
    if (!storage || !storage.isUnlocked()) return;

    var queue = (await storage.getSyncQueue()).filter(function (item) { return item.status !== "failed"; });
    if (!queue || queue.length === 0) return;

    // Pre-flight check: ensure valid server authentication & CSRF context
    var authCheck = await verifyAndRestoreAuth();
    if (!authCheck.authenticated || authCheck.owner !== storage.owner) {
      if (!authCheck.networkError) {
        var authWarning =
          "You have pending offline records, but your server session is not signed in. Please sign in to synchronize.";
        if (typeof showNotification === "function") {
          showNotification(authWarning, "warning");
        } else if (typeof showToast === "function") {
          showToast(authWarning, "warning");
        }
      }
      return;
    }

    isProcessing = true;
    refreshSyncPanel();
    var syncedAttendance = 0;
    var syncedActivities = 0;

    for (var i = 0; i < queue.length; i++) {
      var item = queue[i];
      if (!storage.isUnlocked() || item.owner !== authCheck.owner) break;
      var payload = item.payload || item.action?.payload;
      var url = item.url || item.action?.url;
      var opType = item.operation || item.action?.type;
      var opId = item.operation_id || item.id;

      if (!url || !payload) continue;

      try {
        if (typeof storage.updateSyncItemState === "function") {
          await storage.updateSyncItemState(opId, { status: "syncing", last_attempt_at: new Date().toISOString() });
        }
        var targetUrl = url;
        // The preflight token is authoritative; page-level csrfToken may be a stale const.
        var currentToken = authCheck.csrfToken || global.APP_CSRF_TOKEN || "";

        var headers = {
          "Content-Type": "application/json",
          "X-Offline-Owner": storage.owner,
          Accept: "application/json",
        };
        if (currentToken) {
          headers["X-CSRF-Token"] = currentToken;
        }

        var response = await fetch(targetUrl, {
          method: "POST",
          headers: headers,
          credentials: "same-origin",
          body: JSON.stringify(payload),
        });

        var result = null;
        try { result = await response.json(); } catch (e) {}
        if (response.ok && result && result.success) {
            if (
              opType === "attendance.upsert" ||
              opType === "submit_attendance"
            ) {
              syncedAttendance++;
            } else {
              syncedActivities++;
            }

            // Mark record synced in IndexedDB & remove from queue
            if (typeof storage.markRecordSynced === "function") {
              await storage.markRecordSynced(opId, {
                grade_item_id: result.grade_item_id,
              });
            } else if (typeof storage.removeSyncItem === "function") {
              await storage.removeSyncItem(item.id);
            }
        } else {
          var message = result && result.message ? result.message : "The server could not synchronize this offline item.";
          var authFailure = response.status === 401 || response.status === 403 ||
            message === "Unauthorized access" || message === "Invalid CSRF token";
          var permanent = !authFailure && ((result && result.retryable === false) || (response.status >= 400 && response.status < 500));
          if (typeof storage.markSyncItemFailed === "function") {
            await storage.markSyncItemFailed(opId, message, permanent);
          }
          if (authFailure) {
            var sessionMsg = "Authentication required to complete offline synchronization. Please refresh or sign in.";
            if (typeof showNotification === "function") showNotification(sessionMsg, "danger");
            break;
          }
          if (permanent && typeof showNotification === "function") {
            showNotification("An offline item needs review: " + message, "warning");
          }
        }
      } catch (err) {
        if (typeof storage.markSyncItemFailed === "function") {
          await storage.markSyncItemFailed(opId, err && err.message ? err.message : "Network connection failed", false);
        }
        console.warn("[Sync] Sync attempt failed for item:", item.id, err);
      }
    }

    isProcessing = false;
    refreshSyncPanel();

    var totalSynced = syncedAttendance + syncedActivities;
    if (totalSynced > 0) {
      var parts = [];
      if (syncedAttendance > 0) {
        parts.push(
          syncedAttendance +
            " attendance record" +
            (syncedAttendance === 1 ? "" : "s"),
        );
      }
      if (syncedActivities > 0) {
        parts.push(
          syncedActivities +
            " activity set" +
            (syncedActivities === 1 ? "" : "s"),
        );
      }
      var msg =
        "Offline data synchronized — " +
        parts.join(" and ") +
        " synchronized successfully.";

      if (typeof showNotification === "function") {
        showNotification(msg, "success");
      } else if (typeof showToast === "function") {
        showToast(msg, "success");
      }

      // Refresh fresh rosters and classes from server
      if (typeof storage.bootstrapOnline === "function") {
        storage.bootstrapOnline().catch(function () {});
      }

      // Emit sync completed event so active UI pages can refresh immediately
      if (typeof global.dispatchEvent === "function") {
        try {
          global.dispatchEvent(
            new CustomEvent("bshs:sync-completed", {
              detail: {
                syncedAttendance: syncedAttendance,
                syncedActivities: syncedActivities,
                totalSynced: totalSynced,
              },
            }),
          );
        } catch (e) {}
      }
    }
  }

  var wasOffline = typeof navigator !== "undefined" && !navigator.onLine;
  var onlineDebounceTimer = null;

  function handleOnline() {
    if (onlineDebounceTimer) {
      clearTimeout(onlineDebounceTimer);
    }

    onlineDebounceTimer = setTimeout(async function () {
      if (!wasOffline) {
        return;
      }
      if (isProcessing) {
        return;
      }

      try {
        var storage = global.bshsOfflineStorage;
        var queue =
          storage && typeof storage.getSyncQueue === "function"
            ? await storage.getSyncQueue()
            : [];

        if (queue && queue.length > 0) {
          wasOffline = false;
          if (typeof showNotification === "function") {
            showNotification(
              "Internet connection restored. Checking offline records...",
              "info",
            );
          } else if (typeof showToast === "function") {
            showToast(
              "Internet connection restored. Checking offline records...",
              "info",
            );
          }
          processQueue();
        } else {
          wasOffline = false;
        }
      } catch (err) {
        wasOffline = false;
      }
    }, 300);
  }

  function handleOffline() {
    wasOffline = true;
    if (typeof showNotification === "function") {
      showNotification(
        "You are in offline mode. All records will be stored safely on this device.",
        "warning",
      );
    }
  }

  global.addEventListener("online", handleOnline);
  global.addEventListener("offline", handleOffline);
  global.addEventListener("bshs:sync-status-changed", refreshSyncPanel);

  global.BSHS_NetworkSync = {
    processQueue: processQueue,
    verifyAndRestoreAuth: verifyAndRestoreAuth,
  };

  global.bshsOffline = {
    isOnline: function () {
      return navigator.onLine;
    },
    processNow: function () {
      return processQueue();
    },
  };

  // Trigger sync on startup if online
  if (navigator.onLine) {
    setTimeout(processQueue, 1200);
  }
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", refreshSyncPanel);
    else refreshSyncPanel();
  }
})(typeof window !== "undefined" ? window : this);
