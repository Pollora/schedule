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

    // Once a test defines a WordPress function, it exists for the rest of the process: give it a default
    Monkey\Functions\when('wp_cache_delete')->justReturn(true);
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    // Schedule keeps one engine for the process: start each test from the default one
    $schedule = new ReflectionClass(Schedule::class);
    foreach (['engine', 'fake', 'driverBeforeFake', 'wanted'] as $property) {
        $schedule->setStaticPropertyValue($property, null);
    }

    if (class_exists('ActionScheduler', false) && method_exists('ActionScheduler', 'reset')) {
        ActionScheduler::reset();
    }

    Monkey\tearDown();
})->in('Unit');
