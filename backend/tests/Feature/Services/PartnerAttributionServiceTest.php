<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class PartnerAttributionServiceTest extends FeatureTest
{
    private function service(): PartnerAttributionService
    {
        return app(PartnerAttributionService::class);
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** @return array{0: Tenant, 1: User, 2: string} tenant, member, member's personal code */
    private function partnerWithCode(): array
    {
        $tenant = $this->activePartnerTenant();
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        return [$tenant, $member, $code];
    }

    private function setRequestCookie(?string $value): void
    {
        $this->app['request']->cookies->set(config('partner.cookie_name'), $value);
    }

    public function test_a_members_personal_code_resolves_to_their_active_partner_tenant(): void
    {
        [$tenant, , $code] = $this->partnerWithCode();

        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($tenant));
    }

    public function test_a_code_of_a_user_with_no_partner_tenant_resolves_to_null(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertNull($this->service()->resolveTenantForCode($code));
    }

    public function test_an_unknown_code_resolves_to_null(): void
    {
        $this->assertNull($this->service()->resolveTenantForCode('REF-DOESNOTEXIST'));
    }

    public function test_a_lapsed_partner_tenant_does_not_resolve(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->subDay(),
        ]);
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertNull($this->service()->resolveTenantForCode($code));
    }

    public function test_a_member_of_two_partner_tenants_resolves_to_the_lowest_id(): void
    {
        $first = $this->activePartnerTenant();
        $second = $this->activePartnerTenant();
        $member = $this->createUser($first);
        $second->users()->attach($member);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($first));
    }

    public function test_cookie_code_reads_a_valid_value_and_rejects_junk(): void
    {
        $this->setRequestCookie('REF-ABCDEFGHIJKL');
        $this->assertSame('REF-ABCDEFGHIJKL', $this->service()->cookieCode());

        $this->setRequestCookie('');
        $this->assertNull($this->service()->cookieCode());

        $this->setRequestCookie(str_repeat('x', 65));
        $this->assertNull($this->service()->cookieCode());

        $this->app['request']->cookies->remove(config('partner.cookie_name'));
        $this->assertNull($this->service()->cookieCode());
    }

    public function test_cookie_code_falls_back_to_a_cookie_queued_on_this_request(): void
    {
        // The middleware queues the cookie on the very request that carried
        // the referral link; the browser only sends it back on the NEXT one.
        // Reading the queued value is what makes partner prices show on the
        // first page load rather than after a refresh.
        $this->app['request']->cookies->remove(config('partner.cookie_name'));
        $this->service()->queueCookie('REF-QUEUEDCODE2');

        $this->assertSame('REF-QUEUEDCODE2', $this->service()->cookieCode());
    }

    public function test_has_partner_cookie_requires_the_code_to_resolve(): void
    {
        [, , $code] = $this->partnerWithCode();

        $this->setRequestCookie('REF-NOTAPARTNER0');
        $this->assertFalse($this->service()->hasPartnerCookie());

        $this->setRequestCookie($code);
        $this->assertTrue($this->service()->hasPartnerCookie());
    }

    public function test_queue_cookie_queues_an_http_only_year_long_cookie(): void
    {
        $this->service()->queueCookie('REF-QUEUEDCODE1');

        $cookie = Cookie::queued(config('partner.cookie_name'));

        $this->assertNotNull($cookie);
        $this->assertSame('REF-QUEUEDCODE1', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(365)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_attribute_sets_the_partner_from_the_cookie_once(): void
    {
        [$tenant, , $code] = $this->partnerWithCode();
        $this->setRequestCookie($code);
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::REGISTRATION);

        $user->refresh();
        $this->assertTrue($user->partnerTenant->is($tenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertNotNull($user->partner_attributed_at);
    }

    public function test_attribute_does_not_overwrite_an_existing_attribution(): void
    {
        $original = $this->createTenant();
        [, , $otherCode] = $this->partnerWithCode();
        $this->setRequestCookie($otherCode);
        $user = User::factory()->create(['partner_tenant_id' => $original->id]);

        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($original->id, $user->fresh()->partner_tenant_id);
    }

    public function test_attribute_ignores_a_cookie_that_does_not_resolve(): void
    {
        $this->setRequestCookie('REF-NOTAPARTNER0');
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::REGISTRATION);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_attribute_is_a_no_op_without_a_cookie(): void
    {
        $this->app['request']->cookies->remove(config('partner.cookie_name'));
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_the_first_concurrent_attribution_wins(): void
    {
        $winner = $this->activePartnerTenant();
        [, , $loserCode] = $this->partnerWithCode();
        $user = User::factory()->create();

        User::whereKey($user->id)->update([
            'partner_tenant_id' => $winner->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => PartnerAttributionSource::REGISTRATION->value,
        ]);

        // $user is stale in memory (partner_tenant_id null), like a second request
        // that read the row before the first write landed.
        $this->setRequestCookie($loserCode);
        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($winner->id, $user->fresh()->partner_tenant_id);
    }

    public function test_code_for_tenant_returns_a_code_that_resolves_back_to_it(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->createUser($tenant); // no personal code yet — it must be created on demand

        $code = $this->service()->codeForTenant($tenant);

        $this->assertNotNull($code);
        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($tenant));
    }

    public function test_code_for_tenant_is_null_for_a_non_partner_or_memberless_tenant(): void
    {
        $plain = $this->createTenant();
        $this->createUser($plain);
        $this->assertNull($this->service()->codeForTenant($plain));

        $empty = $this->activePartnerTenant();
        $this->assertNull($this->service()->codeForTenant($empty));
    }

    public function test_refresh_cookie_rewrites_the_cookie_from_the_database(): void
    {
        [$tenant] = $this->partnerWithCode();
        [, , $strayCode] = $this->partnerWithCode();
        $user = User::factory()->create(['partner_tenant_id' => $tenant->id]);
        $this->setRequestCookie($strayCode);

        $this->service()->refreshCookieFromDatabase($user);

        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($this->service()->resolveTenantForCode($queued->getValue())->is($tenant));
        $this->assertNotSame($strayCode, $queued->getValue());
    }

    /**
     * A stale cookie left in place for an unattributed user is exactly how a
     * later, unrelated login on the same browser gets mis-attributed to
     * whoever the previous session's cookie pointed to -- so this must
     * actively clear it, not leave it as-is.
     */
    public function test_refresh_cookie_forgets_the_cookie_for_an_unattributed_user(): void
    {
        $user = User::factory()->create();

        $this->service()->refreshCookieFromDatabase($user);

        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($queued->getExpiresTime() < now()->getTimestamp());
        $this->assertEmpty($queued->getValue());
    }

    public function test_forget_cookie_queues_an_expired_cookie(): void
    {
        $this->service()->forgetCookie();

        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($queued->getExpiresTime() < now()->getTimestamp());
        $this->assertEmpty($queued->getValue());
    }
}
