<?php

return [
    'paths' => ['api/audit-requests', 'api/auth/status'],
    'allowed_methods' => ['GET', 'POST'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://flexpick.net,http://localhost:4321'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];
