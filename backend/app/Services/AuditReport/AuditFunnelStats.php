<?php

namespace App\Services\AuditReport;

use App\Models\AuditFunnelEvent;

class AuditFunnelStats
{
    public function counts(int $days = 30): array
    {
        $rows = AuditFunnelEvent::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        return collect(AuditFunnelRecorder::STAGES)
            ->mapWithKeys(fn (string $stage) => [$stage => (int) ($rows[$stage] ?? 0)])
            ->all();
    }
}
