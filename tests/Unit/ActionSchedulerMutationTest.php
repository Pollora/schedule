<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Exceptions\ScheduleException;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

beforeEach(function (): void {
    $this->driver = new ActionSchedulerDriver;
    $this->hooked = [];
    $this->actions = [];
    $this->schedules = [
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'tiny' => ['interval' => 1, 'display' => 'Every second'],
    ];

    Functions\when('wp_get_schedules')->alias(fn (): array => $this->schedules);
    Functions\when('add_action')->alias(function (string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool {
        $this->hooked[] = [$hook, $priority, $accepted];
        $this->actions[$hook][] = $callback;

        return true;
    });
});

describe('reporting what happens to its actions', function (): void {
    it('listens to the five hooks, first in line, one argument, and follows the action it runs', function (): void {
        $this->driver->observe(fn () => null);

        expect($this->hooked)->toBe([
            ['action_scheduler_deleted_action', 10, 1],
            ['action_scheduler_before_execute', 10, 1],
            ['action_scheduler_after_execute', 10, 0],
            ['action_scheduler_failed_execution', 10, 0],
            ['action_scheduler_execution_ignored', 10, 0],
            ['action_scheduler_stored_action', 10, 1],
            ['action_scheduler_completed_action', 10, 1],
            ['action_scheduler_failed_action', 10, 1],
            ['action_scheduler_canceled_action', 10, 1],
        ]);
    });

    it('says which event an action of its group is about, and nothing for the others', function (): void {
        $said = [];
        $this->driver->observe(function (string $hook, array $args) use (&$said): void {
            $said[] = [$hook, $args];
        });
        $mine = fake_as_create('digest', ['u' => 1], 'pollora/schedule', 4_000_000_000, null);
        $theirs = fake_as_create('theirs', [], 'woocommerce', 4_000_000_000, null);

        foreach (['stored', 'completed', 'failed', 'canceled'] as $what) {
            ($this->actions['action_scheduler_'.$what.'_action'][0])($mine);
        }
        ($this->actions['action_scheduler_stored_action'][0])($theirs);
        ($this->actions['action_scheduler_stored_action'][0])('not an id');
        ($this->actions['action_scheduler_stored_action'][0])(99);

        expect($said)->toBe([['digest', ['u' => 1]], ['digest', ['u' => 1]], ['digest', ['u' => 1]], ['digest', ['u' => 1]]]);
    });

    it('reconciles once, when the request ends, after any number of deletions', function (): void {
        $said = [];
        $this->driver->observe(function (string $hook, array $args) use (&$said): void {
            $said[] = [$hook, $args];
        });

        ($this->actions['action_scheduler_deleted_action'][0])(1);
        ($this->actions['action_scheduler_deleted_action'][0])(2);

        expect($this->actions['shutdown'])->toHaveCount(1)
            ->and($said)->toBe([]);

        ($this->actions['shutdown'][0])();

        expect($said)->toBe([['', []]]);
    });
});

describe('storing actions', function (): void {
    it('stores an action due in the past as an async one, and a future one at its time', function (): void {
        $before = time();
        $this->driver->enqueue('past', 1000);
        $this->driver->enqueue('future', 4_000_000_000);

        $actions = array_values(ActionScheduler_FakeStore::$actions);

        expect($actions[0]->schedule->timestamp)->toBeGreaterThanOrEqual($before)
            ->and($actions[1]->schedule->timestamp)->toBe(4_000_000_000);
    });

    it('refuses to enqueue, schedule or recur when Action Scheduler cannot store', function (): void {
        ActionScheduler::$initialized = false;

        expect(fn () => $this->driver->enqueue('digest', 1000))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' could not be scheduled.")
            ->and(fn () => $this->driver->enqueue('digest', 4_000_000_000))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' could not be scheduled.")
            ->and(fn () => $this->driver->once('digest', 4_000_000_000))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' could not be scheduled.")
            ->and(fn () => $this->driver->recurring('digest', 4_000_000_000, 'hourly'))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' could not be scheduled.");
    });

    it('accepts a one second interval and refuses an unknown or empty one', function (): void {
        $this->schedules['empty'] = ['interval' => 0];

        expect($this->driver->recurring('digest', 4_000_000_000, 'tiny'))->toBeTrue()
            ->and(array_values(ActionScheduler_FakeStore::$actions)[0]->schedule->interval)->toBe(1)
            ->and(fn () => $this->driver->recurring('digest', 4_000_000_000, 'empty'))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' has no known recurrence 'empty'.")
            ->and(fn () => $this->driver->recurring('digest', 4_000_000_000, 'nope'))->toThrow(ScheduleException::class, "Action Scheduler: the event 'digest' has no known recurrence 'nope'.");
    });
});

describe('the ten minute window of a duplicate', function (): void {
    it('is ten minutes either side of a far event, ends included', function (int $offset, bool $duplicate): void {
        $time = time() + 100_000;
        as_schedule_single_action($time + $offset, 'digest', [], 'pollora/schedule');

        expect($this->driver->once('digest', $time))->toBe(! $duplicate);
    })->with([
        'at the start' => [-600, true],
        'just before it' => [-601, false],
        'at the end' => [600, true],
        'just after it' => [601, false],
        'at the same time' => [0, true],
    ]);

    it('reaches back to the beginning for an event due soon', function (): void {
        as_schedule_single_action(time() - 1000, 'late', [], 'pollora/schedule');
        as_schedule_single_action(0, 'epoch', [], 'pollora/schedule');

        expect($this->driver->once('late', time() + 100))->toBeFalse()
            ->and($this->driver->once('epoch', time() + 100))->toBeFalse();
    });

    it('reaches ten minutes past the later of now and the event', function (): void {
        $now = time();
        as_schedule_single_action($now + 650, 'soon', [], 'pollora/schedule');
        as_schedule_single_action($now + 900, 'later', [], 'pollora/schedule');

        expect($this->driver->once('soon', $now + 100))->toBeFalse()
            ->and($this->driver->once('later', $now + 100))->toBeTrue();
    });
});

describe('reading its actions back', function (): void {
    it('asks for all of them, soonest first, not five', function (): void {
        foreach ([60, 50, 40, 30, 20, 10, 70] as $offset) {
            as_schedule_single_action(4_000_000_000 + $offset, 'digest', ['n' => $offset], 'pollora/schedule');
        }

        expect(array_column($this->driver->events(), 'timestamp'))->toBe(array_map(fn (int $offset): int => 4_000_000_000 + $offset, [10, 20, 30, 40, 50, 60, 70]))
            ->and($this->driver->find('digest', ['n' => 40])->timestamp)->toBe(4_000_000_040)
            ->and($this->driver->clear('digest'))->toBe(7);
    });

    it('finds the soonest of several actions', function (): void {
        as_schedule_single_action(4_000_000_300, 'digest', [], 'pollora/schedule');
        as_schedule_single_action(4_000_000_100, 'digest', [], 'pollora/schedule');
        as_schedule_single_action(4_000_000_200, 'digest', [], 'pollora/schedule');

        expect($this->driver->find('digest')->timestamp)->toBe(4_000_000_100)
            ->and($this->driver->find('digest', ['x']))->toBeNull();
    });

    it('does not list the actions of another group', function (): void {
        as_schedule_single_action(4_000_000_000, 'theirs', [], 'woocommerce');

        expect($this->driver->events())->toBe([]);
    });

    it('names the schedule of a recurring action after the registered one with its interval', function (): void {
        $this->schedules = [
            'bad' => ['display' => 'no interval'],
            'text' => ['interval' => '300', 'display' => 'Text'],
            7 => ['interval' => 45, 'display' => 'Numeric key'],
        ];
        fake_as_create('a', [], 'pollora/schedule', 4_000_000_001, 300);
        fake_as_create('b', [], 'pollora/schedule', 4_000_000_002, 45);
        fake_as_create('c', [], 'pollora/schedule', 4_000_000_003, 1);
        fake_as_create('d', [], 'pollora/schedule', 4_000_000_004, 0);
        fake_as_create('e', [], 'pollora/schedule', 4_000_000_005, 999);

        $events = $this->driver->events();

        expect(array_column($events, 'recurrence'))->toBe(['text', '7', 'every_1_seconds', 'bad', 'every_999_seconds'])
            ->and(array_column($events, 'interval'))->toBe([300, 45, 1, 0, 999]);
    });
});
