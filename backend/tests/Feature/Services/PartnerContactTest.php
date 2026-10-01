<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\PaymentProviderConstants;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use App\Models\PaymentProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditRequestService;
use App\Services\PartnerPricingResolver;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

/**
 * A referred visitor is the referrer's customer: every page they see names
 * the referrer as their contact instead of FlexPick, and the operator email
 * their contact form produces is CC'd to the referrer.
 */
class PartnerContactTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.referral.enabled' => true, 'audit.admin_email' => 'admin@flexpick.net']);
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
    }

    private string $referrerEmail;

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function partner(): array
    {
        $this->referrerEmail = 'rita-'.Str::random(10).'@partner.test';
        $tenant = $this->createActivePartnerTenant();
        $referrer = $this->createUser($tenant, attributes: ['name' => 'Rita Referrer', 'email' => $this->referrerEmail]);
        $code = app(ReferralService::class)->getOrCreateReferralCode($referrer)->code;

        return [$tenant, $referrer, $code];
    }

    public function test_the_pricing_page_shows_the_referrer_instead_of_flexpick(): void
    {
        [, , $code] = $this->partner();

        $this->withCookie(config('partner.cookie_name'), $code)
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee('Rita Referrer')
            ->assertSee('mailto:'.$this->referrerEmail, false)
            ->assertDontSee('info@flexpick.net');
    }

    public function test_a_user_attributed_to_a_partner_sees_the_referrer(): void
    {
        [$tenant] = $this->partner();

        $this->actingAs($this->createReferredUser($tenant))
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee($this->referrerEmail);
    }

    public function test_an_unreferred_visitor_still_sees_flexpick(): void
    {
        $this->get(route('privacy-policy'))
            ->assertSee('info@flexpick.net')
            ->assertDontSee('@partner.test');
    }

    public function test_the_contact_endpoint_names_the_referrer_for_the_landing_site(): void
    {
        [, , $code] = $this->partner();

        $this->withCredentials()
            ->withCookie(config('partner.cookie_name'), $code)
            ->getJson(route('partner.contact'))
            ->assertOk()
            ->assertExactJson(['partner' => ['name' => 'Rita Referrer', 'email' => $this->referrerEmail, 'code' => $code]]);
    }

    public function test_the_contact_endpoint_is_empty_without_a_referral(): void
    {
        $this->getJson(route('partner.contact'))->assertOk()->assertExactJson(['partner' => null]);
    }

    public function test_the_contact_form_keeps_only_a_live_partner_code(): void
    {
        Mail::fake();
        [, , $code] = $this->partner();

        $this->postJson('/api/audit-requests', ['name' => 'Ada', 'email' => $ada = 'ada-'.Str::random(10).'@example.com', 'referral_code' => $code])->assertCreated();
        $this->postJson('/api/audit-requests', ['name' => 'Bob', 'email' => $bob = 'bob-'.Str::random(10).'@example.com', 'referral_code' => 'REF-NOPE'])->assertCreated();

        $this->assertSame($code, AuditRequest::where('email', $ada)->firstOrFail()->meta['referral_code'] ?? null);
        $this->assertArrayNotHasKey('referral_code', AuditRequest::where('email', $bob)->firstOrFail()->meta);
    }

    public function test_the_operator_email_is_ccd_to_the_referrer(): void
    {
        Mail::fake();
        Queue::fake();
        [, , $code] = $this->partner();
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'meta' => ['referral_code' => $code],
        ]);

        app(AuditRequestService::class)->routeVerified($request);

        Mail::assertQueued(
            NewAuditRequestAdminNotification::class,
            fn ($mail) => $mail->hasTo('admin@flexpick.net') && $mail->hasCc($this->referrerEmail),
        );
    }

    public function test_an_unreferred_request_is_not_ccd(): void
    {
        Mail::fake();
        Queue::fake();
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        app(AuditRequestService::class)->routeVerified($request);

        Mail::assertQueued(
            NewAuditRequestAdminNotification::class,
            fn ($mail) => $mail->hasTo('admin@flexpick.net') && $mail->cc === [],
        );
    }
}
