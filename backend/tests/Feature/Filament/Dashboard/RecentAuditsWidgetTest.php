<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Widgets\RecentAuditsWidget;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class RecentAuditsWidgetTest extends FeatureTest
{
    public function test_shows_last_five_own_audits_only(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        foreach (range(1, 6) as $i) {
            AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => "https://github.com/acme/recent-{$i}",
                'status' => AuditRequestStatus::SENT->value,
                'created_at' => now()->subDays(7 - $i),
            ]);
        }
        AuditRequest::factory()->create(['repo_url' => 'https://github.com/acme/foreign-recent']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(RecentAuditsWidget::class)
            ->assertSee('recent-6')
            ->assertSee('recent-2')
            ->assertDontSee('recent-1')       // 6th-newest falls off the 5-row list
            ->assertDontSee('foreign-recent'); // isolation
    }

    public function test_shows_score_and_delta_against_previous_audit_of_same_repo(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $older = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
            'created_at' => now()->subDays(7),
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $older->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 60]],
            'created_at' => now()->subDays(7),
        ]);

        $newer = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $newer->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 68]],
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(RecentAuditsWidget::class)
            ->assertSee('68')
            ->assertSee('+8');
    }

    public function test_visible_for_fresh_user_with_only_free_runs(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(RecentAuditsWidget::canView());
    }

    public function test_hidden_without_audits_allowance_or_free_runs(): void
    {
        config(['audit.free_reports_limit' => 0]);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(RecentAuditsWidget::canView());
    }
}
