<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Mail\Audit\AuditVerifyEmail;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class AuditRequestControllerTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withExceptionHandling(); // FeatureTest disables it; we need real 422/429 JSON
        Mail::fake();
        Queue::fake();
    }

    public function test_valid_submission_creates_request_and_dispatches_pipeline(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'repo_url' => 'https://github.com/example/repo',
            'message' => 'Everything is on fire.',
            'website' => '',
        ]);

        $response->assertStatus(201)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('audit_requests', [
            'email' => 'ada@example.com',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);
        Queue::assertNothingPushed();
        Mail::assertQueued(AuditVerifyEmail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_submission_without_repo_goes_to_followup(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada2@example.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('audit_requests', [
            'email' => 'ada2@example.com',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);
        Queue::assertNothingPushed();
        Mail::assertQueued(AuditVerifyEmail::class, fn ($mail) => $mail->hasTo('ada2@example.com'));
    }

    public function test_honeypot_rejects(): void
    {
        $response = $this->postJson(route('audit-requests.store'), [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'website' => 'http://spam.example',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('audit_requests', ['email' => 'bot@example.com']);
    }

    public function test_validation_errors(): void
    {
        $this->postJson(route('audit-requests.store'), ['name' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'A', 'email' => 'a@example.com', 'repo_url' => 'not a url',
        ])->assertStatus(422)->assertJsonValidationErrors(['repo_url']);
    }

    public function test_duplicate_email_within_window_is_rejected(): void
    {
        AuditRequest::factory()->create(['email' => 'dup@example.com', 'created_at' => now()->subMinutes(2)]);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'Dup', 'email' => 'dup@example.com',
            'repo_url' => 'https://github.com/example/repo',
        ])->assertStatus(429);
    }

    /**
     * Audits belong to workspaces, and the only claim triggers are
     * TenantCreated / UserJoinedTenant -- which never fire again for someone
     * who already has a workspace. So a landing-page submission from an
     * already-registered customer must be stamped with their primary
     * workspace up front, or it sits "Unclaimed" in the admin forever and
     * never shows in their dashboard.
     */
    public function test_a_public_submission_by_a_registered_user_is_stamped_with_their_primary_workspace(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant, [], ['email' => 'member-'.Str::random(6).'@example.com']);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'Member',
            // Case-insensitive: the form does not normalise what the visitor types.
            'email' => strtoupper($user->email),
            'repo_url' => 'https://github.com/example/member-repo',
        ])->assertStatus(201);

        $this->assertSame($tenant->id, AuditRequest::where('repo_url', 'https://github.com/example/member-repo')->firstOrFail()->tenant_id);
    }

    public function test_a_public_submission_by_a_registered_user_with_no_workspace_stays_unclaimed(): void
    {
        $user = $this->createUser(null, [], ['email' => 'loner-'.Str::random(6).'@example.com']);

        $this->postJson(route('audit-requests.store'), [
            'name' => 'Loner',
            'email' => $user->email,
            'repo_url' => 'https://github.com/example/loner-repo',
        ])->assertStatus(201);

        $this->assertNull(AuditRequest::where('repo_url', 'https://github.com/example/loner-repo')->firstOrFail()->tenant_id);
    }

    public function test_a_public_submission_by_an_unknown_email_stays_unclaimed(): void
    {
        $this->postJson(route('audit-requests.store'), [
            'name' => 'Stranger',
            'email' => 'stranger-'.Str::random(6).'@example.com',
            'repo_url' => 'https://github.com/example/stranger-repo',
        ])->assertStatus(201);

        $this->assertNull(AuditRequest::where('repo_url', 'https://github.com/example/stranger-repo')->firstOrFail()->tenant_id);
    }

    /**
     * A logged-in user always already has a workspace (SaaSykit provisions
     * one at signup), so purchaseRun() can key the checkout intent on it
     * directly -- and must not fall back to the retired per-user parameter.
     */
    public function test_purchase_run_writes_the_intent_to_the_users_workspace_not_a_user_parameter(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant);
        $this->actingAs($user);

        $request = AuditRequest::factory()->verified()->create([
            'email' => $user->email,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $response = $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));

        // The checkout is pinned to the workspace the intent was written on
        // -- see ProductCheckoutController::addToCart().
        $response->assertRedirect(route('buy.product', ['productSlug' => 'audit-diagnostic', 'tenant' => $tenant->uuid]));
        $this->assertSame($request->uuid, TenantParameter::where('tenant_id', $tenant->id)
            ->where('name', HandleAuditTierOrder::INTENT_PARAM)->value('value'));
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM]);
    }

    /**
     * A brand-new guest gets an account on the spot, but not a workspace --
     * that is provisioned at checkout, not here. Writing the intent against
     * a workspace that does not exist yet would just be a no-op that looks
     * like it worked, so purchaseRun() must skip it entirely until a real
     * workspace exists to key it on (see the extra item on Task 10).
     */
    public function test_purchase_run_for_a_fresh_guest_creates_an_account_but_writes_no_intent(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'email' => 'fresh-guest@example.com',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $response = $this->get(app(AuditRequestService::class)->purchaseRunUrl($request));

        $user = User::where('email', 'fresh-guest@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('buy.product', ['productSlug' => 'audit-diagnostic']));
        $this->assertDatabaseMissing('user_parameters', ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM]);
        $this->assertDatabaseMissing('tenant_parameters', ['name' => HandleAuditTierOrder::INTENT_PARAM, 'value' => $request->uuid]);
    }
}
