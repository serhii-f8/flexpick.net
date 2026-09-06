<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Widgets\LatestHealthWidget;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class LatestHealthWidgetTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_shows_the_latest_repository_score_with_its_band_and_delta(): void
    {
        [$user, $tenant] = $this->userWithAllowance(5);

        $older = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/health',
            'status' => AuditRequestStatus::SENT->value,
            'created_at' => now()->subDays(7),
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $older->id,
            'user_id' => $user->id,
            'payload' => ['scores' => ['overall' => 47], 'risks' => []],
            'created_at' => now()->subDays(7),
        ]);

        $newer = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/health',
            'status' => AuditRequestStatus::SENT->value,
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $newer->id,
            'user_id' => $user->id,
            'payload' => [
                'scores' => ['overall' => 55],
                'risks' => [
                    ['title' => 'No tests', 'impact' => 'high', 'evidence' => '', 'recommendation' => ''],
                    ['title' => 'Old deps', 'impact' => 'medium', 'evidence' => '', 'recommendation' => ''],
                ],
            ],
        ]);

        $this->actAsTenantUser($user, $tenant);

        Livewire::test(LatestHealthWidget::class)
            ->assertSee('acme/health')
            ->assertSee('55')
            ->assertSee(__('needs work'))
            ->assertSee('+8')
            ->assertSee('1 high')
            ->assertSee('1 medium')
            ->assertSee(__('Open report'));
    }

    public function test_invites_a_first_audit_when_nothing_has_been_scored_yet(): void
    {
        [$user, $tenant] = $this->userWithAllowance(5);

        $this->actAsTenantUser($user, $tenant);

        Livewire::test(LatestHealthWidget::class)
            ->assertSee(__('No health report yet'))
            ->assertSee(__('Run your first audit'))
            ->assertDontSee(__('Open report'));
    }

    public function test_a_pending_audit_is_shown_as_in_progress_rather_than_as_a_score(): void
    {
        [$user, $tenant] = $this->userWithAllowance(5);

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/pending',
            'status' => AuditRequestStatus::ANALYZING->value,
        ]);

        $this->actAsTenantUser($user, $tenant);

        Livewire::test(LatestHealthWidget::class)
            ->assertSee('acme/pending')
            ->assertSee(__('Analyzing now'))
            ->assertDontSee(__('Open report'));
    }

    public function test_hidden_without_audit_access(): void
    {
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
        $user = User::factory()->create();

        $this->actAsTenantUser($user);

        $this->assertFalse(LatestHealthWidget::canView());
    }
}
