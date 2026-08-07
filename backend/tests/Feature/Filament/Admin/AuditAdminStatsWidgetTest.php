<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminStatsWidgetTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        AuditEmailLog::query()->delete();
        AuditRequest::query()->delete();

        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
        config()->set('health.flexpick.mail_failure.window_hours', 24);
        config()->set('audit.expert_review_sla_hours', 24);
    }

    public function test_a_clean_system_shows_every_problem_tile_quiet_and_unlinked(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Audit operations'))
            ->assertSee(__('Failed audits'))
            ->assertSee(__('All clear'))
            // Nothing to click means nothing to chase.
            ->assertDontSee('activeTab=failed')
            ->assertDontSee('activeTab=stuck')
            ->assertDontSee('activeTab=needs-action');
    }

    public function test_a_failed_audit_lights_its_tile_and_links_to_the_failed_tab(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::FAILED->value,
            'created_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Failed audits'))
            ->assertSee('activeTab=failed');
    }

    public function test_the_failed_tile_is_windowed_to_the_last_day(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::FAILED->value,
            'created_at' => now()->subDays(3),
        ]);

        // An all-time failure count never returns to zero, so it could never go
        // quiet -- which is the property the whole block depends on.
        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('All clear'))
            ->assertDontSee('activeTab=failed');
    }

    public function test_a_stuck_audit_links_to_the_stuck_tab(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subHours(3),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Stuck in pipeline'))
            ->assertSee('activeTab=stuck');
    }

    public function test_manual_action_tile_breaks_the_count_down_by_status(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Needs manual action'))
            ->assertSee('activeTab=needs-action')
            ->assertSee('2 '.mb_strtolower(__('Awaiting repo access')))
            ->assertSee('1 '.mb_strtolower(__('Awaiting payment')));
    }

    public function test_an_overdue_expert_review_lights_its_tile(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(26),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Expert review overdue'))
            ->assertSee(__('oldest waiting :hours h', ['hours' => 26]));
    }

    public function test_email_tile_reports_the_seven_day_delivery_rate(): void
    {
        $admin = $this->createAdminUser();

        // 1 failure in 10 attempts over the week => 90% delivered.
        AuditEmailLog::factory()->count(9)->create([
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDays(3),
        ]);
        AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(3),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Email failures'))
            ->assertSee(__(':rate% delivered over 7 days', ['rate' => 90]));
    }

    public function test_the_ops_block_ignores_the_dashboard_date_filter(): void
    {
        // "What is broken" is always now. A date-ranged failure count is a trap,
        // so this widget must not opt into the page filter. Pinned structurally
        // because a later refactor would otherwise "fix" it silently.
        $this->assertNotContains(
            InteractsWithPageFilters::class,
            class_uses_recursive(AuditAdminStatsWidget::class),
        );
    }
}
