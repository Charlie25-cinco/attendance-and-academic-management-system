/* ============================================
   Attendance and Academic Management System
   Main JavaScript File
   ============================================ */

// ============================================
// SIDEBAR FUNCTIONS
// ============================================

let appTopProgressTimer = null;
let appTopProgressHideTimer = null;
let appTopProgressInterval = null;
let appTopProgressValue = 0;

function ensureTopProgressBar() {
  let bar = document.getElementById("appTopProgress");
  if (bar) return bar;

  bar = document.createElement("div");
  bar.id = "appTopProgress";
  bar.className = "app-top-progress";
  bar.setAttribute("aria-hidden", "true");
  document.body.appendChild(bar);
  return bar;
}

function markNavigationStarted() {
  try {
    sessionStorage.setItem("app_page_navigating", "true");
    sessionStorage.setItem("app_nav_time", String(Date.now()));
  } catch (e) {}
}

function clearNavigationState() {
  try {
    sessionStorage.removeItem("app_page_navigating");
    sessionStorage.removeItem("app_nav_time");
  } catch (e) {}
}

function isNavigationInProgress() {
  try {
    const isNav = sessionStorage.getItem("app_page_navigating") === "true";
    const navTime = parseInt(sessionStorage.getItem("app_nav_time") || "0", 10);
    if (isNav && Date.now() - navTime < 10000) {
      return true;
    }
  } catch (e) {}
  return false;
}

function showTopProgress(startValue = 35) {
  const bar = ensureTopProgressBar();

  if (appTopProgressHideTimer) {
    window.clearTimeout(appTopProgressHideTimer);
    appTopProgressHideTimer = null;
  }

  if (appTopProgressInterval) {
    window.clearInterval(appTopProgressInterval);
  }

  if (appTopProgressTimer) {
    window.clearTimeout(appTopProgressTimer);
    appTopProgressTimer = null;
  }

  appTopProgressValue = startValue;
  bar.style.setProperty("--app-progress", appTopProgressValue + "vw");
  bar.style.width = appTopProgressValue + "vw";
  bar.classList.remove("is-finishing");
  bar.classList.add("is-visible");

  appTopProgressInterval = window.setInterval(() => {
    const remaining = 92 - appTopProgressValue;
    if (remaining <= 0.5) return;
    appTopProgressValue += Math.max(0.4, remaining * 0.12);
    bar.style.setProperty(
      "--app-progress",
      Math.min(appTopProgressValue, 92) + "vw",
    );
    bar.style.width = Math.min(appTopProgressValue, 92) + "vw";
  }, 140);
}

function finishTopProgress() {
  const bar = ensureTopProgressBar();

  if (appTopProgressInterval) {
    window.clearInterval(appTopProgressInterval);
    appTopProgressInterval = null;
  }

  if (appTopProgressTimer) {
    window.clearTimeout(appTopProgressTimer);
    appTopProgressTimer = null;
  }

  if (appTopProgressHideTimer) {
    window.clearTimeout(appTopProgressHideTimer);
    appTopProgressHideTimer = null;
  }

  const wasNavigating = isNavigationInProgress();
  clearNavigationState();

  // If page was reached via navigation, start bar at 75vw on new page load so it is visibly present
  if (wasNavigating || appTopProgressValue < 10) {
    appTopProgressValue = Math.max(appTopProgressValue, 75);
    bar.style.setProperty("--app-progress", appTopProgressValue + "vw");
    bar.style.width = appTopProgressValue + "vw";
    bar.classList.add("is-visible");
    bar.classList.remove("is-finishing");
  }

  // Animate width to 100vw (100% viewport width, touching right screen edge)
  window.setTimeout(() => {
    appTopProgressValue = 100;
    bar.style.setProperty("--app-progress", "100vw");
    bar.style.width = "100vw";
    bar.classList.add("is-visible");
    bar.classList.remove("is-finishing");

    // Hold at 100vw full width (touching right edge) for 320ms before starting fade out
    appTopProgressHideTimer = window.setTimeout(() => {
      bar.classList.add("is-finishing");
      window.setTimeout(() => {
        bar.classList.remove("is-visible", "is-finishing");
        bar.style.setProperty("--app-progress", "0vw");
        bar.style.width = "0vw";
        appTopProgressValue = 0;
      }, 320);
    }, 320);
  }, 30);
}

function showTopProgressSoon(delay = 0) {
  showTopProgress();
}

function cancelTopProgressSoon() {
  if (!appTopProgressTimer) return;
  window.clearTimeout(appTopProgressTimer);
  appTopProgressTimer = null;
}

