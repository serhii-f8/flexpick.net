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
];
