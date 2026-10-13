<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Resolves the moment an event is first due, in the site's timezone.
 */
final class When
{
    /**
     * @param  string|DateTimeInterface|int  $moment  A Unix timestamp, a date, or 'HH:MM' for the next time the clock shows it
     * @param  int  $now  Current Unix timestamp
     *
     * @throws InvalidArgumentException When a string is not a date
     */
    public static function resolve(string|DateTimeInterface|int $moment, int $now): int
    {
        if (is_int($moment)) {
            return $moment;
        }

        if ($moment instanceof DateTimeInterface) {
            return $moment->getTimestamp();
        }

        $timezone = self::timezone();

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $moment, $time) === 1) {
            $date = (new DateTimeImmutable('@'.$now))->setTimezone($timezone)->setTime((int) $time[1], (int) $time[2]);

            return ($date->getTimestamp() <= $now ? $date->modify('+1 day') : $date)->getTimestamp();
        }

        try {
            return (new DateTimeImmutable($moment, $timezone))->getTimestamp();
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('"%s" is not a date or a time of day (HH:MM).', $moment), 0, $exception);
        }
    }

    public static function timezone(): DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }
}
