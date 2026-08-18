// BSHS AMS root service worker - PWA cache + push notifications

const CACHE_NAME = 'bshs-ams-v13';
const APP_SHELL_URLS = [
  '/assets/manifest.json',
  '/assets/css/main.css',
  '/assets/css/auth.css',
  '/assets/css/Site.css',
  '/assets/js/main.js',
  '/assets/js/networkSync.js',
  '/assets/images/bshs-logo.jpg',
  '/assets/images/icon-192.png',
  '/assets/images/icon-512.png',
  '/assets/images/icon-maskable-512.png',
  '/assets/vendor/bootstrap/bootstrap.min.css',
  '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.css'
];

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return Promise.allSettled(
        APP_SHELL_URLS.map(function (url) {
          return cache.add(url).catch(function (error) {
            console.warn('[SW] Pre-cache failed for ' + url + ':', error);
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
  return response && response.status === 200 && response.type !== 'opaque';
}

function cacheResponse(request, response) {
  if (!shouldCacheResponse(response)) return;
  var clone = response.clone();
  caches.open(CACHE_NAME).then(function (cache) {
    cache.put(request, clone);
  });
}

self.addEventListener('fetch', function (event) {
  var url = new URL(event.request.url);

  if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  var isAsset = url.pathname.indexOf('/assets/') === 0;
  var isStaticAsset = /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|json)(\?|$)/i.test(url.pathname);
  var needsFreshAsset = /\.(css|js)$/i.test(url.pathname);

  if (needsFreshAsset) {
    event.respondWith(
      fetch(event.request).then(function (response) {
        cacheResponse(event.request, response);
        return response;
      }).catch(function () {
        return caches.match(event.request);
      })
    );
    return;
  }

  if (isAsset || isStaticAsset) {
    event.respondWith(
      caches.match(event.request).then(function (cached) {
        var fetchAndCache = fetch(event.request).then(function (response) {
          cacheResponse(event.request, response);
          return response;
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
      fetch(event.request).catch(function () {
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
      })
    );
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
