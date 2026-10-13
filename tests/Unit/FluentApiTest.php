<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Interval;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Testing\ScheduleFake;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class Reports
{
    /** @var list<mixed> */
    public static array $calls = [];

    public function compile(array $args = []): void
    {
        self::$calls[] = ['compile', $args];
    }

    public function __invoke(array $args = []): void
    {
        self::$calls[] = ['invoked', $args];
    }
}

final class NeedsMailer
{
    public function __construct(public readonly object $mailer) {}

    public function __invoke(): void {}
}

const NOW = 1_800_000_000; // 2027-01-15 08:00:00 UTC

beforeEach(function (): void {
    Reports::$calls = [];
    $this->hooks = [];
    $this->filters = [];
    $this->fake = new ScheduleFake;

    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->hooks[$hook][] = $callback;

        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook][] = $callback;

        return true;
    });
    Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Paris'));

    Schedule::engine()->useClock(fn (): int => NOW);
    Schedule::useDriver($this->fake);
});

it('schedules a recurring event with a WordPress schedule', function (): void {
    Schedule::event('reports_compile')->daily()->call([Reports::class, 'compile']);

    expect($this->fake->scheduled('reports_compile'))->toEqual([new Event('reports_compile', NOW, 'daily')]);
});

it('computes at() in the site timezone, on the next day when the time has passed', function (string $time, string $expected): void {
    Schedule::event('nightly')->daily()->at($time)->schedule();

    expect((new DateTimeImmutable('@'.$this->fake->scheduled('nightly')[0]->timestamp))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'))->toBe($expected);
})->with([
    'later today' => ['23:30', '2027-01-15 23:30'],
    'already passed' => ['02:00', '2027-01-16 02:00'],
]);

it('registers the schedules WordPress does not know for every() and monthly()', function (): void {
    Schedule::event('warm')->every(new Interval(minutes: 15, display: 'Every quarter hour'))->schedule();
    Schedule::event('archive')->monthly()->schedule();

    $schedules = array_reduce($this->filters['cron_schedules'], fn (array $schedules, callable $filter): array => $filter($schedules), []);

    expect($this->fake->scheduled('archive')[0]->recurrence)->toBe('every_month')
        ->and($this->fake->scheduled('warm')[0]->recurrence)->toBe('interval_'.md5('warm_900'))
        ->and($schedules['every_month']['interval'])->toBe(30 * 86400)
        ->and($schedules['interval_'.md5('warm_900')])->toBe(['interval' => 900, 'display' => 'Every quarter hour']);
});

it('schedules a single event after a delay, with arguments', function (): void {
    Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 42])->call(Reports::class);

    expect($this->fake->scheduled('send_digest'))->toEqual([new Event('send_digest', NOW + 600, null, ['user' => 42])]);
});

it('schedules a single event at a date', function (): void {
    Schedule::once('close_poll')->at(new DateTimeImmutable('2027-02-01 12:00:00 UTC'))->schedule();

    expect($this->fake->scheduled('close_poll')[0]->timestamp)->toBe((new DateTimeImmutable('2027-02-01 12:00:00 UTC'))->getTimestamp());
});

it('declares an event once however many times it is declared', function (): void {
    foreach (range(1, 3) as $_) {
        Schedule::event('reports_compile')->daily()->call([Reports::class, 'compile']);
    }

    expect($this->fake->scheduled('reports_compile'))->toHaveCount(1);
});

it('does not schedule an event again when the driver already has it', function (): void {
    Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 42])->schedule();
    Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 7])->schedule();

    expect($this->fake->scheduled('send_digest'))->toHaveCount(2);
});

it('cancels the event with the same arguments, and reads the next run', function (): void {
    Schedule::once('send_digest')->in(minutes: 10)->with(['user' => 42])->schedule();

    expect(Schedule::next('send_digest', ['user' => 42])?->getTimestamp())->toBe(NOW + 600)
        ->and(Schedule::next('send_digest'))->toBeNull()
        ->and(Schedule::cancel('send_digest', ['user' => 42]))->toBe(1)
        ->and(Schedule::next('send_digest', ['user' => 42]))->toBeNull()
        ->and(Schedule::events())->toBe([]);
});

it('runs an invokable class, with its arguments as one array, when the hook fires', function (): void {
    Schedule::once('send_digest')->with(['user' => 42])->call(Reports::class);

    ($this->hooks['send_digest'][0])(...['user' => 42]);

    expect(Reports::$calls)->toBe([['invoked', ['user' => 42]]]);
});

