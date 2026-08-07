<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Mail\StoredAuditEmail;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditEmailLogResourceTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest's migrate:fresh+seed runs once per process with no
        // rollback between tests, so earlier tests' AuditEmailLog rows would
        // otherwise leak into this class's count/ratio-sensitive assertions
        // (the failed-24h tab count, the 7-day delivery-rate percentage).
        AuditEmailLog::query()->delete();
    }

    public function test_list_renders_log_rows(): void
    {
        $admin = $this->createAdminUser();
        AuditEmailLog::factory()->create(['recipient' => 'render-check@example.com']);
        AuditEmailLog::factory()->failed()->create(['recipient' => 'broken@example.com']);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('render-check@example.com')
            ->assertSee('broken@example.com');
    }

    public function test_resend_sends_stored_subject_and_body(): void
    {
        Mail::fake();

        $admin = $this->createAdminUser();
        $log = AuditEmailLog::factory()->create([
            'attempts' => 1,
            'recipient' => 'resend-target@example.com',
            'subject' => 'Your codebase health report is ready',
            'body' => '<p>stored body</p>',
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callTableAction('resend', $log);

        Mail::assertSent(StoredAuditEmail::class, function (StoredAuditEmail $mail): bool {
            $mail->build();

            $mail->assertSeeInHtml('<p>stored body</p>', false);

            return $mail->subject === 'Your codebase health report is ready'
                && $mail->hasTo('resend-target@example.com');
        });

        $this->assertSame(2, $log->fresh()->attempts);
        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->fresh()->status);
    }

    public function test_resend_action_is_hidden_for_a_render_failed_row_with_no_stored_body(): void
    {
        $admin = $this->createAdminUser();

        $renderFailed = AuditEmailLog::factory()->create([
            'recipient' => 'render-failed@example.com',
            'subject' => '',
            'body' => '',
            'status' => AuditEmailLog::STATUS_FAILED,
            'last_error' => 'Render failed: view not found',
        ]);

        $normal = AuditEmailLog::factory()->create([
            'recipient' => 'normal@example.com',
            'subject' => 'Your codebase health report is ready',
            'body' => '<p>stored body</p>',
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->assertTableActionHidden('resend', $renderFailed)
            ->assertTableActionVisible('resend', $normal);
    }

    public function test_the_failed_24h_tab_shows_only_recent_failures(): void
    {
        $this->freezeTime();
        config()->set('health.flexpick.mail_failure.window_hours', 24);

        $admin = $this->createAdminUser();

        $recent = AuditEmailLog::factory()->create([
            'recipient' => 'recent-failure@example.com',
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subHours(2),
        ]);
        AuditEmailLog::factory()->create([
            'recipient' => 'old-failure@example.com',
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(4),
        ]);
        AuditEmailLog::factory()->create([
            'recipient' => 'fine@example.com',
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subHours(2),
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->set('activeTab', 'failed-24h')
            ->assertCanSeeTableRecords([$recent])
            ->assertCountTableRecords(1);
    }

    public function test_the_table_links_a_log_back_to_its_audit_request(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['repo_url' => 'https://github.com/example/linked']);
        AuditEmailLog::factory()->create(['audit_request_id' => $request->id]);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('https://github.com/example/linked');
    }

    public function test_the_preview_action_is_hidden_when_no_body_was_stored(): void
    {
        $admin = $this->createAdminUser();

        $empty = AuditEmailLog::factory()->create(['recipient' => 'empty@example.com', 'body' => '']);
        $stored = AuditEmailLog::factory()->create(['recipient' => 'stored@example.com', 'body' => '<p>hi</p>']);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->assertTableActionHidden('preview', $empty)
            ->assertTableActionVisible('preview', $stored);
    }

    public function test_the_preview_renders_the_body_inside_a_sandboxed_iframe(): void
    {
        $admin = $this->createAdminUser();

        $log = AuditEmailLog::factory()->create([
            'recipient' => 'preview@example.com',
            'subject' => 'Your report',
            'body' => '<style>body{color:red}</style><p>stored body</p>',
        ]);

        // Stored bodies are complete HTML documents. Rendering one inline would
        // bleed its CSS across the admin panel and run whatever it contains.
        //
        // Filament 5 renders action modal content as a Livewire partial
        // (wire:partial="action-modals"), delivered via the response's
        // `effects.partials` payload rather than inlined into the component's
        // main HTML. Testable::html()/assertSee() only ever see the main HTML
        // (confirmed: the wire:partial container is always present but empty
        // there), so this asserts against the partial payload directly via
        // Livewire's own `effects` test accessor instead.
        $test = Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->mountTableAction('preview', $log);

        $modalPartial = $test->effects['partials']['action-modals'] ?? '';

        $this->assertStringContainsString('sandbox', $modalPartial);
        $this->assertStringContainsString('srcdoc', $modalPartial);
        $this->assertStringNotContainsString('<style>body{color:red}</style>', $modalPartial);
    }

    public function test_the_header_strip_reports_the_seven_day_delivery_rate(): void
    {
        $this->freezeTime();

        $admin = $this->createAdminUser();

        AuditEmailLog::factory()->count(9)->create([
            'status' => AuditEmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDays(2),
        ]);
        AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_FAILED,
            'sent_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin);

        $this->get(AuditEmailLogResource::getUrl('index', panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Delivered (7 days)'))
            ->assertSee('90%');
    }
}
