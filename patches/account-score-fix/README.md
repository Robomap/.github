# Account page score fix

## Problem
Inserting an `account-hero__score` DOM node into the compiled Ivy template broke account profile rendering (stale `bMT` / nested const indexes).

## Fix (deployed)
Restored the known-good `app-user-profile` template (pre-score DOM) and show score with a star on the username line instead:

- `@username · ★ 5.00` via `getUsernameScoreLine()`
- No extra Ivy nodes / const shifts

## Deploy
- Image: `robomap.azurecr.io/robomap-frontend:account-restore-20260725000432`
- Revision: `robomap-frontend--0000355` at 100% traffic
