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

    // audit_expert_credits is zero on every plan below, by design: Agency at
    // $499/month cannot absorb a $999 audit, and Enterprise at $1,500/month
    // barely can. The key exists so a custom enterprise deal is an admin
    // metadata edit rather than a code change.
    'subscriptions' => [
        'audit-starter' => [
            'name' => 'Starter',
            'price' => 5900,
            'audit_diagnostic_credits' => 5,
            'audit_deep_ai_credits' => 0,
            'audit_expert_credits' => 0,
            'is_popular' => false,
        ],
        'audit-growth' => [
            'name' => 'Growth',
            'price' => 14900,
            'audit_diagnostic_credits' => 20,
            'audit_deep_ai_credits' => 1,
            'audit_expert_credits' => 0,
            'is_popular' => true,
        ],
        'audit-agency' => [
            'name' => 'Agency',
            'price' => 49900,
            'audit_diagnostic_credits' => 75,
            'audit_deep_ai_credits' => 4,
            'audit_expert_credits' => 0,
            'is_popular' => false,
        ],
        'audit-enterprise' => [
            'name' => 'Enterprise',
            'price' => 150000,
            'audit_diagnostic_credits' => 250,
            'audit_deep_ai_credits' => 15,
            'audit_expert_credits' => 0,
            'is_popular' => false,
        ],
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
        // Starter and Growth reuse their old slugs at new prices; Scale has
        // no successor and is the only plan genuinely orphaned by this catalog.
        'plans' => ['audit-scale-monthly'],
    ],
];
