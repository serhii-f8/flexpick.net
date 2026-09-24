<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

/**
 * Audits belong to the workspace, not to the member who clicked: a run one
 * member launches is stamped with the workspace and shows up in every
 * teammate's history, run page and schedules -- and in nobody else's.
 */
class WorkspaceSharedAuditHistoryTest extends FeatureTest
{
    private Tenant $tenant;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRepositoryAccess();
        config(['audit.free_reports_limit' => 3]);
        $this->alice = $this->createUser();
        $this->bob = $this->createUser();
        $this->tenant = Tenant::factory()->create(['created_by' => $this->alice->id]);
        $this->tenant->users()->attach([$this->alice->id, $this->bob->id]);
    }

    public function test_a_run_launched_by_one_member_is_owned_by_the_workspace(): void
    {
        Queue::fake();
        $this->actingAs($this->alice);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->alice)
            ->test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/example/shared')
            ->call('launchAudit');

        $request = AuditRequest::where('repo_url', 'https://github.com/example/shared')->firstOrFail();
        $this->assertSame($this->tenant->id, $request->tenant_id);
        $this->assertSame($this->alice->id, $request->user_id);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_a_teammate_sees_the_run_in_audit_history(): void
    {
        $request = AuditRequest::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->alice->id,
            'repo_url' => 'https://github.com/example/teammate-visible',
        ]);
        AuditRequest::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'repo_url' => 'https://github.com/example/foreign',
        ]);

        $this->actingAs($this->bob);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->bob)
            ->test(ListAuditRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('teammate-visible')
            ->assertDontSee('foreign')
            ->assertSee($this->alice->name);
    }

    public function test_a_member_of_another_workspace_sees_nothing(): void
    {
        AuditRequest::factory()->create(['tenant_id' => $this->tenant->id, 'repo_url' => 'https://github.com/example/private']);
        $outsider = $this->createUser();
        $elsewhere = Tenant::factory()->create(['created_by' => $outsider->id]);
        $elsewhere->users()->attach($outsider);

        $this->actingAs($outsider);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($elsewhere);

        Livewire::actingAs($outsider)
            ->test(ListAuditRequests::class)
            ->assertDontSee('example/private');
    }

    public function test_the_run_page_lists_a_teammates_reports_and_schedules(): void
    {
        $request = AuditRequest::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->alice->id,
            'repo_url' => 'https://github.com/example/run-page',
        ]);
        AuditReport::factory()->create(['audit_request_id' => $request->id, 'user_id' => $this->alice->id]);
        AuditSchedule::create([
            'user_id' => $this->alice->id,
            'tenant_id' => $this->tenant->id,
            'repo_url' => 'https://github.com/example/run-page',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_week' => 1,
        ]);

        $this->actingAs($this->bob);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->bob)
            ->test(AuditReports::class)
            ->assertSee('example/run-page');
    }
}
