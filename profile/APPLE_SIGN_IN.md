# Sign in with Apple

Production Sign in with Apple for `app.robomap.ai` is served by Azure Container App `robomap-backend` (`api.robomap.ai`).

## GitHub secrets

Store these as repository or organization Actions secrets (never commit them):

| Secret | Purpose |
|--------|---------|
| `APPLE_CLIENT_ID` | Services ID (e.g. `ai.robomap.signin`) |
| `APPLE_TEAM_ID` | Apple Developer Team ID |
| `APPLE_KEY_ID` | Key ID for the Sign in with Apple key |
| `APPLE_PRIVATE_KEY` | `.p8` private key (PEM or bare base64). Newlines may be stored as `\n`. |
| `AZURE_CREDENTIALS` | Azure service principal JSON used by the sync workflow |

`APPLE_CLIENT_SECRET` is **not** required. The API generates a short-lived client-secret JWT from the private key at runtime.

## Sync to Azure

Workflow: `.github/workflows/sync-apple-oauth-secrets.yml`

It writes Container App secrets (`apple-client-id`, `apple-team-id`, `apple-key-id`, `apple-private-key`) and binds them to:

- `APPLE_CLIENT_ID`
- `APPLE_TEAM_ID`
- `APPLE_KEY_ID`
- `APPLE_PRIVATE_KEY`

Redirect URI (also configured in Apple Developer):

`https://api.robomap.ai/auth/apple/callback`

## App routes

- Login / signup buttons → `GET https://api.robomap.ai/auth/apple`
- Apple callback (form_post) → `POST https://api.robomap.ai/auth/apple/callback`
- SPA hand-off → `https://app.robomap.ai/auth/oauth-callback?provider=apple&access_token=…` (`registered=1` for new accounts)
