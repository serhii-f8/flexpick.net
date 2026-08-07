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
    // A delivery promise to the customer, not a system-health threshold —
    // which is why it lives here and not in config/health.php beside the
    // pipeline and mail windows.
    'expert_review_sla_hours' => (int) env('AUDIT_EXPERT_REVIEW_SLA_HOURS', 24),
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
        // Deep review is what separates these from `automated`; the tier-1
        // budgets below are deliberately identical.
        'deep_ai' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
            'deep_review' => [
                'min_files' => 20,
                'max_files' => 40,
                'file_bytes' => 12000,
                'min_file_bytes' => 4000,
                'input_token_budget' => 150000,
                'max_tokens' => 16000,
            ],
        ],
        'expert' => [
            'scanners' => ['scc', 'gitleaks', 'osv', 'jscpd', 'semgrep'],
            'excerpt_files' => 50,
            'excerpt_bytes' => 6000,
            'ai_max_tokens' => 16000,
            'narrated_groups' => 12,
            // F5.12.4: tier 3 is everything in tiers 1-2 plus human review, so
            // it runs the same deep review. Phase 13 adds only the delivery
            // hold and the reviewer queue.
            'deep_review' => [
                'min_files' => 20,
                'max_files' => 40,
                'file_bytes' => 12000,
                'min_file_bytes' => 4000,
                'input_token_budget' => 150000,
                'max_tokens' => 16000,
            ],
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
        'scc' => [
            'bin' => env('AUDIT_SCC_BIN', '/opt/flexpick/bin/scc'),
            'version' => '3.5.0',
            'timeout' => 60,
        ],
        'jscpd' => [
            'bin' => env('AUDIT_JSCPD_BIN', '/opt/flexpick/bin/jscpd'),
            'version' => '4.0.5',
            'timeout' => 180,
            'config' => resource_path('scanners/jscpd.json'),
            'max_file_size' => '2mb',
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

    'deep_review' => [
        // Bump whenever weights or signal definitions change, so a stored
        // selection can always be reproduced against the policy that made it.
        'selection_version' => 1,
        'weights' => [
            'churn' => 0.4,
            'findings' => 0.4,
            // Lowest because a path heuristic is the crudest of the three.
            'sensitive' => 0.2,
        ],
        'chars_per_token' => 3.5,
        'safety_margin' => 1.15,
        // Fixed reserve for the system prompt, metrics, groups and selection
        // rationale. A fixed figure rather than a measured one because
        // measuring the prompt requires the file list the budget decides.
        'overhead_tokens' => 8000,
        // Bounds the OUTPUT side: the primary defense against the response
        // hitting max_tokens and arriving as truncated, unparseable JSON.
        'max_findings' => 40,
        'path_exclusions' => [
            'vendor/', 'node_modules/', 'dist/', 'build/', 'storage/framework/',
            '*.min.js', '*.min.css', '*.lock', '*.map',
        ],
        'sensitive_patterns' => [
            '*auth*', '*login*', '*session*', '*token*', '*permission*', '*polic*', '*role*',
            '*payment*', '*billing*', '*checkout*', '*invoice*', '*subscription*', '*webhook*',
            '*upload*', '*crypt*', '*password*', '*secret*', '*credential*',
        ],
    ],

    'secret_files' => [
        // Q17. Unconditional, unlike the Gitleaks signal, which is only
        // present when Gitleaks actually ran.
        'denylist' => [
            '.env', '.env.*', '*.pem', '*.key', '*.p12', '*.pfx', 'id_rsa*', 'id_ed25519*',
            '.npmrc', '.netrc', '.pgpass', '*credentials*.json', '*.keystore', '*.jks',
        ],
    ],
];
