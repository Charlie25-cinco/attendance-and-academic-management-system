/* Local-data ownership only. Server authorization remains mandatory for every sync. */
(function (global) {
  "use strict";
  const documentAccount = global.APP_DOCUMENT_ACCOUNT || "";
  const base = new URL("../../", document.currentScript.src).pathname.replace(/\/$/, "");
  const markerKey = "bshs_active_account";
  let loggingOut = false;

  function currentAccount() {
    const entry = document.cookie.split(";").map(v => v.trim()).find(v => v.startsWith("app_offline_account="));
    if (!entry) return "";
    try {
      const [account, until] = decodeURIComponent(entry.substring(entry.indexOf("=") + 1)).split("|");
      return /^(principal|admin|teacher|student|parent):[1-9]\d*$/.test(account) && Number(until) * 1000 > Date.now() ? account : "";
    } catch (_) { return ""; }
  }

  function guardDocument() {
    if (loggingOut) return false;
    if (documentAccount && currentAccount() !== documentAccount) {
      document.documentElement.style.visibility = "hidden";
      global.location.replace(base + "/auth/login.php");
      return false;
    }
    return true;
  }

  function publishAccount() {
    try { localStorage.setItem(markerKey, currentAccount()); } catch (_) {}
    guardDocument();
  }

  async function lock() {
    loggingOut = true;
    document.documentElement.style.visibility = "hidden";
    document.cookie = "app_offline_account=; Path=/; Max-Age=0; SameSite=Lax";
    try {
      localStorage.setItem(markerKey, "");
      localStorage.removeItem("bshs_cached_teacher");
      localStorage.removeItem("bshs_teacher_session");
    } catch (_) {}
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({ type: "LOCK_OFFLINE" });
    }
    if (global.caches) {
      const keys = await caches.keys();
      await Promise.all(keys.filter(k => k.startsWith("bshs-ams-") && k.includes("-user-")).map(k => caches.delete(k)));
    }
  }

  global.BSHS_OfflineIdentity = { currentAccount, lock, guardDocument, base };
  publishAccount();
  global.addEventListener("storage", e => { if (e.key === markerKey) guardDocument(); });
  global.addEventListener("pageshow", guardDocument);
  document.addEventListener("visibilitychange", guardDocument);
  // Cookie expiry and direct logout in another tab do not emit storage events.
  if (documentAccount) global.setInterval(guardDocument, 1000);
})(window);
