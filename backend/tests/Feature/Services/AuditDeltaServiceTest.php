<?php

namespace Tests\Feature\Services;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditDeltaService;
use Tests\Feature\FeatureTest;

class AuditDeltaServiceTest extends FeatureTest
{
    private function reportWithOverall(string $email, string $repoUrl, int $overall): AuditReport
    {
        $request = AuditRequest::factory()->verified()->create(['email' => $email, 'repo_url' => $repoUrl]);
        $payload = AuditReport::factory()->raw()['payload'];
        $payload['scores'] = array_map(fn () => $overall, $payload['scores']);

        return AuditReport::factory()->locked()->create(['audit_request_id' => $request->id, 'payload' => $payload]);
    }

    public function test_deltas_compare_against_previous_report_of_same_email_and_repo(): void
    {
        $this->reportWithOverall('delta@example.com', 'https://github.com/acme/app', 40);
        $current = $this->reportWithOverall('delta@example.com', 'https://github.com/acme/app/', 55); // note trailing slash

        $result = app(AuditDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $this->assertSame(15, $result['deltas']['overall']);
        $this->assertSame(15, $result['deltas']['testing']);
    }

    public function test_first_report_for_a_repo_has_no_deltas(): void
    {
        $this->reportWithOverall('delta2@example.com', 'https://github.com/acme/other', 40);
        $current = $this->reportWithOverall('delta2@example.com', 'https://github.com/acme/app', 55);

        $this->assertNull(app(AuditDeltaService::class)->deltasFor($current));
    }

    public function test_other_users_reports_are_not_compared(): void
    {
        $this->reportWithOverall('someone-else@example.com', 'https://github.com/acme/app', 40);
        $current = $this->reportWithOverall('delta3@example.com', 'https://github.com/acme/app', 55);

        $this->assertNull(app(AuditDeltaService::class)->deltasFor($current));
    }

    public function test_does_not_compare_across_scoring_versions(): void
    {
        $previous = $this->reportWithOverall('delta4@example.com', 'https://github.com/acme/app', 40);
        $previous->update(['scoring_version' => 1]);

        $current = $this->reportWithOverall('delta4@example.com', 'https://github.com/acme/app', 90);
        $current->update(['scoring_version' => 2]);

        $this->assertNull(app(AuditDeltaService::class)->deltasFor($current->fresh()));
    }
}
