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
