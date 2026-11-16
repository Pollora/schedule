<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Infrastructure\Services\ScheduleDiscovery;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Tests\Support\FailingJobs;
use Pollora\Schedule\Tests\Support\NamespacedJobs;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

require_once __DIR__.'/../Support/NamespacedJobs.php';

beforeEach(function (): void {
    $this->scheduled = [];
    $this->hooks = [];
    $this->added = [];

    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn([]);
    Functions\when('wp_schedule_event')->alias(function (int $timestamp, string $recurrence, string $hook): bool {
        $this->scheduled[] = $hook;

        return true;
    });
    Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10): bool {
        $this->hooks[$hook] = $callback;
        $this->added[] = [$hook, $priority];

        return true;
    });
    Functions\when('did_action')->justReturn(1);
    $this->location = new DiscoveryLocation('', __DIR__);
});

it('finds the methods of a class that lives in a namespace', function (): void {
    NamespacedJobs::$calls = [];
    $discovery = new ScheduleDiscovery(null, new ScheduleEngine(new WpCronDriver));
    $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NamespacedJobs::class)), new ReflectionCache);
    $discovery->apply();

    expect($this->scheduled)->toBe(['namespaced_jobs_tick']);

    ($this->hooks['namespaced_jobs_tick'])();

    expect(NamespacedJobs::$calls)->toBe(['tick']);
});

it('leaves what is not a class alone, without even asking it if it is abstract', function (): void {
    $discovery = new ScheduleDiscovery(null, new ScheduleEngine(new WpCronDriver));
    $interface = DiscoveredInterface::fromReflection(new ReflectionClass(Countable::class));

    strictly(fn () => $discovery->discover($this->location, $interface, new ReflectionCache));
    strictly(fn () => $discovery->discover($this->location, Mockery::mock(DiscoveredStructure::class), new ReflectionCache));
    strictly(fn () => $discovery->apply());

    expect($this->scheduled)->toBe([]);
});

it('keeps going without a logger when a schedule cannot be registered or an event cannot be placed', function (): void {
    $discovery = new ScheduleDiscovery(null, new ScheduleEngine(new WpCronDriver));
    $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(FailingJobs::class)), new ReflectionCache);
    $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NamespacedJobs::class)), new ReflectionCache);
    $discovery->apply();

    expect($this->scheduled)->toBe(['namespaced_jobs_tick']);

    Functions\when('wp_schedule_event')->justReturn(false);
    $refused = new ScheduleDiscovery(null, new ScheduleEngine(new WpCronDriver));
    $refused->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NamespacedJobs::class)), new ReflectionCache);
    $refused->apply();

    expect(true)->toBeTrue();
});

it('waits for init first in line when WordPress has not loaded its cron functions', function (): void {
    Functions\when('did_action')->justReturn(0);
    $discovery = new ScheduleDiscovery(null, new ScheduleEngine(new WpCronDriver));
    $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass(NamespacedJobs::class)), new ReflectionCache);
    $discovery->apply();

    expect(array_values(array_filter($this->added, fn (array $added): bool => $added[0] === 'init')))->toBe([['init', 0]]);
});
