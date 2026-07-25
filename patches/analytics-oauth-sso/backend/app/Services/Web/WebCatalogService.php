<?php

namespace App\Services\Web;

use App\Models\DataAndPrivacy;
use App\Models\Devices;
use App\Models\ServiceRequest;
use App\Models\User;

class WebCatalogService
{
    public function cities(): array
    {
        $path = config_path('ws/cities.json');
        if (!is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function services(): array
    {
        $categories = config('on_demand_services.categories', []);

        return array_map(static function (array $category): array {
            $id = (string) ($category['id'] ?? '');

            return [
                'id' => $id,
                'label' => $category['label'] ?? ucfirst(str_replace('_', ' ', $id)),
                'icon' => $category['icon'] ?? null,
                'services' => $category['services'] ?? [],
            ];
        }, is_array($categories) ? $categories : []);
    }

    public function appSummary(): array
    {
        return [
            'app_name' => (string) config('app.name', 'Robomap'),
            'api_url' => (string) config('app.url', ''),
            'frontend_url' => (string) config('app_frontends.main', ''),
            'chat_url' => (string) config('app_frontends.chat', ''),
            'business_url' => (string) config('app_frontends.business', ''),
            'ws_url' => (string) config('app_frontends.ws', ''),
            'marketing_url' => (string) config('app_frontends.marketing', ''),
            'phone_url' => (string) config('app_frontends.phone', ''),
            'analytics_url' => (string) config('app_frontends.analytics', ''),
            'website_url' => (string) config('app.website_url', env('APP_WEBSITE_URL', 'https://robomap.ai')),
            'counts' => [
                'users' => User::query()->count(),
                'devices' => Devices::query()->count(),
                'service_requests' => ServiceRequest::query()->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function globeMarkers(): array
    {
        $markers = [];

        $sharingOwnerIds = DataAndPrivacy::where('share_location', true)
            ->pluck('owner')
            ->all();

        $devicesQuery = Devices::query()->orderByDesc('updated_at')->limit(250);
        if (!empty($sharingOwnerIds)) {
            $devicesQuery->whereIn('owner', $sharingOwnerIds);
        } else {
            $devicesQuery->whereRaw('0 = 1');
        }

        foreach ($devicesQuery->get(['latitude', 'longitude', 'city', 'status']) as $device) {
            $coords = $this->resolveCoordinates($device->latitude, $device->longitude, $device->city);
            if ($coords === null) {
                continue;
            }

            $markers[] = [
                'type' => 'device',
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'active' => mb_strtolower((string) ($device->status ?? '')) === 'active',
                'label' => $this->publicLocationLabel((string) ($device->city ?? ''), 'PLC'),
            ];
        }

        $usersQuery = User::query()->orderByDesc('updated_at')->limit(250);
        if (!empty($sharingOwnerIds)) {
            $usersQuery->whereIn('id', $sharingOwnerIds);
        } else {
            $usersQuery->whereRaw('0 = 1');
        }

        foreach ($usersQuery->get(['latitude', 'longitude', 'city', 'country']) as $user) {
            $coords = $this->resolveCoordinates($user->latitude, $user->longitude, $user->city, $user->country);
            if ($coords === null) {
                continue;
            }

            $markers[] = [
                'type' => 'user',
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'active' => true,
                'label' => $this->publicLocationLabel((string) ($user->city ?? ''), 'User'),
            ];
        }

        $serviceQuery = ServiceRequest::query()->orderByDesc('updated_at')->limit(150);
        if (!empty($sharingOwnerIds)) {
            $serviceQuery->whereIn('user_id', $sharingOwnerIds);
        } else {
            $serviceQuery->whereRaw('0 = 1');
        }

        foreach ($serviceQuery->get(['latitude', 'longitude', 'city', 'country', 'status']) as $request) {
            $coords = $this->resolveCoordinates($request->latitude, $request->longitude, $request->city, $request->country);
            if ($coords === null) {
                continue;
            }

            $markers[] = [
                'type' => 'service_request',
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'active' => in_array((string) ($request->status ?? ''), ['pending', 'confirmed'], true),
                'label' => $this->publicLocationLabel((string) ($request->city ?? ''), 'Service'),
            ];
        }

        return [
            'markers' => $markers,
            'counts' => [
                'users' => User::query()->count(),
                'devices' => Devices::query()->count(),
                'service_requests' => ServiceRequest::query()->count(),
                'markers' => count($markers),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function resolveCoordinates($latitude, $longitude, ?string $city, ?string $country = null): ?array
    {
        $lat = $this->parseCoordinate($latitude);
        $lng = $this->parseCoordinate($longitude);

        if ($lat !== null && $lng !== null && $this->isValidLatLng($lat, $lng)) {
            return [
                'lat' => $lat,
                'lng' => $this->normalizeLongitude($lng),
            ];
        }

        $cityKey = mb_strtolower(trim((string) $city));
        if ($cityKey === '') {
            return null;
        }

        $countryKey = mb_strtolower(trim((string) $country));

        foreach ($this->cities() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryCity = mb_strtolower(trim((string) ($entry['city'] ?? '')));
            if ($entryCity === '' || $entryCity !== $cityKey) {
                continue;
            }

            $entryCountry = mb_strtolower(trim((string) ($entry['country'] ?? '')));
            if ($countryKey !== '' && $entryCountry !== '' && $entryCountry !== $countryKey) {
                continue;
            }

            $entryLat = $this->parseCoordinate($entry['lat'] ?? null);
            $entryLng = $this->parseCoordinate($entry['lng'] ?? null);
            if ($entryLat === null || $entryLng === null || !$this->isValidLatLng($entryLat, $entryLng)) {
                continue;
            }

            return [
                'lat' => $entryLat,
                'lng' => $this->normalizeLongitude($entryLng),
            ];
        }

        return null;
    }

    private function isValidLatLng(float $lat, float $lng): bool
    {
        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    private function normalizeLongitude(float $lng): float
    {
        $wrapped = fmod($lng + 180.0, 360.0);
        if ($wrapped < 0.0) {
            $wrapped += 360.0;
        }

        return $wrapped - 180.0;
    }

    private function parseCoordinate($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (float) $value;

        return is_finite($parsed) ? $parsed : null;
    }

    private function publicLocationLabel(string $city, string $fallback): string
    {
        $city = trim($city);

        return $city !== '' ? $city : $fallback;
    }
}
