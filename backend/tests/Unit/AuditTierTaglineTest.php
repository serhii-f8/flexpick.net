<?php

namespace Tests\Unit;

use App\Constants\AuditTier;
use Tests\TestCase;

class AuditTierTaglineTest extends TestCase
{
    public function test_every_tier_has_a_one_line_tagline_for_the_picker(): void
    {
        foreach (AuditTier::cases() as $tier) {
            $this->assertNotSame('', $tier->tagline(), $tier->value.' needs a tagline');
            $this->assertLessThan(90, mb_strlen($tier->tagline()), $tier->value.' tagline should fit on one line');
        }
    }

    public function test_the_taglines_build_on_each_other(): void
    {
        $this->assertStringContainsString('fix-first plan', AuditTier::DIAGNOSTIC->tagline());
        $this->assertStringContainsString('key files', AuditTier::DEEP_AI->tagline());
        $this->assertStringContainsString('developer', AuditTier::EXPERT->tagline());
    }
}
