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

    /**
     * /pricing requires authentication, so a guest-facing page linking to it
     * is a dead end that only ever bounces the visitor to login. The header
     * and nav CTAs were fixed to route to registration instead; this guards
     * against a future change silently reintroducing a route('pricing') link
     * on a page a guest can actually reach.
     */
    public function test_no_guest_reachable_page_links_to_the_gated_pricing_route(): void
    {
        foreach (['/terms-of-service', '/privacy-policy', route('login'), route('register')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertDontSee(route('pricing'), false);
        }
    }
}