function shouldShowNavigationProgress(link) {
  if (!link) return false;
  const href = link.getAttribute("href");
  if (!href || href.startsWith("#") || href.startsWith("javascript:"))
    return false;
  if (link.hasAttribute("download")) return false;
  if ((link.target || "").toLowerCase() === "_blank") return false;
  if (link.getAttribute("data-skip-loader") === "true") return false;

  try {
    const targetUrl = new URL(link.href, window.location.href);
    if (targetUrl.origin !== window.location.origin) return false;
    if (
      targetUrl.pathname === window.location.pathname &&
      targetUrl.search === window.location.search &&
      targetUrl.hash !== ""
    ) {
      return false;
    }
  } catch (error) {
    return false;
  }

  return true;
}

function initNavigationProgress() {
  const completeHandler = function () {
    finishTopProgress();
  };

  if (
    document.readyState === "complete" ||
    document.readyState === "interactive"
  ) {
    finishTopProgress();
  } else {
    document.addEventListener("DOMContentLoaded", completeHandler);
  }
  window.addEventListener("pageshow", completeHandler);

  window.addEventListener("beforeunload", function () {
    if (window.APP_SUPPRESS_NEXT_UNLOAD_PROGRESS === true) {
      window.APP_SUPPRESS_NEXT_UNLOAD_PROGRESS = false;
      return;
    }
    markNavigationStarted();
    showTopProgress(30);
  });

  document.addEventListener("click", function (event) {
    const link = event.target.closest("a[href]");
    if (!shouldShowNavigationProgress(link)) return;
    if (
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    markNavigationStarted();
    showTopProgress(30);
  });

  document.addEventListener("submit", function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.getAttribute("data-skip-loader") === "true") return;
    if ((form.getAttribute("method") || "get").toLowerCase() === "dialog")
      return;
    markNavigationStarted();
    showTopProgress(30);
  });
}

function initLogicalSecurityGuards() {
  const dirtyForms = new Set();
  const sensitiveSelector = "form:not([data-skip-logical-security='true'])";

  function isSensitiveForm(form) {
    if (!(form instanceof HTMLFormElement)) return false;
    if (!form.matches(sensitiveSelector)) return false;
    const method = (form.getAttribute("method") || "get").toLowerCase();
    return method !== "get" && method !== "dialog";
  }

  function dirtySensitiveForms() {
    return Array.from(dirtyForms).filter(
      (form) => form.isConnected && isSensitiveForm(form),
    );
  }

  function hasDirtySensitiveForm() {
    return dirtySensitiveForms().length > 0;
  }

  function markFormDirty(target) {
    const field =
      target && target.closest
        ? target.closest("input, select, textarea")
        : null;
    if (!field || field.type === "hidden") return;
    const form = field.form;
    if (isSensitiveForm(form)) {
      dirtyForms.add(form);
    }
  }

  document.addEventListener(
    "input",
    function (event) {
      markFormDirty(event.target);
    },
    true,
  );

  document.addEventListener(
    "change",
    function (event) {
      markFormDirty(event.target);
    },
    true,
  );

  document.addEventListener(
    "submit",
    function (event) {
      if (event.target instanceof HTMLFormElement) {
        dirtyForms.delete(event.target);
      }
    },
    true,
  );

  window.addEventListener("beforeunload", function (event) {
    if (!hasDirtySensitiveForm()) return;
    event.preventDefault();
    event.returnValue = "";
  });

  document.addEventListener(
    "keydown",
    function (event) {
      const key = (event.key || "").toLowerCase();
      const refreshShortcut =
        key === "f5" || ((event.ctrlKey || event.metaKey) && key === "r");
      if (!refreshShortcut || !hasDirtySensitiveForm()) return;

      event.preventDefault();
      if (typeof showNotification === "function") {
        showNotification(
          "Save or clear the form before refreshing this page.",
          "warning",
        );
      }
    },
    true,
  );

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden || !hasDirtySensitiveForm()) return;
    try {
      sessionStorage.setItem("app_unsaved_sensitive_form", "1");
    } catch (e) {}
  });
}

// Toggle sidebar collapse (desktop)
function toggleSidebar() {
  const sidebar = document.getElementById("sidebar");
  const toggleIcon = document.getElementById("toggleIcon");
  if (!sidebar) return;

  sidebar.classList.toggle("collapsed");
  const isCollapsed = sidebar.classList.contains("collapsed");

  if (toggleIcon) {
    if (isCollapsed) {
      toggleIcon.classList.remove("bi-chevron-left");
      toggleIcon.classList.add("bi-chevron-right");
    } else {
      toggleIcon.classList.remove("bi-chevron-right");
      toggleIcon.classList.add("bi-chevron-left");
    }
  }

  try {
    localStorage.setItem("sidebarCollapsed", isCollapsed ? "true" : "false");
  } catch (e) {}
}
window.toggleSidebar = toggleSidebar;

