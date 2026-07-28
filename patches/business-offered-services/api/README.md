# API changes (Laravel)

Apply in the Robomap API repository.

## 1. Migration

File: `database/migrations/2026_07_28_000001_add_offered_services_to_businesses_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // JSON array of { category_id: string, service_type: string }
            $table->json('offered_services')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('offered_services');
        });
    }
};
```

If the provider table is named differently (e.g. `business_profiles`), add the column there instead.

## 2. Catalog helper

File: `app/Services/OnDemandCatalog.php` (new)

Use the same category/service list that powers `GET /on-demand-services/catalog`. Prefer reading from an existing catalog service/config if one already exists; otherwise keep this array in sync with `patches/business-offered-services/shared/on-demand-catalog.json`.

```php
<?php

namespace App\Services;

class OnDemandCatalog
{
    public static function categories(): array
    {
        return [
            [
                'id' => 'domestic',
                'icon' => 'home',
                'services' => [
                    'Home repair', 'Handyman', 'Cleaning', 'Deep cleaning', 'Garden care',
                    'Plumbing', 'Electrical', 'HVAC & air conditioning', 'Moving & assembly',
                    'Appliance repair', 'Pest control', 'Locksmith',
                ],
            ],
            [
                'id' => 'industrial',
                'icon' => 'factory',
                'services' => [
                    'Industrial Maintenance Technician', 'Millwright Services',
                    'Predictive & Preventive Maintenance', 'Equipment Installation & Commissioning',
                    'Forklift & Material Handling Equipment Service',
                    'Welding Services (MIG, TIG, Stick — on-site)',
                ],
            ],
            [
                'id' => 'automation',
                'icon' => 'precision_manufacturing',
                'services' => [
                    'PLC Programming & Troubleshooting',
                    'HMI / SCADA Configuration & Development',
                    'Automation System Commissioning & Startup',
                    'VFD Programming & Tuning',
                    'Robot Programming & Integration',
                ],
            ],
            [
                'id' => 'networking',
                'icon' => 'lan',
                'services' => [
                    'Network Engineer (LAN, WAN, Industrial Ethernet)',
                    'Industrial Network Configuration',
                    'WiFi Site Survey, Design & Optimization',
                    'Firewall & Network Security Configuration',
                    'IIoT Gateway & Edge Device Configuration',
                ],
            ],
            [
                'id' => 'trades',
                'icon' => 'construction',
                'services' => [
                    'CNC Machine Operator',
                    'Heavy Equipment Operator',
                    'Industrial Electrician',
                    'Commercial / Industrial HVAC Technician',
                    'Generator Technician',
                ],
            ],
        ];
    }

    /** @return array<string, array<int, string>> category_id => service types */
    public static function map(): array
    {
        $map = [];
        foreach (self::categories() as $category) {
            $map[$category['id']] = $category['services'];
        }
        return $map;
    }

    public static function isValidPair(string $categoryId, string $serviceType): bool
    {
        $map = self::map();
        return isset($map[$categoryId]) && in_array($serviceType, $map[$categoryId], true);
    }

    /**
     * Normalize and validate offered_services from the request.
     * Invalid pairs are dropped; duplicates are removed.
     *
     * @return list<array{category_id: string, service_type: string}>
     */
    public static function sanitizeOfferedServices(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = trim((string) ($row['category_id'] ?? ''));
            $serviceType = trim((string) ($row['service_type'] ?? ''));
            if ($categoryId === '' || $serviceType === '') {
                continue;
            }
            if (!self::isValidPair($categoryId, $serviceType)) {
                continue;
            }
            $key = $categoryId . "\0" . $serviceType;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'category_id' => $categoryId,
                'service_type' => $serviceType,
            ];
        }

        return $out;
    }

    public static function businessOffers(array $offeredServices, string $category, string $type): bool
    {
        foreach ($offeredServices as $row) {
            if (($row['category_id'] ?? null) === $category
                && ($row['service_type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }
}
```

Wire `GET /on-demand-services/catalog` to `OnDemandCatalog::categories()` so booking and business config cannot drift.

## 3. Business profile GET/POST

In the controller that handles `/account/business-profile`:

### Serialize

Include on the business object:

```php
'offered_services' => $business->offered_services ?? [],
```

Ensure the model casts:

```php
protected $casts = [
    // ...
    'offered_services' => 'array',
];
```

### Persist (POST)

```php
use App\Services\OnDemandCatalog;

// alongside existing name/location/vat/description fields:
$offered = OnDemandCatalog::sanitizeOfferedServices($request->input('offered_services'));
$business->offered_services = $offered;
$business->save();
```

Optional strict validation (422 if any invalid pair was sent):

```php
'offered_services' => ['nullable', 'array'],
'offered_services.*.category_id' => ['required_with:offered_services', 'string'],
'offered_services.*.service_type' => ['required_with:offered_services', 'string'],
```

Then reject rows that fail `OnDemandCatalog::isValidPair`.

## 4. Filter available tickets

In the handler for `GET /on-demand-services/business/available`:

```php
use App\Services\OnDemandCatalog;

$offered = $business->offered_services ?? [];

if (count($offered) === 0) {
    return response()->json(['requests' => []]);
}

$requests = $pendingRequestsQuery
    ->get()
    ->filter(function ($request) use ($offered) {
        return OnDemandCatalog::businessOffers(
            $offered,
            (string) $request->service_category,
            (string) $request->service_type,
        );
    })
    ->values();

return response()->json(['requests' => $requests]);
```

Prefer pushing the filter into SQL with `where` / `orWhere` pairs when the JSON column shape and DB driver allow it.

## 5. Registration (optional)

If `POST /auth/register` with `registration_type=business` creates the business row, accept optional `offered_services` with the same sanitizer so new businesses can configure services at signup.
