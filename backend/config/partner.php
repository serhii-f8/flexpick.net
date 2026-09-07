<?php

return [
    // The encrypted, httpOnly cookie that remembers which partner referred an
    // anonymous visitor (spec §3.3). Registered users are attributed in the
    // database; the cookie is only re-issued from that record after login.
    'cookie_name' => env('PARTNER_COOKIE_NAME', 'fp_rc'),
    'cookie_lifetime_days' => (int) env('PARTNER_COOKIE_LIFETIME_DAYS', 365),
];
