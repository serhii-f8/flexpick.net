<?php

namespace Tests\Feature\Services\AuditReport;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\BusinessReportPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

        $output = Pdf::loadView('reports.technical-pdf', [
            'report' => $report->fresh(),
            'payload' => $report->payload,
        ])->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }

    public function test_the_developer_pdf_has_no_plain_terms_section(): void
    {
        $report = AuditReport::factory()->create([
            'payload' => AuditReport::factory()->definition()['payload'] + [
                'client_summary' => [
                    'overview' => 'Plain-words overview for the owner.',
                    'findings' => [[
                        'what' => 'Nothing is checked before a release.',
                        'consequence' => 'A change can break checkout.',
                        'gain' => 'Fewer surprises.',
                    ]],
                ],
            ],
        ]);

        $html = view('reports.technical-pdf', ['report' => $report->fresh(), 'payload' => $report->payload])->render();

        $this->assertStringNotContainsString(__('In plain terms'), $html);
        $this->assertStringNotContainsString('Plain-words overview for the owner.', $html);
        $this->assertStringStartsWith('%PDF-', Pdf::loadHTML($html)->output());
    }

    public function test_the_business_pdf_renders_with_its_charts(): void
    {
        $report = AuditReport::factory()->unlocked()->create([
            'payload' => AuditReport::factory()->definition()['payload'] + [
                'groups' => [],
                'client_summary' => [
                    'overview' => 'Owner overview.',
                    'verdict' => 'Owner verdict.',
                    'areas' => [['area' => 'testing', 'meaning' => 'm', 'status' => 's']],
                    'findings' => [['what' => 'Owner finding.', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'now', 'business_area' => 'costs']],
                    'roadmap' => [['step' => 'Step one', 'outcome' => 'o', 'effort' => 'M']],
                    'questions' => ['Owner question?'],
                ],
            ],
        ]);
        $business = app(BusinessReportPresenter::class)->present($report, null, 50, collect());

        $html = view('reports.business-pdf', ['report' => $report->fresh(), 'business' => $business])->render();

        $this->assertStringContainsString('Owner verdict.', $html);
        $this->assertStringContainsString('Owner question?', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('Fixture summary.', $html);
        $output = Pdf::loadHTML($html)->output();
        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }

    public function test_unlocking_writes_both_pdfs(): void
    {
        Storage::fake('local');
        Mail::fake();
        $report = AuditReport::factory()->locked()->create();

        app(AuditReportService::class)->unlock($report);

        $report->refresh();
        Storage::disk('local')->assertExists($report->pdf_path);
        Storage::disk('local')->assertExists($report->technical_pdf_path);
        $this->assertStringEndsWith('-technical.pdf', $report->technical_pdf_path);
    }
}
