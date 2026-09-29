<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Constants\AwaitingCreditReason;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
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

    public function test_expert_review_label_is_not_generic_processing(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee(__('Your report is complete and is being reviewed by our expert auditor before delivery.'));
    }

    public function test_status_json_does_not_report_expert_review_as_done(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditReport::factory()->create(['audit_request_id' => $request->id]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('done', false)
            ->assertJsonPath('failed', false)
            ->assertJsonPath('report_url', null);
    }

    public function test_dashboard_status_description_for_expert_review(): void
    {
        $request = AuditRequest::factory()->make(['status' => AuditRequestStatus::EXPERT_REVIEW->value]);

        $this->assertSame(
            'Your report is complete and is being reviewed by our expert auditor before delivery.',
            AuditRequestResource::statusDescription($request),
        );
    }

    /**
     * awaiting_credit is a refunded terminal close: the page must say so and
     * stop polling, not show "Processing" forever.
     */
    public function test_awaiting_credit_has_its_own_label_and_is_reported_as_closed(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::AWAITING_CREDIT->value]);
        $label = 'This repository needs more audit credit — check your email for next steps.';

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee($label);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('label', $label)
            ->assertJsonPath('done', false)
            ->assertJsonPath('closed', true)
            ->assertJsonPath('report_url', null);
    }

    public function test_not_analyzable_is_reported_as_closed(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::NOT_ANALYZABLE->value]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('label', "We couldn't reach your repository — check your email for next steps")
            ->assertJsonPath('done', false)
            ->assertJsonPath('closed', true)
            ->assertJsonPath('report_url', null);
    }

    public function test_an_in_flight_request_is_not_closed(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $this->getJson($this->signedJsonUrl($request))
            ->assertOk()
            ->assertJsonPath('closed', false)
            ->assertJsonPath('failed', false);
    }

    /**
     * The page's poller must stop on a closed request, like it does on a failure.
     */
    public function test_the_status_page_stops_polling_on_a_closed_request(): void
    {
        $request = AuditRequest::factory()->verified()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $this->get(app(AuditRequestService::class)->statusUrl($request))
            ->assertOk()
            ->assertSee('if (data.failed || data.closed)', false);
    }

    public function test_dashboard_hint_for_a_too_large_repo_asks_them_to_contact_us(): void
    {
        $request = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_CREDIT->value,
            'awaiting_credit_reason' => AwaitingCreditReason::TOO_LARGE->value,
        ]);

        $hint = AuditRequestResource::statusDescription($request);

        $this->assertStringContainsString('Above the self-serve size limit — contact us for a custom quote', $hint);
        $this->assertStringContainsString('reply to the email we sent you', $hint);
        $this->assertStringContainsString((string) config('app.support_email'), $hint);
        $this->assertStringNotContainsString('buy credit', $hint);
    }

    public function test_dashboard_hint_for_insufficient_credit_asks_them_to_buy_credit(): void
    {
        foreach ([AwaitingCreditReason::INSUFFICIENT->value, null] as $reason) {
            $request = AuditRequest::factory()->create([
                'status' => AuditRequestStatus::AWAITING_CREDIT->value,
                'awaiting_credit_reason' => $reason,
            ]);

            $hint = AuditRequestResource::statusDescription($request);

            $this->assertStringContainsString('buy credit or upgrade', $hint);
            $this->assertStringNotContainsString('custom quote', $hint);
        }
    }

    public function test_dashboard_hint_asks_a_formerly_connected_workspace_to_reconnect(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://gitlab.com/acme/private',
            'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
            'git_reconnect_required' => true,
        ]);

        $this->assertStringContainsString('reconnect your GitLab account', AuditRequestResource::statusDescription($request));
        $this->assertSame(
            GitConnections::getUrl(panel: 'dashboard', tenant: $tenant),
            AuditRequestResource::timelineViewData($request)['statusHintLink']['url'] ?? null,
        );

        $html = view('filament.dashboard.partials.audit-timeline', AuditRequestResource::timelineViewData($request))->render();
        $this->assertStringContainsString('href="'.e(GitConnections::getUrl(panel: 'dashboard', tenant: $tenant)).'"', $html);
    }

    public function test_dashboard_hint_keeps_the_connect_copy_without_a_connection(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://gitlab.com/acme/private',
            'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
        ]);

        $hint = AuditRequestResource::statusDescription($request);

        $this->assertStringContainsString('connect the matching account', $hint);
        $this->assertStringNotContainsString('reconnect', $hint);
        $this->assertNull(AuditRequestResource::timelineViewData($request)['statusHintLink']);
    }

    private function signedJsonUrl(AuditRequest $request): string
    {
        return URL::signedRoute('audit-requests.status.json', ['auditRequest' => $request->uuid]);
    }
}
