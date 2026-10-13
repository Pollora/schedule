<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Testing\ScheduleFake;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

beforeEach(function (): void {
    $this->filters = [];
    $this->actions = [];
    $this->cron = [];
    $this->wpCron = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook] = $callback;

        return true;
    });
    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->actions[$hook][] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook): bool {
        unset($this->filters[$hook]);

        return true;
    });
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    // WP-Cron, as the engine's fallback reaches it
    Functions\when('wp_schedule_single_event')->alias(function (int $ts, string $hook, array $args): bool {
        $this->wpCron[] = ['once', $hook, $ts, $args];

        return true;
    });
    Functions\when('wp_schedule_event')->alias(function (int $ts, string $recurrence, string $hook, array $args): bool {
        $this->wpCron[] = ['recurring', $hook, $ts, $args];

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    Functions\when('wp_unschedule_hook')->justReturn(0);

    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver(new ActionSchedulerDriver);
});

it('sends a new event to the chosen driver', function (): void {
    $this->engine->schedule(new Event('digest', time() + 100, null, ['u' => 1]));
    $this->engine->schedule(new Event('reports', time() + 100, 'daily'));

    expect($this->wpCron)->toBe([])
        ->and(array_column(CronIndex::events('action-scheduler'), 'hook'))->toEqualCanonicalizing(['digest', 'reports'])
        ->and(ActionScheduler_FakeStore::$actions)->toHaveCount(2)
        ->and($this->engine->next('digest', ['u' => 1]))->not->toBeNull();
});

it('keeps Action Scheduler\'s own queue runner on WP-Cron', function (): void {
    $this->engine->place(new Event('action_scheduler_run_queue', time(), 'daily'));

    expect($this->wpCron)->toHaveCount(1)
        ->and(ActionScheduler_FakeStore::$actions)->toBe([]);
});

it('keeps hooks protected by the site on WP-Cron', function (): void {
    $this->engine->protect('my_heartbeat');
    Functions\when('apply_filters')->alias(fn (string $filter, mixed $value): mixed => $filter === 'pollora/schedule/protected_hooks' ? [...$value, 'from_filter'] : $value);

    $this->engine->place(new Event('my_heartbeat', time()));
    $this->engine->place(new Event('from_filter', time()));

    expect(array_column($this->wpCron, 1))->toBe(['my_heartbeat', 'from_filter']);
});

it('leaves an event already in the cron option on WP-Cron, so it keeps rescheduling itself there', function (): void {
    $this->cron = [time() + 50 => ['wp_version_check' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => []]]]];

    $this->engine->place(new Event('wp_version_check', time() + 86400, 'daily'));

    expect($this->wpCron)->toHaveCount(1)
        ->and(ActionScheduler_FakeStore::$actions)->toBe([])
        ->and($this->engine->driverFor('wp_version_check', [])->available())->toBeTrue();
});