// Open mobile sidebar
function openMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("mobileOverlay");
  if (!sidebar || !overlay) return;

  sidebar.classList.add("mobile-open");
  overlay.classList.add("active");
  document.body.style.overflow = "hidden";
}
window.openMobileSidebar = openMobileSidebar;

// Close mobile sidebar
function closeMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("mobileOverlay");
  if (!sidebar || !overlay) return;

  sidebar.classList.remove("mobile-open");
  overlay.classList.remove("active");
  document.body.style.overflow = "";
}
window.closeMobileSidebar = closeMobileSidebar;

// Restore sidebar state on page load
document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const toggleIcon = document.getElementById("toggleIcon");
  const toggleBtn = document.querySelector(".sidebar-toggle");

  if (toggleBtn) {
    toggleBtn.addEventListener("click", function (e) {
      e.preventDefault();
      toggleSidebar();
    });
  }

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

  // Show a slim, non-blocking page progress indicator during navigation
  initNavigationProgress();

  // Guard sensitive forms from accidental refresh or app switching data loss
  initLogicalSecurityGuards();

  // Focus the first usable field when modal forms open
  initModalAutofocus();

  // Initialize shared settings and PWA push notification controls
  if (document.getElementById("saveSettingsBtn")) {
    initSettingsControls();
  }
  initHeaderNotificationActions();
  focusNotificationTarget();
  initLiveNotificationPoller();
  window.setTimeout(initPwaPushAutoRegistration, 1200);
  window.setTimeout(initPwaPushFirstOpenPrompt, 1500);
});

// ============================================
// MODAL FORM AUTOFOCUS
// ============================================

