/**
 * BSHS AMS - IndexedDB & LocalStorage Client Offline Storage Engine
 * Manages teacher profile, class rosters, offline attendance, offline activities, and sync queue.
 */
(function (global) {
  'use strict';

  const DB_NAME = 'bshs_ams_offline_db';
  const DB_VERSION = 1;
  const STORES = {
    PROFILE: 'teacher_profile',
    CLASSES: 'teacher_classes',
    ROSTERS: 'class_rosters',
    SYNC_QUEUE: 'sync_queue'
  };

  let dbInstance = null;

  function openDatabase() {
    if (dbInstance) return Promise.resolve(dbInstance);
    if (!('indexedDB' in global)) {
      return Promise.resolve(null);
    }

    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = function (event) {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORES.PROFILE)) {
          db.createObjectStore(STORES.PROFILE, { keyPath: 'key' });
        }
        if (!db.objectStoreNames.contains(STORES.CLASSES)) {
          db.createObjectStore(STORES.CLASSES, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(STORES.ROSTERS)) {
          db.createObjectStore(STORES.ROSTERS, { keyPath: 'class_id' });
        }
        if (!db.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
          db.createObjectStore(STORES.SYNC_QUEUE, { keyPath: 'id' });
        }
      };

      request.onsuccess = function (event) {
        dbInstance = event.target.result;
        resolve(dbInstance);
      };

      request.onerror = function (event) {
        console.warn('[OfflineDB] IndexedDB error:', event.target.error);
        resolve(null); // Fallback to localStorage gracefully
      };
    });
  }

  const bshsOfflineStorage = {
    // -------------------------------------------------------------
    // Save Teacher Profile
    // -------------------------------------------------------------
    async saveTeacherProfile(profile) {
      if (!profile) return;
      try {
        localStorage.setItem('bshs_offline_profile', JSON.stringify(profile));
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.PROFILE], 'readwrite');
          tx.objectStore(STORES.PROFILE).put({ key: 'current_user', ...profile });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) { resolve(false); }
      });
    },

    async getTeacherProfile() {
      const db = await openDatabase();
      if (db) {
        try {
          const profile = await new Promise((resolve) => {
            const tx = db.transaction([STORES.PROFILE], 'readonly');
            const req = tx.objectStore(STORES.PROFILE).get('current_user');
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
          if (profile) return profile;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem('bshs_offline_profile')) || null;
      } catch (e) { return null; }
    },

    // -------------------------------------------------------------
    // Save & Retrieve Teacher Classes
    // -------------------------------------------------------------
    async saveClasses(classes) {
      if (!Array.isArray(classes)) return;
      try {
        localStorage.setItem('bshs_offline_classes', JSON.stringify(classes));
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.CLASSES], 'readwrite');
          const store = tx.objectStore(STORES.CLASSES);
          tx.objectStore(STORES.CLASSES).clear();
          classes.forEach((c) => store.put(c));
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) { resolve(false); }
      });
    },

    async getClasses() {
      const db = await openDatabase();
      if (db) {
        try {
          const classes = await new Promise((resolve) => {
            const tx = db.transaction([STORES.CLASSES], 'readonly');
            const req = tx.objectStore(STORES.CLASSES).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
          if (classes && classes.length > 0) return classes;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem('bshs_offline_classes')) || [];
      } catch (e) { return []; }
    },

    // -------------------------------------------------------------
    // Save & Retrieve Class Student Rosters
    // -------------------------------------------------------------
    async saveClassRoster(classId, students) {
      if (!classId || !Array.isArray(students)) return;
      const cId = parseInt(classId, 10);
      try {
        localStorage.setItem('bshs_offline_roster_' + cId, JSON.stringify(students));
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.ROSTERS], 'readwrite');
          tx.objectStore(STORES.ROSTERS).put({ class_id: cId, students, updatedAt: Date.now() });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) { resolve(false); }
      });
    },

    async getClassRoster(classId) {
      const cId = parseInt(classId, 10);
      const db = await openDatabase();
      if (db) {
        try {
          const item = await new Promise((resolve) => {
            const tx = db.transaction([STORES.ROSTERS], 'readonly');
            const req = tx.objectStore(STORES.ROSTERS).get(cId);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
          if (item && Array.isArray(item.students) && item.students.length > 0) {
            return item.students;
          }
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem('bshs_offline_roster_' + cId)) || [];
      } catch (e) { return []; }
    },

    // -------------------------------------------------------------
    // Sync Queue (Attendance & Activity Scores)
    // -------------------------------------------------------------
    async enqueueSyncItem(action) {
      const item = {
        id: Date.now() + '_' + Math.random().toString(36).slice(2, 8),
        action,
        addedAt: new Date().toISOString(),
        attempts: 0
      };

      try {
        const queue = JSON.parse(localStorage.getItem('bshs_offline_queue')) || [];
        queue.push(item);
        localStorage.setItem('bshs_offline_queue', JSON.stringify(queue));
      } catch (e) {}

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.SYNC_QUEUE], 'readwrite');
          tx.objectStore(STORES.SYNC_QUEUE).put(item);
        } catch (e) {}
      }

      return item;
    },

    async getSyncQueue() {
      const db = await openDatabase();
      if (db) {
        try {
          const items = await new Promise((resolve) => {
            const tx = db.transaction([STORES.SYNC_QUEUE], 'readonly');
            const req = tx.objectStore(STORES.SYNC_QUEUE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
          if (items && items.length > 0) return items;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem('bshs_offline_queue')) || [];
      } catch (e) { return []; }
    },

    async removeSyncItem(id) {
      try {
        const queue = JSON.parse(localStorage.getItem('bshs_offline_queue')) || [];
        const filtered = queue.filter((i) => i.id !== id);
        localStorage.setItem('bshs_offline_queue', JSON.stringify(filtered));
      } catch (e) {}

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.SYNC_QUEUE], 'readwrite');
          tx.objectStore(STORES.SYNC_QUEUE).delete(id);
        } catch (e) {}
      }
    },

    // -------------------------------------------------------------
    // Online Bootstrap Sync (Pulls entire teacher roster in <1s)
    // -------------------------------------------------------------
    async bootstrapOnline() {
      if (!navigator.onLine) return false;
      try {
        const targetUrl = (typeof withCsrfUrl === 'function')
          ? withCsrfUrl('teacher_Action.php?action=offline_bootstrap')
          : 'teacher_Action.php?action=offline_bootstrap';

        const res = await fetch(targetUrl, {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });

        if (!res.ok) return false;
        const data = await res.json();
        if (!data || !data.success) return false;

        if (data.teacher) {
          await this.saveTeacherProfile(data.teacher);
        }
        if (Array.isArray(data.classes)) {
          await this.saveClasses(data.classes);
        }
        if (data.rosters && typeof data.rosters === 'object') {
          for (const [classId, students] of Object.entries(data.rosters)) {
            await this.saveClassRoster(classId, students);
          }
        }
        return true;
      } catch (err) {
        return false;
      }
    }
  };

  global.bshsOfflineStorage = bshsOfflineStorage;
})(typeof window !== 'undefined' ? window : this);
