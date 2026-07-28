# Business offered services (match On-demand catalog)

Allow Robomap Business accounts to select and configure the services they provide. Options come from the same catalog used by On-demand Services booking (`GET /on-demand-services/catalog`).

## Why this patch lives here

This Cursor environment only has `Robomap/.github` (org profile). The Angular app and Laravel API source repos are not attached. This package is the ready-to-apply implementation for those codebases.

## Behavior

1. Business profile (`/account/robomap-business` and Business admin company profile) loads categories/types from `GET /on-demand-services/catalog`.
2. Providers multi-select service types per category and save them on the business profile as `offered_services`.
3. `GET /on-demand-services/business/available` only returns requests whose `(service_category, service_type)` pair is in the business’s offered services.
4. If a business has **no** offered services configured, the available queue is empty (forces configuration before claiming).

## Catalog source of truth

`shared/on-demand-catalog.json` mirrors the live API catalog (5 categories). Do **not** use the marketing `services-data.js` list for business config — it is broader and diverges from booking.

| Category id   | Types (API) |
|---------------|-------------|
| domestic      | 12 |
| industrial    | 6 |
| automation    | 5 |
| networking    | 5 |
| trades        | 5 |

## Apply order

1. **API** — `api/` (migration + validation + availability filter)
2. **Frontend** — `frontend/` (settings UI + i18n)
3. Deploy API first, then frontend, so saves of `offered_services` persist.

## Payload shape

```json
{
  "name": "Acme Maintenance",
  "location": "…",
  "city": "…",
  "country": "…",
  "vat": "…",
  "description": "…",
  "upgrade_to_business": false,
  "offered_services": [
    { "category_id": "domestic", "service_type": "Plumbing" },
    { "category_id": "trades", "service_type": "Industrial Electrician" }
  ]
}
```

`GET /account/business-profile` returns the same `offered_services` array on `business`.
