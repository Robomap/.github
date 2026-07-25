# Analytics login → dashboard redirect

After sign-in (email/password or Google/Apple SSO), analytics now lands on the
site dashboard (`/{siteId}/main`) instead of the home/properties page (`/`).

Resolution order:
1. Explicit `redirect` / `next` / `callbackURL` / `returnUrl` query param (if not `/`)
2. Most-active site in the active (or first) organization → `/{siteId}/main`
3. Fallback `/` when the user has no sites yet

Patch: `analytics-proxy` only.
