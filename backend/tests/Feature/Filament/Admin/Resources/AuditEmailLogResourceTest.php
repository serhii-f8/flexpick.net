<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Mail\StoredAuditEmail;
use App\Models\AuditEmailLog;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditEmailLogResourceTest extends FeatureTest
{
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
}
