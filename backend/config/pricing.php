<?php

/**
 * The single source of truth for every monetary and quota figure.
 *
 * Read by AuditMonetizationSeeder (which seeds products and plans) and by
 * app:export-pricing (which generates the marketing data file). Neither may
 * hold a literal figure of its own — A15 requires every figure shown anywhere
 * to match backend configuration exactly.
 *
 * Prices are in cents.
 */
return [
    'currency' => 'USD',

    'tiers' => [
        'audit-automated' => [
            'tier' => 'automated',
            'name' => 'Automated Health Report',
            'description' => 'A scanner-backed health report on one repository, with a prioritized fix-first plan.',
            'price' => 4900,
            'features' => [
                'Five static analyzers across security, duplication, and dependencies',
                'Problems grouped and explained, not a raw lint dump',
                'Prioritized fix-first plan',
                'PDF export',
            ],
        ],
        'audit-deep-ai' => [
            'tier' => 'deep_ai',
            'name' => 'Deep AI Code Review',
            'description' => 'Everything in the Automated Health Report, plus AI review of your riskiest files.',
            'price' => 19900,
            'features' => [
                'Everything in the Automated Health Report',
                'AI review of the 20-40 riskiest files',
                'Findings bound to files, with evidence and effort sizing',
                'PDF export',
            ],
        ],
        'audit-expert' => [
            'tier' => 'expert',
            'name' => 'Expert Audit',
            'description' => 'Everything in the Deep AI Code Review, reviewed and signed off by a human auditor.',
            'price' => 99900,
            'features' => [
                'Everything in the Deep AI Code Review',
                'Human expert review and sign-off',
                'False positives removed, priorities adjusted',
                'Remediation roadmap',
            ],
        ],
    ],

    'subscriptions' => [
        'audit-starter' => [
            'name' => 'Starter',
            'price' => 5900,
            'audit_analyses_per_month' => 5,
            'audit_deep_ai_credits' => 0,
            'is_popular' => false,
        ],
        'audit-growth' => [
            'name' => 'Growth',
            'price' => 14900,
            'audit_analyses_per_month' => 20,
            'audit_deep_ai_credits' => 1,
            'is_popular' => true,
        ],
        'audit-agency' => [
            'name' => 'Agency',
            'price' => 49900,
            'audit_analyses_per_month' => 75,
            'audit_deep_ai_credits' => 4,
            'is_popular' => false,
        ],
        'audit-enterprise' => [
            'name' => 'Enterprise',
            'price' => 150000,
            'audit_analyses_per_month' => 250,
            'audit_deep_ai_credits' => 15,
            'is_popular' => false,
        ],
    ],

    /**
     * Q32: retired. The row survives so already-unlocked reports keep
     * rendering; only new purchases stop.
     */
    'retired' => [
        'one_time' => ['audit-report-unlock'],
        // Starter and Growth reuse their old slugs at new prices; Scale has
        // no successor and is the only plan genuinely orphaned by this catalog.
        'plans' => ['audit-scale-monthly'],
    ],
];
