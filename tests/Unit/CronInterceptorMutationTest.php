<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Event;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Support\CronInterceptor;
use Pollora\Schedule\Tests\Support\EngineProbe;

require_once __DIR__.'/../Support/EngineProbes.php';

const ICP_AT = 4_000_000_000;

beforeEach(function (): void {
    $this->cron = [];
    $this->added = [];
    $this->removed = [];
    $this->filters = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
        $this->added[] = [$hook, $priority, $accepted];
        $this->filters[$hook] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook, callable $callback, int $priority = 10): bool {
        $this->removed[] = [$hook, $priority, $callback === ($this->filters[$hook] ?? null)];

        return true;
    });
    Functions\when('add_action')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('wp_cache_delete')->justReturn(true);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);

    $this->driver = new EngineProbe;
    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver($this->driver, 'queue');
    $this->added = [];
    $this->filter = fn (string $name, mixed ...$arguments): mixed => ($this->filters[$name])(...$arguments);
});

const ICP_FILTERS = [
    ['pre_schedule_event', 10, 3],
    ['pre_clear_scheduled_hook', 10, 4],
    ['pre_unschedule_hook', 10, 3],
    ['pre_get_ready_cron_jobs', 10, 1],
    ['pre_update_option_cron', 10, 1 + 2],
    ['pre_reschedule_event', 10, 3],
    ['pre_unschedule_event', 10, 5],
];

describe('installing', function (): void {
    it('hooks every filter once, at the default priority, with the arguments it reads', function (): void {
        $interceptor = new CronInterceptor($this->engine);
        $interceptor->install();
        $interceptor->install();

        expect($this->added)->toBe(ICP_FILTERS);
    });

    it('hooks only the guard when it does not route', function (): void {
        (new CronInterceptor($this->engine))->install(routing: false);

        expect(array_column($this->added, 0))->toBe(['pre_get_ready_cron_jobs', 'pre_update_option_cron', 'pre_reschedule_event', 'pre_unschedule_event']);
    });

    it('takes its filters off at the priority it put them, and can then be installed again', function (): void {
        $interceptor = new CronInterceptor($this->engine);
        $interceptor->install();
        $interceptor->uninstall();

        expect($this->removed)->toBe(array_map(fn (array $filter): array => [$filter[0], 10, true], ICP_FILTERS));

        $this->added = [];
        $interceptor->install();

        expect($this->added)->toBe(ICP_FILTERS);
    });
});

describe('a write to the cron option', function (): void {
    it('reaches the driver when an event it keeps is removed, moved, or both, and not when nothing changed', function (): void {
        $entry = fn (array $extra = []) => ['schedule' => false, 'args' => []] + $extra + ['driver' => 'queue'];
        $key = md5(serialize([]));
        $old = [ICP_AT => ['gone' => [$key => $entry()], 'moving' => [$key => $entry()], 'still' => [$key => $entry()]]];

        ($this->filter)('pre_update_option_cron', $old, $old);
        expect($this->driver->log)->toBe([]);

        ($this->filter)('pre_update_option_cron', [ICP_AT => ['moving' => [$key => $entry()], 'still' => [$key => $entry()]]], $old);
        expect($this->driver->log)->toBe(['cancel:gone']);

        $this->driver->log = [];
        ($this->filter)('pre_update_option_cron', [ICP_AT => ['gone' => [$key => $entry()], 'still' => [$key => $entry()]], ICP_AT + 9 => ['moving' => [$key => $entry()]]], $old);
        expect($this->driver->log)->toBe(['cancel:moving', 'once:moving']);

        $this->driver->log = [];
        ($this->filter)('pre_update_option_cron', [ICP_AT => ['still' => [$key => $entry()]], ICP_AT + 9 => ['moving' => [$key => $entry()]]], $old);
        expect($this->driver->log)->toBe(['cancel:gone', 'cancel:moving', 'once:moving']);
    });
});

