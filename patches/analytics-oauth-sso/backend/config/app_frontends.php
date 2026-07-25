<?php

return [
    'main' => rtrim((string) env('APP_FRONTEND_URL', 'http://127.0.0.1:8889'), '/'),
    'chat' => rtrim((string) env('APP_CHAT_URL', env('APP_FRONTEND_URL', 'http://127.0.0.1:8890')), '/'),
    'business' => rtrim((string) env('APP_BUSINESS_URL', env('APP_FRONTEND_URL', 'http://127.0.0.1:8891')), '/'),
    'ws' => rtrim((string) env('APP_WS_URL', env('APP_FRONTEND_URL', 'http://127.0.0.1:8892')), '/'),
    'marketing' => rtrim((string) env('APP_MARKETING_URL', env('APP_FRONTEND_URL', 'http://127.0.0.1:8893')), '/'),
    'phone' => rtrim((string) env('APP_PHONE_URL', env('APP_FRONTEND_URL', 'http://127.0.0.1:8894')), '/'),
    'analytics' => rtrim((string) env('APP_ANALYTICS_URL', 'https://analytics.robomap.ai'), '/'),
    'default' => 'main',
];
