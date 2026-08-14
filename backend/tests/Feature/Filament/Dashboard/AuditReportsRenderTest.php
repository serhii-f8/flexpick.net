<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Models\AuditReport;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsRenderTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_the_page_lists_every_tier(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('Free Diagnostic')
            ->assertSee('Automated Health Report')
            ->assertSee('Deep AI Code Review')
            ->assertSee('Expert Audit');
    }

    public function test_a_report_held_for_expert_review_is_visible(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        // Let the factory build its own request, then bend that request into
        // the held state -- this does not assume the report factory's FK name.
        $report = AuditReport::factory()->create(['user_id' => $user->id]);
        $report->auditRequest->update([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/held',
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('In expert review');
    }
}