function isFocusableFormField(field) {
  if (!field) return false;
  if (field.disabled || field.readOnly) return false;
  if (
    field.matches(
      '[type="hidden"], [tabindex="-1"], [data-autofocus-skip="true"]',
    )
  )
    return false;
  if (field.offsetParent === null && field.getClientRects().length === 0)
    return false;
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
    if (
      typeof firstField.select === "function" &&
      firstField.matches(
        "input[type='text'], input[type='email'], input[type='tel'], input[type='search'], input:not([type])",
      )
    ) {
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

// Save attendance
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
      showNotification("Attendance saved successfully!", "success");

      setTimeout(() => {
        btn.innerHTML = '<i class="bi bi-save me-2"></i>Save Attendance';
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

window.escapeHtml = escapeHtml;
window.escHtml = escapeHtml;
window.esc = escapeHtml;

function programLabel(row) {
  if (row.program === "academic_strengthened")
    return "Academic Track (Strengthened)";
  if (row.program === "technical_professional") return "Technical Professional";
  if (row.track === "techpro") return "TechPro";
  if (row.track === "academic") return "Academic";
  return "";
}
window.programLabel = programLabel;

function appendCsrfToFormData(fd) {
  const token =
    typeof csrfToken !== "undefined" && csrfToken
      ? csrfToken
      : window.APP_CSRF_TOKEN || "";
  if (fd && token) {
    fd.set("csrf_token", token);
  }
  return fd;
}
window.appendCsrfToFormData = appendCsrfToFormData;

function withCsrfUrl(url) {
  if (typeof csrfToken === "undefined" || !csrfToken) return url;
  return (
    url +
    (url.includes("?") ? "&" : "?") +
    "csrf_token=" +
    encodeURIComponent(csrfToken)
  );
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

  return fetch(
    appApiUrl(route),
    Object.assign({}, options, {
      headers,
      credentials: "same-origin",
    }),
  ).then((response) =>
    response.json().then((data) => {
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Request failed");
      }
      return data;
    }),
  );
}

function updateNotificationState(action, id = 0) {
  return appFetchJson("notification-action", {
    method: "POST",
    body: JSON.stringify({ action, id }),
  });
}

function setHeaderNotificationCount(count) {
  const bell = document.querySelector('[aria-label="View notifications"]');
  if (!bell) return;
  let badge = document.getElementById("headerNotificationBadge");
  const unread = Math.max(0, Number.parseInt(count || "0", 10) || 0);
  if (unread === 0) {
    if (badge) badge.remove();
    return;
  }
  if (!badge) {
    badge = document.createElement("span");
    badge.id = "headerNotificationBadge";
    badge.className = "notification-badge";
    bell.appendChild(badge);
  }
  badge.textContent = String(unread);
}

function focusNotificationTarget() {
  if (!window.location.hash.startsWith("#notification-")) return;
  const target = document.getElementById(window.location.hash.slice(1));
  if (!target) return;

  ["announcementSearch"].forEach((id) => {
    const input = document.getElementById(id);
    if (input) input.value = "";
  });
  ["announcementSourceFilter", "announcementCategoryFilter"].forEach((id) => {
    const select = document.getElementById(id);
    if (select) select.value = "all";
  });
  target.style.display = "";
  const wrapper = target.closest(".col-md-6");
  if (wrapper) wrapper.style.display = "";
  target.classList.add("notification-target-highlight");
  target.setAttribute("tabindex", "-1");
  window.setTimeout(() => {
    target.scrollIntoView({
      behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
        ? "auto"
        : "smooth",
      block: "center",
    });
    target.focus({ preventScroll: true });
  }, 120);
}

function initHeaderNotificationActions() {
  document
    .querySelectorAll(".header-notification-item[data-notification-id]")
    .forEach((item) => {
      item.addEventListener("click", (event) => {
        const id = Number.parseInt(item.dataset.notificationId || "0", 10);
        const href = item.getAttribute("href") || "";
        if (id <= 0) return;
        event.preventDefault();
        updateNotificationState("read", id)
          .then((data) => {
            setHeaderNotificationCount(data.unread_count);
            if (href && href !== "#") window.location.href = href;
            else window.location.reload();
          })
          .catch((error) =>
            showNotification(
              error.message || "Unable to open notification",
              "danger",
            ),
          );
      });
    });

  document
    .querySelectorAll(".delete-notification-btn[data-notification-id]")
    .forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        const id = Number.parseInt(button.dataset.notificationId || "0", 10);
        if (id <= 0) return;
        button.disabled = true;
        updateNotificationState("delete", id)
          .then((data) => {
            setHeaderNotificationCount(data.unread_count);
            button.closest("[data-notification-row]")?.remove();
          })
          .catch((error) => {
            button.disabled = false;
            showNotification(
              error.message || "Unable to delete notification",
              "danger",
            );
          });
      });
    });

  const readAll = document.getElementById("markAllNotificationsRead");
  if (readAll) {
    readAll.addEventListener("click", (event) => {
      event.preventDefault();
      updateNotificationState("read_all")
        .then((data) => {
          setHeaderNotificationCount(data.unread_count);
          document
            .querySelectorAll(".header-notification-item")
            .forEach((item) => {
              item.closest(".dropdown-item")?.classList.add("opacity-75");
            });
        })
        .catch((error) =>
          showNotification(
            error.message || "Unable to update notifications",
            "danger",
          ),
        );
    });
  }

  const deleteAll = document.getElementById("deleteAllNotifications");
  if (deleteAll) {
    deleteAll.addEventListener("click", (event) => {
      event.preventDefault();
      updateNotificationState("delete_all")
        .then((data) => {
          setHeaderNotificationCount(data.unread_count);
          document
            .querySelectorAll("[data-notification-row]")
            .forEach((row) => row.remove());
        })
        .catch((error) =>
          showNotification(
            error.message || "Unable to delete notifications",
            "danger",
          ),
        );
    });
  }
}

let lastKnownNotificationIds = null;

function renderLiveNotifications(items, unreadCount) {
  setHeaderNotificationCount(unreadCount);
  const scrollContainer = document.querySelector(
    ".header-notification-scroll-body",
  );
  if (!scrollContainer) return;

  if (!items || items.length === 0) {
    scrollContainer.innerHTML =
      '<div class="dropdown-item text-muted py-3 text-center">No new notifications</div>';
    return;
  }

  const html = items
    .map((item) => {
      const isRead = Number(item.is_read || 0) === 1;
      const color = escapeHtml(item.color || "primary");
      const icon = escapeHtml(item.icon || "bi-bell");
      const title = escapeHtml(item.title || "");
      const subtitle = escapeHtml(item.subtitle || "");
      const time = escapeHtml(item.time || "");
      const link = escapeHtml(item.link || "#");
      const id = Number(item.id || 0);

      return `
      <div data-notification-row="${id}">
        <div class="dropdown-item d-flex align-items-start py-2 ${isRead ? "opacity-75" : ""}">
          <a class="header-notification-item d-flex align-items-start text-decoration-none text-reset flex-grow-1" href="${link}" data-notification-id="${id}">
            <div class="header-notification-icon bg-${color} bg-opacity-10 rounded-circle p-2 me-3 flex-shrink-0">
              <i class="bi ${icon} text-${color}"></i>
            </div>
            <div class="header-notification-body flex-grow-1 min-w-0">
              <p class="mb-0 fw-medium notification-title" style="font-size: 14px;">${title}</p>
              <p class="mb-0 text-muted notification-subtitle" style="font-size: 12px;">${subtitle}</p>
              <small class="text-muted header-notification-time">${time}</small>
            </div>
          </a>
          <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2 delete-notification-btn" title="Delete" data-notification-id="${id}">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    `;
    })
    .join("");

  scrollContainer.innerHTML = html;
  initHeaderNotificationActions();
}

