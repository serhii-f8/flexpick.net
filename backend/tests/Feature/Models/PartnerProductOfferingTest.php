<?php

namespace Tests\Feature\Models;

use App\Models\OneTimeProduct;
use App\Models\PartnerProductOffering;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerProductOfferingTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant_and_a_one_time_product(): void
    {
        $tenant = $this->createTenant();
        $product = OneTimeProduct::factory()->create();
        $offering = PartnerProductOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'one_time_product_id' => $product->id,
            'price' => 2500,
        ]);

        $this->assertTrue($offering->tenant->is($tenant));
        $this->assertTrue($offering->oneTimeProduct->is($product));
    }

    public function test_tenant_and_product_combination_must_be_unique(): void
    {
        $tenant = $this->createTenant();
        $product = OneTimeProduct::factory()->create();
        PartnerProductOffering::factory()->create(['tenant_id' => $tenant->id, 'one_time_product_id' => $product->id]);

        $this->expectException(QueryException::class);
        PartnerProductOffering::factory()->create(['tenant_id' => $tenant->id, 'one_time_product_id' => $product->id]);
    }
}
