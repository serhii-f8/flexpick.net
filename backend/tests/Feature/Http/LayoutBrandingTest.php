<?php

namespace Tests\Feature\Http;

use Tests\Feature\FeatureTest;

class LayoutBrandingTest extends FeatureTest
{
    /**
     * Pages rendered through the shared `app` layout must show the FlexPick
     * wordmark and the dark landing canvas.
     */
    public function test_app_layout_pages_render_flexpick_branding_on_dark_canvas(): void
    {
        $user = $this->createUser();

        // pricing requires authentication
        $response = $this->actingAs($user)->get(route('pricing'));
        $response->assertOk();
        $response->assertSee('data-brand="flexpick"', false);
        $response->assertSee('bg-ink', false);

        // terms and privacy are public
        foreach (['/terms-of-service', '/privacy-policy'] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('data-brand="flexpick"', false);
            $response->assertSee('bg-ink', false);
        }
    }

    public function test_focus_layout_pages_render_flexpick_branding_on_dark_canvas(): void
    {
        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('data-brand="flexpick"', false);
            $response->assertSee('bg-ink', false);
        }
    }
}
