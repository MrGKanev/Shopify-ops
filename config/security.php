<?php

return [
    'hsts' => env('HSTS_ENABLED', false),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'worker_max_age_seconds' => (int) env('READY_WORKER_MAX_AGE_SECONDS', 300),
];
