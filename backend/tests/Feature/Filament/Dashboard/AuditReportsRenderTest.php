<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
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
            ->assertSee('In expert review')
            ->assertDontSee(route('reports.download', $report));
    }

    public function test_each_listed_report_shows_the_tier_it_ran_at(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        $report = AuditReport::factory()->create(['user_id' => $user->id]);
        $report->auditRequest->update([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/deep',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::SENT->value,
        ]);

        $html = Livewire::test(AuditReports::class)->assertOk()->html();

        // A bare assertSee would be vacuous here: the launch form's tier picker
        // above the list already renders all four labels. Anchor on the repo
        // heading and look only at what follows it, so this proves the LIST row
        // carries the tier.
        $listMarkup = substr($html, (int) strpos($html, 'github.com/acme/deep'));

        $this->assertStringContainsString(AuditTier::DEEP_AI->label(), $listMarkup);
        $this->assertStringNotContainsString(AuditTier::EXPERT->label(), $listMarkup);
    }
}
