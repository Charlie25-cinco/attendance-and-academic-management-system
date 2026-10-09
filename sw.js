// BSHS AMS root service worker - PWA cache + push notifications

const CACHE_NAME = "bshs-ams-v41";
const BASE_PATH = (self.location.pathname || "").replace(/\/sw\.js$/, "");
const IDENTITY_CACHE = "bshs-ams-offline-state";
const IDENTITY_URL = new URL(BASE_PATH + "/__offline_identity", self.location.origin).href;
const TEACHER_PAGES = ["/teacher/teacher.php", "/teacher/teacher_Attendance.php", "/teacher/teacher_Classes.php", "/teacher/teacher_Grades.php"];
let identityTask = Promise.resolve();

async function offlineAccount() {
  const cache = await caches.open(IDENTITY_CACHE);
  const response = await cache.match(IDENTITY_URL);
  const state = response ? await response.json() : null;
  return state && state.until > Date.now() ? state : null;
}

function updateOfflineAccount(response, lock) {
  identityTask = identityTask.catch(() => {}).then(async () => {
    const account = !lock && response ? response.headers.get("X-App-Offline-Account") : "none";
    const until = !lock && response ? Number(response.headers.get("X-App-Offline-Until")) * 1000 : 0;
    const previous = await offlineAccount();
    const valid = /^(principal|admin|teacher|student|parent):[1-9]\d*$/.test(account) && until > Date.now();
    if (!valid || !previous || previous.account !== account) {
      const keys = await caches.keys();
      await Promise.all(keys.filter(k => k.startsWith("bshs-ams-") && k.includes("-user-")).map(k => caches.delete(k)));
    }
    const cache = await caches.open(IDENTITY_CACHE);
    if (valid) await cache.put(IDENTITY_URL, new Response(JSON.stringify({ account, until })));
    else await cache.delete(IDENTITY_URL);
  });
  return identityTask;
}

function resolvePath(path) {
  if (!path) return path;
  if (path.indexOf("/") === 0) {
    return BASE_PATH + path;
  }
  return path;
}

const APP_SHELL_URLS = [
  "/assets/manifest.json",
  "/assets/css/main.css",
  "/assets/css/role.css",
  "/assets/css/auth.css",
  "/assets/css/Site.css",
  "/assets/js/main.js",
  "/assets/js/offlineIdentity.js",
  "/assets/js/offlineStorage.js",
  "/assets/js/networkSync.js",
  "/assets/images/bshs-logo.jpg",
  "/assets/images/icon-192.png",
  "/assets/images/icon-512.png",
  "/assets/images/icon-maskable-512.png",
  "/assets/vendor/bootstrap/bootstrap.min.css",
  "/assets/vendor/bootstrap/bootstrap.bundle.min.js",
  "/assets/vendor/bootstrap-icons/bootstrap-icons.css",
  "/assets/vendor/html5-qrcode/html5-qrcode.min.js",
];

self.addEventListener("install", function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.allSettled(
        APP_SHELL_URLS.map(function (url) {
          var targetUrl = resolvePath(url);
          return cache.add(targetUrl).catch(function (error) {
            console.warn("[SW] Pre-cache failed for " + targetUrl + ":", error);
          });
        }),
      );
    }),
  );
});

self.addEventListener("message", function (event) {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
  if (event.data && event.data.type === "LOCK_OFFLINE") {
    event.waitUntil(updateOfflineAccount(null, true));
  }
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches
      .keys()
      .then(function (keys) {
        return Promise.all(
          keys
            .filter(function (key) {
              return key.startsWith("bshs-ams-") && key !== IDENTITY_CACHE && key !== CACHE_NAME && !key.startsWith(CACHE_NAME + "-user-");
            })
            .map(function (key) {
              return caches.delete(key);
            }),
        );
      })
      .then(function () {
        return self.clients.claim();
      }),
  );
});

function shouldCacheResponse(response) {
  return (
    response &&
    response.status === 200 &&
    !response.redirected &&
    response.type !== "opaque" &&
    !/no-store|private/i.test(response.headers.get("Cache-Control") || "")
  );
}

