<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditEmailLogs\Pages\ListAuditEmailLogs;
use App\Models\AuditEmailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
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

    public function test_resend_uses_mailcoach_api_for_uuid_rows(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');
        Http::fake(['http://mailcoach/api/transactional-mails/tm-7/resend' => Http::response(null, 200)]);

        $admin = $this->createAdminUser();
        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => 'tm-7', 'attempts' => 1]);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callTableAction('resend', $log);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/transactional-mails/tm-7/resend'));
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public function test_resend_falls_back_to_direct_mail_for_rows_without_uuid(): void
    {
        config()->set('services.mailcoach.endpoint', null);
        Mail::fake();

        $admin = $this->createAdminUser();
        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => null, 'attempts' => 1, 'subject' => 'Re-sub', 'body' => '<p>again</p>']);

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callTableAction('resend', $log);

        Mail::assertSent(fn (Mailable $mail) => true);
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public function test_refresh_statuses_maps_api_delivery_data(): void
    {
        config()->set('services.mailcoach.endpoint', 'http://mailcoach/api');
        config()->set('services.mailcoach.api_token', 'token');

        $log = AuditEmailLog::factory()->create(['mailcoach_uuid' => 'tm-1', 'status' => AuditEmailLog::STATUS_SENT]);
        Http::fake(['http://mailcoach/api/transactional-mails*' => Http::response(['data' => [
            ['uuid' => 'tm-1', 'delivered_at' => now()->toIso8601String()],
        ]], 200)]);

        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(ListAuditEmailLogs::class)
            ->callAction('refreshStatuses');

        $this->assertSame(AuditEmailLog::STATUS_DELIVERED, $log->fresh()->status);
    }
}
