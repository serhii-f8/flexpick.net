<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
            'status' => AuditRequestStatus::QUEUED->value,
        ]);
        Queue::assertPushedOn('audit', GenerateAuditReport::class);
        Mail::assertQueued(AuditRequestReceived::class, fn ($mail) => $mail->hasTo('ada@example.com'));
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
            'status' => AuditRequestStatus::NEEDS_FOLLOWUP->value,
        ]);
        Queue::assertNothingPushed();
        Mail::assertQueued(AuditRepoAccessNeeded::class, fn ($mail) => $mail->hasTo('ada2@example.com'));
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
}
