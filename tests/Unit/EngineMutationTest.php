<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Tests\Support\EngineProbe;
use Pollora\Schedule\Tests\Support\FindingProbe;
use Pollora\Schedule\Tests\Support\ReadyProbe;

require_once __DIR__.'/../Support/EngineProbes.php';

const MUT_AT = 4_000_000_000;

beforeEach(function (): void {
    $this->filters = [];
    $this->actions = [];
    $this->cron = [];
    $this->added = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
        $this->filters[$hook] = [$callback, $priority, $accepted];

        return true;
    });
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
        $this->actions[$hook][] = $callback;
        $this->added[] = [$hook, $priority, $accepted];

        return true;
    });
    Functions\when('did_action')->justReturn(0);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    Functions\when('wp_unschedule_hook')->justReturn(0);
    Functions\when('wp_schedule_single_event')->justReturn(true);
    Functions\when('wp_schedule_event')->justReturn(true);
    Functions\when('do_action_ref_array')->justReturn(null);
});

describe('guarding a site that is back on WP-Cron', function (): void {
    it('guards at once when init has already fired, and watches the drivers that still keep events', function (): void {
        Functions\when('did_action')->justReturn(1);
        CronIndex::record(new Event('digest', MUT_AT), 'queue');
        $leftover = new FindingProbe;
        $engine = new ScheduleEngine;

        $engine->guardIfNeeded(fn (): array => ['queue' => $leftover]);

        expect($this->filters)->toHaveKey('pre_update_option_cron')
            ->and($this->filters)->not->toHaveKey('pre_schedule_event')
            ->and($leftover->observed)->toBe(1)
            ->and($this->added)->toBe([]);
    });

    it('waits for init, first in line, when it has not fired', function (): void {
        CronIndex::record(new Event('digest', MUT_AT), 'queue');
        $leftover = new FindingProbe;
        $engine = new ScheduleEngine;

        $engine->guardIfNeeded(fn (): array => ['queue' => $leftover]);

        expect($this->added)->toBe([['init', 0, 1]])
            ->and($this->filters)->toBe([])
            ->and($leftover->observed)->toBe(0);

        ($this->actions['init'][0])();

        expect($this->filters)->toHaveKey('pre_update_option_cron')
            ->and($leftover->observed)->toBe(1);
    });

    it('does nothing when the index marks no event', function (): void {
        Functions\when('did_action')->justReturn(1);
        $leftover = new FindingProbe;

        (new ScheduleEngine)->guardIfNeeded(fn (): array => ['queue' => $leftover]);

        expect($this->filters)->toBe([])
            ->and($leftover->observed)->toBe(0);
    });
});

describe('drivers by name', function (): void {
    it('answers WP-Cron with the fallback, the chosen driver when it is WP-Cron, or one kept for later', function (): void {
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver(new EngineProbe, 'queue');

        expect($engine->driverNamed('wp-cron'))->toBe($engine->stores()[1]);

        $cron = new WpCronDriver;
        $onCron = new ScheduleEngine($cron);
        expect($onCron->driverNamed('wp-cron'))->toBe($cron);

        $other = new ScheduleEngine(new EngineProbe);
        $other->useDriver(new EngineProbe, 'queue');
        $built = $other->driverNamed('wp-cron');

        expect($built)->toBeInstanceOf(WpCronDriver::class)
            ->and($other->driverNamed('wp-cron'))->toBe($built)
            ->and($other->driverNamed('queue'))->toBe($other->driver());
    });

    it('watches a driver it builds, once', function (): void {
        $built = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->resolveDriversWith(fn (string $name): FindingProbe => $built);

        $engine->driverNamed('queue');

        expect($built->observed)->toBe(1);

        $engine->driverNamed('queue');
        $engine->watch('queue', $built);

        expect($built->observed)->toBe(1);
    });

    it('does not watch a driver that reports nothing', function (): void {
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->watch('queue', new EngineProbe);

        expect(true)->toBeTrue();
    });
});

