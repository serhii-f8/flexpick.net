<?php

namespace Tests\Unit;

use App\Constants\AuditRequestStatus;
use App\Mapper\AuditRequestStatusMapper;
use Tests\TestCase;

class AuditRequestStatusMapperTest extends TestCase
{
    public function test_every_case_has_display_and_color(): void
    {
        $mapper = new AuditRequestStatusMapper;

        foreach (AuditRequestStatus::cases() as $case) {
            $this->assertNotSame('', $mapper->mapForDisplay($case->value));
            $this->assertContains($mapper->mapColor($case->value), ['gray', 'info', 'warning', 'success', 'danger']);
        }
    }

    public function test_maps_new_intake_statuses(): void
    {
        $mapper = new \App\Mapper\AuditRequestStatusMapper;

        $this->assertSame('Pending verification', $mapper->mapForDisplay('pending_verification'));
        $this->assertSame('Awaiting repo access', $mapper->mapForDisplay('awaiting_access'));
        $this->assertSame('Awaiting payment', $mapper->mapForDisplay('awaiting_payment'));
        $this->assertSame('gray', $mapper->mapColor('pending_verification'));
        $this->assertSame('warning', $mapper->mapColor('awaiting_access'));
        $this->assertSame('warning', $mapper->mapColor('awaiting_payment'));
    }
}
