<?php

namespace Tests\Feature\Models;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditModelsTest extends FeatureTest
{
    public function test_audit_request_gets_uuid_and_has_report(): void
    {
        $request = AuditRequest::factory()->create();
        $report = AuditReport::factory()->create(['audit_request_id' => $request->id]);

        $this->assertNotEmpty($request->uuid);
        $this->assertNotEmpty($report->uuid);
        $this->assertTrue($request->report->is($report));
        $this->assertTrue($report->auditRequest->is($request));
        $this->assertSame('uuid', $report->getRouteKeyName());
        $this->assertIsArray($report->payload);
    }
}