function pollNotificationsLive() {
  if (document.hidden) return;
  appFetchJson("notifications")
    .then((data) => {
      if (!data || !data.ok) return;
      const currentIds = new Set((data.items || []).map((it) => it.id));
      const unreadCount = Number(data.unread_count || 0);

      if (lastKnownNotificationIds !== null) {
        const newUnread = (data.items || []).filter(
          (it) =>
            !lastKnownNotificationIds.has(it.id) &&
            Number(it.is_read || 0) === 0,
        );
        if (newUnread.length > 0) {
          const newest = newUnread[0];
          showNotification(`${newest.title}: ${newest.subtitle}`, "info");
          window.dispatchEvent(
            new CustomEvent("ams:notificationReceived", {
              detail: { items: data.items, newCount: newUnread.length },
            }),
          );
        }
      }
      lastKnownNotificationIds = currentIds;
      renderLiveNotifications(data.items, unreadCount);
    })
    .catch(() => {});
}

function initLiveNotificationPoller() {
  if (
    typeof window === "undefined" ||
    !document.querySelector('[aria-label="View notifications"]')
  )
    return;
  const initialRows = document.querySelectorAll("[data-notification-row]");
  lastKnownNotificationIds = new Set(
    Array.from(initialRows).map((r) =>
      Number(r.getAttribute("data-notification-row") || "0"),
    ),
  );
  window.setInterval(pollNotificationsLive, 15000);
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

function setPushPermissionState(state, message = "") {
  const actions = document.getElementById("pushPermissionActions");
  const guidance = document.getElementById("pushPermissionGuidance");
  if (actions) actions.classList.toggle("d-none", state !== "prompt");
  if (guidance) {
    guidance.textContent = message;
    guidance.className = `settings-option-desc d-block mt-2${state === "blocked" ? " text-danger" : ""}`;
  }
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

function isInstalledPwa() {
  if (typeof window === "undefined") return false;
  return (
    (window.matchMedia &&
      (window.matchMedia("(display-mode: standalone)").matches ||
        window.matchMedia("(display-mode: fullscreen)").matches ||
        window.matchMedia("(display-mode: minimal-ui)").matches)) ||
    (typeof navigator !== "undefined" && Boolean(navigator.standalone)) ||
    (typeof document !== "undefined" &&
      typeof document.referrer === "string" &&
      document.referrer.includes("android-app://"))
  );
}

function pwaPushSupported() {
  return (
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window &&
    !!window.APP_PUSH_PUBLIC_KEY
  );
}

function getPwaServiceWorkerRegistration() {
  if (!("serviceWorker" in navigator)) {
    return Promise.reject(
      new Error("Service worker is not supported on this device"),
    );
  }
  if (window._swRegistration) {
    return Promise.resolve(window._swRegistration);
  }
  return navigator.serviceWorker.ready;
}

function updatePwaPushStatus() {
  const pushSwitch = document.getElementById("pushNotifSwitch");
  if (!pushSwitch) return;
  setPushPermissionState("configuring");

  appFetchJson("web-push-status")
    .then((serverStatus) => {
      if (!serverStatus.configured || !window.APP_PUSH_PUBLIC_KEY) {
        pushSwitch.disabled = true;
        setPushStatus("Server push keys are not configured.", "danger");
        setPushPermissionState(
          "server",
          "Ask the administrator to configure Web Push for this server.",
        );
        return;
      }
      if (
        !("serviceWorker" in navigator) ||
        !("PushManager" in window) ||
        !("Notification" in window)
      ) {
        pushSwitch.disabled = true;
        setPushStatus(
          "Device notifications are not supported by this browser.",
          "muted",
        );
        setPushPermissionState(
          "unsupported",
          "Use a supported browser or installed PWA on this device.",
        );
        return;
      }
      pushSwitch.disabled = false;

      if (Notification.permission === "denied") {
        setPushStatus("Notifications are blocked in this browser.", "danger");
        setPushPermissionState(
          "blocked",
          "Open this site's browser or Windows app settings and change Notifications to Allow.",
        );
        return;
      }

      if (Notification.permission === "default") {
        setPushStatus(
          "Permission is required before this device can subscribe.",
          "muted",
        );
        setPushPermissionState(
          "prompt",
          "Choose Allow Notifications, then approve the browser permission dialog.",
        );
        return;
      }

      if (Notification.permission === "granted") {
        return getPwaServiceWorkerRegistration()
          .then((registration) => registration.pushManager.getSubscription())
          .then((subscription) => {
            const ready =
              !!subscription &&
              Number(serverStatus.subscription_count || 0) > 0;
            setPushStatus(
              ready
                ? "This device is ready for notifications."
                : "Save changes to subscribe this device.",
              ready ? "success" : "muted",
            );
            setPushPermissionState(
              ready ? "ready" : "subscribe",
              ready
                ? "You can receive notifications while the PWA is closed."
                : "Keep Push Notifications enabled and save changes to finish subscription.",
            );
          });
      }

      setPushStatus("Save changes to allow device notifications.", "muted");
    })
    .catch((error) => {
      setPushStatus(error.message || "Unable to check push status.", "danger");
      setPushPermissionState(
        "server",
        "The app could not verify notification readiness. Try reopening Settings.",
      );
    });
}

function requestPushPermissionFromSettings() {
  const allowButton = document.getElementById("allowPushPermissionBtn");
  if (!allowButton || !("Notification" in window)) return;
  allowButton.disabled = true;
  Notification.requestPermission()
    .then((permission) => {
      if (permission !== "granted") {
        throw new Error(
          permission === "denied"
            ? "Notifications were blocked"
            : "Notification permission was not granted",
        );
      }
      const pushSwitch = document.getElementById("pushNotifSwitch");
      if (pushSwitch) pushSwitch.checked = true;
      return enablePwaPushNotifications();
    })
    .then(() =>
      showNotification("Notifications enabled for this device", "success"),
    )
    .catch((error) =>
      showNotification(
        error.message || "Unable to enable notifications",
        "danger",
      ),
    )
    .finally(() => {
      allowButton.disabled = false;
      updatePwaPushStatus();
    });
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
    .then((registration) =>
      registration.pushManager.getSubscription().then(
        (existing) =>
          existing ||
          registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(
              window.APP_PUSH_PUBLIC_KEY,
            ),
          }),
      ),
    )
    .then((subscription) =>
      appFetchJson("web-push-subscription", {
        method: "POST",
        body: JSON.stringify({ subscription: subscription.toJSON() }),
      }),
    );
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
      return subscription
        .unsubscribe()
        .catch(() => false)
        .then(() =>
          appFetchJson("web-push-subscription", {
            method: "DELETE",
            body: JSON.stringify({ endpoint }),
          }).catch(() => null),
        );
    });
}

