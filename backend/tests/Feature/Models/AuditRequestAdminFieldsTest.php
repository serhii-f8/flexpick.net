<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestAdminFieldsTest extends FeatureTest
{
    public function test_admin_fields_are_persistable(): void
    {
        $request = AuditRequest::factory()->create([
            'admin_context' => 'Focus on the payment module.',
            'analysis_started_at' => now()->subMinutes(5),
            'analysis_completed_at' => now(),
        ]);

        $fresh = $request->fresh();
        $this->assertSame('Focus on the payment module.', $fresh->admin_context);
        $this->assertNotNull($fresh->analysis_started_at);
        $this->assertNotNull($fresh->analysis_completed_at);
    }

    public function test_append_pipeline_log_accumulates_entries(): void
    {
        $request = AuditRequest::factory()->create();

        $request->appendPipelineLog('clone', 'Repository cloned');
        $request->appendPipelineLog('metrics', 'Metrics collected');

        $log = $request->fresh()->pipeline_log;
        $this->assertCount(2, $log);
        $this->assertSame('clone', $log[0]['step']);
        $this->assertSame('Repository cloned', $log[0]['message']);
        $this->assertArrayHasKey('at', $log[0]);
        $this->assertSame('metrics', $log[1]['step']);
    }
}
