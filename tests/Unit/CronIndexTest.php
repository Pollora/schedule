<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\Migration\Migrator;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

beforeEach(function (): void {
    $this->actions = [];
    $this->cron = [];
    $this->wpCronCalls = [];

    $this->scheduleFilters = [];
    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->scheduleFilters[$hook][] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook, callable $callback): bool {
        $this->scheduleFilters[$hook] = array_values(array_filter($this->scheduleFilters[$hook] ?? [], fn ($c) => $c !== $callback));

        return true;
    });
    Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
        $this->actions[$hook][] = $callback;

        return true;
    });
    $this->schedules = ['daily' => ['interval' => 86400, 'display' => 'Once Daily']];
    Functions\when('wp_get_schedules')->alias(function (): array {
        $schedules = $this->schedules;

        foreach ($this->scheduleFilters['cron_schedules'] ?? [] as $filter) {
            $schedules = $filter($schedules);
        }

        return $schedules;
    });
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    Functions\when('wp_unschedule_hook')->justReturn(0);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_schedule_event')->justReturn(true);
    Functions\when('wp_schedule_single_event')->justReturn(true);

    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver(new ActionSchedulerDriver);
    $this->fire = function (string $hook, int $id): void {
        foreach ($this->actions[$hook] ?? [] as $callback) {
            $callback($id);
        }
    };
});

it('records an event and finds its owner, and an entry without a marker is WP-Cron\'s', function (): void {
    CronIndex::record(new Event('reports', 2000, 'daily', ['a' => 1]), 'action-scheduler');
    CronIndex::record(new Event('wp_version_check', 3000, 'daily'), 'wp-cron');

    expect(CronIndex::ownerOf('reports', ['a' => 1]))->toBe('action-scheduler')
        ->and(CronIndex::ownerOf('wp_version_check'))->toBe('wp-cron')
        ->and(CronIndex::ownerOf('reports'))->toBeNull()
        ->and($this->cron[3000]['wp_version_check'][md5(serialize([]))])->toBe(['schedule' => 'daily', 'args' => [], 'interval' => 86400])
        ->and(CronIndex::hasMarked())->toBeTrue()
        ->and(array_column(CronIndex::events('action-scheduler'), 'driver'))->toBe(['action-scheduler']);
});

it('lists the index with the driver of each event, without asking a driver', function (): void {
    $this->engine->place(new Event('digest', time() + 100));
    $this->cron[time() + 50] = ['wp_version_check' => [md5(serialize([])) => ['schedule' => 'daily', 'args' => [], 'interval' => 86400]]];
    ksort($this->cron);

    expect(array_map(fn (Event $e): array => [$e->hook, $e->driver], $this->engine->index()))->toBe([['wp_version_check', 'wp-cron'], ['digest', 'action-scheduler']]);
});

it('keeps the name of a schedule when only the driver\'s interval is known', function (): void {
    $this->schedules = ['custom_a' => ['interval' => 900], 'every_900_seconds' => ['interval' => 900]];
    CronIndex::record(new Event('job', 1000, 'custom_a', [], 900), 'action-scheduler');
    CronIndex::record(new Event('job', 1900, 'every_900_seconds', [], 900), 'action-scheduler');

    expect(array_keys($this->cron))->toBe([1900])
        ->and($this->cron[1900]['job'][md5(serialize([]))]['schedule'])->toBe('custom_a');
});

it('forgets only what its owner keeps', function (): void {
    CronIndex::record(new Event('job', 1000), 'action-scheduler');
    CronIndex::forget('job', [], 'wp-cron');

    expect(CronIndex::ownerOf('job'))->toBe('action-scheduler');

    CronIndex::forget('job', [], 'action-scheduler');

    expect($this->cron)->toBe([]);
});

it('follows Action Scheduler: stored, completed, cancelled, and not other groups', function (): void {
    $id = as_schedule_recurring_action(time() + 100, 86400, 'reports', [], ActionSchedulerDriver::GROUP);
    ($this->fire)('action_scheduler_stored_action', $id);

    expect(CronIndex::ownerOf('reports'))->toBe('action-scheduler');

    ActionScheduler::store()->cancel_action($id);
    ($this->fire)('action_scheduler_canceled_action', $id);

    expect(CronIndex::ownerOf('reports'))->toBeNull();

    $other = as_schedule_single_action(time() + 100, 'woo_thing', [], 'woocommerce');
    ($this->fire)('action_scheduler_stored_action', $other);

    expect($this->cron)->toBe([]);
});

it('ignores what Action Scheduler reports while the engine is changing it', function (): void {
    $this->engine->place(new Event('digest', time() + 100));
    $entry = $this->cron;

    $this->engine->quietly(function (): void {
        $id = as_schedule_single_action(time() + 5, 'sneaky', [], ActionSchedulerDriver::GROUP);
        ($this->fire)('action_scheduler_stored_action', $id);
    });

    expect($this->cron)->toBe($entry);
});

