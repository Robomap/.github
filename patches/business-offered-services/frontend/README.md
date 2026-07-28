# Frontend changes (Angular)

Apply in the Robomap frontend repository. Target component: `app-robomap-business-settings`
(route `/account/robomap-business`, also embedded in Business admin).

Live behavior today (from production bundle):

- Form fields: `name`, `location`, `city`, `country`, `vat`, `description`
- Save: `POST /account/business-profile` with `{ ...form, upgrade_to_business }`
- Load: `GET /account/business-profile` → `business` object
- **No** offered-services UI or catalog fetch

## Files in this folder

| File | Purpose |
|------|---------|
| `robomap-business-settings.component.ts` | Full settings component with catalog load + offered services |
| `robomap-business-settings.component.html` | Template with services picker section |
| `robomap-business-settings.component.scss` | Styles for the services picker |
| `i18n-en.ACCOUNT_ROBOMAP_BUSINESS.json` | Keys to merge into `assets/i18n/en.json` (and other locales) |

Replace the existing settings component sources with these files (or merge the offered-services parts if your tree already diverged). Keep existing module declarations / routing.

## UX

1. After company details fields, a **Services you provide** section lists every category from the ODS catalog.
2. Each category can Select all / Clear.
3. Individual service types are checkboxes; labels match booking (`WORKSPACE_ON_DEMAND_SERVICES.categories.*` for category titles).
4. Selected pairs are stored as `offered_services: [{ category_id, service_type }, …]` and sent on save.
5. Empty selection is allowed to save (API then returns no available tickets until configured).

## Dependencies

Uses existing `HttpClient`, `MatSnackBar`, `TranslateService`, and Material icons already used by the page. No new npm packages.
