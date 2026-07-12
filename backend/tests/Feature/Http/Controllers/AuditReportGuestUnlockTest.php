<?php

namespace Tests\Feature\Http\Controllers;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;

class AuditReportGuestUnlockTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function lockedReportFor(string $email): AuditReport
    {
        $request = AuditRequest::factory()->verified()->create(['email' => $email, 'name' => 'Guest User']);

        return AuditReport::factory()->locked()->create(['audit_request_id' => $request->id, 'user_id' => null]);
    }

    private function signedUnlockUrl(AuditReport $report): string
    {
        return URL::temporarySignedRoute('reports.unlock', now()->addDay(), ['auditReport' => $report->uuid]);
    }

    public function test_guest_unlock_creates_account_logs_in_and_redirects_to_checkout(): void
    {
        $report = $this->lockedReportFor('guest-unlock@example.com');

        $response = $this->get($this->signedUnlockUrl($report));

        $user = User::where('email', 'guest-unlock@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($user->id, $report->refresh()->user_id);
        $this->assertSame($report->uuid, UserParameter::where('user_id', $user->id)
            ->where('name', HandleAuditUnlockOrder::INTENT_PARAM)->value('value'));
        $response->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));
    }

    public function test_existing_account_is_never_auto_logged_in(): void
    {
        $existing = User::factory()->create(['email' => 'already-registered@example.com']);
        $report = $this->lockedReportFor('already-registered@example.com');

        $response = $this->get($this->signedUnlockUrl($report));

        $this->assertGuest();
        $this->assertNull($report->refresh()->user_id);
        $response->assertRedirect(route('login'));
    }

    public function test_logged_in_owner_still_reaches_checkout(): void
    {
        $report = $this->lockedReportFor('owner-unlock@example.com');
        $user = User::factory()->create(['email' => 'owner-unlock@example.com']);

        $this->actingAs($user)
            ->get($this->signedUnlockUrl($report))
            ->assertRedirect(route('buy.product', ['productSlug' => config('audit.unlock_product_slug')]));

        $this->assertSame($user->id, $report->refresh()->user_id);
    }

    public function test_unsigned_unlock_request_is_rejected(): void
    {
        $this->withExceptionHandling();
        $report = $this->lockedReportFor('unsigned-unlock@example.com');

        $this->get('/reports/'.$report->uuid.'/unlock')->assertForbidden();
    }
}
