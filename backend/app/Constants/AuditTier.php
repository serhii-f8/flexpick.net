<?php

namespace App\Constants;

enum AuditTier: string
{
    case DIAGNOSTIC = 'diagnostic';
    case DEEP_AI = 'deep_ai';
    case EXPERT = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => __('Diagnostic Report'),
            self::DEEP_AI => __('Deep AI Code Review'),
            self::EXPERT => __('Expert Audit'),
        };
    }

    /**
     * One line of what the tier adds, for the picker on the run-an-audit
     * page. Each tier builds on the one before it, and the copy says so.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => __('Five static analyzers, an AI reading of the results, and a fix-first plan.'),
            self::DEEP_AI => __('Everything in Diagnostic, plus an AI review of your key files and core flows.'),
            self::EXPERT => __('Everything in Deep AI, plus a manual review by one of our developers.'),
        };
    }

    /**
     * Catalog price in cents from config('pricing.tiers'), or null if this
     * tier has no priced product yet. Single source of truth shared by
     * AuditEntitlementService::tierPriceCents() and labelWithPrice().
     */
    public function priceCents(): ?int
    {
        foreach ((array) config('pricing.tiers') as $definition) {
            if (($definition['tier'] ?? null) === $this->value) {
                return (int) $definition['price'];
            }
        }

        return null;
    }

    /**
     * The plain label with the catalog price appended, for the two surfaces
     * where staff/customers need to see what an audit type costs at a
     * glance. Falls back to the plain label if this tier has no price yet.
     */
    public function labelWithPrice(): string
    {
        $cents = $this->priceCents();

        if ($cents === null) {
            return $this->label();
        }

        return __(':label — $:price', ['label' => $this->label(), 'price' => number_format($cents / 100)]);
    }

    /**
     * Filament badge colour for this tier, shared by every surface that lists
     * runs so the same tier never appears in two colours.
     *
     * `warning` is deliberately unused: the report list paints an
     * "In expert review" badge warning on the same row, and a tier sharing
     * that colour would read as one state rather than two.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => 'gray',
            self::DEEP_AI => 'primary',
            self::EXPERT => 'success',
        };
    }
}
