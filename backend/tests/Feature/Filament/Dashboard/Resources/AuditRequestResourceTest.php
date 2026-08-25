<?php

namespace Tests\Feature\Filament\Dashboard\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
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

    public function test_list_shows_the_tier_and_price_each_audit_ran_at(): void
    {
        $user = User::factory()->create(['email' => 'tier-column@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/tier-deep',
            'tier' => AuditTier::DEEP_AI->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
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
            ->assertSee(config('audit.github_account'));
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

    public function test_view_hides_report_actions_and_results_while_in_expert_review(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $audit = AuditRequest::factory()->verified()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $audit->id, 'user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful();

        $response->assertDontSee(__('View online'));
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
}
