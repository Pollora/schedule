<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\Drivers\WpCronDriver;
use Pollora\Schedule\Event;
use Pollora\Schedule\Exceptions\ScheduleException;

final class StubWpError
{
    public function __construct(private readonly string $code, private readonly string $message = '') {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

it('schedules through the WordPress functions, asking for errors', function (): void {
    Functions\expect('wp_schedule_single_event')->once()->with(1000, 'digest', ['user' => 1], true)->andReturn(true);
    Functions\expect('wp_schedule_event')->once()->with(2000, 'daily', 'reports', [], true)->andReturn(true);

    $driver = new WpCronDriver;

    expect($driver->once('digest', 1000, ['user' => 1]))->toBeTrue()
        ->and($driver->recurring('reports', 2000, 'daily'))->toBeTrue();
});

it('counts as scheduled what a pre_schedule_event filter returns instead of true', function (mixed $returned): void {
    Functions\when('wp_schedule_single_event')->justReturn($returned);
    Functions\when('wp_schedule_event')->justReturn($returned);

    $driver = new WpCronDriver;

    expect($driver->once('digest', 1000))->toBeTrue()
        ->and($driver->recurring('reports', 2000, 'daily'))->toBeTrue();
})->with([
    'an object, as the Laravel bridge returns' => [fn () => new stdClass],
    'an identifier' => [42],
    'true' => [true],
]);

it('ignores a duplicate single event and throws on any other failure', function (): void {
    Functions\when('wp_schedule_single_event')->justReturn(new StubWpError('duplicate_event'));

    expect((new WpCronDriver)->once('digest', 1000))->toBeFalse();

    Functions\when('wp_schedule_single_event')->justReturn(new StubWpError('invalid_timestamp', 'Event timestamp must be a valid Unix timestamp.'));

    expect(fn () => (new WpCronDriver)->once('digest', 0))->toThrow(ScheduleException::class, 'Event timestamp must be a valid Unix timestamp.');
});

it('throws when a recurring event cannot be scheduled', function (): void {
    Functions\when('wp_schedule_event')->justReturn(new StubWpError('invalid_schedule', 'Event schedule does not exist.'));

    expect(fn () => (new WpCronDriver)->recurring('reports', 1, 'nope'))->toThrow(ScheduleException::class, 'Event schedule does not exist.');
});

it('cancels, clears and finds the next run through the WordPress functions', function (): void {
    Functions\expect('wp_clear_scheduled_hook')->once()->with('digest', ['user' => 1], true)->andReturn(1);
    Functions\expect('wp_unschedule_hook')->once()->with('digest', true)->andReturn(3);
    Functions\when('wp_next_scheduled')->alias(fn (string $hook): int|false => $hook === 'digest' ? 1234 : false);

    $driver = new WpCronDriver;

    expect($driver->cancel('digest', ['user' => 1]))->toBe(1)
        ->and($driver->clear('digest'))->toBe(3)
        ->and($driver->next('digest'))->toBe(1234)
        ->and($driver->next('other'))->toBeNull();
});

it('lists the events of the cron array, soonest first', function (): void {
    Functions\when('_get_cron_array')->justReturn([
        2000 => ['reports' => ['k1' => ['schedule' => 'daily', 'args' => [], 'interval' => 86400]]],
        1000 => ['digest' => ['k2' => ['schedule' => false, 'args' => ['user' => 1]]]],
        'version' => 2,
    ]);

    expect((new WpCronDriver)->events())->toEqual([
        new Event('digest', 1000, null, ['user' => 1], null, 'wp-cron'),
        new Event('reports', 2000, 'daily', [], 86400, 'wp-cron'),
    ]);
});
