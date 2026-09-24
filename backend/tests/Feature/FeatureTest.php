<?php

namespace Tests\Feature;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\RepositoryCloner;
use App\Services\ReferralService;
use Database\Seeders\Testing\TestingDatabaseSeeder;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Base class for the feature suite — abstract so PHPUnit does not collect it as
 * a test class of its own. Its filename matches the `*Test.php` suffix, and a
 * concrete class with no test methods is a test-runner warning, which PHPUnit 11
 * reports through a non-zero exit code and would turn CI red on every run.
 */
abstract class FeatureTest extends TestCase
{
    protected static bool $setUpHasRunOnce = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::$setUpHasRunOnce) {
            $this->artisan('migrate:fresh');
            $this->seed(TestingDatabaseSeeder::class);

            static::$setUpHasRunOnce = true;
        }

        $this->configureDefaultCurrency();
        $this->withoutExceptionHandling();
        $this->withoutVite();
    }

    /**
     * Stand in for the `git ls-remote` access check, so dashboard launches in
     * tests never reach the network. Every other cloner method stays real.
     */
    protected function fakeRepositoryAccess(bool $reachable = true): void
    {
        $this->partialMock(RepositoryCloner::class, function (MockInterface $mock) use ($reachable): void {
            $expectation = $mock->shouldReceive('preflight');

            $reachable
                ? $expectation->andReturnNull()
                : $expectation->andThrow(AuditNotAnalyzableException::accessDenied('Repository is not publicly accessible: https://github.com/acme/private'));
        });
    }

    protected function createUser(?Tenant $tenant = null, array $tenantPermissions = [], array $attributes = [])
    {
        $user = User::factory()->create($attributes);

        if ($tenant !== null) {
            $tenant->users()->attach($user);

            foreach ($tenantPermissions as $permission) {
                $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot->givePermissionTo($permission);
            }
        }

        return $user;
    }

    protected function createTenant()
    {
        return Tenant::factory()->create();
    }

    protected function createAdminUser()
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        $user->each(function ($user) {
            $user->assignRole('admin');
        });

        return $user;
    }

    /**
     * A workspace whose Partner Plan is active, so its members' referral
     * codes resolve to it and it can quote partner prices.
     */
    protected function createActivePartnerTenant(): Tenant
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /**
     * A user attributed to a partner, i.e. one who registered through that
     * partner's referral link. The storefront and checkout only open to such
     * users (RequirePartnerAttribution).
     */
    protected function createReferredUser(?Tenant $partnerTenant = null, ?Tenant $tenant = null, array $tenantPermissions = [], array $attributes = []): User
    {
        $partnerTenant ??= $this->createActivePartnerTenant();

        return $this->createUser($tenant, $tenantPermissions, $attributes + [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => PartnerAttributionSource::REGISTRATION->value,
        ]);
    }

    /**
     * Subsequent requests carry the partner cookie a referral-link visit
     * leaves behind, so a guest passes RequirePartnerAttribution.
     */
    protected function asReferredGuest(?Tenant $partnerTenant = null): static
    {
        $partnerTenant ??= $this->createActivePartnerTenant();
        $code = app(ReferralService::class)->getOrCreateReferralCode($this->createUser($partnerTenant))->code;

        return $this->withCookie(config('partner.cookie_name'), $code);
    }

    protected function configureDefaultCurrency(): void
    {
        config()->set('app.default_currency', 'USD');
    }
}
