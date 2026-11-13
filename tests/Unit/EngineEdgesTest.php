<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Testing\ScheduleFake;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

const EDGE_AT = 4_000_000_000;

beforeEach(function (): void {
    $this->filters = [];
    $this->actions = [];
    $this->cron = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook): bool {
        unset($this->filters[$hook]);

        return true;
    });
    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->actions[$hook][] = $callback;

        return true;
    });
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

    $this->engine = new ScheduleEngine;
    $this->engine->useClock(fn (): int => EDGE_AT - 1000);
    $this->engine->selectDriver(new ActionSchedulerDriver);
    $this->filter = fn (string $name, mixed ...$arguments): mixed => ($this->filters[$name])(...$arguments);
});

describe('drivers by name', function (): void {
    it('builds a driver once through the resolver, and refuses a name nobody knows', function (): void {
        $built = [];
        $this->engine->resolveDriversWith(function (string $name) use (&$built): ?ScheduleFake {
            $built[] = $name;

            return $name === 'queue' ? new ScheduleFake : null;
        });

        expect($this->engine->driverNamed('queue'))->toBe($this->engine->driverNamed('queue'))
            ->and($built)->toBe(['queue'])
            ->and(fn () => $this->engine->driverNamed('ghost'))->toThrow(ScheduleException::class, "No schedule driver is named 'ghost'.");
    });

    it('has nothing to reindex for WP-Cron', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT), 'action-scheduler');

        expect($this->engine->reindex('wp-cron'))->toBe(0)
            ->and(CronIndex::ownerOf('digest'))->toBe('action-scheduler');
    });

    it('ignores what a driver it cannot build reports, and leaves the index as it was', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT), 'ghost');

        $this->engine->refresh('ghost', 'digest');

        expect(CronIndex::ownerOf('digest'))->toBe('ghost');
    });

    it('sends an event whose driver is unknown to WP-Cron, and cannot tell when it runs', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT), 'ghost');

        expect($this->engine->driverFor('digest'))->toBe($this->engine->stores()[1])
            ->and($this->engine->next('digest'))->toBeNull();
    });
});

describe('clearing a hook', function (): void {
    it('cancels it in both stores, with every argument, and takes it out of the index and the buffer', function (): void {
        $this->engine->place(new Event('reports', EDGE_AT, null, ['a' => 1]));
        $this->engine->place(new Event('reports', EDGE_AT + 1, null, ['a' => 2]));
        $this->engine->place(new Event('other', EDGE_AT));
        ActionScheduler::$initialized = false;
        $this->engine->schedule(new Event('reports', EDGE_AT + 2, null, ['a' => 3]));

        ActionScheduler::$initialized = true;
        expect($this->engine->clear('reports'))->toBe(3)
            ->and(array_column(CronIndex::events('action-scheduler'), 'hook'))->toBe(['other'])
            ->and($this->engine->next('reports', ['a' => 3]))->toBeNull();
    });
});

describe('the cron option interceptor', function (): void {
    it('leaves a write that is not an array, or changes nothing a driver keeps, as it is', function (): void {
        $this->engine->place(new Event('reports', EDGE_AT));
        $same = $this->cron;

        expect(($this->filter)('pre_update_option_cron', 'not an array', []))->toBe('not an array')
            ->and(($this->filter)('pre_update_option_cron', $same, $same))->toBe($same)
            ->and(array_values(ActionScheduler_FakeStore::$actions)[0]->status)->toBe('pending');
    });

    it('unschedules an event the driver holds without the index knowing it, only at its time', function (): void {
        $id = as_schedule_single_action(EDGE_AT, 'untracked', [], 'pollora/schedule');

        expect(($this->filter)('pre_unschedule_event', null, EDGE_AT + 1, 'untracked', [], true))->toBeNull()
            ->and(ActionScheduler_FakeStore::$actions[$id]->status)->toBe('pending')
            ->and(($this->filter)('pre_unschedule_event', null, EDGE_AT, 'untracked', [], true))->toBeTrue()
            ->and(ActionScheduler_FakeStore::$actions[$id]->status)->toBe('canceled');
    });

    it('leaves the unscheduling to the core when the driver of the event is gone', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT), 'ghost');

        expect(($this->filter)('pre_unschedule_event', null, EDGE_AT, 'digest', [], true))->toBeNull()
            ->and(CronIndex::ownerOf('digest'))->toBe('ghost');
    });

    it('counts what the driver cancelled when the core finds nothing to clear', function (): void {
        Functions\when('wp_clear_scheduled_hook')->justReturn(false);
        Functions\when('wp_unschedule_hook')->justReturn(false);
        as_schedule_single_action(EDGE_AT, 'untracked', ['u' => 1], 'pollora/schedule');
        as_schedule_single_action(EDGE_AT, 'untracked', ['u' => 2], 'pollora/schedule');

        expect(($this->filter)('pre_clear_scheduled_hook', null, 'untracked', ['u' => 1], false))->toBe(1)
            ->and(($this->filter)('pre_unschedule_hook', null, 'untracked', false))->toBe(1)
            ->and(($this->filter)('pre_unschedule_hook', null, 'untracked', false))->toBeFalse();
    });
});

describe('the index', function (): void {
    it('names each driver that keeps events of a hook once, for any arguments or for exact ones', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT, null, ['a' => 1]), 'action-scheduler');
        CronIndex::record(new Event('digest', EDGE_AT + 1, null, ['a' => 2]), 'action-scheduler');
        CronIndex::record(new Event('digest', EDGE_AT, null, ['a' => 3]), 'queue');
        CronIndex::record(new Event('other', EDGE_AT), 'cron-x');

        expect(CronIndex::ownersOf('digest'))->toEqualCanonicalizing(['action-scheduler', 'queue'])
            ->and(CronIndex::ownersOf('digest', ['a' => 3]))->toBe(['queue'])
            ->and(CronIndex::ownersOf('digest', ['a' => 9]))->toBe([])
            ->and(CronIndex::ownersOf('absent'))->toBe([]);
    });

    it('forgets the events of a hook that one driver keeps, and no one else\'s', function (): void {
        CronIndex::record(new Event('digest', EDGE_AT, null, ['a' => 1]), 'action-scheduler');
        CronIndex::record(new Event('digest', EDGE_AT + 1, null, ['a' => 2]), 'queue');
        CronIndex::record(new Event('other', EDGE_AT), 'action-scheduler');

        CronIndex::forgetHook('digest', 'action-scheduler');

        expect(CronIndex::ownersOf('digest'))->toBe(['queue'])
            ->and(CronIndex::ownerOf('other'))->toBe('action-scheduler')
            ->and(array_keys($this->cron))->toBe([EDGE_AT, EDGE_AT + 1]);

        CronIndex::forgetHook('digest', 'nobody');

        expect(CronIndex::ownersOf('digest'))->toBe(['queue']);
    });

    it('compares only the events a driver keeps when a write replaces the option', function (): void {
        $entry = ['schedule' => false, 'args' => []];
        $old = [EDGE_AT => ['core_hook' => ['a' => $entry], 'digest' => ['b' => $entry + [CronIndex::MARKER => 'queue']]], 'version' => 2];
        $new = ['version' => 2, EDGE_AT => ['core_hook' => ['a' => $entry]], 'broken' => 'not a list'];

        $changes = CronIndex::changes($old, $new);

        expect(array_column($changes['removed'], 'hook'))->toBe(['digest'])
            ->and($changes['moved'])->toBe([]);
    });
});
