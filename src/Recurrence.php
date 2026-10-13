<?php

declare(strict_types=1);

namespace Pollora\Schedule;

use InvalidArgumentException;

/**
 * Turns a recurrence, however it is written, into the key of a WordPress
 * schedule, registering in 'cron_schedules' the ones WordPress does not know.
 *
 * Keys are stable: an event already scheduled under one keeps it.
 */
final class Recurrence
{
    /**
     * Schedules WordPress provides.
     *
     * @var list<string>
     */
    public const array BUILT_IN = ['hourly', 'twicedaily', 'daily', 'weekly'];

    /**
     * @param  string|array<string, mixed>|Every|Interval  $recurrence  A WordPress schedule name, ['interval' => seconds, 'display' => name], an Every case or an Interval
     * @param  string  $hook  The event's hook, which names the custom schedules
     *
     * @throws InvalidArgumentException When the recurrence is not valid
     */
    public static function key(string|array|Every|Interval $recurrence, string $hook): string
    {
        return match (true) {
            is_string($recurrence) => self::builtIn($recurrence),
            is_array($recurrence) => self::fromArray($recurrence, $hook),
            $recurrence instanceof Every => self::fromEvery($recurrence),
            default => self::fromInterval($recurrence, $hook),
        };
    }

    private static function builtIn(string $recurrence): string
    {
        if (! in_array($recurrence, self::BUILT_IN, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid WordPress schedule "%s". Allowed schedules: %s',
                $recurrence,
                implode(', ', self::BUILT_IN),
            ));
        }

        return $recurrence;
    }

    /**
     * @param  array<string, mixed>  $recurrence
     */
    private static function fromArray(array $recurrence, string $hook): string
    {
        if (! isset($recurrence['interval']) || ! is_numeric($recurrence['interval'])) {
            throw new InvalidArgumentException('Custom schedule array must contain a numeric "interval" key (seconds)');
        }

        if (! isset($recurrence['display']) || ! is_string($recurrence['display'])) {
            throw new InvalidArgumentException('Custom schedule array must contain a string "display" key (human-readable name)');
        }

        $key = 'custom_'.md5($hook);
        self::register($key, (int) $recurrence['interval'], $recurrence['display']);

        return $key;
    }

    private static function fromEvery(Every $every): string
    {
        if (! $every->isCustom()) {
            return $every->toScheduleKey();
        }

        $key = 'every_'.strtolower($every->name);
        $interval = $every->toInterval();
        self::register($key, $interval->totalSeconds(), $interval->display);

        return $key;
    }

    private static function fromInterval(Interval $interval, string $hook): string
    {
        $key = 'interval_'.md5($hook.'_'.$interval->totalSeconds());
        self::register($key, $interval->totalSeconds(), $interval->display);

        return $key;
    }

    private static function register(string $key, int $interval, string $display): void
    {
        add_filter('cron_schedules', static function (array $schedules) use ($key, $interval, $display): array {
            $schedules[$key] = ['interval' => $interval, 'display' => $display];

            return $schedules;
        });
    }
}