function cacheResponse(request, response) {
  if (!shouldCacheResponse(response)) return Promise.resolve();
  var clone = response.clone();
  return caches
    .open(CACHE_NAME)
    .then(function (cache) {
      return cache.put(request, clone);
    })
    .catch(function (error) {
      console.warn("[SW] Cache put failed:", error);
    });
}

self.addEventListener("fetch", function (event) {
  if (event.request.method !== "GET") {
    return;
  }

  var url = new URL(event.request.url);
  var isSameOrigin = url.origin === self.location.origin;

  if (!isSameOrigin) {
    return;
  }

  if (url.pathname === resolvePath("/auth/logout.php")) {
    event.respondWith((async () => {
      await updateOfflineAccount(null, true);
      return fetch(event.request);
    })());
    return;
  }

  // Bypass API and action routes from static caching
  var isDynamicRoute =
    url.pathname.indexOf(resolvePath("/api/")) === 0 ||
    /(?:action|_export|seed|scripts|logout)\.php/i.test(
      url.pathname,
    );
  if (isDynamicRoute) {
    return;
  }

  var isAsset = url.pathname.indexOf(resolvePath("/assets/")) === 0;
  var isStaticAsset =
    /\.(png|jpg|jpeg|svg|webp|gif|css|js|woff2?|ttf|eot|ico|json)$/i.test(
      url.pathname,
    );

  if (isAsset || isStaticAsset) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      try {
        const response = await fetch(event.request);
        if (response.status >= 500) {
          const cached = await cache.match(event.request);
          if (cached) return cached;
        }
        await cacheResponse(event.request, response);
        return response;
      } catch (error) {
        return await cache.match(event.request) || Response.error();
      }
    })());
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith((async () => {
      const teacherPage = TEACHER_PAGES.some(path => resolvePath(path) === url.pathname);
      try {
        const response = await fetch(event.request);
        // Only a successful server navigation can establish/switch the offline account.
        if (response.status < 500) await updateOfflineAccount(response, false);
        const state = await offlineAccount();
        if (teacherPage && state && state.account.startsWith("teacher:") && response.status === 200 &&
            !response.redirected && response.headers.get("X-App-Offline-Account") === state.account) {
          const cache = await caches.open(CACHE_NAME + "-user-" + state.account);
          await cache.put(event.request, response.clone());
        }
        return response;
      } catch (error) {
        await identityTask;
        const state = await offlineAccount();
        if (state && state.account.startsWith("teacher:")) {
          if (teacherPage) {
            const cache = await caches.open(CACHE_NAME + "-user-" + state.account);
            // Never substitute a different class/date query's HTML.
            const cached = await cache.match(event.request);
            if (cached) return cached;
          }
          return offlineFallbackResponse(true);
        }
        return offlineFallbackResponse(false);
      }
    })());
    return;
  }
});

