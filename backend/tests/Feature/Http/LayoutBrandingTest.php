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
        $response = $this->get(route('pricing'));
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
     * The email layout, PDF invoices and OG images all read these two files.
     * They shipped as the SaaSykit mark; this pins the FlexPick replacement
     * so a deploy can never silently regress to the boilerplate logo.
     */
    public function test_the_raster_logos_are_the_flexpick_mark(): void
    {
        foreach (['logo-dark.png', 'logo-light.png'] as $file) {
            $path = public_path('images/'.$file);

            $this->assertFileExists($path);
            $this->assertSame('image/png', mime_content_type($path));

            [$width, $height] = getimagesize($path);
            // The SaaSykit originals were 560x133; the FlexPick mark is 300x54.
            $this->assertSame(300, $width, $file);
            $this->assertSame(54, $height, $file);
        }
    }

    public function test_the_email_layout_carries_the_flexpick_logo_and_no_saasykit_text(): void
    {
        $html = view('components.layouts.email', ['slot' => 'body'])->render();

        $this->assertStringContainsString('images/logo-dark.png', $html);
        $this->assertStringNotContainsStringIgnoringCase('saasykit', $html);
    }
}
