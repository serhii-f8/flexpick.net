<?php

namespace Tests\Feature\Listeners;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Auth\Events\Registered;
use Tests\Feature\FeatureTest;

class LinkAuditReportsToUserTest extends FeatureTest
{
    public function test_reports_matching_email_are_linked_on_registration(): void
    {
        $request = AuditRequest::factory()->create(['email' => 'newuser@example.com']);
        $report = AuditReport::factory()->create(['audit_request_id' => $request->id, 'user_id' => null]);
        $other = AuditReport::factory()->create(['user_id' => null]); // different email

        $user = $this->createUser();
        $user->update(['email' => 'newuser@example.com']);

        event(new Registered($user));

        $this->assertSame($user->id, $report->fresh()->user_id);
        $this->assertNull($other->fresh()->user_id);
    }
}
