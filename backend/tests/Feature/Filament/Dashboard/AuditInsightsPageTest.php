<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Pages\AuditInsights;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AuditReport\AuditInsightsService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTest;
use Tests\Feature\Services\AuditInsightsServiceTest;

class AuditInsightsPageTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Platform-wide stats over a shared suite database: see AuditInsightsServiceTest.
        DB::beginTransaction();
        AuditRequest::query()->delete();
        Cache::forget(AuditInsightsService::CACHE_KEY);
        config()->set('audit.insights_min_codebases', 2);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Cache::forget(AuditInsightsService::CACHE_KEY);

        parent::tearDown();
    }

    private function actAsMemberOf(Tenant $tenant): void
    {
        $this->actingAs($this->createUser($tenant));
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);
    }

    private function partnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    public function test_a_partner_sees_aggregated_stats_and_no_client_detail(): void
    {
        $leaky = AuditInsightsServiceTest::completedAudit('https://github.com/acme-private/one', groups: [['secrets.credential', 'critical']]);
        AuditInsightsServiceTest::completedAudit('https://github.com/acme-private/two', ['test_files' => 0, 'test_ratio_pct' => 0.0]);

        $tenant = $this->partnerTenant();
        $this->actAsMemberOf($tenant);

        $this->get(AuditInsights::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Exposed API keys or secrets')
            ->assertSee('No automated tests')
            ->assertSee('50% of the codebases we audit have API keys or other secrets committed to the code.')
            ->assertDontSee('acme-private')
            ->assertDontSee($leaky->email)
            ->assertDontSee('secret-client-dir');
    }

    public function test_a_partner_sees_a_notice_until_the_sample_is_large_enough(): void
    {
        AuditInsightsServiceTest::completedAudit('https://github.com/acme/one');

        $tenant = $this->partnerTenant();
        $this->actAsMemberOf($tenant);

        $this->get(AuditInsights::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Not enough audits yet')
            ->assertDontSee('Most common issues');
    }

    public function test_a_workspace_that_is_not_a_partner_cannot_open_it(): void
    {
        $tenant = $this->createTenant();
        $this->actAsMemberOf($tenant);

        $this->assertFalse(AuditInsights::canAccess());

        $this->expectException(HttpException::class);
        $this->get(AuditInsights::getUrl(tenant: $tenant));
    }
}
