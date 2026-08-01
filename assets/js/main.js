/* ============================================
   Attendance and Academic Management System
   Main JavaScript File
   ============================================ */

// ============================================
// SIDEBAR FUNCTIONS
// ============================================

// Toggle sidebar collapse (desktop)
function toggleSidebar() {
  const sidebar = document.getElementById("sidebar");
  const toggleIcon = document.getElementById("toggleIcon");

  sidebar.classList.toggle("collapsed");

  if (sidebar.classList.contains("collapsed")) {
    toggleIcon.classList.remove("bi-chevron-left");
    toggleIcon.classList.add("bi-chevron-right");
    localStorage.setItem("sidebarCollapsed", "true");
  } else {
    toggleIcon.classList.remove("bi-chevron-right");
    toggleIcon.classList.add("bi-chevron-left");
    localStorage.setItem("sidebarCollapsed", "false");
  }

}

// Open mobile sidebar
function openMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("mobileOverlay");

  sidebar.classList.add("mobile-open");
  overlay.classList.add("active");
  document.body.style.overflow = "hidden";
}

// Close mobile sidebar
function closeMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("mobileOverlay");

  sidebar.classList.remove("mobile-open");
  overlay.classList.remove("active");
  document.body.style.overflow = "";
}

// Restore sidebar state on page load
document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleIcon = document.getElementById("toggleIcon");

  if (sidebar && toggleIcon) {
    const isCollapsed = localStorage.getItem("sidebarCollapsed") === "true";

    if (isCollapsed && window.innerWidth >= 992) {
      sidebar.classList.add("sidebar-no-transition");
      sidebar.classList.add("collapsed");
      toggleIcon.classList.remove("bi-chevron-left");
      toggleIcon.classList.add("bi-chevron-right");
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          sidebar.classList.remove("sidebar-no-transition");
        });
      });
    }
  }

  // Initialize tooltips
  initTooltips();

  // Initialize attendance toggles
  initAttendanceToggles();

  // Initialize progress circles
  initProgressCircles();

  // Focus the first usable field when modal forms open
  initModalAutofocus();

  // Initialize shared settings and PWA push notification controls
  initSettingsControls();
  initPwaPushAutoRegistration();

});

// ============================================
// MODAL FORM AUTOFOCUS
// ============================================

function isFocusableFormField(field) {
  if (!field) return false;
  if (field.disabled || field.readOnly) return false;
  if (field.matches('[type="hidden"], [tabindex="-1"], [data-autofocus-skip="true"]')) return false;
  if (field.offsetParent === null && field.getClientRects().length === 0) return false;
  return true;
}

function focusFirstModalField(modalEl) {
  if (!modalEl) return;
  const selectors = [
    "[data-autofocus]",
    "input:not([type='hidden'])",
    "select",
    "textarea",
  ].join(",");
  const fields = Array.from(modalEl.querySelectorAll(selectors));
  const firstField = fields.find(isFocusableFormField);
  if (!firstField) return;

  window.setTimeout(() => {
    firstField.focus({ preventScroll: true });
    if (typeof firstField.select === "function" && firstField.matches("input[type='text'], input[type='email'], input[type='tel'], input[type='search'], input:not([type])")) {
      firstField.select();
    }
  }, 80);
}

function initModalAutofocus() {
  document.addEventListener("shown.bs.modal", function (event) {
    focusFirstModalField(event.target);
  });
}

// Handle window resize
window.addEventListener("resize", function () {
  if (window.innerWidth >= 992) {
    closeMobileSidebar();
  }
});

// ============================================
// TOOLTIPS
// ============================================

function initTooltips() {
  const tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]'),
  );
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
}

// ============================================
// ATTENDANCE FUNCTIONS
// ============================================

