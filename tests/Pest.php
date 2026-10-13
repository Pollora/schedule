<?php

declare(strict_types=1);

use Brain\Monkey;
use Pollora\Schedule\Schedule;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| No framework to boot. WordPress functions are stubbed with Brain Monkey,
| and Mockery's expectations count as assertions.
|
*/

pest()->beforeEach(function (): void {
    Monkey\setUp();
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    // Schedule keeps one engine for the process: start each test from the default one
    $schedule = new ReflectionClass(Schedule::class);
    foreach (['engine', 'fake', 'driverBeforeFake'] as $property) {
        $schedule->setStaticPropertyValue($property, null);
    }

    Monkey\tearDown();
})->in('Unit');