function applyInitialSettingsToControls() {
  const settings = window.APP_INITIAL_SETTINGS || {};
  const darkMode = document.getElementById("darkModeSwitch");
  const pushNotif = document.getElementById("pushNotifSwitch");

  if (darkMode) darkMode.checked = String(settings.dark_mode || "0") === "1";
  if (pushNotif)
    pushNotif.checked = String(settings.push_notifications ?? "1") === "1";
}

function initSettingsControls() {
  const saveBtn = document.getElementById("saveSettingsBtn");
  if (!saveBtn) return;

  applyInitialSettingsToControls();
  updatePwaPushStatus();
  document
    .getElementById("allowPushPermissionBtn")
    ?.addEventListener("click", requestPushPermissionFromSettings);
  document
    .getElementById("deferPushPermissionBtn")
    ?.addEventListener("click", () => {
      const pushSwitch = document.getElementById("pushNotifSwitch");
      if (pushSwitch) pushSwitch.checked = false;
      setPushStatus("Notifications are not enabled on this device.", "muted");
      setPushPermissionState(
        "deferred",
        "You can enable notifications later from Settings.",
      );
    });
  document
    .getElementById("settingsModal")
    ?.addEventListener("shown.bs.modal", updatePwaPushStatus);
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
      push_notifications: pushNotif?.checked ? 1 : 0,
    };
    const permissionStep =
      payload.push_notifications &&
      pwaPushSupported() &&
      Notification.permission !== "granted"
        ? Notification.requestPermission().then((permission) => {
            if (permission !== "granted") {
              throw new Error("Notification permission was not granted");
            }
            return permission;
          })
        : Promise.resolve();

    setSettingsBusy(true);
    permissionStep
      .then(() =>
        appFetchJson("settings", {
          method: "POST",
          body: JSON.stringify(payload),
        }),
      )
      .then(() => {
        if (!payload.push_notifications) {
          return disablePwaPushNotifications();
        }
        return pwaPushSupported()
          ? enablePwaPushNotifications().catch(() => null)
          : Promise.resolve();
      })
      .then(() => {
        if (typeof showNotification === "function") {
          showNotification("Settings saved successfully", "success");
        }
        updatePwaPushStatus();
      })
      .catch((error) => {
        if (typeof showNotification === "function") {
          showNotification(
            error.message || "Failed to save settings",
            "danger",
          );
        }
        updatePwaPushStatus();
      })
      .finally(() => setSettingsBusy(false));
  });
}

