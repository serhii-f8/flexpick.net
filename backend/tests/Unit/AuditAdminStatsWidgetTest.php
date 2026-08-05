<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
use Tests\TestCase;

class AuditAdminStatsWidgetTest extends TestCase
{
    public function test_every_status_belongs_to_exactly_one_bucket(): void
    {
        $buckets = AuditAdminStatsWidget::statusBuckets();
        $flat = collect($buckets)->flatten()->all();

        $allStatuses = collect(AuditRequestStatus::cases())->map(fn (AuditRequestStatus $c) => $c->value)->sort()->values()->all();
        $this->assertSame($allStatuses, collect($flat)->sort()->values()->all());
        $this->assertCount(count($flat), array_unique($flat), 'a status must not appear in more than one bucket');
    }

    public function test_expert_review_is_its_own_bucket(): void
    {
        $buckets = AuditAdminStatsWidget::statusBuckets();

        $this->assertSame([AuditRequestStatus::EXPERT_REVIEW->value], $buckets['expert_review']);
    }
}
