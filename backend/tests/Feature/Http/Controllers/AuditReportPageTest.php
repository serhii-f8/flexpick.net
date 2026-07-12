<?php

namespace Tests\Feature\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\User;
use App\Services\AuditReport\AuditReportService;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditReportPageTest extends FeatureTest
{
    private function signedUnlockUrl(AuditReport $report): string
    {
        return URL::temporarySignedRoute('reports.unlock', now()->addDay(), ['auditReport' => $report->uuid]);
    }

    public function test_locked_report_shows_titles_but_hides_details(): void
    {
        $report = AuditReport::factory()->locked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('No tests')                       // risk title visible
            ->assertDontSee('Add a smoke suite')          // recommendation hidden
            ->assertDontSee('0 test files')               // evidence hidden
            ->assertSee(__('Unlock full report'))
            ->assertSee('/unlock');
    }

    public function test_unlocked_report_shows_everything_and_pdf_link(): void
    {
        $report = AuditReport::factory()->unlocked()->create();
        $url = app(AuditReportService::class)->signedUrl($report);

        $this->get($url)
            ->assertOk()
            ->assertSee('Add a smoke suite')
            ->assertSee('Add CI')
            ->assertSee(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertDontSee(__('Unlock full report'));
    }

    public function test_sample_report_is_public_and_unlocked(): void
    {
        $this->get('/reports/sample')
            ->assertOk()
            ->assertSee(__('Sample report'))
            ->assertSee(__('What to fix first'));
    }

    public function test_unlock_route_stores_intent_and_redirects_to_checkout(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->locked()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get($this->signedUnlockUrl($report))
            ->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));

        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'name' => HandleAuditUnlockOrder::INTENT_PARAM,
            'value' => $report->uuid,
        ]);
    }

    public function test_unlock_route_claims_report_by_matching_email(): void
    {
        $user = User::factory()->create(['email' => 'match@example.com']);
        $report = AuditReport::factory()->locked()->create(['user_id' => null]);
        $report->auditRequest->update(['email' => 'match@example.com']);

        $this->actingAs($user)->get($this->signedUnlockUrl($report))->assertRedirect();

        $this->assertSame($user->id, $report->refresh()->user_id);
    }

    public function test_unlock_route_denies_foreign_reports(): void
    {
        $user = User::factory()->create(['email' => 'other@example.com']);
        $report = AuditReport::factory()->locked()->create(['user_id' => User::factory()->create()->id]);

        $this->withExceptionHandling()
            ->actingAs($user)->get($this->signedUnlockUrl($report))->assertForbidden();
    }

    public function test_unlock_route_redirects_to_signed_view_when_already_unlocked(): void
    {
        $user = User::factory()->create();
        $report = AuditReport::factory()->unlocked()->create(['user_id' => $user->id]);

        $expectedUrl = app(AuditReportService::class)->signedUrl($report);

        $this->actingAs($user)
            ->get($this->signedUnlockUrl($report))
            ->assertRedirect($expectedUrl);
    }

    public function test_sample_report_returns_404_when_fixture_file_is_missing(): void
    {
        $this->withExceptionHandling();

        $path = resource_path('data/sample-audit-report.json');
        $backupPath = $path.'.bak';

        rename($path, $backupPath);

        try {
            $this->get('/reports/sample')->assertNotFound();
        } finally {
            rename($backupPath, $path);
        }
    }

    public function test_sample_report_returns_404_when_fixture_file_is_corrupt(): void
    {
        $this->withExceptionHandling();

        $path = resource_path('data/sample-audit-report.json');
        $original = file_get_contents($path);

        file_put_contents($path, 'not valid json {{{');

        try {
            $this->get('/reports/sample')->assertNotFound();
        } finally {
            file_put_contents($path, $original);
        }
    }
}
