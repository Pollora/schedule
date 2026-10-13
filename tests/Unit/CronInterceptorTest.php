<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Testing\ScheduleFake;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

beforeEach(function (): void {
    $this->filters = [];
    $this->cron = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook): bool {
        unset($this->filters[$hook]);

        return true;
    });
    Functions\when('add_action')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);

    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver(new ActionSchedulerDriver);
    $this->filter = fn (string $name, mixed ...$arguments): mixed => ($this->filters[$name])(...$arguments);
});

it('installs the pre_* filters of the core, and removes them', function (): void {
    expect(array_keys($this->filters))->toBe(['pre_schedule_event', 'pre_reschedule_event', 'pre_unschedule_event', 'pre_clear_scheduled_hook', 'pre_unschedule_hook', 'pre_get_ready_cron_jobs', 'pre_update_option_cron']);

    $this->engine->useDriver(new ScheduleFake);

    expect($this->filters)->toBe([]);
});

it('takes a new event from wp_schedule_event() and schedules it in the driver', function (): void {
    $event = (object) ['hook' => 'reports', 'timestamp' => time() + 60, 'schedule' => 'daily', 'args' => [], 'interval' => 86400];

    expect(($this->filter)('pre_schedule_event', null, $event, false))->toBeTrue()
        ->and(array_values(ActionScheduler_FakeStore::$actions)[0]->schedule->interval)->toBe(86400);
});

it('answers a duplicate single event as the core does', function (): void {
    $event = (object) ['hook' => 'digest', 'timestamp' => time() + 3600, 'schedule' => false, 'args' => ['u' => 1]];

    expect(($this->filter)('pre_schedule_event', null, $event, true))->toBeTrue();

    $error = ($this->filter)('pre_schedule_event', null, $event, true);

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_code())->toBe('duplicate_event')
        ->and(($this->filter)('pre_schedule_event', null, $event, false))->toBeFalse();
});

it('reports a failure as a WP_Error, or false', function (): void {
    ActionScheduler::$initialized = true;
    Functions\when('wp_get_schedules')->justReturn([]);
    $event = (object) ['hook' => 'reports', 'timestamp' => time(), 'schedule' => 'daily', 'args' => [], 'interval' => 86400];

    expect(($this->filter)('pre_schedule_event', null, $event, true))->toBeInstanceOf(WP_Error::class)
        ->and(($this->filter)('pre_schedule_event', null, $event, false))->toBeFalse();
});

it('steps aside for what WP-Cron owns: protected hooks, the cron option, an earlier filter', function (): void {
    $protected = (object) ['hook' => 'action_scheduler_run_queue', 'timestamp' => time(), 'schedule' => 'daily', 'args' => []];
    $this->cron = [time() + 5 => ['wp_version_check' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => []]]]];
    $inOption = (object) ['hook' => 'wp_version_check', 'timestamp' => time() + 86400, 'schedule' => 'daily', 'args' => []];

    expect(($this->filter)('pre_schedule_event', null, $protected, false))->toBeNull()
        ->and(($this->filter)('pre_schedule_event', null, $inOption, false))->toBeNull()
        ->and(($this->filter)('pre_schedule_event', true, $inOption, false))->toBeTrue()
        ->and(($this->filter)('pre_reschedule_event', null, $inOption, false))->toBeNull()
        ->and(ActionScheduler_FakeStore::$actions)->toBe([]);
});

it('does not move a recurring event WP-Cron runs: rescheduling and unscheduling it are the core\'s', function (): void {
    $time = time() + 5;
    $this->cron = [$time => ['wp_version_check' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => []]]]];
    $event = (object) ['hook' => 'wp_version_check', 'timestamp' => $time, 'schedule' => 'daily', 'args' => []];

    expect(($this->filter)('pre_reschedule_event', null, $event, true))->toBeNull()
        ->and(($this->filter)('pre_schedule_event', null, $event, true))->toBeNull()
        ->and(($this->filter)('pre_unschedule_event', null, $time, 'wp_version_check', [], true))->toBeNull()
        ->and(ActionScheduler_FakeStore::$actions)->toBe([]);
});

it('leaves rescheduling of the driver\'s own events to its runner', function (): void {
    $event = (object) ['hook' => 'reports', 'timestamp' => time(), 'schedule' => 'daily', 'args' => []];

    expect(($this->filter)('pre_reschedule_event', null, $event, true))->toBeTrue();
});

it('unschedules an event of the driver at its timestamp, and leaves other timestamps to the core', function (): void {
    $time = time() + 600;
    $this->engine->place(new Event('digest', $time));

    expect(($this->filter)('pre_unschedule_event', null, $time + 1, 'digest', [], true))->toBeNull()
        ->and(($this->filter)('pre_unschedule_event', null, $time, 'digest', [], true))->toBeTrue()
        ->and($this->engine->next('digest'))->toBeNull();
});

