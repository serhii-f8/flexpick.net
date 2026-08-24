<?php

namespace Tests\Feature\Models;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditTierTest extends FeatureTest
{
    public function test_new_requests_default_to_the_diagnostic_tier(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->fresh()->tier);
    }

    public function test_tier_is_cast_to_the_enum(): void
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DEEP_AI->value]);

        $this->assertInstanceOf(AuditTier::class, $request->fresh()->tier);
        $this->assertSame('deep_ai', $request->fresh()->tier->value);
    }

    /**
     * `automated` (the Automated Health Report) was retired: its scanner
     * profile is what Diagnostic runs now, so the tier had no distinct
     * product left to sell. Asserted as an exact list because a stray
     * re-addition would silently reopen a tier nothing prices.
     */
    public function test_enumeration_is_closed_to_the_three_known_tiers(): void
    {
        $this->assertSame(
            ['diagnostic', 'deep_ai', 'expert'],
            array_column(AuditTier::cases(), 'value'),
        );
    }

    public function test_diagnostic_label_no_longer_says_free(): void
    {
        $this->assertSame('Diagnostic Report', AuditTier::DIAGNOSTIC->label());
    }

    public function test_price_cents_reads_the_pricing_catalog(): void
    {
        $this->assertSame(4900, AuditTier::DIAGNOSTIC->priceCents());
        $this->assertSame(11900, AuditTier::DEEP_AI->priceCents());
        $this->assertSame(99900, AuditTier::EXPERT->priceCents());
    }

    public function test_label_with_price_appends_a_formatted_dollar_amount(): void
    {
        $this->assertSame('Diagnostic Report — $49', AuditTier::DIAGNOSTIC->labelWithPrice());
        $this->assertSame('Deep AI Code Review — $119', AuditTier::DEEP_AI->labelWithPrice());
        $this->assertSame('Expert Audit — $999', AuditTier::EXPERT->labelWithPrice());
    }
}
