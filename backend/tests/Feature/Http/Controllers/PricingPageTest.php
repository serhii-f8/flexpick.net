<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_pricing_page_renders(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
    }

    public function test_pricing_page_shows_auth_links_for_guests(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertSee(route('login'));
        $response->assertSee(route('register'));
    }
}
