// BSHS AMS root service worker - PWA cache + push notifications

const CACHE_NAME = 'bshs-ams-v29';
const BASE_PATH = (self.location.pathname || '').replace(/\/sw\.js$/, '');

function resolvePath(path) {
  if (!path) return path;
  if (path.indexOf('/') === 0) {
    return BASE_PATH + path;
  }
  return path;
}

const APP_SHELL_URLS = [
  '/auth/login.php',
  '/assets/manifest.json',
  '/assets/css/main.css',
  '/assets/css/role.css',
  '/assets/css/auth.css',
  '/assets/css/Site.css',
  '/assets/js/main.js',
  '/assets/js/offlineStorage.js',
  '/assets/js/networkSync.js',
  '/assets/images/bshs-logo.jpg',
  '/assets/images/icon-192.png',
  '/assets/images/icon-512.png',
  '/assets/images/icon-maskable-512.png',
  '/assets/vendor/bootstrap/bootstrap.min.css',
  '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.css',
  '/assets/vendor/html5-qrcode/html5-qrcode.min.js'
];

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.allSettled(
        APP_SHELL_URLS.map(function (url) {
          var targetUrl = resolvePath(url);
          return cache.add(targetUrl).catch(function (error) {
            console.warn('[SW] Pre-cache failed for ' + targetUrl + ':', error);
          });
        })
      );
    })
  );
});

self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (key) { return key !== CACHE_NAME; })
          .map(function (key) { return caches.delete(key); })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function shouldCacheResponse(response) {
  return response && response.status === 200 && !response.redirected && response.type !== 'opaque';
}

