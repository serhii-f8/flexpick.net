<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditRequestStatusTest extends FeatureTest
{
    public function test_status_page_renders_current_label(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee(__('Analyzing your repository'));
    }

    public function test_status_json_includes_signed_report_url_when_sent(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::SENT->value]);
        AuditReport::factory()->locked()->create(['audit_request_id' => $request->id]);

        $json = $this->getJson($this->signedJsonUrl($request));

        $json->assertOk()
            ->assertJsonPath('done', true)
            ->assertJsonPath('failed', false);
        $this->assertStringContainsString('/reports/', $json->json('report_url'));
    }

    public function test_status_json_flags_failure(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::FAILED->value]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('failed', true)
            ->assertJsonPath('report_url', null);
    }

    public function test_unsigned_status_request_is_rejected(): void
    {
        $this->withExceptionHandling();
        $request = AuditRequest::factory()->verified()->create();

        $this->get('/audit-requests/'.$request->uuid.'/status')->assertForbidden();
    }

    private function signedJsonUrl(AuditRequest $request): string
    {
        return URL::signedRoute('audit-requests.status.json', ['auditRequest' => $request->uuid]);
    }
}
