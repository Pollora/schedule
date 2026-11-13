<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;
use PHPUnit\Framework\ExpectationFailedException;
use Pollora\Attributes\Schedule as ScheduleAttribute;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\Infrastructure\Services\ScheduleDiscovery;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Testing\ScheduleFake;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

const SURFACE_NOW = 1_800_000_000;
const SURFACE_LATER = 4_000_000_000;

abstract class AbstractSurfaceJobs
{
    #[ScheduleAttribute('daily')]
    public function never(): void {}
}

final class BrokenSurfaceJobs
{
    #[ScheduleAttribute('no_such_schedule')]
    public function run(): void {}
}

final class PlainSurfaceJobs
{
    #[ScheduleAttribute('daily', hook: 'surface_plain')]
    public function run(): void {}
}

beforeEach(function (): void {
    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('add_action')->justReturn(true);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('did_action')->justReturn(1);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
});

describe('the fluent shortcuts', function (): void {
    it('schedule each shortcut on the WordPress schedule it names', function (string $shortcut, string $key): void {
        $fake = new ScheduleFake;
        Schedule::engine()->useClock(fn (): int => SURFACE_NOW);
        Schedule::useDriver($fake);

        Schedule::event('surface_'.$shortcut)->{$shortcut}()->schedule();

        expect($fake->scheduled('surface_'.$shortcut)[0]->recurrence)->toBe($key)
            ->and($fake->scheduled('surface_'.$shortcut)[0]->isRecurring())->toBeTrue();
    })->with([
        ['hourly', 'hourly'],
        ['twiceDaily', 'twicedaily'],
        ['daily', 'daily'],
        ['weekly', 'weekly'],
        ['monthly', 'every_month'],
    ]);

    it('knows a single event is not recurring', function (): void {
        expect((new Event('x', SURFACE_NOW))->isRecurring())->toBeFalse();
    });

    it('clears a hook through the engine', function (): void {
        $fake = new ScheduleFake;
        Schedule::useDriver($fake);
        $fake->once('surface_clear', SURFACE_LATER, ['a' => 1]);
        $fake->once('surface_clear', SURFACE_LATER + 1, ['a' => 2]);

        expect(Schedule::clear('surface_clear'))->toBe(2)
            ->and($fake->scheduled())->toBe([]);
    });
});

describe('the test double', function (): void {
    it('is always available, clears a hook and reads the next run of exact arguments', function (): void {
        $fake = new ScheduleFake;
        $fake->once('surface_job', SURFACE_LATER + 5, ['a' => 1]);
        $fake->once('surface_job', SURFACE_LATER, ['a' => 2]);
        $fake->once('surface_other', SURFACE_LATER);

        expect($fake->available())->toBeTrue()
            ->and($fake->next('surface_job', ['a' => 1]))->toBe(SURFACE_LATER + 5)
            ->and($fake->next('surface_job', ['a' => 3]))->toBeNull()
            ->and(array_column($fake->events(), 'timestamp'))->toBe([SURFACE_LATER, SURFACE_LATER, SURFACE_LATER + 5])
            ->and($fake->clear('surface_job'))->toBe(2)
            ->and(array_column($fake->events(), 'hook'))->toBe(['surface_other']);
    });

    it('fails an assertion with a message that names the event, and passes the others', function (): void {
        $fake = new ScheduleFake;
        $fake->recurring('surface_job', SURFACE_LATER, 'daily');

        $fake->assertScheduled('surface_job');
        $fake->assertScheduled('surface_job', fn (Event $event): bool => $event->recurrence === 'daily');
        $fake->assertScheduledTimes('surface_job', 1);
        $fake->assertNotScheduled('surface_other');
        $fake->assertNotScheduled('surface_job', fn (Event $event): bool => $event->recurrence === 'weekly');

        expect(fn () => $fake->assertScheduled('surface_other'))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertScheduled('surface_job', fn (): bool => false))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertScheduledTimes('surface_job', 2))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertNotScheduled('surface_job'))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $fake->assertNothingScheduled())->toThrow(ExpectationFailedException::class);

        $fake->clear('surface_job');
        $fake->assertNothingScheduled();
    });
});

describe('the engine of the facade', function (): void {
    it('keeps one engine for the process, and knows the built-in drivers', function (): void {
        $engine = Schedule::engine();

        expect($engine)->toBeInstanceOf(ScheduleEngine::class)
            ->and(Schedule::knows('wp-cron'))->toBeTrue()
            ->and(Schedule::knows('action-scheduler'))->toBeTrue()
            ->and(Schedule::engine())->toBe($engine);
    });
});

describe('the discovery', function (): void {
    beforeEach(function (): void {
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->location = new DiscoveryLocation('', __DIR__);
        $this->engine = new ScheduleEngine($this->driver = new ScheduleFake);
        $this->engine->useClock(fn (): int => SURFACE_NOW);
        $this->discover = function (string $class, ?ReflectionCacheInterface $cache = null): ScheduleDiscovery {
            $discovery = new ScheduleDiscovery($this->logger, $this->engine);
            $discovery->discover($this->location, DiscoveredClass::fromReflection(new ReflectionClass($class)), $cache ?? new ReflectionCache);

            return $discovery;
        };
    });

    it('has the identifier the discovery registry knows it by', function (): void {
        expect((new ScheduleDiscovery)->getIdentifier())->toBe('schedules');
    });

    it('skips what is not a class, and abstract classes', function (): void {
        $discovery = new ScheduleDiscovery($this->logger, $this->engine);
        $discovery->discover($this->location, Mockery::mock(DiscoveredStructure::class), new ReflectionCache);
        ($this->discover)(AbstractSurfaceJobs::class)->apply();
        $discovery->apply();

        expect($this->driver->scheduled())->toBe([]);
    });

    it('skips a class it cannot reflect, without failing the others', function (): void {
        /** @var MockInterface&ReflectionCacheInterface $cache */
        $cache = Mockery::mock(ReflectionCacheInterface::class);
        $cache->shouldReceive('getPublicMethods')->andThrow(new RuntimeException('missing dependency'));

        ($this->discover)(PlainSurfaceJobs::class, $cache)->apply();

        expect($this->driver->scheduled())->toBe([]);
    });

    it('logs a schedule it cannot register and goes on with the others', function (): void {
        $this->logger->shouldReceive('error')->once()->with(
            'Failed to register Schedule from method \\BrokenSurfaceJobs::run',
            Mockery::on(fn (array $context): bool => $context['exception'] instanceof Throwable),
        );

        ($this->discover)(BrokenSurfaceJobs::class)->apply();
        ($this->discover)(PlainSurfaceJobs::class)->apply();

        expect(array_column($this->driver->scheduled(), 'hook'))->toBe(['surface_plain']);
    });

    it('logs an event the driver refuses', function (): void {
        $refusing = new class implements ScheduleDriver
        {
            public function available(): bool
            {
                return true;
            }

            public function once(string $hook, int $timestamp, array $args = []): bool
            {
                return false;
            }

            public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
            {
                throw new ScheduleException('refused');
            }

            public function cancel(string $hook, array $args = []): int
            {
                return 0;
            }

            public function clear(string $hook): int
            {
                return 0;
            }

            public function next(string $hook, array $args = []): ?int
            {
                return null;
            }

            public function events(): array
            {
                return [];
            }
        };
        $this->engine->useDriver($refusing);
        $this->logger->shouldReceive('error')->once()->with('Failed to schedule WordPress cron event for hook: surface_plain');

        ($this->discover)(PlainSurfaceJobs::class)->apply();
    });
});
