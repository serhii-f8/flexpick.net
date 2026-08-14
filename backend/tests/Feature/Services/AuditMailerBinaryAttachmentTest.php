<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditReportReady;
use App\Models\AuditEmailLog;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditMail\AuditMailer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

/**
 * AuditMailer renders the mailable to log its body, and Mailable::render() resolves
 * attachments onto the SAME instance ($rawAttachments). Every audit mailable is
 * ShouldQueue, so that instance is then serialized into a queue payload — and a PDF's
 * bytes are not valid UTF-8, so json_encode() of the payload fails. The report that
 * carries a PDF is exactly the one a customer paid for, so this failed the delivery of
 * every finished report while the ones without an attachment went out fine.
 */
class AuditMailerBinaryAttachmentTest extends FeatureTest
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    public function test_a_report_with_a_pdf_attachment_still_queues(): void
    {
        // 'sync' never builds a payload, so it cannot show this failure. The
        // database driver runs the same createPayload()/json_encode() path redis
        // does in production, without needing a redis server in the suite.
        $this->useDatabaseQueue();
        Storage::fake('local');

        $report = $this->reportWithBinaryPdf();

        $log = app(AuditMailer::class)->send(
            new AuditReportReady($report, 'https://reports.example/x'),
            $report->auditRequest->email,
            $report->auditRequest,
        );

        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->last_error);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_rendering_for_the_log_does_not_load_the_pdf_onto_the_sent_mailable(): void
    {
        $this->useDatabaseQueue();
        Storage::fake('local');

        $report = $this->reportWithBinaryPdf();
        $mailable = new AuditReportReady($report, 'https://reports.example/x');

        app(AuditMailer::class)->send($mailable, $report->auditRequest->email, $report->auditRequest);

        $this->assertNotFalse(
            json_encode(serialize($mailable)),
            'The mailable must stay queue-serializable after AuditMailer has rendered it.',
        );
    }

    /**
     * This app queues on redis, so it has no jobs table of its own; the driver is
     * borrowed here only for its payload encoding.
     */
    private function useDatabaseQueue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
        ]);

        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function reportWithBinaryPdf(): AuditReport
    {
        /** @var AuditRequest $request */
        $request = AuditRequest::factory()->create(['email' => 'pdf-attach@example.com']);
        $path = 'audit-reports/binary.pdf';

        // Real PDFs are binary; \xFF\xFE is not valid UTF-8, which is all this needs.
        Storage::disk('local')->put($path, "%PDF-1.4\n\xFF\xFE\x00binary\n%%EOF");

        /** @var AuditReport $report */
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'pdf_path' => $path,
        ]);

        return $report;
    }
}
