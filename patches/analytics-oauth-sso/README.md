# Analytics Google + Apple sign-in (shared Robomap users)

## Goal
Add Google and Apple buttons on `analytics.robomap.ai/login` (and signup) that authenticate through the **main Robomap API**, so Analytics sessions map to the same accounts as `app.robomap.ai`.

## Flow
1. Login UI → `https://api.robomap.ai/auth/{google|apple}?app=analytics&returnUrl=...`
2. Main API completes OAuth against existing Google/Apple apps (same redirect URIs)
3. Redirect → `https://analytics.robomap.ai/auth/oauth-callback?access_token=...`
4. Callback page POSTs token to `/api/robomap-sso`
5. Analytics backend validates token via `GET /auth/oauth/session` on the main API, finds/creates the Analytics user by email, links a `robomap` account, and sets a Better Auth session cookie

## Deployed images / revisions
| App | Image | Revision |
|-----|-------|----------|
| robomap-backend | `robomap.azurecr.io/robomap-backend:analytics-oauth-20260725002742` | `robomap-backend--0000358` |
| analytics-proxy | `robomap.azurecr.io/analytics-proxy:oauth-sso-20260725002742` | `analytics-proxy--0000008` |
| analytics-backend | `robomap.azurecr.io/analytics-backend:oauth-sso-20260725010407` | `analytics-backend--0000031` |

## Env
- `APP_ANALYTICS_URL=https://analytics.robomap.ai` on robomap-backend
- `ROBOMAP_API_URL=https://api.robomap.ai` on analytics-backend
- `CLUSTER_WORKERS=0` on analytics-backend (ClickHouse currently unavailable; init is timed out and non-fatal so auth can serve)

## Verified
- Login shows Continue with Google / Continue with Apple
- `/auth/oauth-callback` serves the bridge page
- `api.robomap.ai/auth/google?app=analytics` state includes `"app":"analytics"`
- `POST /api/robomap-sso` with bad token → 401 `Invalid or expired Robomap session token`
