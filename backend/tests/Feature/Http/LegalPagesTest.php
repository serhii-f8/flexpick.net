<?php

namespace Tests\Feature\Http;

use Tests\Feature\FeatureTest;

class LegalPagesTest extends FeatureTest
{
    public function test_privacy_policy_is_public_and_describes_the_audit_pipeline(): void
    {
        $response = $this->get(route('privacy-policy'));

        $response->assertOk();
        // The disclosures a customer submitting a repository most needs: what
        // leaves our server, what never does, and that the clone is not kept.
        $response->assertSee('How we handle your code');
        $response->assertSee('delete the clone when the run ends', false);
        $response->assertSee('OSV.dev');
        $response->assertSee(config('legal.ai_provider'));
        $response->assertSee('secret denylist');
    }

    public function test_terms_of_service_is_public_and_covers_the_product_and_partner_terms(): void
    {
        $response = $this->get(route('terms-of-service'));

        $response->assertOk();
        $response->assertSee('Partners and resellers');
        $response->assertSee('right of withdrawal', false);
        $response->assertSee('do not carry over', false);
        $response->assertSee(config('legal.governing_law'));
    }

    public function test_legal_pages_carry_no_boilerplate_from_the_starter_kit(): void
    {
        // The shared layout prints config('app.name') and config('app.description'),
        // which the testing env still sets to the starter kit's — override both so
        // a hit means boilerplate survived in the page body, not in the chrome.
        config([
            'app.name' => 'FlexPick',
            'app.description' => 'FlexPick audits codebases.',
        ]);

        foreach (['privacy-policy', 'terms-of-service'] as $route) {
            $response = $this->get(route($route));

            $response->assertOk();
            foreach (['SaaSykit', 'saasykit.com', 'AdWords', 'remarketing', 'Data Controller means'] as $boilerplate) {
                $response->assertDontSee($boilerplate);
            }
        }
    }

    public function test_legal_pages_render_when_registration_details_are_not_configured(): void
    {
        // Address/NIP/REGON are env-supplied and blank until launch. A blank
        // value must be omitted, not printed as an empty clause.
        config([
            'legal.operator.name' => '',
            'legal.operator.address' => '',
            'legal.operator.nip' => '',
            'legal.operator.regon' => '',
        ]);

        foreach (['privacy-policy', 'terms-of-service'] as $route) {
            $response = $this->get(route($route));

            $response->assertOk();
            $response->assertDontSee('NIP:');
            $response->assertDontSee('REGON:');
        }
    }

    public function test_legal_pages_show_registration_details_once_configured(): void
    {
        config([
            'legal.operator.name' => 'Jan Kowalski',
            'legal.operator.address' => 'ul. Testowa 1, 00-001 Warszawa',
            'legal.operator.nip' => '1234567890',
        ]);

        foreach (['privacy-policy', 'terms-of-service'] as $route) {
            $response = $this->get(route($route));

            $response->assertOk();
            $response->assertSee('Jan Kowalski');
            $response->assertSee('ul. Testowa 1, 00-001 Warszawa');
            $response->assertSee('1234567890');
        }

        // The Terms weave the details into one sentence; a Blade conditional
        // inside that clause would leave a space before the comma.
        $this->assertStringNotContainsString(
            ' ,',
            preg_replace('/\s+/', ' ', $this->get(route('terms-of-service'))->getContent())
        );
    }

    public function test_legal_pages_link_to_each_other(): void
    {
        $this->get(route('privacy-policy'))->assertSee(route('terms-of-service'), false);
        $this->get(route('terms-of-service'))->assertSee(route('privacy-policy'), false);
    }
}
