<?php

namespace Tests\Unit;

use App\Health\FailureRate;
use PHPUnit\Framework\TestCase;

class FailureRateTest extends TestCase
{
    public function test_zero_samples_is_below_the_floor(): void
    {
        $rate = new FailureRate(total: 0, failed: 0, minSamples: 5);

        $this->assertTrue($rate->belowFloor());
        $this->assertSame(0, $rate->percent());
    }

    public function test_just_under_the_floor_is_below_it(): void
    {
        $this->assertTrue((new FailureRate(4, 4, 5))->belowFloor());
    }

    public function test_exactly_at_the_floor_is_not_below_it(): void
    {
        $this->assertFalse((new FailureRate(5, 3, 5))->belowFloor());
    }

    public function test_percent_rounds_to_the_nearest_integer(): void
    {
        $this->assertSame(60, (new FailureRate(5, 3, 5))->percent());
        $this->assertSame(33, (new FailureRate(3, 1, 1))->percent());
        $this->assertSame(100, (new FailureRate(7, 7, 5))->percent());
    }
}
