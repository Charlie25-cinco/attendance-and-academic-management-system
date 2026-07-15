// BSHS AMS Service Worker - caching + push notifications

const CACHE_NAME = 'bshs-ams-v2';

// Assets to pre-cache on install (shell assets that must work offline)
const PRECACHE_URLS = [
  self.registration.scope + 'auth/login.php',
  self.registration.scope + 'src/css/main.css',
  self.registration.scope + 'src/css/auth.css',
  self.registration.scope + 'src/image/bshs-logo.jpg',
  self.registration.scope + 'src/image/icon-192.png',
  self.registration.scope + 'src/image/icon-512.png',
  self.registration.scope + 'manifest.json'
];

// ── Install: pre-cache shell assets ──────────────────────────────────────────
self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.allSettled(
        PRECACHE_URLS.map(function (url) {
          return cache.add(url).catch(function (err) {
            console.warn('[SW] Pre-cache failed for ' + url + ':', err);
          });
        })
      );
    })
  );
});

// ── Activate: clean up old caches ────────────────────────────────────────────
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

// ── Fetch: network-first for PHP pages, cache-first for static assets ────────
self.addEventListener('fetch', function (event) {
  var url = new URL(event.request.url);

  // Only handle same-origin GET requests
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  var isStyleOrScript = /\.(css|js)(\?|$)/i.test(url.pathname);
  var isStaticAsset = /\.(png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)(\?|$)/i.test(url.pathname);

  if (isStyleOrScript) {
    // Network-first for CSS/JS so design updates appear without hard refresh.
    event.respondWith(
      fetch(event.request).then(function (response) {
        if (response && response.status === 200) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, clone);
          });
        }
        return response;
      }).catch(function () {
        return caches.match(event.request).then(function (cached) {
          if (cached) return cached;
          return new Response('', { status: 503, statusText: 'Service Unavailable' });
        });
      })
    );
  } else if (isStaticAsset) {
    // Cache-first strategy for static files that do not affect page chrome.
    event.respondWith(
      caches.match(event.request).then(function (cached) {
        if (cached) return cached;
        return fetch(event.request).then(function (response) {
          if (response && response.status === 200) {
            var clone = response.clone();
            caches.open(CACHE_NAME).then(function (cache) {
              cache.put(event.request, clone);
            });
          }
          return response;
        });
      })
    );
  } else {
    // Network-first strategy for PHP pages
    event.respondWith(
      fetch(event.request).then(function (response) {
        if (response && response.status === 200) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, clone);
          });
        }
        return response;
      }).catch(function () {
        // Offline fallback: serve from cache
        return caches.match(event.request).then(function (cached) {
          if (cached) return cached;
          // If no cache and it's a navigation, serve login page
          if (event.request.mode === 'navigate') {
            return caches.match(self.registration.scope + 'auth/login.php');
          }
          return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
        });
      })
    );
  }
});

// ── Push Notifications ───────────────────────────────────────────────────────
self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'BSHS Notification', body: event.data ? event.data.text() : '' };
  }
  var title = data.title || 'BSHS Notification';
  var options = {
    body: data.body || '',
    icon: data.icon || self.registration.scope + 'src/image/icon-192.png',
    badge: data.badge || self.registration.scope + 'src/image/icon-192.png',
    vibrate: [200, 100, 200],
    data: { url: data.url || self.registration.scope + 'auth/login.php' }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification Click ───────────────────────────────────────────────────────
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var targetUrl = (event.notification && event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : self.registration.scope + 'auth/login.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url === targetUrl && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});