it('rebuilds the index from the driver: adds what it keeps, drops what it does not', function (): void {
    as_schedule_single_action(time() + 100, 'unseen', ['a' => 1], ActionSchedulerDriver::GROUP);
    CronIndex::record(new Event('stale', time() + 50), 'action-scheduler');

    expect($this->engine->reindex())->toBe(2)
        ->and(CronIndex::ownerOf('unseen', ['a' => 1]))->toBe('action-scheduler')
        ->and(CronIndex::ownerOf('stale'))->toBeNull()
        ->and($this->engine->reindex())->toBe(0);
});

describe('migration', function (): void {
    beforeEach(function (): void {
        $this->time = time() + 3600;
        $this->cron = [
            $this->time => [
                'reports' => [md5(serialize([])) => ['schedule' => 'custom_x', 'args' => [], 'interval' => 7200]],
                'digest' => [md5(serialize(['u' => 1])) => ['schedule' => false, 'args' => ['u' => 1]]],
                'action_scheduler_run_queue' => [md5(serialize([])) => ['schedule' => 'every_minute', 'args' => [], 'interval' => 60]],
            ],
            'version' => 2,
        ];
        $this->migrator = new Migrator($this->engine);
    });

    it('lists what a driver still keeps', function (): void {
        expect(array_column($this->migrator->pending('wp-cron'), 'hook'))->toEqualCanonicalizing(['reports', 'digest', 'action_scheduler_run_queue'])
            ->and($this->migrator->pending('action-scheduler'))->toBe([]);
    });

    it('shows what would move and moves nothing with --dry-run', function (): void {
        $report = $this->migrator->migrate('wp-cron', 'action-scheduler', dryRun: true);

        expect(array_column($report->moved, 'hook'))->toEqualCanonicalizing(['reports', 'digest'])
            ->and(array_column(array_column($report->skipped, 'event'), 'hook'))->toBe(['action_scheduler_run_queue'])
            ->and(ActionScheduler_FakeStore::$actions)->toBe([])
            ->and(CronIndex::ownerOf('reports'))->toBe('wp-cron');
    });

    it('moves events to the target, keeping a recurring one\'s own interval, and leaves protected hooks', function (): void {
        $report = $this->migrator->migrate('wp-cron', 'action-scheduler');

        expect($report->succeeded())->toBeTrue()
            ->and(CronIndex::ownerOf('reports'))->toBe('action-scheduler')
            ->and(CronIndex::ownerOf('digest', ['u' => 1]))->toBe('action-scheduler')
            ->and(CronIndex::ownerOf('action_scheduler_run_queue'))->toBe('wp-cron')
            ->and(array_values(array_map(fn ($a) => $a->schedule->interval, ActionScheduler_FakeStore::$actions)))->toContain(7200)
            ->and($this->cron[$this->time]['reports'][md5(serialize([]))]['schedule'])->toBe('custom_x');
    });

    it('can run again without duplicating', function (): void {
        $this->migrator->migrate('wp-cron', 'action-scheduler');
        $second = $this->migrator->migrate('wp-cron', 'action-scheduler');

        expect($second->moved)->toBe([])
            ->and(ActionScheduler_FakeStore::$actions)->toHaveCount(2);
    });

    it('settles an event an earlier run created in the target but did not take out of the source', function (): void {
        as_schedule_recurring_action($this->time, 7200, 'reports', [], ActionSchedulerDriver::GROUP);

        $report = $this->migrator->migrate('wp-cron', 'action-scheduler');

        expect($report->succeeded())->toBeTrue()
            ->and(array_filter(ActionScheduler_FakeStore::$actions, fn ($a) => $a->hook === 'reports'))->toHaveCount(1)
            ->and(CronIndex::ownerOf('reports'))->toBe('action-scheduler');
    });

    it('moves them back, cancelling in the driver', function (): void {
        $this->migrator->migrate('wp-cron', 'action-scheduler');
        $back = $this->migrator->migrate('action-scheduler', 'wp-cron');

        expect(array_column($back->moved, 'hook'))->toEqualCanonicalizing(['reports', 'digest'])
            ->and(CronIndex::ownerOf('reports'))->toBe('wp-cron')
            ->and(array_values(array_unique(array_map(fn ($a) => $a->status, ActionScheduler_FakeStore::$actions))))->toBe(['canceled'])
            ->and(CronIndex::hasMarked())->toBeFalse();
    });

    it('reports an event it could not move and leaves it where it was', function (): void {
        $broken = new ActionSchedulerDriver;
        ActionScheduler::$initialized = true;
        $this->schedules = [];
        // No schedule for the interval and none stored: the driver refuses
        $this->cron[$this->time]['reports'][md5(serialize([]))] = ['schedule' => 'custom_x', 'args' => []];  // no stored interval either

        $report = $this->migrator->migrate('wp-cron', 'action-scheduler');

        expect(array_column(array_column($report->failed, 'event'), 'hook'))->toBe(['reports'])
            ->and($report->succeeded())->toBeFalse()
            ->and(CronIndex::ownerOf('reports'))->toBe('wp-cron')
            ->and($broken->available())->toBeTrue();
    });

    it('refuses a target that is not there, or the same driver twice', function (): void {
        expect(fn () => $this->migrator->migrate('wp-cron', 'wp-cron'))->toThrow(ScheduleException::class);

        ActionScheduler::$initialized = false;

        expect(fn () => $this->migrator->migrate('wp-cron', 'action-scheduler'))->toThrow(ScheduleException::class);
    });
});
