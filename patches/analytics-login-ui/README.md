# Analytics login UI aligned with app.robomap.ai

Restyles `analytics.robomap.ai/login` (and signup / oauth-callback) to match the main app auth format:

- Inter typography
- Dark `#1a1a1a` card on analytics app page background (`#f7f7f7` / `#141414`)
- Form first, then “or continue with”, then Google / Apple
- Same copy strings as main app (`AUTH_LOGIN`)
- Theme toggle in the top-right utilities area

SSO wiring is unchanged (`app=analytics` via main API).

## Deploy

```bash
TAG=login-ui-$(date -u +%Y%m%d%H%M%S)
az acr build -r robomap -t analytics-proxy:$TAG /workspace/patches/analytics-login-ui/analytics-proxy
az containerapp update -g application -n analytics-proxy \
  --image robomap.azurecr.io/analytics-proxy:$TAG
# Ensure 100% traffic on the new revision
```
