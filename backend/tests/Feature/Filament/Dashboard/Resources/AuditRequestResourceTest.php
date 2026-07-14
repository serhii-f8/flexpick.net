<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    public function test_list_shows_own_audits_only(): void
    {
        $user = User::factory()->create(['email' => 'list-owner@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create(['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/mine-by-id']);
        AuditRequest::factory()->create(['user_id' => null, 'email' => 'list-owner@example.com', 'repo_url' => 'https://github.com/acme/mine-by-email']);
        AuditRequest::factory()->create(['repo_url' => 'https://github.com/acme/not-mine']);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('mine-by-id');
        $response->assertSee('mine-by-email');
        $response->assertDontSee('not-mine');
    }

    public function test_foreign_audit_view_is_not_found(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $foreign = AuditRequest::factory()->create();

        $this->actingAs($user);
        $this->expectException(ModelNotFoundException::class);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $foreign->uuid], true, 'dashboard', tenant: $tenant));
    }

    public function test_view_shows_failure_reason_for_failed_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::FAILED->value,
            'failure_reason' => 'Clone timed out after 120s',
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('Clone timed out after 120s');
    }

    public function test_view_shows_invite_instructions_for_awaiting_access(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('flexpick-audit');
    }

    public function test_view_shows_scores_and_report_links_for_completed_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('55'); // overall score from AuditReportFactory payload
        $response->assertSee(__('View online'));
        $response->assertSee(__('Download PDF'));
    }

    public function test_navigation_hidden_without_audits_or_allowance(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(AuditRequestResource::shouldRegisterNavigation());
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }
}
