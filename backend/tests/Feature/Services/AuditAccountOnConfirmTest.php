<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ListPartnerOrders;
use App\Jobs\GenerateAuditReport;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Mail\Audit\AuditAccountReady;
use App\Mail\Audit\AuditAwaitingPartnerApproval;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\CashPayments\PartnerNewPendingOrder;
use App\Models\AuditRequest;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\Order;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditRequestService;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\PartnerPricingResolver;
use App\Services\ReferralService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

/**
 * Confirming the landing form's email is the whole sign-up: it creates the
 * account (attributed to whoever invited the visitor), and when the audit has
 * to be paid for, it puts a cash order in front of the inviting partner to
 * approve.
 */
class AuditAccountOnConfirmTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.referral.enabled' => true, 'audit.admin_email' => 'admin@flexpick.net', 'audit.free_reports_limit' => 0]);
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
        $this->fakeRepositoryAccess();
    }

    /** @return array{0: Tenant, 1: User, 2: string} partner tenant, a member who approves orders, referral code */
    private function partner(): array
    {
        $tenant = $this->createActivePartnerTenant();
        $approver = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS], ['email' => 'rita-'.Str::random(8).'@partner.test']);
        $code = app(ReferralService::class)->getOrCreateReferralCode($approver)->code;

        return [$tenant, $approver, $code];
    }

    private function resells(Tenant $partner, AuditTier $tier, int $price): void
    {
        $slug = $tier->productSlug();
        $product = OneTimeProduct::where('slug', $slug)->first()
            ?? OneTimeProduct::factory()->create(['slug' => $slug, 'is_active' => true]);
        OneTimeProductPrice::updateOrCreate(
            ['one_time_product_id' => $product->id, 'currency_id' => Currency::where('code', 'USD')->first()->id],
            ['price' => $tier->priceCents()],
        );
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partner->id,
            'one_time_product_id' => $product->id,
            'price' => $price,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
    }

    private function landingRequest(array $meta = []): AuditRequest
    {
        return AuditRequest::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada-'.Str::random(10).'@example.com',
            'repo_url' => 'https://github.com/acme/app',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'email_verified_at' => null,
            'tenant_id' => null,
            'user_id' => null,
            'meta' => $meta,
        ]);
    }

    private function confirm(AuditRequest $request): TestResponse
    {
        return $this->get(app(AuditRequestService::class)->verificationUrl($request));
    }

    public function test_confirming_creates_a_verified_account_with_a_workspace_and_signs_in(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        $request = $this->landingRequest();

        $this->confirm($request)->assertRedirect();

        $user = User::where('email', $request->email)->sole();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        $request->refresh();
        $this->assertSame($user->id, $request->user_id);
        $this->assertNotNull($request->tenant_id);
        $this->assertTrue($user->tenants()->whereKey($request->tenant_id)->exists());
        Queue::assertPushed(RouteVerifiedAuditRequest::class);
    }

    public function test_a_referred_visitor_is_attributed_to_the_inviter_without_their_cookie(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        [$partner, , $code] = $this->partner();
        $request = $this->landingRequest(['referral_code' => $code]);

        $this->confirm($request);

        $this->assertSame($partner->id, User::where('email', $request->email)->sole()->partner_tenant_id);
    }

    public function test_an_existing_account_is_neither_duplicated_nor_signed_in(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        $request = $this->landingRequest();
        $existing = User::factory()->create(['email' => $request->email]);

        $this->confirm($request);

        $this->assertSame(1, User::where('email', $request->email)->count());
        $this->assertGuest();
        Mail::assertNotQueued(AuditAccountReady::class);
        $this->assertTrue($existing->is(User::where('email', $request->email)->sole()));
    }

    public function test_confirming_twice_creates_one_account(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        $request = $this->landingRequest();

        $this->confirm($request);
        auth()->logout();
        $this->confirm($request);

        $this->assertSame(1, User::where('email', $request->email)->count());
        Mail::assertQueued(AuditAccountReady::class, 1);
    }

    public function test_the_new_account_gets_a_set_password_link_that_works_once(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        $request = $this->landingRequest();
        $this->confirm($request);
        auth()->logout();

        $link = null;
        Mail::assertQueued(AuditAccountReady::class, function (AuditAccountReady $mail) use ($request, &$link): bool {
            $link = $mail->setPasswordUrl;

            return $mail->hasTo(strtolower($request->email)) && str_contains($mail->render(), e($link));
        });

        $this->get($link)->assertOk()->assertSee('Set your password');

        $this->post($link, ['password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
            ->assertRedirect(GitConnections::getUrl(panel: 'dashboard', tenant: Tenant::find($request->fresh()->tenant_id)));

        $user = User::where('email', $request->email)->sole();
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->withExceptionHandling()->get($link)->assertForbidden();
    }

    public function test_the_set_password_link_must_be_signed(): void
    {
        $this->withExceptionHandling();
        $user = User::factory()->create();

        $this->get(route('audit-account.set-password', ['user' => $user->uuid]))->assertForbidden();
    }

    public function test_the_status_page_offers_the_new_account_its_next_steps(): void
    {
        Mail::fake();
        Queue::fake([RouteVerifiedAuditRequest::class]);
        $request = $this->landingRequest();

        $this->followingRedirects()->get(app(AuditRequestService::class)->verificationUrl($request))
            ->assertSee('Set your password')
            ->assertSee('Connect GitHub, GitLab or Bitbucket');
    }

    public function test_a_referred_audit_that_needs_payment_lands_in_the_partners_orders_at_once(): void
    {
        Mail::fake();
        Queue::fake([GenerateAuditReport::class]);
        [$partner, $approver, $code] = $this->partner();
        $this->resells($partner, AuditTier::DIAGNOSTIC, 7700);
        $request = $this->landingRequest(['referral_code' => $code]);

        $this->confirm($request); // routing runs inline (sync queue)

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $request->tenant_id)->sole();
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame($partner->id, $order->partner_tenant_id);
        $this->assertTrue((bool) $order->is_local);
        $this->assertSame(7700, (int) $order->total_amount);
        $this->assertSame(PaymentProviderConstants::OFFLINE_SLUG, $order->paymentProvider->slug);

        Mail::assertQueued(PartnerNewPendingOrder::class, fn ($mail) => $mail->hasTo($approver->email));
        Mail::assertQueued(AuditAwaitingPartnerApproval::class, fn ($mail) => $mail->hasTo($request->email));
        Mail::assertNotQueued(AuditQuotaExhausted::class);

        // It is waiting on the partner's own Partner Orders page (pending tab, the default).
        auth()->logout();
        $this->actingAs($approver);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($partner);
        Livewire::test(ListPartnerOrders::class)->assertCanSeeTableRecords([$order]);

        // The partner confirms the cash arrived: the audit runs.
        $this->assertTrue(app(OrderApprovalService::class)->approveAsPartner($order, $approver, $partner));

        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->fresh()->status);
        Queue::assertPushed(GenerateAuditReport::class, fn ($job) => $job->auditRequest->is($request));
    }

    public function test_routing_again_does_not_open_a_second_order(): void
    {
        Mail::fake();
        Queue::fake([GenerateAuditReport::class]);
        [$partner, , $code] = $this->partner();
        $this->resells($partner, AuditTier::DIAGNOSTIC, 7700);
        $request = $this->landingRequest(['referral_code' => $code]);
        $this->confirm($request);

        $request->refresh()->update(['status' => AuditRequestStatus::PENDING_VERIFICATION->value]);
        app(AuditRequestService::class)->routeVerified($request);

        $this->assertSame(1, Order::withoutGlobalScopes()->where('tenant_id', $request->tenant_id)->count());
    }

    public function test_an_unreferred_audit_that_needs_payment_keeps_the_pay_now_email(): void
    {
        Mail::fake();
        $request = $this->landingRequest();

        $this->confirm($request);

        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->fresh()->status);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $request->fresh()->tenant_id)->count());
        Mail::assertQueued(AuditQuotaExhausted::class);
    }

    public function test_a_tier_the_partner_does_not_resell_opens_no_order(): void
    {
        Mail::fake();
        [, , $code] = $this->partner();
        $request = $this->landingRequest(['referral_code' => $code]);

        $this->confirm($request);

        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $request->fresh()->tenant_id)->count());
        Mail::assertNotQueued(AuditAwaitingPartnerApproval::class);
    }
}
