<?php

namespace App\Support;

class FrontendUrl
{
    private const APP_KEYS = ['main', 'chat', 'business', 'ws', 'marketing', 'phone', 'analytics'];

    private const APP_ALIASES = [
        'hosting' => 'ws',
    ];

    public static function resolve(?string $app = null): string
    {
        $key = self::resolveAppKey($app);

        return rtrim((string) config("app_frontends.{$key}", config('app_frontends.main')), '/');
    }

    public static function defaultReturnPath(?string $app = null): string
    {
        return match (self::resolveAppKey($app)) {
            'chat' => '/assistant',
            'business', 'ws', 'marketing', 'phone', 'analytics' => '/',
            default => '/dashboard',
        };
    }

    public static function hosts(): array
    {
        $hosts = [];

        foreach (self::APP_KEYS as $key) {
            $host = parse_url((string) config("app_frontends.{$key}"), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function resolveAppKey(?string $app): string
    {
        $normalized = self::APP_ALIASES[$app ?? ''] ?? $app;

        return in_array($normalized, self::APP_KEYS, true)
            ? (string) $normalized
            : (string) config('app_frontends.default', 'main');
    }
}