it('runs a method without arguments when the event has none', function (): void {
    Schedule::event('reports_compile')->daily()->call([Reports::class, 'compile']);

    ($this->hooks['reports_compile'][0])();

    expect(Reports::$calls)->toBe([['compile', []]]);
});

it('builds the handler class with the container', function (): void {
    $built = new Reports;
    $container = new class($built) implements ContainerInterface
    {
        public function __construct(private object $built) {}

        public function get(string $id): object
        {
            return $this->built;
        }

        public function has(string $id): bool
        {
            return true;
        }
    };
    Schedule::useContainer($container);
    Schedule::event('reports_compile')->daily()->call(Reports::class);

    ($this->hooks['reports_compile'][0])();

    expect(Reports::$calls)->toBe([['invoked', []]]);
});

it('does not hide a missing dependency behind a constructor error', function (): void {
    $container = new class implements ContainerInterface
    {
        public function get(string $id): never
        {
            throw new class('No entry for Mailer') extends RuntimeException implements NotFoundExceptionInterface {};
        }

        public function has(string $id): bool
        {
            return false;
        }
    };
    Schedule::useContainer($container);
    Schedule::event('needs_mailer')->daily()->call(NeedsMailer::class);

    expect(fn () => ($this->hooks['needs_mailer'][0])())->toThrow(RuntimeException::class, 'No entry for Mailer');

    Schedule::event('plain')->daily()->call(Reports::class);
    ($this->hooks['plain'][0])();

    expect(Reports::$calls)->toBe([['invoked', []]]);
});

it('fires another WordPress hook when the event is due', function (): void {
    Functions\expect('do_action_ref_array')->once()->with('rebuild_sitemap', ['full']);

    Schedule::event('nightly')->daily()->call('rebuild_sitemap');

    ($this->hooks['nightly'][0])('full');
});

it('attaches the same handler to a hook once', function (): void {
    Schedule::event('nightly')->daily()->call([Reports::class, 'compile']);
    Schedule::event('nightly')->daily()->call([Reports::class, 'compile']);

    expect($this->hooks['nightly'])->toHaveCount(1);
});

it('refuses a recurrence on a single event and a recurring event without one', function (): void {
    expect(fn () => Schedule::once('x')->daily())->toThrow(LogicException::class)
        ->and(fn () => Schedule::event('x')->schedule())->toThrow(LogicException::class);
});

it('refuses a date it cannot read', function (): void {
    expect(fn () => Schedule::once('x')->at('not a date')->schedule())->toThrow(InvalidArgumentException::class);
});

describe('Schedule::fake()', function (): void {
    beforeEach(function (): void {
        Schedule::useDriver(new WpCronDriver);
        $this->faked = Schedule::fake();
    });

    it('records instead of scheduling, and attaches no handler', function (): void {
        Schedule::event('reports_compile')->daily()->call([Reports::class, 'compile']);
        Schedule::once('send_digest')->in(minutes: 5)->with(['user' => 1])->schedule();

        Schedule::assertScheduled('reports_compile');
        Schedule::assertScheduled('send_digest', fn (Event $event): bool => $event->args === ['user' => 1]);
        Schedule::assertScheduledTimes('send_digest', 1);
        Schedule::assertNotScheduled('send_digest', fn (Event $event): bool => $event->args === ['user' => 2]);
        Schedule::assertNotScheduled('other');

        expect($this->hooks)->toBe([]);
    });

    it('asserts that nothing was scheduled, until something is', function (): void {
        Schedule::assertNothingScheduled();

        Schedule::once('x')->schedule();
        Schedule::assertNotScheduled('y');

        expect($this->faked->events())->toHaveCount(1);
    });

    it('returns the same fake when called twice, and the real driver after reset()', function (): void {
        expect(Schedule::fake())->toBe($this->faked);

        Schedule::reset();

        expect(Schedule::engine()->driver())->not->toBeInstanceOf(ScheduleFake::class)
            ->and(fn () => Schedule::assertScheduled('x'))->toThrow(LogicException::class);
    });
});

it('has the default engine on WP-Cron', function (): void {
    expect((new ScheduleEngine)->driver())->toBeInstanceOf(ScheduleDriver::class)
        ->and((new ScheduleEngine)->driver()::class)->toBe(WpCronDriver::class);
});
