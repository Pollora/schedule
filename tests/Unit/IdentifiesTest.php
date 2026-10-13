<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Contracts\Identifies;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Migration\Migrator;
use Pollora\Schedule\Schedule;
use Pollora\Schedule\ScheduleEngine;
use Pollora\Schedule\Support\CronIndex;

/**
 * A driver with no store: the index is its only record.
 */
final class IdentifyingDriver implements Identifies, ScheduleDriver
{
    /** @var list<Event> */
    public array $placed = [];

    public function available(): bool
    {
        return true;
    }

    public function placeIdentified(Event $event): bool
    {
        $this->placed[] = $event;

        return true;
    }

    public function once(string $hook, int $timestamp, array $args = []): bool
    {
        throw new LogicException('Identified placement only');
    }

    public function recurring(string $hook, int $timestamp, string $recurrence, array $args = []): bool
    {
        throw new LogicException('Identified placement only');
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
}

beforeEach(function (): void {
    $this->cron = [];

    Functions\when('add_filter')->justReturn(true);
    Functions\when('add_action')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('wp_get_schedules')->justReturn(['daily' => ['interval' => 86400, 'display' => 'Once Daily']]);
    Functions\when('_get_cron_array')->alias(fn (): array => $this->cron);
    Functions\when('_set_cron_array')->alias(function (array $cron): bool {
        $this->cron = $cron;

        return true;
    });

    $this->driver = new IdentifyingDriver;
    $this->engine = new ScheduleEngine;
    $this->engine->selectDriver($this->driver, 'queue');
});

it('gives each placement an identifier and writes it in the entry', function (): void {
    $this->engine->place(new Event('digest', time() + 100, null, ['u' => 1]));
    $this->engine->place(new Event('digest', time() + 200, null, ['u' => 2]));

    $first = $this->driver->placed[0];

    expect($first->id)->toMatch('/^[0-9a-f]{16}$/')
        ->and(CronIndex::idOf('digest', ['u' => 1]))->toBe($first->id)
        ->and($this->driver->placed[1]->id)->not->toBe($first->id)
        ->and(CronIndex::events('queue')[0]->id)->toBe($first->id)
        ->and(CronIndex::ownerOf('digest', ['u' => 1]))->toBe('queue');
});

it('keeps the identifier when the index records the event again without one', function (): void {
    $this->engine->place(new Event('digest', time() + 100));
    $id = CronIndex::idOf('digest');

    CronIndex::record(new Event('digest', time() + 100), 'queue');

    expect(CronIndex::idOf('digest'))->toBe($id);
});

it('replaces the identifier when a placement carries one', function (): void {
    $this->engine->place(new Event('digest', time() + 100));
    CronIndex::record((new Event('digest', time() + 500))->withId('next'), 'queue');

    expect(CronIndex::idOf('digest'))->toBe('next')
        ->and(CronIndex::events('queue'))->toHaveCount(1);
});

it('has no identifier for an entry that never had one', function (): void {
    $this->cron = [time() + 100 => ['legacy' => [md5(serialize([])) => ['schedule' => false, 'args' => []]]]];

    expect(CronIndex::idOf('legacy'))->toBeNull();
});

it('places the events a direct write moved with a new identifier', function (): void {
    $moved = new Event('digest', time() + 900, null, [], null, 'queue', 'stale');

    $this->engine->applyChanges(['removed' => [], 'moved' => [$moved]]);

    expect($this->driver->placed)->toHaveCount(1)
        ->and($this->driver->placed[0]->id)->not->toBe('stale')
        ->and(CronIndex::idOf('digest'))->toBe($this->driver->placed[0]->id);
});

it('identifies the placement a migration makes', function (): void {
    $this->cron = [time() + 100 => ['digest' => [md5(serialize([])) => ['schedule' => false, 'args' => []]]]];
    Functions\when('wp_unschedule_event')->justReturn(true);
    Functions\when('wp_next_scheduled')->justReturn(false);

    $this->engine->driverNamed('queue');
    $report = (new Migrator($this->engine))->migrate('wp-cron', 'queue');

    expect($report->moved)->toHaveCount(1)
        ->and($this->driver->placed[0]->id)->not->toBeNull()
        ->and(CronIndex::idOf('digest'))->toBe($this->driver->placed[0]->id);
});

it('does not fail when the environment names a driver the application adds afterwards', function (): void {
    $schedule = new ReflectionClass(Schedule::class);
    $schedule->setStaticPropertyValue('engine', null);
    $schedule->setStaticPropertyValue('wanted', null);
    putenv('POLLORA_SCHEDULE_DRIVER=queue');

    try {
        expect(Schedule::engine()->driverName())->toBe('wp-cron');

        Schedule::extend('queue', new IdentifyingDriver);
        Schedule::driver('queue');

        expect(Schedule::engine()->driverName())->toBe('queue');
    } finally {
        putenv('POLLORA_SCHEDULE_DRIVER');
        $schedule->setStaticPropertyValue('engine', null);
        $schedule->setStaticPropertyValue('drivers', []);
    }
});