function cacheResponse(request, response) {
  if (!shouldCacheResponse(response)) return;
  var clone = response.clone();
  caches.open(CACHE_NAME).then(function (cache) {
    cache.put(request, clone);
  }).catch(function (error) {
    console.warn('[SW] Cache put failed:', error);
  });
}

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') {
    return;
  }

  var url = new URL(event.request.url);
  var isSameOrigin = url.origin === self.location.origin;

  if (!isSameOrigin) {
    return;
  }

  // Bypass API and action routes from static caching
  var isDynamicRoute = /\b(api|action|_Action|_Export|seed|scripts|logout)\.php/i.test(url.pathname);
  if (isDynamicRoute) {
    return;
  }

  var isAsset = url.pathname.indexOf(resolvePath('/assets/')) === 0;
  var isStaticAsset = /\.(png|jpg|jpeg|svg|webp|gif|css|js|woff2?|ttf|eot|ico|json)$/i.test(url.pathname);
  var needsFreshAsset = /\.(css|js)$/i.test(url.pathname);

  if (needsFreshAsset) {
    event.respondWith(
      fetch(event.request).then(function (networkResponse) {
        cacheResponse(event.request, networkResponse);
        return networkResponse;
      }).catch(function () {
        return caches.match(event.request);
      })
    );
    return;
  }

  if (isAsset || isStaticAsset) {
    event.respondWith(
      caches.match(event.request).then(function (cached) {
        var fetchAndCache = fetch(event.request).then(function (networkResponse) {
          cacheResponse(event.request, networkResponse);
          return networkResponse;
        }).catch(function () {
          return cached;
        });

        return cached || fetchAndCache;
      }).catch(function () {
        return caches.match(event.request);
      })
    );
    return;
  }

  if (event.request.mode === 'navigate') {
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
          return caches.match(event.request, { ignoreSearch: true }).then(function (cachedPage) {
            if (cachedPage) {
              return cachedPage;
            }
            var targetPath = resolvePath(url.pathname);
            return caches.match(targetPath, { ignoreSearch: true }).then(function (matchedPath) {
              if (matchedPath) {
                return matchedPath;
              }
              return caches.match(url.pathname, { ignoreSearch: true }).then(function (matchedPathname) {
                if (matchedPathname) {
                  return matchedPathname;
                }
                // If uncached navigation while offline, return clean offline notice page
                return new Response(
                  '<!DOCTYPE html><html><head><meta charset="utf-8"><title>BSHS AMS - Connection Required</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;color:#1e293b;text-align:center;padding:1.5rem;box-sizing:border-box;}.card{background:#fff;border-radius:1rem;padding:2.25rem 2rem;box-shadow:0 10px 25px rgba(0,0,0,0.05);max-width:380px;width:100%;border:1px solid #e2e8f0;}.icon-wrap{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:#fef9c3;color:#ca8a04;border-radius:50%;margin-bottom:1.25rem;}.icon-wrap svg{width:32px;height:32px;fill:currentColor;}h2{margin:0 0 0.5rem;font-size:1.35rem;font-weight:700;color:#0f172a;}p{margin:0 0 1.5rem;color:#64748b;font-size:0.9rem;line-height:1.5;}.btn{display:block;width:100%;padding:0.75rem 1.25rem;background:#1f4f82;color:#fff;text-decoration:none;border-radius:0.5rem;font-weight:600;font-size:0.95rem;box-sizing:border-box;transition:background 0.15s ease;}.btn:hover{background:#183e66;}</style></head><body><div class="card"><div class="icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M10.706 3.294A12.6 12.6 0 0 0 8 3C5.259 3 2.723 3.882.663 5.379a.485.485 0 0 0-.048.736.518.518 0 0 0 .668.05A11.45 11.45 0 0 1 8 4c.63 0 1.249.05 1.852.148zM8 6c-1.905 0-3.68.56-5.166 1.526a.48.48 0 0 0-.063.745.525.525 0 0 0 .652.065A9.44 9.44 0 0 1 8 7a9.4 9.4 0 0 1 2.97.476l-.775.775A8.5 8.5 0 0 0 8 8c-1.29 0-2.486.37-3.486 1.012a.482.482 0 0 0-.083.763.53.53 0 0 0 .633.08A7.4 7.4 0 0 1 8 9c.404 0 .798.04 1.176.115l-.83.83A6.3 6.3 0 0 0 8 10c-.714 0-1.378.18-1.954.498a.483.483 0 0 0-.106.786.54.54 0 0 0 .61.103c.42-.194.88-.307 1.45-.307.28 0 .548.03.805.088l-.946.946A3.8 3.8 0 0 0 8 12c-.22 0-.428.03-.623.086l-.75.75A.5.5 0 0 0 6.98 13.7c.307.193.66.3 1.02.3a2.46 2.46 0 0 0 1.74-.72l.006-.006 4.908-4.908a.5.5 0 0 0 0-.707l-.707-.708L14.7 6.207a.5.5 0 0 0 0-.707l-.707-.707L15.354 3.44a.5.5 0 0 0 0-.708l-.708-.707a.5.5 0 0 0-.707 0L10.706 3.294zM.146.146a.5.5 0 0 1 .708 0l15 15a.5.5 0 0 1-.708.708l-15-15a.5.5 0 0 1 0-.708"/></svg></div><h2>Connection Required</h2><p>This page requires an active internet connection. Offline attendance and grade activities remain available.</p><a href="javascript:history.back()" class="btn">Go Back</a></div></body></html>',
                  { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                );
              });
            });
          });
        })
    );
    return;
  }
});

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = { title: 'BSHS Notification', body: event.data ? event.data.text() : '' };
  }

  var title = data.title || 'BSHS Notification';
  var options = {
    body: data.body || '',
    icon: data.icon || '/assets/images/icon-192.png',
    badge: data.badge || '/assets/images/icon-192.png',
    vibrate: [200, 100, 200],
    data: { url: data.url || (data.data && data.data.url) || '/auth/login.php' }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var targetUrl = (event.notification && event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/auth/login.php';
  var resolvedUrl = new URL(targetUrl, self.location.origin).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url === resolvedUrl && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(resolvedUrl);
      }
    })
  );
});