describe('refreshing the index', function (): void {
    it('reconciles rather than looks for one event when a driver only says something changed', function (): void {
        $driver = new FindingProbe;
        $driver->found = new Event('stray', MUT_AT);
        $driver->store = [new Event('real', MUT_AT + 1)];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');

        $engine->refresh('queue', '');

        expect(CronIndex::ownerOf('real'))->toBe('queue')
            ->and(CronIndex::ownerOf('stray'))->toBeNull();
    });

    it('is deaf to a driver while the engine itself changes events', function (): void {
        $driver = new FindingProbe;
        $driver->found = new Event('digest', MUT_AT);
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');

        $engine->quietly(function () use ($engine): void {
            $engine->refresh('queue', 'digest');
        });

        expect(CronIndex::ownerOf('digest'))->toBeNull();

        $engine->refresh('queue', 'digest');

        expect(CronIndex::ownerOf('digest'))->toBe('queue');
    });

    it('takes the event out of the index when its driver cannot find events', function (): void {
        $plain = new EngineProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->resolveDriversWith(fn (string $name): EngineProbe => $plain);
        CronIndex::record(new Event('digest', MUT_AT), 'queue');

        $engine->refresh('queue', 'digest');

        expect(CronIndex::ownerOf('digest'))->toBeNull();
    });

    it('does not reindex WP-Cron, whose events the core writes', function (): void {
        $this->cron = [MUT_AT => ['core_event' => ['k' => ['schedule' => false, 'args' => []]]]];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver(new EngineProbe, 'queue');

        expect($engine->reindex('wp-cron'))->toBe(0)
            ->and($this->cron)->toHaveKey(MUT_AT)
            ->and($this->cron[MUT_AT])->toHaveKey('core_event');
    });

    it('reindexes the chosen driver by default and counts what it writes and removes', function (): void {
        $driver = new EngineProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');
        $driver->store = [new Event('fresh', MUT_AT), new Event('kept', MUT_AT + 1)];
        CronIndex::record(new Event('kept', MUT_AT + 1), 'queue');
        CronIndex::record(new Event('gone', MUT_AT + 2), 'queue');

        expect($engine->reindex())->toBe(2)
            ->and(CronIndex::ownerOf('fresh'))->toBe('queue')
            ->and(CronIndex::ownerOf('kept'))->toBe('queue')
            ->and(CronIndex::ownerOf('gone'))->toBeNull();
    });
});

describe('protected hooks', function (): void {
    it('lists the built-in ones, the site\'s and the filter\'s, as unique strings in a list', function (): void {
        $engine = new ScheduleEngine;
        $engine->protect('mine');
        $engine->protect('yours', 'mine');

        Filters\expectApplied('pollora/schedule/protected_hooks')->once()->andReturnUsing(
            static fn (array $hooks): array => [...$hooks, 'mine', 77],
        );

        expect($engine->protectedHooks())->toBe(['action_scheduler_run_queue', 'mine', 'yours', '77']);
    });

    it('takes what a filter returns for a list, even a single name', function (): void {
        Filters\expectApplied('pollora/schedule/protected_hooks')->once()->andReturn('solo');

        expect((new ScheduleEngine)->protectedHooks())->toBe(['solo']);
    });
});

describe('where an event goes', function (): void {
    it('is the chosen driver when WP-Cron is not set aside, available or not', function (): void {
        $driver = new EngineProbe;
        $driver->available = false;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');

        expect($engine->driverFor('digest'))->toBe($driver);
    });
});

describe('the engine sets itself busy while a driver changes', function (): void {
    function busyProbe(object $test, FindingProbe $driver, ScheduleEngine $engine, callable $operation): array
    {
        $driver->found = new Event('probe', MUT_AT);
        $during = [];
        $driver->during = function () use ($engine, &$during): void {
            $engine->refresh('queue', 'digest');
            $during[] = CronIndex::ownerOf('probe');
        };

        $operation();
        $driver->during = null;

        $engine->refresh('queue', 'digest');

        return [$during, CronIndex::ownerOf('probe')];
    }

    it('when it cancels an event the index shows as a driver\'s, and then forgets it', function (): void {
        $driver = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');
        $driver->store = [new Event('digest', MUT_AT)];
        CronIndex::record(new Event('digest', MUT_AT), 'queue');

        [$during, $after] = busyProbe($this, $driver, $engine, function () use ($engine): void {
            expect($engine->cancelOwned('queue', 'digest', []))->toBe(1)
                ->and(CronIndex::ownerOf('digest'))->toBeNull();
        });

        expect($during)->toBe([null])
            ->and($after)->toBe('queue');
    });

    it('when it applies what a write to the option did', function (): void {
        $driver = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');
        $removed = new Event('old', MUT_AT, null, [], null, 'queue');
        $moved = new Event('digest', MUT_AT + 5, null, [], null, 'queue');

        [$during, $after] = busyProbe($this, $driver, $engine, function () use ($engine, $removed, $moved, $driver): void {
            $engine->applyChanges(['removed' => [$removed], 'moved' => [$moved]]);

            expect($driver->log)->toBe(['cancel:old', 'cancel:digest', 'once:digest']);
        });

        expect($during)->toBe([null, null, null])
            ->and($after)->toBe('queue');
    });

    it('when it cancels', function (): void {
        $driver = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');

        [$during, $after] = busyProbe($this, $driver, $engine, fn () => $engine->cancel('digest'));

        expect($during)->toBe([null])
            ->and($after)->toBe('queue');
    });

    it('when it clears', function (): void {
        $driver = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');

        [$during, $after] = busyProbe($this, $driver, $engine, fn () => $engine->clear('digest'));

        expect($during)->toBe([null])
            ->and($after)->toBe('queue');
    });

    it('when it gives an event to a driver', function (): void {
        $driver = new FindingProbe;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');

        [$during, $after] = busyProbe($this, $driver, $engine, fn () => $engine->place(new Event('digest', MUT_AT)));

        expect($during)->toBe([null])
            ->and($after)->toBe('queue');
    });
});

