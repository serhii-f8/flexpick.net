<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class HomeControllerTest extends FeatureTest
{
    public function test_guest_is_redirected_to_pricing(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('pricing'));
    }

    public function test_user_with_tenant_is_redirected_to_dashboard(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);

        $response = $this->get(route('home'));

        $response->assertRedirect(route('filament.dashboard.pages.dashboard', ['tenant' => $tenant]));
    }

    public function test_user_without_tenant_is_redirected_to_pricing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->get(route('home'));

        $response->assertRedirect(route('pricing'));
    }
}
