<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use PHPUnit\Framework\ExpectationFailedException;
use Pollora\Schedule\Cli\Registrar;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\Testing\ScheduleFake;

require_once __DIR__.'/../Support/WpCliFake.php';

beforeEach(function (): void {
    $this->actions = [];
    $this->filters = [];
    $this->previousDriver = getenv('POLLORA_SCHEDULE_DRIVER');
    putenv('POLLORA_SCHEDULE_DRIVER');

    Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10): bool {
        $this->actions[$hook][] = [$callback, $priority];

        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook][] = $callback;

        return true;
    });
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('did_action')->justReturn(0);
    Functions\when('_get_cron_array')->justReturn([]);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_schedule_event')->justReturn(true);
    Functions\when('wp_schedule_single_event')->justReturn(true);
    Functions\when('wp_timezone')->justReturn(new DateTimeZone('UTC'));

    WP_CLI::reset();
    (new ReflectionClass(Registrar::class))->setStaticPropertyValue('registered', false);
    defined('WP_CLI') || define('WP_CLI', true);
});

afterEach(function (): void {
    putenv($this->previousDriver === false ? 'POLLORA_SCHEDULE_DRIVER' : 'POLLORA_SCHEDULE_DRIVER='.$this->previousDriver);
    WP_CLI::reset();
    (new ReflectionClass(Registrar::class))->setStaticPropertyValue('registered', false);
});

describe('the engine of the facade', function (): void {
    it('lists what is scheduled, whoever scheduled it', function (): void {
        $fake = new ScheduleFake;
        Schedule::useDriver($fake);
        $fake->once('digest', 4_000_000_000);

        expect(Schedule::events())->toEqual([new Event('digest', 4_000_000_000)]);
    });

    it('starts on WP-Cron with its guard, its resolver and its commands', function (): void {
        $engine = Schedule::engine();

        expect($engine->driver())->toBeInstanceOf(WpCronDriver::class)
            ->and($engine->driverName())->toBe('wp-cron')
            ->and($this->actions)->toHaveKey('init')
            ->and($this->actions['init'][0][1])->toBe(0)
            ->and($engine->driverNamed('action-scheduler'))->toBeInstanceOf(ActionSchedulerDriver::class)
            ->and(WP_CLI::$commands)->toHaveKey('pollora schedule migrate');
    });

    it('takes the driver the environment names', function (): void {
        putenv('POLLORA_SCHEDULE_DRIVER=action-scheduler');

        expect(Schedule::engine()->driverName())->toBe('action-scheduler');
    });

    it('falls back to WP-Cron, guarded, when the environment names a driver nobody knows', function (): void {
        putenv('POLLORA_SCHEDULE_DRIVER=nobody');

        $engine = Schedule::engine();

        expect($engine->driverName())->toBe('wp-cron')
            ->and($this->actions)->toHaveKey('init');
    });

    it('finishes choosing a driver it wanted before WordPress could take hooks', function (): void {
        (new ReflectionClass(Schedule::class))->setStaticPropertyValue('wanted', 'action-scheduler');

        expect(Schedule::engine()->driverName())->toBe('action-scheduler');
    });

    it('keeps its engine when a driver is chosen later', function (): void {
        $engine = Schedule::engine();

        Schedule::driver('action-scheduler');

        expect(Schedule::engine())->toBe($engine)
            ->and($engine->driverName())->toBe('action-scheduler')
            ->and($engine->driver())->toBeInstanceOf(ActionSchedulerDriver::class);

        Schedule::driver('wp-cron');

        expect($engine->driver())->toBeInstanceOf(WpCronDriver::class)
            ->and($engine->driverName())->toBe('wp-cron');
    });

    it('registers the commands when a driver is chosen', function (): void {
        Schedule::driver('action-scheduler');

        expect(WP_CLI::$commands)->toHaveKey('pollora schedule migrate');
    });
});

describe('faking', function (): void {
    it('stops handlers from attaching while faked, and attaches them again after', function (): void {
        Schedule::fake();
        Schedule::event('quiet')->daily()->call('some_hook');

        expect($this->actions['quiet'] ?? [])->toBe([]);

        Schedule::reset();
        Schedule::event('loud')->daily()->call('some_hook');

        expect($this->actions['loud'])->toHaveCount(1);
    });

    it('can be reset without having been faked', function (): void {
        $engine = Schedule::engine();
        $driver = $engine->driver();

        Schedule::reset();

        expect($engine->driver())->toBe($driver);
    });

    it('asserts through the fake, and says so when nothing is faked', function (): void {
        expect(fn () => Schedule::assertScheduled('x'))->toThrow(LogicException::class, 'Call Schedule::fake() before asserting.');

        $fake = Schedule::fake();

        expect(fn () => Schedule::assertScheduledTimes('x', 1))->toThrow(ExpectationFailedException::class, 'The event [x] was scheduled 0 time(s) instead of 1.');

        $fake->once('x', 5);

        expect(fn () => Schedule::assertNotScheduled('x'))->toThrow(ExpectationFailedException::class, 'The event [x] was scheduled.')
            ->and(fn () => Schedule::assertNothingScheduled())->toThrow(ExpectationFailedException::class, '1 event(s) were scheduled.');
    });
});

describe('the fake driver', function (): void {
    it('says what it did', function (): void {
        $fake = new ScheduleFake;

        expect($fake->once('a', 1, [1]))->toBeTrue()
            ->and($fake->recurring('a', 2, 'daily', [2]))->toBeTrue();
    });

    it('cancels the exact event only, and clears a hook', function (): void {
        $fake = new ScheduleFake;
        $fake->once('a', 1, [1]);
        $fake->once('a', 2, [2]);
        $fake->once('b', 3, [1]);

        expect($fake->cancel('a', [1]))->toBe(1)
            ->and(array_column($fake->events(), 'timestamp'))->toBe([2, 3])
            ->and($fake->clear('a'))->toBe(1)
            ->and($fake->clear('a'))->toBe(0)
            ->and(array_column($fake->scheduled(), 'hook'))->toBe(['b']);
    });

    it('explains an assertion that fails', function (): void {
        $fake = new ScheduleFake;
        $fake->once('a', 1);

        expect(fn () => $fake->assertScheduled('missing'))->toThrow(ExpectationFailedException::class, 'The event [missing] was not scheduled.')
            ->and(fn () => $fake->assertScheduled('a', fn (Event $event): bool => $event->timestamp === 9))->toThrow(ExpectationFailedException::class, 'The event [a] was not scheduled as expected.');

        $fake->assertScheduled('a', fn (Event $event): bool => $event->timestamp === 1);
    });

    it('lists a list, whatever was removed before', function (): void {
        $fake = new ScheduleFake;
        $fake->once('a', 3);
        $fake->once('b', 2);
        $fake->once('c', 1);
        $fake->cancel('b');

        expect(array_keys($fake->scheduled()))->toBe([0, 1]);
    });
});
