# Account page score fix

The account page broke after the score row was inserted into the compiled Angular Ivy template without updating later `bMT` node/var indexes.

Fix:
- Correct `bMT` indexes after `getScoreDisplay()` in `app-user-profile` embedded view
- Show Material Icons `star` via `.account-hero__score::before` (same icon name as topnav)

Deployed to `robomap-frontend` via ACR image patch.
