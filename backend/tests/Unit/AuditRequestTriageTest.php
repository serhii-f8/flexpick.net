<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Tests\TestCase;

class AuditRequestTriageTest extends TestCase
{
    public function test_every_status_is_classified_exactly_once(): void
    {
        $triage = AuditRequest::statusTriage();

        $allStatuses = collect(AuditRequestStatus::cases())
            ->map(fn (AuditRequestStatus $case): string => $case->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $allStatuses,
            collect(array_keys($triage))->sort()->values()->all(),
            'a new AuditRequestStatus must be given a triage class, not silently ignored',
        );
    }

    public function test_every_classification_is_a_known_triage_constant(): void
    {
        $known = [
            AuditRequest::TRIAGE_IN_FLIGHT,
            AuditRequest::TRIAGE_NEEDS_MANUAL_ACTION,
            AuditRequest::TRIAGE_FAILED,
            AuditRequest::TRIAGE_EXPERT_REVIEW,
            AuditRequest::TRIAGE_TERMINAL,
        ];

        foreach (AuditRequest::statusTriage() as $status => $class) {
            $this->assertContains($class, $known, "status {$status} has an unknown triage class");
        }
    }

    public function test_the_operator_facing_classes_match_the_scopes(): void
    {
        $triage = AuditRequest::statusTriage();

        $this->assertSame(AuditRequest::TRIAGE_FAILED, $triage[AuditRequestStatus::FAILED->value]);
        $this->assertSame(AuditRequest::TRIAGE_EXPERT_REVIEW, $triage[AuditRequestStatus::EXPERT_REVIEW->value]);

        foreach ([
            AuditRequestStatus::NEEDS_FOLLOWUP,
            AuditRequestStatus::AWAITING_ACCESS,
            AuditRequestStatus::AWAITING_PAYMENT,
        ] as $status) {
            $this->assertSame(AuditRequest::TRIAGE_NEEDS_MANUAL_ACTION, $triage[$status->value]);
        }
    }
}