describe('scheduling through the core', function (): void {
    it('hands the driver an event typed as the core\'s object says, with its own interval or the schedule\'s', function (): void {
        $event = (object) ['hook' => 'digest', 'timestamp' => (string) ICP_AT, 'schedule' => 'daily', 'args' => 'x', 'interval' => '60'];

        expect(strictly(fn () => ($this->filter)('pre_schedule_event', null, $event, false)))->toBeTrue();

        $stored = CronIndex::events('queue')[0];

        expect($this->driver->log)->toBe(['recurring:digest'])
            ->and($this->driver->store[0])->toEqual(new Event('digest', ICP_AT, 'daily', ['x']))
            ->and($stored->interval)->toBe(60)
            ->and($stored->recurrence)->toBe('daily');

        $single = (object) ['hook' => 'single', 'timestamp' => ICP_AT, 'schedule' => false, 'args' => []];

        expect(strictly(fn () => ($this->filter)('pre_schedule_event', null, $single, false)))->toBeTrue()
            ->and($this->driver->store[1])->toEqual(new Event('single', ICP_AT));

        $noInterval = (object) ['hook' => 'plain', 'timestamp' => ICP_AT, 'schedule' => 'daily', 'args' => []];

        expect(strictly(fn () => ($this->filter)('pre_schedule_event', null, $noInterval, false)))->toBeTrue()
            ->and(CronIndex::events('queue')[2]->interval)->toBe(86400);
    });

    it('answers what an earlier filter answered, and steps aside while the core runs', function (): void {
        $event = (object) ['hook' => 'digest', 'timestamp' => ICP_AT, 'schedule' => false, 'args' => []];

        expect(($this->filter)('pre_schedule_event', true, $event, false))->toBeTrue()
            ->and(($this->filter)('pre_reschedule_event', 'earlier', $event, false))->toBe('earlier')
            ->and(($this->filter)('pre_unschedule_event', 'earlier', ICP_AT, 'digest', [], false))->toBe('earlier')
            ->and(($this->filter)('pre_clear_scheduled_hook', 7, 'digest', [], false))->toBe(7)
            ->and(($this->filter)('pre_unschedule_hook', 8, 'digest', false))->toBe(8)
            ->and($this->driver->log)->toBe([]);
    });

    it('does not run again inside the core function it calls', function (): void {
        $inner = [];
        $calls = 0;
        Functions\when('wp_clear_scheduled_hook')->alias(function () use (&$inner, &$calls): int {
            if ($calls++ === 0) {
                $inner[] = ($this->filter)('pre_clear_scheduled_hook', null, 'digest', [], false);
            }

            return 0;
        });
        Functions\when('wp_unschedule_hook')->alias(function () use (&$inner, &$calls): int {
            if ($calls++ === 1) {
                $inner[] = ($this->filter)('pre_unschedule_hook', null, 'digest', false);
            }

            return 0;
        });

        ($this->filter)('pre_clear_scheduled_hook', null, 'digest', [], false);
        ($this->filter)('pre_unschedule_hook', null, 'digest', false);

        expect($inner)->toBe([null, null]);
    });

    it('adds what the driver cancelled to what the core did, never subtracting', function (): void {
        $this->driver->store = [new Event('digest', ICP_AT, null, []), new Event('digest', ICP_AT + 1, null, []), new Event('digest', ICP_AT + 2, null, [])];
        Functions\when('wp_clear_scheduled_hook')->justReturn(2);
        Functions\when('wp_unschedule_hook')->justReturn(2);

        expect(($this->filter)('pre_clear_scheduled_hook', null, 'digest', [], false))->toBe(5)
            ->and(($this->filter)('pre_unschedule_hook', null, 'digest', false))->toBe(2);

        // the index shows an entry the driver no longer has: the core counted it, the driver cancels nothing
        CronIndex::record(new Event('ghost', ICP_AT), 'queue');
        Functions\when('wp_clear_scheduled_hook')->justReturn(5);
        Functions\when('wp_unschedule_hook')->justReturn(5);

        expect(($this->filter)('pre_clear_scheduled_hook', null, 'ghost', [], false))->toBe(5)
            ->and(($this->filter)('pre_unschedule_hook', null, 'ghost', false))->toBe(5);
    });
});

