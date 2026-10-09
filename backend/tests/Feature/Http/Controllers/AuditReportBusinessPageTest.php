<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AuditReport;
use App\Services\AuditReport\AuditReportService;
use Tests\Feature\FeatureTest;

class AuditReportBusinessPageTest extends FeatureTest
{
    private function v6Payload(): array
    {
        return AuditReport::factory()->definition()['payload'] + [
            'groups' => [['rule_family' => 'php:unused-imports', 'directory' => 'app/Http/Controllers', 'severity' => 'high', 'count' => 3, 'narrative' => ['what' => 'w', 'affects' => 'a', 'benefit' => 'b']]],
            'file_findings' => [['path' => 'app/Services/Billing/Webhook.php', 'line' => 12, 'title' => 'Unsigned webhook', 'severity' => 'critical', 'category' => 'security', 'evidence' => 'e', 'recommendation' => 'r', 'effort' => 'S']],
            'client_summary' => [
                'overview' => 'Your app works, but checkout is fragile.',
                'verdict' => 'Solid base, two urgent risks.',
                'areas' => [['area' => 'testing', 'meaning' => 'How much is checked automatically.', 'status' => 'Almost nothing is checked.']],
                'findings' => [['what' => 'Anyone can mark an order as paid.', 'consequence' => 'Free orders and lost revenue.', 'gain' => 'Revenue always matches orders.', 'urgency' => 'now', 'business_area' => 'customers']],
                'roadmap' => [['step' => 'Lock down payment confirmations', 'outcome' => 'Only real payments complete orders', 'effort' => 'S']],
                'questions' => ['How do we confirm a payment is real?'],
            ],
        ];
    }

    private function url(AuditReport $report): string
    {
        return app(AuditReportService::class)->signedUrl($report);
    }

    public function test_an_unlocked_business_report_shows_every_section(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Solid base, two urgent risks.')
            ->assertSee('Your app works, but checkout is fragile.')
            ->assertSee(__('Your codebase at a glance'))
            ->assertSee('Almost nothing is checked.')
            ->assertSee(__('How serious are the problems'))
            ->assertSee(__('What it affects in your business'))
            ->assertSee('Free orders and lost revenue.')
            ->assertSee(__('Fix now'))
            ->assertSee(__('Your roadmap'))
            ->assertSee('Only real payments complete orders')
            ->assertSee(__('Questions to ask your team'))
            ->assertSee('How do we confirm a payment is real?')
            ->assertSee('<svg', false)
            ->assertDontSee(__('Unlock full report'));
    }

    public function test_the_business_page_shows_no_rule_families_or_file_paths(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertDontSee('php:unused-imports')
            ->assertDontSee('app/Http/Controllers')
            ->assertDontSee('app/Services/Billing/Webhook.php')
            ->assertDontSee('Fixture summary.'); // the technical summary
    }

    public function test_a_locked_business_report_shows_headlines_and_charts_but_hides_details(): void
    {
        $report = AuditReport::factory()->locked()->create(['payload' => $this->v6Payload()]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Solid base, two urgent risks.')
            ->assertSee('Anyone can mark an order as paid.')
            ->assertSee(__('Fix now'))
            ->assertSee('<svg', false)
            ->assertDontSee('Free orders and lost revenue.')
            ->assertDontSee('Revenue always matches orders.')
            ->assertDontSee('Almost nothing is checked.')
            ->assertDontSee('Only real payments complete orders')
            ->assertDontSee('How do we confirm a payment is real?')
            ->assertSee(__('Unlock full report'))
            ->assertSee('/unlock');
    }

    public function test_a_v5_report_renders_on_the_business_page_without_v6_sections(): void
    {
        $payload = $this->v6Payload();
        $payload['client_summary'] = ['overview' => 'Old overview.', 'findings' => [['what' => 'Old finding.', 'consequence' => 'c', 'gain' => 'g']]];
        $report = AuditReport::factory()->unlocked()->create(['payload' => $payload]);

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee('Old overview.')
            ->assertSee('Old finding.')
            ->assertDontSee(__('Your roadmap'))
            ->assertDontSee(__('Questions to ask your team'))
            ->assertDontSee(__('What it affects in your business'));
    }

    public function test_a_report_without_a_client_summary_still_renders_the_business_page(): void
    {
        $report = AuditReport::factory()->unlocked()->create();

        $this->get($this->url($report))
            ->assertOk()
            ->assertSee(__('Your full findings are in the developer report.'))
            ->assertSee(__('Your codebase at a glance'))
            ->assertDontSee('Fixture summary.');
    }

    public function test_the_business_page_links_to_the_developer_report(): void
    {
        $report = AuditReport::factory()->unlocked()->create(['payload' => $this->v6Payload()]);

        $html = $this->get($this->url($report))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/reports/'.$report->uuid.'/technical\?[^"]*signature=#', $html);
        $this->assertStringContainsString(__('Forward the developer report to your engineer'), $html);
    }

    public function test_the_sample_business_report_carries_every_business_section(): void
    {
        $response = $this->get('/reports/sample')->assertOk();

        foreach (['Your codebase at a glance', 'How serious are the problems', 'What it affects in your business', 'Your roadmap', 'Questions to ask your team', 'Expert\'s note'] as $section) {
            $response->assertSee(__($section));
        }
        $this->assertDoesNotMatchRegularExpression('/\\$\s?\d/', strip_tags($response->getContent()));
    }
}