function initAttendanceToggles() {
  const attendanceButtons = document.querySelectorAll(".attendance-status");

  attendanceButtons.forEach((button) => {
    button.addEventListener("click", function () {
      // Cycle through states: present -> absent -> late -> present
      if (this.classList.contains("present")) {
        this.classList.remove("present");
        this.classList.add("absent");
        this.innerHTML = '<i class="bi bi-x-circle"></i> Absent';
      } else if (this.classList.contains("absent")) {
        this.classList.remove("absent");
        this.classList.add("late");
        this.innerHTML = '<i class="bi bi-clock"></i> Late';
      } else if (this.classList.contains("late")) {
        this.classList.remove("late");
        this.classList.add("present");
        this.innerHTML = '<i class="bi bi-check-circle"></i> Present';
      }
    });
  });
}

// Submit attendance
function submitAttendance() {
  const btn = document.getElementById("submitAttendanceBtn");
  if (btn) {
    btn.disabled = true;
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    setTimeout(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Attendance Saved';
      btn.classList.remove("btn-primary");
      btn.classList.add("btn-success");

      // Show success notification
      showNotification("Attendance submitted successfully!", "success");

      setTimeout(() => {
        btn.innerHTML = '<i class="bi bi-save me-2"></i>Submit Attendance';
        btn.classList.remove("btn-success");
        btn.classList.add("btn-primary");
      }, 2000);
    }, 1500);
  }
}

// ============================================
// PROGRESS CIRCLES
// ============================================

function initProgressCircles() {
  const circles = document.querySelectorAll(".attendance-circle-progress");

  circles.forEach((circle) => {
    const radius = circle.r.baseVal.value;
    const circumference = radius * 2 * Math.PI;
    const percent = circle.getAttribute("data-percent") || 0;

    circle.style.strokeDasharray = `${circumference} ${circumference}`;
    circle.style.strokeDashoffset = circumference;

    const offset = circumference - (percent / 100) * circumference;

    setTimeout(() => {
      circle.style.strokeDashoffset = offset;
    }, 300);
  });
}

// ============================================
// NOTIFICATIONS
// ============================================

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const escHtml = escapeHtml;
const esc = escapeHtml;

function programLabel(row) {
  if (row.program === "academic_strengthened") return "Academic Track (Strengthened)";
  if (row.program === "technical_professional") return "Technical Professional";
  if (row.track === "techpro") return "TechPro";
  if (row.track === "academic") return "Academic";
  return "";
}

function appendCsrfToFormData(fd) {
  if (fd && typeof csrfToken !== "undefined" && csrfToken) {
    fd.set("csrf_token", csrfToken);
  }
  return fd;
}

function withCsrfUrl(url) {
  if (typeof csrfToken === "undefined" || !csrfToken) return url;
  return url + (url.includes("?") ? "&" : "?") + "csrf_token=" + encodeURIComponent(csrfToken);
}

function getNotificationMeta(type = "info") {
  const normalized = String(type || "info").toLowerCase();
  if (normalized === "danger" || normalized === "error") {
    return {
      tone: "error",
      icon: "bi-exclamation-octagon-fill",
      title: "Action failed",
    };
  }
  if (normalized === "success") {
    return {
      tone: "success",
      icon: "bi-check-circle-fill",
      title: "Success",
    };
  }
  if (normalized === "warning") {
    return {
      tone: "warning",
      icon: "bi-exclamation-triangle-fill",
      title: "Heads up",
    };
  }
  return {
    tone: "info",
    icon: "bi-info-circle-fill",
    title: "Notice",
  };
}

function ensureNotificationHost() {
  let host = document.getElementById("appNotificationHost");
  if (host) return host;
  host = document.createElement("div");
  host.id = "appNotificationHost";
  host.className = "notification-host";
  document.body.appendChild(host);
  return host;
}

