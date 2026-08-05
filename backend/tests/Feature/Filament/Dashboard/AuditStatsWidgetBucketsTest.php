<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Widgets\AuditStatsWidget;
use Tests\Feature\FeatureTest;

class AuditStatsWidgetBucketsTest extends FeatureTest
{
    public function test_every_status_belongs_to_exactly_one_bucket(): void
    {
        $buckets = AuditStatsWidget::statusBuckets();
        $flat = collect($buckets)->flatten()->all();

        $allStatuses = collect(AuditRequestStatus::cases())->map(fn (AuditRequestStatus $c) => $c->value)->sort()->values()->all();
        $this->assertSame($allStatuses, collect($flat)->sort()->values()->all());
        $this->assertCount(count($flat), array_unique($flat), 'a status must not appear in more than one bucket');
    }
}
