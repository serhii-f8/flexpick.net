<?php

namespace Tests\Feature\Models;

use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditEmailLogTest extends FeatureTest
{
    public function test_log_row_persists_and_links_to_audit_request(): void
    {
        $request = AuditRequest::factory()->create();

        $log = AuditEmailLog::factory()->create([
            'audit_request_id' => $request->id,
            'mailable' => 'AuditReportReady',
            'recipient' => 'client@example.com',
            'subject' => 'Your report',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->assertSame($request->id, $log->fresh()->auditRequest->id);
        $this->assertSame('sent', $log->fresh()->status);
    }
}
