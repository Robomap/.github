# Account page score fix

The account page broke after the score row was inserted into the compiled Angular Ivy template without updating later `bMT` node/var indexes (labels/details rendered wrong or blank).

## Fix
- Correct `bMT` indexes after `getScoreDisplay()` in `app-user-profile` embedded view `St`
- Show Material Icons `star` via `.account-hero__score::before` (same icon name as topnav)

## Deploy
- Image: `robomap.azurecr.io/robomap-frontend:account-score-fix-20260724235627`
- Revision: `robomap-frontend--0000354` at 100% traffic
