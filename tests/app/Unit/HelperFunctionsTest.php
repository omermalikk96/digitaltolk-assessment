<?php

namespace Unit;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use DTApi\Helpers\TeHelper;

class HelperFunctionsTest extends TestCase
{
    public function testWillExpireAt()
    {
        // Test case 1: Difference <= 90
        $due_time = Carbon::now()->addHours(1);
        $created_at = Carbon::now();
        $expected = $due_time->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($due_time, $created_at));

        // Test case 2: Difference <= 24
        $due_time = Carbon::now()->addHours(2);
        $created_at = Carbon::now()->subHours(1);
        $expected = $created_at->addMinutes(90)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($due_time, $created_at));

        // Test case 3: 24 < Difference <= 72
        $due_time = Carbon::now()->addHours(3);
        $created_at = Carbon::now()->subHours(10);
        $expected = $created_at->addHours(16)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($due_time, $created_at));

        // Test case 4: Difference > 72
        $due_time = Carbon::now()->addHours(4);
        $created_at = Carbon::now()->subHours(100);
        $expected = $due_time->subHours(48)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, TeHelper::willExpireAt($due_time, $created_at));
    }
}
