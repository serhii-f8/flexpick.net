<?php

return [
    'queue' => 'audit',
    'admin_email' => env('AUDIT_ADMIN_EMAIL'),
    'clone_timeout' => 120,
    'preflight_timeout' => 30,
    'max_repo_size_mb' => 500,
    'max_excerpt_files' => 50,
    'max_excerpt_bytes' => 6000,
    'report_link_days' => 30,
    'workdir' => storage_path('app/audit-workdirs'),
    'reports_dir' => 'audit-reports',
    'github_account' => env('AUDIT_GITHUB_ACCOUNT', 'flexpick-audit'),
    'github_token' => env('AUDIT_GITHUB_TOKEN'),
    'free_reports_limit' => 3,
    'verification_link_hours' => 48,
    'unverified_purge_days' => 7,
    'benchmark_min_sample' => 20,
    'unlock_product_slug' => 'audit-report-unlock',
];
