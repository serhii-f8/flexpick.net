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

    // ISO-8601 timestamp marking when the cash-payment feature went live.
    // orders.type was added with a DB default of 'purchase', so every
    // pre-existing order reads as a purchase order, and the "cash
    // subscription" predicate (locally managed + Offline provider + price >
    // 0) is purely structural with no marker for "created under this
    // feature". Without this floor, the first scheduled run of the sweep
    // commands would match every pre-existing abandoned Offline order and
    // paid Offline local subscription in the database and email those
    // customers. Rows created before this timestamp are left to whatever
    // behaviour they already had. A null or empty value means no floor.
    'sweep_from' => env('CASH_SWEEP_FROM', '2026-09-05'),
];
