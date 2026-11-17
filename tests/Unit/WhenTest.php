<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Schedule\When;

beforeEach(function (): void {
    Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Paris'));
});

it('resolves a clock time to the next time it shows, tomorrow when it is now or past', function (): void {
    $now = (new DateTimeImmutable('2027-01-15 08:00:00', new DateTimeZone('Europe/Paris')))->getTimestamp();
    $paris = fn (int $timestamp): string => (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i');

    expect($paris(When::resolve('08:30', $now)))->toBe('2027-01-15 08:30')
        ->and($paris(When::resolve('08:00', $now)))->toBe('2027-01-16 08:00')
        ->and($paris(When::resolve('07:59', $now)))->toBe('2027-01-16 07:59')
        ->and($paris(When::resolve('9:05', $now)))->toBe('2027-01-15 09:05')
        ->and($paris(When::resolve('23:59', $now)))->toBe('2027-01-15 23:59');
});

it('says what it could not read, with the original error behind it', function (): void {
    try {
        When::resolve('not a date at all', 0);
        $thrown = null;
    } catch (InvalidArgumentException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($thrown->getMessage())->toBe('"not a date at all" is not a date or a time of day (HH:MM).')
        ->and($thrown->getCode())->toBe(0)
        ->and($thrown->getPrevious())->toBeInstanceOf(Exception::class);
});
