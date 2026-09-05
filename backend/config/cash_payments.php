<?php

return [
    // How long a pending cash order may sit unapproved before the system
    // rejects it. Also the grace window a lapsed cash subscription gets
    // between PAST_DUE and CANCELED (spec §6.5, §6.6).
    'pending_ttl_hours' => (int) env('CASH_PENDING_TTL_HOURS', 72),

    // How far ahead of ends_at the next renewal order is opened. Kept equal to
    // the TTL by default so a renewal order's life exactly covers the window
    // between issuance and the end of the cycle it renews.
    'renewal_lead_hours' => (int) env('CASH_RENEWAL_LEAD_HOURS', 72),
];
