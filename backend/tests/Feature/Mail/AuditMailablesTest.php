<?php

namespace Tests\Feature\Mail;

use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditMailablesTest extends FeatureTest
{
    public function test_received_mailable_renders(): void
    {
        $request = AuditRequest::factory()->create();

        $mailable = new AuditRequestReceived($request);
        $mailable->assertSeeInHtml($request->name);
    }

    public function test_report_ready_attaches_pdf_and_links(): void
    {
        Storage::disk('local')->put('audit-reports/fixture.pdf', '%PDF-1.4 fixture');
        $report = AuditReport::factory()->create(['pdf_path' => 'audit-reports/fixture.pdf']);

        $mailable = new AuditReportReady($report, 'https://app.example.com/reports/abc?signature=x');
        $mailable->assertSeeInHtml('https://app.example.com/reports/abc?signature=x');
        $mailable->assertHasAttachment(
            Attachment::fromStorageDisk('local', 'audit-reports/fixture.pdf')
                ->as('codebase-health-report.pdf')
                ->withMime('application/pdf')
        );
    }
}
