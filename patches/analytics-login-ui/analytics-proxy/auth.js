window.AuthUI = (() => {
  const THEME_KEY = "robomap-auth-theme";
  const ROBOMAP_API = "https://api.robomap.ai";

  function initTheme() {
    const saved = localStorage.getItem(THEME_KEY);
    const theme = saved || (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    document.documentElement.setAttribute("data-theme", theme);
    document.querySelectorAll("[data-theme-btn]").forEach((btn) => {
      btn.classList.toggle("active", btn.getAttribute("data-theme-btn") === theme);
      btn.addEventListener("click", () => {
        const next = btn.getAttribute("data-theme-btn");
        document.documentElement.setAttribute("data-theme", next);
        localStorage.setItem(THEME_KEY, next);
        document.querySelectorAll("[data-theme-btn]").forEach((b) => {
          b.classList.toggle("active", b.getAttribute("data-theme-btn") === next);
        });
      });
    });
  }

  function bindPasswordToggles() {
    document.querySelectorAll("[data-toggle-password]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-toggle-password");
        const input = document.getElementById(id);
        if (!input) return;
        input.type = input.type === "password" ? "text" : "password";
      });
    });
  }

  function redirectTarget() {
    const params = new URLSearchParams(location.search);
    const raw = params.get("redirect") || params.get("next") || params.get("callbackURL") || params.get("returnUrl");
    if (!raw) return null;
    if (raw.startsWith("/") && !raw.startsWith("//") && !raw.startsWith("/auth/")) return raw;
    return null;
  }

  async function parseError(res) {
    try {
      const data = await res.json();
      return data.message || data.error || data.code || res.statusText || "Request failed";
    } catch {
      return res.statusText || "Request failed";
    }
  }

  async function signIn(email, password, rememberMe) {
    const res = await fetch("/api/auth/sign-in/email", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password, rememberMe: !!rememberMe }),
    });
    if (!res.ok) throw new Error(await parseError(res));
    return res.json();
  }

  async function signUp(email, password, name) {
    const res = await fetch("/api/auth/sign-up/email", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password, name }),
    });
    if (!res.ok) throw new Error(await parseError(res));
    return res.json();
  }

  function startSocial(provider) {
    const returnUrl = redirectTarget() || "/";
    const url = new URL(`${ROBOMAP_API}/auth/${provider}`);
    url.searchParams.set("app", "analytics");
    url.searchParams.set("returnUrl", returnUrl);
    location.href = url.toString();
  }

  async function completeRobomapSso(accessToken, returnUrl) {
    const res = await fetch("/api/robomap-sso", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        access_token: accessToken,
        returnUrl: returnUrl || "/",
      }),
    });
    if (!res.ok) throw new Error(await parseError(res));
    return res.json();
  }

  function showOAuthError() {
    const params = new URLSearchParams(location.search);
    const err = params.get("error");
    if (!err) return;
    const el = document.getElementById("error");
    if (!el) return;
    const messages = {
      google_auth_failed: "Google sign-in failed. Please try again.",
      apple_auth_failed: "Apple sign-in failed. Please try again.",
      google_not_configured: "Google sign-in is not configured.",
      apple_not_configured: "Apple sign-in is not configured.",
    };
    el.textContent = messages[err] || "Sign-in failed. Please try again.";
    el.classList.add("show");
  }

  function bindSocialButtons() {
    document.querySelectorAll("[data-social]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const provider = btn.getAttribute("data-social");
        if (provider === "google" || provider === "apple") {
          btn.disabled = true;
          startSocial(provider);
        }
      });
    });
  }

  return {
    initTheme,
    bindPasswordToggles,
    bindSocialButtons,
    redirectTarget,
    signIn,
    signUp,
    startSocial,
    completeRobomapSso,
    showOAuthError,
  };
})();
