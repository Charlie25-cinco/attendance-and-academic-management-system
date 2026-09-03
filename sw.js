// BSHS AMS root service worker - PWA cache + push notifications

const CACHE_NAME = "bshs-ams-v37";
const BASE_PATH = (self.location.pathname || "").replace(/\/sw\.js$/, "");

function resolvePath(path) {
  if (!path) return path;
  if (path.indexOf("/") === 0) {
    return BASE_PATH + path;
  }
  return path;
}

const APP_SHELL_URLS = [
  "/auth/login.php",
  "/teacher/teacher.php",
  "/teacher/teacher_Attendance.php",
  "/teacher/teacher_Classes.php",
  "/teacher/teacher_Grades.php",
  "/assets/manifest.json",
  "/assets/css/main.css",
  "/assets/css/role.css",
  "/assets/css/auth.css",
  "/assets/css/Site.css",
  "/assets/js/main.js",
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
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches
      .keys()
      .then(function (keys) {
        return Promise.all(
          keys
            .filter(function (key) {
              return key !== CACHE_NAME;
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
    response.type !== "opaque"
  );
}

function cacheResponse(request, response) {
  if (!shouldCacheResponse(response)) return;
  var clone = response.clone();
  caches
    .open(CACHE_NAME)
    .then(function (cache) {
      cache.put(request, clone);
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

  // Bypass API and action routes from static caching
  var isDynamicRoute =
    /\b(api|action|_Action|_Export|seed|scripts|logout)\.php/i.test(
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
    event.respondWith(
      caches
        .match(event.request)
        .then(function (cached) {
          var fetchAndCache = fetch(event.request)
            .then(function (networkResponse) {
              cacheResponse(event.request, networkResponse);
              return networkResponse;
            })
            .catch(function () {
              return cached;
            });

          return cached || fetchAndCache;
        })
        .catch(function () {
          return fetch(event.request);
        }),
    );
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request)
        .then(function (networkResponse) {
          if (shouldCacheResponse(networkResponse)) {
            var clone = networkResponse.clone();
            caches.open(CACHE_NAME).then(function (cache) {
              cache.put(event.request, clone);
              cache.put(url.pathname, clone.clone());
              cache.put(resolvePath(url.pathname), clone.clone());
            });
          }
          return networkResponse;
        })
        .catch(function () {
          return caches
            .match(event.request, { ignoreSearch: true })
            .then(function (cachedPage) {
              if (cachedPage) {
                return cachedPage;
              }
              var targetPath = resolvePath(url.pathname);
              return caches
                .match(targetPath, { ignoreSearch: true })
                .then(function (matchedPath) {
                  if (matchedPath) {
                    return matchedPath;
                  }
                  return caches
                    .match(url.pathname, { ignoreSearch: true })
                    .then(function (matchedPathname) {
                      if (matchedPathname) {
                        return matchedPathname;
                      }

                      // If navigating to any teacher page offline, fallback to cached teacher shells
                      if (
                        url.pathname.indexOf("/teacher/") !== -1 ||
                        url.pathname === "/" ||
                        url.pathname === "/index.php"
                      ) {
                        return caches
                          .match(
                            resolvePath("/teacher/teacher_Attendance.php"),
                            { ignoreSearch: true },
                          )
                          .then(function (attPage) {
                            if (attPage) return attPage;
                            return caches
                              .match(resolvePath("/teacher/teacher.php"), {
                                ignoreSearch: true,
                              })
                              .then(function (dashPage) {
                                if (dashPage) return dashPage;
                                return offlineFallbackResponse();
                              });
                          });
                      }

                      return offlineFallbackResponse();
                    });
                });
            });
        }),
    );
    return;
  }
});

function offlineFallbackResponse() {
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

async function openOfflineDb() {
  if (!("indexedDB" in self)) return null;
  return new Promise(function (resolve) {
    var req = indexedDB.open("bshs_ams_offline_db", 2);
    req.onsuccess = function (e) { resolve(e.target.result); };
    req.onerror = function () { resolve(null); };
  });
}

async function handleBackgroundSync() {
  var db = await openOfflineDb();
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

  if (!queue || queue.length === 0) return;

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
        return { authenticated: true, csrfToken: data.csrf_token || "" };
      }
      return { authenticated: false };
    } catch (e) {
      return { authenticated: false, networkError: true };
    }
  })();

  if (!authCheck.authenticated) {
    // Unauthenticated or network error: halt, preserve queue, do not notify
    return;
  }

  var syncedAttendanceRecords = 0;
  var syncedAttendanceSheets = 0;
  var syncedActivitySets = 0;
  var syncedActivityScores = 0;
  var failedCount = 0;

  for (var i = 0; i < queue.length; i++) {
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

      if (response.ok) {
        var result = await response.json();
        if (result && result.success) {
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
            var wtx = db.transaction(["sync_queue", "activity_records", "attendance_records"], "readwrite");
            wtx.objectStore("sync_queue").delete(item.id);
            wtx.objectStore("sync_queue").delete("op_" + rawId);
            wtx.objectStore("sync_queue").delete(rawId);

            if (opType === "attendance.upsert" || opType === "submit_attendance") {
              var attStore = wtx.objectStore("attendance_records");
              var attReq = attStore.get(rawId);
              attReq.onsuccess = function () {
                if (attReq.result) {
                  var rec = attReq.result;
                  rec.sync_status = "synced";
                  rec.synced_at = new Date().toISOString();
                  attStore.put(rec);
                }
              };
            } else {
              var actStore = wtx.objectStore("activity_records");
              var actReq = actStore.get(rawId);
              actReq.onsuccess = function () {
                if (actReq.result) {
                  var rec = actReq.result;
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
          } catch (e) {}
        } else {
          failedCount++;
          if (result && (result.message === "Unauthorized access" || result.message === "Invalid CSRF token")) {
            // Mid-queue auth failure: halt and preserve remaining
            break;
          }
        }
      } else {
        failedCount++;
      }
    } catch (err) {
      failedCount++;
      console.warn("[SW Background Sync] Item sync error:", item.id, err);
    }
  }

  var totalSynced = syncedAttendanceRecords + syncedActivitySets;
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
}