function offlineFallbackResponse(unlocked) {
  var html =
    '<!DOCTYPE html><html><head><meta charset="utf-8"><title>BSHS AMS - Offline Workspaces</title><meta name="viewport" content="width=device-width,initial-scale=1">' +
    "<style>" +
    'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;color:#1e293b;text-align:center;padding:1.5rem;box-sizing:border-box;}' +
    ".card{background:#fff;border-radius:1rem;padding:2.25rem 2rem;box-shadow:0 10px 25px rgba(0,0,0,0.06);max-width:420px;width:100%;border:1px solid #e2e8f0;}" +
    ".icon-wrap{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:#fef9c3;color:#ca8a04;border-radius:50%;margin-bottom:1.25rem;}" +
    ".icon-wrap svg{width:32px;height:32px;fill:currentColor;}" +
    "h2{margin:0 0 0.5rem;font-size:1.35rem;font-weight:700;color:#0f172a;}" +
    "p{margin:0 0 1.5rem;color:#64748b;font-size:0.9rem;line-height:1.5;}" +
    ".actions{display:flex;flex-direction:column;gap:0.6rem;}" +
    ".btn{display:block;width:100%;padding:0.75rem 1.25rem;text-decoration:none;border-radius:0.5rem;font-weight:600;font-size:0.95rem;box-sizing:border-box;transition:all 0.15s ease;text-align:center;}" +
    ".btn-primary{background:#1f4f82;color:#fff;border:none;}" +
    ".btn-primary:hover{background:#183e66;}" +
    ".btn-secondary{background:#f1f5f9;color:#1e293b;border:1px solid #cbd5e1;}" +
    ".btn-secondary:hover{background:#e2e8f0;}" +
    ".btn-link{background:transparent;color:#64748b;font-size:0.875rem;padding:0.4rem;}" +
    ".btn-link:hover{color:#0f172a;text-decoration:underline;}" +
    "</style></head><body>" +
    '<div class="card">' +
    '<div class="icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M10.706 3.294A12.545 12.545 0 0 0 8 3C5.259 3 2.723 3.882.663 5.379a.485.485 0 0 0-.048.736.518.518 0 0 0 .668.05A11.448 11.448 0 0 1 8 4c.63 0 1.249.05 1.852.148l.854-.854zM8 6c-1.905 0-3.68.56-5.166 1.526a.48.48 0 0 0-.063.745.525.525 0 0 0 .652.065 8.448 8.448 0 0 1 4.577-1.336L8 6zm0 3c-.886 0-1.72.195-2.473.541a.48.48 0 0 0-.173.693.52.52 0 0 0 .66.195A4.475 4.475 0 0 1 8 10c.264 0 .52.03.766.088l.84-.84A5.46 5.46 0 0 0 8 9zm0 3a1.5 1.5 0 0 0-1.45 1.116.5.5 0 1 0 .964.268A.5.5 0 0 1 8 13.5a.5.5 0 0 1 .5.5.5.5 0 1 0 1 0A1.5 1.5 0 0 0 8 12z"/><path d="M.146.146a.5.5 0 0 1 .708 0l15 15a.5.5 0 0 1-.708.708l-15-15a.5.5 0 0 1 0-.708z"/></svg></div>' +
    "<h2>Device is Offline</h2>" +
    "<p>You are currently disconnected, but your offline workspaces are available on this device.</p>" +
    '<div class="actions">' +
    '<a href="' +
    resolvePath("/teacher/teacher_Attendance.php") +
    '" class="btn btn-primary">Take Offline Attendance</a>' +
    '<a href="' +
    resolvePath("/teacher/teacher_Classes.php") +
    '" class="btn btn-secondary">Offline Classes & Grades</a>' +
    '<a href="' +
    resolvePath("/teacher/teacher.php") +
    '" class="btn btn-secondary">Teacher Dashboard</a>' +
    '<a href="' +
    resolvePath("/auth/login.php") +
    '" class="btn btn-link">Return to Login / Reconnect</a>' +
    "</div>" +
    "</div></body></html>";

  if (!unlocked) {
    html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in required</title><body><main><h1>Offline workspace locked</h1><p>Reconnect and sign in to your account to access pending offline work.</p><a href="' + resolvePath('/auth/login.php') + '">Return to sign in</a></main></body></html>';
  }
  return new Response(html, {
    headers: { "Content-Type": "text/html; charset=utf-8" },
  });
}

self.addEventListener("push", function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = {
      title: "BSHS Notification",
      body: event.data ? event.data.text() : "",
    };
  }

  var title = data.title || "BSHS Notification";
  var options = {
    body: data.body || "",
    icon: data.icon || "/assets/images/icon-192.png",
    badge: data.badge || "/assets/images/icon-192.png",
    vibrate: [200, 100, 200],
    data: {
      url: data.url || (data.data && data.data.url) || "/auth/login.php",
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", function (event) {
  event.notification.close();
  var targetUrl =
    event.notification && event.notification.data && event.notification.data.url
      ? event.notification.data.url
      : "/auth/login.php";
  var resolvedUrl = new URL(targetUrl, self.location.origin).href;

  event.waitUntil(
    clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then(function (clientList) {
        for (var i = 0; i < clientList.length; i++) {
          var client = clientList[i];
          if (client.url === resolvedUrl && "focus" in client) {
            return client.focus();
          }
        }
        if (clients.openWindow) {
          return clients.openWindow(resolvedUrl);
        }
      }),
  );
});

// -------------------------------------------------------------
// Background Sync API Handler (Best-Effort when app is closed)
// -------------------------------------------------------------
self.addEventListener("sync", function (event) {
  if (event.tag === "bshs-offline-sync") {
    event.waitUntil(handleBackgroundSync());
  }
});

