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
        'audit-diagnostic' => [
            'tier' => 'diagnostic',
            'name' => 'Diagnostic Report',
            'description' => 'The full analysis pipeline on one repository, with AI interpretation of every result.',
            'price' => 4900,
            'features' => [
                'Five static analyzers across security, duplication, and dependencies',
                'AI interpretation of the results, not a raw lint dump',
                'Prioritized fix-first plan',
                'PDF export',
            ],
        ],
        'audit-deep-ai' => [
            'tier' => 'deep_ai',
            'name' => 'Deep AI Code Review',
            'description' => 'Everything in the Diagnostic Report, plus an AI code review of your key files and core flows.',
            'price' => 11900,
            'features' => [
                'Everything in the Diagnostic Report',
                'AI code review of the key files and core flows',
                'Findings bound to files, with evidence and effort sizing',
                'PDF export',
            ],
        ],
        'audit-expert' => [
            'tier' => 'expert',
            'name' => 'Expert Audit',
            'description' => 'Everything in the Diagnostic Report and the Deep AI Code Review, plus a manual review by one of our developers.',
            'price' => 99900,
            'features' => [
                'Everything in the Diagnostic Report and Deep AI Code Review',
                'Manual review by one of our developers',
                'False positives removed, priorities adjusted',
                'Remediation roadmap',
            ],
        ],
    ],

    // Starter/Growth/Agency/Enterprise were replaced by `packages` on
    // 2026-09-07; their slugs are in `retired.plans`. Kept as an empty block
    // so AuditMonetizationSeeder and app:export-pricing need no branch.
    'subscriptions' => [],

    // The per-report tiers the packages below are built from. `credit_key`
    // is the plan-metadata key AuditEntitlementService meters that tier on.
    'package_tiers' => [
        'diagnostic' => [
            'name' => 'Diagnostic Report',
            'plural' => 'Diagnostic Reports',
            'credit_key' => 'audit_diagnostic_credits',
            'headline' => 'CTOs, product owners, and business leaders who want to evaluate team efficiency and basic code health.',
        ],
        'deep_ai' => [
            'name' => 'Deep AI Code Review',
            'plural' => 'Deep AI Code Reviews',
            'credit_key' => 'audit_deep_ai_credits',
            'headline' => 'Small, medium-sized, and large development teams that want to verify code health without paying for a full manual QA review.',
        ],
        'expert' => [
            'name' => 'Expert Audit',
            'plural' => 'Expert Audits',
            'credit_key' => 'audit_expert_credits',
            'headline' => 'Product companies that outsource development and need both a code-health assessment and support from a human expert.',
        ],
    ],

    // Monthly packages: N reports of one tier. `price` is the FlexPick package
    // price (unit × actions × (1 − discount)); `partner_unit_price` is the
    // suggested partner selling price per report, which the Pricing Settings
    // page multiplies by `actions` as the partner's default. A test asserts
    // the arithmetic so the table cannot drift.
    'packages' => [
        'audit-diagnostic-5' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 5', 'actions' => 5, 'unit_price' => 4900, 'price' => 23275, 'discount_percent' => 5, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-diagnostic-15' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 15', 'actions' => 15, 'unit_price' => 4900, 'price' => 66150, 'discount_percent' => 10, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-diagnostic-30' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 30', 'actions' => 30, 'unit_price' => 4900, 'price' => 117600, 'discount_percent' => 20, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-deep-ai-5' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 5', 'actions' => 5, 'unit_price' => 11900, 'price' => 56525, 'discount_percent' => 5, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-deep-ai-15' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 15', 'actions' => 15, 'unit_price' => 11900, 'price' => 160650, 'discount_percent' => 10, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-deep-ai-30' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 30', 'actions' => 30, 'unit_price' => 11900, 'price' => 285600, 'discount_percent' => 20, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-expert-5' => ['tier' => 'expert', 'name' => 'Expert Audit × 5', 'actions' => 5, 'unit_price' => 99900, 'price' => 474525, 'discount_percent' => 5, 'partner_unit_price' => 170000, 'is_popular' => false],
        'audit-expert-15' => ['tier' => 'expert', 'name' => 'Expert Audit × 15', 'actions' => 15, 'unit_price' => 99900, 'price' => 1348650, 'discount_percent' => 10, 'partner_unit_price' => 170000, 'is_popular' => false],
        'audit-expert-30' => ['tier' => 'expert', 'name' => 'Expert Audit × 30', 'actions' => 30, 'unit_price' => 99900, 'price' => 2397600, 'discount_percent' => 20, 'partner_unit_price' => 170000, 'is_popular' => false],
    ],

    /**
     * Standalone one-time products that are not part of the priced tier
     * catalog above -- reachable only from a specific in-app flow, never
     * listed on the public pricing page or exported to the marketing site.
     */
    'one_time' => [
        'audit-report-unlock' => [
            'name' => 'Full Audit Report Unlock',
            'description' => 'Unlock the full report for a free diagnostic you already ran.',
            // Same price as buying the Diagnostic tier outright (Q32
            // superseded: no longer a distinct discount, just a distinct
            // product so a fresh diagnostic purchase and an unlock of an
            // existing report stay unambiguous to the order listeners).
            'price' => 500,
            'features' => [
                'Every finding\'s evidence and recommendation',
                'The prioritized fix-first plan',
                'PDF export',
            ],
        ],
    ],

    'retired' => [
        // audit-automated was the Automated Health Report tier. Its name and
        // positioning never worked, and everything it did is now the base
        // Diagnostic tier -- so the product is deactivated rather than
        // deleted, because orders referencing it still exist.
        'one_time' => ['audit-automated'],
        // Starter/Growth/Agency/Enterprise were the subscription grid;
        // Scale had no successor even before that. None have a package
        // equivalent, so all five are retired.
        'plans' => [
            'audit-scale-monthly',
            'audit-starter-monthly',
            'audit-growth-monthly',
            'audit-agency-monthly',
            'audit-enterprise-monthly',
        ],
    ],
];
