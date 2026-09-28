<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditAwaitingCreditTest extends FeatureTest
{
    public function test_awaiting_credit_is_a_terminal_status(): void
    {
        $this->assertSame(AuditRequest::TRIAGE_TERMINAL, AuditRequest::statusTriage()[AuditRequestStatus::AWAITING_CREDIT->value]);
    }

    public function test_awaiting_credit_has_a_label_and_the_closed_color(): void
    {
        $mapper = app(AuditRequestStatusMapper::class);

        $this->assertSame('Needs more credit', $mapper->mapForDisplay('awaiting_credit'));
        $this->assertSame('danger', $mapper->mapColor('awaiting_credit'));
    }

    public function test_the_dashboard_tells_the_customer_to_buy_credit_and_run_again(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_CREDIT->value]);

        $hint = AuditRequestResource::statusDescription($request);

        $this->assertStringContainsString("weren't charged", $hint);
        $this->assertStringContainsString('run a new audit', $hint);
        $this->assertSame('failed', AuditRequestResource::timelineViewData($request)['steps'][2]['state']);
    }

    public function test_sizing_columns_default_to_unsized_with_no_extras(): void
    {
        $request = AuditRequest::factory()->create()->fresh();

        $this->assertNull($request->run_count);
        $this->assertSame(0, $request->extra_metered_runs);
        $this->assertSame(0, $request->extra_purchased_runs);
    }
}