function showNotification(message, type = "info", title = "") {
  const host = ensureNotificationHost();
  const meta = getNotificationMeta(type);
  const notification = document.createElement("div");
  notification.className = `notification-toast ${meta.tone}`;
  notification.setAttribute("role", "status");
  notification.setAttribute("aria-live", "polite");
  notification.innerHTML = `
    <div class="notification-icon">
      <i class="bi ${meta.icon}"></i>
    </div>
    <div class="notification-content">
      <div class="notification-title">${escapeHtml(title || meta.title)}</div>
      <div class="notification-message">${escapeHtml(String(message || ""))}</div>
    </div>
    <button type="button" class="notification-close" aria-label="Dismiss notification">
      <i class="bi bi-x-lg"></i>
    </button>
  `;

  const closeBtn = notification.querySelector(".notification-close");
  const removeNotification = function () {
    notification.style.animation = "slideOutRight 0.2s ease forwards";
    window.setTimeout(() => notification.remove(), 180);
  };
  if (closeBtn) {
    closeBtn.addEventListener("click", removeNotification);
  }

  host.appendChild(notification);
  window.setTimeout(removeNotification, 5000);
}

// ============================================
// SETTINGS AND PWA PUSH NOTIFICATIONS
// ============================================

function appApiUrl(route) {
  const url = `/api/index.php?route=${encodeURIComponent(route)}`;
  const token = window.APP_CSRF_TOKEN || "";
  return token ? `${url}&csrf_token=${encodeURIComponent(token)}` : url;
}

function appFetchJson(route, options = {}) {
  const headers = new Headers(options.headers || {});
  if (!headers.has("Content-Type") && options.body) {
    headers.set("Content-Type", "application/json");
  }
  if (window.APP_CSRF_TOKEN && !headers.has("X-CSRF-Token")) {
    headers.set("X-CSRF-Token", window.APP_CSRF_TOKEN);
  }

  return fetch(appApiUrl(route), Object.assign({}, options, {
    headers,
    credentials: "same-origin",
  })).then((response) => response.json().then((data) => {
    if (!response.ok || !data.ok) {
      throw new Error(data.message || "Request failed");
    }
    return data;
  }));
}

function setSettingsBusy(isBusy) {
  const btn = document.getElementById("saveSettingsBtn");
  if (!btn) return;
  btn.disabled = !!isBusy;
  btn.innerHTML = isBusy
    ? '<span class="spinner-border spinner-border-sm me-2"></span>Saving...'
    : "Save Changes";
}

function setPushStatus(message, tone = "muted") {
  const status = document.getElementById("pushNotificationStatus");
  if (!status) return;
  status.textContent = message || "";
  status.className = `settings-option-desc d-block text-${tone}`;
}

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

function pwaPushSupported() {
  return "serviceWorker" in navigator
    && "PushManager" in window
    && "Notification" in window
    && !!window.APP_PUSH_PUBLIC_KEY;
}

function getPwaServiceWorkerRegistration() {
  if (!("serviceWorker" in navigator)) {
    return Promise.reject(new Error("Service worker is not supported on this device"));
  }
  if (window._swRegistration) {
    return Promise.resolve(window._swRegistration);
  }
  return navigator.serviceWorker.ready;
}

function updatePwaPushStatus() {
  const pushSwitch = document.getElementById("pushNotifSwitch");
  if (!pushSwitch) return;

  if (!pwaPushSupported()) {
    pushSwitch.disabled = true;
    setPushStatus("Device notifications need VAPID push keys in the environment.", "muted");
    return;
  }

  if (Notification.permission === "denied") {
    setPushStatus("Notifications are blocked in this browser.", "danger");
    return;
  }

  if (Notification.permission === "granted") {
    getPwaServiceWorkerRegistration()
      .then((registration) => registration.pushManager.getSubscription())
      .then((subscription) => {
        setPushStatus(subscription ? "This device is subscribed." : "Save changes to subscribe this device.", subscription ? "success" : "muted");
      })
      .catch(() => setPushStatus("Save changes to subscribe this device.", "muted"));
    return;
  }

  setPushStatus("Save changes to allow device notifications.", "muted");
}

function enablePwaPushNotifications() {
  if (!pwaPushSupported()) {
    throw new Error("Device notifications are not configured for this app");
  }

  return getPwaServiceWorkerRegistration()
    .then((registration) => {
      if (Notification.permission === "granted") {
        return registration;
      }
      return Notification.requestPermission().then((permission) => {
        if (permission !== "granted") {
          throw new Error("Notification permission was not granted");
        }
        return registration;
      });
    })
    .then((registration) => registration.pushManager.getSubscription()
      .then((existing) => existing || registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(window.APP_PUSH_PUBLIC_KEY),
      })))
    .then((subscription) => appFetchJson("web-push-subscription", {
      method: "POST",
      body: JSON.stringify({ subscription: subscription.toJSON() }),
    }));
}