it('clears both stores and adds the counts, the core function running without the interceptor', function (): void {
    $this->engine->place(new Event('digest', time() + 600, null, ['u' => 1]));
    $inner = null;
    Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook, array $args, bool $wpError) use (&$inner): int {
        $inner = ($this->filter)('pre_clear_scheduled_hook', null, $hook, $args, $wpError);

        return 2;
    });
    Functions\when('wp_unschedule_hook')->alias(fn (): int => 3);

    // The event is in the option too, so the core's count has it already
    expect(($this->filter)('pre_clear_scheduled_hook', null, 'digest', ['u' => 1], false))->toBe(2)
        ->and($inner)->toBeNull()
        ->and(($this->filter)('pre_unschedule_hook', null, 'digest', false))->toBe(3)
        ->and($this->engine->next('digest', ['u' => 1]))->toBeNull();
});

it('writes what the driver keeps to the cron option, marked with the driver, and leaves the core to read it', function (): void {
    $time = time() + 600;
    $this->engine->place(new Event('reports', $time, 'daily', ['x' => 1]));

    expect($this->cron[$time]['reports'][md5(serialize(['x' => 1]))])->toBe(['schedule' => 'daily', 'args' => ['x' => 1], 'interval' => 86400, 'driver' => 'action-scheduler'])
        ->and($this->filters)->not->toHaveKey('pre_get_scheduled_event');
});

it('does not let WP-Cron run what another driver keeps', function (): void {
    $past = time() - 60;
    $this->cron = [
        $past - 10 => ['wp_version_check' => ['k1' => ['schedule' => 'daily', 'args' => []]]],
        $past => ['reports' => ['k2' => ['schedule' => 'daily', 'args' => [], 'interval' => 86400, 'driver' => 'action-scheduler']]],
        time() + 600 => ['later' => ['k3' => ['schedule' => false, 'args' => []]]],
        'version' => 2,
    ];

    expect(array_keys(($this->filter)('pre_get_ready_cron_jobs', null)))->toBe([$past - 10])
        ->and(($this->filter)('pre_get_ready_cron_jobs', ['mine' => 1]))->toBe(['mine' => 1]);

    $this->cron = [$past => ['wp_version_check' => ['k1' => ['schedule' => 'daily', 'args' => []]]]];

    expect(($this->filter)('pre_get_ready_cron_jobs', null))->toBeNull();
});

it('unschedules an event the index shows as the driver\'s, and only at its time', function (): void {
    $time = time() + 600;
    $this->engine->place(new Event('reports', $time, 'daily'));

    expect(($this->filter)('pre_unschedule_event', null, $time + 1, 'reports', [], true))->toBeNull()
        ->and(($this->filter)('pre_unschedule_event', null, $time, 'reports', [], true))->toBeTrue()
        ->and($this->engine->next('reports'))->toBeNull()
        ->and($this->cron)->toBe([]);
});

it('counts a cleared event once, though both the driver and the option had it', function (): void {
    $time = time() + 600;
    $this->engine->place(new Event('digest', $time, null, ['u' => 1]));
    Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook, array $args): int {
        // The core counts what is still in the option, the event of the driver included
        $count = 0;
        foreach ($this->cron as $hooks) {
            $count += count($hooks[$hook] ?? []);
        }

        return $count;
    });

    expect(($this->filter)('pre_clear_scheduled_hook', null, 'digest', ['u' => 1], false))->toBe(1);
});

it('gives the driver what a direct write to the option removed or moved', function (): void {
    $time = time() + 600;
    $this->engine->place(new Event('gone', $time, null, ['u' => 1]));
    $this->engine->place(new Event('moved', $time + 1));
    $old = $this->cron;
    $new = $old;
    unset($new[$time]);
    $new[$time + 100]['moved'] = $old[$time + 1]['moved'];
    unset($new[$time + 1]);

    expect(($this->filter)('pre_update_option_cron', $new, $old, 'cron'))->toBe($new)
        ->and($this->engine->next('gone', ['u' => 1]))->toBeNull()
        ->and($this->engine->next('moved'))->toBe($time + 100);
});

it('does not take its own writes for somebody else\'s', function (): void {
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    $time = time() + 600;
    $this->engine->place(new Event('digest', $time));
    $before = $this->cron;

    $this->engine->cancel('digest');

    expect($before)->not->toBe([])
        ->and($this->cron)->toBe([])
        ->and(array_values(ActionScheduler_FakeStore::$actions)[0]->status)->toBe('canceled');
});
