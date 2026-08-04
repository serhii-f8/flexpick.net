<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\SensitivePathMatcher;
use Tests\Feature\FeatureTest;

class SensitivePathMatcherTest extends FeatureTest
{
    public function test_sensitive_domain_paths_match(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        foreach ([
            'app/Http/Controllers/AuthController.php',
            'app/Policies/OrderPolicy.php',
            'src/billing/checkout.ts',
            'app/Services/PaymentProviders/Stripe.php',
            'app/Http/Controllers/UploadController.php',
            'lib/crypto/password_hash.rb',
        ] as $path) {
            $this->assertTrue($matcher->matches($path), "{$path} should be sensitive");
        }
    }

    public function test_ordinary_paths_do_not_match(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        foreach ([
            'app/Models/Post.php',
            'resources/views/welcome.blade.php',
            'src/utils/format-date.ts',
        ] as $path) {
            $this->assertFalse($matcher->matches($path), "{$path} should not be sensitive");
        }
    }

    public function test_matching_is_case_insensitive_and_directory_aware(): void
    {
        $matcher = app(SensitivePathMatcher::class);

        $this->assertTrue($matcher->matches('App/AUTH/Guard.php'));
    }
}