function disablePwaPushNotifications() {
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
    return Promise.resolve();
  }

  return getPwaServiceWorkerRegistration()
    .then((registration) => registration.pushManager.getSubscription())
    .then((subscription) => {
      if (!subscription) return null;
      const endpoint = subscription.endpoint;
      return subscription.unsubscribe()
        .catch(() => false)
        .then(() => appFetchJson("web-push-subscription", {
          method: "DELETE",
          body: JSON.stringify({ endpoint }),
        }).catch(() => null));
    });
}

function applyInitialSettingsToControls() {
  const settings = window.APP_INITIAL_SETTINGS || {};
  const darkMode = document.getElementById("darkModeSwitch");
  const emailNotif = document.getElementById("emailNotifSwitch");
  const pushNotif = document.getElementById("pushNotifSwitch");

  if (darkMode) darkMode.checked = String(settings.dark_mode || "0") === "1";
  if (emailNotif) emailNotif.checked = String(settings.email_notifications ?? "1") === "1";
  if (pushNotif) pushNotif.checked = String(settings.push_notifications ?? "1") === "1";
}

function initSettingsControls() {
  const saveBtn = document.getElementById("saveSettingsBtn");
  if (!saveBtn) return;

  applyInitialSettingsToControls();
  updatePwaPushStatus();

  const darkMode = document.getElementById("darkModeSwitch");
  if (darkMode) {
    darkMode.addEventListener("change", function () {
      document.body.classList.toggle("dark-mode", darkMode.checked);
    });
  }

  saveBtn.addEventListener("click", function () {
    const pushNotif = document.getElementById("pushNotifSwitch");
    const payload = {
      dark_mode: document.getElementById("darkModeSwitch")?.checked ? 1 : 0,
      email_notifications: document.getElementById("emailNotifSwitch")?.checked ? 1 : 0,
      push_notifications: pushNotif?.checked ? 1 : 0,
    };
    const permissionStep = payload.push_notifications && pwaPushSupported() && Notification.permission !== "granted"
      ? Notification.requestPermission().then((permission) => {
        if (permission !== "granted") {
          throw new Error("Notification permission was not granted");
        }
        return permission;
      })
      : Promise.resolve();

    setSettingsBusy(true);
    permissionStep
      .then(() => appFetchJson("settings", {
        method: "POST",
        body: JSON.stringify(payload),
      }))
      .then(() => {
        if (!payload.push_notifications) {
          return disablePwaPushNotifications();
        }
        return pwaPushSupported() ? enablePwaPushNotifications() : Promise.resolve();
      })
      .then(() => {
        if (typeof showNotification === "function") {
          showNotification("Settings saved successfully", "success");
        }
        updatePwaPushStatus();
      })
      .catch((error) => {
        if (typeof showNotification === "function") {
          showNotification(error.message || "Failed to save settings", "danger");
        }
        updatePwaPushStatus();
      })
      .finally(() => setSettingsBusy(false));
  });
}

function initPwaPushAutoRegistration() {
  const settings = window.APP_INITIAL_SETTINGS || {};
  const pushEnabled = String(settings.push_notifications ?? "1") === "1";
  if (!pushEnabled || !pwaPushSupported() || Notification.permission !== "granted") {
    return;
  }

  enablePwaPushNotifications().catch(() => {
    updatePwaPushStatus();
  });
}

function ensureAppConfirmModal() {
  const modalEl = document.getElementById("appConfirmModal");
  if (!modalEl || typeof bootstrap === "undefined" || !bootstrap.Modal) return null;
  return bootstrap.Modal.getOrCreateInstance(modalEl);
}