function initPwaPushAutoRegistration() {
  const settings = window.APP_INITIAL_SETTINGS || {};
  const pushEnabled = String(settings.push_notifications ?? "1") === "1";
  if (
    !pushEnabled ||
    !pwaPushSupported() ||
    Notification.permission !== "granted"
  ) {
    return;
  }

  enablePwaPushNotifications().catch(() => {
    updatePwaPushStatus();
  });
}

function initPwaPushFirstOpenPrompt() {
  const modalEl = document.getElementById("pushPromptModal");
  if (!modalEl || typeof bootstrap === "undefined" || !bootstrap.Modal) {
    return;
  }

  // Only prompt when running as an installed standalone application on the device
  if (!isInstalledPwa()) {
    return;
  }

  if (!("Notification" in window)) {
    return;
  }

  if (Notification.permission !== "default") {
    return;
  }

  const dismissed = localStorage.getItem("bshs_push_prompt_dismissed");
  const dismissedAt = Number.parseInt(localStorage.getItem("bshs_push_prompt_dismissed_at") || "0", 10);
  if (dismissed === "granted" || dismissed === "denied") {
    return;
  }
  if (dismissed === "later" && Date.now() - dismissedAt < 86400000) {
    return;
  }

  const promptModal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const allowBtn = document.getElementById("pushPromptAllowBtn");
  const denyBtn = document.getElementById("pushPromptDenyBtn");
  const laterBtn = document.getElementById("pushPromptLaterBtn");
  const closeBtn = document.getElementById("pushPromptCloseBtn");

  const handleDismiss = (status) => {
    try {
      localStorage.setItem("bshs_push_prompt_dismissed", status || "later");
      localStorage.setItem("bshs_push_prompt_dismissed_at", Date.now().toString());
    } catch (_) {}
    promptModal.hide();
  };

  if (allowBtn) {
    allowBtn.onclick = function () {
      allowBtn.disabled = true;
      Notification.requestPermission()
        .then((permission) => {
          try {
            localStorage.setItem(
              "bshs_push_prompt_dismissed",
              permission === "granted" ? "granted" : "denied",
            );
          } catch (_) {}
          promptModal.hide();
          if (permission === "granted") {
            const pushSwitch = document.getElementById("pushNotifSwitch");
            if (pushSwitch) pushSwitch.checked = true;
            if (pwaPushSupported()) {
              return enablePwaPushNotifications()
                .then(() => {
                  showNotification(
                    "Notifications enabled for this device",
                    "success",
                  );
                  updatePwaPushStatus();
                })
                .catch((err) => {
                  showNotification(
                    err.message || "Failed to subscribe for notifications",
                    "warning",
                  );
                  updatePwaPushStatus();
                });
            }
            showNotification("Notifications allowed for this device", "success");
          } else {
            showNotification("Notifications denied for this device.", "muted");
            updatePwaPushStatus();
          }
        })
        .catch(() => {
          promptModal.hide();
        })
        .finally(() => {
          allowBtn.disabled = false;
        });
    };
  }

  if (denyBtn) {
    denyBtn.onclick = function () {
      handleDismiss("denied");
      showNotification("Notifications denied for this device.", "muted");
    };
  }

  if (laterBtn) {
    laterBtn.onclick = function () {
      handleDismiss("later");
    };
  }

  if (closeBtn) {
    closeBtn.onclick = function () {
      handleDismiss("closed");
    };
  }

  window.setTimeout(() => {
    if (
      isInstalledPwa() &&
      Notification.permission === "default" &&
      (!dismissed || (dismissed === "later" && Date.now() - dismissedAt >= 86400000))
    ) {
      promptModal.show();
    }
  }, 400);
}

window.showDeviceNotificationPrompt = function () {
  const modalEl = document.getElementById("pushPromptModal");
  if (modalEl && typeof bootstrap !== "undefined" && bootstrap.Modal) {
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }
};

