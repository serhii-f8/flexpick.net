<?php

return [
    'queue' => 'audit',
    'admin_email' => env('AUDIT_ADMIN_EMAIL'),
    'clone_timeout' => 120,
    'clone_depth' => 200,
    'preflight_timeout' => 30,
    'max_repo_size_mb' => 500,
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
    'osv_endpoint' => 'https://api.osv.dev/v1/querybatch',

    'tiers' => [
        'diagnostic' => [
            'scanners' => ['scc', 'gitleaks', 'osv'],
            'excerpt_files' => 15,
            'excerpt_bytes' => 3000,
            'ai_max_tokens' => 4000,
            'narrated_groups' => 2,
        ],
        'automated' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
        ],
        // Phase 12 diverges deep_ai; Phase 13 adds the expert delivery hold.
        // Until then both compose identically to `automated` — deliberate, not an omission.
        'deep_ai' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
        ],
        'expert' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
        ],
    ],

    'scanners' => [
        'gitleaks' => [
            'bin' => env('AUDIT_GITLEAKS_BIN', '/opt/flexpick/bin/gitleaks'),
            'version' => '8.28.0',
            'timeout' => 120,
            'config' => resource_path('scanners/gitleaks.toml'),
        ],
        'semgrep' => [
            'bin' => env('AUDIT_SEMGREP_BIN', '/opt/flexpick/bin/semgrep'),
            'version' => '1.99.0',
            'timeout' => 300,
            'rules_path' => resource_path('semgrep/flexpick'),
        ],
    ],

    'findings' => [
        'max_groups' => 20,
        'max_group_examples' => 8,
        'directory_depth' => 2,
        'severity_weights' => [
            'critical' => 100,
            'high' => 40,
            'medium' => 10,
            'low' => 3,
            'info' => 1,
        ],
    ],
];
