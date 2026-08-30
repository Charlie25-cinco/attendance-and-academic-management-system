/**
 * BSHS AMS - Production IndexedDB Client Offline Storage Engine (v2)
 * Manages teacher local session, assigned classes, student rosters,
 * local attendance records, local activity scores, and sync queue.
 */
(function (global) {
  "use strict";

  const DB_NAME = "bshs_ams_offline_db";
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

  function openDatabase() {
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
    // -------------------------------------------------------------
    // 1. Teacher Session Management
    // -------------------------------------------------------------
    async saveTeacherSession(session) {
      if (!session || !session.teacher_id) return;
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
      try {
        localStorage.removeItem("bshs_teacher_session");
        localStorage.removeItem("bshs_cached_teacher");
      } catch (e) {}
      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction(
            [STORES.SESSION, STORES.PROFILE],
            "readwrite",
          );
          tx.objectStore(STORES.SESSION).clear();
          tx.objectStore(STORES.PROFILE).clear();
          tx.oncomplete = () => resolve(true);
          tx.onerror = () => resolve(false);
        } catch (e) {
          resolve(false);
        }
      });
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
      try {
        localStorage.setItem(
          "bshs_offline_roster_" + cId,
          JSON.stringify(students),
        );
      } catch (e) {}

      const db = await openDatabase();
      if (!db) return;
      return new Promise((resolve) => {
        try {
          const tx = db.transaction([STORES.ROSTERS], "readwrite");
          tx.objectStore(STORES.ROSTERS).put({
            class_id: cId,
            students,
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
            return item.students;
          }
        } catch (e) {}
      }
      try {
        return (
          JSON.parse(localStorage.getItem("bshs_offline_roster_" + cId)) || []
        );
      } catch (e) {
        return [];
      }
    },

    // -------------------------------------------------------------
    // 4. Local Offline Attendance Records
    // -------------------------------------------------------------
    async saveAttendanceLocally(classId, date, records) {
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

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ATTENDANCE, STORES.SYNC_QUEUE],
            "readwrite",
          );
          tx.objectStore(STORES.ATTENDANCE).put(recordItem);
          tx.objectStore(STORES.SYNC_QUEUE).put(syncOperation);
        } catch (e) {}
      }

      // Also persist in localStorage queue for sync bridge
      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter((q) => q.id !== syncOperation.id);
        filtered.push({
          id: syncOperation.id,
          action: {
            type: "submit_attendance",
            url: syncOperation.url,
            payload: syncOperation.payload,
          },
          addedAt: syncOperation.added_at,
          attempts: 0,
        });
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}

      return recordItem;
    },

    async getLocalAttendance(classId, date) {
      const cId = parseInt(classId, 10);
      const localId = "att_" + cId + "_" + date;
      const db = await openDatabase();
      if (db) {
        try {
          return await new Promise((resolve) => {
            const tx = db.transaction([STORES.ATTENDANCE], "readonly");
            const req = tx.objectStore(STORES.ATTENDANCE).get(localId);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => resolve(null);
          });
        } catch (e) {}
      }
      return null;
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
      classId,
      title,
      component,
      totalScore,
      date,
      scores,
    ) {
      const cId = parseInt(classId, 10);
      const safeTitle = (title || "untitled")
        .replace(/\s+/g, "_")
        .toLowerCase();
      const localId = "act_" + cId + "_" + safeTitle + "_" + date;

      const activityItem = {
        local_id: localId,
        class_id: cId,
        title: title,
        component: component,
        total_score: parseFloat(totalScore),
        activity_date: date,
        scores: scores,
        saved_at: new Date().toISOString(),
        sync_status: "pending",
      };

      const syncOperation = {
        id: "op_" + localId,
        operation_id: localId,
        operation: "activity.upsert",
        url: "teacher_Action.php?action=save_offline_activity",
        payload: {
          class_id: cId,
          title: title,
          component: component,
          total_score: parseFloat(totalScore),
          activity_date: date,
          scores: scores,
        },
        added_at: new Date().toISOString(),
        attempts: 0,
        status: "pending",
      };

      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ACTIVITIES, STORES.SYNC_QUEUE],
            "readwrite",
          );
          tx.objectStore(STORES.ACTIVITIES).put(activityItem);
          tx.objectStore(STORES.SYNC_QUEUE).put(syncOperation);
        } catch (e) {}
      }

      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter((q) => q.id !== syncOperation.id);
        filtered.push({
          id: syncOperation.id,
          action: {
            type: "save_offline_activity",
            url: syncOperation.url,
            payload: syncOperation.payload,
          },
          addedAt: syncOperation.added_at,
          attempts: 0,
        });
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}

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
      if (db) {
        try {
          const items = await new Promise((resolve) => {
            const tx = db.transaction([STORES.SYNC_QUEUE], "readonly");
            const req = tx.objectStore(STORES.SYNC_QUEUE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => resolve([]);
          });
          if (items && items.length > 0) return items;
        } catch (e) {}
      }
      try {
        return JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
      } catch (e) {
        return [];
      }
    },

    async markRecordSynced(operationId) {
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction(
            [STORES.ATTENDANCE, STORES.ACTIVITIES, STORES.SYNC_QUEUE],
            "readwrite",
          );
          // Update attendance record if exists
          const attStore = tx.objectStore(STORES.ATTENDANCE);
          const attReq = attStore.get(operationId);
          attReq.onsuccess = function () {
            if (attReq.result) {
              const rec = attReq.result;
              rec.sync_status = "synced";
              rec.synced_at = new Date().toISOString();
              attStore.put(rec);
            }
          };

          // Update activity record if exists
          const actStore = tx.objectStore(STORES.ACTIVITIES);
          const actReq = actStore.get(operationId);
          actReq.onsuccess = function () {
            if (actReq.result) {
              const rec = actReq.result;
              rec.sync_status = "synced";
              rec.synced_at = new Date().toISOString();
              actStore.put(rec);
            }
          };

          // Remove from sync queue
          tx.objectStore(STORES.SYNC_QUEUE).delete("op_" + operationId);
          tx.objectStore(STORES.SYNC_QUEUE).delete(operationId);
        } catch (e) {}
      }

      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter(
          (q) => q.id !== operationId && q.id !== "op_" + operationId,
        );
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}
    },

    async removeSyncItem(id) {
      const db = await openDatabase();
      if (db) {
        try {
          const tx = db.transaction([STORES.SYNC_QUEUE], "readwrite");
          tx.objectStore(STORES.SYNC_QUEUE).delete(id);
        } catch (e) {}
      }
      try {
        const queue =
          JSON.parse(localStorage.getItem("bshs_offline_queue")) || [];
        const filtered = queue.filter((q) => q.id !== id);
        localStorage.setItem("bshs_offline_queue", JSON.stringify(filtered));
      } catch (e) {}
    },

    // -------------------------------------------------------------
    // 7. Online Bootstrap Sync (Fetches Real Teacher Data)
    // -------------------------------------------------------------
    async bootstrapOnline() {
      if (!navigator.onLine) return false;
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
            const cacheKeys = await caches.keys();
            const activeCacheName =
              cacheKeys.find((k) => k.startsWith("bshs-ams-")) ||
              "bshs-ams-v33";
            const cache = await caches.open(activeCacheName);
            const teacherPages = [
              "/teacher/teacher.php",
              "/teacher/teacher_Attendance.php",
              "/teacher/teacher_Classes.php",
              "/teacher/teacher_Grades.php",
              "/teacher/teacher_Archives.php",
            ];
            const warmPages = async () => {
              for (const pageUrl of teacherPages) {
                try {
                  const pageRes = await fetch(pageUrl, {
                    credentials: "same-origin",
                  });
                  if (
                    pageRes &&
                    pageRes.status === 200 &&
                    !pageRes.redirected
                  ) {
                    await cache.put(pageUrl, pageRes.clone());
                    if (global.location && global.location.origin) {
                      await cache.put(
                        new URL(pageUrl, global.location.origin).href,
                        pageRes,
                      );
                    }
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

  global.bshsOfflineStorage = bshsOfflineStorage;
})(typeof window !== "undefined" ? window : this);
