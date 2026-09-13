<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    /**
     * The list is the workspace's: a run a member launched, a pre-signup
     * request claimed into the workspace (no user_id yet), and nothing that
     * belongs to another workspace -- or to none.
     */
    public function test_list_shows_workspace_audits_only(): void
    {
        $user = User::factory()->create(['email' => 'list-owner@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/mine-by-id']);
        AuditRequest::factory()->create(['user_id' => null, 'tenant_id' => $tenant->id, 'email' => 'list-owner@example.com', 'repo_url' => 'https://github.com/acme/mine-claimed']);
        AuditRequest::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'repo_url' => 'https://github.com/acme/not-mine']);
        AuditRequest::factory()->create(['email' => 'list-owner@example.com', 'repo_url' => 'https://github.com/acme/not-mine-unclaimed']);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('mine-by-id');
        $response->assertSee('mine-claimed');
        $response->assertDontSee('not-mine');
    }

    public function test_list_names_the_member_who_requested_each_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $teammate = User::factory()->create(['name' => 'Teammate Requester']);
        $tenant->users()->attach($teammate);

        AuditRequest::factory()->create(['user_id' => $teammate->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/by-teammate']);
        AuditRequest::factory()->create(['user_id' => null, 'tenant_id' => $tenant->id, 'name' => 'Guest Submitter', 'repo_url' => 'https://github.com/acme/by-guest']);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Requested by'))
            ->assertSee('Teammate Requester')
            // A claimed pre-signup request has no member yet; fall back to the submitted name.
            ->assertSee('Guest Submitter');
    }

    public function test_list_shows_the_tier_and_price_each_audit_ran_at(): void
    {
        $user = User::factory()->create(['email' => 'tier-column@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/tier-deep',
            'tier' => AuditTier::DEEP_AI->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/tier-free',
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(AuditTier::DEEP_AI->labelWithPrice())
            ->assertSee(AuditTier::DIAGNOSTIC->labelWithPrice())
            // Nothing on this page paints a tier the user does not own.
            ->assertDontSee(AuditTier::EXPERT->labelWithPrice());
    }

    public function test_foreign_audit_view_is_not_found(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $foreign = AuditRequest::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

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
            'tenant_id' => $tenant->id,
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
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(config('audit.github_account'));
    }

    public function test_view_shows_scores_and_report_links_for_completed_audit(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertSee('55'); // overall score from AuditReportFactory payload
        $response->assertSee(__('Open report'));
        $response->assertSee(__('Download PDF'));
    }

    public function test_view_hides_report_actions_and_results_while_in_expert_review(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertDontSee(__('Open report'));
        $response->assertDontSee(__('Download PDF'));
        $response->assertDontSee(__('Overall score'));
        $response->assertDontSee(__('Category scores'));
    }

    public function test_category_scores_render_as_bars_not_a_joined_string(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $audit->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 68, 'security' => 80, 'testing' => 44]],
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('role="meter"', false)
            ->assertDontSee('Security: 80 · Testing: 44');
    }

    public function test_navigation_visible_for_fresh_user_with_only_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditRequestResource::shouldRegisterNavigation());
    }

    /**
     * The production default is zero free runs, so a directly registered user
     * has no request, no free run and no subscription. The audit nav must
     * still register: every tier is priced, and hiding it left them with no
     * in-app route to a purchase at all.
     */
    public function test_navigation_visible_for_a_fresh_signup_at_the_production_default(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->assertSame(0, (int) config('audit.free_reports_limit'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditRequestResource::shouldRegisterNavigation());
    }

    public function test_navigation_hidden_without_audits_allowance_free_runs_or_a_buyable_tier(): void
    {
        // An empty catalog is what makes this a real negative now: with one,
        // any authenticated user can always reach a purchase.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
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

    public function test_list_shows_short_repository_names_and_tucks_the_source_column_away(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/short-list-name',
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(ListAuditRequests::class)
            ->assertSee('acme/short-list-name')
            ->assertTableColumnFormattedStateSet('repo_url', 'acme/short-list-name', $audit)
            ->assertTableColumnExists('source', fn (Column $column): bool => $column->isToggledHiddenByDefault())
            ->assertSee('fp-band-watch');
    }

    public function test_view_is_titled_by_the_repository_and_leads_with_the_result(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/view-title',
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $html = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee('acme/view-title')
            ->assertDontSee(__('View Audit'))
            ->assertSee('Fixture summary.')
            ->assertSee(__('needs work'))
            ->assertSee(__('Report sent'))
            ->getContent();

        $this->assertLessThan(
            strpos($html, __('Submitted by')),
            strpos($html, 'Fixture summary.'),
            'The result should render above the request details.'
        );
    }

    public function test_view_tells_a_pending_user_what_happens_next(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Waiting for email confirmation.'))
            ->assertSee(__('Report sent'))
            ->assertDontSee('role="meter"', false);
    }
}
