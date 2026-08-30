<?php

namespace Tests\Feature\Models;

use App\Models\OneTimeProduct;
use App\Models\Product;
use Tests\Feature\FeatureTest;

class ResellerQuotaKeysTest extends FeatureTest
{
    public function test_product_stores_reseller_quota_keys_as_array(): void
    {
        $product = Product::factory()->create(['reseller_quota_keys' => ['audit_diagnostic_credits']]);

        $this->assertSame(['audit_diagnostic_credits'], $product->fresh()->reseller_quota_keys);
    }

    public function test_one_time_product_stores_reseller_quota_keys_as_array(): void
    {
        $product = OneTimeProduct::factory()->create(['reseller_quota_keys' => ['bonus_credits']]);

        $this->assertSame(['bonus_credits'], $product->fresh()->reseller_quota_keys);
    }
}
