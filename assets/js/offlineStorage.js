/**
 * BSHS AMS - Production IndexedDB Client Offline Storage Engine (v2)
 * Manages teacher local session, assigned classes, student rosters,
 * local attendance records, local activity scores, and sync queue.
 */
(function (global) {
  "use strict";

  const identity = global.BSHS_OfflineIdentity;
  const owner = global.APP_DOCUMENT_ACCOUNT || "";
  const isUnlocked = () => /^teacher:[1-9]\d*$/.test(owner) && identity.currentAccount() === owner;
  const DB_NAME = "bshs_ams_offline_db_" + owner.replace(":", "_");
  // Scope every legacy fallback key as well as IndexedDB to its owning teacher.
  const prefix = "bshs_user_" + owner + ":";
  const localStorage = {
    getItem(key) { return isUnlocked() ? global.localStorage.getItem(prefix + key) : null; },
    setItem(key, value) { if (isUnlocked()) global.localStorage.setItem(prefix + key, value); },
    removeItem(key) { if (isUnlocked()) global.localStorage.removeItem(prefix + key); },
  };
  const DB_VERSION = 2;
  const STORES = {
    SESSION: "teacher_session",
    PROFILE: "teacher_profile",
    CLASSES: "teacher_classes",
    ROSTERS: "class_rosters",
    ATTENDANCE: "attendance_records",
    ACTIVITIES: "activity_records",
    SYNC_QUEUE: "sync_queue",
  };

  let dbInstance = null;

  function waitForTransaction(tx) {
    return new Promise((resolve) => {
      tx.oncomplete = () => resolve(true);
      tx.onerror = () => resolve(false);
      tx.onabort = () => resolve(false);
    });
  }

  function notifySyncStatusChanged() {
    if (typeof global.dispatchEvent === "function" && typeof global.CustomEvent === "function") {
      try { global.dispatchEvent(new global.CustomEvent("bshs:sync-status-changed")); } catch (e) {}
    }
  }

  function openDatabase() {
    if (!isUnlocked()) return Promise.resolve(null);
    if (dbInstance) return Promise.resolve(dbInstance);
    if (!("indexedDB" in global)) {
      return Promise.resolve(null);
    }

    return new Promise((resolve) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = function (event) {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORES.SESSION)) {
          db.createObjectStore(STORES.SESSION, { keyPath: "key" });
        }
        if (!db.objectStoreNames.contains(STORES.PROFILE)) {
          db.createObjectStore(STORES.PROFILE, { keyPath: "key" });
        }
        if (!db.objectStoreNames.contains(STORES.CLASSES)) {
          db.createObjectStore(STORES.CLASSES, { keyPath: "id" });
        }
        if (!db.objectStoreNames.contains(STORES.ROSTERS)) {
          db.createObjectStore(STORES.ROSTERS, { keyPath: "class_id" });
        }
        if (!db.objectStoreNames.contains(STORES.ATTENDANCE)) {
          const attStore = db.createObjectStore(STORES.ATTENDANCE, {
            keyPath: "local_id",
          });
          attStore.createIndex("class_date", ["class_id", "date"], {
            unique: false,
          });
        }
        if (!db.objectStoreNames.contains(STORES.ACTIVITIES)) {
          const actStore = db.createObjectStore(STORES.ACTIVITIES, {
            keyPath: "local_id",
          });
          actStore.createIndex("class_id", "class_id", { unique: false });
        }
        if (!db.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
          const queueStore = db.createObjectStore(STORES.SYNC_QUEUE, {
            keyPath: "id",
          });
          queueStore.createIndex("status", "status", { unique: false });
        }
      };

      request.onsuccess = function (event) {
        dbInstance = event.target.result;
        resolve(dbInstance);
      };

      request.onerror = function (event) {
        console.warn("[OfflineDB] IndexedDB open error:", event.target.error);
        resolve(null);
      };
    });
  }

  const bshsOfflineStorage = {
    owner,
    isUnlocked,
    // -------------------------------------------------------------
    // 1. Teacher Session Management
    // -------------------------------------------------------------
    async saveTeacherSession(session) {
      if (!session || "teacher:" + Number(session.teacher_id) !== owner || !isUnlocked()) return;
      const data = {
        key: "current_user",
        teacher_id: parseInt(session.teacher_id, 10),
        role: session.role || "teacher",
        first_name: session.first_name || "",
        last_name: session.last_name || "",
        email: session.email || "",
        reference_code: session.reference_code || "",
        profile_picture: session.profile_picture || "",
        authenticated_at: session.authenticated_at || Date.now(),
        last_sync_at: Date.now(),
      };

      try {
        localStorage.setItem("bshs_teacher_session", JSON.stringify(data));
        localStorage.setItem("bshs_cached_teacher", "1");
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction(
            [STORES.SESSION, STORES.PROFILE],
            "readwrite",
          );
          tx.objectStore(STORES.SESSION).put(data);
          tx.objectStore(STORES.PROFILE).put(data);
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) {
          resolve(false);
        }
      });
    },

    async clearTeacherSession() {
      if (identity) await identity.lock();
      // Pending work stays in its account namespace until the same teacher signs in.
      if (dbInstance) { dbInstance.close(); dbInstance = null; }
      return true;
    },

    async getTeacherSession() {
      const db = await openDatabase();
      if (db) {
        try {
          const session = await new Promise((resolve) => {
            const tx = db.transaction([STORES.SESSION], "readonly");
            const req = tx.objectStore(STORES.SESSION).get("current_user");
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
          if (session && session.teacher_id) return session;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem("bshs_teacher_session")) || null;
      } catch (e) {
        return null;
      }
    },

    // -------------------------------------------------------------
    // 2. Teacher Assigned Classes Management
    // -------------------------------------------------------------
    async saveClasses(classes) {
      if (!Array.isArray(classes)) return;
      try {
        localStorage.setItem("bshs_offline_classes", JSON.stringify(classes));
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.CLASSES], "readwrite");
          const store = tx.objectStore(STORES.CLASSES);
          store.clear();
          classes.forEach((c) => store.put(c));
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) {
          resolve(false);
        }
      });
    },

    async getClasses() {
      const db = await openDatabase();
      if (db) {
        try {
          const classes = await new Promise((resolve) => {
            const tx = db.transaction([STORES.CLASSES], "readonly");
            const req = tx.objectStore(STORES.CLASSES).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
          if (classes && classes.length > 0) return classes;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem("bshs_offline_classes")) || [];
      } catch (e) {
        return [];
      }
    },

    // -------------------------------------------------------------
    // 3. Enrolled Student Rosters Management
    // -------------------------------------------------------------
    async saveClassRoster(classId, students) {
      if (!classId || !Array.isArray(students)) return;
      const cId = parseInt(classId, 10);
      const roster = students.map((student) => ({
        id: parseInt(student.id, 10),
        first_name: student.first_name || "",
        last_name: student.last_name || "",
        reference_code: student.reference_code || "",
        lrn: student.lrn || "",
        sex: student.sex || student.gender || "",
        gender: student.gender || student.sex || "",
      }));
      try {
        localStorage.setItem(
          "bshs_offline_roster_" + cId,
          JSON.stringify(roster),
        );
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.ROSTERS], "readwrite");
          tx.objectStore(STORES.ROSTERS).put({
            class_id: cId,
            students: roster,
            updatedAt: Date.now(),
          });
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) {
          resolve(false);
        }
      });
    },

    async getClassRoster(classId) {
      const cId = parseInt(classId, 10);
      const db = await openDatabase();
      if (db) {
        try {
          const item = await new Promise((resolve) => {
            const tx = db.transaction([STORES.ROSTERS], "readonly");
            const req = tx.objectStore(STORES.ROSTERS).get(cId);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
          if (
            item &&
            Array.isArray(item.students) &&
            item.students.length > 0
          ) {
            return item.students.map(({ attendance_status, remarks, ...student }) => student);
          }
        } catch (e) {}
      }
      try {
        const stored = JSON.parse(localStorage.getItem("bshs_offline_roster_" + cId)) || [];
        return stored.map(({ attendance_status, remarks, ...student }) => student);
      } catch (e) {
        return [];
      }
    },

    async saveAttendanceSnapshot(classId, date, students) {
      if (!isUnlocked() || !classId || !/^\d{4}-\d{2}-\d{2}$/.test(String(date)) || !Array.isArray(students)) return false;
      const cId = parseInt(classId, 10);
      const localId = "att_" + cId + "_" + date;
      const existing = await this.getLocalAttendance(cId, date);
      if (existing && existing.sync_status === "pending") return false;
      const recordItem = {
        local_id: localId,
        class_id: cId,
        date: String(date),
        records: students.map((student) => ({
          student_id: parseInt(student.id || student.student_id, 10),
          status: student.attendance_status || "present",
          remarks: student.remarks || "",
        })),
        saved_at: new Date().toISOString(),
        sync_status: "synced",
        source: "server",
      };

      let idbSaved = false;
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.ATTENDANCE], "readwrite");
          tx.objectStore(STORES.ATTENDANCE).put(recordItem);
          idbSaved = await waitForTransaction(tx);
        } catch (e) {}
      }
      let fallbackSaved = false;
      try {
        localStorage.setItem("bshs_offline_attendance_" + cId + "_" + date, JSON.stringify(recordItem));
        fallbackSaved = Boolean(localStorage.getItem("bshs_offline_attendance_" + cId + "_" + date));
      } catch (e) {}
      return idbSaved || fallbackSaved;
    },

    // -------------------------------------------------------------
    // 4. Local Offline Attendance Records
    // -------------------------------------------------------------
    async saveAttendanceLocally(classId, date, records) {
      if (!isUnlocked()) throw new Error("Sign in to your account before saving offline work.");
      const cId = parseInt(classId, 10);
      const localId = "att_" + cId + "_" + date;
      const recordItem = {
        local_id: localId,
        class_id: cId,
        date: date,
        records: records,
        saved_at: new Date().toISOString(),
        sync_status: "pending",
      };

      const syncOperation = {
        owner,
        id: "op_" + localId,
        operation_id: localId,
        operation: "attendance.upsert",
        url: "teacher_Action.php?action=submit_attendance",
        payload: {
          class_id: cId,
          date: date,
          mode: "subject",
          records: records,
        },
        added_at: new Date().toISOString(),
        attempts: 0,
        status: "pending",
      };

      let idbSaved = false;
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ATTENDANCE, STORES.SYNC_QUEUE],
            "readwrite",
          );
          tx.objectStore(STORES.ATTENDANCE).put(recordItem);
          tx.objectStore(STORES.SYNC_QUEUE).put(syncOperation);
          idbSaved = await waitForTransaction(tx);
        } catch (e) {}
      }

      // Also persist in localStorage queue for sync bridge
      let fallbackSaved = false;
      try {
        if (!idbSaved) {
          const queue = JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
          const filtered = queue.filter((q) => q.id !== syncOperation.id);
          filtered.push({
            owner,
            id: syncOperation.id,
            action: {
              type: "submit_attendance",
              url: syncOperation.url,
              payload: syncOperation.payload,
            },
            addedAt: syncOperation.added_at,
            attempts: 0,
            status: "pending",
          });
          localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
        }
        localStorage.setItem("bshs_offline_attendance_" + cId + "_" + date, JSON.stringify(recordItem));
        fallbackSaved = Boolean(localStorage.getItem("bshs_offline_attendance_" + cId + "_" + date)) &&
          (idbSaved || Boolean(localStorage.getItem("bshs_offline_queue")));
      } catch (e) {}

      if (!idbSaved && !fallbackSaved) {
        throw new Error("This device could not store the attendance sheet. Free storage space and try again.");
      }
      this.requestBackgroundSync();
      notifySyncStatusChanged();
      return recordItem;
    },

    async getLocalAttendance(classId, date) {
      const cId = parseInt(classId, 10);
      const localId = "att_" + cId + "_" + date;
      const db = await openDatabase();
      if (db) {
        try {
          const stored = await new Promise((resolve) => {
            const tx = db.transaction([STORES.ATTENDANCE], "readonly");
            const req = tx.objectStore(STORES.ATTENDANCE).get(localId);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
          if (stored) return stored;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem("bshs_offline_attendance_" + cId + "_" + date)) || null;
      } catch (e) {
        return null;
      }
    },

    async getAllLocalAttendance() {
      const db = await openDatabase();
      if (db) {
        try {
          return await new Promise((resolve) => {
            const tx = db.transaction([STORES.ATTENDANCE], "readonly");
            const req = tx.objectStore(STORES.ATTENDANCE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
        } catch (e) {}
      }
      return [];
    },

    // -------------------------------------------------------------
    // 5. Local Offline Activity & Score Records
    // -------------------------------------------------------------
    async saveActivityLocally(
      classIdOrObj,
      titleArg,
      componentArg,
      totalScoreArg,
      dateArg,
      scoresArg,
    ) {
      if (!isUnlocked()) throw new Error("Sign in to your account before saving offline work.");
      let cId, actTitle, comp, total, actDate, actScores, localId, serverId;

      if (typeof classIdOrObj === "object" && classIdOrObj !== null) {
        const obj = classIdOrObj;
        cId = parseInt(obj.class_id, 10);
        actTitle = obj.title || "untitled";
        comp = (obj.component || "ww").toLowerCase();
        total = parseFloat(obj.total_score || 0);
        actDate = obj.activity_date || obj.date || new Date().toISOString().split("T")[0];
        actScores = Array.isArray(obj.scores) ? obj.scores : [];
        serverId = obj.grade_item_id || obj.server_id || (typeof obj.id === "number" ? obj.id : null);
        localId = obj.local_id || ("act_" + cId + "_" + Date.now());
      } else {
        cId = parseInt(classIdOrObj, 10);
        actTitle = titleArg || "untitled";
        comp = (componentArg || "ww").toLowerCase();
        total = parseFloat(totalScoreArg || 0);
        actDate = dateArg || new Date().toISOString().split("T")[0];
        actScores = Array.isArray(scoresArg) ? scoresArg : [];
        const safeTitle = (actTitle || "untitled").replace(/\s+/g, "_").toLowerCase();
        localId = "act_" + cId + "_" + safeTitle + "_" + actDate;
        serverId = null;
      }

      const activityItem = {
        local_id: localId,
        server_id: serverId,
        class_id: cId,
        title: actTitle,
        component: comp,
        total_score: total,
        activity_date: actDate,
        scores: actScores,
        saved_at: new Date().toISOString(),
        sync_status: "pending",
      };

      const syncOperation = {
        owner,
        id: "op_" + localId,
        operation_id: localId,
        operation: "activity.upsert",
        url: "teacher_Action.php?action=save_offline_activity",
        payload: {
          grade_item_id: serverId,
          server_id: serverId,
          class_id: cId,
          title: actTitle,
          component: comp,
          total_score: total,
          activity_date: actDate,
          scores: actScores,
        },
        added_at: new Date().toISOString(),
        attempts: 0,
        status: "pending",
      };

      let idbSaved = false;
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ACTIVITIES, STORES.SYNC_QUEUE],
            "readwrite",
          );
          tx.objectStore(STORES.ACTIVITIES).put(activityItem);
          tx.objectStore(STORES.SYNC_QUEUE).put(syncOperation);
          idbSaved = await waitForTransaction(tx);
        } catch (e) {}
      }

      let fallbackSaved = false;
      try {
        if (!idbSaved) {
          const queue = JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
          const filtered = queue.filter((q) => q.id !== syncOperation.id && q.id !== localId);
          filtered.push({
            owner,
            id: syncOperation.id,
            action: {
              type: "save_offline_activity",
              url: syncOperation.url,
              payload: syncOperation.payload,
            },
            addedAt: syncOperation.added_at,
            attempts: 0,
            status: "pending",
          });
          localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
          fallbackSaved = Boolean(localStorage.getItem("bshs_offline_queue"));
        }
      } catch (e) {}

      if (!idbSaved && !fallbackSaved) {
        throw new Error("This device could not store the grade activity. Free storage space and try again.");
      }
      this.requestBackgroundSync();
      notifySyncStatusChanged();
      return activityItem;
    },

    async getAllLocalActivities() {
      const db = await openDatabase();
      if (db) {
        try {
          return await new Promise((resolve) => {
            const tx = db.transaction([STORES.ACTIVITIES], "readonly");
            const req = tx.objectStore(STORES.ACTIVITIES).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
        } catch (e) {}
      }
      return [];
    },

    // -------------------------------------------------------------
    // 6. Sync Queue Operations
    // -------------------------------------------------------------
    async getSyncQueue() {
      const db = await openDatabase();
      let idbQueue = [];
      if (db) {
        try {
          idbQueue = await new Promise((resolve) => {
            const tx = db.transaction([STORES.SYNC_QUEUE], "readonly");
            const req = tx.objectStore(STORES.SYNC_QUEUE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
        } catch (e) {}
      }

      let lsQueue = [];
      try {
        lsQueue = JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
      } catch (e) {}

      // IndexedDB is the authoritative queue whenever available. Remove legacy
      // mirrored entries so a service-worker sync cannot be replayed later.
      if (idbQueue.length > 0 && lsQueue.length > 0) {
        const idbKeys = new Set(idbQueue.map(item => item.id || ("op_" + (item.operation_id || item.local_id))));
        const fallbackOnly = lsQueue.filter(item => !idbKeys.has(item.id || ("op_" + (item.operation_id || item.local_id))));
        if (fallbackOnly.length !== lsQueue.length) {
          lsQueue = fallbackOnly;
          try { localStorage.setItem("bshs_offline_queue", JSON.stringify(lsQueue)); } catch (e) {}
        }
      }

      // Merge queues by key to prevent duplicates while ensuring survival across restarts
      const map = new Map();
      idbQueue.forEach((item) => {
        const key = item.id || ("op_" + (item.operation_id || item.local_id));
        map.set(key, item);
      });
      lsQueue.forEach((item) => {
        const key = item.id || ("op_" + (item.operation_id || item.local_id));
        if (!map.has(key)) {
          map.set(key, item);
        }
      });

      return isUnlocked()
        ? Array.from(map.values()).filter(item => item.owner === owner).map(item => ({
            ...item,
            status: item.status || "pending",
            attempts: Number(item.attempts || 0),
          }))
        : [];
    },

    async updateSyncItemState(identifier, changes) {
      const id = String(identifier || "");
      const opKey = id.startsWith("op_") ? id : "op_" + id;
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.SYNC_QUEUE], "readwrite");
          const store = tx.objectStore(STORES.SYNC_QUEUE);
          const req = store.get(opKey);
          req.onsuccess = function () {
            if (req.result) store.put({ ...req.result, ...changes });
          };
          await waitForTransaction(tx);
        } catch (e) {}
      }
      try {
        const queue = JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const updated = queue.map((item) => item.id === opKey || item.id === id
          ? { ...item, ...changes }
          : item);
        localStorage.setItem("bshs_offline_queue", JSON.stringify(updated));
      } catch (e) {}
      notifySyncStatusChanged();
    },

    async markSyncItemFailed(identifier, message, permanent = false) {
      const queue = await this.getSyncQueue();
      const id = String(identifier || "");
      const item = queue.find(entry => entry.id === id || entry.operation_id === id || entry.id === "op_" + id);
      const attempts = Number(item?.attempts || 0) + 1;
      const failed = permanent || attempts >= 5;
      await this.updateSyncItemState(identifier, {
        attempts,
        status: failed ? "failed" : "pending",
        last_error: String(message || "Synchronization failed"),
        last_attempt_at: new Date().toISOString(),
        retryable: !permanent,
      });
      return failed;
    },

    async retryFailedItems() {
      const queue = await this.getSyncQueue();
      for (const item of queue.filter(entry => entry.status === "failed")) {
        await this.updateSyncItemState(item.id, {
          status: "pending",
          attempts: 0,
          last_error: "",
          retryable: true,
        });
      }
      this.requestBackgroundSync();
      return true;
    },

    async getSyncStatus() {
      const queue = await this.getSyncQueue();
      const pending = queue.filter(item => item.status !== "failed").length;
      const failedItems = queue.filter(item => item.status === "failed");
      const failed = failedItems.length;
      let lastSyncedAt = "";
      try { lastSyncedAt = localStorage.getItem("bshs_offline_last_sync") || ""; } catch (e) {}
      return { pending, failed, total: queue.length, lastSyncedAt, lastError: failedItems[0]?.last_error || "" };
    },

    async isClassScheduledOnDate(classId, date) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(String(date || ""))) return false;
      const classes = await this.getClasses();
      const selected = classes.find(item => String(item.id) === String(classId));
      const schedule = String(selected?.schedule || "").trim();
      if (!schedule) return false;
      const parsedDate = new Date(String(date) + "T12:00:00");
      if (Number.isNaN(parsedDate.getTime())) return false;
      const day = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"][parsedDate.getDay()];
      return schedule.split(/\s*;\s*/).some((segment) => {
        const match = segment.trim().match(/^([\w,\s/\-]+)\s+(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i);
        if (!match) return false;
        return match[1].replace(/\s*\/\s*/g, ",").split(",")
          .map(value => value.trim().slice(0, 3).toLowerCase())
          .includes(day.toLowerCase());
      });
    },

    async clearLocalData() {
      if (!isUnlocked()) return false;
      if (dbInstance) { dbInstance.close(); dbInstance = null; }
      if ("indexedDB" in global && typeof global.indexedDB.deleteDatabase === "function") {
        await new Promise((resolve) => {
          try {
            const req = global.indexedDB.deleteDatabase(DB_NAME);
            req.onsuccess = req.onerror = req.onblocked = () => resolve();
          } catch (e) { resolve(); }
        });
      }
      try {
        const keys = [];
        for (let i = 0; i < global.localStorage.length; i++) {
          const key = global.localStorage.key(i);
          if (key && key.startsWith(prefix)) keys.push(key);
        }
        keys.forEach(key => global.localStorage.removeItem(key));
      } catch (e) {}
      if ("caches" in global) {
        try { await global.caches.delete("bshs-ams-v40-user-" + owner); } catch (e) {}
      }
      notifySyncStatusChanged();
      return true;
    },

    async deleteActivityLocally(identifier) {
      const rawId = String(identifier || "").replace(/^op_/, "");
      const opKey = "op_" + rawId;

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ACTIVITIES, STORES.SYNC_QUEUE],
            "readwrite",
          );
          const actStore = tx.objectStore(STORES.ACTIVITIES);
          const queueStore = tx.objectStore(STORES.SYNC_QUEUE);

          // Direct delete by key
          actStore.delete(rawId);
          queueStore.delete(opKey);
          queueStore.delete(rawId);

          // Scan all activities to match by local_id, id, or server_id
          const actReq = actStore.getAll();
          actReq.onsuccess = function () {
            const items = actReq.result || [];
            items.forEach(function (item) {
              if (
                String(item.local_id) === rawId ||
                String(item.id) === rawId ||
                (item.server_id && String(item.server_id) === rawId)
              ) {
                actStore.delete(item.local_id);
                queueStore.delete("op_" + item.local_id);
                queueStore.delete(item.local_id);
              }
            });
          };

          // Also scan queue to match by payload grade_item_id or local_id
          const qReq = queueStore.getAll();
          qReq.onsuccess = function () {
            const qItems = qReq.result || [];
            qItems.forEach(function (qItem) {
              const p = qItem.payload || {};
              if (
                String(qItem.id) === opKey ||
                String(qItem.id) === rawId ||
                String(qItem.operation_id) === rawId ||
                String(p.grade_item_id) === rawId ||
                String(p.server_id) === rawId
              ) {
                queueStore.delete(qItem.id);
              }
            });
          };
        } catch (e) {}
      }

      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter(function (q) {
          const p = q.payload || q.action?.payload || {};
          return (
            q.id !== rawId &&
            q.id !== opKey &&
            q.operation_id !== rawId &&
            String(p.grade_item_id) !== rawId &&
            String(p.server_id) !== rawId
          );
        });
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}
    },

    requestBackgroundSync() {
      if (typeof navigator !== "undefined" && "serviceWorker" in navigator && "SyncManager" in global) {
        navigator.serviceWorker.ready
          .then(function (reg) {
            if (reg && reg.sync && typeof reg.sync.register === "function") {
              return reg.sync.register("bshs-offline-sync");
            }
          })
          .catch(function () {});
      }
    },

    async markRecordSynced(operationId, extraData) {
      const rawId = String(operationId || "").replace(/^op_/, "");
      const opKey = "op_" + rawId;
      const serverGradeItemId = extraData && (extraData.grade_item_id || extraData.server_id)
        ? parseInt(extraData.grade_item_id || extraData.server_id, 10)
        : null;

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ATTENDANCE, STORES.ACTIVITIES, STORES.SYNC_QUEUE],
            "readwrite",
          );
          // Update attendance record if exists
          const attStore = tx.objectStore(STORES.ATTENDANCE);
          const attReq = attStore.get(rawId);
          attReq.onsuccess = function () {
            if (attReq.result) {
              const rec = attReq.result;
              rec.sync_status = "synced";
              rec.synced_at = new Date().toISOString();
              attStore.put(rec);
            }
          };

          // Update activity record if exists and persist server_id
          const actStore = tx.objectStore(STORES.ACTIVITIES);
          const actReq = actStore.get(rawId);
          actReq.onsuccess = function () {
            if (actReq.result) {
              const rec = actReq.result;
              rec.sync_status = "synced";
              rec.synced_at = new Date().toISOString();
              if (serverGradeItemId && serverGradeItemId > 0) {
                rec.server_id = serverGradeItemId;
                rec.id = serverGradeItemId;
              }
              actStore.put(rec);
            }
          };

          // Remove from sync queue
          tx.objectStore(STORES.SYNC_QUEUE).delete(opKey);
          tx.objectStore(STORES.SYNC_QUEUE).delete(rawId);
          await waitForTransaction(tx);
        } catch (e) {}
      }

      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter(
          (q) => q.id !== rawId && q.id !== opKey && (q.operation_id !== rawId),
        );
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
        if (rawId.startsWith("att_")) {
          const attendanceKey = "bshs_offline_attendance_" + rawId.substring(4);
          const stored = JSON.parse(localStorage.getItem(attendanceKey) || "null");
          if (stored) {
            stored.sync_status = "synced";
            stored.synced_at = new Date().toISOString();
            localStorage.setItem(attendanceKey, JSON.stringify(stored));
          }
        }
        localStorage.setItem("bshs_offline_last_sync", new Date().toISOString());
      } catch (e) {}
      notifySyncStatusChanged();
    },

    async removeSyncItem(id) {
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.SYNC_QUEUE], "readwrite");
          tx.objectStore(STORES.SYNC_QUEUE).delete(id);
          await waitForTransaction(tx);
        } catch (e) {}
      }
      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter((q) => q.id !== id);
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}
      notifySyncStatusChanged();
    },

    // -------------------------------------------------------------
    // 7. Online Bootstrap Sync (Fetches Real Teacher Data)
    // -------------------------------------------------------------
    async bootstrapOnline() {
      if (!navigator.onLine || !isUnlocked()) return false;
      try {
        const targetUrl =
          typeof withCsrfUrl === "function"
            ? withCsrfUrl("teacher_Action.php?action=offline_bootstrap")
            : "teacher_Action.php?action=offline_bootstrap";

        const res = await fetch(targetUrl, {
          headers: { Accept: "application/json" },
          cache: "no-store",
        });

        if (!res.ok) return false;
        const data = await res.json();
        if (!data || !data.success) return false;
        if (!data.teacher || "teacher:" + Number(data.teacher.id) !== owner || !isUnlocked()) return false;

        if (data.teacher) {
          await this.saveTeacherSession({
            teacher_id: data.teacher.id,
            role: "teacher",
            first_name: data.teacher.first_name,
            last_name: data.teacher.last_name,
            email: data.teacher.email,
            reference_code: data.teacher.reference_code,
            profile_picture: data.teacher.profile_picture,
          });
        }

        if (Array.isArray(data.classes)) {
          await this.saveClasses(data.classes);
        }

        if (data.rosters && typeof data.rosters === "object") {
          for (const [classId, students] of Object.entries(data.rosters)) {
            await this.saveClassRoster(classId, students);
          }
        }

        if ("caches" in global) {
          try {
            const activeCacheName = "bshs-ams-v40-user-" + owner;
            const cache = await caches.open(activeCacheName);
            const teacherPages = [
              "/teacher/teacher.php",
              "/teacher/teacher_Attendance.php",
              "/teacher/teacher_Classes.php",
              "/teacher/teacher_Grades.php",
            ];
            const warmPages = async () => {
              for (const pageUrl of teacherPages) {
                if (!isUnlocked()) return;
                try {
                  const target = (identity.base || "") + pageUrl;
                  const pageRes = await fetch(target, {
                    credentials: "same-origin",
                  });
                  if (
                    pageRes &&
                    pageRes.status === 200 &&
                    !pageRes.redirected && isUnlocked() &&
                    pageRes.headers.get("X-App-Offline-Account") === owner
                  ) {
                    await cache.put(target, pageRes);
                    if (!isUnlocked()) await caches.delete(activeCacheName);
                  }
                } catch (e) {}
              }
            };
            if (typeof global.requestIdleCallback === "function") {
              global.requestIdleCallback(() => warmPages(), { timeout: 5000 });
            } else {
              setTimeout(warmPages, 2000);
            }
          } catch (e) {}
        }

        return true;
      } catch (err) {
        return false;
      }
    },
  };

  // An account switch during an asynchronous read must not return the old account's data.
  for (const [method, empty] of Object.entries({
    getTeacherSession: null, getClasses: [], getClassRoster: [], getLocalAttendance: null,
    getAllLocalAttendance: [], getAllLocalActivities: [], getSyncQueue: [],
  })) {
    const read = bshsOfflineStorage[method];
    bshsOfflineStorage[method] = async function (...args) {
      if (!isUnlocked()) return empty;
      const result = await read.apply(this, args);
      return isUnlocked() ? result : empty;
    };
  }
  global.bshsOfflineStorage = bshsOfflineStorage;
})(typeof window !== "undefined" ? window : this);
