<?php

namespace App\Services;

/**
 * Single source of truth for on-demand service categories/types.
 * Keep in sync with GET /on-demand-services/catalog and
 * patches/business-offered-services/shared/on-demand-catalog.json
 */
class OnDemandCatalog
{
    public static function categories(): array
    {
        return [
            [
                'id' => 'domestic',
                'icon' => 'home',
                'services' => [
                    'Home repair',
                    'Handyman',
                    'Cleaning',
                    'Deep cleaning',
                    'Garden care',
                    'Plumbing',
                    'Electrical',
                    'HVAC & air conditioning',
                    'Moving & assembly',
                    'Appliance repair',
                    'Pest control',
                    'Locksmith',
                ],
            ],
            [
                'id' => 'industrial',
                'icon' => 'factory',
                'services' => [
                    'Industrial Maintenance Technician',
                    'Millwright Services',
                    'Predictive & Preventive Maintenance',
                    'Equipment Installation & Commissioning',
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

    /** @return array<string, list<string>> */
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
     * @return list<array{category_id: string, service_type: string}>
     */
    public static function sanitizeOfferedServices(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = trim((string) ($row['category_id'] ?? ''));
            $serviceType = trim((string) ($row['service_type'] ?? ''));

            if ($categoryId === '' || $serviceType === '') {
                continue;
            }

            if (! self::isValidPair($categoryId, $serviceType)) {
                continue;
            }

            $key = $categoryId."\0".$serviceType;
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
