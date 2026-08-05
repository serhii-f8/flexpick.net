<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Models\AuditRequest;
use App\Models\User;
use Tests\Feature\FeatureTest;

class ExpertReviewResourceTest extends FeatureTest
{
    public function test_reviewer_can_list_the_queue(): void
    {
        $reviewer = $this->createAdminUser();
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::SENT->value]); // not in queue

        $response = $this->actingAs($reviewer)->get(ExpertReviewResource::getUrl('index', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_queue_only_lists_expert_tier_requests_awaiting_review(): void
    {
        $inQueue = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::EXPERT_REVIEW->value]);
        $alreadyPublished = AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value, 'status' => AuditRequestStatus::SENT->value]); // already published
        $automated = AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::REPORT_READY->value]);

        $ids = ExpertReviewResource::getEloquentQuery()->pluck('id')->all();

        // Asserted via contains/not-contains rather than an exact array match: FeatureTest
        // does not wrap tests in a DB transaction, so unrelated rows created by earlier
        // tests in this suite run may still be present in the table.
        $this->assertContains($inQueue->id, $ids);
        $this->assertNotContains($alreadyPublished->id, $ids);
        $this->assertNotContains($automated->id, $ids);
    }

    public function test_user_without_the_permission_is_denied(): void
    {
        $user = User::factory()->create(['is_admin' => true]); // no role assigned, so no permission

        $this->assertFalse(ExpertReviewResource::canViewAny());

        $this->actingAs($user);
        $this->assertFalse(ExpertReviewResource::canViewAny());
    }
}