async function openOfflineDb(owner) {
  if (!("indexedDB" in self)) return null;
  return new Promise(function (resolve) {
    var req = indexedDB.open("bshs_ams_offline_db_" + owner.replace(":", "_"), 2);
    req.onupgradeneeded = function () { req.transaction.abort(); resolve(null); };
    req.onsuccess = function (e) { resolve(e.target.result); };
    req.onerror = function () { resolve(null); };
  });
}

function waitForOfflineTransaction(tx) {
  return new Promise(function (resolve) {
    tx.oncomplete = function () { resolve(true); };
    tx.onerror = tx.onabort = function () { resolve(false); };
  });
}

async function handleBackgroundSync() {
  await identityTask;
  var state = await offlineAccount();
  if (!state || !state.account.startsWith("teacher:")) return;
  var db = await openOfflineDb(state.account);
  if (!db) return;

  var queue = await new Promise(function (resolve) {
    try {
      if (!db.objectStoreNames.contains("sync_queue")) { resolve([]); return; }
      var tx = db.transaction(["sync_queue"], "readonly");
      var req = tx.objectStore("sync_queue").getAll();
      req.onsuccess = function () { resolve(req.result || []); };
      req.onerror = function () { resolve([]); };
    } catch (e) { resolve([]); }
  });

  queue = queue.filter(item => item.owner === state.account && item.status !== "failed");
  if (!queue || queue.length === 0) { db.close(); return; }

  // Check if any window client is currently open
  var windowClients = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
  var isAppClosed = windowClients.length === 0;

  // Probe server authentication with credentials
  var authCheck = await (async function () {
    try {
      var targetUrl = resolvePath("/teacher/teacher_Action.php?action=offline_bootstrap");
      var res = await fetch(targetUrl, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store"
      });
      if (!res.ok) return { authenticated: false };
      var data = await res.json();
      if (data && data.success && data.teacher) {
        return { authenticated: true, owner: "teacher:" + Number(data.teacher.id), csrfToken: data.csrf_token || "" };
      }
      return { authenticated: false };
    } catch (e) {
      return { authenticated: false, networkError: true };
    }
  })();

  if (!authCheck.authenticated || authCheck.owner !== state.account) {
    // Unauthenticated or network error: halt, preserve queue, do not notify
    db.close();
    return;
  }

  var syncedAttendanceRecords = 0;
  var syncedAttendanceSheets = 0;
  var syncedActivitySets = 0;
  var syncedActivityScores = 0;
  var failedCount = 0;
  var retryableFailureCount = 0;

  for (var i = 0; i < queue.length; i++) {
    const active = await offlineAccount();
    if (!active || active.account !== state.account) break;
    var item = queue[i];
    var payload = item.payload;
    var url = item.url;
    var opType = item.operation;
    var opId = item.operation_id || item.id;
    if (!url || !payload) continue;

    try {
      var targetUrl = resolvePath("/teacher/" + url.replace(/^\/?teacher\//, ""));
      var headers = {
        "Content-Type": "application/json",
        "X-Offline-Owner": state.account,
        Accept: "application/json"
      };
      if (authCheck.csrfToken) {
        headers["X-CSRF-Token"] = authCheck.csrfToken;
      }

      var response = await fetch(targetUrl, {
        method: "POST",
        headers: headers,
        credentials: "same-origin",
        body: JSON.stringify(payload)
      });

      var result = null;
      try { result = await response.json(); } catch (e) {}
      if (response.ok && result && result.success) {
          if (opType === "attendance.upsert" || opType === "submit_attendance") {
            syncedAttendanceSheets++;
            var recCount = 1;
            if (payload.records) {
              if (Array.isArray(payload.records)) recCount = payload.records.length;
              else if (typeof payload.records === "object") recCount = Object.keys(payload.records).length;
            }
            syncedAttendanceRecords += recCount;
          } else {
            syncedActivitySets++;
            var scoreCount = 1;
            if (payload.scores) {
              if (Array.isArray(payload.scores)) scoreCount = payload.scores.length;
              else if (typeof payload.scores === "object") scoreCount = Object.keys(payload.scores).length;
            }
            syncedActivityScores += scoreCount;
          }

          // Delete from sync_queue and update local record in IndexedDB
          try {
            var rawId = String(opId || "").replace(/^op_/, "");
            const wtx = db.transaction(["sync_queue", "activity_records", "attendance_records"], "readwrite");
            wtx.objectStore("sync_queue").delete(item.id);
            wtx.objectStore("sync_queue").delete("op_" + rawId);
            wtx.objectStore("sync_queue").delete(rawId);

            if (opType === "attendance.upsert" || opType === "submit_attendance") {
              const attStore = wtx.objectStore("attendance_records");
              const attReq = attStore.get(rawId);
              attReq.onsuccess = function () {
                if (attReq.result) {
                  const rec = attReq.result;
                  rec.sync_status = "synced";
                  rec.synced_at = new Date().toISOString();
                  attStore.put(rec);
                }
              };
            } else {
              const actStore = wtx.objectStore("activity_records");
              const actReq = actStore.get(rawId);
              actReq.onsuccess = function () {
                if (actReq.result) {
                  const rec = actReq.result;
                  rec.sync_status = "synced";
                  rec.synced_at = new Date().toISOString();
                  if (result.grade_item_id && parseInt(result.grade_item_id, 10) > 0) {
                    rec.server_id = parseInt(result.grade_item_id, 10);
                    rec.id = parseInt(result.grade_item_id, 10);
                  }
                  actStore.put(rec);
                }
              };
            }
            await waitForOfflineTransaction(wtx);
          } catch (e) {}
      } else {
        failedCount++;
        var message = result && result.message ? result.message : "The server could not synchronize this offline item.";
        var authFailure = response.status === 401 || response.status === 403 || message === "Unauthorized access" || message === "Invalid CSRF token";
        var attempts = Number(item.attempts || 0) + 1;
        var permanent = !authFailure && ((result && result.retryable === false) || (response.status >= 400 && response.status < 500));
        try {
          var failureTx = db.transaction(["sync_queue"], "readwrite");
          item.attempts = attempts;
          item.status = permanent || attempts >= 5 ? "failed" : "pending";
          item.last_error = message;
          item.last_attempt_at = new Date().toISOString();
          item.retryable = !permanent;
          failureTx.objectStore("sync_queue").put(item);
          await waitForOfflineTransaction(failureTx);
        } catch (e) {}
        if (!authFailure && !permanent && attempts < 5) retryableFailureCount++;
        if (authFailure) break;
      }
    } catch (err) {
      failedCount++;
      try {
        var retryTx = db.transaction(["sync_queue"], "readwrite");
        item.attempts = Number(item.attempts || 0) + 1;
        item.status = item.attempts >= 5 ? "failed" : "pending";
        item.last_error = err && err.message ? err.message : "Network connection failed";
        item.last_attempt_at = new Date().toISOString();
        item.retryable = true;
        retryTx.objectStore("sync_queue").put(item);
        await waitForOfflineTransaction(retryTx);
        if (item.attempts < 5) retryableFailureCount++;
      } catch (e) {}
      console.warn("[SW Background Sync] Item sync error:", item.id, err);
    }
  }

  var totalSynced = syncedAttendanceRecords + syncedActivitySets;
  db.close();
  if (totalSynced > 0 && failedCount === 0 && isAppClosed) {
    var parts = [];
    if (syncedAttendanceRecords > 0) {
      parts.push(syncedAttendanceRecords + " attendance record" + (syncedAttendanceRecords === 1 ? "" : "s"));
    }
    if (syncedActivitySets > 0) {
      parts.push(syncedActivitySets + " activity set" + (syncedActivitySets === 1 ? "" : "s"));
    }
    var summaryText = "Offline data synchronized — " + parts.join(" and ") + " synchronized successfully.";

    self.registration.showNotification("BSHS AMS - Data Synchronized", {
      body: summaryText,
      icon: resolvePath("/assets/images/icon-192.png"),
      badge: resolvePath("/assets/images/icon-192.png"),
      tag: "bshs-sync-completed",
      data: { url: resolvePath("/teacher/teacher.php") }
    });
  }
  if (retryableFailureCount > 0) {
    throw new Error("Offline synchronization has retryable failures");
  }
}
