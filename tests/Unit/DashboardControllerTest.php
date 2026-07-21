<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DashboardController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DashboardControllerTest extends TestCase
{
    public function test_map_priority_accepts_null_values(): void
    {
        $controller = new DashboardController();
        $method = new ReflectionMethod($controller, 'mapPriority');
        $method->setAccessible(true);

        $this->assertSame('medium', $method->invoke($controller, null));
    }
}
