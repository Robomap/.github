# Account page restore (menus + score)

## Problem
Score-row Ivy patches broke account profile rendering. A later partial restore still left the page not matching the pre-score account UI (management cards/menus).

## Fix
Rebuild frontend main from last good account build (`catalan-flag-emoji`), then:
- keep Plans / Docs / Payment / Security / Privacy / Settings / Integrations cards
- re-apply Face ID login button hide
- show score on username line as `@user · ★ 5.00` (no extra Ivy nodes)

## Deploy
- Image: `robomap.azurecr.io/robomap-frontend:account-menus-20260725001238`
- Revision: `robomap-frontend--0000356` at 100% traffic
