# Landing page background unify

Unifies \`robomap.ai\` section backgrounds to \`var(--white)\` (light white / dark \`#111111\` via theme tokens).

Deployed via ACR image patch on \`robomap-website\` (not built from this monorepo).

Changes:
- \`styles.css\`: plan-later, account-cta, business, mobile-download, footer → page bg; business/footer text contrast fixed
- \`theme.css\`: remove dark-mode pure-black business/footer override
- \`apple-theme.css\`: remove zebra / gray section overrides if loaded
