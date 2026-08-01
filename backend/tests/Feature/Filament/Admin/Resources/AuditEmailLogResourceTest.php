<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Mail\Audit\StoredAuditEmail;
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

            return $mail->subject === 'Your codebase health report is ready'
                && $mail->hasTo('resend-target@example.com');
        });

        $this->assertSame(2, $log->fresh()->attempts);
        $this->assertSame(AuditEmailLog::STATUS_SENT, $log->fresh()->status);
    }
}
