<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Filament\Admin\Resources\ExpertReviews\Pages\EditExpertReview;
use App\Mail\Audit\AuditReportReady;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class ExpertReviewResourceTest extends FeatureTest
{
    public function test_reviewer_can_list_the_queue(): void
    {
        $reviewer = $this->createAdminUser();
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value, 'status' => AuditRequestStatus::SENT->value]); // not in queue

        $response = $this->actingAs($reviewer)->get(ExpertReviewResource::getUrl('index', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_queue_only_lists_expert_tier_requests_awaiting_review(): void
    {
        $inQueue = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $alreadyPublished = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::SENT->value]); // already published
        $diagnostic = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value, 'status' => AuditRequestStatus::REPORT_READY->value]);

        $ids = ExpertReviewResource::getEloquentQuery()->pluck('id')->all();

        // Asserted via contains/not-contains rather than an exact array match: FeatureTest
        // does not wrap tests in a DB transaction, so unrelated rows created by earlier
        // tests in this suite run may still be present in the table.
        $this->assertContains($inQueue->id, $ids);
        $this->assertNotContains($alreadyPublished->id, $ids);
        $this->assertNotContains($diagnostic->id, $ids);
    }

    public function test_navigation_badge_matches_queue_count(): void
    {
        $before = ExpertReviewResource::getEloquentQuery()->count();

        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value, 'status' => AuditRequestStatus::SENT->value]); // not in queue

        $this->assertSame((string) ($before + 2), ExpertReviewResource::getNavigationBadge());
    }

    public function test_user_without_the_permission_is_denied(): void
    {
        $user = User::factory()->create(['is_admin' => true]); // no role assigned, so no permission

        $this->assertFalse(ExpertReviewResource::canViewAny());

        $this->actingAs($user);
        $this->assertFalse(ExpertReviewResource::canViewAny());
    }

    public function test_edit_page_shows_repo_and_customer_context(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'repo_url' => 'https://github.com/acme/context-check',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'AI summary text.', 'scores' => ['overall' => 72], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        $response = $this->actingAs($reviewer)->get(ExpertReviewResource::getUrl('edit', ['record' => $request->getRouteKey()], true, 'admin'));

        $response->assertSuccessful();
        $response->assertSee('https://github.com/acme/context-check');
        $response->assertSee('ada@example.com');
    }

    public function test_reviewer_can_edit_and_save_findings(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => [
                'summary' => 'ok',
                'scores' => ['overall' => 60],
                'risks' => [
                    ['title' => 'Keep', 'impact' => 'high', 'evidence' => 'e1', 'recommendation' => 'r1'],
                    ['title' => 'Drop as false positive', 'impact' => 'low', 'evidence' => 'e2', 'recommendation' => 'r2'],
                ],
                'fix_first_plan' => [],
                'groups' => [],
                'file_findings' => [
                    ['path' => 'app/A.php', 'line' => 3, 'title' => 'Finding', 'evidence' => 'ev', 'recommendation' => 'rec', 'severity' => 'high', 'category' => 'security', 'effort' => 'M', 'related_paths' => ['app/B.php']],
                ],
            ],
        ]);

        Livewire::actingAs($reviewer)
            ->test(EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm([
                'risks' => [
                    ['title' => 'Keep', 'impact' => 'high', 'evidence' => 'e1', 'recommendation' => 'r1'],
                    // second risk removed — simulates deleting a false positive
                ],
                'file_findings' => [
                    ['path' => 'app/A.php', 'line' => 3, 'title' => 'Finding', 'evidence' => 'ev', 'recommendation' => 'rec', 'severity' => 'high', 'category' => 'security', 'effort' => 'M', 'related_paths' => ['app/B.php']],
                ],
                'expert_summary' => 'Looks solid overall.',
                'review_notes' => 'One risk removed as a false positive.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $payload = $report->fresh()->payload;
        $this->assertCount(1, $payload['risks']);
        $this->assertSame('Keep', $payload['risks'][0]['title']);
        $this->assertSame('app/B.php', $payload['file_findings'][0]['related_paths'][0]); // hidden field round-tripped
        $this->assertSame('Looks solid overall.', $payload['expert_review']['expert_summary']);
        $this->assertSame('', $payload['expert_review']['reviewed_by']); // not stamped until publish
    }

    public function test_draft_save_without_a_summary_omits_the_expert_review_key(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        Livewire::actingAs($reviewer)
            ->test(EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm(['risks' => [], 'file_findings' => [], 'expert_summary' => '', 'review_notes' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('expert_review', $report->fresh()->payload);
    }

    public function test_publish_action_is_disabled_without_a_summary(): void
    {
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        Livewire::actingAs($reviewer)
            ->test(EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->assertActionDisabled('publish');
    }

    public function test_publish_action_sends_and_transitions_status(): void
    {
        Mail::fake();
        $reviewer = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $report = AuditReport::factory()->create([
            'audit_request_id' => $request->id,
            'payload' => ['summary' => 'ok', 'scores' => ['overall' => 60], 'risks' => [], 'fix_first_plan' => [], 'groups' => []],
        ]);

        Livewire::actingAs($reviewer)
            ->test(EditExpertReview::class, ['record' => $request->getRouteKey()])
            ->fillForm(['risks' => [], 'file_findings' => [], 'expert_summary' => 'All clear.', 'review_notes' => ''])
            ->callAction('publish');

        $this->assertSame(AuditRequestStatus::SENT->value, $request->fresh()->status);
        $this->assertSame($reviewer->name, $report->fresh()->payload['expert_review']['reviewed_by']);
        Mail::assertQueued(AuditReportReady::class);
    }
}
