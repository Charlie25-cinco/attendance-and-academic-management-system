/* Execute the real browser code using deterministic browser API doubles. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

function cacheStorage(origin) {
  const caches = new Map();
  const key = request => new URL(typeof request === 'string' ? request : request.url, origin).href;
  return {
    async keys() { return [...caches.keys()]; },
    async delete(name) { return caches.delete(name); },
    async open(name) {
      if (!caches.has(name)) caches.set(name, new Map());
      const data = caches.get(name);
      return {
        async match(request) { return data.get(key(request))?.clone(); },
        async put(request, response) { data.set(key(request), response.clone()); },
        async delete(request) { return data.delete(key(request)); },
        async add() {},
      };
    },
  };
}

async function serviceWorkerTests() {
  const origin = 'https://school.test';
  const handlers = {};
  const caches = cacheStorage(origin);
  let network;
  const self = {
    location: new URL(origin + '/sw.js'),
    addEventListener(type, fn) { handlers[type] = fn; },
    clients: { matchAll: async () => [], claim: async () => {} },
    registration: { showNotification: async () => {} },
    skipWaiting() {},
  };
  vm.runInNewContext(source('sw.js'), { self, caches, URL, Response, console, fetch: request => network(request), clients: self.clients });
  const request = async (pathname, mode = 'navigate') => {
    let response;
    handlers.fetch({ request: { url: origin + pathname, method: 'GET', mode }, respondWith(promise) { response = promise; }, waitUntil() {} });
    return response ? await response : null;
  };
  const accountResponse = (account, text, until = Date.now() / 1000 + 3600) => new Response(text, {
    headers: { 'X-App-Offline-Account': account, 'X-App-Offline-Until': String(until), 'Cache-Control': 'no-store' },
  });
  network = async () => accountResponse('teacher:1', 'Teacher one private HTML');
  await request('/teacher/teacher.php');
  network = async () => { throw new Error('offline'); };
  assert.equal(await (await request('/teacher/teacher.php')).text(), 'Teacher one private HTML');
  assert.doesNotMatch(await (await request('/teacher/teacher.php?class_id=2')).text(), /Teacher one private HTML/);

  network = async () => accountResponse('teacher:2', 'Teacher two HTML');
  await request('/teacher/teacher.php');
  assert.equal((await caches.keys()).some(k => k.includes('user-teacher:1')), false);
  network = async () => { throw new Error('offline'); };
  assert.equal(await (await request('/teacher/teacher.php')).text(), 'Teacher two HTML');

  let lock;
  handlers.message({ data: { type: 'LOCK_OFFLINE' }, waitUntil(promise) { lock = promise; } });
  await lock;
  assert.match(await (await request('/teacher/teacher.php')).text(), /Offline workspace locked/);
  assert.equal((await caches.keys()).some(k => k.includes('-user-')), false);

  network = async () => accountResponse('admin:3', 'Admin private HTML');
  await request('/admin/admin.php');
  network = async () => { throw new Error('offline'); };
  assert.doesNotMatch(await (await request('/admin/admin.php')).text(), /Admin private HTML/);
  network = async () => accountResponse('teacher:1', 'Expired HTML', 1);
  await request('/teacher/teacher.php');
  network = async () => { throw new Error('offline'); };
  assert.match(await (await request('/teacher/teacher.php')).text(), /Offline workspace locked/);

  network = async () => new Response('old asset');
  await request('/assets/js/main.js', 'cors');
  network = async () => new Response('fresh asset');
  assert.equal(await (await request('/assets/js/main.js', 'cors')).text(), 'fresh asset');
  network = async () => { throw new Error('offline'); };
  assert.equal(await (await request('/assets/js/main.js', 'cors')).text(), 'fresh asset');
  assert.equal(await request('/api/index.php?route=profile'), null);
  assert.equal(await request('/teacher/teacher_Action.php?action=fetch_students'), null);
  console.log('PASS service worker: account switch, logout, expiry, private-page exclusion, exact query matching, network-first/offline assets');
}

function indexedDbDouble() {
  const databases = new Map();
  const request = value => {
    const req = {};
    setImmediate(() => { req.result = value(); req.onsuccess?.({ target: req }); });
    return req;
  };
  return {
    open(name) {
      const req = {};
      setImmediate(() => {
        const fresh = !databases.has(name);
        if (fresh) databases.set(name, new Map());
        const stores = databases.get(name);
        const store = name => {
          const { data, keyPath } = stores.get(name);
          return { createIndex() {}, clear() { data.clear(); }, put(v) { data.set(v[keyPath], structuredClone(v)); }, delete(k) { data.delete(k); }, get(k) { return request(() => data.get(k)); }, getAll() { return request(() => [...data.values()]); } };
        };
        const db = { close() {}, objectStoreNames: { contains: name => stores.has(name) }, createObjectStore(name, options) { stores.set(name, { data: new Map(), keyPath: options.keyPath }); return store(name); }, transaction() { const tx = { objectStore: store }; setImmediate(() => tx.oncomplete?.()); return tx; } };
        req.result = db;
        if (fresh) req.onupgradeneeded?.({ target: req });
        req.onsuccess?.({ target: req });
      });
      return req;
    },
  };
}

async function storageTests(useIndexedDb = false) {
  const values = new Map();
  const localStorage = { getItem: k => values.get(k) || null, setItem: (k, v) => values.set(k, String(v)), removeItem: k => values.delete(k) };
  let account = 'teacher:1';
  const syncTags = [];
  const navigator = { serviceWorker: { ready: Promise.resolve({ sync: { register: async tag => syncTags.push(tag) } }) } };
  const indexedDB = useIndexedDb ? indexedDbDouble() : null;
  const makeStorage = () => {
    const window = { APP_DOCUMENT_ACCOUNT: account, localStorage, SyncManager: function () {}, BSHS_OfflineIdentity: { currentAccount: () => account, lock: async () => { account = ''; } } };
    if (indexedDB) window.indexedDB = indexedDB;
    vm.runInNewContext(source('assets/js/offlineStorage.js'), { window, indexedDB, navigator, console, Date, Map });
    return window.bshsOfflineStorage;
  };
  values.set('bshs_offline_queue', JSON.stringify([{ id: 'legacy', payload: {} }]));
  const first = makeStorage();
  await first.saveTeacherSession({ teacher_id: 1, first_name: 'First' });
  await first.saveClasses([{ id: 1, name: 'Private class', schedule: 'Mon 8:00 AM - 9:00 AM' }]);
  await first.saveClassRoster(1, [{ id: 10, first_name: 'Learner', last_name: 'One', attendance_status: 'absent' }]);
  assert.equal((await first.getClassRoster(1))[0].attendance_status, undefined);
  await first.saveAttendanceSnapshot(1, '2026-10-05', [{ id: 10, attendance_status: 'late', remarks: 'Traffic' }]);
  assert.equal((await first.getLocalAttendance(1, '2026-10-05')).records[0].status, 'late');
  assert.equal(await first.getLocalAttendance(1, '2026-10-06'), null);
  assert.equal(await first.isClassScheduledOnDate(1, '2026-10-05'), true);
  assert.equal(await first.isClassScheduledOnDate(1, '2026-10-06'), false);
  await first.saveAttendanceLocally(1, '2026-10-03', [{ student_id: 10, status: 'present' }]);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(syncTags.includes('bshs-offline-sync'), true);
  assert.equal((await first.getSyncQueue()).length, 1);
  account = 'teacher:2';
  assert.equal((await first.getClasses()).length, 0);
  assert.equal(await first.getTeacherSession(), null);
  const second = makeStorage();
  assert.equal((await second.getSyncQueue()).length, 0);
  assert.equal((await second.getClasses()).length, 0);
  account = 'teacher:1';
  assert.equal((await first.getSyncQueue()).length, 1);
  await first.clearTeacherSession();
  assert.equal((await first.getSyncQueue()).length, 0);
  await assert.rejects(first.saveAttendanceLocally(1, '2026-10-03', []), /Sign in/);
  account = 'teacher:1';
  assert.equal((await makeStorage().getSyncQueue()).length, 1);
  console.log('PASS offline storage (' + (useIndexedDb ? 'IndexedDB' : 'localStorage fallback') + '): account namespaces, logout lock, preserved pending work, rejected legacy queue');
}

async function storageFailureTests() {
  const failingStorage = {
    getItem() { return null; },
    setItem() { throw new Error('quota exceeded'); },
    removeItem() {},
  };
  const window = {
    APP_DOCUMENT_ACCOUNT: 'teacher:9',
    localStorage: failingStorage,
    BSHS_OfflineIdentity: { currentAccount: () => 'teacher:9', lock: async () => {} },
  };
  vm.runInNewContext(source('assets/js/offlineStorage.js'), { window, indexedDB: null, navigator: {}, console, Date, Map });
  await assert.rejects(
    window.bshsOfflineStorage.saveAttendanceLocally(1, '2026-10-05', [{ student_id: 10, status: 'present' }]),
    /could not store the attendance sheet/i,
  );
  console.log('PASS offline storage failure: UI-facing save rejects when no durable storage succeeds');
}

async function syncTests() {
  let owner = 'teacher:2';
  let mutationResult = { success: true };
  const failures = [];
  const requests = [];
  const window = {
    addEventListener() {},
    bshsOfflineStorage: {
      owner: 'teacher:1',
      isUnlocked: () => true,
      getSyncQueue: async () => [{ owner: 'teacher:1', id: 'op_1', url: 'teacher_Action.php?action=submit_attendance', payload: { records: [] } }],
      markRecordSynced: async () => {},
      markSyncItemFailed: async (id, message, permanent) => failures.push({ id, message, permanent }),
    },
  };
  const sandbox = { window, navigator: { onLine: true }, console, setTimeout() {}, clearTimeout() {}, fetch: async (url, options) => {
    requests.push({ url, options });
    return new Response(JSON.stringify(options.method === 'POST' ? mutationResult : { success: true, teacher: { id: Number(owner.split(':')[1]) }, csrf_token: 'fresh' }));
  } };
  vm.createContext(sandbox);
  vm.runInContext('const csrfToken = "old";', sandbox);
  vm.runInContext(source('assets/js/networkSync.js'), sandbox);
  await window.BSHS_NetworkSync.processQueue();
  assert.equal(requests.filter(r => r.options.method === 'POST').length, 0);
  owner = 'teacher:1';
  await window.BSHS_NetworkSync.processQueue();
  const mutation = requests.find(r => r.options.method === 'POST');
  assert.equal(mutation.options.headers['X-CSRF-Token'], 'fresh');
  assert.equal(mutation.options.headers['X-Offline-Owner'], 'teacher:1');
  mutationResult = { success: false, message: 'This class has no schedule', retryable: false };
  await window.BSHS_NetworkSync.processQueue();
  assert.deepEqual(failures[0], { id: 'op_1', message: 'This class has no schedule', permanent: true });
  console.log('PASS network sync: other-account rejection, authoritative preflight CSRF, and permanent failure reporting');
}

async function identityTests() {
  let cookie = 'app_offline_account=' + encodeURIComponent('teacher:1|' + (Date.now() / 1000 + 3600));
  const redirects = [];
  const events = {};
  const window = { APP_DOCUMENT_ACCOUNT: 'teacher:1', location: { replace: url => redirects.push(url) }, setInterval() {}, addEventListener: (type, fn) => { events[type] = fn; } };
  const document = { currentScript: { src: 'https://school.test/assets/js/offlineIdentity.js?v=1' }, documentElement: { style: {} }, addEventListener() {}, get cookie() { return cookie; }, set cookie(value) { cookie = value.includes('Max-Age=0') ? '' : value; } };
  vm.runInNewContext(source('assets/js/offlineIdentity.js'), { window, document, URL, Date, navigator: {}, localStorage: { setItem() {}, removeItem() {} } });
  assert.equal(redirects.length, 0);
  cookie = 'app_offline_account=' + encodeURIComponent('principal:4|' + (Date.now() / 1000 + 3600));
  assert.equal(window.BSHS_OfflineIdentity.currentAccount(), 'principal:4');
  cookie = 'app_offline_account=' + encodeURIComponent('teacher:2|' + (Date.now() / 1000 + 3600));
  events.pageshow();
  assert.equal(document.documentElement.style.visibility, 'hidden');
  assert.equal(redirects.pop(), '/auth/login.php');
  cookie = 'app_offline_account=' + encodeURIComponent('teacher:1|1');
  assert.equal(window.BSHS_OfflineIdentity.currentAccount(), '');
  console.log('PASS document lock: account switch and expiry prevent stale-document access');
}

(async () => { await serviceWorkerTests(); await storageTests(); await storageTests(true); await storageFailureTests(); await syncTests(); await identityTests(); })().catch(error => { console.error(error); process.exitCode = 1; });
