(() => {
  const root = document.documentElement;
  const KEY = "kiosk_fs_done_session_v1";

  // session flag (per tab/session)
  const getDone = () => {
    try { return sessionStorage.getItem(KEY) === "1"; } catch { return false; }
  };
  const setDone = () => {
    try { sessionStorage.setItem(KEY, "1"); } catch {}
  };

  const isFs = () =>
    document.fullscreenElement ||
    document.webkitFullscreenElement ||
    document.msFullscreenElement;

  const requestFs = () => {
    const fn =
      root.requestFullscreen ||
      root.webkitRequestFullscreen ||
      root.msRequestFullscreen;
    if (!fn) return Promise.reject(new Error("Fullscreen not supported"));
    return fn.call(root);
  };

  // Inject UI once per page
  function injectUI() {
    if (document.getElementById("fsOverlay")) return;

    const fsBtn = document.createElement("button");
    fsBtn.id = "fsBtn";
    fsBtn.textContent = "FULL SCREEN";
    fsBtn.style.cssText = `
      position:fixed; right:14px; top:14px; z-index:9999;
      padding:12px 16px; border:0; border-radius:12px;
      font-weight:700; cursor:pointer; display:none;
    `;

    const overlay = document.createElement("div");
    overlay.id = "fsOverlay";
    overlay.style.cssText = `
      position:fixed; inset:0; z-index:9998;
      display:none; align-items:center; justify-content:center;
      background:rgba(0,0,0,.55); backdrop-filter: blur(2px);
      text-align:center; padding:24px;
    `;

    const overlayBtn = document.createElement("button");
    overlayBtn.id = "fsOverlayBtn";
    overlayBtn.textContent = "TAP TO GO FULLSCREEN";
    overlayBtn.style.cssText = `
      padding:18px 22px; border:0; border-radius:16px;
      font-weight:800; font-size:18px; cursor:pointer;
    `;

    overlay.appendChild(overlayBtn);
    document.body.appendChild(fsBtn);
    document.body.appendChild(overlay);

    return { fsBtn, overlay, overlayBtn };
  }

  function hideUI() {
    const fsBtn = document.getElementById("fsBtn");
    const overlay = document.getElementById("fsOverlay");
    if (overlay) overlay.style.display = "none";
    if (fsBtn) fsBtn.style.display = "none";
  }

  function showUIOncePerSession() {
    if (getDone()) return hideUI();
    const fsBtn = document.getElementById("fsBtn");
    const overlay = document.getElementById("fsOverlay");
    if (overlay) overlay.style.display = "flex";
    if (fsBtn) fsBtn.style.display = "block";
  }

  async function ensureFs() {
    if (isFs()) { setDone(); hideUI(); return true; }

    try {
      await requestFs();
      setDone();
      hideUI();
      return true;
    } catch {
      // show overlay ONLY if not yet done in this session
      if (!getDone()) showUIOncePerSession();
      else hideUI();
      return false;
    }
  }

  function sameOrigin(href) {
    try {
      const u = new URL(href, location.href);
      return u.origin === location.origin;
    } catch {
      return false;
    }
  }

  function init() {
    const ui = injectUI();
    if (!ui) return;

    // click overlay/button -> fullscreen
    ui.fsBtn.addEventListener("click", ensureFs);
    ui.overlayBtn.addEventListener("click", ensureFs);

    // If already done this session, never show overlay again
    if (getDone()) hideUI();

    // Best-effort auto try on load (may be blocked; ok)
    ensureFs();

    // Any tap/click/keypress triggers fullscreen attempt (until success)
    const trigger = async () => { if (!isFs()) await ensureFs(); };
    document.addEventListener("pointerdown", trigger, { capture: true });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " " ) trigger();
    }, { capture: true });

    // IMPORTANT: Intercept SAME-ORIGIN link clicks so that click counts as user gesture.
    document.addEventListener("click", async (e) => {
      const a = e.target.closest("a[href]");
      if (!a) return;
      if (!sameOrigin(a.href)) return; // only your sub-pages
      if (a.target && a.target !== "_self") return;

      if (!isFs()) {
        // try fullscreen using the same click gesture
        e.preventDefault();
        await ensureFs();
        // continue navigation regardless (fullscreen will be re-applied on next page)
        location.href = a.href;
      }
    }, true);

    // If fullscreen becomes active anytime -> hide forever (this session)
    ["fullscreenchange", "webkitfullscreenchange", "MSFullscreenChange"].forEach(ev => {
      document.addEventListener(ev, () => {
        if (isFs()) { setDone(); hideUI(); }
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();