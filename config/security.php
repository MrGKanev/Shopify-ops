<?php

return [
    'installation_token' => env('INSTALLATION_TOKEN', ''),
    'report_pending_limit' => (int) env('REPORT_PENDING_LIMIT', 10),
    'report_daily_limit' => (int) env('REPORT_DAILY_LIMIT', 500),
    'hsts' => env('HSTS_ENABLED', env('APP_ENV', 'production') === 'production'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'worker_max_age_seconds' => (int) env('READY_WORKER_MAX_AGE_SECONDS', 300),
];