function showAppConfirm(options = {}) {
  const modalEl = document.getElementById("appConfirmModal");
  const modal = ensureAppConfirmModal();
  if (!modalEl || !modal) {
    return Promise.resolve(window.confirm(String(options.message || "Are you sure?")));
  }

  const opts = Object.assign(
    {
      title: "Please confirm",
      message: "Are you sure you want to continue?",
      confirmText: "Continue",
      cancelText: "Cancel",
      tone: "primary",
      icon: "bi-question-circle-fill",
      subtitle: "",
    },
    options || {},
  );

  const kicker = modalEl.querySelector("[data-confirm-kicker]");
  const titleEl = modalEl.querySelector("[data-confirm-title]");
  const subtitleEl = modalEl.querySelector("[data-confirm-subtitle]");
  const messageEl = modalEl.querySelector("[data-confirm-message]");
  const iconEl = modalEl.querySelector("[data-confirm-icon]");
  const confirmBtn = modalEl.querySelector("[data-confirm-accept]");
  const cancelBtn = modalEl.querySelector("[data-confirm-cancel]");
  const contentEl = modalEl.querySelector(".app-modal-content");

  if (kicker) {
    const label = opts.tone === "danger" ? "Confirm action" : "Confirmation";
    kicker.innerHTML = `<i class="bi ${escapeHtml(opts.icon)}"></i>${escapeHtml(label)}`;
  }
  if (titleEl) titleEl.textContent = String(opts.title || "Please confirm");
  if (subtitleEl) {
    const subtitle = String(opts.subtitle || "");
    subtitleEl.textContent = subtitle;
    subtitleEl.style.display = subtitle ? "" : "none";
  }
  if (messageEl) messageEl.innerHTML = String(opts.message || "");
  if (iconEl) {
    iconEl.className = `bi ${opts.icon} app-confirm-hero-icon text-${opts.tone === "danger" ? "danger" : "primary"}`;
  }
  if (confirmBtn) {
    confirmBtn.textContent = String(opts.confirmText || "Continue");
    confirmBtn.className = `btn ${opts.tone === "danger" ? "btn-danger" : "btn-primary-custom"}`;
  }
  if (cancelBtn) cancelBtn.textContent = String(opts.cancelText || "Cancel");
  if (contentEl) {
    contentEl.classList.toggle("app-modal-danger", opts.tone === "danger");
  }

  return new Promise((resolve) => {
    let settled = false;
    const cleanup = function (value) {
      if (settled) return;
      settled = true;
      modalEl.removeEventListener("hidden.bs.modal", handleHidden);
      if (confirmBtn) confirmBtn.removeEventListener("click", handleConfirm);
      resolve(value);
    };
    const handleHidden = function () {
      cleanup(false);
    };
    const handleConfirm = function () {
      cleanup(true);
      modal.hide();
    };

    modalEl.addEventListener("hidden.bs.modal", handleHidden, { once: true });
    if (confirmBtn) confirmBtn.addEventListener("click", handleConfirm, { once: true });
    modal.show();
  });
}

// Remove invalid class on input
document.addEventListener("input", function (e) {
  if (e.target.classList.contains("is-invalid")) {
    e.target.classList.remove("is-invalid");
  }
});

function exportToPDF() {
  showNotification("Exporting to PDF...", "info");

  setTimeout(() => {
    showNotification("PDF exported successfully!", "success");
  }, 1500);
}

function exportToExcel() {
  showNotification("Exporting to Excel...", "info");

  setTimeout(() => {
    showNotification("Excel file exported successfully!", "success");
  }, 1500);
}

// Intersection Observer for scroll animations
const observerOptions = {
  threshold: 0.1,
  rootMargin: "0px 0px -50px 0px",
};

const observer = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add("animate-fade-in");
      observer.unobserve(entry.target);
    }
  });
}, observerOptions);

// Observe elements with animation class
document.addEventListener("DOMContentLoaded", () => {
  const animatedElements = document.querySelectorAll(".animate-on-scroll");
  animatedElements.forEach((el) => observer.observe(el));
});

// ============================================
// UTILITY FUNCTIONS
// ============================================

function capitalizeFirst(string) {
  return string.charAt(0).toUpperCase() + string.slice(1);
}




