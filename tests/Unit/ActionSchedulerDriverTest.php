<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

require_once __DIR__.'/../Support/ActionSchedulerFake.php';

const AS_NOW = 1_800_000_000;

beforeEach(function (): void {
    $this->driver = new ActionSchedulerDriver;
    Functions\when('wp_get_schedules')->justReturn([
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
    ]);
});

it('creates actions in its own group', function (): void {
    $this->driver->once('digest', AS_NOW + 100, ['user' => 1]);
    $this->driver->recurring('reports', AS_NOW + 200, 'daily');

    $actions = array_values(ActionScheduler_FakeStore::$actions);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->group)->toBe('pollora/schedule')
        ->and($actions[1]->schedule->interval)->toBe(86400);
});

it('ignores a single event identical to one due within ten minutes, as WordPress does', function (): void {
    $time = time() + 3600;

    expect($this->driver->once('digest', $time, ['user' => 1]))->toBeTrue()
        ->and($this->driver->once('digest', $time + 500, ['user' => 1]))->toBeFalse()
        ->and($this->driver->once('digest', $time + 700, ['user' => 1]))->toBeTrue()
        ->and($this->driver->once('digest', $time, ['user' => 2]))->toBeTrue();
});

it('throws when Action Scheduler is not initialised or the recurrence is unknown', function (): void {
    expect(fn () => $this->driver->recurring('reports', AS_NOW, 'fortnightly'))->toThrow(ScheduleException::class, 'recurrence');

    ActionScheduler::$initialized = false;

    expect(fn () => $this->driver->once('digest', AS_NOW))->toThrow(ScheduleException::class);
});

it('cancels only the actions of its group, and counts them', function (): void {
    $this->driver->once('shared', AS_NOW + 100, ['a' => 1]);
    $this->driver->once('shared', AS_NOW + 5000, ['a' => 2]);
    as_schedule_single_action(AS_NOW + 100, 'shared', ['a' => 1], 'woocommerce');

    expect($this->driver->cancel('shared', ['a' => 1]))->toBe(1)
        ->and($this->driver->next('shared', ['a' => 1]))->toBeNull()
        ->and($this->driver->clear('shared'))->toBe(1)
        ->and(as_next_scheduled_action('shared', ['a' => 1], 'woocommerce'))->toBe(AS_NOW + 100);
});

it('reads the next run, now for an action in progress', function (): void {
    $this->driver->once('digest', AS_NOW + 100);
    $id = (int) array_key_first(ActionScheduler_FakeStore::$actions);

    expect($this->driver->next('digest'))->toBe(AS_NOW + 100)
        ->and($this->driver->next('other'))->toBeNull();

    ActionScheduler_FakeStore::$actions[$id]->status = 'in-progress';

    expect($this->driver->next('digest'))->toBeGreaterThanOrEqual(time() - 1);
});

it('lists its events, soonest first, with the recurrence key of their interval', function (): void {
    $this->driver->recurring('reports', AS_NOW + 500, 'daily', ['x' => 1]);
    $this->driver->once('digest', AS_NOW + 100);
    as_schedule_single_action(AS_NOW, 'foreign', [], 'woocommerce');

    expect($this->driver->events())->toEqual([
        new Event('digest', AS_NOW + 100),
        new Event('reports', AS_NOW + 500, 'daily', ['x' => 1], 86400),
    ])->and($this->driver->find('reports', ['x' => 1]))->toEqual(new Event('reports', AS_NOW + 500, 'daily', ['x' => 1], 86400))
        ->and($this->driver->find('reports'))->toBeNull();
});

it('is available once its functions exist, ready once initialised', function (): void {
    expect($this->driver->available())->toBeTrue()
        ->and($this->driver->ready())->toBeTrue()
        ->and($this->driver->readyAction())->toBe('action_scheduler_init');

    ActionScheduler::$initialized = false;

    expect($this->driver->ready())->toBeFalse();
});

describe('another group', function (): void {
    it('keeps its actions apart: the default group neither sees nor cancels them', function (): void {
        $crm = new ActionSchedulerDriver('crm');
        $time = time() + 3600;

        $crm->once('digest', $time, ['user' => 1]);
        $this->driver->once('digest', $time, ['user' => 1]);

        expect($crm->next('digest', ['user' => 1]))->toBe($time)
            ->and(array_column(array_map(fn ($a) => (array) $a, array_values(ActionScheduler_FakeStore::$actions)), 'group'))->toBe(['crm', 'pollora/schedule'])
            ->and($crm->cancel('digest', ['user' => 1]))->toBe(1)
            ->and($this->driver->next('digest', ['user' => 1]))->toBe($time)
            ->and($crm->events())->toBe([]);
    });

    it('is not watched as the engine\'s: an action of another group is never reported', function (): void {
        $reported = [];
        $added = [];
        Functions\when('add_action')->alias(function (string $hook, callable $callback) use (&$added): bool {
            $added[$hook][] = $callback;

            return true;
        });
        (new ActionSchedulerDriver('crm'))->observe(function (string $hook) use (&$reported): void {
            $reported[] = $hook;
        });
        $id = as_schedule_single_action(time() + 60, 'mine', [], 'pollora/schedule');

        foreach ($added['action_scheduler_stored_action'] as $callback) {
            $callback($id);
        }

        expect($reported)->toBe([]);
    });
});

describe('enqueue', function (): void {
    it('stores an event as it is, without looking for a duplicate', function (): void {
        $time = time() + 3600;
        $this->driver->enqueue('digest', $time, ['id' => 'a']);
        $this->driver->enqueue('digest', $time + 1, ['id' => 'a']);

        expect(ActionScheduler_FakeStore::$actions)->toHaveCount(2);
    });

    it('queues an async action when the time is now or past, in its own group', function (): void {
        $crm = new ActionSchedulerDriver('crm');
        $crm->enqueue('digest', time() - 5, ['id' => 'a']);

        $action = array_values(ActionScheduler_FakeStore::$actions)[0];

        expect($action->group)->toBe('crm')
            ->and($action->schedule->timestamp)->toBeLessThanOrEqual(time());
    });

    it('says when Action Scheduler does not store it', function (): void {
        ActionScheduler::$initialized = false;

        expect(fn () => $this->driver->enqueue('digest', time(), []))->toThrow(ScheduleException::class)
            ->and(fn () => $this->driver->enqueue('digest', time() + 600, []))->toThrow(ScheduleException::class);
    });
});
