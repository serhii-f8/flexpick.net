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
}
