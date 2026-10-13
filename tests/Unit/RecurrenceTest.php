<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Every;
use Pollora\Schedule\Interval;
use Pollora\Schedule\Recurrence;

beforeEach(function (): void {
    $this->filters = [];
    Functions\when('add_filter')->alias(function (string $hook, callable $callback): bool {
        $this->filters[$hook][] = $callback;

        return true;
    });
});

it('keeps the key of every kind of recurrence, so scheduled events keep their schedule', function (): void {
    expect(Recurrence::key('hourly', 'h'))->toBe('hourly')
        ->and(Recurrence::key(Every::DAY, 'h'))->toBe('daily')
        ->and(Recurrence::key(Every::MONTH, 'h'))->toBe('every_month')
        ->and(Recurrence::key(Every::YEAR, 'h'))->toBe('every_year')
        ->and(Recurrence::key(new Interval(minutes: 15), 'h'))->toBe('interval_'.md5('h_900'))
        ->and(Recurrence::key(['interval' => 60, 'display' => 'Minutely'], 'h'))->toBe('custom_'.md5('h'));
});

it('registers only the schedules WordPress does not provide', function (): void {
    Recurrence::key('daily', 'h');
    Recurrence::key(Every::WEEK, 'h');

    expect($this->filters)->toBe([]);
});

it('rejects an unknown schedule name and an incomplete array', function (): void {
    expect(fn () => Recurrence::key('fortnightly', 'h'))->toThrow(InvalidArgumentException::class, 'Allowed schedules')
        ->and(fn () => Recurrence::key(['display' => 'x'], 'h'))->toThrow(InvalidArgumentException::class, 'interval')
        ->and(fn () => Recurrence::key(['interval' => 60], 'h'))->toThrow(InvalidArgumentException::class, 'display');
});