it('falls back to WP-Cron when the driver is not there', function (): void {
    $absent = new class implements ScheduleDriver
    {
        public function available(): bool
        {
            return false;
        }

        public function once(string $hook, int $timestamp, array $args = []): bool
        {
            throw new LogicException('never');
        }

        public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
        {
            throw new LogicException('never');
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
    $engine = new ScheduleEngine;
    $engine->selectDriver($absent);

    $engine->place(new Event('digest', time() + 10));

    expect($this->wpCron)->toHaveCount(1);
});

it('keeps what is scheduled before Action Scheduler is ready, then gives it once, without duplicates', function (): void {
    ActionScheduler::$initialized = false;
    $time = time() + 3600;

    foreach (range(1, 3) as $_) {
        $this->engine->schedule(new Event('reports', $time, 'daily'));
    }

    expect(ActionScheduler_FakeStore::$actions)->toBe([])
        ->and($this->engine->next('reports'))->toBe($time)
        ->and($this->engine->events())->toHaveCount(1)
        ->and($this->actions['action_scheduler_init'])->toHaveCount(1);

    // Another plugin got there first once it was ready
    ActionScheduler::$initialized = true;
    as_schedule_recurring_action($time, 86400, 'other', [], 'pollora/schedule');
    $this->engine->schedule(new Event('other', $time, 'daily'));
    ($this->actions['action_scheduler_init'][0])();

    expect(ActionScheduler_FakeStore::$actions)->toHaveCount(2)
        ->and($this->engine->events())->toHaveCount(2);
});

it('cancels what is still buffered', function (): void {
    ActionScheduler::$initialized = false;
    $this->engine->schedule(new Event('digest', time() + 10, null, ['u' => 1]));

    expect($this->engine->cancel('digest', ['u' => 1]))->toBe(1)
        ->and($this->engine->next('digest', ['u' => 1]))->toBeNull();

    ($this->actions['action_scheduler_init'][0])();

    expect(ActionScheduler_FakeStore::$actions)->toBe([]);
});

it('reads, cancels and lists in both stores', function (): void {
    $this->cron = [time() + 50 => ['wp_version_check' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => []]]]];
    Functions\when('wp_next_scheduled')->alias(fn (string $hook): int|false => $hook === 'wp_version_check' ? time() + 50 : false);
    $this->engine->schedule(new Event('digest', time() + 100));

    expect(array_column($this->engine->events(), 'hook'))->toBe(['wp_version_check', 'digest'])
        ->and($this->engine->next('wp_version_check'))->not->toBeNull();

    Functions\when('wp_clear_scheduled_hook')->alias(fn (string $hook): int => $hook === 'wp_version_check' ? 1 : 0);

    expect($this->engine->cancel('digest'))->toBe(1)
        ->and($this->engine->cancel('wp_version_check'))->toBe(1)
        ->and($this->engine->next('digest'))->toBeNull();
});

it('chooses a driver by name, and a site that never left WP-Cron hooks into nothing', function (): void {
    Schedule::extend('test', fn (): ScheduleDriver => new ScheduleFake);
    Schedule::driver('test');

    expect(Schedule::engine()->driver())->toBeInstanceOf(ScheduleFake::class)
        ->and($this->filters)->toHaveKey('pre_schedule_event')
        ->and(Schedule::knows('test'))->toBeTrue()
        ->and(Schedule::knows('nope'))->toBeFalse();

    $this->filters = [];
    $this->actions = [];
    Schedule::driver('wp-cron');

    // Nothing marked in the option: the check waits for init, and finds nothing to guard
    expect($this->filters)->toBe([])
        ->and(fn () => Schedule::driver('nope'))->toThrow(InvalidArgumentException::class);

    foreach ($this->actions['init'] ?? [] as $callback) {
        $callback();
    }

    expect($this->filters)->toBe([])
        ->and($this->actions)->not->toHaveKey('action_scheduler_stored_action');
});

it('guards a site back on WP-Cron that another driver still keeps events for, once WordPress has initialised', function (): void {
    CronIndex::record(new Event('digest', time() + 100), 'action-scheduler');
    $this->filters = [];
    $this->actions = [];

    Schedule::driver('wp-cron');

    expect($this->filters)->toBe([]);

    foreach ($this->actions['init'] as $callback) {
        $callback();
    }

    expect(array_keys($this->filters))->toBe(['pre_get_ready_cron_jobs', 'pre_update_option_cron', 'pre_reschedule_event', 'pre_unschedule_event'])
        ->and($this->actions)->toHaveKey('action_scheduler_stored_action');
});

it('wires the driver chosen before WordPress could take hooks, once it can', function (): void {
    // The application chose it at its own start: Schedule::driver() only noted the name
    $this->filters = [];
    (new ReflectionClass(Schedule::class))->setStaticPropertyValue('wanted', 'action-scheduler');

    expect($this->filters)->toBe([]);

    Schedule::boot();

    expect(Schedule::engine()->driverName())->toBe('action-scheduler')
        ->and($this->filters)->toHaveKey('pre_schedule_event');

    $filters = $this->filters;
    Schedule::boot();

    expect($this->filters)->toBe($filters);
});