describe('rescheduling and unscheduling', function (): void {
    beforeEach(function (): void {
        $this->event = fn (string|int $hook, int|string $timestamp, array $args = []) => (object) ['hook' => $hook, 'timestamp' => $timestamp, 'schedule' => 'daily', 'args' => $args];
    });

    it('remembers a reschedule so the unschedule at the old time cancels nothing, for that event only', function (): void {
        CronIndex::record(new Event('a', ICP_AT, null, [1]), 'queue');
        CronIndex::record(new Event('b', ICP_AT, null, [1]), 'queue');
        CronIndex::record(new Event('a', ICP_AT, null, [2]), 'queue');

        expect(($this->filter)('pre_reschedule_event', null, ($this->event)('a', (string) ICP_AT, [1]), false))->toBeTrue()
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT, 'b', [1], false))->toBeTrue()
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT, 'a', [2], false))->toBeTrue()
            ->and($this->driver->log)->toBe(['cancel:b', 'cancel:a'])
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT, 'a', [1], false))->toBeTrue()
            ->and($this->driver->log)->toBe(['cancel:b', 'cancel:a']);
    });

    it('takes a hook the core gives as a number', function (): void {
        CronIndex::record(new Event('123', ICP_AT), 'queue');

        expect(strictly(fn () => ($this->filter)('pre_reschedule_event', null, ($this->event)(123, ICP_AT), false)))->toBeTrue();
    });

    it('takes arguments the core gives as a single value for a list of one', function (): void {
        CronIndex::record(new Event('digest', ICP_AT, null, ['x']), 'queue');

        expect(strictly(fn () => ($this->filter)('pre_reschedule_event', null, (object) ['hook' => 'digest', 'timestamp' => ICP_AT, 'schedule' => 'daily', 'args' => 'x'], false)))->toBeTrue();
    });

    it('leaves the reschedule of an event WP-Cron keeps to the core, and answers for one that is new', function (): void {
        expect(($this->filter)('pre_reschedule_event', null, ($this->event)('core', ICP_AT), false))->toBeTrue();
        CronIndex::record(new Event('core', ICP_AT), 'wp-cron');

        expect(($this->filter)('pre_reschedule_event', null, ($this->event)('core', ICP_AT), false))->toBeNull();
    });

    it('answers for an event a driver keeps even when it does not route', function (): void {
        $guard = new CronInterceptor(new ScheduleEngine);
        $guard->install(routing: false);
        CronIndex::record(new Event('kept', ICP_AT), 'queue');
        $reschedule = $this->filters['pre_reschedule_event'];
        $unschedule = $this->filters['pre_unschedule_event'];

        expect($reschedule(null, ($this->event)('kept', ICP_AT), false))->toBeTrue()
            ->and($reschedule(null, ($this->event)('unkept', ICP_AT), false))->toBeNull()
            ->and($unschedule(null, ICP_AT, 'unkept', [], false))->toBeNull();
    });

    it('cancels an event of a driver the engine cannot reach through unscheduling when not routing, and otherwise', function (): void {
        $engine = new ScheduleEngine;
        $driver = new EngineProbe;
        $engine->useDriver($driver, 'queue');
        $guard = new CronInterceptor($engine);
        $guard->install(routing: false);
        CronIndex::record(new Event('kept', ICP_AT), 'queue');

        expect($this->filters['pre_unschedule_event'](null, ICP_AT, 'kept', [], false))->toBeTrue()
            ->and($driver->log)->toBe(['cancel:kept']);
    });

    it('cancels only what the index shows at that very time', function (): void {
        CronIndex::record(new Event('digest', ICP_AT + 5, null, [1]), 'queue');
        CronIndex::record(new Event('other', ICP_AT), 'queue');
        CronIndex::record(new Event('digest', ICP_AT, null, [2]), 'queue');

        expect(($this->filter)('pre_unschedule_event', null, ICP_AT, 'digest', [1], false))->toBeNull()
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT + 5, 'digest', [2], false))->toBeNull()
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT + 5, 'other', [], false))->toBeNull()
            ->and($this->driver->log)->toBe([])
            ->and(($this->filter)('pre_unschedule_event', null, ICP_AT + 5, 'digest', [1], false))->toBeTrue()
            ->and($this->driver->log)->toBe(['cancel:digest']);
    });
});
