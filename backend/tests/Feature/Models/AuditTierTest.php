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
        $request = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);

        $this->assertInstanceOf(AuditTier::class, $request->fresh()->tier);
        $this->assertSame('automated', $request->fresh()->tier->value);
    }

    public function test_enumeration_is_closed_to_the_four_known_tiers(): void
    {
        $this->assertSame(
            ['diagnostic', 'automated', 'deep_ai', 'expert'],
            array_column(AuditTier::cases(), 'value'),
        );
    }

    public function test_diagnostic_label_no_longer_says_free(): void
    {
        $this->assertSame('Diagnostic Report', AuditTier::DIAGNOSTIC->label());
    }

    public function test_price_cents_reads_the_pricing_catalog(): void
    {
        $this->assertSame(500, AuditTier::DIAGNOSTIC->priceCents());
        $this->assertSame(4900, AuditTier::AUTOMATED->priceCents());
        $this->assertSame(19900, AuditTier::DEEP_AI->priceCents());
        $this->assertSame(99900, AuditTier::EXPERT->priceCents());
    }

    public function test_label_with_price_appends_a_formatted_dollar_amount(): void
    {
        $this->assertSame('Diagnostic Report — $5', AuditTier::DIAGNOSTIC->labelWithPrice());
        $this->assertSame('Automated Health Report — $49', AuditTier::AUTOMATED->labelWithPrice());
        $this->assertSame('Expert Audit — $999', AuditTier::EXPERT->labelWithPrice());
    }
}
