<?php

namespace Tests\Feature\Services\AuditReport;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\Feature\FeatureTest;

class PdfRenderTest extends FeatureTest
{
    /**
     * DomPDF fails on CSS a browser tolerates -- flexbox and grid in
     * particular. The PDF is a paid deliverable, so it gets its own guard.
     */
    public function test_report_pdf_renders_to_a_pdf_document(): void
    {
        $user = User::factory()->create();
        $request = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
        ]);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'user_id' => $user->id,
            'payload' => [
                'summary' => 'Fixture summary.',
                'scores' => ['overall' => 68, 'security' => 80],
                'risks' => [[
                    'title' => 'No tests',
                    'impact' => 'high',
                    'evidence' => '0 test files',
                    'recommendation' => 'Add a smoke suite',
                ]],
                'fix_first_plan' => [[
                    'step' => 'Add CI',
                    'why' => 'Catch regressions',
                    'effort' => 'S',
                ]],
                'file_findings' => [[
                    'path' => 'src/App.php',
                    'severity' => 'high',
                    'line' => 12,
                    'title' => 'Unvalidated input',
                    'evidence' => 'Evidence here.',
                    'recommendation' => 'Validate it.',
                    'effort' => 'small',
                ]],
                'deep_review' => ['files_reviewed' => 1, 'files_selected' => 1],
            ],
        ]);

        $output = Pdf::loadView('reports.audit', [
            'report' => $report->fresh(),
            'payload' => $report->payload,
        ])->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }
}
