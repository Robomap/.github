# Account page fix (cache bust + score star)

## Root cause
JS/CSS filenames were reused across deploys while nginx caches `*.js` for 6 months, so browsers kept serving the broken account-score build (truncated mail/phone/place cards).

## Fix
- Restore account template from pre-score baseline (menus/cards intact)
- Inject Score pill with Material star icon after username (no Ivy node surgery)
- **New asset hashes** so clients must fetch fresh JS/CSS
- `index.html` served with `Cache-Control: no-store`

## Deploy
- Image: `robomap.azurecr.io/robomap-frontend:account-bust-20260725001720`
- Assets: `main.eafe7c5ec0950671.js`, `styles.33e657631ff3507b.css`
- Revision: `robomap-frontend--0000357` at 100% traffic
