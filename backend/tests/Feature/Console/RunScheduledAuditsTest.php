<?php

namespace Tests\Feature\Console;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class RunScheduledAuditsTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_a_schedule_runs_at_its_own_tier(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditFunding::ALLOWANCE, $request->funding);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_exhausted_tier_is_skipped_not_downgraded(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 0);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        // FeatureTest does not roll back between tests, so this must stay
        // scoped to the user under test rather than a global count.
        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }
}