function ensureAppConfirmModal() {
  const modalEl = document.getElementById("appConfirmModal");
  if (!modalEl || typeof bootstrap === "undefined" || !bootstrap.Modal)
    return null;
  return bootstrap.Modal.getOrCreateInstance(modalEl);
}

function showAppConfirm(options = {}) {
  const modalEl = document.getElementById("appConfirmModal");
  const modal = ensureAppConfirmModal();
  if (!modalEl || !modal) {
    return Promise.resolve(
      window.confirm(String(options.message || "Are you sure?")),
    );
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
    if (confirmBtn)
      confirmBtn.addEventListener("click", handleConfirm, { once: true });
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

// ============================================
// PWA & SERVICE WORKER AUTOMATIC FRESHNESS
// ============================================

if ("serviceWorker" in navigator && navigator.onLine) {
  const checkFreshness = () => {
    navigator.serviceWorker
      .getRegistrations()
      .then((regs) => {
        regs.forEach((reg) => {
          reg.update().catch(() => {});
        });
      })
      .catch(() => {});

    if ("caches" in window) {
      caches
        .keys()
        .then((keys) => {
          keys.forEach((key) => {
            if (key.startsWith("bshs-ams-v") && key !== "bshs-ams-v36") {
              caches.delete(key);
            }
          });
        })
        .catch(() => {});
    }
  };

  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(checkFreshness, { timeout: 4000 });
  } else {
    setTimeout(checkFreshness, 2000);
  }
}

// Clear offline cached session on explicit logout
document.addEventListener("click", function (e) {
  const link = e.target.closest('a[href*="logout.php"]');
  if (link) {
    try {
      localStorage.removeItem("bshs_cached_teacher");
      localStorage.removeItem("bshs_teacher_session");
    } catch (e) {}
  }
});

// ============================================
// OFFLINE FEATURE MODAL
// ============================================
function showOfflineModal(featureName) {
  let modalEl = document.getElementById("bshsOfflineFeatureModal");
  if (!modalEl) {
    modalEl = document.createElement("div");
    modalEl.id = "bshsOfflineFeatureModal";
    modalEl.className = "modal fade";
    modalEl.setAttribute("tabindex", "-1");
    modalEl.setAttribute("aria-hidden", "true");
    modalEl.innerHTML = `
      <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
          <div class="modal-body text-center p-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle mb-3" style="width: 60px; height: 60px;">
              <i class="bi bi-wifi-off fs-2"></i>
            </div>
            <h5 class="fw-bold mb-2 text-dark">Connection Required</h5>
            <p class="text-muted small mb-3" id="bshsOfflineModalMessage">
              This feature requires an active internet connection. Offline attendance and grade activities remain available.
            </p>
            <div class="d-grid gap-2">
              <a href="../teacher/teacher_Attendance.php" class="btn btn-primary btn-sm fw-semibold">
                <i class="bi bi-calendar-check-fill me-1"></i>Take Offline Attendance
              </a>
              <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modalEl);
  }

  const msgEl = document.getElementById("bshsOfflineModalMessage");
  if (msgEl) {
    msgEl.textContent = featureName
      ? `"${featureName}" requires an active internet connection. Offline attendance and grade activities remain available.`
      : "This feature requires an active internet connection. Offline attendance and grade activities remain available.";
  }

  if (window.bootstrap && window.bootstrap.Modal) {
    const modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
  } else {
    alert(
      "This feature requires an active internet connection. Offline attendance and grade activities remain available.",
    );
  }
}

// Intercept online-only navigation links when offline
document.addEventListener("click", function (e) {
  if (navigator.onLine) return;
  const link = e.target.closest("a[href]");
  if (!link) return;
  const href = (link.getAttribute("href") || "").trim();
  if (
    !href ||
    href.startsWith("#") ||
    href.startsWith("javascript:") ||
    href === ""
  )
    return;

  // Only intercept navigation links to distinct online-only pages
  const onlineOnlyPages = [
    /teacher_Reports\.php/i,
    /teacher_SF2_Export\.php/i,
    /teacher_SF5_Export\.php/i,
    /teacher_SF9_Export\.php/i,
    /teacher_Chat\.php/i,
    /teacher_Advisory\.php/i,
    /teacher_Announcements\.php/i,
    /admin\//i,
    /student\//i,
    /parent\//i,
    /site\//i,
  ];

  const isOnlineOnly = onlineOnlyPages.some((pattern) => pattern.test(href));
  if (isOnlineOnly) {
    e.preventDefault();
    e.stopPropagation();
    const linkText = (link.textContent || "").trim().replace(/\s+/g, " ");
    showOfflineModal(linkText);
  }
});
