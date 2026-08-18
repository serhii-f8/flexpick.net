<?php

/**
 * Credentials for the accounts seeded by Database\Seeders\Demo\*.
 *
 * Read from env with strong fallback defaults rather than hardcoded literals
 * in the seeders themselves, so a real, publicly-reachable demo deployment
 * (e.g. audit.flexpick.net) can override them without a code change, and so
 * the value survives `config:cache` -- env() calls outside config/ files
 * return null once config is cached, since .env is never re-parsed.
 */
return [
    'admin_password' => env('DEMO_ADMIN_PASSWORD', 'Kx7#mQ2vN9pL$wR4'),
    'user_password' => env('DEMO_USER_PASSWORD', 'Bz3&hT8jY5cF@nX6'),
];
