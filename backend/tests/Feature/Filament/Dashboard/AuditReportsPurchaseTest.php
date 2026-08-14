<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsPurchaseTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_an_exhausted_paid_tier_creates_an_intent_and_redirects(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit')
            ->assertRedirect(route('buy.product', ['productSlug' => 'audit-deep-ai']));

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertSame(AuditFunding::PURCHASE, $request->funding);
        $this->assertSame('https://github.com/acme/app', $request->repo_url);

        $this->assertSame(
            $request->uuid,
            UserParameter::where('user_id', $user->id)
                ->where('name', HandleAuditTierOrder::INTENT_PARAM)
                ->value('value'),
        );

        // Nothing runs until the order lands.
        Queue::assertNothingPushed();
    }

    public function test_an_unpaid_intent_does_not_consume_quota(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        // Spend the single Deep AI credit, then try again.
        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/one')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/two')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        // The pending purchase must not push usage past the credit that was
        // actually spent.
        $this->assertSame(
            1,
            app(\App\Services\AuditReport\AuditEntitlementService::class)
                ->runsUsedThisMonth($user, AuditTier::DEEP_AI),
        );
    }
}
