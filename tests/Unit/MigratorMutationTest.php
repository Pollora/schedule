<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;
use Pollora\Schedule\Migration\Migrator;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;
use Pollora\Schedule\Tests\Support\EngineProbe;

require_once __DIR__.'/../Support/EngineProbes.php';

const MIG_AT = 4_000_000_000;

beforeEach(function (): void {
    $this->cron = [];
    $this->added = [];
    $this->removed = [];
    $this->filters = [];

    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->added[] = $hook;
        $this->filters[$hook] = $callback;

        return true;
    });
    Functions\when('remove_filter')->alias(function (string $hook, callable $callback): bool {
        $this->removed[] = [$hook, $callback === ($this->filters[$hook] ?? null)];

        return true;
    });
    Functions\when('wp_get_schedules')->justReturn([]);
    Functions\when('wp_cache_delete')->justReturn(true);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });

    $this->target = new EngineProbe;
    $this->engine = new ScheduleEngine(new EngineProbe);
    $this->engine->useDriver($this->target, 'queue');
    $this->migrator = new Migrator($this->engine);
});

describe('what a driver still keeps', function (): void {
    it('is the index and what the driver holds that the index does not show, soonest first', function (): void {
        CronIndex::record(new Event('a', MIG_AT + 5, null, [1]), 'queue');
        $this->target->store = [
            new Event('a', MIG_AT + 5, null, [1]),
            new Event('a', MIG_AT + 5, null, [2]),
            new Event('b', MIG_AT + 5, null, [1]),
            new Event('a', MIG_AT + 9, null, [1]),
            new Event('c', MIG_AT + 1, 'daily', [], 86400),
        ];

        $pending = $this->migrator->pending('queue');

        expect(array_map(fn (Event $event): string => $event->hook.json_encode($event->args).$event->timestamp, $pending))
            ->toBe(['c[]'.(MIG_AT + 1), 'a[1]'.(MIG_AT + 5), 'a[2]'.(MIG_AT + 5), 'b[1]'.(MIG_AT + 5), 'a[1]'.(MIG_AT + 9)])
            ->and($pending[0])->toEqual(new Event('c', MIG_AT + 1, 'daily', [], 86400, 'queue'));
    });

    it('is the index alone for WP-Cron', function (): void {
        $this->cron = [MIG_AT => ['core' => [md5(serialize([])) => ['schedule' => false, 'args' => []]]]];

        expect(array_column($this->migrator->pending('wp-cron'), 'hook'))->toBe(['core']);
    });
});

describe('migrating', function (): void {
    it('refuses a source nobody knows', function (): void {
        expect(fn () => $this->migrator->migrate('ghost', 'queue'))->toThrow(ScheduleException::class, "No schedule driver is named 'ghost'.");
    });

    it('moves what is pending, keeps protected hooks where they are and goes on past them', function (): void {
        $this->cron = [
            MIG_AT => ['action_scheduler_run_queue' => [md5(serialize([])) => ['schedule' => false, 'args' => []]]],
            MIG_AT + 1 => ['core' => [md5(serialize([])) => ['schedule' => false, 'args' => []]]],
        ];

        $dry = $this->migrator->migrate('wp-cron', 'queue', true);

        expect(array_column($dry->moved, 'hook'))->toBe(['core'])
            ->and(array_column(array_column($dry->skipped, 'event'), 'hook'))->toBe(['action_scheduler_run_queue'])
            ->and($this->target->log)->toBe([]);

        $report = $this->migrator->migrate('wp-cron', 'queue');

        expect(array_column($report->moved, 'hook'))->toBe(['core'])
            ->and($report->failed)->toBe([])
            ->and($this->target->log)->toBe(['once:core'])
            ->and(CronIndex::ownerOf('core'))->toBe('queue')
            ->and(CronIndex::ownerOf('action_scheduler_run_queue'))->toBe('wp-cron');
    });

    it('registers the schedule of a recurring event while it moves, and only then', function (): void {
        $seen = [];
        $this->target->during = function () use (&$seen): void {
            $filter = $this->filters['cron_schedules'] ?? null;
            $seen[] = $filter === null ? null : [$filter(['x' => ['interval' => 5, 'display' => 'Mine']]), $filter('oops'), $filter(['y' => 1])];
        };
        $entry = fn (array $extra) => ['args' => []] + $extra;
        $this->cron = [
            MIG_AT => ['no_interval' => [md5(serialize([])) => $entry(['schedule' => 'x'])]],
            MIG_AT + 1 => ['no_schedule' => [md5(serialize([])) => $entry(['schedule' => false, 'interval' => 60])]],
            MIG_AT + 2 => ['both' => [md5(serialize([])) => $entry(['schedule' => 'x', 'interval' => 60])]],
        ];

        $this->migrator->migrate('wp-cron', 'queue');

        expect($seen)->toHaveCount(3)
            ->and($seen[0])->toBeNull()
            ->and($seen[1])->toBeNull()
            ->and($seen[2][0])->toBe(['x' => ['interval' => 5, 'display' => 'Mine']])
            ->and($seen[2][1])->toBe([0 => 'oops', 'x' => ['interval' => 60, 'display' => 'x']])
            ->and($seen[2][2])->toBe(['y' => 1, 'x' => ['interval' => 60, 'display' => 'x']])
            ->and($this->added)->toBe(['cron_schedules'])
            ->and($this->removed)->toBe([['cron_schedules', true]]);
    });
});