describe('the clock', function (): void {
    it('returns an integer even from a clock that answers with a string', function (): void {
        $engine = new ScheduleEngine;
        $engine->useClock(fn (): string => '1234');

        expect($engine->now())->toBe(1234);
    });
});

describe('scheduling', function (): void {
    it('schedules an event once and says so', function (): void {
        $driver = new EngineProbe;
        $engine = new ScheduleEngine($driver);

        expect($engine->schedule(new Event('digest', MUT_AT)))->toBeTrue()
            ->and($engine->schedule(new Event('digest', MUT_AT)))->toBeFalse()
            ->and($driver->log)->toBe(['once:digest']);
    });

    it('finds the soonest time among the stores and the buffer, without reading a missing buffer entry', function (): void {
        Functions\when('wp_next_scheduled')->justReturn(MUT_AT + 5);
        $driver = new EngineProbe;
        $driver->store = [new Event('digest', MUT_AT + 10)];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');

        expect(strictly(fn () => $engine->next('digest')))->toBe(MUT_AT + 5);

        Functions\when('wp_next_scheduled')->justReturn(MUT_AT + 50);

        expect(strictly(fn () => $engine->next('digest')))->toBe(MUT_AT + 10);
    });

    it('keeps the time a driver gives for an event the index shows as its own, which needs no readiness', function (): void {
        $driver = new EngineProbe;
        $driver->store = [new Event('digest', MUT_AT + 7)];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');
        CronIndex::record(new Event('digest', MUT_AT + 7), 'queue');

        expect($engine->next('digest'))->toBe(MUT_AT + 7)
            ->and(CronIndex::ownerOf('digest'))->toBe('queue');

        $driver->store = [];

        expect($engine->next('digest'))->toBeNull()
            ->and(CronIndex::ownerOf('digest'))->toBeNull();
    });

    it('answers with the time of a driver that only the index names', function (): void {
        $other = new EngineProbe;
        $other->store = [new Event('digest', MUT_AT + 3)];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver(new EngineProbe, 'queue');
        $engine->resolveDriversWith(fn (string $name): EngineProbe => $other);
        CronIndex::record(new Event('digest', MUT_AT + 3), 'elsewhere');

        expect($engine->next('digest'))->toBe(MUT_AT + 3);
    });

    it('trusts the index for a driver that is not ready, for these arguments only', function (): void {
        $driver = new ReadyProbe;
        $driver->ready = false;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');
        CronIndex::record(new Event('other', MUT_AT, null, [2]), 'queue');
        CronIndex::record(new Event('digest', MUT_AT + 1, null, [1]), 'queue');
        CronIndex::record(new Event('digest', MUT_AT + 2, null, [2]), 'queue');

        expect($engine->next('digest', [2]))->toBe(MUT_AT + 2)
            ->and($engine->next('digest', [1]))->toBe(MUT_AT + 1)
            ->and(CronIndex::ownerOf('digest', [2]))->toBe('queue');
    });
});

describe('listing what is scheduled', function (): void {
    it('lists only the driver\'s own events when WP-Cron is not involved, whatever the index says', function (): void {
        $driver = new EngineProbe;
        $driver->store = [new Event('mine', MUT_AT + 1)];
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->useDriver($driver, 'queue');
        CronIndex::record(new Event('indexed', MUT_AT), 'queue');

        expect(array_column($engine->events(), 'hook'))->toBe(['mine']);
    });

    it('merges the buffer, the index and the stores soonest first, each event once', function (): void {
        $driver = new ReadyProbe;
        $driver->ready = false;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');
        $engine->place(new Event('buffered', MUT_AT + 30));
        $driver->ready = true;
        $driver->store = [new Event('stored', MUT_AT + 20), new Event('both', MUT_AT + 10)];
        CronIndex::record(new Event('both', MUT_AT + 10), 'queue');
        CronIndex::record(new Event('indexed', MUT_AT + 40), 'queue');
        CronIndex::record(new Event('early', MUT_AT + 5), 'wp-cron');

        expect(array_column($engine->events(), 'hook'))->toBe(['early', 'both', 'stored', 'buffered', 'indexed']);
    });

    it('lists the index too when the driver is WP-Cron', function (): void {
        $engine = new ScheduleEngine;
        CronIndex::record(new Event('indexed', MUT_AT), 'queue');
        Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);

        expect(array_column($engine->events(), 'hook'))->toContain('indexed');
    });
});

