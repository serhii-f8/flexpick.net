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
}
