<?php

namespace Tests\Feature\Mapper;

use App\Constants\OrderStatus;
use App\Mapper\OrderStatusMapper;
use Tests\Feature\FeatureTest;

class OrderStatusMapperTest extends FeatureTest
{
    public function test_rejected_reads_differently_from_failed(): void
    {
        $mapper = new OrderStatusMapper;

        $this->assertSame(__('Rejected'), $mapper->mapForDisplay(OrderStatus::REJECTED->value));
        $this->assertSame(__('Failed'), $mapper->mapForDisplay(OrderStatus::FAILED->value));
    }

    public function test_rejected_is_coloured_as_a_failure_not_a_pending_state(): void
    {
        $mapper = new OrderStatusMapper;

        $this->assertSame('danger', $mapper->mapColor(OrderStatus::REJECTED->value));
        $this->assertSame('warning', $mapper->mapColor(OrderStatus::PENDING->value));
        $this->assertSame('success', $mapper->mapColor(OrderStatus::SUCCESS->value));
    }
}