describe('the buffer', function (): void {
    it('keeps the first of two events with the same hook and arguments, and tells them apart by arguments', function (): void {
        $driver = new ReadyProbe;
        $driver->ready = false;
        $engine = new ScheduleEngine(new EngineProbe);
        $engine->selectDriver($driver, 'queue');

        expect($engine->place(new Event('digest', MUT_AT, null, ['a'])))->toBeTrue()
            ->and($engine->place(new Event('digest', MUT_AT + 9, null, ['a'])))->toBeTrue()
            ->and($engine->place(new Event('digest', MUT_AT + 3, null, ['b'])))->toBeTrue();

        $times = array_column($engine->events(), 'timestamp');
        sort($times);

        expect($times)->toBe([MUT_AT, MUT_AT + 3])
            ->and(array_filter($this->added, fn (array $added): bool => $added[0] === 'probe_ready'))->toBe([0 => ['probe_ready', 20, 1]]);
    });
});

describe('attaching handlers', function (): void {
    it('attaches nothing when handlers are off', function (): void {
        $engine = new ScheduleEngine;
        $engine->attachHandlers(false);
        $engine->attach('digest', fn () => null);

        expect($this->added)->toBe([]);
    });

    it('hooks at the default priority with every argument', function (): void {
        (new ScheduleEngine)->attach('digest', 'some_hook');

        expect($this->added)->toBe([['digest', 10, PHP_INT_MAX]]);
    });

    it('runs a closure handler', function (): void {
        $received = [];
        (new ScheduleEngine)->attach('digest', function (array $args = []) use (&$received): void {
            $received[] = $args;
        });

        ($this->actions['digest'][0])('x');
        ($this->actions['digest'][0])();

        expect($received)->toBe([['x'], []]);
    });

    it('says so when a class handler is not invokable, instead of failing on the call', function (): void {
        $handler = (new class {})::class;
        (new ScheduleEngine)->attach('digest', $handler);

        expect(fn () => ($this->actions['digest'][0])())->toThrow(ScheduleException::class, 'is not invokable');
    });

    it('says so when a [class, method] handler has no such method', function (): void {
        (new ScheduleEngine)->attach('digest', [new class {}, 'missing']);

        expect(fn () => ($this->actions['digest'][0])())->toThrow(ScheduleException::class, 'is not callable');
    });

    it('fires nothing for an empty hook name', function (): void {
        $fired = [];
        Functions\when('do_action_ref_array')->alias(function (string $hook) use (&$fired): void {
            $fired[] = $hook;
        });
        (new ScheduleEngine)->attach('digest', '');

        ($this->actions['digest'][0])();

        expect($fired)->toBe([]);
    });

    it('tells handlers apart: by hook, handler, object and method', function (): void {
        $engine = new ScheduleEngine;
        $one = new class
        {
            public function a(): void {}

            public function b(): void {}
        };
        $two = new class
        {
            public function a(): void {}
        };
        $closure = fn () => null;
        $other = fn () => null;
        $attach = function (string $hook, string|array|Closure $handler) use ($engine): int {
            $before = count($this->added);
            strictly(fn () => $engine->attach($hook, $handler));

            return count($this->added) - $before;
        };

        // each is new, a repeat is not
        expect($attach('h', [$one, 'a']))->toBe(1)
            ->and($attach('h', [$one, 'a']))->toBe(0)
            ->and($attach('h', [$one, 'b']))->toBe(1)
            ->and($attach('h', [$two, 'a']))->toBe(1)
            ->and($attach('h2', [$one, 'a']))->toBe(1)
            ->and($attach('h', $closure))->toBe(1)
            ->and($attach('h', $closure))->toBe(0)
            ->and($attach('h', $other))->toBe(1)
            ->and($attach('h', (string) spl_object_id($closure)))->toBe(1)
            ->and($attach('h', ['ClassA', 'x']))->toBe(1)
            ->and($attach('h', ['ClassA', 'x']))->toBe(0)
            ->and($attach('h', ['ClassA', 'y']))->toBe(1)
            ->and($attach('h', ['ClassB', 'x']))->toBe(1)
            ->and($attach('h', ['ab', 'c']))->toBe(1)
            ->and($attach('h', ['a', 'bc']))->toBe(1)
            ->and($attach('a', 'bc'))->toBe(1)
            ->and($attach('ab', 'c'))->toBe(1)
            ->and($attach('yz', 'x'))->toBe(1)
            ->and($attach('z', 'xy'))->toBe(1)
            ->and($attach('a', 'bd'))->toBe(1)
            ->and($attach('a', 'be'))->toBe(1);
    });
});
