<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditTier;
use App\Filament\Admin\Widgets\AuditCostWidget;
use App\Models\AuditAiCall;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditCostReporter;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditCostWidgetTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        AuditAiCall::query()->delete();
        AuditReport::query()->delete();
        AuditRequest::query()->delete();

        config()->set('audit.cost_window_days', 30);
        config()->set('audit.model_pricing', [
            'priced-model' => ['input' => 5.0, 'output' => 25.0],
        ]);
    }

    /** One delivered deep_ai run: tier-1 call + deep-review call. */
    private function deepAiRun(): AuditRequest
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DEEP_AI->value]);

        AuditAiCall::factory()->for($request)->create([
            'model' => 'priced-model', 'input_tokens' => 133_701, 'output_tokens' => 3_171,
        ]);
        AuditAiCall::factory()->for($request)->deepReview()->create([
            'model' => 'priced-model', 'input_tokens' => 178_399, 'output_tokens' => 9_198,
        ]);
        AuditReport::factory()->for($request, 'auditRequest')->create();

        return $request;
    }

    public function test_it_reports_cost_per_delivered_report_from_the_ledger(): void
    {
        $this->deepAiRun();

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::DEEP_AI->value];

        // $0.7478 tier-1 + $1.1219 deep review
        $this->assertEqualsWithDelta(1.869725, $spend->spendUsd, 0.000001);
        $this->assertSame(2, $spend->calls);
        $this->assertSame(1, $spend->reports);
        $this->assertEqualsWithDelta(1.869725, $spend->costPerReport(), 0.000001);
        $this->assertTrue($spend->isComplete());
    }

    public function test_retried_spend_raises_cost_per_report_rather_than_hiding(): void
    {
        $request = $this->deepAiRun();

        // The Aug-14 shape: a delivery failure re-runs the pipeline, so a
        // second tier-1 call is billed against the same single report.
        AuditAiCall::factory()->for($request)->create([
            'model' => 'priced-model', 'input_tokens' => 133_701, 'output_tokens' => 3_314,
        ]);

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::DEEP_AI->value];

        $this->assertSame(1, $spend->reports);
        $this->assertEqualsWithDelta(2.62108, $spend->costPerReport(), 0.00001);
    }

    public function test_an_unsized_call_marks_the_total_a_floor(): void
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DEEP_AI->value]);
        AuditAiCall::factory()->for($request)->failed()->create(['model' => 'priced-model']);

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::DEEP_AI->value];

        $this->assertSame(1, $spend->unsizedCalls);
        $this->assertFalse($spend->isComplete());

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditCostWidget::class)
            ->assertSee(__('AI spend'))
            ->assertSee('unknown cost');
    }

    public function test_an_unpriced_model_is_never_counted_as_free(): void
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);
        AuditAiCall::factory()->for($request)->create(['model' => 'nobody-priced-this']);

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::AUTOMATED->value];

        $this->assertSame(0.0, $spend->spendUsd);
        $this->assertSame(1, $spend->unsizedCalls);
    }

    public function test_reports_with_no_recorded_spend_do_not_dilute_the_average(): void
    {
        $this->deepAiRun();

        // A report from before the ledger existed, or from seeded demo data:
        // real delivery, no cost on file. Counting it would halve the figure.
        $legacy = AuditRequest::factory()->create(['tier' => AuditTier::DEEP_AI->value]);
        AuditReport::factory()->for($legacy, 'auditRequest')->create();

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::DEEP_AI->value];

        $this->assertSame(1, $spend->reports);
        $this->assertEqualsWithDelta(1.869725, $spend->costPerReport(), 0.000001);
    }

    public function test_calls_outside_the_window_are_excluded(): void
    {
        $request = $this->deepAiRun();
        $request->aiCalls()->update(['created_at' => now()->subDays(31)]);

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::DEEP_AI->value];

        $this->assertSame(0.0, $spend->spendUsd);
        $this->assertSame(0, $spend->calls);
    }

    public function test_spend_with_no_delivery_shows_as_a_problem_not_an_average(): void
    {
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value]);
        AuditAiCall::factory()->for($request)->create([
            'model' => 'priced-model', 'input_tokens' => 133_701, 'output_tokens' => 3_314,
        ]);

        $spend = app(AuditCostReporter::class)->byTier()[AuditTier::EXPERT->value];

        // An average over zero reports is not zero.
        $this->assertNull($spend->costPerReport());
        $this->assertGreaterThan(0, $spend->spendUsd);

        Livewire::actingAs($this->createAdminUser())
            ->test(AuditCostWidget::class)
            ->assertSee(__('AI cost per audit'))
            // Three decimals under a dollar: $0.751, not a rounded-away "$0.75".
            ->assertSee(__(':spend spent, nothing delivered', ['spend' => '$0.751']));
    }

    public function test_a_quiet_window_renders_every_tier_without_inventing_a_price(): void
    {
        Livewire::actingAs($this->createAdminUser())
            ->test(AuditCostWidget::class)
            ->assertSee(AuditTier::DIAGNOSTIC->label())
            ->assertSee(AuditTier::EXPERT->label())
            ->assertSee(__('No runs in window'))
            ->assertSee(__('AI spend'));
    }

    public function test_the_widget_is_admin_only(): void
    {
        $this->assertFalse(AuditCostWidget::canView());
    }
}
