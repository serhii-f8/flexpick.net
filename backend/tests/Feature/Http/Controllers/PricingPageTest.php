<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_authenticated_user_can_view_pricing(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->withExceptionHandling();
        $response = $this->get(route('pricing'));

        $response->assertRedirect(route('login'));
    }
}
